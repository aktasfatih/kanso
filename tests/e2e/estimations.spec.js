// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

const rawPatch = (path, body) => api.raw('PATCH', path, body)

test.describe('Card estimations (#3443)', () => {
	const state = { boardId: 0, stackId: 0, cardId: 0, title: '' }

	test.beforeAll(async () => {
		state.title = 'Estimate me ' + Math.floor(Date.now() / 1000)
		const board = await api.post('/boards', { title: 'Estimates ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'To do' })).id
		state.cardId = (await api.post('/cards', { stackId: state.stackId, title: state.title })).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('a board defaults to the none scale and can switch scales', async () => {
		let board = await api.get(`/boards/${state.boardId}`)
		expect(board.board.estimateScale).toBe('none')

		await api.patch(`/boards/${state.boardId}`, { estimateScale: 'fibonacci' })
		board = await api.get(`/boards/${state.boardId}`)
		expect(board.board.estimateScale).toBe('fibonacci')
	})

	test('an unknown scale is rejected', async () => {
		const r = await rawPatch(`/boards/${state.boardId}`, { estimateScale: 'made-up' })
		expect(r.ok).toBe(false)
	})

	test('a card estimate must belong to the board scale', async () => {
		// Board is fibonacci from the earlier test. A valid token sticks…
		await api.patch(`/cards/${state.cardId}`, { estimate: '8' })
		expect((await api.get(`/cards/${state.cardId}`)).estimate).toBe('8')

		// …an off-scale token is rejected…
		expect((await rawPatch(`/cards/${state.cardId}`, { estimate: '4' })).ok).toBe(false)

		// …and '' clears it.
		await api.patch(`/cards/${state.cardId}`, { estimate: '' })
		expect((await api.get(`/cards/${state.cardId}`)).estimate).toBeNull()
	})

	test('set an estimate from the card modal → chip shows on the tile', async ({ page }) => {
		await api.patch(`/boards/${state.boardId}`, { estimateScale: 'fibonacci' })
		await api.patch(`/cards/${state.cardId}`, { estimate: '' })

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.cardId}`)
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 15_000 })

		// The estimate pill renders because the board scale is not 'none'.
		const estimatePill = page.locator('.card-modal__attrbar button.card-modal__pill', { hasText: 'Estimate' })
		await expect(estimatePill).toBeVisible({ timeout: 8_000 })

		// Open the estimate popover and click the "8" token (exact text so it
		// doesn't match "13"/"21").
		await estimatePill.click()
		const btn8 = page.locator('.card-modal__popover .card-modal__popover-opt', { hasText: /^8$/ })
		await btn8.click()

		// The pill now reflects the chosen estimate.
		await expect(page.locator('.card-modal__attrbar button.card-modal__pill', { hasText: 'Estimate: 8' }))
			.toBeVisible({ timeout: 6_000 })

		// Close the modal → the tile shows the estimate chip.
		await page.keyboard.press('Escape')
		const tile = page.locator('.card-tile', { hasText: state.title })
		await expect(tile.locator('.card-tile__estimate')).toHaveText('8', { timeout: 8_000 })
	})

	test('switching scale warns and clears estimates that no longer fit', async ({ page }) => {
		// Deterministic start: fibonacci board with an off-scale-for-tshirt token.
		await api.patch(`/boards/${state.boardId}`, { estimateScale: 'fibonacci' })
		await api.patch(`/cards/${state.cardId}`, { estimate: '8' })

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		// Board settings now lives in the consolidated ⋯ More overflow menu.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /workflow/i }).click()

		const select = page.locator(`#estimate-scale-${state.boardId}`)
		await expect(select).toBeVisible({ timeout: 8_000 })
		await select.selectOption('tshirt')

		// The confirmation names the affected count; confirm the destructive change.
		await expect(page.getByText(/does not fit the new scale and will be cleared/i))
			.toBeVisible({ timeout: 8_000 })
		await page.getByRole('button', { name: 'Change and clear' }).click()

		await expect.poll(async () => (await api.get(`/boards/${state.boardId}`)).board.estimateScale, { timeout: 8_000 })
			.toBe('tshirt')
		// The off-scale '8' was cleared server-side by the scale change.
		await expect.poll(async () => (await api.get(`/cards/${state.cardId}`)).estimate, { timeout: 8_000 })
			.toBeNull()
	})

	test('cancelling the scale-change confirmation keeps scale and estimates', async ({ page }) => {
		// Board is tshirt from the previous test; give the card a valid tshirt token.
		await api.patch(`/boards/${state.boardId}`, { estimateScale: 'tshirt' })
		await api.patch(`/cards/${state.cardId}`, { estimate: 'M' })

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /workflow/i }).click()

		const select = page.locator(`#estimate-scale-${state.boardId}`)
		await expect(select).toBeVisible({ timeout: 8_000 })
		// 'M' does not fit fibonacci → the confirmation appears; cancel it.
		await select.selectOption('fibonacci')
		await expect(page.getByText(/does not fit the new scale and will be cleared/i))
			.toBeVisible({ timeout: 8_000 })
		await page.getByRole('button', { name: 'Cancel' }).click()

		// Nothing changed: scale still tshirt, estimate still 'M', select reverted.
		await expect(select).toHaveValue('tshirt', { timeout: 8_000 })
		expect((await api.get(`/boards/${state.boardId}`)).board.estimateScale).toBe('tshirt')
		expect((await api.get(`/cards/${state.cardId}`)).estimate).toBe('M')
	})
})

test.describe('Estimate sorting & filtering', () => {
	const state = { boardId: 0, stackId: 0, none: 0, small: 0, big: 0 }

	test.beforeAll(async () => {
		const stamp = Math.floor(Date.now() / 1000)
		const board = await api.post('/boards', { title: 'EstSortFilter ' + stamp })
		state.boardId = board.id
		await api.patch(`/boards/${board.id}`, { estimateScale: 'fibonacci' })
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'To do' })).id

		// Create in an order that is NOT the estimate order, so a passing sort
		// assertion can only come from the estimate ranking (not creation order):
		// manual order is None → Small(2) → Big(13); estimate-desc is Big → Small → None.
		state.none = (await api.post('/cards', { stackId: state.stackId, title: 'EstNone' })).id
		state.small = (await api.post('/cards', { stackId: state.stackId, title: 'EstSmall' })).id
		await api.patch(`/cards/${state.small}`, { estimate: '2' })
		state.big = (await api.post('/cards', { stackId: state.stackId, title: 'EstBig' })).id
		await api.patch(`/cards/${state.big}`, { estimate: '13' })
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('sort by estimate orders cards high→low with unestimated last', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Manual (persisted) order first: None, Small, Big.
		const titlesManual = (await page.locator('.card-tile__title').allTextContents()).map((s) => s.trim())
		expect(titlesManual.indexOf('EstNone')).toBeLessThan(titlesManual.indexOf('EstSmall'))
		expect(titlesManual.indexOf('EstSmall')).toBeLessThan(titlesManual.indexOf('EstBig'))

		// Switch the display sort to Estimate (radio only present because the board
		// has a scale). The estimate ranks by scale position, not string value.
		await page.locator('.board-view__display-menu button').first().click()
		await page.locator('.action-radio__text', { hasText: 'Estimate' }).click()
		await page.keyboard.press('Escape')

		// Estimate defaults to Descending: Big(13) → Small(2) → None(unestimated, last).
		await expect.poll(async () => {
			const t = (await page.locator('.card-tile__title').allTextContents()).map((s) => s.trim())
			return t.indexOf('EstBig') < t.indexOf('EstSmall') && t.indexOf('EstSmall') < t.indexOf('EstNone')
		}, { timeout: 8_000 }).toBe(true)

		// Reopening the menu shows the active mode as selected (not blank).
		await page.locator('.board-view__display-menu button').first().click()
		await expect(page.getByRole('menuitemradio', { name: 'Estimate' }))
			.toHaveAttribute('aria-checked', 'true', { timeout: 8_000 })

		// Flip to Ascending (single click, in the already-open menu): Small(2) →
		// Big(13), with unestimated STILL last.
		await page.locator('.action-radio__text', { hasText: 'Ascending' }).click()
		await page.keyboard.press('Escape')
		await expect.poll(async () => {
			const t = (await page.locator('.card-tile__title').allTextContents()).map((s) => s.trim())
			return t.indexOf('EstSmall') < t.indexOf('EstBig') && t.indexOf('EstBig') < t.indexOf('EstNone')
		}, { timeout: 8_000 }).toBe(true)
	})

	test('filter by estimate token, and by "Unestimated"', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Filter to just the "13" token → only EstBig remains (drill into Estimate, #3785).
		await page.locator('.board-filter-bar__filter button').first().click()
		await page.locator('.board-filter-bar__dim-row[data-dim="estimates"]').click()
		await page.locator('.board-filter-bar__opt-text', { hasText: /^13$/ }).click()
		await page.keyboard.press('Escape')
		await expect(page.locator('.card-tile__title', { hasText: 'EstBig' })).toHaveCount(1)
		await expect(page.locator('.card-tile__title', { hasText: 'EstSmall' })).toHaveCount(0)
		await expect(page.locator('.card-tile__title', { hasText: 'EstNone' })).toHaveCount(0)
		// The estimate facet round-trips through the URL (shareable).
		await expect.poll(() => page.url()).toContain('fe=13')

		// Swap to the "Unestimated" facet → only EstNone remains.
		await page.locator('.board-filter-bar__filter button').first().click()
		await page.locator('.board-filter-bar__dim-row[data-dim="estimates"]').click()
		await page.locator('.board-filter-bar__opt-text', { hasText: /^13$/ }).click() // uncheck 13
		await page.locator('.board-filter-bar__opt-text', { hasText: 'Unestimated' }).click()
		await page.keyboard.press('Escape')
		await expect(page.locator('.card-tile__title', { hasText: 'EstNone' })).toHaveCount(1)
		await expect(page.locator('.card-tile__title', { hasText: 'EstBig' })).toHaveCount(0)
		await expect(page.locator('.card-tile__title', { hasText: 'EstSmall' })).toHaveCount(0)
	})
})
