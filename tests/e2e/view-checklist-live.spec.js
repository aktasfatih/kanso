// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #9898 — the two mutation paths #9859 left behind.
//
// #9859 swapped card mutations onto invalidateCrossBoardFeeds so an edit made
// in the card overlay reaches the View feed and the My Work feeds. Two paths
// reached NEITHER and were out of scope:
//
//   1. useChecklist's addItem / toggleItem / deleteItem — they settled on the
//      checklist + card + board keys only.
//   2. BoardView's 0–4 priority quick-set shortcut — its .finally() invalidated
//      only the board key (the adjacent `d` shortcut already did the right
//      thing).
//
// The checklist half is the one with real teeth, and it is what the first two
// tests drive. `checklist` is a View FILTER facet (useBoardFilters: has /
// incomplete / complete / none, off the derived {total,done} summary), so an
// item add/toggle/delete can push a card INTO or OUT OF a filtered View. And
// the card detail opens as an in-place overlay ON the View (ViewPage), so
// ['view-cards'] is an ACTIVE query at mutation time — invalidateQueries
// refetches it immediately. Without the fix the tile behind the overlay keeps
// the stale membership until the 60s interval ticks, so these two tests FAIL on
// unfixed code. Every assertion runs with the overlay still open and a
// no-reload marker in place, so nothing but the settle-phase invalidation can
// be doing the work.
//
// The priority shortcut is asserted on its observable half only (third test):
// useMyWorkBadges keeps ['my-cards'] mounted app-wide, so the fix makes the
// keypress fire a real GET /api/my-cards. It is deliberately NOT asserted via
// "press 0–4 then navigate to a View": the shortcut only exists on the board
// route, where view-cards is INACTIVE, and useViewCards sets
// refetchOnMount:'always' — so that navigation refetches with or without the
// fix and the assertion could not fail.
//
// The complementary guard — that a notify_push event still does NOT drag the
// View feed onto the 30s realtime cadence — lives in
// tests/unit/queryKeys.test.mjs. Push is disabled in CI
// (KANSO_SKIP_NOTIFY_PUSH=1) and absent from the dev stack, so asserting it in
// a browser would pass vacuously.

import { test, expect, api, ncLogin, BASE, boardUrl } from './helpers.js'

test.describe('Cross-board feed invalidation, the missed paths (#9898)', () => {
	const ts = Math.floor(Date.now() / 1000)
	const COMPLETE_CARD = `CLLive Complete ${ts}`
	const INCOMPLETE_CARD = `CLLive Incomplete ${ts}`
	const PRIORITY_CARD = `CLLive Priority ${ts}`
	const SEED_DONE = 'seeded done step'
	const SEED_OPEN = 'seeded open step'

	const state = {
		boardId: 0,
		scopeLabelId: 0,
		completeCardId: 0,
		incompleteCardId: 0,
	}

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: `CLLive E2E ${ts}` })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })

		// A per-run scope label the saved Views filter on, so each View resolves
		// to EXACTLY this run's cards no matter what else lives in the dev DB.
		state.scopeLabelId = (await api.post('/labels', {
			boardId: board.id,
			title: `cllive-scope ${ts}`,
			color: 'ff0000',
		})).id

		// Card A: one item, done → checklist summary {1,1} → facet 'complete'.
		state.completeCardId = (await api.post('/cards', { stackId: stack.id, title: COMPLETE_CARD })).id
		const doneItem = await api.post(`/cards/${state.completeCardId}/checklist`, { title: SEED_DONE })
		await api.patch(`/checklist/${doneItem.id}`, { done: true })

		// Card B: two items, one done → {2,1} → facet 'incomplete'.
		state.incompleteCardId = (await api.post('/cards', { stackId: stack.id, title: INCOMPLETE_CARD })).id
		const bDone = await api.post(`/cards/${state.incompleteCardId}/checklist`, { title: SEED_DONE })
		await api.patch(`/checklist/${bDone.id}`, { done: true })
		await api.post(`/cards/${state.incompleteCardId}/checklist`, { title: SEED_OPEN })

		// A third card for the board-route priority shortcut. Assigned to the
		// current user so it is genuinely a My Tasks row.
		await api.post('/cards', { stackId: stack.id, title: PRIORITY_CARD })

		await api.put(`/cards/${state.completeCardId}/labels/${state.scopeLabelId}`)
		await api.put(`/cards/${state.incompleteCardId}/labels/${state.scopeLabelId}`)
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	/** Create a saved View scoped to this run's cards on one checklist facet. */
	async function createChecklistView(name, checklist) {
		const created = await api.put('/views', {
			name: `${name} ${ts}`,
			filter: { labels: [state.scopeLabelId], checklist },
			groupBy: 'board',
			display: 'list',
		})
		const view = created.views[created.views.length - 1]
		expect(view.id).toBeTruthy()
		return view.id
	}

	/** A refetch of the cross-board View feed. */
	function feedRefetch(page) {
		return page.waitForResponse(
			(r) => r.url().includes('/api/views/cards') && r.ok(),
			{ timeout: 15_000 },
		)
	}

	test('adding then completing a checklist item moves the card in and out of a filtered View', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })
		// 'complete' = every item done. Card A ({1,1}) matches; Card B ({2,1}) does not.
		const viewId = await createChecklistView('CLLive complete', 'complete')
		const NEW_ITEM = `cllive extra ${ts}`

		try {
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/views/${viewId}`)

			const completeRow = page.locator('.board-list-row__title', { hasText: COMPLETE_CARD })
			const incompleteRow = page.locator('.board-list-row__title', { hasText: INCOMPLETE_CARD })
			await expect(completeRow).toBeVisible({ timeout: 15_000 })
			// The facet really is filtering, not showing everything.
			await expect(incompleteRow).toHaveCount(0)

			// Marker that only survives if NO full page reload happens from here on.
			await page.evaluate(() => { window.__kansoNoReload = true })

			// Open the card as an in-place overlay ON the View (never navigates).
			await completeRow.click()
			await expect(page.locator('.card-modal-modal')).toBeVisible({ timeout: 15_000 })
			await expect(page).toHaveURL(new RegExp(`/views/${viewId}`))
			await expect(page.locator('.card-modal__checklist-count')).toHaveText('1 / 1', { timeout: 15_000 })

			// ── addItem ──────────────────────────────────────────────────────────
			// A new, undone item makes the summary {2,1}: no longer 'complete', so
			// the row behind the overlay must LEAVE the View on its own.
			let refetch = feedRefetch(page)
			await page.locator('.card-modal__checklist-add-input').fill(NEW_ITEM)
			await page.locator('.card-modal__checklist-add-input').press('Enter')
			await expect(page.locator('.card-modal__checklist-item').filter({ hasText: NEW_ITEM }))
				.toBeVisible({ timeout: 15_000 })
			await expect(page.locator('.card-modal__checklist-count')).toHaveText('1 / 2', { timeout: 15_000 })
			await refetch

			await expect(completeRow).toHaveCount(0, { timeout: 10_000 })

			// ── toggleItem ───────────────────────────────────────────────────────
			// Tick it: {2,2} is 'complete' again, so the row must come BACK.
			refetch = feedRefetch(page)
			const newItem = page.locator('.card-modal__checklist-item').filter({ hasText: NEW_ITEM })
			const checkbox = newItem.locator('.card-modal__checklist-checkbox')
			await expect(checkbox).toBeEnabled({ timeout: 15_000 })
			await checkbox.click()
			await expect(checkbox).toBeChecked({ timeout: 15_000 })
			await expect(page.locator('.card-modal__checklist-count')).toHaveText('2 / 2', { timeout: 15_000 })
			await refetch

			await expect(completeRow).toHaveCount(1, { timeout: 10_000 })

			// …and every bit of that happened in place, with the overlay still open.
			await expect(page.locator('.card-modal-modal')).toBeVisible()
			expect(await page.evaluate(() => window.__kansoNoReload)).toBe(true)
		} finally {
			await api.delete(`/views/${viewId}`).catch(() => {})
			// Put card A back to {1,1} so the tests stay order-independent.
			const items = await api.get(`/cards/${state.completeCardId}/checklist`).catch(() => [])
			for (const it of items) {
				if (it.title === NEW_ITEM) await api.delete(`/checklist/${it.id}`).catch(() => {})
			}
		}
	})

	test('deleting a checklist item moves the card out of a filtered View', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })
		// 'incomplete' = has items, not all done. Card B ({2,1}) matches; A ({1,1}) does not.
		const viewId = await createChecklistView('CLLive incomplete', 'incomplete')

		try {
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/views/${viewId}`)

			const incompleteRow = page.locator('.board-list-row__title', { hasText: INCOMPLETE_CARD })
			const completeRow = page.locator('.board-list-row__title', { hasText: COMPLETE_CARD })
			await expect(incompleteRow).toBeVisible({ timeout: 15_000 })
			await expect(completeRow).toHaveCount(0)

			await page.evaluate(() => { window.__kansoNoReload = true })

			await incompleteRow.click()
			await expect(page.locator('.card-modal-modal')).toBeVisible({ timeout: 15_000 })
			await expect(page.locator('.card-modal__checklist-count')).toHaveText('1 / 2', { timeout: 15_000 })

			// ── deleteItem ───────────────────────────────────────────────────────
			// Dropping the only OPEN item leaves {1,1}: 'complete', not
			// 'incomplete', so the row behind the overlay must leave the View.
			const refetch = feedRefetch(page)
			const openItem = page.locator('.card-modal__checklist-item').filter({ hasText: SEED_OPEN })
			await expect(openItem).toBeVisible({ timeout: 15_000 })
			await openItem.locator('.card-modal__checklist-item-delete').click()
			await expect(page.locator('.card-modal__checklist-count')).toHaveText('1 / 1', { timeout: 15_000 })
			await refetch

			await expect(incompleteRow).toHaveCount(0, { timeout: 10_000 })
			await expect(page.locator('.card-modal-modal')).toBeVisible()
			expect(await page.evaluate(() => window.__kansoNoReload)).toBe(true)
		} finally {
			await api.delete(`/views/${viewId}`).catch(() => {})
			// Restore card B to {2,1} for order-independence.
			const items = await api.get(`/cards/${state.incompleteCardId}/checklist`).catch(() => [])
			if (!items.some((i) => i.title === SEED_OPEN)) {
				await api.post(`/cards/${state.incompleteCardId}/checklist`, { title: SEED_OPEN }).catch(() => {})
			}
		}
	})

	test('the 0–4 priority shortcut refreshes the My Work feeds', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })
		await ncLogin(page)

		// Record every my-cards hit, registered BEFORE the board loads so the
		// app-boot fetch (App.vue mounts useMyWorkBadges for the app's lifetime)
		// is counted too. The assertion below is then against the count at
		// keypress time, not against "some request eventually happened".
		let myCardsHits = 0
		page.on('response', (r) => {
			if (r.url().includes('/api/my-cards')) myCardsHits++
		})

		await page.goto(boardUrl(state.boardId))

		await expect(page.locator('.card-tile__title', { hasText: PRIORITY_CARD }))
			.toBeVisible({ timeout: 20_000 })

		// useMyCards fetches once on app mount and then polls every 60s, so the
		// mount hit has already landed by the time the board paints and the next
		// scheduled one is ~a minute out: nothing but the shortcut can satisfy the
		// short window below.
		await expect.poll(() => myCardsHits, { timeout: 20_000 }).toBeGreaterThan(0)
		const hitsBeforeKeypress = myCardsHits

		// Focus the first card, then quick-set priority. The shortcut calls the
		// card API directly (deliberately not routed through usePriority, which
		// resolves its card id lazily and would roll back the wrong card if focus
		// moved mid-PATCH), so its .finally() is the only thing that can reach the
		// cross-board feeds.
		// ArrowDown with nothing focused seeds to the first card of the first stack.
		const firstTile = page.locator('.stack-column').nth(0).locator('.card-tile').first()
		await page.keyboard.press('ArrowDown')
		await expect(firstTile).toBeFocused({ timeout: 8000 })

		const patched = page.waitForResponse(
			(r) => /\/api\/cards\/\d+(?:\?|$)/.test(r.url())
				&& r.request().method() === 'PATCH'
				&& r.status() < 400,
			{ timeout: 15_000 },
		)
		await page.keyboard.press('3')
		await patched

		// The settle-phase invalidateCrossBoardFeeds is the only thing that can
		// have fired this. Without it the shortcut invalidates the board key alone
		// and the count stays put.
		await expect.poll(() => myCardsHits, { timeout: 15_000 })
			.toBeGreaterThan(hitsBeforeKeypress)
	})
})
