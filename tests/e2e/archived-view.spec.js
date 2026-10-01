// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #3595 — routed, virtualized Archived cards page. A card archived via the API
// disappears from the board and shows on /board/:id/archived; unarchiving it from
// the page removes it from the list and returns it to the board.

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Archived cards page', () => {
	const state = { boardId: 0, stackId: 0, cardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.send('POST', '/boards', { title: `Archived E2E ${Date.now()}` })
		state.boardId = board.id
		const stack = await api.send('POST', '/stacks', { boardId: board.id, title: 'Backlog' })
		state.stackId = stack.id
		const card = await api.send('POST', '/cards', { stackId: stack.id, title: 'Archivable Card' })
		state.cardId = card.id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.send('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('archived card appears on the routed page and unarchive returns it to the board', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		// Archive the card via the API, then reload so the board reflects it.
		await api.send('PATCH', `/cards/${state.cardId}`, { archived: true })
		await page.reload()
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

		// The board has re-hydrated (the ⋯ menu trigger is present) and the card
		// tile is gone from the board.
		await page.waitForSelector('.board-view__more-menu', { timeout: 15_000 })
		await expect(page.locator('.card-tile').filter({ hasText: 'Archivable Card' })).not.toBeVisible()

		// The archived action lives in the consolidated ⋯ More overflow menu and is
		// only offered when ≥1 archived card exists. Open the menu, then assert the
		// item is present (carrying the count) — the item renders reactively once
		// the board GET (which carries the archived count) resolves, so the menu can
		// stay open while it appears.
		await page.getByRole('button', { name: 'More' }).click()
		const archivedBtn = page.getByRole('menuitem', { name: /Archived cards \(\d+\)/ })
		await expect(archivedBtn).toBeVisible()
		await archivedBtn.click()

		// Routed, deep-linkable page.
		await expect(page).toHaveURL(/#\/board\/\d+\/archived/, { timeout: 8000 })
		await page.waitForSelector('.archived-view', { timeout: 15_000 })

		// The card shows in the virtualized archived list.
		const archivedItem = page.locator('.archived-view__row-title').filter({ hasText: 'Archivable Card' })
		await expect(archivedItem).toBeVisible()

		// Unarchive from the row.
		const row = page.locator('.archived-view__row').filter({ hasText: 'Archivable Card' })
		const unarchiveBtn = row.locator('button', { hasText: 'Unarchive' })
		await expect(unarchiveBtn).toBeVisible()
		await unarchiveBtn.click()

		// It leaves the list (optimistic removal + db-first reconcile).
		await expect(archivedItem).not.toBeVisible({ timeout: 8000 })

		// Back to the board via the header affordance; card is back on the board.
		await page.locator('.archived-view__back').click()
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await expect(page.locator('.card-tile').filter({ hasText: 'Archivable Card' })).toBeVisible()
	})

	test('the Archived page is deep-linkable and shows an empty state when nothing is archived', async ({ page }) => {
		// Ensure the card is not archived (previous test unarchived it).
		await api.send('PATCH', `/cards/${state.cardId}`, { archived: false }).catch(() => {})

		await ncLogin(page)
		await page.goto(`${state.boardUrl}/archived`)
		await page.waitForSelector('.archived-view', { timeout: 15_000 })
		await page.waitForLoadState('networkidle', { timeout: 10_000 }).catch(() => {})

		// Empty state renders (no archived cards).
		await expect(page.locator('.archived-view__empty')).toBeVisible()
		await expect(page.getByText('No archived cards')).toBeVisible()
	})
})

// #10440 — the reverse of #10430. A whole column archives in ONE click, so the
// Archived page has to give those cards back in one action too, not one click per
// card. It reuses the board's selection store (useBulkSelect) and the existing
// /api/cards/bulk `unarchive` action; what these specs pin is the round trip —
// and that an unarchived card lands in the column it was archived FROM, which is
// the whole point of restoring rather than re-filing by hand.

const SPRINT_CARDS = ['Alpha One', 'Alpha Two', 'Alpha Three']
const LATER_CARDS = ['Beta One', 'Beta Two']

test.describe('Restore many archived cards at once (#10440)', () => {
	const state = { boardId: 0, sprintId: 0, laterId: 0, boardUrl: '' }

	test.beforeEach(async () => {
		const board = await api.post('/boards', { title: `Bulk Restore E2E ${Date.now()}` })
		state.boardId = board.id
		state.sprintId = (await api.post('/stacks', { boardId: board.id, title: 'Sprint' })).id
		state.laterId = (await api.post('/stacks', { boardId: board.id, title: 'Later' })).id
		for (const title of SPRINT_CARDS) {
			await api.post('/cards', { stackId: state.sprintId, title })
		}
		for (const title of LATER_CARDS) {
			await api.post('/cards', { stackId: state.laterId, title })
		}
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterEach(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
		state.boardId = 0
	})

	test('a column archived in one click is restored to that same column in one action', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await expect(page.locator('.card-tile')).toHaveCount(5)

		// Archive the whole Sprint column the way the board offers it (#10430) —
		// the one-click gesture whose reverse trip this card is about.
		const sprintColumn = page.locator('.stack-column').filter({ hasText: 'Sprint' })
		await sprintColumn.locator('.stack-column__actions button').first().click()
		const menu = page.locator('[role="dialog"]').first()
		await expect(menu).toBeVisible()
		await menu.getByRole('button', { name: 'Archive 3 cards' }).click()
		await expect(page.locator('.card-tile')).toHaveCount(2)

		// Archive the OTHER column's cards too, over the API. The Archived page then
		// holds cards from two different stacks, so "restore the selection" has to be
		// scoped to what was ticked AND has to put each card back where it came from
		// — neither of which a single-stack fixture could tell apart.
		const seeded = await api.get(`/boards/${state.boardId}`)
		for (const card of seeded.cards.filter((c) => LATER_CARDS.includes(c.title))) {
			await api.patch(`/cards/${card.id}`, { archived: true })
		}

		// Reload rather than trust the 30s-stale board cache for the two cards that
		// were archived behind the SPA's back.
		await page.goto(`${state.boardUrl}/archived`)
		await page.reload()
		await page.waitForSelector('.archived-view')
		await expect(page.locator('.archived-view__row')).toHaveCount(5)

		await page.getByRole('button', { name: 'Select multiple cards' }).click()
		const sprintGroup = page.locator('.archived-view__group').filter({ hasText: 'Sprint' })

		// A group's select-all covers what the group SHOWS, so under the in-view
		// filter it must not go on claiming the whole column — same wording, and the
		// same honesty, as the column ⋯ menu's entry.
		await page.locator('.archived-view__filter-input').fill('Alpha T')
		await expect(sprintGroup.getByRole('button', { name: 'Select 2 visible cards' }))
			.toBeVisible()
		await page.locator('.archived-view__filter-input').fill('')

		// Unfiltered, the Sprint group's select-all takes 3 of the 5 in one click.
		await sprintGroup.getByRole('button', { name: 'Select 3 cards' }).click()
		await expect(page.locator('.bulk-action-bar')).toContainText('3 selected')

		// ONE action for all three.
		await page.getByRole('button', { name: 'Restore selected' }).click()

		// Server truth: every restored card is un-archived AND back in the stack it
		// was archived from (a restore that dumped them in the first column would
		// pass a "they left the archive" assertion and fail this one).
		await expect
			.poll(async () => {
				const board = await api.get(`/boards/${state.boardId}`)
				return board.cards
					.filter((c) => !c.archived)
					.map((c) => `${c.title} in ${c.stackId === state.sprintId ? 'Sprint' : c.stackId === state.laterId ? 'Later' : 'elsewhere'}`)
					.sort()
			})
			.toEqual(SPRINT_CARDS.map((title) => `${title} in Sprint`).sort())

		// And the two cards nobody ticked are still archived — the action ran over
		// the selection, not over the page.
		await expect
			.poll(async () => {
				const board = await api.get(`/boards/${state.boardId}`)
				return board.cards.filter((c) => c.archived).map((c) => c.title).sort()
			})
			.toEqual([...LATER_CARDS].sort())

		// No error banner — asserted only AFTER the writes landed, since a check
		// taken the instant the click returns passes on a request still in flight.
		await expect(page.getByText('Bulk action failed.')).toHaveCount(0)

		// The board itself shows them back in Sprint.
		await page.locator('.archived-view__back').click()
		await page.waitForSelector('.board-view__header')
		await expect(page.locator('.stack-column').filter({ hasText: 'Sprint' }).locator('.card-tile'))
			.toHaveCount(3)
	})
})

test.describe('Shift-range over the virtualized Archived list (#10440)', () => {
	// Enough rows that TanStack Virtual cannot mount them all at once — the spec
	// asserts that below rather than assuming it.
	const COUNT = 40
	/** Zero-padded so the first and last rows are addressable by name. */
	const titleFor = (i) => `Restore Me ${String(i).padStart(3, '0')}`
	const state = { boardId: 0, stackId: 0, archivedUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: `Bulk Restore Range E2E ${Date.now()}` })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'Backlog' })).id
		// Sequential on purpose: each create appends after the current last card, so
		// this is the order the Archived page will group them in.
		for (let i = 1; i <= COUNT; i++) {
			const card = await api.post('/cards', { stackId: state.stackId, title: titleFor(i) })
			await api.patch(`/cards/${card.id}`, { archived: true })
		}
		state.archivedUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/archived`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	/**
	 * Scroll the Archived list to the bottom until the given row is mounted, then
	 * return it. The virtualizer only renders its window, so the last of 40 rows
	 * does not exist in the DOM until we get there.
	 *
	 * @param {import('@playwright/test').Page} page - the page under test
	 * @param {string} title - the card title to reveal
	 * @return {Promise<import('@playwright/test').Locator>} the revealed row
	 */
	async function scrollToRow(page, title) {
		const row = page.locator('.archived-view__row').filter({ hasText: title })
		await expect.poll(async () => {
			await page.locator('.archived-view__scroll')
				.evaluate((el) => { el.scrollTop = el.scrollHeight })
			await page.waitForTimeout(150)
			return await row.count()
		}).toBe(1)
		return row
	}

	test('a shift-range covers rows that were never mounted, and the selection survives scrolling', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.archivedUrl)
		await page.waitForSelector('.archived-view')
		const rows = page.locator('.archived-view__row')
		await expect(rows.first()).toBeVisible()

		// The premise: only a window of the 40 rows is in the DOM at all. A range
		// resolved against the DOM could not reach the rest. The lower bound is
		// there so an empty or broken list cannot satisfy the upper one.
		const mounted = await rows.count()
		expect(mounted).toBeGreaterThan(5)
		expect(mounted).toBeLessThan(COUNT)

		await page.getByRole('button', { name: 'Select multiple cards' }).click()

		// Anchor on the first row.
		const first = rows.filter({ hasText: titleFor(1) })
		await first.locator('.archived-view__row-open').click()
		await expect(page.locator('.bulk-action-bar')).toContainText('1 selected')

		// The selection outlives the virtualizer unmounting and remounting that row:
		// it lives in a Set keyed by card id, not in the DOM.
		await scrollToRow(page, titleFor(COUNT))
		await page.locator('.archived-view__scroll').evaluate((el) => { el.scrollTop = 0 })
		await expect(page.locator('.bulk-action-bar')).toContainText('1 selected')
		await expect(rows.filter({ hasText: titleFor(1) }).locator('input[type="checkbox"]'))
			.toBeChecked()

		// Shift-click the LAST row, which was not mounted when the anchor was
		// clicked. The range comes off the row model, so it covers every card in
		// between whether or not it is rendered.
		const last = await scrollToRow(page, titleFor(COUNT))
		await last.locator('.archived-view__row-open').click({ modifiers: ['Shift'] })
		await expect(page.locator('.bulk-action-bar')).toContainText(`${COUNT} selected`)

		// A click that leaves the selection UNCHANGED must not desync the tick from
		// it. Shift-clicking a row already inside the range is exactly that click,
		// and on the checkbox it is the browser's own activation that would flip the
		// box while the model stands still — so the box's default is prevented and
		// what is drawn always comes from the model.
		const inside = await scrollToRow(page, titleFor(COUNT - 5))
		await inside.locator('input[type="checkbox"]').click({ modifiers: ['Shift'] })
		await expect(inside.locator('input[type="checkbox"]')).toBeChecked()
		await expect(page.locator('.bulk-action-bar')).toContainText(`${COUNT} selected`)

		// One action restores all 40 …
		await page.getByRole('button', { name: 'Restore selected' }).click()
		await expect
			.poll(async () => {
				const board = await api.get(`/boards/${state.boardId}`)
				return board.cards.filter((c) => !c.archived && c.stackId === state.stackId).length
			}, { timeout: 60_000 })
			.toBe(COUNT)
		await expect(page.getByText('Bulk action failed.')).toHaveCount(0)

		// … and the page is left empty, which drops multi-select mode with it.
		await expect(page.locator('.archived-view__empty')).toBeVisible()
		await expect(page.locator('.bulk-action-bar')).toHaveCount(0)
	})
})
