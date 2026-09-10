// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// Every wait in this file used to be a fixed `waitForTimeout`, and that is a
// fragile way to drive a keyboard: BoardView's window keydown handler DROPS a
// keypress it cannot act on, and a dropped keypress is not something an
// auto-retrying assertion can recover from. It bails when
// `route.name === 'card-modal'` (src/views/BoardView.vue), and ArrowDown with no
// `focusedCardId` seeds to the first card of the first NON-EMPTY stack — so a
// key sent before the modal route has actually unwound, or before the card
// summaries have arrived, is simply swallowed. The assertion that follows then
// starts from a state that will never arrive and burns its whole budget.
//
// So each sleep is replaced by a wait on the condition it stood in for: the
// tiles being present, the route having changed, the modal being gone, or the
// focus ring having landed.

// Focus lands on the nextTick+rAF after the keypress — sub-frame work locally,
// but a saturated CI runner (measured 1.8-3x degradation, see
// playwright.config.js) can starve that rAF for far longer than the 3s these
// assertions used to allow. 15s is ~100x the healthy cost and still fails fast
// when a keypress was genuinely dropped rather than merely delayed.
const FOCUS_TIMEOUT = 15_000

/** The board-scoped card modal route, e.g. `#/board/12/card/34`. */
const CARD_ROUTE = /#\/board\/\d+\/card\/\d+/

test.describe('Keyboard navigation', () => {
	const state = {
		boardId: 0,
		stackS1Id: 0,
		stackS2Id: 0,
		card1Id: 0,
		card2Id: 0,
		card3Id: 0,
		card4Id: 0,
		boardUrl: '',
	}

	/**
	 * Open the board and wait until it is actually keyboard-navigable.
	 *
	 * `waitForSelector('.stack-column')` was not enough: the columns render from
	 * the board read while the card summaries are still in flight, and on that
	 * board `nonEmptyStacks` is empty, so the seeding ArrowDown/j returns without
	 * doing anything. Waiting for all four seeded tiles makes the precondition
	 * explicit instead of hoping a 200ms sleep covered the fetch.
	 */
	async function openBoard(page) {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await expect(page.locator('.stack-column')).toHaveCount(2, { timeout: 30_000 })
		await expect(page.locator('.card-tile')).toHaveCount(4, { timeout: 30_000 })
	}

	/** Wait until the card modal route is fully unwound — route AND DOM. */
	async function waitForModalClosed(page) {
		await page.waitForURL((url) => !url.hash.includes('/card/'), { timeout: FOCUS_TIMEOUT })
		await expect(page.locator('.card-modal')).toHaveCount(0, { timeout: FOCUS_TIMEOUT })
	}

	test.beforeAll(async () => {
		// Clean up any previous run
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === 'Keyboard Test Board') {
				await api.delete(`/boards/${b.id}`)
			}
		}

		// Create board with 2 stacks × 2 cards each
		const board = await api.post('/boards', { title: 'Keyboard Test Board' })
		state.boardId = board.id

		const s1 = await api.post('/stacks', { boardId: board.id, title: 'Stack One' })
		const s2 = await api.post('/stacks', { boardId: board.id, title: 'Stack Two' })
		state.stackS1Id = s1.id
		state.stackS2Id = s2.id

		const c1 = await api.post('/cards', { stackId: s1.id, title: 'Card Alpha' })
		const c2 = await api.post('/cards', { stackId: s1.id, title: 'Card Beta' })
		const c3 = await api.post('/cards', { stackId: s2.id, title: 'Card Gamma' })
		const c4 = await api.post('/cards', { stackId: s2.id, title: 'Card Delta' })
		state.card1Id = c1.id
		state.card2Id = c2.id
		state.card3Id = c3.id
		state.card4Id = c4.id

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test('ArrowDown seeds to first card, navigates down and right', async ({ page }) => {
		await openBoard(page)

		// ArrowDown with no focus should seed to first card of first stack
		await page.keyboard.press('ArrowDown')

		// First card in S1 should be focused. No sleep first: toBeFocused already
		// polls, and openBoard has established the precondition the sleep guessed at.
		const s1 = page.locator('.stack-column').nth(0)
		const firstCard = s1.locator('.card-tile').first()
		await expect(firstCard).toBeFocused({ timeout: FOCUS_TIMEOUT })

		// ArrowDown → second card in S1
		await page.keyboard.press('ArrowDown')
		const secondCard = s1.locator('.card-tile').nth(1)
		await expect(secondCard).toBeFocused({ timeout: FOCUS_TIMEOUT })

		// ArrowDown at bottom clamps - still second card
		await page.keyboard.press('ArrowDown')
		await expect(secondCard).toBeFocused({ timeout: FOCUS_TIMEOUT })

		// ArrowRight → move to S2, card index clamped to 1
		await page.keyboard.press('ArrowRight')
		const s2 = page.locator('.stack-column').nth(1)
		const s2SecondCard = s2.locator('.card-tile').nth(1)
		await expect(s2SecondCard).toBeFocused({ timeout: FOCUS_TIMEOUT })

		// ArrowUp → first card of S2
		await page.keyboard.press('ArrowUp')
		const s2FirstCard = s2.locator('.card-tile').first()
		await expect(s2FirstCard).toBeFocused({ timeout: FOCUS_TIMEOUT })
	})

	test("'e' opens card modal for the focused card (URL check), Esc closes", async ({ page }) => {
		await openBoard(page)

		const s1 = page.locator('.stack-column').nth(0)
		const s2 = page.locator('.stack-column').nth(1)

		// Seed focus, then navigate right to S2 and down to its second card. Each
		// step waits for the focus ring it should have moved rather than sleeping:
		// asserting the intermediate state is also what makes the final 'e' target
		// unambiguous, since a swallowed keypress would leave focus behind.
		await page.keyboard.press('ArrowDown')
		await expect(s1.locator('.card-tile').first()).toBeFocused({ timeout: FOCUS_TIMEOUT })

		await page.keyboard.press('ArrowRight')
		await expect(s2.locator('.card-tile').first()).toBeFocused({ timeout: FOCUS_TIMEOUT })

		await page.keyboard.press('ArrowDown')
		await expect(s2.locator('.card-tile').nth(1)).toBeFocused({ timeout: FOCUS_TIMEOUT })

		// 'e' should open the card modal - URL should include /card/<id>.
		// `page.url()` is a single-shot read with no retry of its own, so the route
		// push is waited for explicitly; a fixed sleep here read the pre-push URL
		// on a loaded runner and failed outright rather than flakily.
		await page.keyboard.press('e')
		await page.waitForURL(CARD_ROUTE, { timeout: FOCUS_TIMEOUT })

		const url = page.url()
		expect(url).toContain('/card/')

		// Esc closes the modal (NcModal native close)
		await page.keyboard.press('Escape')
		await waitForModalClosed(page)

		// URL should no longer have /card/
		const urlAfter = page.url()
		expect(urlAfter).not.toContain('/card/')
	})

	test("'d' toggles done styling on the focused tile", async ({ page }) => {
		await openBoard(page)

		// Seed focus to first card S1
		await page.keyboard.press('ArrowDown')

		const s1 = page.locator('.stack-column').nth(0)
		const firstTile = s1.locator('.card-tile').first()
		await expect(firstTile).toBeFocused({ timeout: FOCUS_TIMEOUT })

		// Toggle done
		await page.keyboard.press('d')

		// Wait for done styling to appear (poll - the update is a server round-trip)
		await expect(firstTile).toHaveClass(/card-tile--done/, { timeout: 30_000 })

		// Toggle done back
		await page.keyboard.press('d')
		await expect(firstTile).not.toHaveClass(/card-tile--done/, { timeout: 30_000 })
	})

	test("'n' focuses the composer of the focused card's stack", async ({ page }) => {
		await openBoard(page)

		const s1 = page.locator('.stack-column').nth(0)

		// Seed focus to S1 — asserted, so 'n' below is known to be aimed at S1
		// rather than landing there by the no-focus fallback.
		await page.keyboard.press('ArrowDown')
		await expect(s1.locator('.card-tile').first()).toBeFocused({ timeout: FOCUS_TIMEOUT })

		// 'n' should focus the composer input of S1
		await page.keyboard.press('n')

		const composer = s1.locator('.card-composer__input')
		await expect(composer).toBeFocused({ timeout: FOCUS_TIMEOUT })

		// IMPORTANT: typing in the composer should NOT trigger shortcuts
		// Type a title containing 'n', 'e', 'd'
		await page.keyboard.type('ned')

		// Composer value should be 'ned', no modal opened, no done toggled.
		// toHaveValue polls, so it also establishes that all three keys landed —
		// which is what makes the single-shot url read below sound: a shortcut
		// would have pushed the route synchronously with its own keydown.
		await expect(composer).toHaveValue('ned')
		// Card modal should not be open
		const url = page.url()
		expect(url).not.toContain('/card/')
	})

	test("'?' opens the shortcuts overlay and Esc closes it", async ({ page }) => {
		await openBoard(page)

		// '?' should open the shortcuts overlay
		await page.keyboard.press('?')

		// NcModal should be visible - look for the modal with keyboard shortcuts heading
		const modal = page.locator('.modal-container, [role="dialog"]').filter({ hasText: 'Keyboard shortcuts' })
		await expect(modal).toBeVisible({ timeout: FOCUS_TIMEOUT })

		// Esc should close it (NcModal native close)
		await page.keyboard.press('Escape')
		await expect(modal).not.toBeVisible({ timeout: FOCUS_TIMEOUT })
	})

	test('hjkl alias the arrow-key navigation (j/k cards, l/h stacks)', async ({ page }) => {
		await openBoard(page)

		// 'j' with no focus should seed to first card of first stack (like ArrowDown)
		await page.keyboard.press('j')
		const s1 = page.locator('.stack-column').nth(0)
		const firstCard = s1.locator('.card-tile').first()
		await expect(firstCard).toBeFocused({ timeout: FOCUS_TIMEOUT })

		// 'j' → second card in S1 (ArrowDown)
		await page.keyboard.press('j')
		const secondCard = s1.locator('.card-tile').nth(1)
		await expect(secondCard).toBeFocused({ timeout: FOCUS_TIMEOUT })

		// 'k' → back to first card in S1 (ArrowUp)
		await page.keyboard.press('k')
		await expect(firstCard).toBeFocused({ timeout: FOCUS_TIMEOUT })

		// 'l' → move to S2 (ArrowRight)
		await page.keyboard.press('l')
		const s2 = page.locator('.stack-column').nth(1)
		const s2FirstCard = s2.locator('.card-tile').first()
		await expect(s2FirstCard).toBeFocused({ timeout: FOCUS_TIMEOUT })

		// 'h' → back to S1 (ArrowLeft)
		await page.keyboard.press('h')
		await expect(firstCard).toBeFocused({ timeout: FOCUS_TIMEOUT })
	})

	test('typing h/j/k/l in the composer inserts the letters (guard holds)', async ({ page }) => {
		await openBoard(page)

		const s1 = page.locator('.stack-column').nth(0)

		// Seed focus to S1, then open its composer with 'n'
		await page.keyboard.press('j')
		await expect(s1.locator('.card-tile').first()).toBeFocused({ timeout: FOCUS_TIMEOUT })

		await page.keyboard.press('n')
		const composer = s1.locator('.card-composer__input')
		await expect(composer).toBeFocused({ timeout: FOCUS_TIMEOUT })

		// Typing the vim nav letters must insert them, not navigate
		await page.keyboard.type('hjkl')
		await expect(composer).toHaveValue('hjkl')
	})

	test('mouse click on tile keeps focusedCardId in sync (tile click syncs keyboard state)', async ({ page }) => {
		await openBoard(page)

		// Click on the second card in S2 to open the modal
		const s2 = page.locator('.stack-column').nth(1)
		const s2SecondCard = s2.locator('.card-tile').nth(1)
		await s2SecondCard.click()

		// The click opens the modal through a route push. Wait for the route: the
		// url read below cannot retry, so a fixed sleep that came up short here
		// failed the test outright.
		await page.waitForURL(CARD_ROUTE, { timeout: FOCUS_TIMEOUT })

		// Modal should be open
		const url = page.url()
		expect(url).toContain('/card/')

		// Close modal with Esc.
		await page.keyboard.press('Escape')
		// THIS is what the old 300ms sleep stood in for, and why this test failed
		// three attempts in a row on a saturated runner: BoardView's keydown
		// handler returns immediately for as long as `route.name === 'card-modal'`,
		// so the ArrowUp below is SWALLOWED if it arrives before the route has
		// unwound — and no assertion budget can recover a keypress that was never
		// handled. Wait for the route and the modal DOM to be gone instead.
		await waitForModalClosed(page)

		// After close, ArrowUp from the second card position should go to first card of S2
		await page.keyboard.press('ArrowUp')
		const s2FirstCard = s2.locator('.card-tile').first()
		await expect(s2FirstCard).toBeFocused({ timeout: FOCUS_TIMEOUT })
	})
})
