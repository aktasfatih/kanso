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

import { test, expect, api, ncLogin, BASE } from './helpers.js'

const USER = 'admin'

// Several assigned cards so a per-avatar storm (O(cards × assignees)) would be
// visibly larger than the O(unique actors) bound we assert.
const CARD_COUNT = 8

test.describe('Avatar user-status storm on board load (#3663)', () => {
	const state = { boardId: 0, stackId: 0 }

	test.beforeAll(async () => {
		const board = await api.send('POST', '/boards', { title: 'StatusStorm ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.stackId = (await api.send('POST', '/stacks', { boardId: board.id, title: 'To do' })).id
		for (let i = 0; i < CARD_COUNT; i++) {
			const card = await api.send('POST', '/cards', { stackId: state.stackId, title: `Assigned ${i}` })
			// Assign admin (the only actor available in the dev stack) to each card.
			await api.send('PUT', `/cards/${card.id}/assignees/${USER}`)
		}
	})

	test.afterAll(async () => {
		if (state.boardId) await api.send('DELETE', `/boards/${state.boardId}`).catch(() => {})
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
