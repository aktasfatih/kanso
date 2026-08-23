// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #3413 — Empty states and first-run onboarding.
// A fresh user (no boards) sees the enriched empty state: a primary
// "Create your first board" CTA plus a "Start with a template" action that
// seeds a To do / Doing / Done starter board with a few sample cards. Opening a
// board surfaces a one-time "press ? for shortcuts" hint whose dismissal is
// persisted PER USER via the shared `dismissed_hints` settings key, so it stays
// hidden after a reload.

import { test, expect, ncLogin, adminAuth } from './helpers.js'

const BASE = 'http://localhost:8891'

// A hermetic, throwaway user so the board list is genuinely empty. The name is
// made per-worker-unique in beforeAll (worker index) so parallel workers never
// provision/delete the same account.
let UID = ''
const PASS = 'onboard-pass-123'

const OCS = BASE + '/ocs/v2.php/cloud'
const OCS_HEADERS = { 'OCS-APIREQUEST': 'true', Accept: 'application/json' }

async function provisionUser(uid, password) {
	await fetch(`${OCS}/users/${uid}`, { method: 'DELETE', headers: { ...OCS_HEADERS, Authorization: adminAuth } }).catch(() => {})
	const body = new URLSearchParams({ userid: uid, password })
	const r = await fetch(`${OCS}/users`, {
		method: 'POST',
		headers: { ...OCS_HEADERS, Authorization: adminAuth, 'Content-Type': 'application/x-www-form-urlencoded' },
		body,
	})
	if (!r.ok) throw new Error(`provision ${uid} → ${r.status}: ${await r.text()}`)
}

async function deleteUser(uid) {
	await fetch(`${OCS}/users/${uid}`, { method: 'DELETE', headers: { ...OCS_HEADERS, Authorization: adminAuth } }).catch(() => {})
}

test.describe('First-run onboarding (#3413)', () => {
	// Logs in as a freshly provisioned (non-admin) user on the default page to
	// exercise the first-run flow, so it must NOT inherit the shared authenticated
	// storageState — otherwise the page starts as admin and loginAs no-ops.
	test.use({ storageState: { cookies: [], origins: [] } })

	test.beforeAll(async ({}, workerInfo) => {
		UID = `kanso-onboard-${Math.floor(Date.now() / 1000)}-w${workerInfo.workerIndex}`
		await provisionUser(UID, PASS)
	})

	test.afterAll(async () => {
		await deleteUser(UID)
	})

	test('empty state seeds a starter board and the shortcut hint stays dismissed', async ({ page }) => {
		await ncLogin(page, { user: UID, pass: PASS })
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await page.waitForSelector('.board-list-view', { timeout: 15_000 })

		// Empty state: both onboarding actions are present.
		const createCta = page.locator('[data-test="empty-create-board"]')
		const templateCta = page.locator('[data-test="empty-start-template"]')
		await expect(createCta).toBeVisible({ timeout: 10_000 })
		await expect(templateCta).toBeVisible()

		// "Start with a template" seeds the starter board and navigates to it.
		await templateCta.click()
		await page.waitForSelector('.board-view__stacks-wrap', { timeout: 20_000 })

		// The three classic stacks are present.
		for (const col of ['To do', 'Doing', 'Done']) {
			await expect(page.locator('.stack-column', { hasText: col }).first())
				.toBeVisible({ timeout: 10_000 })
		}

		// A couple of the sample cards seeded across the stacks.
		await expect(page.getByText('👋 Welcome to Kanso!').first()).toBeVisible({ timeout: 10_000 })
		await expect(page.getByText('Delete these sample cards whenever you like').first()).toBeVisible()

		// The one-time shortcut discoverability hint appears on the board.
		const hint = page.locator('[data-test="shortcuts-hint"]')
		await expect(hint).toBeVisible({ timeout: 10_000 })

		// Dismiss it — it hides, and the dismissal is persisted server-side. Wait
		// for the settings PUT so the assertion below doesn't race the write.
		const putDone = page.waitForResponse(
			(r) => r.url().includes('/apps/kanso/api/settings') && r.request().method() === 'PUT' && r.ok(),
			{ timeout: 10_000 },
		)
		await page.locator('[data-test="shortcuts-hint-dismiss"]').click()
		await putDone
		await expect(hint).toHaveCount(0)

		// The settings API reflects the persisted dismissal for this user.
		const settings = await page.evaluate(async (base) => {
			const r = await fetch(base + '/index.php/apps/kanso/api/settings', {
				headers: { 'OCS-APIREQUEST': 'true' },
			})
			return r.json()
		}, BASE)
		expect(Array.isArray(settings.dismissedHints)).toBe(true)
		expect(settings.dismissedHints).toContain('shortcuts-discoverability')

		// Reload the board: the hint must STAY hidden (persisted via settings).
		await page.reload()
		await page.waitForSelector('.board-view__stacks-wrap', { timeout: 20_000 })
		await expect(page.locator('[data-test="shortcuts-hint"]')).toHaveCount(0)
	})
})
