// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Keyboard navigation', () => {
	const state = {
		boardId: 0,
		stackS1Id: 0,
		stackS2Id: 0,
		card1Id: 0,
		card2Id: 0,
		card3Id: 0,
		card4Id: 0,
		boardUrl: '',
	}

	test.beforeAll(async () => {
		// Clean up any previous run
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === 'Keyboard Test Board') {
				await api.delete(`/boards/${b.id}`)
			}
		}

		// Create board with 2 stacks × 2 cards each
		const board = await api.post('/boards', { title: 'Keyboard Test Board' })
		state.boardId = board.id

		const s1 = await api.post('/stacks', { boardId: board.id, title: 'Stack One' })
		const s2 = await api.post('/stacks', { boardId: board.id, title: 'Stack Two' })
		state.stackS1Id = s1.id
		state.stackS2Id = s2.id

		const c1 = await api.post('/cards', { stackId: s1.id, title: 'Card Alpha' })
		const c2 = await api.post('/cards', { stackId: s1.id, title: 'Card Beta' })
		const c3 = await api.post('/cards', { stackId: s2.id, title: 'Card Gamma' })
		const c4 = await api.post('/cards', { stackId: s2.id, title: 'Card Delta' })
		state.card1Id = c1.id
		state.card2Id = c2.id
		state.card3Id = c3.id
		state.card4Id = c4.id

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test('ArrowDown seeds to first card, navigates down and right', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		// ArrowDown with no focus should seed to first card of first stack
		await page.keyboard.press('ArrowDown')
		await page.waitForTimeout(200)

		// First card in S1 should be focused
		const s1 = page.locator('.stack-column').nth(0)
		const firstCard = s1.locator('.card-tile').first()
		await expect(firstCard).toBeFocused({ timeout: 3000 })

		// ArrowDown → second card in S1
		await page.keyboard.press('ArrowDown')
		await page.waitForTimeout(200)
		const secondCard = s1.locator('.card-tile').nth(1)
		await expect(secondCard).toBeFocused({ timeout: 3000 })

		// ArrowDown at bottom clamps - still second card
		await page.keyboard.press('ArrowDown')
		await expect(secondCard).toBeFocused({ timeout: 3000 })

		// ArrowRight → move to S2, card index clamped to 1
		await page.keyboard.press('ArrowRight')
		await page.waitForTimeout(200)
		const s2 = page.locator('.stack-column').nth(1)
		const s2SecondCard = s2.locator('.card-tile').nth(1)
		await expect(s2SecondCard).toBeFocused({ timeout: 3000 })

		// ArrowUp → first card of S2
		await page.keyboard.press('ArrowUp')
		await page.waitForTimeout(200)
		const s2FirstCard = s2.locator('.card-tile').first()
		await expect(s2FirstCard).toBeFocused({ timeout: 3000 })
	})

	test("'e' opens card modal for the focused card (URL check), Esc closes", async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		// Seed focus
		await page.keyboard.press('ArrowDown')
		await page.waitForTimeout(200)

		// Navigate right to S2, then ArrowDown to second card
		await page.keyboard.press('ArrowRight')
		await page.waitForTimeout(200)
		await page.keyboard.press('ArrowDown')
		await page.waitForTimeout(200)

		// 'e' should open the card modal - URL should include /card/<id>
		await page.keyboard.press('e')
		await page.waitForTimeout(500)

		const url = page.url()
		expect(url).toContain('/card/')

		// Esc closes the modal (NcModal native close)
		await page.keyboard.press('Escape')
		await page.waitForTimeout(500)

		// URL should no longer have /card/
		const urlAfter = page.url()
		expect(urlAfter).not.toContain('/card/')
	})

	test("'d' toggles done styling on the focused tile", async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		// Seed focus to first card S1
		await page.keyboard.press('ArrowDown')
		await page.waitForTimeout(200)

		const s1 = page.locator('.stack-column').nth(0)
		const firstTile = s1.locator('.card-tile').first()
		await expect(firstTile).toBeFocused({ timeout: 3000 })

		// Toggle done
		await page.keyboard.press('d')

		// Wait for done styling to appear (poll - the update is async)
		await expect(firstTile).toHaveClass(/card-tile--done/, { timeout: 8000 })

		// Toggle done back
		await page.keyboard.press('d')
		await expect(firstTile).not.toHaveClass(/card-tile--done/, { timeout: 8000 })
	})

	test("'n' focuses the composer of the focused card's stack", async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		// Seed focus to S1
		await page.keyboard.press('ArrowDown')
		await page.waitForTimeout(200)

		// 'n' should focus the composer input of S1
		await page.keyboard.press('n')
		await page.waitForTimeout(200)

		const s1 = page.locator('.stack-column').nth(0)
		const composer = s1.locator('.card-composer__input')
		await expect(composer).toBeFocused({ timeout: 3000 })

		// IMPORTANT: typing in the composer should NOT trigger shortcuts
		// Type a title containing 'n', 'e', 'd'
		await page.keyboard.type('ned')
		await page.waitForTimeout(200)

		// Composer value should be 'ned', no modal opened, no done toggled
		await expect(composer).toHaveValue('ned')
		// Card modal should not be open
		const url = page.url()
		expect(url).not.toContain('/card/')
	})

	test("'?' opens the shortcuts overlay and Esc closes it", async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		// '?' should open the shortcuts overlay
		await page.keyboard.press('?')
		await page.waitForTimeout(300)

		// NcModal should be visible - look for the modal with keyboard shortcuts heading
		const modal = page.locator('.modal-container, [role="dialog"]').filter({ hasText: 'Keyboard shortcuts' })
		await expect(modal).toBeVisible({ timeout: 3000 })

		// Esc should close it (NcModal native close)
		await page.keyboard.press('Escape')
		await expect(modal).not.toBeVisible({ timeout: 3000 })
	})

	test('hjkl alias the arrow-key navigation (j/k cards, l/h stacks)', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		// 'j' with no focus should seed to first card of first stack (like ArrowDown)
		await page.keyboard.press('j')
		await page.waitForTimeout(200)
		const s1 = page.locator('.stack-column').nth(0)
		const firstCard = s1.locator('.card-tile').first()
		await expect(firstCard).toBeFocused({ timeout: 3000 })

		// 'j' → second card in S1 (ArrowDown)
		await page.keyboard.press('j')
		await page.waitForTimeout(200)
		const secondCard = s1.locator('.card-tile').nth(1)
		await expect(secondCard).toBeFocused({ timeout: 3000 })

		// 'k' → back to first card in S1 (ArrowUp)
		await page.keyboard.press('k')
		await expect(firstCard).toBeFocused({ timeout: 3000 })

		// 'l' → move to S2 (ArrowRight)
		await page.keyboard.press('l')
		await page.waitForTimeout(200)
		const s2 = page.locator('.stack-column').nth(1)
		const s2FirstCard = s2.locator('.card-tile').first()
		await expect(s2FirstCard).toBeFocused({ timeout: 3000 })

		// 'h' → back to S1 (ArrowLeft)
		await page.keyboard.press('h')
		await expect(firstCard).toBeFocused({ timeout: 3000 })
	})

	test('typing h/j/k/l in the composer inserts the letters (guard holds)', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		// Seed focus to S1, then open its composer with 'n'
		await page.keyboard.press('j')
		await page.waitForTimeout(200)
		await page.keyboard.press('n')
		await page.waitForTimeout(200)

		const s1 = page.locator('.stack-column').nth(0)
		const composer = s1.locator('.card-composer__input')
		await expect(composer).toBeFocused({ timeout: 3000 })

		// Typing the vim nav letters must insert them, not navigate
		await page.keyboard.type('hjkl')
		await expect(composer).toHaveValue('hjkl')
	})

	test('mouse click on tile keeps focusedCardId in sync (tile click syncs keyboard state)', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		// Click on the second card in S2 to open the modal
		const s2 = page.locator('.stack-column').nth(1)
		const s2SecondCard = s2.locator('.card-tile').nth(1)
		await s2SecondCard.click()
		await page.waitForTimeout(300)

		// Modal should be open
		const url = page.url()
		expect(url).toContain('/card/')

		// Close modal with Esc
		await page.keyboard.press('Escape')
		await page.waitForTimeout(300)

		// After close, ArrowUp from the second card position should go to first card of S2
		await page.keyboard.press('ArrowUp')
		await page.waitForTimeout(200)
		const s2FirstCard = s2.locator('.card-tile').first()
		await expect(s2FirstCard).toBeFocused({ timeout: 3000 })
	})
})
