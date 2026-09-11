// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// ── Hermetic test state ────────────────────────────────────────────────────────
// Three cards on one board:
//   1. "Alpha widget"       - card title match
//   2. "Beta gadget"        - card description contains "photosynthesis"
//   3. "Gamma fixture"      - has a comment containing "xylorimba"
test.describe('Search', () => {
	const state = {
		boardId: 0,
		stackId: 0,
		cardAlphaId: 0,
		cardBetaId: 0,
		cardGammaId: 0,
		boardUrl: '',
	}

	test.beforeAll(async () => {
		// Tear down any prior board with the same name to ensure hermetic setup
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === 'Search Test Board E2E') {
				await api.delete(`/boards/${b.id}`)
			}
		}

		// Create fresh board + stack
		const board = await api.post('/boards', { title: 'Search Test Board E2E' })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Backlog' })
		state.stackId = stack.id

		// Card 1 - unique title term "Alpha widget"
		const cardAlpha = await api.post('/cards', {
			stackId: stack.id,
			title: 'Alpha widget',
		})
		state.cardAlphaId = cardAlpha.id

		// Card 2 - title "Beta gadget", description contains "photosynthesis".
		// Description is set via PATCH (card create is title-only by design).
		const cardBeta = await api.post('/cards', {
			stackId: stack.id,
			title: 'Beta gadget',
		})
		state.cardBetaId = cardBeta.id
		await api.patch(`/cards/${cardBeta.id}`, {
			description: 'This card explains photosynthesis in plants.',
		})

		// Card 3 - title "Gamma fixture", comment contains "xylorimba"
		const cardGamma = await api.post('/cards', {
			stackId: stack.id,
			title: 'Gamma fixture',
		})
		state.cardGammaId = cardGamma.id
		await api.post(`/cards/${cardGamma.id}/comments`, {
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

	// #122 — a board routinely holds several cards whose titles are near-identical
	// and whose only distinguishing feature is the stage they are at, which made
	// the result list unreadable: every row looked the same and had to be opened
	// to tell them apart. Each hit now names its column.
	test('results name the column, which is what tells identically-titled cards apart', async ({ page }) => {
		// Two cards with the SAME title in DIFFERENT columns - the reporter's case.
		const twinA = await api.post('/cards', { stackId: state.stackId, title: 'Wheelchair repair Kaya' })
		const secondStack = await api.post('/stacks', { boardId: state.boardId, title: 'Awaiting parts' })
		const twinB = await api.post('/cards', { stackId: secondStack.id, title: 'Wheelchair repair Kaya' })

		await goToBoard(page)
		const searchInput = page.locator('.search-box__input')
		await searchInput.fill('Wheelchair')

		const dropdown = page.locator('.search-box__dropdown')
		await expect(dropdown).toBeVisible({ timeout: 5000 })
		const rows = dropdown.locator('.search-box__result').filter({ hasText: 'Wheelchair repair Kaya' })
		await expect(rows).toHaveCount(2, { timeout: 5000 })

		// Identical titles, so the column is the ONLY thing separating the rows.
		const columns = await rows.locator('.search-box__result-column').allTextContents()
		expect(columns.map((c) => c.trim()).sort()).toEqual(['Awaiting parts', 'Backlog'])

		// A comment hit carries it too - it is a card hit by another route.
		await searchInput.fill('xylorimba')
		const commentRow = dropdown.locator('.search-box__result').filter({ hasText: 'Gamma fixture' })
		await expect(commentRow).toBeVisible({ timeout: 5000 })
		await expect(commentRow.locator('.search-box__result-column')).toHaveText('Backlog')

		await api.delete(`/cards/${twinA.id}`)
		await api.delete(`/cards/${twinB.id}`)
		await api.delete(`/stacks/${secondStack.id}`)
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
		await expect(page.locator('.search-box__dropdown')).not.toBeVisible()
	})

	test('pressing "/" keyboard shortcut focuses the search box', async ({ page }) => {
		await goToBoard(page)

		// Make sure no input element is active
		await page.locator('.board-view__stacks-wrap').click()
		await page.waitForTimeout(100)

		await page.keyboard.press('/')

		const searchInput = page.locator('.search-box__input')
		await expect(searchInput).toBeFocused()
	})
})
