// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'

const ISSUES_URL = 'https://github.com/aktasfatih/kanso/issues'
const MCP_SETUP_URL = 'https://github.com/aktasfatih/kanso/tree/main/mcp'

async function ncLogin(page) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})

	const userInput = page.locator('#user')
	const isLoginPage = await userInput.isVisible({ timeout: 3000 }).catch(() => false)
	if (!isLoginPage) return // Already logged in

	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

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
		const helpTrigger = page.locator('.app-nav__footer button').first()
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
