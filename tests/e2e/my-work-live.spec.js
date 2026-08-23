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

import { test, expect, api, BASE } from './helpers.js'

// Local ncLogin takes POSITIONAL (page, user, pass) and always drives the form
// for an explicit non-admin identity — kept as-is (the shared ncLogin uses an
// object arg and short-circuits on an existing session), per the migration
// contract (rule 6/8). This spec opts out of the shared admin storageState.
async function ncLogin(page, user, pass) {
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

	test.beforeAll(async ({ peer }) => {
		const board = await api.post('/boards', { title: `MyWork Live E2E ${ts}` })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		state.reviewCardId = (await api.post('/cards', { stackId: stack.id, title: REVIEW_TITLE })).id
		state.taskCardId = (await api.post('/cards', { stackId: stack.id, title: TASK_TITLE })).id
		// Share with the peer (READ|EDIT = 3) so the current user's review request /
		// assignment lands in the peer's cross-board feeds.
		await api.post(`/boards/${board.id}/acl`, {
			participant: peer.user,
			participantType: 'user',
			permission: 3,
		})
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('a review requested by another user appears in the open My Reviews view by itself', async ({ browser, peer }) => {
		// Polling budget (60s interval + slow-CI headroom) on top of the default.
		test.setTimeout(180_000)
		const ctx = await browser.newContext()
		try {
			const page = await ctx.newPage()
			await ncLogin(page, peer.user, peer.pass)

			// Tester parks on My Reviews — from here on: no navigation, no focus
			// change, no reload. The marker survives only if that holds.
			await page.goto(`${BASE}/index.php/apps/kanso#/reviews`)
			await expect(page.locator('.my-reviews-view')).toBeVisible({ timeout: 15_000 })
			const row = page.locator('.review-row', { hasText: REVIEW_TITLE })
			await expect(row).toBeHidden()
			await page.evaluate(() => { window.__kansoNoReload = true })

			// The current user — another user, no browser — requests a review FROM the peer.
			await api.put(`/cards/${state.reviewCardId}/reviews/${peer.user}`)

			// Push: near-instant. Poll-only (CI): within the 60s interval.
			await expect(row).toBeVisible({ timeout: 90_000 })
			expect(await page.evaluate(() => window.__kansoNoReload)).toBe(true)
			expect(new URL(page.url()).hash).toBe('#/reviews')
		} finally {
			await ctx.close()
		}
	})

	test('a card assigned by another user appears in the open My Tasks view by itself', async ({ browser, peer }) => {
		test.setTimeout(180_000)
		const ctx = await browser.newContext()
		try {
			const page = await ctx.newPage()
			await ncLogin(page, peer.user, peer.pass)

			await page.goto(`${BASE}/index.php/apps/kanso#/my-tasks`)
			await expect(page.locator('.my-cards-view')).toBeVisible({ timeout: 15_000 })
			const row = page.locator('.my-cards-view__row', { hasText: TASK_TITLE })
			await expect(row).toBeHidden()
			await page.evaluate(() => { window.__kansoNoReload = true })

			// The current user assigns the peer to the card.
			await api.put(`/cards/${state.taskCardId}/assignees/${peer.user}`)

			await expect(row).toBeVisible({ timeout: 90_000 })
			expect(await page.evaluate(() => window.__kansoNoReload)).toBe(true)
			expect(new URL(page.url()).hash).toBe('#/my-tasks')
		} finally {
			await ctx.close()
		}
	})
})
