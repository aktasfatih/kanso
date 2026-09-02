// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10010 — a cancelled View-feed refetch must be aborted in the browser, not
// read to the end.
//
// ['view-cards'] is the heaviest query in the app and stays ACTIVE while the
// card detail sits as an overlay on the View, so the ~44 invalidateCrossBoardFeeds
// call sites refetch it. invalidateQueries defaults to cancelRefetch:true, but
// TanStack can only act on that if the queryFn CONSUMES the AbortSignal it is
// handed — useViewCards used to drop it, so a "cancelled" refetch kept its
// connection open and normalised an envelope of up to `limit` enriched cards
// that the cache threw away on arrival.
//
// What this spec pins is exactly that, and only that: the superseded request
// ends client-ABORTED rather than completed. It is NOT a claim about the
// server — the response body is written in one go, so PHP finishes the request
// either way; the 400ms VIEW_FEED_INVALIDATE_THROTTLE (tests/e2e/view-feed-burst.spec.js)
// is what keeps the server load down and stays load-bearing.
//
// ── How the abort is observed ────────────────────────────────────────────────
// In-page transport instrumentation, not Playwright network events: the point
// is what the CLIENT did with its own request (XMLHttpRequest#abort / an aborted
// fetch signal), which is precisely the thing a request/response listener cannot
// distinguish from a slow response. The /api/views/cards route is stalled so the
// first refetch is guaranteed to still be in flight when the second one
// supersedes it — without that the race is decided by the dev box's disk.

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('View feed refetch cancellation (#10010)', () => {
	const ts = Math.floor(Date.now() / 1000)
	const CARD = `Abort card ${ts}`

	const state = { boardId: 0, cardId: 0, viewId: 0, scopeLabelId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: `Abort E2E ${ts}` })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })

		// A per-run scope label the View filters on, so the View resolves to this
		// run's card whatever else lives in the dev DB.
		state.scopeLabelId = (await api.post('/labels', {
			boardId: board.id,
			title: `abort-scope ${ts}`,
			color: '0066aa',
		})).id

		state.cardId = (await api.post('/cards', { stackId: stack.id, title: CARD })).id
		await api.put(`/cards/${state.cardId}/labels/${state.scopeLabelId}`)
		for (let i = 1; i <= 2; i++) {
			await api.post(`/cards/${state.cardId}/checklist`, { title: `abort step ${i}` })
		}

		// Filtered on the LABEL, so a tick never moves the row in or out of the
		// View — the only thing the ticks vary is how often the feed is re-read.
		const created = await api.put('/views', {
			name: `Abort view ${ts}`,
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

	test('a superseded view-cards refetch is aborted client-side instead of completing', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })

		// Records every /api/views/cards request the page issues and how it ended.
		// Both transports are patched: axios picks XHR in browsers today, but the
		// assertion must not silently go vacuous if it ever picks fetch.
		await page.addInitScript(() => {
			const FEED = '/api/views/cards'
			window.__kansoFeedXhr = []

			const origOpen = XMLHttpRequest.prototype.open
			const origSend = XMLHttpRequest.prototype.send
			const origAbort = XMLHttpRequest.prototype.abort
			XMLHttpRequest.prototype.open = function (method, requestUrl, ...rest) {
				this.__kansoUrl = String(requestUrl)
				return origOpen.call(this, method, requestUrl, ...rest)
			}
			XMLHttpRequest.prototype.send = function (...args) {
				if (typeof this.__kansoUrl === 'string' && this.__kansoUrl.includes(FEED)) {
					const entry = { transport: 'xhr', aborted: false, completed: false }
					this.__kansoEntry = entry
					window.__kansoFeedXhr.push(entry)
					this.addEventListener('load', () => { entry.completed = true })
				}
				return origSend.apply(this, args)
			}
			XMLHttpRequest.prototype.abort = function (...args) {
				if (this.__kansoEntry) this.__kansoEntry.aborted = true
				return origAbort.apply(this, args)
			}

			const origFetch = window.fetch
			window.fetch = function (input, init) {
				const requestUrl = typeof input === 'string' ? input : (input && input.url) || ''
				if (!requestUrl.includes(FEED)) return origFetch.call(this, input, init)
				const entry = { transport: 'fetch', aborted: false, completed: false }
				window.__kansoFeedXhr.push(entry)
				const signal = init && init.signal
				if (signal) signal.addEventListener('abort', () => { entry.aborted = true })
				return origFetch.call(this, input, init).then((response) => {
					entry.completed = true
					return response
				})
			}
		})

		await ncLogin(page)

		// Stalling the feed endpoint is what makes the overlap deterministic: the
		// first refetch is still open when the second supersedes it.
		let stallMs = 0
		await page.route('**/api/views/cards*', async (route) => {
			if (stallMs > 0) await new Promise((resolve) => setTimeout(resolve, stallMs))
			// A request the page aborted mid-flight can no longer be continued.
			await route.continue().catch(() => {})
		})

		await page.goto(`${BASE}/index.php/apps/kanso#/views/${state.viewId}`)

		const row = page.locator('.board-list-row__title', { hasText: CARD })
		await expect(row).toBeVisible({ timeout: 20_000 })

		// Open the card as an in-place overlay ON the View (never navigates), so
		// ViewPage stays mounted and ['view-cards'] stays ACTIVE.
		await page.evaluate(() => { window.__kansoNoReload = true })
		await row.click()
		await expect(page.locator('.card-modal-modal')).toBeVisible({ timeout: 15_000 })
		await expect(page.locator('.card-modal__checklist-checkbox')).toHaveCount(2)

		await page.evaluate(() => { window.__kansoFeedXhr.length = 0 })
		stallMs = 4000

		const boxes = page.locator('.card-modal__checklist-checkbox')
		// Tick one: leading-edge invalidate → a refetch that the route now stalls.
		await boxes.nth(0).click()
		await expect.poll(
			() => page.evaluate(() => window.__kansoFeedXhr.length),
			{ timeout: 15_000, message: 'the first tick issued no view-cards refetch at all' },
		).toBeGreaterThan(0)

		// Tick two, past the 400ms throttle window so it invalidates immediately:
		// TanStack supersedes the still-open refetch (cancelRefetch defaults true).
		await page.waitForTimeout(700)
		await boxes.nth(1).click()

		await expect.poll(
			async () => (await page.evaluate(() => window.__kansoFeedXhr)).filter((e) => e.aborted).length,
			{
				timeout: 20_000,
				message: 'the superseded view-cards refetch was never aborted — the query signal is not reaching axios',
			},
		).toBeGreaterThan(0)

		// …and the abort really replaced a completion rather than following one.
		const entries = await page.evaluate(() => window.__kansoFeedXhr)
		expect(entries.some((e) => e.aborted && !e.completed),
			`no aborted-and-unfinished view-cards request: ${JSON.stringify(entries)}`).toBe(true)
	})
})
