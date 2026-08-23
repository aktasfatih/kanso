// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// #3569 — Boards page grid v1.
test.describe('Boards page — grid v1', () => {
	const state = { active: 0, archived: 0, stamp: Math.floor(Date.now() / 1000) }

	test.beforeAll(async () => {
		// An active board with a card, so the meta line has a non-zero count.
		const active = await api.post('/boards', { title: 'Grid Active ' + state.stamp })
		state.active = active.id
		const stack = await api.post('/stacks', { boardId: active.id, title: 'To Do' })
		await api.post('/cards', { stackId: stack.id, title: 'A card' })

		// A second board that we archive, to exercise the Active/Archived toggle.
		const arch = await api.post('/boards', { title: 'Grid Archived ' + state.stamp })
		state.archived = arch.id
		await api.patch(`/boards/${arch.id}`, { archived: true })
	})

	test.afterAll(async () => {
		if (state.active) await api.delete(`/boards/${state.active}`).catch(() => {})
		if (state.archived) await api.delete(`/boards/${state.archived}`).catch(() => {})
	})

	test('renders the grid with stat meta, search filters, and the toggle switches sets', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await page.waitForSelector('.board-list-view', { timeout: 15_000 })

		// The grid renders with tiles.
		await expect(page.locator('.board-grid').first()).toBeVisible({ timeout: 10_000 })
		const activeTile = page.locator('.board-tile', { hasText: 'Grid Active ' + state.stamp })
		await expect(activeTile.first()).toBeVisible({ timeout: 10_000 })

		// The tile shows the stats meta line: a card count and a progress bar.
		await expect(activeTile.first().locator('.board-tile__meta')).toContainText(/card/)
		await expect(activeTile.first().locator('.board-tile__progress-track')).toBeVisible()

		// Search filters the active set down.
		const searchBox = page.locator('.board-list-search__input')
		await searchBox.fill('Grid Active ' + state.stamp)
		await expect(page.locator('.board-tile', { hasText: 'Grid Active ' + state.stamp }).first())
			.toBeVisible({ timeout: 6_000 })
		await searchBox.fill('zzz-no-such-board-' + state.stamp)
		// Target the search-empty message specifically: a leftover empty folder
		// (from board-groups.spec) also renders a .board-section__empty node.
		await expect(page.getByText('No boards match your search.')).toBeVisible({ timeout: 6_000 })
		await searchBox.fill('')

		// The Active/Archived toggle switches the visible set.
		await expect(page.locator('.board-tile__title', { hasText: 'Grid Archived ' + state.stamp }))
			.toHaveCount(0)
		await page.getByRole('button', { name: /Archived/ }).click()
		await expect(page.locator('.board-tile__title', { hasText: 'Grid Archived ' + state.stamp }).first())
			.toBeVisible({ timeout: 6_000 })
		// Active board is not in the archived set.
		await expect(page.locator('.board-tile__title', { hasText: 'Grid Active ' + state.stamp }))
			.toHaveCount(0)
	})
})
