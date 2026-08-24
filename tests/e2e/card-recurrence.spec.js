// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, BASE, api, ncLogin } from './helpers.js'

// The card's own recurrence rule (its templateCardId points back at itself), if any.
async function cardRule(boardId, cardId) {
	const rules = await api.get(`/boards/${boardId}/recur-rules`)
	return rules.find((r) => Number(r.templateCardId) === Number(cardId)) ?? null
}

test.describe('Repeat from the card due-date menu (#55)', () => {
	const state = { boardId: 0, stackId: 0, cardId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Repeat ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'Tasks' })).id
		state.cardId = (await api.post('/cards', { stackId: state.stackId, title: 'Water the plants' })).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('setting Repeat creates, updates, then clears a recurring rule for the card', async ({ page }) => {
		// No rule to begin with.
		expect(await cardRule(state.boardId, state.cardId)).toBeNull()

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.cardId}`)
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 15_000 })

		// Open the due-date popover, where the Repeat control lives.
		await page.locator('[data-pill="due"]').click()
		const freq = page.locator('select[data-recur="freq"]')
		await expect(freq).toBeVisible({ timeout: 6_000 })

		// Choose "Weekly" → a rule is created with this card as its source and its
		// own column as the target, on a FREQ=WEEKLY schedule. The quick Repeat
		// control defaults to RESET mode (the card comes back each week rather than
		// spawning a duplicate) — see the recurring-UX overhaul.
		await freq.selectOption('WEEKLY')
		await expect.poll(
			async () => (await cardRule(state.boardId, state.cardId))?.rrule ?? null,
			{ timeout: 8_000 },
		).toContain('FREQ=WEEKLY')
		let rule = await cardRule(state.boardId, state.cardId)
		expect(Number(rule.templateCardId)).toBe(Number(state.cardId))
		expect(Number(rule.targetStackId)).toBe(Number(state.stackId))
		expect(Number(rule.mode)).toBe(1) // reset (the default for the quick Repeat control)

		// Bump the interval to 2 → the same rule updates to every 2 weeks.
		const interval = page.locator('input[data-recur="interval"]')
		await interval.fill('2')
		await interval.blur()
		await expect.poll(
			async () => (await cardRule(state.boardId, state.cardId))?.rrule ?? '',
			{ timeout: 8_000 },
		).toContain('INTERVAL=2')

		// Turn Repeat off → the rule is deleted.
		await freq.selectOption('OFF')
		await expect.poll(
			async () => await cardRule(state.boardId, state.cardId),
			{ timeout: 8_000 },
		).toBeNull()
	})
})

test.describe('Recurring indicator on the board tile (#61)', () => {
	const state = { boardId: 0, stackId: 0, recurCardId: 0, plainCardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Recur Tile ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'Chores' })).id
		state.recurCardId = (await api.post('/cards', { stackId: state.stackId, title: 'Recurring chore' })).id
		state.plainCardId = (await api.post('/cards', { stackId: state.stackId, title: 'One-off chore' })).id

		// Give the first card a live weekly rule (its own column is the target,
		// clone mode) - exactly what the due-date Repeat control creates.
		await api.post(`/boards/${state.boardId}/recur-rules`, {
			templateCardId: state.recurCardId,
			targetStackId: state.stackId,
			mode: 0,
			rrule: 'FREQ=WEEKLY',
		})

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${state.boardId}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('board summary carries the recurring boolean derived from the rule', async () => {
		const payload = await api.get(`/boards/${state.boardId}`)
		const byId = Object.fromEntries(payload.cards.map((c) => [c.id, c]))
		expect(byId[state.recurCardId].recurring).toBe(true)
		expect(byId[state.plainCardId].recurring).toBe(false)
	})

	test('repeat icon shows on the recurring tile and is absent on a normal tile', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		const recurTile = page.locator('.card-tile').filter({ hasText: 'Recurring chore' })
		const plainTile = page.locator('.card-tile').filter({ hasText: 'One-off chore' })

		await expect(recurTile.locator('.card-tile__recurring')).toBeVisible({ timeout: 10_000 })
		await expect(plainTile.locator('.card-tile__recurring')).toHaveCount(0)
	})
})

test.describe('Recurring indicator on the open card Due Date pill (#61 follow-up)', () => {
	const state = { boardId: 0, stackId: 0, recurCardId: 0, plainCardId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Recur Pill ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'Chores' })).id
		state.recurCardId = (await api.post('/cards', { stackId: state.stackId, title: 'Recurring pill card' })).id
		state.plainCardId = (await api.post('/cards', { stackId: state.stackId, title: 'One-off pill card' })).id

		// Live weekly rule on the first card (its own column is the target, clone
		// mode) - exactly what the due-date Repeat control creates.
		await api.post(`/boards/${state.boardId}/recur-rules`, {
			templateCardId: state.recurCardId,
			targetStackId: state.stackId,
			mode: 0,
			rrule: 'FREQ=WEEKLY',
		})
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('card show() payload carries the recurring boolean derived from the rule', async () => {
		const recur = await api.get(`/cards/${state.recurCardId}`)
		const plain = await api.get(`/cards/${state.plainCardId}`)
		expect(recur.recurring).toBe(true)
		expect(plain.recurring).toBe(false)
	})

	test('due-date pill shows the repeat icon for a recurring card, calendar icon otherwise', async ({ page }) => {
		await ncLogin(page)

		// Recurring card → the Due Date pill renders the repeat glyph, not calendar.
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.recurCardId}`)
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 15_000 })
		const recurPill = page.locator('[data-pill="due"]')
		await expect(recurPill.locator('.repeat-icon')).toBeVisible({ timeout: 10_000 })
		await expect(recurPill.locator('.calendar-icon')).toHaveCount(0)

		// Non-recurring card → the calendar glyph stays, no repeat icon.
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.plainCardId}`)
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 15_000 })
		const plainPill = page.locator('[data-pill="due"]')
		await expect(plainPill.locator('.calendar-icon')).toBeVisible({ timeout: 10_000 })
		await expect(plainPill.locator('.repeat-icon')).toHaveCount(0)
	})
})

// Deterministic regression guards for the recurring-schedule bugs that kept
// coming back. All checks are API-level on the rule's cached next fire time and
// on create-now (which spawns synchronously), so nothing waits on the 15-minute
// cron - each assertion is exact and flake-free.
test.describe('Recurring schedule edge cases (regression guards)', () => {
	const state = { boardId: 0, stackId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Recur Edge ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'Tasks' })).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	const nowSec = () => Math.floor(Date.now() / 1000)
	const iso = (ts) => new Date(ts * 1000).toISOString()
	const makeCard = async (title) => (await api.post('/cards', { stackId: state.stackId, title })).id
	const addRule = (cardId, rrule, mode = 1) => api.post(`/boards/${state.boardId}/recur-rules`, {
		templateCardId: cardId, targetStackId: state.stackId, mode, rrule,
	})
	const ruleFor = async (cardId) => {
		const rules = await api.get(`/boards/${state.boardId}/recur-rules`)
		return rules.find((r) => Number(r.templateCardId) === Number(cardId)) ?? null
	}

	// #80: a brand-new repeat must be armed in the FUTURE, never ready to fire on
	// the very next cron tick (which reset the card's date to its creation day).
	test('a new yearly repeat is scheduled in the future, not immediately due (#80)', async () => {
		const cardId = await makeCard('Yearly not-immediate ' + Date.now())
		const rule = await addRule(cardId, 'FREQ=YEARLY')
		const now = nowSec()
		expect(rule.nextOccurrenceAt).toBeGreaterThan(now)
		// A year out, not "today" - well beyond any single-day margin.
		expect(rule.nextOccurrenceAt).toBeGreaterThan(now + 300 * 86400)
	})

	// The schedule anchors on the card's Start date: a Start set for the future
	// makes the first fire land ON that date.
	test('a repeat anchors on the card start date and first fires on a future start', async () => {
		const cardId = await makeCard('Future start anchor ' + Date.now())
		const startTs = nowSec() + 30 * 86400
		await api.patch(`/cards/${cardId}`, { startDate: iso(startTs) })
		const rule = await addRule(cardId, 'FREQ=YEARLY')
		expect(rule.nextOccurrenceAt).toBe(startTs)
	})

	// Editing the Start date re-points the whole series (reschedule-it-and-it-
	// follows), instead of the repeat staying pinned to the original date.
	test('editing the card start date re-points the repeat schedule', async () => {
		const cardId = await makeCard('Re-anchor ' + Date.now())
		const start1 = nowSec() + 10 * 86400
		await api.patch(`/cards/${cardId}`, { startDate: iso(start1) })
		await addRule(cardId, 'FREQ=YEARLY')
		expect((await ruleFor(cardId)).nextOccurrenceAt).toBe(start1)

		const start2 = nowSec() + 40 * 86400
		await api.patch(`/cards/${cardId}`, { startDate: iso(start2) })
		expect((await ruleFor(cardId)).nextOccurrenceAt).toBe(start2)
	})

	// Speeding up the cadence takes effect promptly: Weekly → Daily starts firing
	// the next day, not only once the old far-off weekly date arrives.
	test('changing weekly to daily takes effect from the next day, not the old weekly date', async () => {
		const cardId = await makeCard('Cadence speedup ' + Date.now())
		const rule = await addRule(cardId, 'FREQ=WEEKLY')
		const now = nowSec()
		expect(rule.nextOccurrenceAt).toBeGreaterThan(now + 5 * 86400) // ~a week out
		await api.patch(`/recur-rules/${rule.id}`, { rrule: 'FREQ=DAILY' })
		const daily = await ruleFor(cardId)
		expect(daily.nextOccurrenceAt).toBeGreaterThan(now)
		expect(daily.nextOccurrenceAt).toBeLessThanOrEqual(now + 2 * 86400) // within a day-ish
	})

	// The whole Start→End window slides forward on a spawn, keeping its length:
	// a 1-hour clone stays a 1-hour clone.
	test('create-now clones the card with its start–end window slid forward, length preserved', async () => {
		const cardId = await makeCard('Window slide ' + Date.now())
		const startTs = nowSec() + 2 * 86400
		await api.patch(`/cards/${cardId}`, { startDate: iso(startTs), duedate: iso(startTs + 3600) })
		const rule = await addRule(cardId, 'FREQ=WEEKLY', 0) // clone mode
		const clone = (await api.post(`/recur-rules/${rule.id}/create-now`)).card
		expect(Number(clone.id)).not.toBe(Number(cardId))
		expect(clone.startDate).toBeTruthy()
		expect(clone.duedate).toBeTruthy()
		// The 1-hour gap between start and end is preserved on the clone.
		const gapSeconds = (Date.parse(clone.duedate) - Date.parse(clone.startDate)) / 1000
		expect(gapSeconds).toBe(3600)
	})

	// An all-day card is a single day (End date at UTC midnight); its repeat must
	// anchor on that day. A future all-day date fires first on that exact day.
	test('an all-day repeat anchors on the all-day date (UTC midnight)', async () => {
		const cardId = await makeCard('All-day repeat ' + Date.now())
		// A future all-day day at UTC midnight, ~60 days out.
		const base = new Date((nowSec() + 60 * 86400) * 1000)
		const dayTs = Math.floor(Date.UTC(base.getUTCFullYear(), base.getUTCMonth(), base.getUTCDate()) / 1000)
		await api.patch(`/cards/${cardId}`, { duedate: new Date(dayTs * 1000).toISOString(), allDay: true })
		const rule = await addRule(cardId, 'FREQ=DAILY')
		// Anchored on the all-day day and it's in the future, so the first fire is
		// that exact day (UTC midnight), not shifted off it.
		expect(rule.nextOccurrenceAt).toBe(dayTs)
	})

	// The end-before-start guard: an inverted window is refused with a 400.
	test('setting an end date before the start date is rejected', async () => {
		const cardId = await makeCard('Inverted window ' + Date.now())
		await api.patch(`/cards/${cardId}`, { startDate: iso(nowSec() + 10 * 86400) })
		// End 5 days out is before the start 10 days out → rejected.
		const r = await api.raw('PATCH', `/cards/${cardId}`, { duedate: iso(nowSec() + 5 * 86400) })
		expect(r.status).toBe(400)
	})
})
