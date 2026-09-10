// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10130 — the same failure mode as #10045, one field down. The Automation
// recurrence editor models the due-date offset in whole DAYS, but the API and
// MCP accept an arbitrary offset in seconds (e.g. a 1-hour lead time). Opening
// such a rule loaded the offset through `Math.round(seconds / 86400)` and, since
// `duedatePolicy === 1` always re-sent `duedateOffsetSeconds`, saving the rule
// for an unrelated reason wrote the rounded value back — silently zeroing (or
// changing) the user's lead time.
//
// As with #10045 the assertion that matters is the API read-back, not the UI:
// the panel never renders the lost precision, so "the form still looks right"
// passes against the broken build. Only the stored seconds tell the two apart.

import { test, expect, BASE, api, ncLogin } from './helpers.js'

const SUB_DAY_OFFSET = 3600 // one hour — not a whole number of days

async function openRuleEditor(page, boardId, cardTitle) {
	await ncLogin(page)
	await page.goto(`${BASE}/index.php/apps/kanso#/board/${boardId}`)
	await page.getByRole('button', { name: 'More' }).click()
	await page.getByRole('menuitem', { name: /board settings/i }).click()
	await page.getByRole('tab', { name: /automation/i }).click()
	await page.getByRole('button', { name: /Recurring cards/ }).click()
	const recurring = page.locator('#bs-automation-recurring')
	const item = recurring.locator('.automation__rule-item').filter({ hasText: cardTitle })
	await expect(item).toBeVisible({ timeout: 8_000 })
	await item.getByRole('button', { name: /^Edit$/ }).click()
	return recurring
}

test.describe('Editing a rule with a sub-day due-date offset (#10130)', () => {
	const state = { boardId: 0, stackId: 0, otherStackId: 0, cardId: 0, ruleId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Offset RRULE ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'Prep' })).id
		state.otherStackId = (await api.post('/stacks', { boardId: board.id, title: 'Ready' })).id
		state.cardId = (await api.post('/cards', { stackId: state.stackId, title: 'Warm up the oven' })).id

		// The kind of rule only the API / MCP can author today: a one-hour lead time.
		const rule = await api.post(`/boards/${state.boardId}/recur-rules`, {
			templateCardId: state.cardId,
			targetStackId: state.stackId,
			mode: 0,
			rrule: 'FREQ=WEEKLY',
			duedatePolicy: 1,
			duedateOffsetSeconds: SUB_DAY_OFFSET,
		})
		state.ruleId = rule.id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	const storedRule = async () => {
		const rules = await api.get(`/boards/${state.boardId}/recur-rules`)
		return rules.find((r) => Number(r.id) === Number(state.ruleId)) ?? null
	}

	test('changing only the target column leaves the sub-day offset intact', async ({ page }) => {
		const before = await storedRule()
		expect(Number(before.duedateOffsetSeconds)).toBe(SUB_DAY_OFFSET)

		const recurring = await openRuleEditor(page, state.boardId, 'Warm up the oven')

		// The offset is shown read-only: the raw duration with a note, and the
		// whole-day number input is not rendered at all.
		await expect(recurring.locator('.automation__custom-note')).toBeVisible()
		await expect(recurring.locator('.automation__custom-rrule')).toHaveText('1h')
		await expect(page.locator(`#recur-due-offset-${state.boardId}`)).toHaveCount(0)

		// Change an unrelated field — the target column — and save.
		await page.locator(`#recur-stack-${state.boardId}`).selectOption(String(state.otherStackId))
		await page.getByRole('button', { name: /^Save rule$/ }).click()

		// Read the rule back: the column moved, the offset survived byte for byte.
		await expect.poll(
			async () => Number((await storedRule())?.targetStackId),
			{ timeout: 8_000 },
		).toBe(Number(state.otherStackId))

		const after = await storedRule()
		expect(Number(after.duedateOffsetSeconds)).toBe(SUB_DAY_OFFSET)
		expect(Number(after.duedatePolicy)).toBe(1)
	})
})

test.describe('Editing a rule with a whole-day due-date offset (#10130)', () => {
	const state = { boardId: 0, stackId: 0, otherStackId: 0, cardId: 0, ruleId: 0 }
	const TWO_DAYS = 2 * 86400

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Day offset ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'Todo' })).id
		state.otherStackId = (await api.post('/stacks', { boardId: board.id, title: 'Filed' })).id
		state.cardId = (await api.post('/cards', { stackId: state.stackId, title: 'File the report' })).id
		state.ruleId = (await api.post(`/boards/${state.boardId}/recur-rules`, {
			templateCardId: state.cardId,
			targetStackId: state.stackId,
			mode: 0,
			rrule: 'FREQ=WEEKLY',
			duedatePolicy: 1,
			duedateOffsetSeconds: TWO_DAYS,
		})).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	// A whole-day offset stays fully editable through the days control, exactly
	// as before: the custom read-only branch must not swallow the ordinary case.
	test('the days control still edits a whole-day offset', async ({ page }) => {
		const recurring = await openRuleEditor(page, state.boardId, 'File the report')

		// The editable number input, populated from the stored offset — no note.
		await expect(recurring.locator('.automation__custom-note')).toHaveCount(0)
		const input = page.locator(`#recur-due-offset-${state.boardId}`)
		await expect(input).toHaveValue('2')

		// Bump it to three days and save.
		await input.fill('3')
		await page.getByRole('button', { name: /^Save rule$/ }).click()

		await expect.poll(async () => {
			const rules = await api.get(`/boards/${state.boardId}/recur-rules`)
			return Number(rules.find((r) => Number(r.id) === Number(state.ruleId))?.duedateOffsetSeconds ?? 0)
		}, { timeout: 8_000 }).toBe(3 * 86400)
	})
})
