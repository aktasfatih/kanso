// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #9981 — a burst of edits in a card overlay opened FROM a View must not fire
// one cross-board feed read per edit.
//
// ~20 mutation settle sites call invalidateCrossBoardFeeds. Opening the card
// detail from a View leaves ViewPage mounted behind the overlay, so
// ['view-cards'] is an ACTIVE query — the only kind invalidateQueries actually
// refetches. Every settle therefore used to trigger a full read of the heaviest
// query in the app (enriched summaries across every readable board, up to the
// server cap), and TanStack did not absorb the duplicates: invalidateQueries
// defaults to cancelRefetch:true, but getViewCards is a plain axios GET with no
// AbortSignal, so a "cancelled" refetch's request still completes server-side.
//
// ── Why the checklist PATCH is stubbed ───────────────────────────────────────
// The quantity under test is purely client-side: how many /api/views/cards
// requests the settle path ISSUES for one burst. Left on the real endpoint, the
// burst is paced by the PATCH round trip — measured here at ~160ms/tick, which
// collapses 8 ticks into 4 reads locally but into ~8 on the ~5x slower CI
// runner, where nothing is a burst any more. That would make the assertion
// track the runner's disk speed rather than the guard. Fulfilling the PATCH in
// the browser removes that one variable and nothing else: the real checkbox,
// the real Vue handler, the real mutation, the real settle phase and the real
// /api/views/cards requests all still run. (The trade is that server state
// doesn't move, so this spec asserts request counts only — the state half is
// tests/e2e/view-checklist-live.spec.js's job.)
//
// The counting is done with page.route, not with timing: the route handler sees
// every request the page issues. The cadence unit tests are in
// tests/unit/queryKeys.test.mjs; this spec is what proves the guard survives the
// real bundle, the real overlay and the real settle path.
//
// tests/e2e/view-checklist-live.spec.js is the other half of the contract and
// must keep passing unchanged: the leading edge still repaints the View tile on
// the first edit.

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('View feed burst collapsing (#9981)', () => {
	const ts = Math.floor(Date.now() / 1000)
	const CARD = `Burst card ${ts}`
	const ITEMS = 8

	const state = { boardId: 0, scopeLabelId: 0, cardId: 0, viewId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: `Burst E2E ${ts}` })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })

		// A per-run scope label the View filters on, so the View resolves to
		// exactly this run's card whatever else lives in the dev DB.
		state.scopeLabelId = (await api.post('/labels', {
			boardId: board.id,
			title: `burst-scope ${ts}`,
			color: '00aa00',
		})).id

		state.cardId = (await api.post('/cards', { stackId: stack.id, title: CARD })).id
		await api.put(`/cards/${state.cardId}/labels/${state.scopeLabelId}`)
		for (let i = 1; i <= ITEMS; i++) {
			await api.post(`/cards/${state.cardId}/checklist`, { title: `burst step ${i}` })
		}

		// Filtered on the LABEL, not on the checklist facet, so a tick never moves
		// the row in or out of the View — the only thing that varies across the
		// burst is how often the feed is re-read.
		const created = await api.put('/views', {
			name: `Burst view ${ts}`,
			filter: { labels: [state.scopeLabelId] },
			groupBy: 'board',
			display: 'list',
		})
		state.viewId = created.views[created.views.length - 1].id
		expect(state.viewId).toBeTruthy()
	})

	test.afterAll(async () => {
		if (state.viewId) await api.delete(`/views/${state.viewId}`).catch(() => {})
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('a burst of checklist ticks in a View overlay collapses into a couple of cross-board reads', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })
		await ncLogin(page)

		// page.route sees the request as the page ISSUES it — a response listener
		// would miss any the browser never completes, and "issued" is the quantity
		// that regressed (a cancelled refetch still runs server-side).
		let feedRequests = 0
		let counting = false
		await page.route('**/api/views/cards*', async (route) => {
			if (counting) feedRequests++
			await route.continue()
		})

		// See the header: latency removal, not behaviour replacement. Nothing in
		// the toggle mutation reads this body.
		await page.route('**/api/checklist/*', async (route) => {
			if (route.request().method() !== 'PATCH') return route.continue()
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: '{}',
			})
		})

		await page.goto(`${BASE}/index.php/apps/kanso#/views/${state.viewId}`)

		const row = page.locator('.board-list-row__title', { hasText: CARD })
		await expect(row).toBeVisible({ timeout: 20_000 })

		// Open the card as an in-place overlay ON the View (never navigates), so
		// ViewPage stays mounted and ['view-cards'] stays ACTIVE.
		await page.evaluate(() => { window.__kansoNoReload = true })
		await row.click()
		await expect(page.locator('.card-modal-modal')).toBeVisible({ timeout: 15_000 })
		await expect(page.locator('.card-modal__checklist-count'))
			.toHaveText(`0 / ${ITEMS}`, { timeout: 15_000 })
		await expect(page.locator('.card-modal__checklist-checkbox')).toHaveCount(ITEMS)

		// Everything before this point (view load, overlay open) is setup traffic.
		counting = true

		// One synchronous task, so all ITEMS toggles start before Vue can re-render
		// the checkboxes into their in-flight disabled state — i.e. a genuine
		// burst, the shape ungated call sites (bulk apply, quick-set shortcuts)
		// produce on their own.
		const fired = await page.evaluate((expected) => {
			const boxes = [...document.querySelectorAll('.card-modal__checklist-checkbox')]
			boxes.forEach((box) => box.click())
			return boxes.length === expected ? boxes.length : -boxes.length
		}, ITEMS)
		expect(fired).toBe(ITEMS)

		// Long enough for every settle plus the trailing edge (400ms) to land.
		await page.waitForTimeout(3000)
		const observed = feedRequests

		// Leading edge + one trailing catch-up is the contract. The slots above
		// that are deliberate slack, not sloppiness: on a 2-worker, 4-CPU runner
		// the 8 stubbed settles need not all land inside one 400ms window, and the
		// counter matches on URL alone — the View feed's own 60s refetchInterval
		// ticking mid-burst would add a spurious read this route handler cannot
		// tell apart. Slack is the right answer here rather than `retries`, which
		// would mask a real regression in the suite's longest pole. The teeth are
		// unaffected: unguarded code issues ITEMS (8) of these.
		expect(observed,
			`${ITEMS} ticks issued ${observed} /api/views/cards reads — the burst was not collapsed`)
			.toBeLessThanOrEqual(4)
		expect(observed).toBeLessThan(ITEMS)

		// …and the leading edge still fired, so the View behind the overlay is not
		// simply going unrefreshed.
		expect(observed,
			'the burst produced no feed read at all — the leading edge is gone')
			.toBeGreaterThan(0)

		// All of it happened in place, with the overlay still open.
		await expect(page.locator('.card-modal-modal')).toBeVisible()
		expect(await page.evaluate(() => window.__kansoNoReload)).toBe(true)
	})
})
