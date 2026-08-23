// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #3665 — the attribute pickers in the card view (Assign, priority, type, due,
// estimate, cover, status, labels…) all close on a click OUTSIDE the popover, via
// one shared document-level mousedown handler keyed on openPicker. This exercises:
//   - open a picker → click a neutral spot in the modal → popover closes
//   - open a picker → click an option → applies AND closes
//   - open the due picker → click inside the date input → stays open
//   - open a picker → Escape closes the PICKER, not the whole card (picker-first)
//   - open a picker → outside click closes the PICKER, not the whole card

import { test, expect, BASE, api, ncLogin } from './helpers.js'

test.describe('Card picker click-outside dismiss (#3665)', () => {
	const BOARD_TITLE = 'Picker Outside Board ' + Date.now()
	const state = { boardId: 0, stackId: 0, cardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title.startsWith('Picker Outside Board')) {
				await api.delete(`/boards/${b.id}`)
			}
		}
		const board = await api.post('/boards', { title: BOARD_TITLE })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Backlog' })
		state.stackId = stack.id
		const card = await api.post('/cards', { stackId: stack.id, title: 'Picker Outside Card' })
		state.cardId = card.id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await api.delete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	async function openCardModal(page) {
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })
		const tile = page.locator('.card-tile').filter({ hasText: 'Picker Outside Card' })
		await expect(tile).toBeVisible({ timeout: 5000 })
		await tile.click()
		await page.waitForSelector('.card-modal', { timeout: 10_000 })
	}

	test('open Assign → click a neutral spot in the modal → popover closes', async ({ page }) => {
		await ncLogin(page)
		await openCardModal(page)

		const assignPill = page.locator('.card-modal__attrbar button.card-modal__pill', { hasText: 'Assign' })
		await expect(assignPill).toBeVisible({ timeout: 5000 })
		await assignPill.click()

		// Popover is open.
		const popover = page.locator('.card-modal__popover')
		await expect(popover.first()).toBeVisible({ timeout: 3000 })

		// Click a neutral area of the modal (the header) — outside popover + trigger.
		await page.locator('.card-modal__header').click()

		// Popover closes; the card modal stays open.
		await expect(page.locator('.card-modal__popover')).toHaveCount(0, { timeout: 3000 })
		await expect(page.locator('.card-modal')).toBeVisible()
	})

	test('open priority → click an option → applies AND closes', async ({ page }) => {
		await ncLogin(page)
		await openCardModal(page)

		const attrbar = page.locator('.card-modal__attrbar')
		const priorityPill = attrbar.locator('button.card-modal__pill').first()
		await priorityPill.click()
		await page.locator('.card-modal__popover .card-modal__popover-opt', { hasText: /^High$/ }).click()

		// Selection applied…
		await expect(attrbar.locator('.card-modal__pill--priority-3')).toBeVisible({ timeout: 5000 })
		// …and the popover closed as part of the same click.
		await expect(page.locator('.card-modal__popover')).toHaveCount(0, { timeout: 3000 })
	})

	test('open due → click inside the date input → stays open', async ({ page }) => {
		await ncLogin(page)
		await openCardModal(page)

		const duePill = page.locator('.card-modal__attrbar button[data-pill="due"]')
		await expect(duePill).toBeVisible({ timeout: 5000 })
		await duePill.click()

		const dateInput = page.locator('.card-modal__popover .card-modal__date-input').first()
		await expect(dateInput).toBeVisible({ timeout: 3000 })

		// Clicking inside the popover's own input must NOT dismiss it.
		await dateInput.click()
		await expect(page.locator('.card-modal__popover')).toBeVisible()
		await expect(dateInput).toBeVisible()
	})

	test('picker open → Escape closes the picker, not the whole card', async ({ page }) => {
		await ncLogin(page)
		await openCardModal(page)

		const priorityPill = page.locator('.card-modal__attrbar button.card-modal__pill').first()
		await priorityPill.click()
		await expect(page.locator('.card-modal__popover').first()).toBeVisible({ timeout: 3000 })

		await page.keyboard.press('Escape')

		// Picker-first precedence: picker gone, card still open.
		await expect(page.locator('.card-modal__popover')).toHaveCount(0, { timeout: 3000 })
		await expect(page.locator('.card-modal')).toBeVisible()

		// A second Escape now closes the card.
		await page.keyboard.press('Escape')
		await page.waitForSelector('.card-modal', { state: 'hidden', timeout: 5000 }).catch(() => {})
		await expect(page.locator('.card-modal')).toHaveCount(0)
	})

	test('picker open → click on the modal backdrop closes the picker, not the card', async ({ page }) => {
		await ncLogin(page)
		await openCardModal(page)

		const priorityPill = page.locator('.card-modal__attrbar button.card-modal__pill').first()
		await priorityPill.click()
		await expect(page.locator('.card-modal__popover').first()).toBeVisible({ timeout: 3000 })

		// The dismiss handler is a document-level mousedown (capture) that keys on
		// event.target being the dark backdrop (.modal-wrapper). Dispatch that exact
		// event on the wrapper — Playwright's .click() would refuse it as the modal
		// header overlaps the wrapper's own bounding box, but the real handler only
		// cares about the mousedown target, not pointer geometry.
		const fireWrapperMousedown = () => page.evaluate(() => {
			const wrapper = document.querySelector('.card-modal-modal .modal-wrapper')
			wrapper.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }))
		})

		// Picker-first precedence: the backdrop mousedown clears the picker only.
		await fireWrapperMousedown()
		await expect(page.locator('.card-modal__popover')).toHaveCount(0, { timeout: 3000 })
		await expect(page.locator('.card-modal')).toBeVisible()

		// With no picker open, a second backdrop mousedown now closes the card.
		await fireWrapperMousedown()
		await page.waitForSelector('.card-modal', { state: 'hidden', timeout: 5000 }).catch(() => {})
		await expect(page.locator('.card-modal')).toHaveCount(0)
	})
})
