// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'

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

test.describe('Left Navigation App Shell', () => {
	test.beforeEach(async ({ page }) => {
		await ncLogin(page)
		await gotoKanso(page)
	})

	test('left nav shows Boards, My Reviews and Inbox items', async ({ page }) => {
		// NcAppNavigation renders as nav with list items
		const nav = page.locator('.app-navigation, [class*="app-navigation"]').first()
		await expect(nav).toBeVisible({ timeout: 10_000 })

		// Each NcAppNavigationItem renders an <li> or anchor with the item name
		await expect(page.getByRole('link', { name: 'Boards' })).toBeVisible({ timeout: 8_000 })
		await expect(page.getByRole('link', { name: 'My Reviews' })).toBeVisible({ timeout: 8_000 })
		await expect(page.getByRole('link', { name: 'Inbox' })).toBeVisible({ timeout: 8_000 })
	})

	test('clicking "My Reviews" navigates to #/reviews and renders placeholder', async ({ page }) => {
		await page.getByRole('link', { name: 'My Reviews' }).click()

		// Hash should update to #/reviews
		await expect(page).toHaveURL(/#\/reviews/, { timeout: 10_000 })

		// The MyReviewsView NcEmptyContent should be visible
		await expect(page.getByText('My Reviews').first()).toBeVisible({ timeout: 8_000 })
		await expect(page.getByText('Nothing here yet').first()).toBeVisible({ timeout: 8_000 })
	})

	test('clicking "Inbox" navigates to #/inbox and renders placeholder', async ({ page }) => {
		await page.getByRole('link', { name: 'Inbox' }).click()

		// Hash should update to #/inbox
		await expect(page).toHaveURL(/#\/inbox/, { timeout: 10_000 })

		// The InboxView NcEmptyContent should be visible
		await expect(page.getByText('Inbox').first()).toBeVisible({ timeout: 8_000 })
		await expect(page.getByText('Nothing here yet').first()).toBeVisible({ timeout: 8_000 })
	})

	test('clicking "Boards" from Inbox navigates back to #/', async ({ page }) => {
		// First navigate away to inbox
		await page.getByRole('link', { name: 'Inbox' }).click()
		await expect(page).toHaveURL(/#\/inbox/, { timeout: 10_000 })

		// Now click Boards
		await page.getByRole('link', { name: 'Boards' }).click()

		// Hash should be back at root
		await expect(page).toHaveURL(/#\/$|#\/$/, { timeout: 10_000 })

		// BoardList view should render (My Boards heading or board grid)
		await expect(page.getByText('My Boards').first()).toBeVisible({ timeout: 10_000 })
	})
})
