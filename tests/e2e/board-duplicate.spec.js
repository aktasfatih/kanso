// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// Duplicate board (#3543): a READ-authorized board is cloned server-side into a
// FRESH board the caller owns (export→import in-process). "Copy cards too"
// controls whether the card graph rides along; either way stacks/labels/rules
// are cloned and the new copy opens.
test.describe('Duplicate board (#3543)', () => {
	const state = { boardId: 0, copyBoardId: 0 }
	const title = 'Duplicate E2E ' + Math.floor(Date.now() / 1000)

	test.beforeAll(async () => {
		const board = await api.send('POST', '/boards', { title })
		state.boardId = board.id
		const todo = await api.send('POST', '/stacks', { boardId: board.id, title: 'To do' })
		await api.send('POST', '/stacks', { boardId: board.id, title: 'Done' })
		await api.send('POST', '/labels', { boardId: board.id, title: 'Priority', color: 'e11d48' })
		await api.send('POST', '/cards', { stackId: todo.id, title: 'Alpha' })
		await api.send('POST', '/cards', { stackId: todo.id, title: 'Beta' })
	})

	test.afterAll(async () => {
		if (state.copyBoardId) await api.send('DELETE', `/boards/${state.copyBoardId}`).catch(() => {})
		if (state.boardId) await api.send('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('duplicates a populated board with cards and opens the copy', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Board settings now lives in the consolidated ⋯ More overflow menu.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()

		// Board actions moved into the General tab; open it first.
		await page.getByRole('tab', { name: 'General' }).click()

		// The duplicate action lives in the General tab's board-actions block,
		// next to Export.
		const dupBtn = page.locator('[data-test="board-duplicate"]')
		await expect(dupBtn).toBeVisible({ timeout: 8_000 })

		// "Copy cards too" defaults on; assert and keep it on for this run.
		const withCards = page.locator('[data-test="board-duplicate-with-cards"]')
		await expect(withCards).toBeChecked()

		await dupBtn.click()

		// The router navigates to the new copy: URL changes to a different board id.
		await page.waitForURL(
			(url) => /#\/board\/\d+/.test(url.hash) && !url.hash.includes(`/board/${state.boardId}`),
			{ timeout: 15_000 },
		)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		const m = page.url().match(/\/board\/(\d+)/)
		state.copyBoardId = Number(m[1])
		expect(state.copyBoardId).not.toBe(state.boardId)

		// The copy carries the source stacks + cards (verified through the API,
		// which is the source of truth the board view renders from).
		const copy = await api.send('GET', `/boards/${state.copyBoardId}/export`)
		expect(copy.board.title).toBe(`${title} (copy)`)
		expect(copy.board.stacks.map((s) => s.title).sort()).toEqual(['Done', 'To do'])
		expect(copy.board.labels.map((l) => l.title)).toEqual(['Priority'])
		expect(copy.board.cards.map((c) => c.title).sort()).toEqual(['Alpha', 'Beta'])

		// Fresh board id (owned by the caller), distinct from the source.
		expect(copy.board.title).not.toBe(title)
	})

	test('structural-only clone (no cards) via the API', async () => {
		const res = await api.send('POST', `/boards/${state.boardId}/duplicate`, { withCards: false })
		try {
			expect(res.boardId).not.toBe(state.boardId)
			expect(res.title).toBe(`${title} (copy)`)
			expect(res.stacks).toBe(2)
			expect(res.labels).toBe(1)
			expect(res.cards).toBe(0)

			const doc = await api.send('GET', `/boards/${res.boardId}/export`)
			expect(doc.board.cards).toHaveLength(0)
			expect(doc.board.stacks).toHaveLength(2)
		} finally {
			await api.send('DELETE', `/boards/${res.boardId}`).catch(() => {})
		}
	})
})
