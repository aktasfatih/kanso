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

async function dragWithMouse(page, sourceLocator, targetLocator, targetPosition = 'top') {
	const srcBox = await sourceLocator.boundingBox()
	const tgtBox = await targetLocator.boundingBox()
	if (!srcBox || !tgtBox) throw new Error('Could not get bounding boxes for drag')

	const srcX = srcBox.x + srcBox.width / 2
	const srcY = srcBox.y + srcBox.height / 2
	// 'left' aims at the left edge of the target (stack column reordering);
	// 'top'/'bottom' aim at the vertical edges (card reordering).
	const tgtX = targetPosition === 'left'
		? tgtBox.x + tgtBox.width * 0.1
		: tgtBox.x + tgtBox.width / 2
	const tgtY = targetPosition === 'left'
		? tgtBox.y + tgtBox.height / 2
		: targetPosition === 'top'
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

test.describe('Card drag and drop', () => {
	// IDs set by beforeAll, shared across tests
	const state = { boardId: 0, stackS1Id: 0, stackS2Id: 0, cardAId: 0, cardBId: 0, boardUrl: '' }

	// ── Same-column reorder fixture ─────────────────────────────────────────────
	// A dedicated board so the reorder test is independent of the cross-column
	// tests above (which empty S1 as a side effect). Three cards R1, R2, R3 in a
	// single column let us assert a genuine above/below-sibling reorder + persist.
	const reorder = { boardId: 0, stackId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		// Delete any existing DnD Test Board to start clean
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === 'DnD Test Board') {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		// Create fresh board
		const board = await apiPost('/boards', { title: 'DnD Test Board' })
		state.boardId = board.id

		// Create stacks S1 and S2
		const s1 = await apiPost('/stacks', { boardId: board.id, title: 'S1' })
		const s2 = await apiPost('/stacks', { boardId: board.id, title: 'S2' })
		state.stackS1Id = s1.id
		state.stackS2Id = s2.id

		// Create card A in S1, card B in S2
		const cardA = await apiPost('/cards', { stackId: s1.id, title: 'A' })
		const cardB = await apiPost('/cards', { stackId: s2.id, title: 'B' })
		state.cardAId = cardA.id
		state.cardBId = cardB.id

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
		console.log('Setup complete - boardUrl:', state.boardUrl)

		// Reorder fixture: a separate board with one column holding R1, R2, R3
		// (created in order, so their initial top-to-bottom order is R1, R2, R3).
		for (const b of boards) {
			if (b.title === 'DnD Reorder Board') await apiDelete(`/boards/${b.id}`)
		}
		const rBoard = await apiPost('/boards', { title: 'DnD Reorder Board' })
		reorder.boardId = rBoard.id
		const rStack = await apiPost('/stacks', { boardId: rBoard.id, title: 'R' })
		reorder.stackId = rStack.id
		// Create in order so the board renders R1 (top), R2, R3 (bottom).
		await apiPost('/cards', { stackId: rStack.id, title: 'R1' })
		await apiPost('/cards', { stackId: rStack.id, title: 'R2' })
		await apiPost('/cards', { stackId: rStack.id, title: 'R3' })
		reorder.boardUrl = `${BASE}/index.php/apps/kanso#/board/${rBoard.id}`
	})

	test('drag card A into S2 above card B, persists after reload', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

		// Wait for stacks to render
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		// Use nth(0)/nth(1) since there are exactly 2 stacks (S1=first, S2=second by sortKey)
		const s1 = page.locator('.stack-column').nth(0)
		const s2 = page.locator('.stack-column').nth(1)

		const cardA = s1.locator('.card-tile-wrap .card-tile').filter({ hasText: 'A' })
		const cardB = s2.locator('.card-tile-wrap .card-tile').filter({ hasText: 'B' })

		await expect(cardA).toBeVisible({ timeout: 5000 })
		await expect(cardB).toBeVisible({ timeout: 5000 })

		// Drag A to top of B (above B in S2)
		await dragWithMouse(page, cardA, cardB, 'top')

		// After drop: S2 should show A then B
		const s2Cards = s2.locator('.card-tile-wrap .card-tile')
		await expect(s2Cards).toHaveCount(2, { timeout: 8000 })
		await expect(s2Cards.nth(0)).toContainText('A')
		await expect(s2Cards.nth(1)).toContainText('B')

		// S1 should now be empty of cards
		const s1Cards = s1.locator('.card-tile-wrap .card-tile')
		await expect(s1Cards).toHaveCount(0, { timeout: 5000 })

		// Reload and verify persistence
		await page.reload()
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		const s2After = page.locator('.stack-column').nth(1)
		const s2CardsAfter = s2After.locator('.card-tile-wrap .card-tile')
		await expect(s2CardsAfter).toHaveCount(2, { timeout: 8000 })
		await expect(s2CardsAfter.nth(0)).toContainText('A')
		await expect(s2CardsAfter.nth(1)).toContainText('B')
	})

	test('rapid successive drags end in server-consistent order', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		// After test 1: A and B are both in S2. S1 is empty.
		const s1 = page.locator('.stack-column').nth(0)
		const s2 = page.locator('.stack-column').nth(1)

		const cardA = s2.locator('.card-tile-wrap .card-tile').filter({ hasText: 'A' })
		const cardB = s2.locator('.card-tile-wrap .card-tile').filter({ hasText: 'B' })
		const s1CardList = s1.locator('.stack-column__cards')

		await expect(cardA).toBeVisible({ timeout: 5000 })
		await expect(cardB).toBeVisible({ timeout: 5000 })

		// Drag A to S1 (empty column drop)
		const s1Box = await s1CardList.boundingBox()
		if (!s1Box) throw new Error('S1 cards area not found')
		const aBox = await cardA.boundingBox()
		if (!aBox) throw new Error('Card A not found')

		await page.mouse.move(aBox.x + aBox.width / 2, aBox.y + aBox.height / 2)
		await page.mouse.down()
		for (let i = 1; i <= 10; i++) {
			await page.mouse.move(
				aBox.x + aBox.width / 2 + (s1Box.x + s1Box.width / 2 - (aBox.x + aBox.width / 2)) * (i / 10),
				aBox.y + aBox.height / 2 + (s1Box.y + s1Box.height / 2 - (aBox.y + aBox.height / 2)) * (i / 10),
				{ steps: 1 },
			)
			await page.waitForTimeout(15)
		}
		await page.waitForTimeout(50)
		await page.mouse.up()
		await page.waitForTimeout(150) // intentionally rapid - don't wait for settle

		// Immediately drag B to S1 as well
		const bBox = await cardB.boundingBox()
		if (!bBox) throw new Error('Card B not found')
		const s1BoxFresh = await s1CardList.boundingBox() ?? s1Box

		await page.mouse.move(bBox.x + bBox.width / 2, bBox.y + bBox.height / 2)
		await page.mouse.down()
		for (let i = 1; i <= 10; i++) {
			await page.mouse.move(
				bBox.x + bBox.width / 2 + (s1BoxFresh.x + s1BoxFresh.width / 2 - (bBox.x + bBox.width / 2)) * (i / 10),
				bBox.y + bBox.height / 2 + (s1BoxFresh.y + s1BoxFresh.height / 2 - (bBox.y + bBox.height / 2)) * (i / 10),
				{ steps: 1 },
			)
			await page.waitForTimeout(15)
		}
		await page.waitForTimeout(50)
		await page.mouse.up()

		// Wait for queue to drain (both moves settled + final invalidate refetch)
		await page.waitForTimeout(3000)

		// Reload to confirm server persisted both moves
		await page.reload()
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		const s1After = page.locator('.stack-column').nth(0)
		const s2After = page.locator('.stack-column').nth(1)

		const s1CardsAfter = s1After.locator('.card-tile-wrap .card-tile')
		await expect(s1CardsAfter).toHaveCount(2, { timeout: 8000 })

		const s2CardsAfter = s2After.locator('.card-tile-wrap .card-tile')
		await expect(s2CardsAfter).toHaveCount(0, { timeout: 5000 })
	})

	test('drag stack S2 header to the left edge of S1 flips column order, persists after reload', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		// Starting order: S1, S2
		const titles = page.locator('.stack-column__title')
		await expect(titles.nth(0)).toHaveText('S1', { timeout: 5000 })
		await expect(titles.nth(1)).toHaveText('S2')

		// Drag S2's HEADER (the stack drag handle) onto the LEFT edge of S1
		const s2Header = page.locator('.stack-column').nth(1).locator('.stack-column__header')
		const s1Column = page.locator('.stack-column').nth(0)
		await dragWithMouse(page, s2Header, s1Column, 'left')

		// Column order flips: S2, S1
		await expect(titles.nth(0)).toHaveText('S2', { timeout: 8000 })
		await expect(titles.nth(1)).toHaveText('S1')

		// Reload and verify persistence
		await page.reload()
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		const titlesAfter = page.locator('.stack-column__title')
		await expect(titlesAfter.nth(0)).toHaveText('S2', { timeout: 8000 })
		await expect(titlesAfter.nth(1)).toHaveText('S1')
	})

	test('same-column reorder: drag R3 above R1, order changes and persists after reload', async ({ page }) => {
		await ncLogin(page)
		await page.goto(reorder.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		const col = page.locator('.stack-column').nth(0)
		const cards = col.locator('.card-tile-wrap .card-tile')

		// Initial order (top-to-bottom): R1, R2, R3
		await expect(cards).toHaveCount(3, { timeout: 8000 })
		await expect(cards.nth(0)).toContainText('R1')
		await expect(cards.nth(1)).toContainText('R2')
		await expect(cards.nth(2)).toContainText('R3')

		// Drag the LAST card (R3) up onto the TOP edge of the FIRST card (R1).
		// This is a genuine same-column above-sibling reorder — a single-row
		// fractional-key UPDATE, not a cross-stack move.
		const r3 = cards.filter({ hasText: 'R3' })
		const r1 = cards.filter({ hasText: 'R1' })
		await dragWithMouse(page, r3, r1, 'top')

		// New order: R3, R1, R2
		await expect(cards.nth(0)).toContainText('R3', { timeout: 8000 })
		await expect(cards.nth(1)).toContainText('R1')
		await expect(cards.nth(2)).toContainText('R2')

		// Persist across reload (server is source of truth for the sort keys)
		await page.reload()
		await page.waitForSelector('.stack-column', { timeout: 10_000 })
		const colAfter = page.locator('.stack-column').nth(0)
		const cardsAfter = colAfter.locator('.card-tile-wrap .card-tile')
		await expect(cardsAfter).toHaveCount(3, { timeout: 8000 })
		await expect(cardsAfter.nth(0)).toContainText('R3', { timeout: 8000 })
		await expect(cardsAfter.nth(1)).toContainText('R1')
		await expect(cardsAfter.nth(2)).toContainText('R2')
	})
})
