// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10299 — charter speed bet #4, end to end in a real browser.
//
// BoardController::show has always answered a matching `If-None-Match` with a
// 304 before it touches the stack/card/label/acl tables, and BoardControllerTest
// has always proved that branch — by setting the request header itself. That
// test is just as green when NO client sends one, which is exactly the state
// this card found: one hit for `If-None-Match|ETag` in all of src/, and it was a
// comment. So the claim worth testing here is the one no server-side test can
// make: a real browser, running the shipped bundle, revalidates instead of
// re-downloading — and survives the answer. The two failure modes are opposite
// and both worse than doing nothing:
//   - no validator on the wire → the bet stays inert;
//   - a 304 mishandled → its EMPTY body overwrites the query cache and the user
//     is looking at a blank board.
//
// The refetch is triggered the way a user triggers one — leave the board, come
// back to it — rather than by waiting out useBoard's 60s `refetchInterval`.
// Measured while writing this spec: that interval never fires at all.
// syncBoardDelta calls setQueryData on EVERY delta tick (empty ones included),
// each dispatch reaches QueryObserver.onQueryUpdate → #updateTimers, and the
// interval is cleared and re-armed from zero every 5s (30s with push live) —
// always sooner than the 60s it is counting to. Separate bug, out of this card's
// scope, but it means a spec built on that timer would sit and wait forever.

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// main.js's global staleTime. A remount only refetches once the data is stale,
// so the spec has to outlive it.
const STALE_TIME = 30_000

test.describe('Board read ETag (#10299)', () => {
	const state = { boardId: 0, stackId: 0, cardId: 0, title: '' }

	test.beforeAll(async () => {
		const stamp = Math.floor(Date.now() / 1000)
		state.title = 'ETag Board ' + stamp
		const board = await api.post('/boards', { title: state.title })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'To do' })).id
		state.cardId = (await api.post('/cards', { stackId: state.stackId, title: 'etag-card' })).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('re-reading an unchanged board 304s and keeps the board; a changed one still comes back 200', async ({ page }) => {
		// One staleTime wait plus a resync round-trip, on a runner that can be 3x
		// slower than a dev box for everything around them.
		test.setTimeout(180_000)

		// Every full board read this page makes, with the validator it carried.
		// `/changes` is a different endpoint (deliberately ETag-less) and must not
		// be counted as one.
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

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await expect(page.locator('.card-tile').filter({ hasText: 'etag-card' }))
			.toBeVisible({ timeout: 15_000 })

		// The first read has nothing to revalidate against, so it is a full 200.
		expect(reads.length).toBeGreaterThan(0)
		expect(reads[0]).toEqual({ status: 200, validator: null })
		const afterLoad = reads.length

		// Leave the board, linger elsewhere long enough for its cache entry to go
		// stale, then come back — the ordinary "I was looking at something else"
		// path, and the one that actually produces a full re-read of an UNCHANGED
		// board. The waiting has to happen while the board view is unmounted: its
		// delta poll writes to the query cache every 5s, and each write resets
		// `dataUpdatedAt`, so a board that is still on screen never goes stale and
		// never gets re-read at all.
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await expect(page.locator('.board-tile, .boards-grid').first()).toBeVisible({ timeout: 15_000 })
		await page.waitForTimeout(STALE_TIME + 2_000)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await expect(page.locator('.card-tile').filter({ hasText: 'etag-card' }))
			.toBeVisible({ timeout: 15_000 })

		await expect.poll(() => reads.length, { timeout: 20_000, intervals: [500] })
			.toBeGreaterThan(afterLoad)

		const revalidations = reads.slice(afterLoad)
		for (const read of revalidations) {
			expect(read.validator, 'the re-read must replay the board ETag — without a '
				+ 'client sending it, BoardController::show\'s 304 branch is unreachable '
				+ 'in production and the bet is inert').not.toBeNull()
			expect(read.status, 'an unchanged board must answer 304, not re-serialize '
				+ 'every stack, card and label').toBe(304)
		}

		// And the board is still THERE. A 304 has an empty body; a client that
		// stores it wipes the query cache and blanks the board — a "faster" board
		// with nothing on it, strictly worse than the read it replaced.
		await expect(page.locator('.card-tile').filter({ hasText: 'etag-card' })).toBeVisible()
		await expect(page.locator('.board-view__title-text')).toContainText(state.title)

		// Now change the board from outside this session. A board rename is an
		// entity kind the delta path does not model, so /changes answers `resync`
		// and the client falls back to a full board read — which is now a
		// CONDITIONAL one. The validator no longer matches, so it must come back
		// 200 with the new payload, and the client must apply it. This is the
		// dangerous direction for a conditional read: a stale board nobody can
		// refresh. It also covers the acceptance criterion that the ETag path and
		// delta sync stay coherent, since the resync IS what triggers this read.
		const renamedTo = state.title + ' renamed'
		const seen = reads.length
		await api.patch(`/boards/${state.boardId}`, { title: renamedTo })

		await expect(page.locator('.board-view__title-text'))
			.toContainText(renamedTo, { timeout: 90_000 })

		const afterEdit = reads.slice(seen)
		expect(afterEdit.some((r) => r.status === 200),
			'a changed board must fall through to a full 200 — a conditional read '
			+ 'that kept answering 304 would strand the client on a stale board')
			.toBe(true)
		// …and it got there by revalidating, not by forgetting the ETag exists.
		expect(afterEdit.every((r) => r.validator !== null)).toBe(true)
		await expect(page.locator('.card-tile').filter({ hasText: 'etag-card' })).toBeVisible()
	})
})
