// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// Titles of the cards currently in a stack (from the board summary payload).
async function stackTitles(boardId, stackId) {
	const board = await api.get(`/boards/${boardId}`)
	return board.cards
		.filter((c) => c.stackId === stackId && !c.archived)
		.map((c) => c.title)
		.sort()
}

// #3523 — multi-select cards, then bulk-move them to another stack.
test.describe('Bulk edit cards (multi-select)', () => {
	const state = { boardId: 0, todoId: 0, doneId: 0, boardUrl: '' }

	test.beforeAll(async ({ peer }) => {
		const board = await api.post('/boards', { title: 'Bulk-Edit E2E' })
		state.boardId = board.id
		state.todoId = (await api.post('/stacks', { boardId: board.id, title: 'To Do' })).id
		state.doneId = (await api.post('/stacks', { boardId: board.id, title: 'Done' })).id
		// The bulk bar's menus must each hold MORE than one entry to render as
		// menus at all: NcActions renders nothing for an empty menu and collapses
		// to a single immediate-action button for a one-entry one. Two labels and
		// a second board member (the browser stays the owner — the peer is only
		// there to be assignable) give every menu in the bar its real shape.
		await api.post('/labels', { boardId: board.id, title: 'Bug', color: 'e07b00' })
		await api.post('/labels', { boardId: board.id, title: 'Chore', color: '2ecc71' })
		await api.post(`/boards/${board.id}/acl`, {
			participant: peer.user,
			participantType: 'user',
			permission: 3,
		})
		await api.post('/cards', { stackId: state.todoId, title: 'Alpha' })
		await api.post('/cards', { stackId: state.todoId, title: 'Bravo' })
		await api.post('/cards', { stackId: state.todoId, title: 'Charlie' })
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('selecting two cards and bulk-moving lands them in the target stack', async ({ page }) => {
		expect(await stackTitles(state.boardId, state.todoId)).toEqual(['Alpha', 'Bravo', 'Charlie'])
		expect(await stackTitles(state.boardId, state.doneId)).toEqual([])

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 15_000 })
		await expect(page.locator('.card-tile', { hasText: 'Alpha' })).toBeVisible({ timeout: 10_000 })

		// Enter multi-select mode via the consolidated ⋯ More overflow menu.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: 'Select multiple cards' }).click()

		// Selection checkboxes now appear on the tiles; select Alpha and Bravo.
		await page.locator('.card-tile', { hasText: 'Alpha' }).click()
		await page.locator('.card-tile', { hasText: 'Bravo' }).click()

		// The bulk action bar reports the selection count.
		await expect(page.locator('.bulk-action-bar')).toContainText('2')

		// #10275 — every control in the bar shows a hover hint, so a sighted mouse
		// user can tell the icon-only buttons apart. The plain buttons carry `title`
		// themselves; the menu triggers get it from the wrapping `.action-item`
		// (NcActions forwards fall-through attributes to its root element, not to
		// the trigger button) — which is exactly what the browser resolves a
		// tooltip from when the button itself has none. Polled: the assignee list
		// is a separate request, so the "Assign…" menu appears a beat after the
		// rest of the bar.
		await expect.poll(
			() => page.locator('.bulk-action-bar button').evaluateAll(
				(els) => els.map((el) => el.closest('[title]')?.getAttribute('title') ?? null),
			),
			{ timeout: 10_000 },
		).toEqual([
			'Move to…',
			'Add label…',
			'Remove label…',
			'Assign…',
			'Set due date…',
			'Mark done',
			'Archive selected',
			'Delete selected',
			'Exit selection mode',
		])

		// Open the "Move to…" menu and pick the Done stack.
		await page.getByRole('button', { name: 'Move to…' }).click()
		await page.getByRole('menuitem', { name: 'Done' }).click()

		// Both selected cards land in Done; Charlie stays in To Do.
		await expect
			.poll(() => stackTitles(state.boardId, state.doneId), { timeout: 10_000 })
			.toEqual(['Alpha', 'Bravo'])
		await expect
			.poll(() => stackTitles(state.boardId, state.todoId), { timeout: 10_000 })
			.toEqual(['Charlie'])
	})
})
