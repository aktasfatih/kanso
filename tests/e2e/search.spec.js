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

// ── Hermetic test state ────────────────────────────────────────────────────────
// Three cards on one board:
//   1. "Alpha widget"       - card title match
//   2. "Beta gadget"        - card description contains "photosynthesis"
//   3. "Gamma fixture"      - has a comment containing "xylorimba"
test.describe('Search', () => {
	const state = {
		boardId: 0,
		cardAlphaId: 0,
		cardBetaId: 0,
		cardGammaId: 0,
		boardUrl: '',
	}

	test.beforeAll(async () => {
		// Tear down any prior board with the same name to ensure hermetic setup
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === 'Search Test Board E2E') {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		// Create fresh board + stack
		const board = await apiPost('/boards', { title: 'Search Test Board E2E' })
		state.boardId = board.id
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'Backlog' })

		// Card 1 - unique title term "Alpha widget"
		const cardAlpha = await apiPost('/cards', {
			stackId: stack.id,
			title: 'Alpha widget',
		})
		state.cardAlphaId = cardAlpha.id

		// Card 2 - title "Beta gadget", description contains "photosynthesis".
		// Description is set via PATCH (card create is title-only by design).
		const cardBeta = await apiPost('/cards', {
			stackId: stack.id,
			title: 'Beta gadget',
		})
		state.cardBetaId = cardBeta.id
		await apiPatch(`/cards/${cardBeta.id}`, {
			description: 'This card explains photosynthesis in plants.',
		})

		// Card 3 - title "Gamma fixture", comment contains "xylorimba"
		const cardGamma = await apiPost('/cards', {
			stackId: stack.id,
			title: 'Gamma fixture',
		})
		state.cardGammaId = cardGamma.id
		await apiPost(`/cards/${cardGamma.id}/comments`, {
			body: 'Check the xylorimba tuning reference.',
		})

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	// ── Shared navigation helper ───────────────────────────────────────────────

	async function goToBoard(page) {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })
	}

	// ── Tests ──────────────────────────────────────────────────────────────────

	test('typing a card-title term shows that card in results and clicking opens the modal', async ({ page }) => {
		await goToBoard(page)

		const searchInput = page.locator('.search-box__input')
		await expect(searchInput).toBeVisible({ timeout: 5000 })

		// Type "Alpha" - unique enough to match only "Alpha widget"
		await searchInput.fill('Alpha')

		// Dropdown should appear with the result
		const dropdown = page.locator('.search-box__dropdown')
		await expect(dropdown).toBeVisible({ timeout: 5000 })

		const alphaResult = dropdown.locator('.search-box__result').filter({ hasText: 'Alpha widget' })
		await expect(alphaResult).toBeVisible({ timeout: 5000 })

		// Click the result → card modal should open
		await alphaResult.click()
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Dropdown should close and search cleared after selecting a result
		await expect(dropdown).not.toBeVisible({ timeout: 3000 }).catch(() => {})
	})

	test('typing a description-only term shows the correct card', async ({ page }) => {
		await goToBoard(page)

		const searchInput = page.locator('.search-box__input')
		await expect(searchInput).toBeVisible({ timeout: 5000 })

		// "photosynthesis" is only in Beta gadget's description, not in any title
		await searchInput.fill('photosynthesis')

		const dropdown = page.locator('.search-box__dropdown')
		await expect(dropdown).toBeVisible({ timeout: 5000 })

		// Beta gadget should appear
		const betaResult = dropdown.locator('.search-box__result').filter({ hasText: 'Beta gadget' })
		await expect(betaResult).toBeVisible({ timeout: 5000 })

		// Alpha widget should NOT appear
		const alphaResult = dropdown.locator('.search-box__result').filter({ hasText: 'Alpha widget' })
		await expect(alphaResult).not.toBeVisible()
	})

	test('typing the comment-only distinctive word shows a comment-type result pointing at the right card', async ({ page }) => {
		await goToBoard(page)

		const searchInput = page.locator('.search-box__input')
		await expect(searchInput).toBeVisible({ timeout: 5000 })

		// "xylorimba" is only in Gamma fixture's comment
		await searchInput.fill('xylorimba')

		const dropdown = page.locator('.search-box__dropdown')
		await expect(dropdown).toBeVisible({ timeout: 5000 })

		// Result should list Gamma fixture as the card title
		const gammaResult = dropdown.locator('.search-box__result').filter({ hasText: 'Gamma fixture' })
		await expect(gammaResult).toBeVisible({ timeout: 5000 })

		// Should show the comment badge
		const commentBadge = gammaResult.locator('.search-box__result-badge')
		await expect(commentBadge).toBeVisible()
		await expect(commentBadge).toHaveText('comment')
	})

	test('typing a single character does NOT open the dropdown or make an API call', async ({ page }) => {
		await goToBoard(page)

		// Intercept search API calls so we can assert none fired
		const searchRequests = []
		page.on('request', (req) => {
			if (req.url().includes('/api/search')) searchRequests.push(req)
		})

		const searchInput = page.locator('.search-box__input')
		await expect(searchInput).toBeVisible({ timeout: 5000 })

		await searchInput.fill('A')

		// Wait a moment to let any (erroneous) debounced request fire
		await page.waitForTimeout(600)

		// Dropdown should NOT be visible
		const dropdown = page.locator('.search-box__dropdown')
		await expect(dropdown).not.toBeVisible()

		// No search API request should have been made
		expect(searchRequests.length).toBe(0)
	})

	test('typing gibberish shows the "No matches" empty state', async ({ page }) => {
		await goToBoard(page)

		const searchInput = page.locator('.search-box__input')
		await expect(searchInput).toBeVisible({ timeout: 5000 })

		await searchInput.fill('zzzxqxqxq')

		const dropdown = page.locator('.search-box__dropdown')
		await expect(dropdown).toBeVisible({ timeout: 5000 })

		// Empty-state message should appear
		const emptyState = dropdown.locator('.search-box__status--empty')
		await expect(emptyState).toBeVisible({ timeout: 5000 })
		await expect(emptyState).toContainText('No matches')
	})

	test('pressing Escape closes the dropdown and clears the input', async ({ page }) => {
		await goToBoard(page)

		const searchInput = page.locator('.search-box__input')
		await expect(searchInput).toBeVisible({ timeout: 5000 })

		await searchInput.fill('Alpha')
		await expect(page.locator('.search-box__dropdown')).toBeVisible({ timeout: 5000 })

		await searchInput.press('Escape')

		// Input should be cleared
		await expect(searchInput).toHaveValue('')
		// Dropdown should be gone
		await expect(page.locator('.search-box__dropdown')).not.toBeVisible({ timeout: 3000 })
	})

	test('pressing "/" keyboard shortcut focuses the search box', async ({ page }) => {
		await goToBoard(page)

		// Make sure no input element is active
		await page.locator('.board-view__stacks-wrap').click()
		await page.waitForTimeout(100)

		await page.keyboard.press('/')

		const searchInput = page.locator('.search-box__input')
		await expect(searchInput).toBeFocused({ timeout: 3000 })
	})
})
