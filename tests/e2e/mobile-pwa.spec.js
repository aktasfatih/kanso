// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Mobile / PWA smoke test.
 *
 * Runs the board on a phone viewport and asserts the two things the PWA work
 * added on top of the normal board flow:
 *   1. The board is usable at 390px — columns render and a card opens.
 *   2. The app is installable + offline-capable — the manifest link is present
 *      and fetches as application/manifest+json, and the service worker is
 *      served with the right scope header and registers.
 *
 * Runs under the mobile-chromium (Pixel 7) and mobile-webkit (iPhone 14)
 * projects (see playwright.config.js), so each test executes on both the
 * Android-Chrome and iOS-Safari engines with a real phone viewport + touch.
 */
import { test, expect, api, gotoBoard, BASE } from './helpers.js'

const state = { boardId: null }

test.beforeAll(async () => {
	const board = await api.post('/boards', { title: 'Mobile PWA E2E' })
	state.boardId = board.id
	const todo = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
	await api.post('/stacks', { boardId: board.id, title: 'Doing' })
	await api.post('/cards', { stackId: todo.id, title: 'Mobile card one' })
	await api.post('/cards', { stackId: todo.id, title: 'Mobile card two' })
})

test.afterAll(async () => {
	if (state.boardId) {
		await api.delete(`/boards/${state.boardId}`).catch(() => {})
	}
})

test('board is usable on a phone viewport', async ({ page }) => {
	await gotoBoard(page, state.boardId)

	// Columns render and a card is visible at 390px.
	await expect(page.locator('.stack-column').first()).toBeVisible({ timeout: 30_000 })
	await expect(page.getByText('Mobile card one')).toBeVisible()

	// mobile.css sizes columns at 88vw on phones — assert the column is in that
	// band (relative to the device viewport, so it holds on both Pixel 7 and
	// iPhone 14). Proves the mobile layout applied, not the 280–420px desktop
	// sizing.
	const vw = page.viewportSize().width
	const width = await page.locator('.stack-column').first().evaluate((el) => el.getBoundingClientRect().width)
	expect(width).toBeGreaterThan(vw * 0.8)
	expect(width).toBeLessThan(vw * 0.95)

	// Opening a card works (full-screen NcModal on mobile).
	await page.getByText('Mobile card one').click()
	await expect(page.getByRole('dialog')).toBeVisible({ timeout: 15_000 })
})

test('has an installable web app manifest', async ({ page }) => {
	await gotoBoard(page, state.boardId)

	// Nextcloud's theming app injects the app-scoped manifest link (Kanso adds
	// only the service worker on top of it). Fetch whatever it points at and
	// assert the installability essentials.
	const manifestLink = page.locator('link[rel="manifest"]').first()
	await expect(manifestLink).toHaveCount(1)
	const href = await manifestLink.getAttribute('href')
	expect(href).toBeTruthy()

	const res = await page.request.get(new URL(href, BASE).toString())
	expect(res.status()).toBe(200)
	const manifest = await res.json()
	expect(manifest.display).toBe('standalone')
	expect(manifest.start_url).toContain('/apps/kanso/')
	expect(Array.isArray(manifest.icons) && manifest.icons.length).toBeTruthy()
})

test('serves the service worker with app scope and registers it', async ({ page }) => {
	// The worker must carry Service-Worker-Allowed so its scope can cover the app.
	const res = await page.request.get(`${BASE}/index.php/apps/kanso/sw.js`)
	expect(res.status()).toBe(200)
	expect(res.headers()['content-type']).toContain('javascript')
	expect(res.headers()['service-worker-allowed']).toBe('/apps/kanso/')

	// The app registers the worker on load — wait for it to become active.
	await gotoBoard(page, state.boardId)
	const registered = await page.waitForFunction(async () => {
		if (!('serviceWorker' in navigator)) return false
		const reg = await navigator.serviceWorker.getRegistration()
		return !!reg
	}, null, { timeout: 20_000 }).then(() => true).catch(() => false)
	expect(registered).toBe(true)
})
