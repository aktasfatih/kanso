// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, BASE, api, ncLogin } from './helpers.js'

const ROLE_IN_PROGRESS = 3

// #10064 — a board with NO workflow roles is the DEFAULT shape in Kanso (nothing
// in the create paths sets a role). Until now such a board never showed the card's
// column anywhere in the card view, and its breadcrumb chip offered only the three
// generic statuses. The card view must now read `Board > Column > [status chip]`
// on every board, and the chip's picker must offer the columns AND the statuses —
// so a card can change column without changing status, and change status without
// leaving its column.
test.describe('Card column in the breadcrumb (#10064)', () => {
	const state = {
		boardId: 0,
		inboxId: 0,
		workingId: 0,
		moveCardId: 0,
		statusCardId: 0,
		roledBoardId: 0,
		roledWaitingId: 0,
		roledDoingId: 0,
		roledCardId: 0,
	}

	const cardUrl = (boardId, cardId) => `${BASE}/index.php/apps/kanso#/board/${boardId}/card/${cardId}`

	test.beforeAll(async () => {
		// Role-less board: two plain columns, exactly what /boards + /stacks create.
		const board = await api.post('/boards', { title: 'Colcrumb ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.inboxId = (await api.post('/stacks', { boardId: board.id, title: 'Intake column' })).id
		state.workingId = (await api.post('/stacks', { boardId: board.id, title: 'Working column' })).id
		state.moveCardId = (await api.post('/cards', { stackId: state.inboxId, title: 'Column crumb card' })).id
		state.statusCardId = (await api.post('/cards', { stackId: state.inboxId, title: 'Status keeper card' })).id

		// Roled board: the chip must show the STATUS here too, not the column stage.
		const roled = await api.post('/boards', { title: 'Colcrumb roles ' + Math.floor(Date.now() / 1000) })
		state.roledBoardId = roled.id
		state.roledWaitingId = (await api.post('/stacks', { boardId: roled.id, title: 'Waiting room' })).id
		state.roledDoingId = (await api.post('/stacks', { boardId: roled.id, title: 'Doing' })).id
		await api.patch(`/stacks/${state.roledDoingId}`, { role: ROLE_IN_PROGRESS })
		// The card lives in the board's role-less column, so its status is "Not
		// started" while its column is "Waiting room" — the case that tells a status
		// chip apart from the old column-stage chip.
		state.roledCardId = (await api.post('/cards', { stackId: state.roledWaitingId, title: 'Roled chip card' })).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
		if (state.roledBoardId) await api.delete(`/boards/${state.roledBoardId}`).catch(() => {})
	})

	test('a role-less board shows the column in the breadcrumb without opening anything', async ({ page }) => {
		await ncLogin(page)
		await page.goto(cardUrl(state.boardId, state.moveCardId))
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 15_000 })

		// The column is readable straight away — no popover, no menu.
		await expect(page.locator('.card-modal__popover')).toHaveCount(0)
		await expect(page.locator('.card-modal__crumb--column')).toHaveText('Intake column')

		// The chip next to it is the STATUS, not the column.
		await expect(page.locator('.card-modal__status-chip--btn')).toContainText('NOT STARTED')

		// The copyable KAN- reference is still in the header.
		await expect(page.locator('.card-modal__header .card-modal__ref')).toBeVisible()
	})

	test('the chip picker moves the card between role-less columns without touching the status', async ({ page }) => {
		// Give the card a real, non-zero status first, so the move has something to
		// preserve — a 0 → 0 assertion would pass even if the move stamped nothing.
		await ncLogin(page)
		await page.goto(cardUrl(state.boardId, state.statusCardId))
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 15_000 })

		// The three statuses stay reachable on a role-less board — the chip picker is
		// their only full entry point (the header button never reaches "not started").
		await page.locator('.card-modal__status-chip--btn').click()
		await page.locator('.card-modal__status-wrap .card-modal__popover-opt--status', { hasText: 'In progress' }).click()
		await expect(page.locator('.card-modal__status-chip--in_progress')).toBeVisible({ timeout: 8_000 })

		// A role-less board has no in-progress column to move to, so the card stays put.
		let card = await api.get(`/cards/${state.statusCardId}`)
		const startedAt = Number(card.startedAt)
		expect(startedAt).toBeGreaterThan(0)
		expect(card.stackId).toBe(state.inboxId)
		await expect(page.locator('.card-modal__crumb--column')).toHaveText('Intake column')

		// Now change the COLUMN from the same picker.
		await page.locator('.card-modal__status-chip--btn').click()
		await page.locator('.card-modal__status-wrap .card-modal__popover-opt--column', { hasText: 'Working column' }).click()

		await expect.poll(
			async () => (await api.get(`/cards/${state.statusCardId}`)).stackId,
			{ timeout: 8_000 },
		).toBe(state.workingId)

		// …and the status is untouched: same started_at, still not done.
		card = await api.get(`/cards/${state.statusCardId}`)
		expect(Number(card.startedAt)).toBe(startedAt)
		expect(Number(card.doneAt)).toBe(0)

		// The breadcrumb follows the move; the chip still reads the status.
		await expect(page.locator('.card-modal__crumb--column')).toHaveText('Working column', { timeout: 8_000 })
		await expect(page.locator('.card-modal__status-chip--btn')).toContainText('IN PROGRESS')
	})

	test('the chip shows the status on a roled board too, with the column in the breadcrumb', async ({ page }) => {
		await ncLogin(page)
		await page.goto(cardUrl(state.roledBoardId, state.roledCardId))
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 15_000 })

		await expect(page.locator('.card-modal__crumb--column')).toHaveText('Waiting room')
		// This board uses workflow roles, so the chip used to render the column stage.
		// It must now read the card's status instead — the column is in the crumb.
		await expect(page.locator('.card-modal__status-chip--btn')).toContainText('NOT STARTED')

		// Both sections are offered on a roled board as well.
		await page.locator('.card-modal__status-chip--btn').click()
		const popover = page.locator('.card-modal__status-wrap .card-modal__popover')
		await expect(popover.locator('.card-modal__popover-opt--column')).not.toHaveCount(0)
		await expect(popover.locator('.card-modal__popover-opt--status')).toHaveCount(3)
	})
})
