// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Quick-look preview: hover a card (or keyboard-focus it) and press Space for a
// floating, read-only peek. Space again / Escape / click-away dismisses it. The
// Space typing-guard must hold so a space typed in the composer still inserts a
// space (never opens a preview).

import { test, expect, api, ncLogin, BASE, collectConsoleErrors } from './helpers.js'

const DESC = 'Peekaboo description text for the quick look preview.'
const DESC_B = 'Beta body content that only the second card shows.'

// Space is a keypress BoardView can DROP: its handler peeks
// `hoveredCardId ?? focusedCardId` (src/views/BoardView.vue), and with both null
// it returns without doing anything. A dropped keypress is not something an
// auto-retrying assertion can recover from — the `expect(preview).toBeVisible()`
// that follows then waits out its whole budget for a state that will never
// arrive. So every Space here is preceded by a wait on the condition that makes
// it actionable (the focus ring having landed, or the mouse hover having taken
// effect) rather than by a fixed sleep, which only holds while the runner is
// fast enough — see playwright.config.js on the measured 1.8-3x CI degradation.

test.describe('Quick-look preview (Space)', () => {
	const state = { boardId: 0, stackId: 0, card1Id: 0, card2Id: 0, boardUrl: '' }

	/**
	 * Open the board and wait until it is actually keyboard-navigable.
	 *
	 * `waitForSelector('.stack-column')` alone was not enough: ArrowDown with no
	 * `focusedCardId` seeds to the first card of the first NON-EMPTY stack, so a
	 * keypress sent before the card summaries have rendered is swallowed. Waiting
	 * for both seeded tiles makes that precondition explicit.
	 *
	 * `onLoggedIn` runs in the one window that matters for console assertions:
	 * after `ncLogin`, before the board is fetched. `ncLogin` goes via
	 * /index.php/login, which with a live session redirects through the Nextcloud
	 * DASHBOARD, where every other installed app mounts a widget and logs its own
	 * errors — none of which this spec asserts about. Attaching there keeps the
	 * board's own load inside the assertion while leaving the login detour out of
	 * it by construction (see collectConsoleErrors() in helpers.js).
	 *
	 * @param {import('@playwright/test').Page} page the page under test
	 * @param {{onLoggedIn?: (page: import('@playwright/test').Page) => void}} [hooks]
	 */
	async function openBoard(page, { onLoggedIn } = {}) {
		await ncLogin(page)
		onLoggedIn?.(page)
		await page.goto(state.boardUrl)
		await expect(page.locator('.stack-column').first()).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('.card-tile')).toHaveCount(2, { timeout: 30_000 })
	}

	test.beforeAll(async () => {
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === 'Quick Preview Board') {
				await api.delete(`/boards/${b.id}`)
			}
		}

		const board = await api.post('/boards', { title: 'Quick Preview Board' })
		state.boardId = board.id
		const s1 = await api.post('/stacks', { boardId: board.id, title: 'Stack One' })
		state.stackId = s1.id
		const c1 = await api.post('/cards', { stackId: s1.id, title: 'Preview Alpha' })
		const c2 = await api.post('/cards', { stackId: s1.id, title: 'Preview Beta' })
		state.card1Id = c1.id
		state.card2Id = c2.id
		// Give both cards distinct descriptions so the preview body proves WHICH
		// card is being shown as the selection moves.
		await api.patch(`/cards/${c1.id}`, { description: DESC })
		await api.patch(`/cards/${c2.id}`, { description: DESC_B })

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test('hover + Space opens a preview showing title + description; Space closes it', async ({ page }) => {
		await openBoard(page)

		const firstTile = page.locator('.card-tile').first()
		await firstTile.hover()
		// `hover()` only guarantees the mouse was moved onto the tile; what Space
		// needs is BoardView's `hoveredCardId`, set from the tile's mouseenter.
		// The browser's own :hover state landing on that tile is the observable
		// condition the 100ms sleep was standing in for.
		await expect(page.locator('.card-tile:hover')).toHaveCount(1)

		// Space peeks the hovered card.
		await page.keyboard.press('Space')

		const preview = page.locator('.card-preview')
		await expect(preview).toBeVisible()
		await expect(preview.locator('.card-preview__title')).toHaveText('Preview Alpha')
		// Description is lazily fetched via useCard - poll until it renders.
		await expect(preview.locator('.card-preview__desc-rendered')).toContainText(DESC, { timeout: 5000 })

		// Space again closes it.
		await page.keyboard.press('Space')
		await expect(preview).not.toBeVisible()

		// The board must not have scrolled from the Space presses (preventDefault).
		const scrolled = await page.evaluate(() => window.scrollY)
		expect(scrolled).toBe(0)
	})

	test('keyboard-focused card + Space opens the preview; Escape closes it', async ({ page }) => {
		await openBoard(page)

		// Seed keyboard focus to the first card (no mouse hover).
		await page.keyboard.press('ArrowDown')
		const firstTile = page.locator('.card-tile').first()
		await expect(firstTile).toBeFocused()

		await page.keyboard.press('Space')
		const preview = page.locator('.card-preview')
		await expect(preview).toBeVisible()
		await expect(preview.locator('.card-preview__title')).toHaveText('Preview Alpha')

		// Escape closes it.
		await page.keyboard.press('Escape')
		await expect(preview).not.toBeVisible()
	})

	test('Enter from an open preview opens the full card modal', async ({ page }) => {
		await openBoard(page)

		await page.keyboard.press('ArrowDown')
		// Guard the Space: without the focus ring having landed it is dropped.
		await expect(page.locator('.card-tile').first()).toBeFocused()
		await page.keyboard.press('Space')
		await expect(page.locator('.card-preview')).toBeVisible()

		await page.keyboard.press('Enter')
		// Wait for the route to actually change (auto-waiting; replaces a fixed sleep).
		await page.waitForURL(/\/card\//, { timeout: 5_000 })
		// Preview is dismissed when the modal opens.
		await expect(page.locator('.card-preview')).not.toBeVisible()
	})

	test('click-away on the backdrop dismisses the preview', async ({ page }) => {
		await openBoard(page)

		await page.keyboard.press('ArrowDown')
		// Same dropped-Space hole as the test above.
		await expect(page.locator('.card-tile').first()).toBeFocused()
		await page.keyboard.press('Space')
		const preview = page.locator('.card-preview')
		await expect(preview).toBeVisible()

		// Click empty board whitespace (viewport coords) - covered only by the
		// transparent full-screen backdrop, clear of the floating panel (anchored
		// near the top-left card) and the fixed Nextcloud header at the very top.
		await page.mouse.click(900, 500)
		await expect(preview).not.toBeVisible()
		// Click-away must not have opened the card modal.
		expect(page.url()).not.toContain('/card/')
	})

	test('open preview follows keyboard selection and re-anchors to the new tile (#3908)', async ({ page }) => {
		// Collected from inside openBoard(), after the login and before the board
		// loads. The old collector was attached before ncLogin and filtered nothing
		// at all, so it asserted on every console error of every page the test
		// touched — including the post-login dashboard, where CI produced "could not
		// load recommendation preview Event" + a 404 from /apps/recommendations/js/.
		// collectConsoleErrors() keys on the message's source bundle instead of on an
		// allowlist of message strings, so an unrelated app rewording its own logging
		// cannot rot this — while Kanso's own errors still fail the assertion.
		let errors = []
		await openBoard(page, { onLoggedIn: (p) => { errors = collectConsoleErrors(p) } })

		// Focus the first card (Alpha) and Space to open the preview on it.
		await page.keyboard.press('ArrowDown')
		const alphaTile = page.locator(`[data-card-id="${state.card1Id}"]`)
		const betaTile = page.locator(`[data-card-id="${state.card2Id}"]`)
		await expect(alphaTile).toBeFocused()

		await page.keyboard.press('Space')
		const preview = page.locator('.card-preview')
		await expect(preview).toBeVisible()
		await expect(preview.locator('.card-preview__title')).toHaveText('Preview Alpha')
		await expect(preview.locator('.card-preview__desc-rendered')).toContainText(DESC, { timeout: 5000 })

		// Record where the panel is anchored while showing Alpha.
		const alphaLeft = await preview.evaluate((el) => el.getBoundingClientRect().left)
		const alphaPanelTop = await preview.evaluate((el) => el.getBoundingClientRect().top)

		// Move the keyboard selection down: the OPEN preview must switch to Beta.
		await page.keyboard.press('ArrowDown')
		await expect(betaTile).toBeFocused()
		await expect(preview.locator('.card-preview__title')).toHaveText('Preview Beta')
		// No stale content: it must now show Beta's body, not Alpha's.
		await expect(preview.locator('.card-preview__desc-rendered')).toContainText(DESC_B, { timeout: 5000 })

		// The panel re-anchored to Beta's tile: it moved DOWN from where it sat for
		// Alpha (Beta is below Alpha in the same column) and now tracks Beta's top.
		const betaBox = await betaTile.boundingBox()
		const betaPanelTop = await preview.evaluate((el) => el.getBoundingClientRect().top)
		expect(betaPanelTop).toBeGreaterThan(alphaPanelTop + 10)
		expect(Math.abs(betaPanelTop - betaBox.y)).toBeLessThan(80)

		// Move back up: preview follows to Alpha again and re-anchors.
		await page.keyboard.press('ArrowUp')
		await expect(alphaTile).toBeFocused()
		await expect(preview.locator('.card-preview__title')).toHaveText('Preview Alpha')
		const alphaLeft2 = await preview.evaluate((el) => el.getBoundingClientRect().left)
		const alphaPanelTop2 = await preview.evaluate((el) => el.getBoundingClientRect().top)
		// Same column → same horizontal anchor, and the panel returned up to Alpha.
		expect(Math.abs(alphaLeft2 - alphaLeft)).toBeLessThan(2)
		expect(Math.abs(alphaPanelTop2 - alphaPanelTop)).toBeLessThan(10)

		// Escape still dismisses.
		await page.keyboard.press('Escape')
		await expect(preview).not.toBeVisible()

		expect(errors, `console errors: ${errors.join('\n')}`).toEqual([])
	})

	test('typing space in the composer inserts a space (guard holds, no preview)', async ({ page }) => {
		await openBoard(page)

		const s1 = page.locator('.stack-column').nth(0)
		const composer = s1.locator('.card-composer__input')
		await composer.click()
		await expect(composer).toBeFocused()

		// A title with an embedded space must insert the space, not open a preview.
		await page.keyboard.type('hello world')
		await expect(composer).toHaveValue('hello world')
		await expect(page.locator('.card-preview')).not.toBeVisible()
	})
})
