// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #9859 — editing a card from inside a View must reach the View's tiles.
//
// A View renders over one cross-board feed (['view-cards'], useViewCards.js).
// That feed is a cache island: board-scoped invalidation and the per-board
// delta log never touch it. And unlike My Tasks it cannot fall back on
// refetchOnWindowFocus, because the card detail opens as an in-place OVERLAY on
// the View (ViewPage.vue) — editing never blurs the window and never unmounts
// the page. Before the fix the only refresh path left was the 60s interval, so
// a change made in the overlay sat visibly wrong in the View behind it.
//
// The fix is a settle-phase invalidateCrossBoardFeeds on card mutations. These
// tests drive the exact repro through the UI and assert NO full page reload
// happens (a window marker survives), so nothing but the invalidation can be
// doing the work:
//   1. group-by TYPE: change type in the overlay → the row moves to the other
//      group behind it (the headline acceptance).
//   2. toggle a LABEL in the overlay → the chip appears on the tile. Labels are
//      a View filter dimension and a rendered chip, and useLabels' settle path
//      previously invalidated only the card + board keys.
//
// The complementary regression — that a notify_push event does NOT drag this
// feed onto the 30s realtime cadence — is asserted in tests/unit/queryKeys.test.mjs.
// It cannot be asserted here: push is disabled in CI (KANSO_SKIP_NOTIFY_PUSH=1)
// and absent from the dev stack, so a browser assertion would pass vacuously.

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('View live edit (#9859)', () => {
	const ts = Math.floor(Date.now() / 1000)
	const BUG_CARD = `ViewLive Bug ${ts}`
	const FEATURE_CARD = `ViewLive Feature ${ts}`
	const state = {
		boardId: 0,
		scopeLabelId: 0,
		extraLabelId: 0,
		bugCardId: 0,
		featureCardId: 0,
	}

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: `ViewLive E2E ${ts}` })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })

		// A per-run scope label the saved Views filter on, so each View resolves to
		// EXACTLY these two cards no matter what else lives in the dev DB (small,
		// deterministic list — no virtualization off-screen flake).
		state.scopeLabelId = (await api.post('/labels', {
			boardId: board.id,
			title: `vlive-scope ${ts}`,
			color: 'ff0000',
		})).id
		// A second label, unassigned at seed time — test 2 toggles it on.
		state.extraLabelId = (await api.post('/labels', {
			boardId: board.id,
			title: `vlive-extra ${ts}`,
			color: '00ff00',
		})).id

		state.bugCardId = (await api.post('/cards', { stackId: stack.id, title: BUG_CARD })).id
		state.featureCardId = (await api.post('/cards', { stackId: stack.id, title: FEATURE_CARD })).id
		await api.put(`/cards/${state.bugCardId}/labels/${state.scopeLabelId}`)
		await api.put(`/cards/${state.featureCardId}/labels/${state.scopeLabelId}`)
		// Seed the two type groups the first test watches a card move between.
		await api.patch(`/cards/${state.bugCardId}`, { type: 'bug' })
		await api.patch(`/cards/${state.featureCardId}`, { type: 'feature' })
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	/** Create a saved View scoped to this run's two cards. Returns its id. */
	async function createView(name, { groupBy, display }) {
		const created = await api.put('/views', {
			name: `${name} ${ts}`,
			filter: { labels: [state.scopeLabelId] },
			groupBy,
			display,
		})
		const view = created.views[created.views.length - 1]
		expect(view.id).toBeTruthy()
		return view.id
	}

	test('changing a group-by field in the overlay moves the row in the View behind it', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })
		const viewId = await createView('ViewLive type', { groupBy: 'type', display: 'list' })

		try {
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/views/${viewId}`)

			// Baseline: one card in each type group.
			const bugRow = page.locator('.board-list-row__title', { hasText: BUG_CARD })
			const featureRow = page.locator('.board-list-row__title', { hasText: FEATURE_CARD })
			await expect(bugRow).toBeVisible({ timeout: 15_000 })
			await expect(featureRow).toBeVisible({ timeout: 15_000 })
			const bugGroup = page.locator('.board-list-group__title', { hasText: /^Bug$/ })
			const featureGroup = page.locator('.board-list-group__title', { hasText: /^Feature$/ })
			await expect(bugGroup).toHaveCount(1)
			await expect(featureGroup).toHaveCount(1)

			// Marker that only survives if NO full page reload happens from here on.
			// The whole point: the View must update in place.
			await page.evaluate(() => { window.__kansoNoReload = true })

			// Open the card as an in-place overlay ON the View (never navigates).
			await bugRow.click()
			await expect(page.locator('.card-modal-modal')).toBeVisible({ timeout: 15_000 })
			await expect(page).toHaveURL(new RegExp(`/views/${viewId}`))

			// Re-type it Bug → Feature. The settle-phase invalidation must refetch
			// the cross-board feed on its own — no navigation, no focus change.
			// The type pill is addressed by its data-pill hook, not by text: it renders
			// the card's CURRENT type ("Bug" here), and only reads "Type" while unset.
			const typePill = page.locator('.card-modal__attrbar button[data-pill="type"]')
			await expect(typePill).toHaveClass(/card-modal__pill--type-bug/)

			const feedRefetch = page.waitForResponse(
				(r) => r.url().includes('/api/views/cards') && r.ok(),
				{ timeout: 15_000 },
			)
			await typePill.click()
			await page.locator('.card-modal__popover .card-modal__popover-opt', { hasText: 'Feature' }).click()
			await expect(typePill).toHaveClass(/card-modal__pill--type-feature/, { timeout: 8000 })
			await feedRefetch

			// Close the overlay — we stay in the View.
			await page.keyboard.press('Escape')
			await expect(page.locator('.card-modal-modal')).toHaveCount(0, { timeout: 10_000 })
			await expect(page).toHaveURL(new RegExp(`/views/${viewId}`))

			// The row MOVED: no card carries type Bug any more, so that group is gone
			// entirely and both cards now sit under Feature.
			await expect(bugGroup).toHaveCount(0, { timeout: 10_000 })
			await expect(featureGroup).toHaveCount(1)
			await expect(page.locator('.board-list-group__count')).toHaveText('2', { timeout: 10_000 })
			await expect(bugRow).toBeVisible()
			await expect(featureRow).toBeVisible()

			// …and it happened without a reload.
			expect(await page.evaluate(() => window.__kansoNoReload)).toBe(true)
		} finally {
			await api.delete(`/views/${viewId}`).catch(() => {})
			// Put the card back so this spec's tests stay order-independent.
			await api.patch(`/cards/${state.bugCardId}`, { type: 'bug' }).catch(() => {})
		}
	})

	test('toggling a label in the overlay reaches the tile in the View', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })
		// Kanban renders labels as text-bearing chips, so the assertion names the
		// label rather than counting anonymous dots.
		const viewId = await createView('ViewLive label', { groupBy: 'board', display: 'kanban' })

		try {
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/views/${viewId}`)

			const tile = page.locator('.card-tile__title', { hasText: FEATURE_CARD })
			await expect(tile).toBeVisible({ timeout: 15_000 })

			// The extra label is not on the card yet, so no chip for it anywhere.
			const extraChip = page.locator('.view-kanban-col .card-tile__label-chip', {
				hasText: `vlive-extra ${ts}`,
			})
			await expect(extraChip).toHaveCount(0)

			await page.evaluate(() => { window.__kansoNoReload = true })

			await tile.click()
			await expect(page.locator('.card-modal-modal')).toBeVisible({ timeout: 15_000 })

			// Toggle the extra label on from the overlay's label popover. useLabels'
			// settle path previously stopped at the card + board keys, so the feed
			// behind the overlay never heard about it.
			const feedRefetch = page.waitForResponse(
				(r) => r.url().includes('/api/views/cards') && r.ok(),
				{ timeout: 15_000 },
			)
			await page.locator('.card-modal__attr button', { hasText: 'Label' }).first().click()
			await page.locator('.card-modal__label-toggle', { hasText: `vlive-extra ${ts}` }).click()
			await expect(page.locator('.card-modal__label-chip', { hasText: `vlive-extra ${ts}` }))
				.toBeVisible({ timeout: 8000 })
			await feedRefetch

			await page.keyboard.press('Escape') // close the label popover
			await page.keyboard.press('Escape') // close the card overlay
			await expect(page.locator('.card-modal-modal')).toHaveCount(0, { timeout: 10_000 })

			// The chip is now on the tile in the View — no reload.
			await expect(extraChip).toHaveCount(1, { timeout: 10_000 })
			expect(await page.evaluate(() => window.__kansoNoReload)).toBe(true)
		} finally {
			await api.delete(`/views/${viewId}`).catch(() => {})
			await api.delete(`/cards/${state.featureCardId}/labels/${state.extraLabelId}`).catch(() => {})
		}
	})
})
