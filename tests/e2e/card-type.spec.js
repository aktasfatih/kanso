// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Card types (#3402): exactly one built-in type per card (bug/feature/task/
// chore), icon-first on the tile, pickable in the modal, filterable in the bar.

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Card types', () => {
	const BOARD_TITLE = 'Type Test Board ' + Date.now()
	const state = {
		boardId: 0,
		stackId: 0,
		bugCardId: 0,
		featureCardId: 0,
		boardUrl: '',
	}

	test.beforeAll(async () => {
		// Tear down any prior board with the same title prefix for hermeticity
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title.startsWith('Type Test Board')) {
				await api.delete(`/boards/${b.id}`)
			}
		}

		const board = await api.post('/boards', { title: BOARD_TITLE })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Backlog' })
		state.stackId = stack.id

		const bugCard = await api.post('/cards', { stackId: stack.id, title: 'Bug Type Card' })
		state.bugCardId = bugCard.id
		const featureCard = await api.post('/cards', { stackId: stack.id, title: 'Feature Type Card' })
		state.featureCardId = featureCard.id

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await api.delete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	// The Type pill is identified by its text; the popover exposes the built-in
	// options. Locates the pill by the "Type" label (dashed placeholder state).
	function typePill(page) {
		return page.locator('.card-modal__attrbar button.card-modal__pill', { hasText: 'Type' })
	}

	test('set type to Bug via the card modal UI; assert tile shows the type icon', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		const cardTile = page.locator('.card-tile').filter({ hasText: 'Bug Type Card' })
		await expect(cardTile).toBeVisible()
		await cardTile.click()

		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		const pill = typePill(page)
		await expect(pill).toBeVisible()
		await pill.click()

		// Pick "Bug" from the type popover
		await page.locator('.card-modal__popover .card-modal__popover-opt', { hasText: 'Bug' }).click()

		// The pill should pick up the --type-bug modifier
		await expect(page.locator('.card-modal__attrbar .card-modal__pill--type-bug'))
			.toBeVisible()

		await page.keyboard.press('Escape')
		await page.waitForSelector('.card-modal', { state: 'hidden', timeout: 5000 }).catch(() => {})

		// The tile should now show the bug type icon
		const typeIcon = page.locator('.card-tile')
			.filter({ hasText: 'Bug Type Card' })
			.locator('.card-tile__type--bug')
		await expect(typeIcon).toBeVisible()
	})

	test('set type to Feature on the second card; assert its tile icon', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		const featureTile = page.locator('.card-tile').filter({ hasText: 'Feature Type Card' })
		await expect(featureTile).toBeVisible()
		await featureTile.click()

		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		const pill = typePill(page)
		await expect(pill).toBeVisible()
		await pill.click()
		await page.locator('.card-modal__popover .card-modal__popover-opt', { hasText: 'Feature' }).click()
		await expect(page.locator('.card-modal__attrbar .card-modal__pill--type-feature'))
			.toBeVisible()

		await page.keyboard.press('Escape')
		await page.waitForSelector('.card-modal', { state: 'hidden', timeout: 5000 }).catch(() => {})

		const featureIcon = page.locator('.card-tile')
			.filter({ hasText: 'Feature Type Card' })
			.locator('.card-tile__type--feature')
		await expect(featureIcon).toBeVisible()
	})

	test('filter to Bug only - Feature card is hidden; clear filter restores it', async ({ page }) => {
		// Own the types this filters on: a retry re-runs only this test, so the two
		// modal tests that set them never ran. Setting the same type again is a no-op.
		await api.patch(`/cards/${state.bugCardId}`, { type: 'bug' })
		await api.patch(`/cards/${state.featureCardId}`, { type: 'feature' })

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		// Both cards visible initially
		await expect(page.locator('.card-tile').filter({ hasText: 'Bug Type Card' }))
			.toBeVisible()
		await expect(page.locator('.card-tile').filter({ hasText: 'Feature Type Card' }))
			.toBeVisible()

		// Open the filter popover and drill into the Type dimension (#3785).
		const filterMenu = page.locator('.board-filter-bar__filter button').first()
		await expect(filterMenu).toBeVisible()
		await filterMenu.click()
		await page.locator('.board-filter-bar__dim-row[data-dim="types"]').click()

		// Check the "Bug" type filter
		const bugFilter = page.locator('.board-filter-bar__type-item--bug')
		await expect(bugFilter).toBeVisible()
		await bugFilter.click()

		await page.keyboard.press('Escape')
		await page.waitForTimeout(300)

		// Bug card visible; Feature card hidden
		await expect(page.locator('.card-tile').filter({ hasText: 'Bug Type Card' }))
			.toBeVisible()
		await expect(page.locator('.card-tile').filter({ hasText: 'Feature Type Card' }))
			.not.toBeVisible({ timeout: 5000 })

		// Clear the filter by re-opening, drilling into Type, and unchecking Bug
		await filterMenu.click()
		await page.locator('.board-filter-bar__dim-row[data-dim="types"]').click()
		const bugAgain = page.locator('.board-filter-bar__type-item--bug')
		await expect(bugAgain).toBeVisible()
		await bugAgain.click()
		await page.keyboard.press('Escape')
		await page.waitForTimeout(300)

		// Both visible again
		await expect(page.locator('.card-tile').filter({ hasText: 'Bug Type Card' }))
			.toBeVisible()
		await expect(page.locator('.card-tile').filter({ hasText: 'Feature Type Card' }))
			.toBeVisible()
	})

	test('type persists after page reload', async ({ page }) => {
		// Own the whole round trip. A retry re-runs only this test, so the modal
		// test that set the type never ran — clear it over the API ('' is "no
		// type"), then set it through the UI below, so the reload proves a UI write
		// really reached the server rather than that a seeded row renders.
		await api.patch(`/cards/${state.bugCardId}`, { type: '' })

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		// Set the type to Bug through the modal, exactly as the first test does.
		const cardTile = page.locator('.card-tile').filter({ hasText: 'Bug Type Card' })
		await expect(cardTile).toBeVisible()
		await cardTile.click()

		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		const pill = typePill(page)
		await expect(pill).toBeVisible()
		await pill.click()
		await page.locator('.card-modal__popover .card-modal__popover-opt', { hasText: 'Bug' }).click()
		await expect(page.locator('.card-modal__attrbar .card-modal__pill--type-bug'))
			.toBeVisible()

		await page.keyboard.press('Escape')
		await page.waitForSelector('.card-modal', { state: 'hidden', timeout: 5000 }).catch(() => {})

		await page.reload()
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		// The Bug Type Card tile should still carry the bug type icon
		const bugIcon = page.locator('.card-tile')
			.filter({ hasText: 'Bug Type Card' })
			.locator('.card-tile__type--bug')
		await expect(bugIcon).toBeVisible()

		// The modal pill should still carry the --type-bug modifier
		const bugTile = page.locator('.card-tile').filter({ hasText: 'Bug Type Card' })
		await bugTile.click()
		await page.waitForSelector('.card-modal', { timeout: 15_000 })
		await expect(page.locator('.card-modal__attrbar .card-modal__pill--type-bug'))
			.toBeVisible()
	})
})
