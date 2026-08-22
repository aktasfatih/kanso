// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')

async function api(method, path, body) {
	const r = await fetch(API + path, {
		method,
		headers: { ...HEADERS, Authorization: AUTH },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`${method} ${path} → ${r.status}: ${await r.text()}`)
	return method === 'DELETE' ? null : r.json()
}

async function ncLogin(page) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	if (!(await page.locator('#user').isVisible({ timeout: 3000 }).catch(() => false))) return
	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

// #3494 — per-card Activity tab.
test.describe('Card Activity feed', () => {
	const state = { boardId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Activity E2E' })
		state.boardId = board.id
		const stack = await api('POST', '/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api('POST', '/cards', { stackId: stack.id, title: 'Tracked card' })
		// Generate a few distinct activity rows.
		await api('POST', `/cards/${card.id}/comments`, { body: 'first note' })
		await api('PATCH', `/cards/${card.id}`, { priority: 3 })
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('the Activity tab lists what happened to the card, newest-first', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Switch to the Activity tab.
		await page.locator('.card-modal__discussion-tab', { hasText: 'Activity' }).click()

		const rows = page.locator('.card-modal__activity-row')
		await expect(rows.first()).toBeVisible({ timeout: 8_000 })
		// created + commented + priority-change → at least 3 rows.
		expect(await rows.count()).toBeGreaterThanOrEqual(3)

		// The feed shows the verbs (not a stream of blank "updated").
		const feed = page.locator('.card-modal__activity')
		await expect(feed).toContainText('commented')
		await expect(feed).toContainText('created this card')

		// Newest-first: the most recent row is the priority change (verb 19).
		// The granular activity log now renders it as "changed the priority to X"
		// rather than the generic "updated this card".
		await expect(rows.first()).toContainText('changed the priority')
	})

	// #3553 — the feed must update live while the Activity tab is open, both for a
	// change the same client makes and for one arriving via realtime/poll. We drive
	// the change through the API (an external mutation that broadcasts
	// kanso_board_changed) and assert the feed grows with NO manual tab switch.
	test('an external change refreshes the open Activity feed without a tab switch', async ({ page }) => {
		// A fresh card so this test's row counts are independent of the first test.
		const stack = await api('POST', '/stacks', { boardId: state.boardId, title: 'Live' })
		const card = await api('POST', '/cards', { stackId: stack.id, title: 'Live card' })
		const cardUrl = `${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${card.id}`

		await ncLogin(page)
		await page.goto(cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Open the Activity tab and record what's there (just the "created" row).
		await page.locator('.card-modal__discussion-tab', { hasText: 'Activity' }).click()
		const rows = page.locator('.card-modal__activity-row')
		await expect(rows.first()).toBeVisible({ timeout: 8_000 })
		const before = await rows.count()

		// Only start watching for console errors now that the app + modal have
		// booted — a page-lifetime capture would catch unrelated Nextcloud boot
		// noise. We scope to errors mentioning the feature under test.
		const activityErrors = []
		page.on('console', (msg) => {
			if (msg.type() !== 'error') return
			const text = msg.text()
			if (/card-activity|activity|CardModal/i.test(text)) activityErrors.push(text)
		})

		// Mutate the card via the API WITHOUT touching the tab. This appends to
		// kanso_changes and broadcasts kanso_board_changed; the open feed must pick
		// it up through the board-cache → card-activity invalidation (push or poll).
		await api('PATCH', `/cards/${card.id}`, { priority: 2 })

		// The new "changed the priority" row appears on its own — no tab switch.
		// A single edit may append ≥1 change row, so assert growth (not an exact
		// count) plus the new verb. Budget covers push and the 5s poll fallback.
		// The granular activity log now renders priority changes as "changed the priority"
		// rather than the generic "updated this card".
		await expect
			.poll(async () => await rows.count(), { timeout: 15_000 })
			.toBeGreaterThan(before)
		await expect(rows.first()).toContainText('changed the priority')

		expect(activityErrors).toEqual([])
	})

	// #3659 — the Activity feed must not fire a request storm. Every NcAvatar in
	// the modal used to fetch the actor's user_status per instance (the prop was
	// `show-user-status`, which @nextcloud/vue 9 doesn't have — the real one is
	// `hide-status`), so a feed of N rows by the same actor issued ~N presence
	// lookups. With `:hide-status="true"` the count must be bounded regardless of
	// how many entries share an actor.
	test('a multi-entry feed with a repeated actor stays O(actors), not O(entries)', async ({ page }) => {
		// One card with MANY activity rows, all authored by the same actor (admin).
		const stack = await api('POST', '/stacks', { boardId: state.boardId, title: 'Storm' })
		const card = await api('POST', '/cards', { stackId: stack.id, title: 'Storm card' })
		for (let i = 0; i < 15; i++) {
			await api('POST', `/cards/${card.id}/comments`, { body: `note ${i}` })
			await api('PATCH', `/cards/${card.id}`, { priority: i % 3 })
		}
		const cardUrl = `${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${card.id}`

		// Count avatar / user-status / displayname network calls. These are the
		// per-actor lookups that must NOT scale with the number of entries.
		const perActorReqs = []
		const perActorRe = /\/avatar\/|\/user_status\/api\/v1\/statuses\/|\/displaynames/
		page.on('request', (req) => {
			if (perActorRe.test(req.url())) perActorReqs.push(req.url())
		})

		await ncLogin(page)
		await page.goto(cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Open the Activity tab and wait for a genuinely multi-entry feed.
		await page.locator('.card-modal__discussion-tab', { hasText: 'Activity' }).click()
		const rows = page.locator('.card-modal__activity-row')
		await expect(rows.first()).toBeVisible({ timeout: 8_000 })
		await expect.poll(async () => await rows.count()).toBeGreaterThanOrEqual(10)

		// Let any straggling avatar/status requests settle.
		await page.waitForTimeout(1500)

		const rowCount = await rows.count()
		// Dedup by URL: a correct implementation resolves each actor's avatar and
		// status ONCE and reuses it. Even counting raw (non-deduped) requests, the
		// total must be far below one-per-row — bound it well under the row count.
		expect(perActorReqs.length).toBeLessThan(rowCount)
		// And the DISTINCT per-actor endpoints hit must be a small constant
		// (a single actor here → a handful of URLs at most), never O(entries).
		const distinct = new Set(perActorReqs).size
		expect(distinct).toBeLessThanOrEqual(4)
	})
})
