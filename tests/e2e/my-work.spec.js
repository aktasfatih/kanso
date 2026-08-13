// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// My Work hub e2e (#3458): the three personal cross-board pages (My tasks,
// Reviews, Inbox) are unified under one nav entry with a tab strip. Switching
// tabs swaps the embedded sub-view; each is shown WITHOUT its own header (the
// hub supplies the single "My Work" title). A board filter is present.

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'

async function ncLogin(page) {
	// Session is preloaded via storageState (see playwright.config.js) for the
	// default admin context — skip the UI login round-trip entirely. Specs that
	// opt out of storageState start with no cookies and fall through to log in.
	if ((await page.context().cookies()).some((c) => c.name === 'nc_username' || c.name === 'nc_session_id')) return
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	if (!(await page.locator('#user').isVisible({ timeout: 3000 }).catch(() => false))) return
	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

test.describe('My Work hub', () => {
	test('one nav entry, three tabs, each swapping the embedded sub-view', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/my-work`)

		const hub = page.locator('.my-work-view')
		await expect(hub).toBeVisible({ timeout: 10_000 })

		// Single hub title; the three tabs live in a tablist.
		await expect(page.getByRole('heading', { name: 'My Work' })).toBeVisible()
		const tabs = page.locator('[role="tablist"] button[role="tab"]')
		await expect(tabs).toHaveCount(3)

		// Default tab = My tasks: the embedded MyCardsView renders WITHOUT its own
		// header (the hub owns the title), proving embedded mode.
		await expect(page.locator('.my-cards-view')).toBeVisible({ timeout: 8_000 })
		await expect(page.locator('.my-cards-view__header')).toHaveCount(0)

		// Switch to Reviews.
		await page.getByRole('tab', { name: 'Reviews' }).click()
		await expect(page.locator('.my-reviews-view')).toBeVisible({ timeout: 8_000 })
		await expect(page.locator('.my-cards-view')).toHaveCount(0)

		// Switch to Inbox.
		await page.getByRole('tab', { name: 'Inbox' }).click()
		await expect(page.locator('.inbox-view')).toBeVisible({ timeout: 8_000 })
		await expect(page.locator('.my-reviews-view')).toHaveCount(0)

		// The board filter control is present.
		await expect(page.locator('#my-work-board-filter')).toBeVisible()
	})

	test('the standalone sub-view routes still render with their own header', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/my-tasks`)
		// Not embedded → its own header is shown (backward compatible).
		await expect(page.locator('.my-cards-view__header')).toBeVisible({ timeout: 10_000 })
	})
})
