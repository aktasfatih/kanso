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
		// own column as the target, cloning on a FREQ=WEEKLY schedule.
		await freq.selectOption('WEEKLY')
		await expect.poll(
			async () => (await cardRule(state.boardId, state.cardId))?.rrule ?? null,
			{ timeout: 8_000 },
		).toContain('FREQ=WEEKLY')
		let rule = await cardRule(state.boardId, state.cardId)
		expect(Number(rule.templateCardId)).toBe(Number(state.cardId))
		expect(Number(rule.targetStackId)).toBe(Number(state.stackId))
		expect(Number(rule.mode)).toBe(0) // clone

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
