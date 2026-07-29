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

	test('left nav shows Boards and the unified My Work items', async ({ page }) => {
		// NcAppNavigation renders as nav with list items
		const nav = page.locator('.app-navigation, [class*="app-navigation"]').first()
		await expect(nav).toBeVisible({ timeout: 10_000 })

		// The personal surfaces (My tasks / My Reviews / Inbox) are unified into
		// one "My Work" entry (#3458); the standalone links no longer exist.
		await expect(page.getByRole('link', { name: 'Boards' })).toBeVisible({ timeout: 8_000 })
		await expect(page.getByRole('link', { name: 'My Work' })).toBeVisible({ timeout: 8_000 })
		await expect(page.getByRole('link', { name: 'My Reviews' })).toHaveCount(0)
		await expect(page.getByRole('link', { name: 'Inbox' })).toHaveCount(0)
	})

	test('clicking "My Work" opens the hub, whose tabs reach Reviews and Inbox', async ({ page }) => {
		await page.getByRole('link', { name: 'My Work' }).click()

		await expect(page).toHaveURL(/#\/my-work/, { timeout: 10_000 })
		await expect(page.getByRole('heading', { name: 'My Work' })).toBeVisible({ timeout: 8_000 })

		// Reviews tab → the embedded My Reviews view.
		await page.getByRole('tab', { name: 'Reviews' }).click()
		await expect(page.locator('.my-reviews-view')).toBeVisible({ timeout: 8_000 })

		// Inbox tab → the embedded Inbox view.
		await page.getByRole('tab', { name: 'Inbox' }).click()
		await expect(page.locator('.inbox-view')).toBeVisible({ timeout: 8_000 })
	})

	test('clicking "Boards" from the hub navigates back to #/', async ({ page }) => {
		// First navigate away to the My Work hub
		await page.getByRole('link', { name: 'My Work' }).click()
		await expect(page).toHaveURL(/#\/my-work/, { timeout: 10_000 })

		// Now click Boards
		await page.getByRole('link', { name: 'Boards' }).click()

		// Hash should be back at root
		await expect(page).toHaveURL(/#\/$|#\/$/, { timeout: 10_000 })

		// BoardList view should render (My Boards heading or board grid)
		await expect(page.getByText('My Boards').first()).toBeVisible({ timeout: 10_000 })
	})
})
