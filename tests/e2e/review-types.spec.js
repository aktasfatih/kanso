// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE, me } from './helpers.js'

test.describe('Review types', () => {
	const state = {
		boardId: 0,
		stackId: 0,
		cardId: 0,
		reviewTypeId: 0,
		cardUrl: '',
		boardUrl: '',
		settingsUrl: '',
	}

	test.beforeAll(async () => {
		// Clean up any stale board from a previous run
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === 'Review Types E2E Board') {
				await api.delete(`/boards/${b.id}`)
			}
		}

		// Create board + stack + card via API
		const board = await api.post('/boards', { title: 'Review Types E2E Board' })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Backlog' })
		state.stackId = stack.id
		const card = await api.post('/cards', { stackId: stack.id, title: 'Card for Type Review' })
		state.cardId = card.id

		// Create a review type via the backend API
		const rt = await api.post('/review-types', {
			boardId: board.id,
			title: 'QA',
			color: '3498db', // bare hex - no leading #
		})
		state.reviewTypeId = rt.id

		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await api.delete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	// ── Settings panel: Review types tab renders the created type ───────────────

	test('review types tab in board settings shows the QA type', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

		// Open board settings (now in the consolidated ⋯ More overflow menu)
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()

		// Click the Review types tab
		await page.getByRole('tab', { name: /review types/i }).click()

		// The QA type should appear in the list
		const item = page.locator('.rt-settings__list .label-settings__item', { hasText: 'QA' })
		await expect(item).toHaveCount(1, { timeout: 8_000 })

		// Its swatch should be colored blue (3498db = rgb(52,152,219))
		const swatchBg = await item.locator('.label-settings__swatch')
			.evaluate((el) => getComputedStyle(el).backgroundColor)
		expect(swatchBg).toBe('rgb(52, 152, 219)')
	})

	// ── Settings panel: create a new review type via the UI form ────────────────

	test('can create a new review type via the settings panel', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /review types/i }).click()

		// Pick a color for the new type
		await page.getByRole('button', { name: /pick color for new review type/i }).click()
		// Pick the second preset (orange e67e22)
		await page.locator('.label-settings__color-option').nth(1).click()

		// Fill the name
		await page.getByLabel(/new review type name/i).fill('Legal')
		await page.getByRole('button', { name: /create review type/i }).click()

		// The new type should appear in the list without error
		const item = page.locator('.rt-settings__list .label-settings__item', { hasText: 'Legal' })
		await expect(item).toHaveCount(1, { timeout: 8_000 })
		await expect(page.locator('.label-settings__error')).toHaveCount(0)

		// Verify the server stored bare hex (no leading #)
		const boardPayload = await api.get(`/boards/${state.boardId}`)
		const savedType = boardPayload.reviewTypes.find((rt) => rt.title === 'Legal')
		expect(savedType?.color).toBe('e67e22')
	})

	// ── Card modal: requesting a review shows the type selector when types exist ─

	test('type selector appears in the request-review popover', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 12_000 })

		// Open the request-review popover - the "Request" pill lives in the
		// reviews cluster (.card-modal__attr-right) at the end of the attribute bar.
		await page.locator('.card-modal__attr-right button.card-modal__pill', { hasText: 'Request' }).click()

		// The type selector row should be visible with at least the QA type
		const selector = page.locator('.card-modal__review-type-selector')
		await expect(selector).toBeVisible({ timeout: 6_000 })
		await expect(selector.locator('.card-modal__review-type-option', { hasText: 'QA' })).toHaveCount(1)
		// "Review" (no-type) option should also be present
		await expect(selector.locator('.card-modal__review-type-option', { hasText: 'Review' })).toHaveCount(1)
	})

	// ── Card modal: requesting a review with a type shows the type badge on the chip

	test('review chip shows the type name when reviewTypeId is set', async ({ page }) => {
		await ncLogin(page)

		// Pre-create a typed review via API so we can verify the chip without
		// needing to interact with the popover (avoids participant-picker complexity).
		await api.raw('PUT', `/cards/${state.cardId}/reviews/${me}`, { reviewTypeId: state.reviewTypeId })

		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 12_000 })

		// A pending review chip should be present
		const chip = page.locator('.card-modal__review-pill--pending')
		await expect(chip).toBeVisible({ timeout: 8_000 })

		// And the type badge with the "QA" label should appear inside it
		const typeBadge = chip.locator('.card-modal__review-type-badge')
		await expect(typeBadge).toBeVisible({ timeout: 6_000 })
		await expect(typeBadge).toContainText('QA')
	})
})
