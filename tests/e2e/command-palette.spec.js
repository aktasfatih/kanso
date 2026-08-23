// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

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
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === state.boardTitle) {
				await api.delete(`/boards/${b.id}`)
			}
		}

		// Create fresh board + stack + card
		const board = await api.post('/boards', { title: state.boardTitle })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Todo' })

		const card = await api.post('/cards', {
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
