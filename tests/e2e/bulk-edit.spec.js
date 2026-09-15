// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// Titles of the cards currently in a stack (from the board summary payload).
async function stackTitles(boardId, stackId) {
	const board = await api.get(`/boards/${boardId}`)
	return board.cards
		.filter((c) => c.stackId === stackId && !c.archived)
		.map((c) => c.title)
		.sort()
}

// #3523 — multi-select cards, then bulk-move them to another stack.
test.describe('Bulk edit cards (multi-select)', () => {
	const state = { boardId: 0, todoId: 0, doneId: 0, boardUrl: '' }

	test.beforeAll(async ({ peer }) => {
		const board = await api.post('/boards', { title: 'Bulk-Edit E2E' })
		state.boardId = board.id
		state.todoId = (await api.post('/stacks', { boardId: board.id, title: 'To Do' })).id
		state.doneId = (await api.post('/stacks', { boardId: board.id, title: 'Done' })).id
		// The bulk bar's menus must each hold MORE than one entry to render as
		// menus at all: NcActions renders nothing for an empty menu and collapses
		// to a single immediate-action button for a one-entry one. Two labels and
		// a second board member (the browser stays the owner — the peer is only
		// there to be assignable) give every menu in the bar its real shape.
		await api.post('/labels', { boardId: board.id, title: 'Bug', color: 'e07b00' })
		await api.post('/labels', { boardId: board.id, title: 'Chore', color: '2ecc71' })
		await api.post(`/boards/${board.id}/acl`, {
			participant: peer.user,
			participantType: 'user',
			permission: 3,
		})
		await api.post('/cards', { stackId: state.todoId, title: 'Alpha' })
		await api.post('/cards', { stackId: state.todoId, title: 'Bravo' })
		await api.post('/cards', { stackId: state.todoId, title: 'Charlie' })
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('selecting two cards and bulk-moving lands them in the target stack', async ({ page }) => {
		expect(await stackTitles(state.boardId, state.todoId)).toEqual(['Alpha', 'Bravo', 'Charlie'])
		expect(await stackTitles(state.boardId, state.doneId)).toEqual([])

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 15_000 })
		await expect(page.locator('.card-tile', { hasText: 'Alpha' })).toBeVisible({ timeout: 10_000 })

		// Enter multi-select mode via the consolidated ⋯ More overflow menu.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: 'Select multiple cards' }).click()

		// Selection checkboxes now appear on the tiles; select Alpha and Bravo.
		await page.locator('.card-tile', { hasText: 'Alpha' }).click()
		await page.locator('.card-tile', { hasText: 'Bravo' }).click()

		// The bulk action bar reports the selection count.
		await expect(page.locator('.bulk-action-bar')).toContainText('2')

		// #10275 — every control in the bar shows a hover hint, so a sighted mouse
		// user can tell the icon-only buttons apart. The plain buttons carry `title`
		// themselves; the menu triggers get it from the wrapping `.action-item`
		// (NcActions forwards fall-through attributes to its root element, not to
		// the trigger button) — which is exactly what the browser resolves a
		// tooltip from when the button itself has none.
		//
		// #10287 — the bar is now three inline actions plus one "More" overflow, so
		// this is the whole inline row: the remaining actions are entries INSIDE
		// More and are asserted below.
		await expect.poll(
			() => page.locator('.bulk-action-bar button').evaluateAll(
				(els) => els.map((el) => el.closest('[title]')?.getAttribute('title') ?? null),
			),
			{ timeout: 10_000 },
		).toEqual([
			'Move to…',
			'Add label…',
			'Mark done',
			'More actions',
			'Exit selection mode',
		])

		// The overflow holds the rest, and each entry names the ACTION rather than
		// just the thing it acts on — the assignee list is a separate request, so
		// its entries appear a beat after the menu opens.
		// The overflow holds a date input as well as buttons, so NcActions gives its
		// popover role=dialog rather than role=menu (a menu may only contain
		// menuitems) — the button-only "Move to…" picker below is still a menu.
		await page.getByRole('button', { name: 'More actions' }).click()
		const more = page.getByRole('dialog', { name: 'More actions' })
		await expect(more.getByRole('button', { name: 'Remove label Bug' })).toBeVisible({ timeout: 10_000 })
		await expect(more.getByRole('button', { name: 'Remove label Chore' })).toBeVisible()
		await expect(more.getByRole('button', { name: /^Assign to / }).first()).toBeVisible({ timeout: 10_000 })
		await expect(more.getByRole('button', { name: 'Clear due date' })).toBeVisible()
		await expect(more.getByRole('button', { name: 'Archive selected' })).toBeVisible()
		await expect(more.getByRole('button', { name: 'Delete selected' })).toBeVisible()
		await page.keyboard.press('Escape')
		await expect(more).toBeHidden()

		// Open the "Move to…" menu and pick the Done stack.
		await page.getByRole('button', { name: 'Move to…' }).click()
		await page.getByRole('menuitem', { name: 'Done' }).click()

		// Both selected cards land in Done; Charlie stays in To Do.
		await expect
			.poll(() => stackTitles(state.boardId, state.doneId), { timeout: 10_000 })
			.toEqual(['Alpha', 'Bravo'])
		await expect
			.poll(() => stackTitles(state.boardId, state.todoId), { timeout: 10_000 })
			.toEqual(['Charlie'])
	})

	// #10485 — "select every card in this column" from the column ⋯ menu, so a whole
	// column no longer has to be ticked card by card. Mirrors the shipped
	// archive-all entry (#10430): same column-scoped set, same filter-aware label
	// that names a count rather than promising "all", same empty-column guard.
	test.describe('Select every card in a column (#10485)', () => {
		// Comfortably more than the virtualizer mounts at once (overscan 6 around a
		// ~8-row window), so "selects the cards that are not even in the DOM" is a
		// real assertion rather than a coincidence.
		const CARD_COUNT = 45
		const titleFor = (i) => `Select Card ${String(i).padStart(3, '0')}`
		const sel = { boardId: 0, sprintId: 0, sideId: 0, labelId: 0, boardUrl: '' }

		/**
		 * Open the ⋯ menu of the named column and return the teleported panel
		 * (NcActions teleports its popover to <body>).
		 *
		 * @param {import('@playwright/test').Page} page - the page under test
		 * @param {string} name - the column title
		 * @return {Promise<import('@playwright/test').Locator>} the menu panel
		 */
		async function openColumnMenu(page, name) {
			await page.locator('.stack-column').filter({ hasText: name })
				.locator('.stack-column__actions button').first().click()
			const dialog = page.locator('[role="dialog"]').first()
			await expect(dialog).toBeVisible({ timeout: 6_000 })
			return dialog
		}

		test.beforeAll(async () => {
			const board = await api.post('/boards', { title: `Select All E2E ${Date.now()}` })
			sel.boardId = board.id
			sel.sprintId = (await api.post('/stacks', { boardId: board.id, title: 'Sprint' })).id
			sel.sideId = (await api.post('/stacks', { boardId: board.id, title: 'Side' })).id
			await api.post('/stacks', { boardId: board.id, title: 'Empty' })
			for (let i = 1; i <= CARD_COUNT; i++) {
				await api.post('/cards', { stackId: sel.sprintId, title: titleFor(i) })
			}
			await api.post('/cards', { stackId: sel.sideId, title: 'Side Card' })
			// One labelled card, so a label filter narrows the column to a strict subset.
			const label = await api.post('/labels', { boardId: board.id, title: 'Keep', color: '31CC7C' })
			sel.labelId = label.id
			const full = await api.get(`/boards/${board.id}`)
			const one = full.cards.find((c) => c.title === titleFor(1))
			await api.put(`/cards/${one.id}/labels/${label.id}`)
			sel.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
		})

		test.afterAll(async () => {
			if (sel.boardId) await api.delete(`/boards/${sel.boardId}`).catch(() => {})
			sel.boardId = 0
		})

		test('the column entry arms multi-select and selects every card, including the ones outside the virtualized window', async ({ page }) => {
			await ncLogin(page)
			await page.goto(sel.boardUrl)
			await page.waitForSelector('.stack-column', { timeout: 20_000 })
			await expect(page.locator('.card-tile', { hasText: titleFor(1) }))
				.toBeVisible({ timeout: 15_000 })

			// Selection mode is OFF — the action has to turn it on by itself.
			await expect(page.locator('.bulk-action-bar')).toHaveCount(0)

			// THE subtle failure mode: only a slice of the column is mounted. A
			// select-all driven off the DOM would quietly take just these.
			const mounted = await page.locator('.card-tile').count()
			expect(mounted).toBeLessThan(CARD_COUNT)

			const menu = await openColumnMenu(page, 'Sprint')
			const selectAll = menu.getByRole('button', { name: `Select ${CARD_COUNT} cards` })
			await expect(selectAll).toBeVisible({ timeout: 8_000 })
			await selectAll.click()

			// Multi-select is now on and the WHOLE column is selected.
			await expect(page.locator('.bulk-action-bar'))
				.toContainText(`${CARD_COUNT} selected`, { timeout: 10_000 })
		})

		test('an existing selection elsewhere on the board is extended, not replaced', async ({ page }) => {
			await ncLogin(page)
			await page.goto(sel.boardUrl)
			await page.waitForSelector('.stack-column', { timeout: 20_000 })
			await expect(page.locator('.card-tile', { hasText: 'Side Card' }))
				.toBeVisible({ timeout: 15_000 })

			await page.getByRole('button', { name: 'More' }).click()
			await page.getByRole('menuitem', { name: 'Select multiple cards' }).click()
			await page.locator('.card-tile', { hasText: 'Side Card' }).click()
			await expect(page.locator('.bulk-action-bar'))
				.toContainText('1 selected', { timeout: 10_000 })

			const menu = await openColumnMenu(page, 'Sprint')
			await menu.getByRole('button', { name: `Select ${CARD_COUNT} cards` }).click()

			// Union, matching shift-range semantics — the Side Card survives.
			await expect(page.locator('.bulk-action-bar'))
				.toContainText(`${CARD_COUNT + 1} selected`, { timeout: 10_000 })
		})

		test('the entry is hidden on an empty column and names the visible count under a filter', async ({ page }) => {
			await ncLogin(page)
			await page.goto(sel.boardUrl)
			await page.waitForSelector('.stack-column', { timeout: 20_000 })
			await expect(page.locator('.card-tile', { hasText: titleFor(1) }))
				.toBeVisible({ timeout: 15_000 })

			// Nothing to select, so no entry at all.
			const emptyMenu = await openColumnMenu(page, 'Empty')
			await expect(emptyMenu.getByRole('button', { name: /^Select / })).toHaveCount(0)
			await page.keyboard.press('Escape')
			await expect(emptyMenu).toBeHidden({ timeout: 6_000 })

			// Filter to the one labelled card (straight from the URL, the shareable
			// form the filter bar writes): the entry must name the VISIBLE count …
			await page.goto(`${sel.boardUrl}?fl=${sel.labelId}`)
			await page.waitForSelector('.board-view__header', { timeout: 15_000 })
			await expect(page.locator('.card-tile')).toHaveCount(1, { timeout: 10_000 })

			const menu = await openColumnMenu(page, 'Sprint')
			const visibleEntry = menu.getByRole('button', { name: 'Select 1 visible card' })
			await expect(visibleEntry).toBeVisible({ timeout: 8_000 })
			await expect(menu.getByRole('button', { name: `Select ${CARD_COUNT} cards` })).toHaveCount(0)
			await expect(menu.getByRole('button', { name: /^Select all/ })).toHaveCount(0)

			// … and select exactly that set, not the whole column behind the filter.
			await visibleEntry.click()
			await expect(page.locator('.bulk-action-bar'))
				.toContainText('1 selected', { timeout: 10_000 })
		})
	})
})
