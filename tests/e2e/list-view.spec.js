// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Board List view (#3444)', () => {
	const state = { boardId: 0, title: 'List View ' + Math.floor(Date.now() / 1000), cardTitle: 'List row card' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: state.title })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		await api.post('/cards', { stackId: stack.id, title: state.cardTitle })
		// A second card with a due date well in the past → the group header should
		// surface an "overdue" hint for it (variant 1d per-group hints).
		const overdue = await api.post('/cards', { stackId: stack.id, title: 'Overdue row card' })
		await api.patch(`/cards/${overdue.id}`, { duedate: '2020-01-01T00:00:00+00:00' })
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('switches to List, renders card rows, opens a card, and switches back', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		const setView = async (name) => {
			await page.locator('.board-view__display-menu button').first().click()
			await page.getByRole('menuitemradio', { name, exact: true }).click()
			// Close the popover so the next open() is a fresh open, not a toggle-shut.
			await page.keyboard.press('Escape')
		}

		// Switch to List → card renders as a row, Board columns hidden.
		await setView('List')
		const row = page.locator('.board-list-row', { hasText: state.cardTitle })
		await expect(row).toBeVisible({ timeout: 8_000 })
		await expect(page.locator('.board-view__stacks-wrap')).toBeHidden()

		// The group header surfaces a per-group overdue hint for the past-due card.
		await expect(page.locator('.board-list-group__hint--overdue', { hasText: 'overdue' }))
			.toBeVisible({ timeout: 8_000 })

		// Toggle back to Board → columns visible again (round-trip, no modal open).
		await setView('Board')
		await expect(page.locator('.board-view__stacks-wrap')).toBeVisible({ timeout: 8_000 })

		// Back to List, then open a card. dispatchEvent fires the handler directly:
		// the row is a virtualized item on a list that refreshes on the board poll,
		// so a coordinate click can race the re-render.
		await setView('List')
		await expect(row).toBeVisible({ timeout: 8_000 })
		await row.dispatchEvent('click')
		await expect(page).toHaveURL(new RegExp(`/board/${state.boardId}/card/`), { timeout: 8_000 })
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 10_000 })
	})

	test('collapsing a group hides its cards; expanding shows them again', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Switch to List view.
		await page.locator('.board-view__display-menu button').first().click()
		await page.getByText('List', { exact: true }).click()

		const row = page.locator('.board-list-row', { hasText: state.cardTitle })
		const group = page.locator('.board-list-group', { hasText: 'To do' })
		await expect(row).toBeVisible({ timeout: 8_000 })
		await expect(group).toBeVisible()

		// Collapse the group → its card row disappears from the virtualized list.
		await group.dispatchEvent('click')
		await expect(row).toBeHidden({ timeout: 8_000 })
		await expect(group).toBeVisible()

		// Expand again → the card row comes back.
		await group.dispatchEvent('click')
		await expect(row).toBeVisible({ timeout: 8_000 })
	})
})

test.describe('List view — subtask tree (#4178)', () => {
	// Fixture: one board, one stack, one parent card with two child cards.
	// The parent and its children are all in the same stack so the nesting applies.
	const state = {
		boardId: 0,
		parentId: 0,
		child1Id: 0,
		child2Id: 0,
		boardTitle: 'List Subtask ' + Math.floor(Date.now() / 1000),
	}

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: state.boardTitle })
		state.boardId = board.id
		const stack = await api('POST', '/stacks', { boardId: board.id, title: 'Tasks' })

		const parent = await api('POST', '/cards', { stackId: stack.id, title: 'Parent task' })
		state.parentId = parent.id

		// Create child cards by setting their parentCardId on creation (or patch
		// immediately after). Use PATCH since the cards endpoint may not accept
		// parentCardId at creation time.
		const child1 = await api('POST', '/cards', { stackId: stack.id, title: 'Sub-task Alpha' })
		state.child1Id = child1.id
		await api('PATCH', `/cards/${child1.id}`, { parentCardId: parent.id })

		const child2 = await api('POST', '/cards', { stackId: stack.id, title: 'Sub-task Beta' })
		state.child2Id = child2.id
		await api('PATCH', `/cards/${child2.id}`, { parentCardId: parent.id })
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	/** Navigate to the board and switch to List view. */
	async function openListView(page) {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await page.locator('.board-view__display-menu button').first().click()
		await page.getByRole('menuitemradio', { name: 'List', exact: true }).click()
		await page.keyboard.press('Escape')
		await page.waitForSelector('.board-list-row', { timeout: 10_000 })
	}

	test('children are rendered indented under their parent (default expanded)', async ({ page }) => {
		await openListView(page)

		const parentRow = page.locator('.board-list-row', { hasText: 'Parent task' })
		const child1Row = page.locator('.board-list-row', { hasText: 'Sub-task Alpha' })
		const child2Row = page.locator('.board-list-row', { hasText: 'Sub-task Beta' })

		// All three rows should be visible by default (subtasks expanded).
		await expect(parentRow).toBeVisible({ timeout: 8_000 })
		await expect(child1Row).toBeVisible({ timeout: 8_000 })
		await expect(child2Row).toBeVisible({ timeout: 8_000 })

		// Child rows must carry the --child modifier class for indentation.
		await expect(child1Row).toHaveClass(/board-list-row--child/)
		await expect(child2Row).toHaveClass(/board-list-row--child/)

		// Parent row must NOT carry the --child class.
		await expect(parentRow).not.toHaveClass(/board-list-row--child/)
	})

	test('parent row has a caret; clicking it collapses and expands its children', async ({ page }) => {
		await openListView(page)

		const parentRow = page.locator('.board-list-row', { hasText: 'Parent task' })
		const caret = parentRow.locator('.board-list-row__caret')
		const child1Row = page.locator('.board-list-row', { hasText: 'Sub-task Alpha' })
		const child2Row = page.locator('.board-list-row', { hasText: 'Sub-task Beta' })

		// Caret must be present and have an aria-label.
		await expect(caret).toBeVisible({ timeout: 8_000 })
		const label = await caret.getAttribute('aria-label')
		expect(['Expand subtasks', 'Collapse subtasks']).toContain(label)

		// Children visible → clicking caret collapses.
		await expect(child1Row).toBeVisible()
		await caret.dispatchEvent('click')
		await expect(child1Row).toBeHidden({ timeout: 8_000 })
		await expect(child2Row).toBeHidden({ timeout: 8_000 })

		// Parent row stays visible when its children are collapsed.
		await expect(parentRow).toBeVisible()

		// Clicking caret again expands.
		await caret.dispatchEvent('click')
		await expect(child1Row).toBeVisible({ timeout: 8_000 })
		await expect(child2Row).toBeVisible({ timeout: 8_000 })
	})

	test('clicking the caret does not open the parent card modal', async ({ page }) => {
		await openListView(page)

		const parentRow = page.locator('.board-list-row', { hasText: 'Parent task' })
		const caret = parentRow.locator('.board-list-row__caret')

		await expect(caret).toBeVisible({ timeout: 8_000 })
		await caret.dispatchEvent('click')

		// The URL must NOT gain a /card/ segment (i.e. no navigation occurred).
		await page.waitForTimeout(500)
		expect(page.url()).not.toMatch(/\/card\//)
		await expect(page.locator('.card-modal')).toHaveCount(0)
	})

	test('clicking a child row opens the child card modal', async ({ page }) => {
		await openListView(page)

		const child1Row = page.locator('.board-list-row', { hasText: 'Sub-task Alpha' })
		await expect(child1Row).toBeVisible({ timeout: 8_000 })
		await child1Row.dispatchEvent('click')

		await expect(page).toHaveURL(
			new RegExp(`/board/${state.boardId}/card/${state.child1Id}`),
			{ timeout: 8_000 },
		)
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 10_000 })
	})
})
