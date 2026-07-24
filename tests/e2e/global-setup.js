// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Global warmup: hit the app once (authenticated) before any spec runs so the
 * very first spec (alphabetically `checklist`) doesn't race PHP/APCu/route-cache
 * cold-start. A cold first request could otherwise 500/hang and flake whichever
 * spec runs first. Best-effort — never fails the run.
 */
import { chromium, request } from '@playwright/test'

const BASE = 'http://localhost:8891'
const AUTH = 'Basic ' + Buffer.from('admin:admin').toString('base64')

export default async function globalSetup() {
	// Warm the API (boots the app container's PHP + route cache).
	try {
		const ctx = await request.newContext()
		for (let i = 0; i < 3; i++) {
			await ctx.get(`${BASE}/index.php/apps/kanso/api/boards`, {
				headers: { 'OCS-APIREQUEST': 'true', Authorization: AUTH },
			}).catch(() => {})
		}
		await ctx.dispose()
	} catch { /* best-effort */ }

	// Warm the frontend bundle + a logged-in session render.
	try {
		const browser = await chromium.launch()
		const page = await browser.newPage()
		await page.goto(`${BASE}/index.php/login`, { timeout: 30_000 }).catch(() => {})
		if (await page.locator('#user').isVisible({ timeout: 3000 }).catch(() => false)) {
			await page.fill('#user', 'admin')
			await page.fill('#password', 'admin')
			await page.click('button[type=submit]')
			await page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30_000 }).catch(() => {})
		}
		await page.goto(`${BASE}/index.php/apps/kanso`, { timeout: 30_000 }).catch(() => {})
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await browser.close()
	} catch { /* best-effort */ }
}
