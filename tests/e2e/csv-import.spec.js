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

		// The modal reports what landed instead of navigating away silently
		// (#10297): three cards created, and nothing skipped for this CSV.
		const summary = page.locator('[data-test="csv-import-summary"]')
		await expect(summary).toBeVisible({ timeout: 20_000 })
		await expect(summary).toContainText('3 cards created')
		await expect(page.locator('[data-test="csv-import-skipped-rows"]')).toHaveCount(0)
		await expect(page.locator('[data-test="csv-import-labels-skipped"]')).toHaveCount(0)

		// Clicking through from the summary is what navigates to the board.
		await page.locator('[data-test="csv-import-open"]').click()
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

// #10297 — an EDIT-only member's unknown label names are dropped (minting a
// label definition needs MANAGE). That was computed and thrown away, so the
// member saw a plain success and never learned their labels were missing.
test.describe('CSV import reports labels an editor could not create', () => {
	// A second identity logs in explicitly, so this describe must NOT inherit the
	// shared admin storageState — admin HAS manage, so it would false-pass. What
	// actually guarantees that is the explicit `browser.newContext()` below (a
	// fresh context with no stored session); under E2E_ISOLATE the storageState
	// FIXTURE in helpers.js outranks a describe-level `test.use`, so this line is
	// belt-and-braces for the `page` fixture, not the load-bearing part.
	test.use({ storageState: { cookies: [], origins: [] } })

	const stamp = Math.floor(Date.now() / 1000)
	const state = {}

	test.beforeAll(async ({ peer }) => {
		const board = await api.post('/boards', { title: 'CSV Editor ' + stamp })
		state.boardId = board.id
		state.boardTitle = board.title
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Inbox' })
		state.stackId = stack.id
		// READ | EDIT (3) — the import is allowed, creating a label is not.
		await api.post(`/boards/${board.id}/acl`, {
			participant: peer.user,
			participantType: 'user',
			permission: 3,
		})
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('an editor is told the labels were not created', async ({ browser, peer }) => {
		const ctx = await browser.newContext()
		try {
			const page = await ctx.newPage()
			await ncLogin(page, { user: peer.user, pass: peer.pass })
			await page.goto(`${BASE}/index.php/apps/kanso#/`)
			await page.waitForSelector('.board-list-view', { timeout: 15_000 })

			await page.getByRole('button', { name: 'Import' }).click()
			await page.getByText('CSV file', { exact: true }).click()

			await page.locator('[data-test="csv-import-paste"]').fill([
				'title,labels',
				'Editor row one,brand-new-label',
				'Editor row two,brand-new-label',
			].join('\n'))
			await page.locator('[data-test="csv-import-next"]').click()

			await page.locator('[data-test="csv-import-board"]').selectOption({ label: state.boardTitle })
			await page.locator('[data-test="csv-import-stack"]').selectOption({ label: 'Inbox' })
			await page.locator('[data-test="csv-import-submit"]').click()

			// The cards land, but the modal says the label was NOT created.
			await expect(page.locator('[data-test="csv-import-summary"]'))
				.toContainText('2 cards created', { timeout: 20_000 })
			await expect(page.locator('[data-test="csv-import-labels-skipped"]'))
				.toContainText('only board managers can add new labels')
		} finally {
			await ctx.close()
		}

		// …and the board really has no such label, so the warning is not cosmetic.
		const payload = await api.get(`/boards/${state.boardId}`)
		expect(payload.labels.map((l) => l.title)).not.toContain('brand-new-label')
		expect(payload.cards.map((c) => c.title).sort())
			.toEqual(['Editor row one', 'Editor row two'])
	})
})
