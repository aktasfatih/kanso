// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// ── Drag helper (mirrors dnd.spec.js): Pragmatic DnD needs incremental pointer moves ──
async function dragWithMouse(page, sourceLocator, targetLocator, targetPosition = 'top') {
	const srcBox = await sourceLocator.boundingBox()
	const tgtBox = await targetLocator.boundingBox()
	if (!srcBox || !tgtBox) throw new Error('Could not get bounding boxes for drag')

	const srcX = srcBox.x + srcBox.width / 2
	const srcY = srcBox.y + srcBox.height / 2
	const tgtX = tgtBox.x + tgtBox.width / 2
	const tgtY = targetPosition === 'top'
		? tgtBox.y + tgtBox.height * 0.2
		: tgtBox.y + tgtBox.height * 0.8

	await page.mouse.move(srcX, srcY)
	await page.mouse.down()
	const steps = 15
	for (let i = 1; i <= steps; i++) {
		await page.mouse.move(
			srcX + (tgtX - srcX) * (i / steps),
			srcY + (tgtY - srcY) * (i / steps),
			{ steps: 1 },
		)
		await page.waitForTimeout(20)
	}
	await page.waitForTimeout(150)
	await page.mouse.up()
	await page.waitForTimeout(500)
}

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

test.describe('List view — quick-add composer', () => {
	const state = { boardId: 0, stackId: 0, title: 'List Composer ' + Math.floor(Date.now() / 1000) }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: state.title })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Backlog' })
		state.stackId = stack.id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	/** Navigate to the board and switch to List view. */
	async function openListView(page) {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await page.locator('.board-view__display-menu button').first().click()
		await page.getByRole('menuitemradio', { name: 'List', exact: true }).click()
		await page.keyboard.press('Escape')
		// Wait for the group header to appear (even when there are no cards yet).
		await page.waitForSelector('.board-list-group', { timeout: 10_000 })
	}

	test('composer renders above the group cards and creates a card on Enter', async ({ page }) => {
		await openListView(page)

		// The "Add card…" input should be visible in the group's add row.
		const input = page.locator('.board-list-table .card-composer__input').first()
		await expect(input).toBeVisible({ timeout: 8_000 })

		// Type a title and press Enter.
		const newTitle = 'Quick add card ' + Date.now()
		await input.click()
		await input.fill(newTitle)
		await input.press('Enter')

		// The new card row should appear in the list view.
		const newRow = page.locator('.board-list-row', { hasText: newTitle })
		await expect(newRow).toBeVisible({ timeout: 10_000 })

		// The input should be cleared and refocused after creation.
		await expect(input).toHaveValue('')
	})

	test('composer is at the top of the group, above existing cards', async ({ page }) => {
		await openListView(page)

		// There should be at least one card in the stack from the previous test.
		// The composer row (add type) should appear before card rows in the DOM.
		const composerWrap = page.locator('.board-list-table .card-composer-wrap').first()
		const firstCardRow = page.locator('.board-list-row').first()

		await expect(composerWrap).toBeVisible({ timeout: 8_000 })

		// Verify the composer appears before the first card row in the DOM order
		// by checking their bounding boxes (composer Y < card Y).
		const composerBox = await composerWrap.boundingBox()
		const cardBox = await firstCardRow.boundingBox()
		expect(composerBox).not.toBeNull()
		expect(cardBox).not.toBeNull()
		expect(composerBox.y).toBeLessThanOrEqual(cardBox.y)
	})

	test('composer is hidden when the group is collapsed', async ({ page }) => {
		await openListView(page)

		const input = page.locator('.board-list-table .card-composer__input').first()
		await expect(input).toBeVisible({ timeout: 8_000 })

		// Collapse the group.
		const group = page.locator('.board-list-group', { hasText: 'Backlog' })
		await group.dispatchEvent('click')

		// The composer should no longer be visible (collapsed group drops all rows).
		await expect(input).toBeHidden({ timeout: 8_000 })

		// Expand again → composer reappears.
		await group.dispatchEvent('click')
		await expect(input).toBeVisible({ timeout: 8_000 })
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
		const board = await api.post('/boards', { title: state.boardTitle })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Tasks' })

		const parent = await api.post('/cards', { stackId: stack.id, title: 'Parent task' })
		state.parentId = parent.id

		// Create child cards by setting their parentCardId on creation (or patch
		// immediately after). Use PATCH since the cards endpoint may not accept
		// parentCardId at creation time.
		const child1 = await api.post('/cards', { stackId: stack.id, title: 'Sub-task Alpha' })
		state.child1Id = child1.id
		await api.put(`/cards/${child1.id}/parent`, { parentCardId: parent.id })

		const child2 = await api.post('/cards', { stackId: stack.id, title: 'Sub-task Beta' })
		state.child2Id = child2.id
		await api.put(`/cards/${child2.id}/parent`, { parentCardId: parent.id })
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
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

// ── List view — drag-and-drop card reordering ────────────────────────────────
// Verifies that top-level card rows are draggable in List view (manual sort) and
// that the BoardView card monitor moves the card + persists the new order.
//
// Scope:
//   - Only top-level card rows participate in DnD; child rows are inert.
//   - Only the per-board stacks path (classic mode, not cross-board groups).
//   - Only when sortMode === 'manual' (the default; other sorts are view-only).
//   - A drop never changes parentCardId — only stackId + sortKey.
test.describe('List view — drag-and-drop reorder', () => {
	// Fixture: one board with one stack, three cards L1 (top) → L2 → L3 (bottom).
	const state = {
		boardId: 0,
		stackId: 0,
		card1Id: 0,
		card2Id: 0,
		card3Id: 0,
		boardTitle: 'List DnD ' + Math.floor(Date.now() / 1000),
	}

	test.beforeAll(async () => {
		// Clean up any lingering fixture from a previous run.
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title.startsWith('List DnD')) {
				await api.delete(`/boards/${b.id}`).catch(() => {})
			}
		}

		const board = await api.post('/boards', { title: state.boardTitle })
		state.boardId = board.id

		const stack = await api.post('/stacks', { boardId: board.id, title: 'Column' })
		state.stackId = stack.id

		// Create in order so initial top-to-bottom render is L1, L2, L3.
		const c1 = await api.post('/cards', { stackId: stack.id, title: 'L1' })
		const c2 = await api.post('/cards', { stackId: stack.id, title: 'L2' })
		const c3 = await api.post('/cards', { stackId: stack.id, title: 'L3' })
		state.card1Id = c1.id
		state.card2Id = c2.id
		state.card3Id = c3.id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	/** Navigate to the board and switch to List view. */
	async function openListView(page) {
		const boardUrl = `${BASE}/index.php/apps/kanso#/board/${state.boardId}`
		await ncLogin(page)
		await page.goto(boardUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		// Switch to List view via the display-mode menu.
		await page.locator('.board-view__display-menu button').first().click()
		await page.getByRole('menuitemradio', { name: 'List', exact: true }).click()
		await page.keyboard.press('Escape')
		// Wait until card rows are visible.
		await page.waitForSelector('.board-list-row', { timeout: 10_000 })
	}

	test('drag L3 above L1 in List view — order changes and persists after reload', async ({ page }) => {
		await openListView(page)

		// Initial order: L1 (top), L2, L3 (bottom).
		const allRows = page.locator('.board-list-row-wrap')
		await expect(allRows).toHaveCount(3, { timeout: 8_000 })

		const rowL1 = page.locator('.board-list-row-wrap', { hasText: 'L1' })
		const rowL3 = page.locator('.board-list-row-wrap', { hasText: 'L3' })

		await expect(rowL1).toBeVisible({ timeout: 5_000 })
		await expect(rowL3).toBeVisible({ timeout: 5_000 })

		// Drag L3 above L1 (drop on top edge of L1 row).
		await dragWithMouse(page, rowL3, rowL1, 'top')

		// New order should be L3, L1, L2.
		// The virtualizer renders rows in DOM order matching the data model, so
		// asserting by nth() position reflects the actual rendered sequence.
		await expect(allRows.nth(0)).toContainText('L3', { timeout: 8_000 })
		await expect(allRows.nth(1)).toContainText('L1')
		await expect(allRows.nth(2)).toContainText('L2')

		// Reload and verify the server persisted the fractional sort key change.
		await page.reload()
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		// Re-enter List view after reload (view mode is persisted via localStorage,
		// but wait for the rows to be present either way).
		const afterRows = page.locator('.board-list-row-wrap')
		// If the view reverted to Board, switch back.
		const isList = await page.locator('.board-list-row-wrap').first().isVisible({ timeout: 3_000 }).catch(() => false)
		if (!isList) {
			await page.locator('.board-view__display-menu button').first().click()
			await page.getByRole('menuitemradio', { name: 'List', exact: true }).click()
			await page.keyboard.press('Escape')
			await page.waitForSelector('.board-list-row-wrap', { timeout: 10_000 })
		}
		await expect(afterRows).toHaveCount(3, { timeout: 8_000 })
		await expect(afterRows.nth(0)).toContainText('L3', { timeout: 8_000 })
		await expect(afterRows.nth(1)).toContainText('L1')
		await expect(afterRows.nth(2)).toContainText('L2')
	})

	test('child rows are inert — dragging a child row does not move it', async ({ page }) => {
		// Regression guard: child rows must not participate in DnD. We verify that
		// a child row element does not carry the vCardDnd directive by checking that
		// it does NOT have the board-list-row-wrap container (that wrapper is only
		// rendered for top-level cards). Children render as bare board-list-row--child
		// buttons, not inside board-list-row-wrap.
		const board2 = await api.post('/boards', { title: 'List DnD Child Guard ' + Date.now() })
		const stack2 = await api.post('/stacks', { boardId: board2.id, title: 'Tasks' })
		const parent = await api.post('/cards', { stackId: stack2.id, title: 'Parent' })
		const child = await api.post('/cards', { stackId: stack2.id, title: 'Child' })
		await api.put(`/cards/${child.id}/parent`, { parentCardId: parent.id })

		const boardUrl = `${BASE}/index.php/apps/kanso#/board/${board2.id}`
		await page.goto(boardUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await page.locator('.board-view__display-menu button').first().click()
		await page.getByRole('menuitemradio', { name: 'List', exact: true }).click()
		await page.keyboard.press('Escape')
		await page.waitForSelector('.board-list-row', { timeout: 10_000 })

		// The child row should be a .board-list-row--child element and must NOT be
		// wrapped inside a .board-list-row-wrap (which is the DnD host element).
		const childRow = page.locator('.board-list-row--child', { hasText: 'Child' })
		await expect(childRow).toBeVisible({ timeout: 8_000 })

		const wrapCount = await page.locator('.board-list-row-wrap', { hasText: 'Child' }).count()
		expect(wrapCount).toBe(0)

		// Cleanup
		await api.delete(`/boards/${board2.id}`).catch(() => {})
	})
})
