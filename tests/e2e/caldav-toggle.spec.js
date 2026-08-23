// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE, adminAuth } from './helpers.js'

// Does the user's CalDAV calendar-home currently list this board's calendar?
async function calendarPresent(boardId) {
	const r = await fetch(`${BASE}/remote.php/dav/calendars/admin/`, {
		method: 'PROPFIND',
		headers: { Authorization: adminAuth, Depth: '1' },
	})
	return (await r.text()).includes(`app-generated--kanso--board-${boardId}`)
}

// Per-user "show this board in my calendar" toggle (issue #49). Boards sync to
// your CalDAV calendar by default; this personal switch (in board settings →
// General, available to any member) hides the noisy ones from YOUR calendar.
// Driven through the real UI so a broken switch binding can't pass silently
// (the calendar-feed switch once regressed exactly that way).
test.describe('CalDAV per-board calendar toggle (UI)', () => {
	const state = { boardId: 0, cardId: 0 }

	test.beforeAll(async () => {
		const board = await api.send('POST', '/boards', { title: 'CalDAV toggle ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const stack = await api.send('POST', '/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api.send('POST', '/cards', { stackId: stack.id, title: 'due card' })
		state.cardId = card.id
		await api.send('PATCH', `/cards/${card.id}`, { duedate: '2026-08-15T09:30:00+00:00' })
	})

	test.afterAll(async () => {
		// Re-enable (clears the per-user hidden preference) then delete the board.
		await api.send('PUT', `/boards/${state.boardId}/calendar-sync`, { enabled: true }).catch(() => {})
		if (state.boardId) await api.send('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('toggling it off removes the board from your calendar, on restores it', async ({ page }) => {
		// Default: on, so the board is already in the calendar-home.
		expect(await calendarPresent(state.boardId)).toBe(true)

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /general/i }).click()
		await expect(page.locator('#bs-pane-general')).toBeVisible({ timeout: 8_000 })
		// Personal calendar switch lives in the General pane (any member).
		const toggle = page.getByText('Show this board in my calendar')
		await expect(toggle).toBeVisible({ timeout: 8_000 })

		// Turn it OFF → the board must drop out of the calendar-home.
		await toggle.click()
		await expect.poll(() => calendarPresent(state.boardId), { timeout: 8_000 }).toBe(false)

		// Turn it back ON → it returns.
		await toggle.click()
		await expect.poll(() => calendarPresent(state.boardId), { timeout: 8_000 }).toBe(true)
	})
})
