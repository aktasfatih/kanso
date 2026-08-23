// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, ncLogin, BASE } from './helpers.js'

const ISSUES_URL = 'https://github.com/aktasfatih/kanso/issues'
const MCP_SETUP_URL = 'https://github.com/aktasfatih/kanso/tree/main/mcp'

async function gotoKanso(page) {
	await page.goto(BASE + '/index.php/apps/kanso')
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

// #3901: an always-visible help affordance in the nav footer, reachable from
// anywhere in the app, exposing two external links (file an issue / set up MCP).
test.describe('Nav-footer help links', () => {
	test.beforeEach(async ({ page }) => {
		await ncLogin(page)
		await gotoKanso(page)
	})

	test('Help menu exposes issue + MCP links pointing at the exact URLs in a new tab', async ({ page }) => {
		const nav = page.locator('.app-navigation, [class*="app-navigation"]').first()
		await expect(nav).toBeVisible({ timeout: 10_000 })

		// The footer Help trigger is present regardless of which board/view is open.
		// The footer now contains both a Settings button and the Help actions menu;
		// target the Help trigger by its aria-label to avoid selecting the Settings gear.
		const helpTrigger = page.locator('.app-nav__footer button[aria-label="Help"]')
		await expect(helpTrigger).toBeVisible({ timeout: 10_000 })
		await helpTrigger.click()

		// NcActionLink renders a real <a>; data-test lands on its <li> wrapper.
		const issue = page.locator('[data-test="menu-file-issue"] a')
		const mcp = page.locator('[data-test="menu-setup-mcp"] a')

		await expect(issue).toHaveAttribute('href', ISSUES_URL, { timeout: 8_000 })
		await expect(issue).toHaveAttribute('target', '_blank')
		await expect(mcp).toHaveAttribute('href', MCP_SETUP_URL, { timeout: 8_000 })
		await expect(mcp).toHaveAttribute('target', '_blank')
	})
})
