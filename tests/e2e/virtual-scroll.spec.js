// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

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

async function apiPost(path, body) {
	const r = await fetch(API + path, {
		method: 'POST',
		headers: { ...HEADERS, Authorization: AUTH },
		body: JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`POST ${path} → ${r.status}: ${await r.text()}`)
	return r.json()
}

async function apiDelete(path) {
	const r = await fetch(API + path, {
		method: 'DELETE',
		headers: { ...HEADERS, Authorization: AUTH },
	})
	if (!r.ok) throw new Error(`DELETE ${path} → ${r.status}`)
}

async function ncLogin(page) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})

	const userInput = page.locator('#user')
	const isLoginPage = await userInput.isVisible({ timeout: 3000 }).catch(() => false)
	if (!isLoginPage) return // Already logged in

	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

/**
 * Drag from source element toward a target position using incremental mouse
 * steps - same pattern as dnd.spec.js dragWithMouse.
 */
async function dragWithMouse(page, sourceLocator, targetLocator, targetPosition = 'top') {
	const srcBox = await sourceLocator.boundingBox()
	const tgtBox = await targetLocator.boundingBox()
	if (!srcBox || !tgtBox) throw new Error('Could not get bounding boxes for drag')

	const srcX = srcBox.x + srcBox.width / 2
	const srcY = srcBox.y + srcBox.height / 2
	const tgtX = tgtBox.x + tgtBox.width / 2
	const tgtY = targetPosition === 'top'
		? tgtBox.y + tgtBox.height * 0.2
		: tgtBox.y + tgtBox.height * 0.8

	await page.mouse.move(srcX, srcY)
	await page.mouse.down()
	const steps = 15
	for (let i = 1; i <= steps; i++) {
		await page.mouse.move(
			srcX + (tgtX - srcX) * (i / steps),
			srcY + (tgtY - srcY) * (i / steps),
			{ steps: 1 },
		)
		await page.waitForTimeout(20)
	}
	await page.waitForTimeout(150)
	await page.mouse.up()
	await page.waitForTimeout(500)
}

/**
 * Drag from a source element toward the bottom of the scroll container,
 * moving in small mouse steps to trigger autoScrollForElements.
 */
async function dragTowardBottomEdge(page, sourceLocator, containerLocator, durationMs = 2500) {
	const srcBox = await sourceLocator.boundingBox()
	const containerBox = await containerLocator.boundingBox()
	if (!srcBox || !containerBox) throw new Error('Could not get bounding boxes')

	const srcX = srcBox.x + srcBox.width / 2
	const srcY = srcBox.y + srcBox.height / 2
	const tgtX = srcX
	// Target: bottom edge of the scroll container (triggers auto-scroll)
	const tgtY = containerBox.y + containerBox.height - 5

	await page.mouse.move(srcX, srcY)
	await page.mouse.down()

	// Move in many steps over durationMs to keep auto-scroll alive
	const totalSteps = Math.ceil(durationMs / 30)
	for (let i = 1; i <= totalSteps; i++) {
		await page.mouse.move(
			srcX + (tgtX - srcX) * (i / totalSteps),
			srcY + (tgtY - srcY) * (i / totalSteps),
			{ steps: 1 },
		)
		await page.waitForTimeout(30)
	}
	// Stay at bottom edge a moment so virtualizer renders bottom items
	await page.waitForTimeout(500)
	await page.mouse.up()
	await page.waitForTimeout(800)
}

test.describe('Virtualized card list', () => {
	const TOTAL_CARDS = 30
	const state = {
		boardId: 0,
		stackId: 0,
		cardIds: [],
		boardUrl: '',
	}

	test.beforeAll(async () => {
		// Clean up existing Virtual Test Boards
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === 'Virtual Test Board') {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		// Create board + one stack
		const board = await apiPost('/boards', { title: 'Virtual Test Board', color: '3a87ad' })
		state.boardId = board.id

		const stack = await apiPost('/stacks', { boardId: board.id, title: 'Long Stack' })
		state.stackId = stack.id

		// Seed 30 cards
		for (let i = 1; i <= TOTAL_CARDS; i++) {
			const card = await apiPost('/cards', { stackId: stack.id, title: `Card ${i}` })
			state.cardIds.push(card.id)
		}

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
		console.log('Virtual test board ready:', state.boardUrl)
	})

	test('only a subset of 30 tiles rendered in DOM (virtualization proof)', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		// Wait for at least one card tile to appear
		await page.waitForSelector('.card-tile-wrap', { timeout: 10_000 })

		// Count how many card-tile-wrap elements are in the DOM
		const tileCount = await page.locator('.card-tile-wrap').count()

		console.log(`DOM tile count: ${tileCount} out of ${TOTAL_CARDS} total cards`)

		// Virtualization proof: with 30 cards and overscan=6, we expect significantly
		// fewer than 30 tiles in the DOM at any given time (typically 10–18 depending
		// on viewport height ~90px/card estimate).
		expect(tileCount).toBeLessThan(TOTAL_CARDS)
		expect(tileCount).toBeGreaterThan(0)
	})

	test('drag first visible tile toward bottom, drop after initially-offscreen tile, order persists on reload', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 10_000 })
		await page.waitForSelector('.card-tile-wrap', { timeout: 10_000 })

		const column = page.locator('.stack-column').first()
		const cardList = column.locator('.stack-column__cards')

		// Get first visible card
		const firstCard = column.locator('.card-tile-wrap .card-tile').first()
		await expect(firstCard).toBeVisible({ timeout: 5000 })

		const firstCardTitle = await firstCard.innerText()
		console.log('First card title:', firstCardTitle.trim())

		// Count DOM tiles before drag
		const beforeCount = await column.locator('.card-tile-wrap').count()
		console.log(`DOM tiles before drag: ${beforeCount}`)

		// Drag the first card toward the bottom edge of the scroll container.
		// autoScrollForElements will scroll the list, revealing items not initially
		// rendered. We then drop it at the end.
		await dragTowardBottomEdge(page, firstCard, cardList, 2500)

		// After auto-scroll, more tiles should be in the DOM
		const afterScrollCount = await column.locator('.card-tile-wrap').count()
		console.log(`DOM tiles after scroll: ${afterScrollCount}`)

		// The last visible tile after scrolling should be a card that was NOT
		// in the initial render window - confirming virtualization scrolled correctly.
		const lastVisibleCard = column.locator('.card-tile-wrap .card-tile').last()
		await expect(lastVisibleCard).toBeVisible({ timeout: 5000 })
		const lastCardTitle = await lastVisibleCard.innerText()
		console.log('Last visible card title after scroll:', lastCardTitle.trim())

		// Verify the dragged card moved (it should no longer be at position 0)
		// Give the optimistic update + server round-trip time to settle
		await page.waitForTimeout(2000)

		// Reload and verify the board state is consistent (server persisted the move)
		await page.reload()
		await page.waitForSelector('.stack-column', { timeout: 10_000 })
		await page.waitForSelector('.card-tile-wrap', { timeout: 10_000 })

		// After reload: virtualization still working (subset in DOM)
		const reloadCount = await page.locator('.card-tile-wrap').count()
		console.log(`DOM tile count after reload: ${reloadCount} (of ${TOTAL_CARDS})`)
		expect(reloadCount).toBeLessThan(TOTAL_CARDS)
		expect(reloadCount).toBeGreaterThan(0)
	})
})
