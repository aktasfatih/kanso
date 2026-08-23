// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Delta sync (#3675): when board B reflects a change made on board A, it must do
// so via the `?since=` delta endpoint (GET /boards/{id}/changes) and PATCH only
// the touched card into its cache - NOT re-download the whole board via
// GET /boards/{id}. This runs on the dev/ docker stack (like realtime.spec.js).

import { test, expect, api, ncLogin, BASE, adminAuth } from './helpers.js'
import { execSync } from 'node:child_process'

// Kept local: the raw capabilities probe below is an OCS (not Kanso-API) call
// with its own headers, so it can't use the shared Kanso client.
const HEADERS = {
	'OCS-APIREQUEST': 'true',
	'Content-Type': 'application/json',
}

// notify_push is disabled for this suite so B takes the deterministic 5s delta
// poll (the push path also delta-syncs, but the poll is what we can reliably
// force on any runner). Mirrors realtime.spec.js's fallback handling.
const SKIP_NOTIFY_PUSH = process.env.KANSO_SKIP_NOTIFY_PUSH === '1'

function occSafe(command) {
	if (SKIP_NOTIFY_PUSH) return
	try {
		execSync(`docker exec -u www-data kanso-dev php occ ${command}`, { stdio: 'pipe' })
	} catch {
		// notify_push not installed on this runner - nothing to toggle.
	}
}

test.describe('Board delta sync (#3675)', () => {
	// Drives two distinct users (admin + tester) and logs each in explicitly — so
	// it must NOT inherit the shared authenticated storageState, or every context
	// would start as admin and ncLogin would no-op.
	test.use({ storageState: { cookies: [], origins: [] } })

	const state = { boardId: 0, stackId: 0, cardId: 0, boardUrl: '' }

	test.beforeAll(async ({ peer }) => {
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === 'Delta Sync Board') {
				await api.send('DELETE', `/boards/${b.id}`)
			}
		}
		const board = await api.post('/boards', { title: 'Delta Sync Board' })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'S1' })
		state.stackId = stack.id
		const card = await api.post('/cards', { stackId: stack.id, title: 'delta-original' })
		state.cardId = card.id
		await api.post(`/boards/${board.id}/acl`, {
			participant: peer.user,
			participantType: 'user',
			permission: 3,
		})
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		occSafe('app:enable notify_push')
		if (state.boardId) await api.send('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('B reflects an edit via /changes, not a full board refetch', async ({ browser, peer }) => {
		// Force the poll path: disable notify_push and wait for the capability to
		// stop being advertised (a fixed sleep was a flake source in realtime.spec).
		occSafe('app:disable notify_push')
		for (let i = 0; i < 20; i++) {
			const r = await fetch(BASE + '/ocs/v2.php/cloud/capabilities?format=json', {
				headers: { ...HEADERS, Authorization: adminAuth },
			})
			const caps = (await r.json())?.ocs?.data?.capabilities ?? {}
			if (!('notify_push' in caps)) break
			await new Promise((resolve) => setTimeout(resolve, 500))
		}

		const testerCtx = await browser.newContext()
		try {
			const testerPage = await testerCtx.newPage()
			await ncLogin(testerPage, { user: peer.user, pass: peer.pass })

			// Track B's board API calls. The initial full load hits /boards/{id};
			// after that, reflecting A's edit must go through /boards/{id}/changes.
			const fullReads = []
			const deltaReads = []
			testerPage.on('request', (req) => {
				const u = req.url()
				if (u.includes(`/boards/${state.boardId}/changes`)) {
					deltaReads.push(u)
				} else if (new RegExp(`/boards/${state.boardId}(\\?|$)`).test(u)) {
					fullReads.push(u)
				}
			})

			await testerPage.goto(state.boardUrl)
			await expect(testerPage.locator('.stack-column').first()).toBeVisible({ timeout: 15_000 })
			await expect(
				testerPage.locator('.card-tile').filter({ hasText: 'delta-original' }),
			).toBeVisible({ timeout: 15_000 })

			// The initial load did at least one full board read; from here we only
			// tolerate delta reads for reflecting the change.
			const fullReadsAfterLoad = fullReads.length

			// Admin edits the card title. Its change row bumps the board cursor.
			await api.patch(`/cards/${state.cardId}`, { title: 'delta-edited' })

			// B must reflect the new title WITHOUT any interaction, via the 5s poll.
			await expect(
				testerPage.locator('.card-tile').filter({ hasText: 'delta-edited' }),
			).toBeVisible({ timeout: 15_000 })
			await expect(
				testerPage.locator('.card-tile').filter({ hasText: 'delta-original' }),
			).toHaveCount(0)

			// The reflection came through the delta endpoint, and it did NOT trigger
			// a new full-board download (the whole point of #3675).
			expect(deltaReads.length).toBeGreaterThan(0)
			expect(fullReads.length).toBe(fullReadsAfterLoad)
		} finally {
			occSafe('app:enable notify_push')
			await testerCtx.close()
		}
	})
})
