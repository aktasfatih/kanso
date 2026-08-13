// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #3768 — My Work surfaces live-update while OPEN. #3766 made My Tasks /
// My Reviews / Inbox fresh on the user's own mutations and on client-side
// navigation; the remaining gap was OTHER users' changes while the user just
// sits on the page (no navigation, no focus change, no reload). The fix is a
// 60s visible-tab refetchInterval on the three cross-board feed queries
// (MY_WORK_POLL_INTERVAL — the interval IS the delta mechanism there, since no
// board delta poll runs on those pages), plus a throttled push/delta-driven
// invalidation fast path when notify_push is available.
//
// These tests drive the exact acceptance scenario with two users: the tester
// parks on a My Work page and never touches it again, while the admin (a
// plain API client — a different "user in another browser") makes a change
// that concerns the tester. The entry must appear on its own:
// near-instantly via push on a push-enabled stack (dev/setup.sh), or within
// the 60s polling interval otherwise (CI sets KANSO_SKIP_NOTIFY_PUSH=1) —
// the 90s expectation budget covers either, and a no-reload marker plus a
// hash check prove no reload/navigation "helped".

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const ADMIN_AUTH = 'Basic ' + Buffer.from('admin:admin').toString('base64')

const TESTER = { user: 'tester', pass: 'kanso-dev-tester!1' }

async function api(method, path, body) {
	const r = await fetch(API + path, {
		method,
		headers: { ...HEADERS, Authorization: ADMIN_AUTH },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`${method} ${path} → ${r.status}: ${await r.text()}`)
	return method === 'DELETE' ? null : r.json()
}

async function ncLogin(page, user, pass) {
	// Session is preloaded via storageState (see playwright.config.js) for the
	// default admin context — skip the UI login round-trip entirely. Specs that
	// opt out of storageState start with no cookies and fall through to log in.
	if ((await page.context().cookies()).some((c) => c.name === 'nc_username' || c.name === 'nc_session_id')) return
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	if (!(await page.locator('#user').isVisible({ timeout: 3000 }).catch(() => false))) return
	await page.fill('#user', user)
	await page.fill('#password', pass)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

test.describe('My Work live updates (#3768)', () => {
	// This spec drives TWO distinct users (tester in the browser, admin via the
	// API) and logs in explicitly, so it must NOT inherit the shared authenticated
	// storageState from the config — otherwise every browser context starts as
	// admin, ncLogin short-circuits, and the tester never actually logs in.
	test.use({ storageState: { cookies: [], origins: [] } })

	const ts = Date.now()
	const REVIEW_TITLE = `Live ReviewReq ${ts}`
	const TASK_TITLE = `Live Assign ${ts}`
	const state = { boardId: 0, reviewCardId: 0, taskCardId: 0 }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: `MyWork Live E2E ${ts}` })
		state.boardId = board.id
		const stack = await api('POST', '/stacks', { boardId: board.id, title: 'To do' })
		state.reviewCardId = (await api('POST', '/cards', { stackId: stack.id, title: REVIEW_TITLE })).id
		state.taskCardId = (await api('POST', '/cards', { stackId: stack.id, title: TASK_TITLE })).id
		// Share with tester (READ|EDIT = 3) so the admin's review request /
		// assignment lands in the tester's cross-board feeds.
		await api('POST', `/boards/${board.id}/acl`, {
			participant: TESTER.user,
			participantType: 'user',
			permission: 3,
		})
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('a review requested by another user appears in the open My Reviews view by itself', async ({ browser }) => {
		// Polling budget (60s interval + slow-CI headroom) on top of the default.
		test.setTimeout(180_000)
		const ctx = await browser.newContext()
		try {
			const page = await ctx.newPage()
			await ncLogin(page, TESTER.user, TESTER.pass)

			// Tester parks on My Reviews — from here on: no navigation, no focus
			// change, no reload. The marker survives only if that holds.
			await page.goto(`${BASE}/index.php/apps/kanso#/reviews`)
			await expect(page.locator('.my-reviews-view')).toBeVisible({ timeout: 15_000 })
			const row = page.locator('.review-row', { hasText: REVIEW_TITLE })
			await expect(row).toBeHidden()
			await page.evaluate(() => { window.__kansoNoReload = true })

			// The admin — another user, no browser — requests a review FROM the tester.
			await api('PUT', `/cards/${state.reviewCardId}/reviews/${TESTER.user}`)

			// Push: near-instant. Poll-only (CI): within the 60s interval.
			await expect(row).toBeVisible({ timeout: 90_000 })
			expect(await page.evaluate(() => window.__kansoNoReload)).toBe(true)
			expect(new URL(page.url()).hash).toBe('#/reviews')
		} finally {
			await ctx.close()
		}
	})

	test('a card assigned by another user appears in the open My Tasks view by itself', async ({ browser }) => {
		test.setTimeout(180_000)
		const ctx = await browser.newContext()
		try {
			const page = await ctx.newPage()
			await ncLogin(page, TESTER.user, TESTER.pass)

			await page.goto(`${BASE}/index.php/apps/kanso#/my-tasks`)
			await expect(page.locator('.my-cards-view')).toBeVisible({ timeout: 15_000 })
			const row = page.locator('.my-cards-view__row', { hasText: TASK_TITLE })
			await expect(row).toBeHidden()
			await page.evaluate(() => { window.__kansoNoReload = true })

			// The admin assigns the tester to the card.
			await api('PUT', `/cards/${state.taskCardId}/assignees/${TESTER.user}`)

			await expect(row).toBeVisible({ timeout: 90_000 })
			expect(await page.evaluate(() => window.__kansoNoReload)).toBe(true)
			expect(new URL(page.url()).hash).toBe('#/my-tasks')
		} finally {
			await ctx.close()
		}
	})
})
