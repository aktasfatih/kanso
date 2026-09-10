// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10384 — a board open on screen must notice that its viewer's own access
// changed, without a reload.
//
// This is the case the card called the sharpest, and it is the one no amount of
// change-row plumbing can reach. A board's `permissions` and `role` are folded
// from the ACL rows addressing the viewer, and an ACL row may address a
// NEXTCLOUD GROUP. Group membership changes in Nextcloud — in the user admin
// page, or by an LDAP/SAML sync that never enters Kanso's process at all — so
// there is no Kanso write path on which to append a `kanso_changes` row. Delta
// sync reads that log, so delta sync is blind to it, and delta sync was the ONLY
// refresh path an open board had, because syncBoardDelta wrote the query cache
// on every empty tick and so re-armed the 60s `refetchInterval` from zero
// forever (measured: zero GET /boards/{id} in 100s).
//
// Two things therefore had to change together, and this spec is what proves the
// pair works end to end in a real browser:
//   1. an empty delta tick no longer writes the cache, so the 60s safety-net
//      re-read actually fires (pinned in isolation by
//      tests/unit/boardRefetchInterval.test.mjs);
//   2. the board read's validator covers the viewer's own permission mask and
//      role, not just the board's latest change id — otherwise that restored
//      re-read would revalidate successfully and be answered 304 out of the
//      client's own stale payload, which is the exact opposite of a fix.
//
// The scenario is a PARTIAL revocation: the peer holds the board through two
// group grants, one EDIT and one READ, and is dropped from the EDIT group. Full
// revocation is a different (and already safe) path — BoardService::find throws
// 403 before any validator is consulted — while the partial one leaves the
// board readable and the change id untouched, so it is the case that actually
// exercises both halves.

import { test, expect, api, ncLogin, BASE, OCS, adminAuth } from './helpers.js'

// useBoard's safety-net cadence, plus enough slack for a CI runner that can be
// 2-3x slower than a dev box at everything around it.
const REFETCH_INTERVAL = 60_000
const SLACK = 30_000

/** OCS provisioning call as admin. `fetch` is used directly: this is Nextcloud's
 * API, not Kanso's, so it does not go through the helpers' Kanso client. */
async function ocs(method, path, params = null) {
	const res = await fetch(`${OCS}${path}`, {
		method,
		headers: {
			'OCS-APIREQUEST': 'true',
			Authorization: adminAuth,
			'Content-Type': 'application/x-www-form-urlencoded',
		},
		body: params ? new URLSearchParams(params) : undefined,
	})
	const text = await res.text()
	// 102 "already exists" is success for the idempotent create calls.
	if (!res.ok && !/already exists/i.test(text)) {
		throw new Error(`${method} ${path} → ${res.status}: ${text}`)
	}
	return text
}

test.describe('An open board notices its own access changing (#10384)', () => {
	// A second identity logs in explicitly, so this describe must NOT inherit the
	// shared admin storageState — it would silently stay admin and false-pass.
	test.use({ storageState: { cookies: [], origins: [] }, viewport: { width: 1600, height: 900 } })

	const state = { boardId: 0, editGroup: '', readGroup: '', boardUrl: '' }

	test.beforeAll(async ({ peer }) => {
		const stamp = Math.floor(Date.now() / 1000)
		state.editGroup = `kansoe2e_edit_${stamp}`
		state.readGroup = `kansoe2e_read_${stamp}`

		await ocs('POST', '/groups', { groupid: state.editGroup })
		await ocs('POST', '/groups', { groupid: state.readGroup })
		await ocs('POST', `/users/${encodeURIComponent(peer.user)}/groups`, { groupid: state.editGroup })
		await ocs('POST', `/users/${encodeURIComponent(peer.user)}/groups`, { groupid: state.readGroup })

		const board = await api.post('/boards', { title: 'Group membership ' + stamp })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Inbox' })
		await api.post('/cards', { stackId: stack.id, title: 'group-membership-card' })

		// Two GROUP grants, deliberately: the peer's membership of the wider one
		// is the only thing this spec changes, and the narrower one is what keeps
		// the board readable afterwards so the assertion is about the RENDERED
		// board rather than about a 403 page.
		await api.post(`/boards/${board.id}/acl`, {
			participant: state.editGroup,
			participantType: 'group',
			permission: 3, // READ | EDIT
		})
		await api.post(`/boards/${board.id}/acl`, {
			participant: state.readGroup,
			participantType: 'group',
			permission: 1, // READ
		})

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async ({ peer }) => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
		for (const gid of [state.editGroup, state.readGroup]) {
			if (!gid) continue
			await ocs('DELETE', `/users/${encodeURIComponent(peer.user)}/groups`, { groupid: gid }).catch(() => {})
			await ocs('DELETE', `/groups/${encodeURIComponent(gid)}`).catch(() => {})
		}
	})

	test('losing a group grant narrows the open board without a reload, and the idle re-read stays a 304', async ({ browser, peer }) => {
		test.setTimeout(REFETCH_INTERVAL * 2 + SLACK * 3)

		const ctx = await browser.newContext({ viewport: { width: 1600, height: 900 } })
		try {
			const page = await ctx.newPage()

			// Every full board read this page makes, with the validator it carried.
			// `/changes` is a different endpoint (deliberately ETag-less) and must
			// not be counted as one.
			const reads = []
			page.on('response', (res) => {
				if (!new RegExp(`/boards/${state.boardId}(\\?|$)`).test(res.url())) {
					return
				}
				reads.push({
					status: res.status(),
					validator: res.request().headers()['if-none-match'] ?? null,
				})
			})

			await ncLogin(page, { user: peer.user, pass: peer.pass })
			await page.goto(state.boardUrl)
			await page.waitForSelector('.board-view__header', { timeout: 20_000 })

			// The EDIT-gated affordance (the same one card-composer-acl.spec.js
			// uses as the canonical "this member may write" marker).
			const composer = page.locator('.stack-column .card-composer__input')
			await expect(composer, 'the peer holds EDIT through a group grant, so the '
				+ 'composer must be offered to begin with')
				.toBeVisible({ timeout: 15_000 })
			const afterLoad = reads.length

			// ── Half 1: the safety-net re-read fires at all, and is cheap. ──
			//
			// Nothing has changed, and nothing is touched: the board simply sits
			// there while its delta poll ticks. Before #10384 this window produced
			// no board read whatsoever, because every empty tick re-armed the 60s
			// interval. It must now produce one, and it must be a 304 — a restored
			// re-read that re-downloaded the board every minute would have traded
			// this card's correctness fix for the regression #10299 just removed.
			await expect
				.poll(() => reads.length, { timeout: REFETCH_INTERVAL + SLACK, intervals: [2_000] })
				.toBeGreaterThan(afterLoad)

			const idle = reads.slice(afterLoad)
			expect(idle.every((r) => r.validator !== null),
				'the periodic re-read must replay the validator — the whole point of '
				+ 'restoring it is that it costs a revalidation, not a board').toBe(true)
			expect(idle.every((r) => r.status === 304),
				'an unchanged board, unchanged viewer, must still answer 304').toBe(true)
			const afterIdle = reads.length

			// ── Half 2: the membership change lands, unassisted. ──
			//
			// No reload, no navigation, no interaction on the page — the tab is left
			// exactly as it was. This happens entirely inside Nextcloud, so no
			// `kanso_changes` row is written, the board's latest change id does not
			// move, and neither the delta poll nor a change-id-only validator can
			// see it.
			await ocs('DELETE', `/users/${encodeURIComponent(peer.user)}/groups`, {
				groupid: state.editGroup,
			})

			await expect(composer, 'the board must reflect the revoked group grant on '
				+ 'its own. A validator that only covered the board\'s change id would '
				+ 'answer this re-read 304 out of the client\'s own stale payload, and '
				+ 'the user would go on being offered writes they no longer hold for as '
				+ 'long as the tab stayed open')
				.toHaveCount(0, { timeout: REFETCH_INTERVAL + SLACK })

			// …and it got there by revalidating and being told no, not by giving up
			// on the ETag.
			const afterRevoke = reads.slice(afterIdle)
			expect(afterRevoke.some((r) => r.status === 200 && r.validator !== null),
				'the re-read that carried the new permissions must have been a '
				+ 'CONDITIONAL 200: the validator has to break on a membership change '
				+ 'even though the board itself did not change').toBe(true)

			// The board is still THERE — the peer kept READ through the other group,
			// so this is a narrowing, not a disappearance.
			await expect(page.locator('.card-tile', { hasText: 'group-membership-card' }))
				.toBeVisible()
		} finally {
			await ctx.close()
		}
	})
})
