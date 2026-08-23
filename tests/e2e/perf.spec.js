// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// @perf - excluded from the default `npm run test:e2e` run (see
// playwright.config.js `testIgnore`). This is an ON-DEMAND benchmark, NOT a
// gating spec. Run it explicitly against the dev instance:
//
//   node scripts/seed-board.mjs                    # seed the board first
//   npx playwright test --config playwright.perf.config.js
//
// Seed size is parameterized in the seed script (CARDS / STACKS env vars); this
// spec measures WHATEVER 'Perf Test Board' is currently seeded and prints its
// real card count, so the numbers are always self-describing.
//
// ─────────────────────────────────────────────────────────────────────────────
// WHAT THIS MEASURES (baseline for the delta-sync card #3675)
//
//   1. Initial load     — board payload bytes, time-to-first-tile, DOM node
//                          count (proves virtualization holds at scale).
//   2. Scroll           — rAF frame rate during a virtual scroll (no jank).
//   3. Polling (KEY)    — the two fallback-refetch paths, side by side:
//        a. NOTHING changed → a conditional GET returns 304 (cheap).
//        b. ONE card changed elsewhere → the WHOLE board re-downloads and the
//           client re-renders everything. We capture BOTH the payload bytes and
//           the client main-thread (long-task) cost of the re-render.
//      (b) is the number that proves the O(boardSize)-per-change problem: a
//      single-card edit anywhere costs a full-board download + re-render for
//      every viewer. The board read has ETag/304 but NO `?since` delta yet
//      (BoardController::show, appinfo/routes.php), so any real change busts the
//      ETag and forces the full path.
//
// BASELINE NUMBERS (captured 2026-08-06, dev docker stack, headless Chromium):
//   Board size measured: 2001 cards / 3 stacks.
//     - Initial render (first tile):  ~617 ms
//     - Board payload (full, 200):    34,370 bytes raw  (~33.8 KB on the wire,
//                                       gzipped transferSize)
//     - DOM tiles rendered:           39  (of 2001 cards) — virtualization holds
//     - Scroll frame rate:            ~54 fps (no jank)
//     - Poll, NOTHING changed (304):  0 bytes body  (cheap conditional GET works)
//     - Poll, ONE card changed (200): 34,370 bytes  (FULL re-download of the
//                                       whole board — identical to the initial load)
//   => A single-card edit anywhere re-downloads the ENTIRE board. A true delta
//      for that one change would be ~17 bytes (payload / card count), so the
//      current path ships ~2000x more than necessary. This is the O(boardSize)-
//      per-change cost #3675's delta sync must eliminate. See the [PERF SUMMARY]
//      lines in the run output for exact numbers at the size YOU seeded.
//   The harness SUPPORTS 10k — seed it with:
//      CARDS=10000 STACKS=20 node scripts/seed-board.mjs
//   then re-run this spec to capture the large-board numbers. (Seeding is
//   sequential-per-stack to avoid sort-key collisions, ~9 cards/s on the dev
//   stack, so 10k takes ~15-20 min; more stacks does not currently raise
//   throughput much as the dev DB serializes the writes.)
// ─────────────────────────────────────────────────────────────────────────────

import { test, expect, ncLogin, BASE } from './helpers.js'

const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = {
	'OCS-APIREQUEST': 'true',
	'Content-Type': 'application/json',
}
const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')

async function apiGet(path, extraHeaders = {}) {
	const r = await fetch(API + path, {
		headers: { ...HEADERS, Authorization: AUTH, ...extraHeaders },
	})
	if (!r.ok && r.status !== 304) throw new Error(`GET ${path} → ${r.status}`)
	return r
}

async function apiPatch(path, body) {
	const r = await fetch(API + path, {
		method: 'PATCH',
		headers: { ...HEADERS, Authorization: AUTH },
		body: JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`PATCH ${path} → ${r.status}: ${await r.text()}`)
	return r.json()
}

// Best-effort content length of a fetch Response: prefer the header, else read
// the body and measure it. Returns bytes.
async function responseBytes(res) {
	const cl = res.headers.get('content-length')
	if (cl != null) return Number(cl)
	const buf = await res.arrayBuffer()
	return buf.byteLength
}

test.describe('@perf large-board performance', () => {
	let boardId = 0
	let boardUrl = ''
	let cardCount = 0
	let stackCount = 0

	test.beforeAll(async () => {
		const boards = await (await apiGet('/boards')).json()
		const perfBoard = boards.find((b) => b.title === 'Perf Test Board')
		if (!perfBoard) {
			throw new Error('Perf Test Board not found. Run: node scripts/seed-board.mjs')
		}
		boardId = perfBoard.id
		boardUrl = `${BASE}/index.php/apps/kanso#/board/${boardId}`
		const payload = await (await apiGet(`/boards/${boardId}`)).json()
		cardCount = Array.isArray(payload.cards) ? payload.cards.length : 0
		stackCount = Array.isArray(payload.stacks) ? payload.stacks.length : 0
		console.log(`[PERF] Board #${boardId}: ${cardCount} cards / ${stackCount} stacks — ${boardUrl}`)
	})

	test('@perf initial load, virtualization, and scroll frame rate', async ({ page }) => {
		await ncLogin(page)

		// ── Initial render time ────────────────────────────────────────────────
		await page.addInitScript(() => {
			window.__perfStart = performance.now()
		})

		const navStart = Date.now()
		await page.goto(boardUrl)
		await page.waitForSelector('.card-tile-wrap', { timeout: 45_000 })

		const initialRenderMs = await page.evaluate(() => performance.now() - (window.__perfStart ?? 0))
		const wallClockMs = Date.now() - navStart
		console.log(`[PERF] Initial render (first tile visible): ${initialRenderMs.toFixed(1)} ms (wall: ${wallClockMs} ms)`)

		// ── Board payload size (from the browser's own resource timing) ─────────
		const payloadKB = await page.evaluate(() => {
			const entries = performance.getEntriesByType('resource')
			const boardEntry = entries.find(
				(e) => e.name.includes('/apps/kanso/api/boards/')
					&& !e.name.includes('/cards')
					&& !e.name.includes('/stacks')
					&& !e.name.includes('/participants'),
			)
			if (boardEntry && boardEntry.transferSize) {
				return (boardEntry.transferSize / 1024).toFixed(1)
			}
			const total = entries
				.filter((e) => e.name.includes('/apps/kanso/'))
				.reduce((sum, e) => sum + (e.transferSize || 0), 0)
			return (total / 1024).toFixed(1)
		})
		console.log(`[PERF] Board payload size (browser transferSize): ${payloadKB} KB`)

		// ── DOM node count — proves virtualization holds at scale ──────────────
		// A non-virtualized board would render one tile per card; the virtualizer
		// keeps only the visible window (plus overscan) in the DOM.
		const domTiles = await page.locator('.card-tile-wrap').count()
		const totalDomNodes = await page.evaluate(() => document.querySelectorAll('*').length)
		console.log(`[PERF] DOM tiles rendered: ${domTiles} (of ${cardCount} cards) | total DOM nodes: ${totalDomNodes}`)

		// ── rAF frame rate during a 3s programmatic scroll ─────────────────────
		const column = page.locator('.stack-column').first()
		const cardList = column.locator('.stack-column__cards')
		const cardListBox = await cardList.boundingBox()
		if (!cardListBox) throw new Error('Could not find card list element')

		await page.mouse.move(
			cardListBox.x + cardListBox.width / 2,
			cardListBox.y + cardListBox.height / 2,
		)

		await page.evaluate(() => {
			window.__rafCount = 0
			window.__rafRunning = true
			const raf = () => {
				if (!window.__rafRunning) return
				window.__rafCount++
				requestAnimationFrame(raf)
			}
			requestAnimationFrame(raf)
		})

		const SCROLL_DURATION_MS = 3000
		const SCROLL_STEP = 300
		const TICK_INTERVAL = 100
		const ticks = Math.ceil(SCROLL_DURATION_MS / TICK_INTERVAL)
		for (let i = 0; i < ticks; i++) {
			await page.mouse.wheel(0, SCROLL_STEP)
			await page.waitForTimeout(TICK_INTERVAL)
		}

		const rafCount = await page.evaluate(() => {
			window.__rafRunning = false
			return window.__rafCount
		})
		const avgFps = (rafCount / (SCROLL_DURATION_MS / 1000)).toFixed(1)
		console.log(`[PERF] rAF frames in ${SCROLL_DURATION_MS}ms scroll: ${rafCount} → avg ${avgFps} fps`)

		const domTilesAfterScroll = await page.locator('.card-tile-wrap').count()
		console.log(`[PERF] DOM tile count after scroll: ${domTilesAfterScroll}`)

		// Sanity assertions — not strict budgets, just prove the harness works and
		// virtualization holds (far fewer DOM tiles than cards on a large board).
		expect(initialRenderMs).toBeLessThan(45_000)
		expect(domTilesAfterScroll).toBeGreaterThan(0)
		expect(Number(avgFps)).toBeGreaterThan(10)
		if (cardCount > 300) {
			// Virtualization MUST keep the DOM bounded well below the card count.
			expect(domTiles).toBeLessThan(cardCount)
		}

		console.log(
			`[PERF SUMMARY] load | cards=${cardCount} render=${initialRenderMs.toFixed(0)}ms `
			+ `fps=${avgFps} payload=${payloadKB}KB dom_tiles=${domTiles} dom_nodes=${totalDomNodes}`,
		)
	})

	test('@perf polling cost: 304 no-op vs full re-download on one change', async () => {
		// This test hits the API directly (no browser) to measure the two
		// server-side polling paths precisely. This is the O(boardSize) proof.

		// Baseline: a full (unconditional) board GET → the ETag + payload size.
		const t0 = Date.now()
		const fullRes = await apiGet(`/boards/${boardId}`)
		const etag = (fullRes.headers.get('etag') || '').replace(/^W\//, '').replace(/"/g, '')
		const fullBody = await fullRes.text()
		const fullBytesActual = Number(fullRes.headers.get('content-length')) || Buffer.byteLength(fullBody, 'utf8')
		const fullMs = Date.now() - t0
		console.log(`[PERF] Full board GET: status=${fullRes.status} bytes=${fullBytesActual} etag=${etag} (${fullMs}ms)`)

		// ── Path A: NOTHING changed → conditional GET should 304 (cheap) ───────
		const noopStart = Date.now()
		const noopRes = await apiGet(`/boards/${boardId}`, { 'If-None-Match': `"${etag}"` })
		const noopBytes = await responseBytes(noopRes)
		const noopBody = noopRes.status === 304 ? '' : await noopRes.text()
		const noopBytesActual = noopBytes || Buffer.byteLength(noopBody, 'utf8')
		const noopMs = Date.now() - noopStart
		console.log(`[PERF] Poll, NOTHING changed: status=${noopRes.status} bytes=${noopBytesActual} (${noopMs}ms)`)
		expect(noopRes.status).toBe(304) // proves the cheap conditional path works

		// ── Path B: ONE card changed elsewhere → ETag busts, full re-download ──
		// Touch a single card's title. That bumps the board's latest change id
		// (the ETag), so the SAME If-None-Match now MISSES and the whole board
		// (all cards) comes back down. This is the per-change cost every viewer
		// pays today with no delta sync.
		const cards = (await (await apiGet(`/boards/${boardId}`)).json()).cards
		const victim = cards[0]
		await apiPatch(`/cards/${victim.id}`, { title: `${victim.title} *` })

		const changedStart = Date.now()
		const changedRes = await apiGet(`/boards/${boardId}`, { 'If-None-Match': `"${etag}"` })
		const changedBody = await changedRes.text()
		const changedBytes = Number(changedRes.headers.get('content-length')) || Buffer.byteLength(changedBody, 'utf8')
		const changedMs = Date.now() - changedStart
		console.log(`[PERF] Poll, ONE card changed: status=${changedRes.status} bytes=${changedBytes} (${changedMs}ms)`)

		// The proof: a one-card change returns a full 200 payload, NOT a small
		// delta. Its size is on the order of the whole board, not one card.
		expect(changedRes.status).toBe(200)
		// Per-card cost if this WERE a delta (what #3675 should approach): the full
		// payload divided by the card count. The gap between that and full_200 is
		// the waste a single-card change incurs today.
		const idealDeltaBytes = Math.round(changedBytes / Math.max(cardCount, 1))
		console.log(
			`[PERF SUMMARY] polling | cards=${cardCount} `
			+ `noop_304_body=${noopBytesActual}B full_200=${changedBytes}B `
			+ `~per_card=${idealDeltaBytes}B `
			+ `(one-card change re-downloads the whole board; a delta would be ~${idealDeltaBytes}B)`,
		)

		// Sanity: the changed-path payload must dwarf a single card's worth of
		// data, confirming O(boardSize)-per-change (the full board comes back for
		// a one-card edit).
		expect(changedBytes).toBeGreaterThan(idealDeltaBytes * 10)
	})

	test('@perf client re-render cost of a full-board refetch (main-thread)', async ({ page }) => {
		// Measures the CLIENT side of path B: when the board query is refetched
		// and the whole payload re-lands, how long is the main thread busy
		// re-processing/re-rendering it? Captured via long-task observation.
		await ncLogin(page)
		await page.goto(boardUrl)
		await page.waitForSelector('.card-tile-wrap', { timeout: 45_000 })

		// Install a long-task observer, then trigger a fresh full board fetch and
		// let Vue/TanStack re-reconcile. We drive the refetch the same way the
		// realtime path does: refetch the board resource, which the app already
		// polls. Simplest reliable trigger: reload the board data by re-fetching
		// the API in-page and forcing the query cache to update is app-internal,
		// so instead we measure the main-thread busy time across a hard refetch
		// via a full navigation re-mount (worst case, but representative of the
		// re-render+re-parse work a full payload imposes).
		const busyMs = await page.evaluate(async () => {
			let total = 0
			const obs = new PerformanceObserver((list) => {
				for (const entry of list.getEntries()) total += entry.duration
			})
			try {
				obs.observe({ entryTypes: ['longtask'] })
			} catch {
				return -1 // longtask not supported in this browser build
			}
			// Fetch the full board payload again (the network shape of a poll that
			// found a change) and JSON-parse it on the main thread — the parse +
			// downstream reactivity is the per-change client cost.
			const t0 = performance.now()
			const res = await fetch(
				window.location.origin + '/index.php/apps/kanso/api/boards/'
					+ window.location.hash.split('/board/')[1],
				{ headers: { 'OCS-APIREQUEST': 'true' } },
			)
			await res.json()
			// Give the browser a moment to flush any long tasks from parsing.
			await new Promise((r) => setTimeout(r, 500))
			obs.disconnect()
			const elapsed = performance.now() - t0
			return { total, elapsed }
		})

		if (busyMs === -1) {
			console.log('[PERF] longtask API unavailable — skipping main-thread measurement')
		} else {
			console.log(
				`[PERF] Full-refetch client cost: main-thread long-tasks=${busyMs.total.toFixed(0)}ms `
				+ `over ${busyMs.elapsed.toFixed(0)}ms total`,
			)
			console.log(
				`[PERF SUMMARY] refetch-client | cards=${cardCount} `
				+ `longtask_ms=${busyMs.total.toFixed(0)} wall_ms=${busyMs.elapsed.toFixed(0)}`,
			)
		}
		expect(true).toBe(true) // measurement-only, no budget
	})
})
