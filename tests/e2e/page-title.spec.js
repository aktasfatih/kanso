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
//
// The board→board test is the one exception to "assert the title" — it asserts
// the SEQUENCE of titles, recorded off the <title> node with a MutationObserver,
// because the thing it forbids (#10445) is a transient that no polling assertion
// can sample. See its own comments.

import { test, expect, BASE, api, ncLogin, gotoBoard, boardUrl } from './helpers.js'

/** Escape a fixture title for use inside a RegExp. */
function esc(s) {
	return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

test.describe('Browser tab title (#125)', () => {
	const BOARD_TITLE = 'Tab Title E2E ' + Date.now()
	const BOARD_B_TITLE = 'Tab Title E2E Next ' + Date.now()
	const CARD_TITLE = 'Tab title card ' + Date.now()
	const state = { boardId: 0, stackId: 0, cardId: 0, boardBId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: BOARD_TITLE })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id
		const card = await api.post('/cards', { stackId: stack.id, title: CARD_TITLE })
		state.cardId = card.id
		// A second board, for the board→board switch below.
		const boardB = await api.post('/boards', { title: BOARD_B_TITLE })
		state.boardBId = boardB.id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
		if (state.boardBId) await api.delete(`/boards/${state.boardBId}`).catch(() => {})
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

	test('switching straight from one board to another never blinks the bare app name (#10445)', async ({ page }) => {
		await ncLogin(page)

		// Start on the boards list, which renders from the ['boards'] query — so
		// reaching it establishes the premise the fix rests on: that list is warm
		// before any in-app board switch, and it already carries every board's name.
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await page.waitForSelector('.board-list-view', { timeout: 15_000 })
		const baseTitle = (await page.title()).trim()
		expect(baseTitle).toContain('Kanso')
		expect(baseTitle).not.toContain(BOARD_TITLE)
		expect(baseTitle).not.toContain(BOARD_B_TITLE)

		await gotoBoard(page, state.boardId)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await expect(page).toHaveTitle(BOARD_TITLE + ' - ' + baseTitle)

		// Record every value the tab title takes from here on. A `toHaveTitle`
		// assertion CANNOT see the bug this covers: BoardView is reused across
		// board switches, so the incoming board's query starts cold and the title
		// used to fall through to the bare app name for exactly one round trip —
		// gone long before any polling assertion could sample it. Watching the
		// <title> node is the only honest way to assert that a transient is absent.
		// Hash navigation is same-document, so this observer survives the switch.
		await page.evaluate(() => {
			window.__titleLog = []
			const observer = new MutationObserver(() => window.__titleLog.push(document.title))
			observer.observe(document.querySelector('title'), {
				childList: true,
				characterData: true,
				subtree: true,
			})
		})

		// ── Board A straight to board B, with no boards list in between ─────────
		await page.goto(boardUrl(state.boardBId))
		await expect(page.locator('.board-view__title-text')).toHaveText(BOARD_B_TITLE, { timeout: 15_000 })
		await expect(page).toHaveTitle(BOARD_B_TITLE + ' - ' + baseTitle)

		const seen = await page.evaluate(() => window.__titleLog)
		// The switch really did rewrite the title while the observer was attached,
		// so the absence assertion below is looking at a populated log rather than
		// passing because nothing was ever recorded.
		expect(seen).toContain(BOARD_B_TITLE + ' - ' + baseTitle)
		// The point of the test: at no instant in between did the tab fall back to
		// the bare app name, and it never showed board A's name either.
		expect(seen).not.toContain(baseTitle)
		expect(seen.filter((title) => title.startsWith(BOARD_TITLE + ' - '))).toEqual([])
	})

	// The other side of that cache fallback. It exists so an in-app board switch
	// never blinks the bare app name — but a cached row is only a good guess while
	// the board is still LOADING. Once the server answers 403/404 the row is stale
	// by definition, and the tab used to go on flying the board's name over a view
	// saying access was lost. Not a disclosure — the name was already on this
	// user's screen, and the nav still lists the same stale row — but the tab and
	// the view must not contradict each other.
	//
	// The revocation is staged by answering THIS board's GET the way a revoked
	// share does, rather than by wiring up a second user: the fix turns on the
	// answered status alone, and a real two-account share would test Nextcloud's
	// ACL rather than the title. The route is pinned to the board endpoint exactly
	// — `**/api/boards/<id>` does not match `/changes` under it — so nothing else
	// on the page is disturbed.
	test('a board whose access was revoked drops its name from the tab', async ({ page }) => {
		await ncLogin(page)

		// The premise: the boards list is warm and carries this board's title, which
		// is what the tab would otherwise fall back to. Without this step the test
		// would pass on an empty cache for the wrong reason.
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await page.waitForSelector('.board-list-view', { timeout: 15_000 })
		await expect(page.getByText(BOARD_TITLE).first()).toBeVisible({ timeout: 15_000 })
		const baseTitle = (await page.title()).trim()
		expect(baseTitle).not.toContain(BOARD_TITLE)

		await page.route(`**/apps/kanso/api/boards/${state.boardId}`, (route) =>
			route.fulfill({
				status: 403,
				contentType: 'application/json',
				body: JSON.stringify({ error: 'Forbidden' }),
			}))

		// Mark the document, so the assertion below can prove the cache that makes
		// this test non-vacuous is still there. `boardUrl()` differs from the list
		// URL above only in the hash, so this `goto` is a SAME-DOCUMENT navigation
		// and the ['boards'] query the list warmed survives it — the same property
		// the #10445 test relies on to keep a MutationObserver attached across a
		// board switch. If that ever stopped holding, the page would reload with an
		// empty cache, there would be no cached title left to misuse, and this test
		// would go green without exercising the guard at all. So assert it rather
		// than reason about it.
		await page.evaluate(() => { window.__sameDocument = true })

		await page.goto(boardUrl(state.boardId))

		// The view says access was lost…
		await expect(page.getByText('This board no longer exists or you no longer have access.'))
			.toBeVisible({ timeout: 15_000 })
		// …the warm ['boards'] cache really did survive into this view, so the title
		// below is suppressed rather than merely absent…
		expect(await page.evaluate(() => window.__sameDocument)).toBe(true)
		// …and the tab agrees, rather than keeping the name out of the list cache.
		await expect(page).toHaveTitle(baseTitle, { timeout: 15_000 })
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
