// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'

async function ncLogin(page) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	if (!(await page.locator('#user').isVisible({ timeout: 3000 }).catch(() => false))) return
	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

/** Two DOM rects overlap iff they intersect on both axes. */
function rectsOverlap(a, b) {
	return a.x < b.x + b.width
		&& b.x < a.x + a.width
		&& a.y < b.y + b.height
		&& b.y < a.y + a.height
}

test.describe('NC nav-toggle vs page title (#3651)', () => {
	// When Nextcloud's app navigation is collapsed it keeps the toggle button
	// (.app-navigation-toggle) pinned to the top-left of the content area. Our
	// shared page-header treatment reserves left space in that state so the
	// toggle never lands on top of the page <h1>.
	test('collapsed nav toggle does not overlap the My tasks title', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/my-tasks`)

		const title = page.locator('.my-cards-view__title')
		await expect(title).toBeVisible({ timeout: 15_000 })

		const nav = page.locator('.app-navigation')
		const toggle = page.locator('.app-navigation-toggle')
		await expect(toggle).toBeVisible({ timeout: 10_000 })

		// Collapse the navigation if it is currently open.
		if (!(await nav.evaluate((el) => el.classList.contains('app-navigation--closed')))) {
			await toggle.click()
			await expect(nav).toHaveClass(/app-navigation--closed/, { timeout: 5000 })
		}

		// Let the collapse transition settle so the measured boxes are final.
		await page.waitForTimeout(400)

		const titleBox = await title.boundingBox()
		const toggleBox = await toggle.boundingBox()
		expect(titleBox).not.toBeNull()
		expect(toggleBox).not.toBeNull()

		// The core acceptance check: the toggle and the title must not overlap
		// while the nav is collapsed, and the title must start to the right of
		// the toggle (i.e. the reserved space actually cleared it).
		expect(rectsOverlap(titleBox, toggleBox)).toBe(false)
		expect(titleBox.x).toBeGreaterThanOrEqual(toggleBox.x + toggleBox.width)
	})
})
