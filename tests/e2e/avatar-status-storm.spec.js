// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Card #3663 — Perf: guard against the NcAvatar user-status request storm on
// board load. `NcAvatar` (@nextcloud/vue 9) has NO `show-user-status` prop; the
// real one is `hide-status`. `:show-user-status="false"` was a no-op, so every
// assignee avatar mounted a `GET /user_status/api/v1/statuses/{uid}` request —
// O(cards × assignees) on board load. With `:hide-status="true"` the status dot
// is hidden and no status request should fire.
//
// This spec loads a board with several assigned cards and asserts the number of
// user_status requests is bounded (ideally zero) — not proportional to the
// card × assignee count.

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
	// Session is preloaded via storageState (see playwright.config.js) for the
	// default admin context — skip the UI login round-trip entirely. Specs that
	// opt out of storageState start with no cookies and fall through to log in.
	if ((await page.context().cookies()).some((c) => c.name === 'nc_username' || c.name === 'nc_session_id')) return
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	if (!(await page.locator('#user').isVisible({ timeout: 3000 }).catch(() => false))) return
	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

// Several assigned cards so a per-avatar storm (O(cards × assignees)) would be
// visibly larger than the O(unique actors) bound we assert.
const CARD_COUNT = 8

test.describe('Avatar user-status storm on board load (#3663)', () => {
	const state = { boardId: 0, stackId: 0 }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'StatusStorm ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.stackId = (await api('POST', '/stacks', { boardId: board.id, title: 'To do' })).id
		for (let i = 0; i < CARD_COUNT; i++) {
			const card = await api('POST', '/cards', { stackId: state.stackId, title: `Assigned ${i}` })
			// Assign admin (the only actor available in the dev stack) to each card.
			await api('PUT', `/cards/${card.id}/assignees/${USER}`)
		}
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('board load issues at most O(unique actors) user_status requests', async ({ page }) => {
		await ncLogin(page)

		// Count every user_status API request the page fires.
		const statusRequests = []
		page.on('request', (req) => {
			if (/\/user_status\/api\/v1\/statuses?\b/.test(req.url())) {
				statusRequests.push(req.url())
			}
		})

		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)

		// Wait for the assignee avatars to actually render — this is the mount
		// point that used to fire the storm.
		await page.waitForSelector('.card-tile__avatar', { timeout: 30_000 })
		await expect(page.locator('.card-tile__avatar').first()).toBeVisible()
		// Give any (mis)fired status requests a moment to land.
		await page.waitForTimeout(1500)

		const uniqueActors = 1 // only admin is assigned across all cards
		// eslint-disable-next-line no-console
		console.log(`[#3663] user_status requests on board load: ${statusRequests.length} (cards=${CARD_COUNT}, unique actors=${uniqueActors})`)

		// The status dot is hidden, so ideally ZERO status requests fire. Guard
		// against the O(cards × assignees) storm: the count must be bounded by the
		// number of unique actors, never by the card count. Allow a small slack
		// (+1) for any unrelated one-off status probe (e.g. the top-bar own-status
		// widget) without letting a per-avatar storm through.
		expect(statusRequests.length).toBeLessThanOrEqual(uniqueActors + 1)
		expect(statusRequests.length).toBeLessThan(CARD_COUNT)
	})
})
