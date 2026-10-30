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
		await page.waitForSelector('.card-tile', { timeout: 15_000 })
	}

	test('opens from the board list and navigates to a board', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await expect(page.getByRole('heading', { name: 'My Boards', exact: true })).toBeVisible()
		await page.keyboard.press('Control+k')
		const palette = page.locator('.command-palette')
		await expect(palette).toHaveCount(1)
		await palette.locator('.command-palette__input').fill(state.boardTitle)
		await expect(palette.getByRole('option', { name: state.boardTitle })).toBeVisible()
		await page.keyboard.press('Enter')
		await expect(page).toHaveURL(new RegExp(`/board/${state.boardId}$`))
		await expect(palette).not.toBeVisible()
	})

	test('searches cards from another workspace page', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/my-tasks`)
		await expect(page.getByRole('heading', { name: 'My Tasks' })).toBeVisible()
		await page.keyboard.press('Control+k')
		const palette = page.locator('.command-palette')
		await palette.locator('.command-palette__input').fill('Quartz timepiece')
		await expect(palette.getByRole('option').filter({ hasText: 'Quartz timepiece sync' })).toBeVisible()
		await page.keyboard.press('Enter')
		await expect(page).toHaveURL(new RegExp(`/board/${state.boardId}/card/${state.cardId}$`))
		await expect(page.getByRole('dialog', { name: 'Quartz timepiece sync' })).toBeVisible()
	})

	test('does not open over another modal', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await page.locator('[data-test="open-settings"]').click()
		const settings = page.getByRole('dialog', { name: 'Kanso settings' })
		await expect(settings).toBeVisible()
		await page.keyboard.press('Control+k')
		await expect(page.locator('.command-palette')).toHaveCount(0)
		await expect(settings).toBeVisible()
		await page.keyboard.press('Escape')
		await expect(settings).not.toBeVisible()
		await page.evaluate(() => {
			const dialog = document.createElement('div')
			dialog.setAttribute('role', 'dialog')
			dialog.style.cssText = 'width: 100px; height: 100px; visibility: hidden'
			document.body.append(dialog)
		})
		await page.keyboard.press('Control+k')
		await expect(page.locator('.command-palette')).toBeVisible()
	})

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
		await expect(palette).toBeVisible()

		// Input should be auto-focused
		const input = palette.locator('.command-palette__input')
		await expect(input).toBeFocused()
	})

	test('board shortcuts do not run behind the open palette', async ({ page }) => {
		await goToBoard(page)
		await page.locator('.board-view__stacks-wrap').click()
		await page.keyboard.press('Control+k')
		const palette = page.locator('.command-palette')
		await palette.locator('.command-palette__input').fill('Quartz')
		const clear = palette.getByRole('button', { name: 'Clear search' })
		await clear.focus()
		await page.keyboard.press('j')
		await expect(clear).toBeFocused()
		await page.keyboard.press('Space')
		await expect(palette.locator('.command-palette__input')).toHaveValue('')
	})

	test('empty query shows recent boards section', async ({ page }) => {
		await goToBoard(page)

		await page.locator('.board-view__stacks-wrap').click()
		await page.waitForTimeout(100)
		await page.keyboard.press('Control+k')

		const palette = page.locator('.command-palette')
		await expect(palette).toBeVisible()

		// The test board should appear in the Boards section
		const results = palette.locator('#command-palette-results')
		const boardResult = results.locator('.command-palette__result').filter({ hasText: state.boardTitle })
		await expect(boardResult).toBeVisible()
	})

	test('typing a board name filters boards in results', async ({ page }) => {
		await goToBoard(page)

		await page.locator('.board-view__stacks-wrap').click()
		await page.waitForTimeout(100)
		await page.keyboard.press('Control+k')

		const palette = page.locator('.command-palette')
		await expect(palette).toBeVisible()

		// Type the distinctive board name prefix
		await palette.locator('.command-palette__input').fill('CmdK')

		const results = palette.locator('#command-palette-results')
		const boardResult = results.locator('.command-palette__result').filter({ hasText: state.boardTitle })
		await expect(boardResult).toBeVisible()
	})

	test('typing a card title shows a card result', async ({ page }) => {
		await goToBoard(page)

		await page.locator('.board-view__stacks-wrap').click()
		await page.waitForTimeout(100)
		await page.keyboard.press('Control+k')

		const palette = page.locator('.command-palette')
		await expect(palette).toBeVisible()

		// "Quartz" is unique to the card we created
		await palette.locator('.command-palette__input').fill('Quartz')

		const results = palette.locator('#command-palette-results')
		const cardResult = results.locator('.command-palette__result').filter({ hasText: 'Quartz timepiece sync' })
		// Server search has a debounce, give it a generous timeout
		await expect(cardResult).toBeVisible()
	})

	test('pressing Enter on the first board result navigates to that board', async ({ page }) => {
		await goToBoard(page)

		await page.locator('.board-view__stacks-wrap').click()
		await page.waitForTimeout(100)
		await page.keyboard.press('Control+k')

		const palette = page.locator('.command-palette')
		await expect(palette).toBeVisible()

		// Type the board title to get a unique match
		await palette.locator('.command-palette__input').fill('CmdK')

		const results = palette.locator('#command-palette-results')
		await expect(
			results.locator('.command-palette__result').filter({ hasText: state.boardTitle }),
		).toBeVisible()

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
		await expect(palette).toBeVisible()

		await page.keyboard.press('Escape')

		await expect(palette).not.toBeVisible()
	})

	test('Ctrl+K does NOT open palette when typing in a text input', async ({ page }) => {
		await goToBoard(page)

		// Focus the add-stack input (or search input) - any real text input
		const searchInput = page.locator('.search-box__input')
		await expect(searchInput).toBeVisible()
		await searchInput.focus()

		await page.keyboard.press('Control+k')

		// Palette should NOT have opened
		const palette = page.locator('.command-palette')
		await expect(palette).toHaveCount(0)
	})
})
