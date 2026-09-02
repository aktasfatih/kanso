// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10045 — the Automation recurrence editor parses five RRULE parts (FREQ,
// INTERVAL, BYDAY, COUNT, UNTIL) and used to re-serialize the rule from exactly
// those on every save. A rule authored through the API — `FREQ=MONTHLY;
// BYMONTHDAY=1,15`, "the 1st and the 15th" — therefore came back as a bare
// `FREQ=MONTHLY` the moment someone opened it to change its target column.
//
// The assertion that matters is the API read-back: the panel never rendered the
// dropped part in the first place, so "the form still looks right" passes
// against the broken build. Only the stored string can tell the two apart.

import { test, expect, BASE, api, ncLogin } from './helpers.js'

const CUSTOM_RRULE = 'FREQ=MONTHLY;BYMONTHDAY=1,15'

test.describe('Editing an API-authored recurrence rule (#10045)', () => {
	const state = { boardId: 0, stackId: 0, otherStackId: 0, cardId: 0, ruleId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Custom RRULE ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'Invoices' })).id
		state.otherStackId = (await api.post('/stacks', { boardId: board.id, title: 'Paid' })).id
		state.cardId = (await api.post('/cards', { stackId: state.stackId, title: 'Send the invoice' })).id

		// The kind of rule only the API / MCP can author today: twice a month.
		const rule = await api.post(`/boards/${state.boardId}/recur-rules`, {
			templateCardId: state.cardId,
			targetStackId: state.stackId,
			mode: 0,
			rrule: CUSTOM_RRULE,
		})
		state.ruleId = rule.id

		// Spawn one occurrence so the "ends after N times" tally is non-zero —
		// otherwise "the counter was not reset" is a vacuous 0 === 0.
		await api.post(`/recur-rules/${state.ruleId}/create-now`)
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	const storedRule = async () => {
		const rules = await api.get(`/boards/${state.boardId}/recur-rules`)
		return rules.find((r) => Number(r.id) === Number(state.ruleId)) ?? null
	}

	test('changing only the target column leaves the custom schedule byte-identical', async ({ page }) => {
		const before = await storedRule()
		expect(before.rrule).toBe(CUSTOM_RRULE)
		expect(before.occurrencesSpawned).toBe(1)

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)

		// Board settings → Automation → expand "Recurring cards".
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /automation/i }).click()
		await page.getByRole('button', { name: /Recurring cards/ }).click()

		const recurring = page.locator('#bs-automation-recurring')
		const item = recurring.locator('.automation__rule-item').filter({ hasText: 'Send the invoice' })
		await expect(item).toBeVisible({ timeout: 8_000 })
		await item.getByRole('button', { name: /^Edit$/ }).click()

		// The schedule half of the editor is read-only: the raw rule is shown with
		// a note, and the frequency/interval controls are not rendered at all.
		await expect(recurring.locator('.automation__custom-rrule')).toHaveText(CUSTOM_RRULE)
		await expect(recurring.locator('.automation__custom-note')).toBeVisible()
		await expect(page.locator(`#recur-freq-${state.boardId}`)).toHaveCount(0)

		// Everything the editor genuinely owns stays editable — only the schedule
		// is frozen. Change the target column AND the due-date policy, then save.
		await page.locator(`#recur-stack-${state.boardId}`).selectOption(String(state.otherStackId))
		await page.locator(`#recur-due-${state.boardId}`).selectOption('2') // None
		await page.getByRole('button', { name: /^Save rule$/ }).click()

		// Read the rule back from the server. Both edited fields moved; the
		// schedule string is untouched byte for byte, and the tally did not restart.
		await expect.poll(
			async () => Number((await storedRule())?.targetStackId),
			{ timeout: 8_000 },
		).toBe(Number(state.otherStackId))

		const after = await storedRule()
		expect(Number(after.duedatePolicy)).toBe(2)
		expect(after.rrule).toBe(CUSTOM_RRULE)
		expect(after.occurrencesSpawned).toBe(1)
	})
})

// The second half of the same harm: RecurrenceService::update() compares rule
// STRINGS, so re-sending a semantically identical but differently spelled rule
// (INTERVAL=1, which any generic RRULE library emits) restarts the
// occurrences-spawned tally — an "ends after 5" rule quietly becomes 5 MORE.
// The editor only sends a schedule the user actually changed.
test.describe('Saving a rule whose schedule was not touched (#10045)', () => {
	const state = { boardId: 0, stackId: 0, otherStackId: 0, cardId: 0, ruleId: 0 }
	const VERBOSE_RRULE = 'FREQ=WEEKLY;INTERVAL=1;COUNT=5'

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Verbose RRULE ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'Standup' })).id
		state.otherStackId = (await api.post('/stacks', { boardId: board.id, title: 'Archive' })).id
		state.cardId = (await api.post('/cards', { stackId: state.stackId, title: 'Write the update' })).id
		state.ruleId = (await api.post(`/boards/${state.boardId}/recur-rules`, {
			templateCardId: state.cardId,
			targetStackId: state.stackId,
			mode: 0,
			rrule: VERBOSE_RRULE,
		})).id
		await api.post(`/recur-rules/${state.ruleId}/create-now`)
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('an untouched schedule is not re-sent, so the occurrence tally survives', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)

		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /automation/i }).click()
		await page.getByRole('button', { name: /Recurring cards/ }).click()

		const recurring = page.locator('#bs-automation-recurring')
		const item = recurring.locator('.automation__rule-item').filter({ hasText: 'Write the update' })
		await expect(item).toBeVisible({ timeout: 8_000 })
		await item.getByRole('button', { name: /^Edit$/ }).click()

		// This rule IS editable — it is only spelled more verbosely than the
		// builder would spell it.
		await expect(page.locator(`#recur-freq-${state.boardId}`)).toHaveValue('WEEKLY')
		await page.locator(`#recur-stack-${state.boardId}`).selectOption(String(state.otherStackId))
		await page.getByRole('button', { name: /^Save rule$/ }).click()

		const stored = async () => {
			const rules = await api.get(`/boards/${state.boardId}/recur-rules`)
			return rules.find((r) => Number(r.id) === Number(state.ruleId)) ?? null
		}
		await expect.poll(
			async () => Number((await stored())?.targetStackId),
			{ timeout: 8_000 },
		).toBe(Number(state.otherStackId))

		const after = await stored()
		expect(after.rrule).toBe(VERBOSE_RRULE)
		expect(after.occurrencesSpawned).toBe(1)
	})
})

test.describe('Editing a rule the recurrence editor fully models (#10045)', () => {
	const state = { boardId: 0, stackId: 0, otherStackId: 0, cardId: 0, ruleId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Simple RRULE ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'Chores' })).id
		state.otherStackId = (await api.post('/stacks', { boardId: board.id, title: 'Done-ish' })).id
		state.cardId = (await api.post('/cards', { stackId: state.stackId, title: 'Take the bins out' })).id
		state.ruleId = (await api.post(`/boards/${state.boardId}/recur-rules`, {
			templateCardId: state.cardId,
			targetStackId: state.stackId,
			mode: 0,
			rrule: 'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE',
		})).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	// The custom-schedule guard must not swallow the ordinary case: a rule built
	// from these very controls still round-trips and still saves edits.
	test('the builder stays editable and keeps round-tripping a weekly rule', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)

		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /automation/i }).click()
		await page.getByRole('button', { name: /Recurring cards/ }).click()

		const recurring = page.locator('#bs-automation-recurring')
		const item = recurring.locator('.automation__rule-item').filter({ hasText: 'Take the bins out' })
		await expect(item).toBeVisible({ timeout: 8_000 })
		await item.getByRole('button', { name: /^Edit$/ }).click()

		// The real controls, populated from the stored rule — no read-only note.
		await expect(recurring.locator('.automation__custom-note')).toHaveCount(0)
		await expect(page.locator(`#recur-freq-${state.boardId}`)).toHaveValue('WEEKLY')
		await expect(page.locator(`#recur-interval-${state.boardId}`)).toHaveValue('2')

		// Bump the interval and save: the rule is re-serialized, weekdays included.
		await page.locator(`#recur-interval-${state.boardId}`).fill('3')
		await page.getByRole('button', { name: /^Save rule$/ }).click()

		await expect.poll(async () => {
			const rules = await api.get(`/boards/${state.boardId}/recur-rules`)
			return rules.find((r) => Number(r.id) === Number(state.ruleId))?.rrule ?? ''
		}, { timeout: 8_000 }).toBe('FREQ=WEEKLY;INTERVAL=3;BYDAY=MO,WE')
	})
})
