// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, ncLogin, BASE } from './helpers.js'

/** Two DOM rects overlap iff they intersect on both axes. */
function rectsOverlap(a, b) {
	return a.x < b.x + b.width
		&& b.x < a.x + a.width
		&& a.y < b.y + b.height
		&& b.y < a.y + a.height
}

/**
 * Assert the page <h1> clears the NC nav toggle in the current nav state:
 *  - no bounding-box overlap, and
 *  - the title starts to the right of the toggle (the reserved space cleared it), and
 *  - the title is not pushed absurdly far past the toggle (no ugly gap).
 */
async function expectTitleClearsToggle(page, title, toggle, label) {
	const titleBox = await title.boundingBox()
	const toggleBox = await toggle.boundingBox()
	expect(titleBox, `${label}: title box`).not.toBeNull()
	expect(toggleBox, `${label}: toggle box`).not.toBeNull()

	expect(rectsOverlap(titleBox, toggleBox), `${label}: title/toggle overlap`).toBe(false)
	expect(titleBox.x, `${label}: title left vs toggle right`)
		.toBeGreaterThanOrEqual(toggleBox.x + toggleBox.width)
	// Guard against a runaway inset: the title should sit just right of the
	// toggle, within ~40px of its right edge, not floated far into the page.
	expect(titleBox.x - (toggleBox.x + toggleBox.width), `${label}: gap after toggle`)
		.toBeLessThanOrEqual(40)
}

test.describe('NC nav-toggle vs page title (#3651)', () => {
	// Nextcloud renders the app-navigation toggle (.app-navigation-toggle) pinned
	// to the leading edge of the content area in BOTH nav states (open and
	// collapsed) on NC34. Our shared page-header treatment reserves left space
	// unconditionally so the toggle never lands on top of the page <h1> in either
	// state. "My tasks" (MyCardsView) always renders its <h1>, so we use it.
	test('nav toggle does not overlap the My tasks title (open AND collapsed)', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/my-tasks`)

		const title = page.locator('.my-cards-view__title')
		await expect(title).toBeVisible({ timeout: 15_000 })

		const nav = page.locator('.app-navigation')
		const toggle = page.locator('.app-navigation-toggle')
		await expect(toggle).toBeVisible({ timeout: 10_000 })

		// Ensure the nav is OPEN first (the common case the user reported).
		if (await nav.evaluate((el) => el.classList.contains('app-navigation--closed'))) {
			await toggle.click()
			await expect(nav).not.toHaveClass(/app-navigation--closed/, { timeout: 5000 })
		}
		await page.waitForTimeout(400)
		await expectTitleClearsToggle(page, title, toggle, 'nav open')

		// Now COLLAPSE the nav and re-check.
		await toggle.click()
		await expect(nav).toHaveClass(/app-navigation--closed/, { timeout: 5000 })
		await page.waitForTimeout(400)
		await expectTitleClearsToggle(page, title, toggle, 'nav collapsed')
	})
})
