// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10385 — a board on screen whose access is revoked ENTIRELY must stop showing
// itself; a board whose server merely stumbled must not.
//
// #10384 restored the periodic re-read, so the 403 now actually arrives. What
// the app did with it was nothing visible: a failed refetch leaves the query's
// `data` in place, and BoardView renders its stacks from `data`, so the revoked
// board went on rendering — cards, columns and all — under an error banner. "The
// app still shows me the board" is indistinguishable from "I still have access"
// to the person looking at it.
//
// The half that makes the fix safe is the other one, and two of the three tests
// below are it: a 500 and a dropped connection must leave the cached board
// exactly where it is. Blanking on those would turn a flaky network into an
// empty screen — a worse bug than the one being fixed — and would undo both the
// offline cache and #10299's 304 path, whose whole premise is that a re-read
// producing no new board leaves the rendered one alone.
//
// Everything here is driven from the browser with no reload and no interaction:
// the tab is left exactly as the user left it, which is the situation the bug
// lives in.

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// useBoard's slowest path to a board read: the delta poll (5s, or 30s once push
// is proven) invalidates the board on a failed /changes, and the 60s safety-net
// re-read fires regardless. Plus slack for a CI runner that is 2-3x slower.
const REFETCH_INTERVAL = 60_000
const SLACK = 40_000

const CARD_TITLE = 'revocation-card'

/** Boards created by this file, torn down in afterAll. */
const created = []

/**
 * A board holding one card, shared with `peer` at READ|EDIT through a direct
 * user grant. Per test rather than per file: the first test revokes the grant,
 * and a shared board would leave the other two testing a board nobody can read.
 *
 * @param {{user: string}} peer - the second identity fixture
 * @return {Promise<{id: number, aclId: number, url: string}>}
 */
async function shareBoardWithPeer(peer) {
	const board = await api.post('/boards', { title: 'Access revoked ' + Date.now() })
	created.push(board.id)
	const stack = await api.post('/stacks', { boardId: board.id, title: 'Inbox' })
	await api.post('/cards', { stackId: stack.id, title: CARD_TITLE })
	const acl = await api.post(`/boards/${board.id}/acl`, {
		participant: peer.user,
		participantType: 'user',
		permission: 3, // READ | EDIT
	})
	return {
		id: board.id,
		aclId: acl.id,
		url: `${BASE}/index.php/apps/kanso#/board/${board.id}`,
	}
}

/**
 * Every read this board makes: the full board read and its `/changes` delta.
 *
 * A RegExp rather than a glob, because `**\/boards/${id}**` also matches every
 * board whose id merely STARTS with this one's (`/boards/5` catching
 * `/boards/55`), and a fault injected into an unrelated board's reads would make
 * these tests mean something else entirely.
 *
 * @param {number} id - board id
 * @return {RegExp}
 */
function boardRequests(id) {
	return new RegExp(`/apps/kanso/api/boards/${id}(?:[/?]|$)`)
}

/**
 * Assert that a locator stays absent for a whole window, rather than merely
 * becoming absent within one. Playwright's retrying assertions all wait for a
 * condition to BECOME true, which cannot express "the stale board never
 * flashes" — the exact claim the dropped-payload half makes.
 *
 * @param {import('@playwright/test').Locator} locator
 * @param {string} message
 * @param {number} windowMs
 * @return {Promise<void>}
 */
async function staysAbsent(locator, message, windowMs = 4_000) {
	const deadline = Date.now() + windowMs
	while (Date.now() < deadline) {
		expect(await locator.count(), message).toBe(0)
		await new Promise((resolve) => setTimeout(resolve, 200))
	}
}

test.describe('A board on screen when access is revoked (#10385)', () => {
	// A second identity logs in explicitly, so this describe must NOT inherit the
	// shared admin storageState — it would silently stay admin and false-pass.
	// (Each test builds its own context below, which never inherits it anyway;
	// this is the belt to that pair of braces, and the documented guard.)
	test.use({ storageState: { cookies: [], origins: [] } })

	test.afterAll(async () => {
		for (const id of created) {
			await api.delete(`/boards/${id}`).catch(() => {})
		}
	})

	test('revoking access entirely replaces the board with the explanation, and the payload is gone', async ({ browser, peer }) => {
		test.setTimeout(REFETCH_INTERVAL * 2 + SLACK * 3)
		const board = await shareBoardWithPeer(peer)

		const ctx = await browser.newContext({ viewport: { width: 1600, height: 900 } })
		try {
			const page = await ctx.newPage()
			await ncLogin(page, { user: peer.user, pass: peer.pass })
			await page.goto(board.url)
			await page.waitForSelector('.board-view__header', { timeout: 20_000 })

			const card = page.locator('.card-tile', { hasText: CARD_TITLE })
			await expect(card, 'the peer holds the board to begin with').toBeVisible({ timeout: 15_000 })

			// Revoked entirely — no second grant, no group, nothing left. Every read
			// this tab makes from here is answered 403.
			await api.delete(`/boards/${board.id}/acl/${board.aclId}`)

			// The copy that was already written for exactly this and never showed.
			await expect(page.locator('.board-view__error-msg'),
				'the viewer must be told, without touching anything')
				.toHaveText(/no longer have access|no longer exists/, { timeout: REFETCH_INTERVAL + SLACK })

			await expect(card,
				'and the revoked board must stop rendering. A failed refetch keeps the '
				+ 'query\'s data, and the stacks render from data, so the board sat there '
				+ 'under the banner — still offering its cards to someone the server was '
				+ 'by then refusing every write from')
				.toHaveCount(0, { timeout: 15_000 })
			await expect(page.locator('.stack-column'), 'columns included').toHaveCount(0)

			// The payload must be DROPPED, not merely hidden. Navigating away and
			// back inside the SPA (no reload, so the query cache is the same one)
			// would otherwise render it straight back out of cache while the fresh
			// 403 is still in flight.
			await page.evaluate(() => { window.location.hash = '#/' })
			await page.waitForSelector('.board-list-view', { timeout: 20_000 })
			await page.evaluate((id) => { window.location.hash = `#/board/${id}` }, board.id)

			await staysAbsent(page.locator('.card-tile', { hasText: CARD_TITLE }),
				'coming back to the revoked board must not resurrect it out of the '
				+ 'cache — the payload is also dehydrated into the offline snapshot on '
				+ 'every cache change, so anything left behind outlives the session')
			await expect(page.locator('.board-view__error-msg'),
				'and the explanation is what greets them instead')
				.toHaveText(/no longer have access|no longer exists/, { timeout: 20_000 })
		} finally {
			await ctx.close()
		}
	})

	test('a 500 leaves the cached board on screen', async ({ browser, peer }) => {
		test.setTimeout(REFETCH_INTERVAL * 2 + SLACK * 3)
		const board = await shareBoardWithPeer(peer)

		const ctx = await browser.newContext({ viewport: { width: 1600, height: 900 } })
		try {
			const page = await ctx.newPage()
			await ncLogin(page, { user: peer.user, pass: peer.pass })
			await page.goto(board.url)
			await page.waitForSelector('.board-view__header', { timeout: 20_000 })

			const card = page.locator('.card-tile', { hasText: CARD_TITLE })
			await expect(card).toBeVisible({ timeout: 15_000 })

			// Every subsequent read of this board — full read and delta alike — is a
			// server error. The viewer's access is not in question; the server just
			// failed to answer.
			let faults = 0
			await page.route(boardRequests(board.id), async (route) => {
				faults++
				await route.fulfill({
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify({ message: 'Internal Server Error' }),
				})
			})

			await expect
				.poll(() => faults, {
					message: 'the 500 must actually have reached a board read, or this test '
						+ 'asserts nothing at all',
					timeout: REFETCH_INTERVAL + SLACK,
					intervals: [1_000],
				})
				.toBeGreaterThan(0)

			// Give the failure every chance to blank the board before believing it did not.
			await page.waitForTimeout(5_000)
			await expect(card,
				'a 500 must leave the cached board exactly where it was — treating any '
				+ 'error as terminal turns a backend hiccup into an empty screen')
				.toBeVisible()
			await expect(page.locator('.stack-column').first()).toBeVisible()
		} finally {
			await ctx.close()
		}
	})

	test('a dropped connection leaves the cached board on screen', async ({ browser, peer }) => {
		test.setTimeout(REFETCH_INTERVAL * 2 + SLACK * 3)
		const board = await shareBoardWithPeer(peer)

		const ctx = await browser.newContext({ viewport: { width: 1600, height: 900 } })
		try {
			const page = await ctx.newPage()
			await ncLogin(page, { user: peer.user, pass: peer.pass })
			await page.goto(board.url)
			await page.waitForSelector('.board-view__header', { timeout: 20_000 })

			const card = page.locator('.card-tile', { hasText: CARD_TITLE })
			await expect(card).toBeVisible({ timeout: 15_000 })

			// The other transient shape, and the one that reads differently in code: a
			// dropped request produces a rejection with no HTTP response at all, so
			// any check that reaches for a status finds `undefined`. This is precisely
			// when the cached board matters most.
			let faults = 0
			await page.route(boardRequests(board.id), async (route) => {
				faults++
				await route.abort('failed')
			})

			await expect
				.poll(() => faults, {
					message: 'the dropped connection must actually have reached a board read',
					timeout: REFETCH_INTERVAL + SLACK,
					intervals: [1_000],
				})
				.toBeGreaterThan(0)

			await page.waitForTimeout(5_000)
			await expect(card,
				'an offline blip must leave the cached board on screen — this is the '
				+ 'case the offline cache exists for')
				.toBeVisible()
			await expect(page.locator('.stack-column').first()).toBeVisible()
		} finally {
			await ctx.close()
		}
	})
})
