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

async function apiPatch(path, body) {
	const r = await fetch(API + path, {
		method: 'PATCH',
		headers: { ...HEADERS, Authorization: AUTH },
		body: JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`PATCH ${path} → ${r.status}: ${await r.text()}`)
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

test.describe('Card modal two-column layout', () => {
	// Unique board title to avoid collision with parallel test runs
	const BOARD_TITLE = 'Modal Layout E2E Board ' + Date.now()
	const state = {
		boardId: 0,
		stackId: 0,
		cardId: 0,
		cardUrl: '',
	}

	test.beforeAll(async () => {
		// Tear down any stale board with the same name
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === BOARD_TITLE) {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		// Create board + stack + card via API
		const board = await apiPost('/boards', { title: BOARD_TITLE })
		state.boardId = board.id

		const stack = await apiPost('/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id

		const card = await apiPost('/cards', {
			stackId: stack.id,
			title: 'Layout Test Card',
			description: 'This is the card description used to verify left column placement.',
		})
		state.cardId = card.id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`

		// Set a due date via API
		await apiPatch(`/cards/${card.id}`, {
			duedate: new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString(),
		})

		// Set priority to High (3) via API
		await apiPatch(`/cards/${card.id}`, { priority: 3 })
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiDelete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('description and comment compose are in .card-modal__main (left)', async ({ page }) => {
		// Use a wide viewport so two-column layout is active
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)

		// Wait for the card modal to be visible
		await page.waitForSelector('.card-modal__main', { timeout: 15_000 })

		// Description section must be inside .card-modal__main
		const mainLocator = page.locator('.card-modal__main')
		await expect(mainLocator.locator('.card-modal__description-section')).toBeVisible()

		// Comment compose textarea must also be inside .card-modal__main
		await expect(mainLocator.locator('.card-modal__comment-compose-textarea').first()).toBeVisible()
	})

	test('due-date input and priority buttons are in .card-modal__sidebar (right)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)

		await page.waitForSelector('.card-modal__sidebar', { timeout: 15_000 })

		const sidebar = page.locator('.card-modal__sidebar')

		// Due date input must be inside the sidebar
		await expect(sidebar.locator('.card-modal__due-input')).toBeVisible()

		// Priority buttons must be inside the sidebar
		await expect(sidebar.locator('.card-modal__priority-btn--3')).toBeVisible()
	})

	test('.card-modal__main is to the LEFT of .card-modal__sidebar on wide viewport', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)

		await page.waitForSelector('.card-modal__columns', { timeout: 15_000 })

		const mainBox = await page.locator('.card-modal__main').boundingBox()
		const sidebarBox = await page.locator('.card-modal__sidebar').boundingBox()

		expect(mainBox).not.toBeNull()
		expect(sidebarBox).not.toBeNull()

		// The main column's left edge must be strictly to the left of the sidebar's left edge
		expect(mainBox.x).toBeLessThan(sidebarBox.x)
		// And the main column's right edge must not exceed the sidebar's left edge
		// (i.e. they do not overlap horizontally)
		expect(mainBox.x + mainBox.width).toBeLessThanOrEqual(sidebarBox.x + 4) // 4px tolerance for rounding
	})

	test('priority High button (level 3) is clickable and toggles aria-pressed', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)

		await page.waitForSelector('.card-modal__sidebar', { timeout: 15_000 })

		const sidebar = page.locator('.card-modal__sidebar')
		const highBtn = sidebar.locator('.card-modal__priority-btn--3')

		// The button should exist and be visible
		await expect(highBtn).toBeVisible()

		// Click None (level 0) to deactivate High first
		const noneBtn = sidebar.locator('.card-modal__priority-btn--0')
		await noneBtn.click()
		await expect(noneBtn).toHaveAttribute('aria-pressed', 'true', { timeout: 5_000 })

		// Now click High (level 3) — it should become active
		await highBtn.click()
		await expect(highBtn).toHaveAttribute('aria-pressed', 'true', { timeout: 5_000 })
	})

	test('layout collapses to single column on narrow viewport', async ({ page }) => {
		await page.setViewportSize({ width: 500, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)

		await page.waitForSelector('.card-modal__columns', { timeout: 15_000 })

		const mainBox = await page.locator('.card-modal__main').boundingBox()
		const sidebarBox = await page.locator('.card-modal__sidebar').boundingBox()

		expect(mainBox).not.toBeNull()
		expect(sidebarBox).not.toBeNull()

		// In single-column mode, they must overlap horizontally (same x start, similar widths)
		// Both should start at roughly the same x position (within 16px)
		expect(Math.abs(mainBox.x - sidebarBox.x)).toBeLessThan(16)
	})
})
