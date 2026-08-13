// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

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

	test('left nav shows Boards and three separate My Work items', async ({ page }) => {
		// NcAppNavigation renders as nav with list items
		const nav = page.locator('.app-navigation, [class*="app-navigation"]').first()
		await expect(nav).toBeVisible({ timeout: 10_000 })

		// The personal surfaces are three distinct nav entries (#3610): My Tasks,
		// My Reviews, and Inbox. The unified "My Work" hub entry is retired from
		// the nav (its route still resolves for deep links).
		await expect(page.getByRole('link', { name: 'Boards' })).toBeVisible({ timeout: 8_000 })
		await expect(page.getByRole('link', { name: 'My Tasks' })).toBeVisible({ timeout: 8_000 })
		await expect(page.getByRole('link', { name: 'My Reviews' })).toBeVisible({ timeout: 8_000 })
		await expect(page.getByRole('link', { name: 'Inbox' })).toBeVisible({ timeout: 8_000 })
		await expect(page.getByRole('link', { name: 'My Work', exact: true })).toHaveCount(0)
	})

	test('the three My Work nav items route to their standalone views', async ({ page }) => {
		await page.getByRole('link', { name: 'My Tasks' }).click()
		await expect(page).toHaveURL(/#\/my-tasks/, { timeout: 10_000 })
		await expect(page.locator('.my-cards-view')).toBeVisible({ timeout: 8_000 })

		await page.getByRole('link', { name: 'My Reviews' }).click()
		await expect(page).toHaveURL(/#\/reviews/, { timeout: 10_000 })
		await expect(page.locator('.my-reviews-view')).toBeVisible({ timeout: 8_000 })

		await page.getByRole('link', { name: 'Inbox' }).click()
		await expect(page).toHaveURL(/#\/inbox/, { timeout: 10_000 })
		await expect(page.locator('.inbox-view')).toBeVisible({ timeout: 8_000 })
	})

	test('clicking "Boards" from a personal view navigates back to #/', async ({ page }) => {
		// First navigate away to a personal view.
		await page.getByRole('link', { name: 'My Reviews' }).click()
		await expect(page).toHaveURL(/#\/reviews/, { timeout: 10_000 })

		// Now click Boards
		await page.getByRole('link', { name: 'Boards' }).click()

		// Hash should be back at root
		await expect(page).toHaveURL(/#\/$|#\/$/, { timeout: 10_000 })

		// BoardList view should render (My Boards heading or board grid)
		await expect(page.getByText('My Boards').first()).toBeVisible({ timeout: 10_000 })
	})
})
