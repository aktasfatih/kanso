// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Browser tab title (#125). Nextcloud renders `<title>` once, server-side, from
// the app name + the instance name, and Kanso is a hash-router SPA — so before
// this every route (every board, every card) shared one tab title, and a folder
// of bookmarked boards was indistinguishable.
//
// This is the only test in the suite that asserts a page title, so it is the
// whole safety net for the feature. It deliberately checks the SUFFIX by
// capturing whatever the server rendered on the boards list rather than
// hardcoding "Kanso - Nextcloud": the app name is translated and the instance
// name is themable, so a literal would be wrong on a themed instance and the
// assertion would be testing the fixture instead of the behaviour.
//
// The last two navigations are the parts most likely to regress:
//   * closing a card must give the BOARD title back (the card route is nested
//     inside the board route, so both components hold a claim at once), and
//   * returning to the boards list must restore the base title exactly — no
//     stale prefix, and no prefix compounded onto the previous tab title.

import { test, expect, BASE, api, ncLogin, gotoBoard, boardUrl } from './helpers.js'

/** Escape a fixture title for use inside a RegExp. */
function esc(s) {
	return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

test.describe('Browser tab title (#125)', () => {
	const BOARD_TITLE = 'Tab Title E2E ' + Date.now()
	const CARD_TITLE = 'Tab title card ' + Date.now()
	const state = { boardId: 0, stackId: 0, cardId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: BOARD_TITLE })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id
		const card = await api.post('/cards', { stackId: stack.id, title: CARD_TITLE })
		state.cardId = card.id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('the tab title follows the board, the open card, and the feed you are on', async ({ page }) => {
		await ncLogin(page)

		// ── The boards list keeps Nextcloud's own title ──────────────────────────
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await page.waitForSelector('.board-list-view', { timeout: 15_000 })
		const baseTitle = (await page.title()).trim()
		// Sanity-check the captured suffix so a broken fixture can't make every
		// assertion below trivially true.
		expect(baseTitle).toContain('Kanso')
		expect(baseTitle).not.toContain(BOARD_TITLE)

		// ── A board puts its own name in front ──────────────────────────────────
		await gotoBoard(page, state.boardId)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await expect(page).toHaveTitle(new RegExp('^' + esc(BOARD_TITLE)))
		// …in front of the SAME suffix the server rendered, with core's separator.
		await expect(page).toHaveTitle(BOARD_TITLE + ' - ' + baseTitle)

		// ── An open card takes the title over ───────────────────────────────────
		const tile = page.locator('.card-tile', { hasText: CARD_TITLE })
		await expect(tile).toBeVisible({ timeout: 15_000 })
		await tile.click()
		const modal = page.locator('.card-modal')
		await expect(modal).toBeVisible({ timeout: 15_000 })
		await expect(page).toHaveTitle(new RegExp('^' + esc(CARD_TITLE)))

		// ── …and closing it gives the BOARD title back, not the bare app name ───
		// This is the claim-stack behaviour: CardModal is mounted INSIDE BoardView,
		// so popping its claim has to fall through to the board's.
		await page.keyboard.press('Escape')
		await expect(modal).toBeHidden({ timeout: 10_000 })
		await expect(page).toHaveURL(boardUrl(state.boardId))
		await expect(page).toHaveTitle(BOARD_TITLE + ' - ' + baseTitle)

		// ── A cross-board feed names itself ─────────────────────────────────────
		await page.goto(`${BASE}/index.php/apps/kanso#/my-tasks`)
		await page.waitForSelector('.my-cards-view', { timeout: 15_000 })
		await expect(page).toHaveTitle('My tasks - ' + baseTitle)

		// ── Back to the list restores the base title exactly ────────────────────
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await page.waitForSelector('.board-list-view', { timeout: 15_000 })
		await expect(page).toHaveTitle(baseTitle)
	})

	test('a full-page card link is titled with the card, and board analytics names its board', async ({ page }) => {
		await ncLogin(page)

		// A deep-linked full-page card (no board route underneath it) still titles
		// the tab with the card once the card query resolves.
		await page.goto(`${BASE}/index.php/apps/kanso#/card/${state.cardId}`)
		await page.waitForSelector('.card-modal--mode-page', { timeout: 15_000 })
		await expect(page).toHaveTitle(new RegExp('^' + esc(CARD_TITLE)))

		// Board sub-pages qualify the board name with the section they show, so two
		// tabs on the same board are tellable apart.
		//
		// This is the SOFT path — reached the way a user usually reaches it, through
		// the board, with the board query already warm. The hard path (a bookmark or
		// a refresh of the stats URL, where the cache is cold and BoardStats has to
		// fetch the board itself) is covered in board-stats.spec.js, which asserts
		// the tab title and the bar labels together; it cannot be asserted from here
		// because a `page.goto` that only changes the hash is a same-document
		// navigation and would leave this page's cache warm.
		await gotoBoard(page, state.boardId)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/stats`)
		await page.waitForSelector('.board-stats__body', { timeout: 15_000 })
		await expect(page).toHaveTitle(new RegExp('^' + esc(BOARD_TITLE) + ' · Analytics'))
	})
})
