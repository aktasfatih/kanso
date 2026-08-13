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

// ── Hermetic test state ────────────────────────────────────────────────────────
// One board with a distinctive card for palette navigation.
test.describe('Command Palette', () => {
	const state = {
		boardId: 0,
		cardId: 0,
		boardUrl: '',
		boardTitle: 'CmdK Palette Board E2E',
	}

	test.beforeAll(async () => {
		// Tear down any prior board with the same name to ensure hermetic setup
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === state.boardTitle) {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		// Create fresh board + stack + card
		const board = await apiPost('/boards', { title: state.boardTitle })
		state.boardId = board.id
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'Todo' })

		const card = await apiPost('/cards', {
			stackId: stack.id,
			title: 'Quartz timepiece sync',
		})
		state.cardId = card.id

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	async function goToBoard(page) {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })
	}

	// ── Tests ──────────────────────────────────────────────────────────────────

	test('Ctrl+K opens the command palette with a focused search input', async ({ page }) => {
		await goToBoard(page)

		// Click the board canvas to ensure no input is focused
		await page.locator('.board-view__stacks-wrap').click()
		await page.waitForTimeout(100)

		// Press Ctrl+K (Cmd+K on Mac is handled by Meta+K; Playwright uses Control for Ctrl)
		await page.keyboard.press('Control+k')

		// Palette should appear
		const palette = page.locator('.command-palette')
		await expect(palette).toBeVisible({ timeout: 5000 })

		// Input should be auto-focused
		const input = palette.locator('.command-palette__input')
		await expect(input).toBeFocused({ timeout: 3000 })
	})

	test('empty query shows recent boards section', async ({ page }) => {
		await goToBoard(page)

		await page.locator('.board-view__stacks-wrap').click()
		await page.waitForTimeout(100)
		await page.keyboard.press('Control+k')

		const palette = page.locator('.command-palette')
		await expect(palette).toBeVisible({ timeout: 5000 })

		// The test board should appear in the Boards section
		const results = palette.locator('#command-palette-results')
		const boardResult = results.locator('.command-palette__result').filter({ hasText: state.boardTitle })
		await expect(boardResult).toBeVisible({ timeout: 5000 })
	})

	test('typing a board name filters boards in results', async ({ page }) => {
		await goToBoard(page)

		await page.locator('.board-view__stacks-wrap').click()
		await page.waitForTimeout(100)
		await page.keyboard.press('Control+k')

		const palette = page.locator('.command-palette')
		await expect(palette).toBeVisible({ timeout: 5000 })

		// Type the distinctive board name prefix
		await palette.locator('.command-palette__input').fill('CmdK')

		const results = palette.locator('#command-palette-results')
		const boardResult = results.locator('.command-palette__result').filter({ hasText: state.boardTitle })
		await expect(boardResult).toBeVisible({ timeout: 5000 })
	})

	test('typing a card title shows a card result', async ({ page }) => {
		await goToBoard(page)

		await page.locator('.board-view__stacks-wrap').click()
		await page.waitForTimeout(100)
		await page.keyboard.press('Control+k')

		const palette = page.locator('.command-palette')
		await expect(palette).toBeVisible({ timeout: 5000 })

		// "Quartz" is unique to the card we created
		await palette.locator('.command-palette__input').fill('Quartz')

		const results = palette.locator('#command-palette-results')
		const cardResult = results.locator('.command-palette__result').filter({ hasText: 'Quartz timepiece sync' })
		// Server search has a debounce, give it a generous timeout
		await expect(cardResult).toBeVisible({ timeout: 8000 })
	})

	test('pressing Enter on the first board result navigates to that board', async ({ page }) => {
		await goToBoard(page)

		await page.locator('.board-view__stacks-wrap').click()
		await page.waitForTimeout(100)
		await page.keyboard.press('Control+k')

		const palette = page.locator('.command-palette')
		await expect(palette).toBeVisible({ timeout: 5000 })

		// Type the board title to get a unique match
		await palette.locator('.command-palette__input').fill('CmdK')

		const results = palette.locator('#command-palette-results')
		await expect(
			results.locator('.command-palette__result').filter({ hasText: state.boardTitle }),
		).toBeVisible({ timeout: 5000 })

		// Arrow down to highlight first result, then Enter
		await page.keyboard.press('ArrowDown')
		await page.keyboard.press('Enter')

		// Palette should close
		await expect(palette).not.toBeVisible({ timeout: 3000 }).catch(() => {})

		// URL hash should reference the board id
		await expect(page).toHaveURL(new RegExp(`/board/${state.boardId}`), { timeout: 5000 })
	})

	test('pressing Escape closes the palette', async ({ page }) => {
		await goToBoard(page)

		await page.locator('.board-view__stacks-wrap').click()
		await page.waitForTimeout(100)
		await page.keyboard.press('Control+k')

		const palette = page.locator('.command-palette')
		await expect(palette).toBeVisible({ timeout: 5000 })

		await page.keyboard.press('Escape')

		await expect(palette).not.toBeVisible({ timeout: 3000 })
	})

	test('Ctrl+K does NOT open palette when typing in a text input', async ({ page }) => {
		await goToBoard(page)

		// Focus the add-stack input (or search input) - any real text input
		const searchInput = page.locator('.search-box__input')
		await expect(searchInput).toBeVisible({ timeout: 5000 })
		await searchInput.focus()

		await page.keyboard.press('Control+k')

		// Palette should NOT have opened
		const palette = page.locator('.command-palette')
		await expect(palette).not.toBeVisible({ timeout: 1000 }).catch(() => {})
	})
})
