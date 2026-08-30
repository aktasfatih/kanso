// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// #9897 gated the column actions menu and card drag on the board's EDIT bit, but
// the two WRITE keyboard shortcuts on the same surface — 'd' (toggle done) and
// '0'–'4' (set priority) — stayed ungated. A read-only member could focus a card
// with j/k and fire either one, and the only feedback was the server's 403
// rendering as an "Access denied" banner. These specs pin the shortcuts to
// editors on both board render paths (flat and swimlanes), and pin that they
// still work for an editor.

/** Count PATCHes to /api/cards/<id> that the page issues from here on. */
function watchCardPatches(page) {
	const seen = []
	page.on('request', (req) => {
		if (req.method() === 'PATCH' && /\/apps\/kanso\/api\/cards\/\d+(\?|$)/.test(req.url())) {
			seen.push(req.url())
		}
	})
	return seen
}

/** Pick a radio in the board's display menu ("Board"/"List", "Group by"). */
async function setDisplay(page, name) {
	await page.locator('.board-view__display-menu button').first().click()
	await page.getByRole('menuitemradio', { name, exact: true }).click()
	await page.keyboard.press('Escape')
}

test.describe('Done / priority shortcuts are editors only (#9978)', () => {
	// A second identity logs in explicitly, so this describe must NOT inherit the
	// shared admin storageState (it would silently stay admin and false-pass).
	test.use({ storageState: { cookies: [], origins: [] }, viewport: { width: 1600, height: 900 } })

	const state = { boardId: 0, stackId: 0, cardId: 0, boardUrl: '' }

	test.beforeAll(async ({ peer }) => {
		const board = await api.post('/boards', { title: 'Shortcut ACL ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Inbox' })
		state.stackId = stack.id
		const card = await api.post('/cards', { stackId: stack.id, title: 'Read-only card' })
		state.cardId = card.id
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

	test('the server refuses a done / priority PATCH from a viewer', async ({ peer }) => {
		// The UI gates below only hide dead affordances — this pins that the real
		// gate is server-side, so hiding them never becomes the only check.
		const done = await peer.api.raw('PATCH', `/cards/${state.cardId}`, { done: true })
		expect(done.status).toBe(403)
		const priority = await peer.api.raw('PATCH', `/cards/${state.cardId}`, { priority: 3 })
		expect(priority.status).toBe(403)
	})

	test("a viewer's 'd' and '0'–'4' fire no PATCH and are not advertised", async ({ browser, peer }) => {
		const ctx = await browser.newContext({ viewport: { width: 1600, height: 900 } })
		try {
			const page = await ctx.newPage()
			await ncLogin(page, { user: peer.user, pass: peer.pass })
			await page.goto(state.boardUrl)
			await page.waitForSelector('.board-view__header', { timeout: 15_000 })
			await expect(page.locator('.card-tile', { hasText: 'Read-only card' }))
				.toBeVisible({ timeout: 10_000 })

			const patches = watchCardPatches(page)

			// j focuses the card — navigation itself stays available to a viewer.
			await page.keyboard.press('j')
			await expect(page.locator('.card-tile').first()).toBeFocused({ timeout: 5000 })

			// Neither write shortcut may reach the API…
			await page.keyboard.press('d')
			await page.keyboard.press('3')
			await page.keyboard.press('0')
			await page.waitForTimeout(1500)
			expect(patches).toEqual([])

			// …and nothing may render the refusal banner ("Access denied" — the
			// server supplies `error`, so that string, not the generic fallback).
			await expect(page.locator('.board-view__move-error')).toHaveCount(0)
			await expect(page.locator('.card-tile--done')).toHaveCount(0)

			// The overlay must not teach a viewer keys the handler now refuses.
			await page.keyboard.press('?')
			const modal = page.locator('.modal-container, [role="dialog"]')
				.filter({ hasText: 'Keyboard shortcuts' })
			await expect(modal).toBeVisible({ timeout: 5000 })
			await expect(modal.getByText('Toggle done on focused card')).toHaveCount(0)
			await expect(modal.getByText('Set priority on focused card', { exact: false })).toHaveCount(0)
			// The read-only navigation rows are still listed — the overlay is
			// trimmed, not emptied.
			await expect(modal.getByText('Navigate cards up / down')).toHaveCount(1)
			await page.keyboard.press('Escape')
			await expect(modal).not.toBeVisible({ timeout: 5000 })

			// Swimlane render path (#9978): its :on-card-focus is unconditional too,
			// so the gate has to live in the handler, not on the prop sites.
			await setDisplay(page, 'Assignee')
			await expect(page.locator('.swimlane')).toHaveCount(1, { timeout: 10_000 })
			await expect(page.locator('.swimlane .card-tile', { hasText: 'Read-only card' }))
				.toBeVisible({ timeout: 8_000 })

			patches.length = 0
			await page.keyboard.press('j')
			await expect(page.locator('.swimlane .card-tile').first()).toBeFocused({ timeout: 5000 })
			await page.keyboard.press('d')
			await page.keyboard.press('4')
			await page.waitForTimeout(1500)
			expect(patches).toEqual([])
			await expect(page.locator('.board-view__move-error')).toHaveCount(0)
		} finally {
			await ctx.close()
		}
	})
})

test.describe('Done / priority shortcuts still work for an editor (#9978)', () => {
	test.use({ viewport: { width: 1600, height: 900 } })

	const state = { boardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Shortcut Editor ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Inbox' })
		await api.post('/cards', { stackId: stack.id, title: 'Editable card' })
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test("an editor fires exactly one PATCH per 'd' / priority key", async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		const tile = page.locator('.card-tile', { hasText: 'Editable card' })
		await expect(tile).toBeVisible({ timeout: 10_000 })

		const patches = watchCardPatches(page)

		await page.keyboard.press('j')
		await expect(tile).toBeFocused({ timeout: 5000 })

		await page.keyboard.press('3')
		await expect(tile.locator('.card-tile__priority')).toHaveClass(/card-tile__priority--3/, { timeout: 10_000 })
		expect(patches).toHaveLength(1)

		await page.keyboard.press('d')
		await expect(tile).toHaveClass(/card-tile--done/, { timeout: 10_000 })
		expect(patches).toHaveLength(2)

		// Both keys are advertised to an editor.
		await page.keyboard.press('?')
		const modal = page.locator('.modal-container, [role="dialog"]')
			.filter({ hasText: 'Keyboard shortcuts' })
		await expect(modal).toBeVisible({ timeout: 5000 })
		await expect(modal.getByText('Toggle done on focused card')).toHaveCount(1)
		await expect(modal.getByText('Set priority on focused card', { exact: false })).toHaveCount(1)
	})
})
