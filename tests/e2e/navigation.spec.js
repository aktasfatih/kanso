// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, ncLogin, BASE } from './helpers.js'

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
		await expect(nav).toBeVisible()

		// The personal surfaces are three distinct nav entries (#3610): My Tasks,
		// My Reviews, and Inbox. The unified "My Work" hub entry is retired from
		// the nav (its route still resolves for deep links).
		await expect(page.getByRole('link', { name: 'Boards' })).toBeVisible()
		await expect(page.getByRole('link', { name: 'My Tasks' })).toBeVisible()
		await expect(page.getByRole('link', { name: 'My Reviews' })).toBeVisible()
		await expect(page.getByRole('link', { name: 'Inbox' })).toBeVisible()
		await expect(page.getByRole('link', { name: 'My Work', exact: true })).toHaveCount(0)
	})

	test('the three My Work nav items route to their standalone views', async ({ page }) => {
		await page.getByRole('link', { name: 'My Tasks' }).click()
		await expect(page).toHaveURL(/#\/my-tasks/, { timeout: 10_000 })
		await expect(page.locator('.my-cards-view')).toBeVisible()

		await page.getByRole('link', { name: 'My Reviews' }).click()
		await expect(page).toHaveURL(/#\/reviews/, { timeout: 10_000 })
		await expect(page.locator('.my-reviews-view')).toBeVisible()

		await page.getByRole('link', { name: 'Inbox' }).click()
		await expect(page).toHaveURL(/#\/inbox/, { timeout: 10_000 })
		await expect(page.locator('.inbox-view')).toBeVisible()
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
		await expect(page.getByText('My Boards').first()).toBeVisible()
	})
})
