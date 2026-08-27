// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// The inline "Add card…" composer is a write affordance: creating a card needs
// the board's EDIT bit server-side, so a read-only member who sees the input
// only gets an error on Enter. These specs pin the composer (and its sibling
// "from template" picker) to editors in all three board views.

/** Pick a radio in the board's display menu ("Board"/"List", or a Group by mode). */
async function setDisplay(page, name) {
	await page.locator('.board-view__display-menu button').first().click()
	await page.getByRole('menuitemradio', { name, exact: true }).click()
	await page.keyboard.press('Escape')
}

test.describe('Card composer is editors only (#9857)', () => {
	// A second identity logs in explicitly, so this describe must NOT inherit the
	// shared admin storageState (it would silently stay admin and false-pass).
	test.use({ storageState: { cookies: [], origins: [] }, viewport: { width: 1600, height: 900 } })

	const state = { boardId: 0, stackId: 0, boardUrl: '' }

	test.beforeAll(async ({ peer }) => {
		const board = await api.post('/boards', { title: 'Composer ACL ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Inbox' })
		state.stackId = stack.id
		await api.post('/cards', { stackId: stack.id, title: 'Read-only card' })
		// READ only (1) — no EDIT bit, so canEditBoard is false for the peer.
		await api.post(`/boards/${board.id}/acl`, {
			participant: peer.user,
			participantType: 'user',
			permission: 1,
		})
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('the server refuses a card create from a viewer', async ({ peer }) => {
		// The UI gate below only hides a dead affordance — this pins that the real
		// gate is server-side, so hiding the input never becomes the only check.
		const denied = await peer.api.raw('POST', '/cards', {
			stackId: state.stackId,
			title: 'Should never be created',
		})
		expect(denied.status).toBe(403)
	})

	test('a viewer gets no "Add card…" composer in kanban, swimlane or list view', async ({ browser, peer }) => {
		const ctx = await browser.newContext({ viewport: { width: 1600, height: 900 } })
		try {
			const page = await ctx.newPage()
			await ncLogin(page, { user: peer.user, pass: peer.pass })
			await page.goto(state.boardUrl)
			await page.waitForSelector('.board-view__header', { timeout: 15_000 })

			// Kanban — the column and its card render for them…
			await expect(page.locator('.stack-column')).toHaveCount(1, { timeout: 10_000 })
			await expect(page.locator('.card-tile', { hasText: 'Read-only card' }))
				.toBeVisible({ timeout: 8_000 })
			// …but neither the composer nor the "from template" picker is offered.
			await expect(page.locator('.card-composer__input')).toHaveCount(0)
			await expect(page.locator('.card-composer__templates')).toHaveCount(0)

			// Swimlanes — grouping by label always yields at least the "No label" lane.
			await setDisplay(page, 'Label')
			await expect(page.locator('.swimlane')).toHaveCount(1, { timeout: 10_000 })
			await expect(page.locator('.swimlane .card-tile', { hasText: 'Read-only card' }))
				.toBeVisible({ timeout: 8_000 })
			await expect(page.locator('.swimlane .card-composer__input')).toHaveCount(0)
			await setDisplay(page, 'None')

			// List view.
			await setDisplay(page, 'List')
			await page.waitForSelector('.board-list-group', { timeout: 10_000 })
			await expect(page.locator('.board-list-row', { hasText: 'Read-only card' }))
				.toBeVisible({ timeout: 8_000 })
			await expect(page.locator('.board-list-table .card-composer__input')).toHaveCount(0)
			await expect(page.locator('.board-list-table .card-composer__templates')).toHaveCount(0)
		} finally {
			await ctx.close()
		}
	})
})

test.describe('Card composer stays available to editors (#9857)', () => {
	test.use({ viewport: { width: 1600, height: 900 } })

	const state = { boardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Composer Editor ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Inbox' })
		await api.post('/cards', { stackId: stack.id, title: 'Editable card' })
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('an editor can still quick-add in kanban, swimlane and list view', async ({ page }) => {
		await page.goto(state.boardUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Kanban — composer present and functional; the picker rides along.
		const kanbanComposer = page.locator('.stack-column .card-composer__input')
		await expect(kanbanComposer).toBeVisible({ timeout: 10_000 })
		await expect(page.locator('.stack-column .card-composer__templates')).toHaveCount(1)
		await kanbanComposer.fill('Added from kanban')
		await kanbanComposer.press('Enter')
		await expect(page.locator('.card-tile', { hasText: 'Added from kanban' }))
			.toBeVisible({ timeout: 10_000 })

		// Swimlanes — the lane's column keeps its composer.
		await setDisplay(page, 'Label')
		await expect(page.locator('.swimlane')).toHaveCount(1, { timeout: 10_000 })
		const laneComposer = page.locator('.swimlane .card-composer__input').first()
		await expect(laneComposer).toBeVisible({ timeout: 8_000 })
		await laneComposer.fill('Added from swimlane')
		await laneComposer.press('Enter')
		await expect(page.locator('.swimlane .card-tile', { hasText: 'Added from swimlane' }))
			.toBeVisible({ timeout: 10_000 })
		await setDisplay(page, 'None')

		// List view.
		await setDisplay(page, 'List')
		await page.waitForSelector('.board-list-group', { timeout: 10_000 })
		const listComposer = page.locator('.board-list-table .card-composer__input').first()
		await expect(listComposer).toBeVisible({ timeout: 8_000 })
		await expect(page.locator('.board-list-table .card-composer__templates')).toHaveCount(1)
		await listComposer.fill('Added from list')
		await listComposer.press('Enter')
		await expect(page.locator('.board-list-row', { hasText: 'Added from list' }))
			.toBeVisible({ timeout: 10_000 })
	})
})
