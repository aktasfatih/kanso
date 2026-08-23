// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, BASE, api, ncLogin } from './helpers.js'

// The persisted-width localStorage key mirrors CardModal.vue (DISCUSSION_WIDTH_KEY).
const WIDTH_KEY = 'kanso.cardDiscussionWidth'

test.describe('Card modal resizable discussion pane (#3661)', () => {
	const BOARD_TITLE = 'Modal Resize E2E Board ' + Date.now()
	const state = { boardId: 0, stackId: 0, cardId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === BOARD_TITLE) await api.delete(`/boards/${b.id}`)
		}
		const board = await api.post('/boards', { title: BOARD_TITLE })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id
		const card = await api.post('/cards', {
			stackId: stack.id,
			title: 'Resize Test Card',
			description: 'Card used to verify the resizable discussion pane.',
		})
		state.cardId = card.id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('dragging the handle resizes the discussion pane and clamps within bounds', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		// Start from a clean, known width so the assertion is deterministic.
		await page.addInitScript(() => { try { localStorage.removeItem('kanso.cardDiscussionWidth') } catch (e) {} })
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal__resizer', { timeout: 15_000 })

		const before = await page.locator('.card-modal__discussion').boundingBox()
		expect(before).not.toBeNull()

		// Drag the handle LEFT by 120px → the discussion pane grows.
		const handle = await page.locator('.card-modal__resizer').boundingBox()
		expect(handle).not.toBeNull()
		const cx = handle.x + handle.width / 2
		const cy = handle.y + handle.height / 2
		await page.mouse.move(cx, cy)
		await page.mouse.down()
		await page.mouse.move(cx - 120, cy, { steps: 10 })
		await page.mouse.up()

		const after = await page.locator('.card-modal__discussion').boundingBox()
		expect(after.width).toBeGreaterThan(before.width + 60)

		// Clamp check: drag far RIGHT past the min → pane never collapses below 280px.
		const h2 = await page.locator('.card-modal__resizer').boundingBox()
		await page.mouse.move(h2.x + h2.width / 2, h2.y + h2.height / 2)
		await page.mouse.down()
		await page.mouse.move(h2.x + 2000, h2.y, { steps: 10 })
		await page.mouse.up()
		const clamped = await page.locator('.card-modal__discussion').boundingBox()
		expect(clamped.width).toBeGreaterThanOrEqual(279)
	})

	test('the chosen width persists across reload', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal__resizer', { timeout: 15_000 })
		// Reset to a known width, then reopen so the modal starts from the default.
		await page.evaluate((k) => { try { localStorage.removeItem(k) } catch (e) {} }, WIDTH_KEY)
		await page.reload()
		await page.waitForSelector('.card-modal__resizer', { timeout: 15_000 })

		// Drag to widen the discussion pane.
		const handle = await page.locator('.card-modal__resizer').boundingBox()
		const cx = handle.x + handle.width / 2
		const cy = handle.y + handle.height / 2
		await page.mouse.move(cx, cy)
		await page.mouse.down()
		await page.mouse.move(cx - 100, cy, { steps: 10 })
		await page.mouse.up()

		const widened = await page.locator('.card-modal__discussion').boundingBox()

		// The width is written to localStorage under the shared key.
		const stored = await page.evaluate((k) => localStorage.getItem(k), WIDTH_KEY)
		expect(stored).not.toBeNull()
		expect(parseInt(stored, 10)).toBeGreaterThan(400)

		// Reload → the pane comes back at (approximately) the persisted width.
		await page.reload()
		await page.waitForSelector('.card-modal__discussion', { timeout: 15_000 })
		const restored = await page.locator('.card-modal__discussion').boundingBox()
		expect(Math.abs(restored.width - widened.width)).toBeLessThanOrEqual(6)
	})

	test('the handle is keyboard-accessible and arrow keys nudge the width', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.addInitScript(() => { try { localStorage.removeItem('kanso.cardDiscussionWidth') } catch (e) {} })
		await page.goto(state.cardUrl)
		const handle = page.locator('.card-modal__resizer')
		await handle.waitFor({ timeout: 15_000 })

		// a11y contract: role=separator, vertical orientation, focusable.
		await expect(handle).toHaveAttribute('role', 'separator')
		await expect(handle).toHaveAttribute('aria-orientation', 'vertical')
		await expect(handle).toHaveAttribute('tabindex', '0')

		const before = await page.locator('.card-modal__discussion').boundingBox()
		await handle.focus()
		// ArrowLeft grows the discussion pane.
		await handle.press('ArrowLeft')
		await handle.press('ArrowLeft')
		await handle.press('ArrowLeft')
		const after = await page.locator('.card-modal__discussion').boundingBox()
		expect(after.width).toBeGreaterThan(before.width)
	})

	test('below the 680px breakpoint the panes stack and the handle is inert', async ({ page }) => {
		await page.setViewportSize({ width: 500, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal__body', { timeout: 15_000 })

		// The resizer is hidden (display:none) in the stacked mobile layout.
		await expect(page.locator('.card-modal__resizer')).toBeHidden()
	})
})
