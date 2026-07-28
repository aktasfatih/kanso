// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// @perf - excluded from the default `npm run test:e2e` run.
// Run explicitly: npx playwright test --grep @perf
//
// This spec requires the 'Perf Test Board' seeded by scripts/seed-board.mjs
// (3 stacks × 667 cards = 2 001 total).

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = {
	'OCS-APIREQUEST': 'true',
	'Content-Type': 'application/json',
}
const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')

async function apiGet(path) {
	const r = await fetch(API + path, { headers: { ...HEADERS, Authorization: AUTH } })
	if (!r.ok) throw new Error(`GET ${path} → ${r.status}`)
	return r.json()
}

async function ncLogin(page) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})

	const userInput = page.locator('#user')
	const isLoginPage = await userInput.isVisible({ timeout: 3000 }).catch(() => false)
	if (!isLoginPage) return

	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

test.describe('@perf 2000-card board performance', () => {
	let boardUrl = ''

	test.beforeAll(async () => {
		const boards = await apiGet('/boards')
		const perfBoard = boards.find((b) => b.title === 'Perf Test Board')
		if (!perfBoard) {
			throw new Error('Perf Test Board not found. Run: node scripts/seed-board.mjs')
		}
		boardUrl = `${BASE}/index.php/apps/kanso#/board/${perfBoard.id}`
		console.log('Perf Test Board URL:', boardUrl)
	})

	test('@perf initial render time and frame rate during scroll', async ({ page }) => {
		await ncLogin(page)

		// ── Measure initial render time ────────────────────────────────────────
		// Use addInitScript to stamp performance.now() on every new page load.
		// This fires synchronously before any page scripts, so it captures the
		// very start of the document lifecycle for the board URL navigation.
		await page.addInitScript(() => {
			window.__perfStart = performance.now()
		})

		const navStart = Date.now()
		await page.goto(boardUrl)

		// Wait for the first tile to appear in the DOM
		await page.waitForSelector('.card-tile-wrap', { timeout: 30_000 })

		// Two measurements: in-page timing (performance.now delta from init script)
		// and wall-clock navigation-to-tile time.
		const initialRenderMs = await page.evaluate(() => {
			return performance.now() - (window.__perfStart ?? 0)
		})
		const wallClockMs = Date.now() - navStart
		console.log(`[PERF] Initial render (first tile visible): ${initialRenderMs.toFixed(1)} ms (wall: ${wallClockMs} ms)`)


		// ── Measure board payload size ─────────────────────────────────────────
		const payloadKB = await page.evaluate(() => {
			const entries = performance.getEntriesByType('resource')
			// Find the kanso board API call (board endpoint returns stacks+cards)
			const boardEntry = entries.find(
				(e) => e.name.includes('/apps/kanso/api/boards/') && !e.name.includes('/cards') && !e.name.includes('/stacks'),
			)
			if (boardEntry && boardEntry.transferSize) {
				return (boardEntry.transferSize / 1024).toFixed(1)
			}
			// Fallback: sum all kanso API calls
			const total = entries
				.filter((e) => e.name.includes('/apps/kanso/'))
				.reduce((sum, e) => sum + (e.transferSize || 0), 0)
			return (total / 1024).toFixed(1)
		})
		console.log(`[PERF] Board payload size: ${payloadKB} KB`)

		// ── Measure rAF frame rate during a 3-second programmatic scroll ───────
		const column = page.locator('.stack-column').first()
		const cardList = column.locator('.stack-column__cards')
		const cardListBox = await cardList.boundingBox()
		if (!cardListBox) throw new Error('Could not find card list element')

		// Hover over the card list so scroll events land on it
		await page.mouse.move(
			cardListBox.x + cardListBox.width / 2,
			cardListBox.y + cardListBox.height / 2,
		)

		// Inject rAF counter before scrolling
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

		// Programmatically scroll the card list element over ~3 seconds via
		// repeated mouse wheel events so the virtualizer recalculates visible items.
		const SCROLL_DURATION_MS = 3000
		const SCROLL_STEP = 200 // px per tick
		const TICK_INTERVAL = 100 // ms between ticks
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

		// Verify some tiles are still in DOM (virtualizer working during scroll)
		const domTilesAfterScroll = await page.locator('.card-tile-wrap').count()
		console.log(`[PERF] DOM tile count after scroll: ${domTilesAfterScroll}`)

		// Sanity assertions - not strict perf budgets, just ensure things work
		expect(initialRenderMs).toBeLessThan(10_000) // under 10s
		expect(domTilesAfterScroll).toBeGreaterThan(0)
		expect(Number(avgFps)).toBeGreaterThan(10) // at least 10 fps (headless baseline)

		// Summary line for easy grepping
		console.log(
			`[PERF SUMMARY] render=${initialRenderMs.toFixed(0)}ms | fps=${avgFps} | payload=${payloadKB}KB | dom_tiles=${domTilesAfterScroll}`,
		)
	})
})
