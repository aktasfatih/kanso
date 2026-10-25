// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Project colour indicator: a card that belongs to a coloured project shows
// that project's colour on the board, so the user can tell at a glance which
// project a card belongs to. The tile renders a small dot per coloured project
// (the project title is on hover); a card in no project — or only in
// uncoloured projects — shows nothing.

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Project colour indicator on the board tile', () => {
	const state = { boardId: 0, cardId: 0, projectId: 0 }
	const COLOR = 'e53935'

	test.beforeAll(async () => {
		const ts = Date.now()
		const board = await api.post('/boards', { title: `ProjColor Board ${ts}` })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api.post('/cards', { stackId: stack.id, title: `Colored project card ${ts}` })
		state.cardId = card.id

		const project = await api.post('/projects', { title: `Red Initiative ${ts}`, color: COLOR })
		state.projectId = project.id
		await api.put(`/projects/${project.id}/cards/${card.id}`)
	})

	test.afterAll(async () => {
		if (state.projectId) await api.delete(`/projects/${state.projectId}`).catch(() => {})
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('the card tile shows the project colour and the uncoloured card shows none', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)

		// The board payload carries the project (id + colour) and the card's
		// projectIds — the server-side contract the tile resolves against.
		const board = await api.get(`/boards/${state.boardId}`)
		const card = board.cards.find((c) => c.id === state.cardId)
		expect(card.projectIds).toContain(state.projectId)
		const project = board.projects.find((p) => p.id === state.projectId)
		expect(project.color).toBe(COLOR)

		// The tile renders a dot for the coloured project, tinted with it.
		const tile = page.locator('.card-tile', { hasText: 'Colored project card' })
		await expect(tile).toBeVisible()
		const dot = tile.locator('.card-tile__project-dot')
		await expect(dot).toHaveCount(1)
		await expect(dot).toHaveCSS('background-color', `rgb(${parseInt(COLOR.slice(0, 2), 16)}, ${parseInt(COLOR.slice(2, 4), 16)}, ${parseInt(COLOR.slice(4, 6), 16)})`)
		// The project title is the hover tooltip.
		await expect(dot).toHaveAttribute('title', new RegExp('Red Initiative'))
	})
})
