// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// #3678 — Import cards from a CSV into an EXISTING board's stack via the
// board-list Import menu, then assert the mapped cards landed on that stack.
test.describe('Import cards from CSV', () => {
	const stamp = Math.floor(Date.now() / 1000)
	const state = {}

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'CSV Target ' + stamp })
		state.boardId = board.id
		state.boardTitle = board.title
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Inbox' })
		state.stackId = stack.id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('pastes a CSV, maps columns, and creates the cards in the chosen stack', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await page.waitForSelector('.board-list-view', { timeout: 15_000 })

		// Open the Import menu and pick "CSV file".
		await page.getByRole('button', { name: 'Import' }).click()
		await page.getByText('CSV file', { exact: true }).click()

		// Step 1: paste a small CSV (header + three data rows) and continue.
		const csv = [
			'title,description,due date,labels',
			'Design login,Wireframe the flow,2026-02-01,ux',
			'Build API,,,backend',
			'Ship it,Final polish,,',
		].join('\n')
		await page.locator('[data-test="csv-import-paste"]').fill(csv)
		await page.locator('[data-test="csv-import-next"]').click()

		// Step 2: choose the target board + its stack. (Auto-detection already
		// mapped title/description/due/labels from the header row.)
		await page.locator('[data-test="csv-import-board"]').selectOption({ label: state.boardTitle })
		await page.locator('[data-test="csv-import-stack"]').selectOption({ label: 'Inbox' })

		await page.locator('[data-test="csv-import-submit"]').click()

		// The FE navigates to the populated board once the import returns.
		await page.waitForURL(new RegExp(`#/board/${state.boardId}\\b`), { timeout: 20_000 })

		// Assert the three cards landed on the Inbox stack, in file order, with the
		// mapped description + match-or-created labels.
		const payload = await api.get(`/boards/${state.boardId}`)
		const cards = payload.cards
			.filter((c) => c.stackId === state.stackId)
			.slice()
			.sort((a, b) => (a.sortKey < b.sortKey ? -1 : 1))
		expect(cards.map((c) => c.title)).toEqual(['Design login', 'Build API', 'Ship it'])

		const byTitle = Object.fromEntries(cards.map((c) => [c.title, c]))
		// The labels column auto-created "ux" + "backend" on the board.
		const labelByTitle = Object.fromEntries(payload.labels.map((l) => [l.title, l.id]))
		expect(labelByTitle.ux).toBeTruthy()
		expect(labelByTitle.backend).toBeTruthy()
		expect(byTitle['Design login'].labelIds).toContain(labelByTitle.ux)
		expect(byTitle['Build API'].labelIds).toContain(labelByTitle.backend)

		// The mapped description + due date came across for the first card.
		const detail = await api.get(`/cards/${byTitle['Design login'].id}`)
		expect(detail.description).toBe('Wireframe the flow')
		expect(byTitle['Design login'].duedate).toBeTruthy()
	})
})
