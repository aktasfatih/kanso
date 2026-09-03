// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// #3494 — per-card Activity tab.
test.describe('Card Activity feed', () => {
	const state = { boardId: 0, cardId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.send('POST', '/boards', { title: 'Activity E2E' })
		state.boardId = board.id
		const stack = await api.send('POST', '/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api.send('POST', '/cards', { stackId: stack.id, title: 'Tracked card' })
		state.cardId = card.id
		// Generate a few distinct activity rows.
		await api.send('POST', `/cards/${card.id}/comments`, { body: 'first note' })
		await api.send('PATCH', `/cards/${card.id}`, { priority: 3 })
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.send('DELETE', `/boards/${state.boardId}`).catch(() => {})
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

	// #10131 — "5 days ago" can't answer "when exactly?". Every activity row now
	// carries the precise stamp beside the relative label, in a <time> element so
	// it is machine-readable and copy-pasteable.
	//
	// The stamp is ALWAYS rendered, deliberately NOT a hover tooltip: a
	// hover-only affordance is unreachable on touch, and this app is installed on
	// phones. So this asserts visibility with no hover, no focus and no tap —
	// and that the relative label survives alongside it (the change is additive).
	test('every activity row shows the exact date and time, with no hover needed', async ({ page }) => {
		// A time entry on the same card: the activity feed and the time-tracking
		// list share the `.card-modal__activity-time` class, and this change must
		// leave the time-tracking list byte-for-byte as it was.
		const timeEntry = await api.send('POST', `/cards/${state.cardId}/time-entries`, { seconds: 5400, note: 'Pairing' })

		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// The time-tracking list is on the main pane, before we go near Activity.
		const trackedRow = page.locator('.card-modal__time-entry', { hasText: 'Pairing' })
		await expect(trackedRow).toHaveCount(1, { timeout: 8_000 })
		// UNCHANGED: still the relative label alone — no <time>, no exact stamp.
		await expect(trackedRow.locator('.card-modal__activity-time')).toHaveCount(1)
		await expect(trackedRow.locator('.card-modal__activity-time time')).toHaveCount(0)
		await expect(trackedRow.locator('.card-modal__activity-exact')).toHaveCount(0)

		await page.locator('.card-modal__discussion-tab', { hasText: 'Activity' }).click()
		const rows = page.locator('.card-modal__activity-row')
		await expect(rows.first()).toBeVisible({ timeout: 8_000 })

		// One exact stamp per row — no row is left with a relative label only.
		const stamps = page.locator('.card-modal__activity-row time.card-modal__activity-exact')
		await expect.poll(async () => await stamps.count()).toBe(await rows.count())

		const stamp = stamps.first()
		// Visible as rendered: nothing was hovered, focused or tapped.
		await expect(stamp).toBeVisible()
		// Machine-readable instant on the element itself.
		expect(await stamp.getAttribute('datetime')).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/)
		// And a human clock in the visible text.
		await expect(stamp).toHaveText(/\d{1,2}:\d{2}/)
		// The relative label is still there — this is additive, not a swap — and
		// the separator is real text, so a copy-paste reads "just now · 09:31 PM"
		// rather than running the two labels together.
		await expect(rows.first()).toContainText(/ago|just now/)
		expect(await rows.first().textContent()).toContain(' · ')
		// The title spells the stamp out in full.
		const title = await stamp.getAttribute('title')
		expect((title || '').length).toBeGreaterThan((await stamp.textContent()).length)

		await api.send('DELETE', `/cards/${state.cardId}/time-entries/${timeEntry.id}`).catch(() => {})
	})

	// #3553 — the feed must update live while the Activity tab is open, both for a
	// change the same client makes and for one arriving via realtime/poll. We drive
	// the change through the API (an external mutation that broadcasts
	// kanso_board_changed) and assert the feed grows with NO manual tab switch.
	test('an external change refreshes the open Activity feed without a tab switch', async ({ page }) => {
		// A fresh card so this test's row counts are independent of the first test.
		const stack = await api.send('POST', '/stacks', { boardId: state.boardId, title: 'Live' })
		const card = await api.send('POST', '/cards', { stackId: stack.id, title: 'Live card' })
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
		await api.send('PATCH', `/cards/${card.id}`, { priority: 2 })

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
		const stack = await api.send('POST', '/stacks', { boardId: state.boardId, title: 'Storm' })
		const card = await api.send('POST', '/cards', { stackId: stack.id, title: 'Storm card' })
		for (let i = 0; i < 15; i++) {
			await api.send('POST', `/cards/${card.id}/comments`, { body: `note ${i}` })
			await api.send('PATCH', `/cards/${card.id}`, { priority: i % 3 })
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
