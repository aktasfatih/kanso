// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Cross-board Views (#3815)', () => {
	const state = { boardA: 0, boardB: 0, cardA: '', cardB: '', cardAId: 0, cardBId: 0, labelA: 0, labelB: 0, viewId: '' }

	test.beforeAll(async () => {
		const stamp = Math.floor(Date.now() / 1000)
		state.cardA = 'ViewsA ' + stamp
		state.cardB = 'ViewsB ' + stamp

		// Two boards, one card each, each tagged with a per-board label. Every read
		// below - the saved Views AND the raw feed request - filters to those two
		// labels, so each one narrows to EXACTLY these two cards regardless of how
		// much other data lives in the dev DB: the list stays small and
		// deterministic (no virtualization off-screen flake) and, because the
		// server filters BEFORE the MAX_CARDS cap (#9862), the fixtures can never
		// be sliced out of the window on an instance holding more than that.
		const a = await api.post('/boards', { title: 'ViewsBoardA ' + stamp })
		state.boardA = a.id
		state.labelA = (await api.post('/labels', { boardId: a.id, title: 'vlabelA ' + stamp, color: 'ff0000' })).id
		const stackA = (await api.post('/stacks', { boardId: a.id, title: 'To do' })).id
		state.cardAId = (await api.post('/cards', { stackId: stackA, title: state.cardA })).id
		await api.put(`/cards/${state.cardAId}/labels/${state.labelA}`)

		const b = await api.post('/boards', { title: 'ViewsBoardB ' + stamp })
		state.boardB = b.id
		state.labelB = (await api.post('/labels', { boardId: b.id, title: 'vlabelB ' + stamp, color: '00ff00' })).id
		const stackB = (await api.post('/stacks', { boardId: b.id, title: 'To do' })).id
		state.cardBId = (await api.post('/cards', { stackId: stackB, title: state.cardB })).id
		await api.put(`/cards/${state.cardBId}/labels/${state.labelB}`)
	})

	test.afterAll(async () => {
		if (state.viewId) await api.delete(`/views/${state.viewId}`).catch(() => {})
		if (state.boardA) await api.delete(`/boards/${state.boardA}`).catch(() => {})
		if (state.boardB) await api.delete(`/boards/${state.boardB}`).catch(() => {})
	})

	test('the cross-board feed returns a capped envelope of cards from every readable board', async () => {
		// The feed is a bounded envelope { cards, capped, total, limit } (#3892) -
		// not a bare array - so a huge readable set can never ship one unbounded
		// payload.
		//
		// The request is scoped to the two per-board labels, like every other read
		// in this file. That is not cosmetic: the cap keeps the FIRST MAX_CARDS
		// rows of the default (boardId, id) ASC order, so on an instance holding
		// more than that, the newest rows - these fixtures - are exactly what gets
		// sliced off, and an unscoped read here would fail for a reason that has
		// nothing to do with what it asserts. Filtering runs before the cap
		// (#9862), so a scoped read still sees its own cards. The envelope is
		// asserted the same way either way.
		const feed = await api.get(`/views/cards?fl=${state.labelA},${state.labelB}`)
		expect(Array.isArray(feed.cards)).toBe(true)
		expect(typeof feed.capped).toBe('boolean')
		expect(feed.limit).toBeGreaterThan(0)
		expect(feed.total).toBe(feed.cards.length + (feed.capped ? feed.total - feed.cards.length : 0))
		expect(feed.cards.length).toBeLessThanOrEqual(feed.limit)

		const titles = feed.cards.map((c) => c.title)
		expect(titles).toContain(state.cardA)
		expect(titles).toContain(state.cardB)
		// Each card carries its board identity for grouping + deep-link.
		const rowA = feed.cards.find((c) => c.title === state.cardA)
		expect(rowA.boardId).toBe(state.boardA)
		expect(rowA.boardTitle).toBeTruthy()
	})

	test('create a View → it appears in the nav → opening shows both boards\' cards (List)', async ({ page }) => {
		// Persist a View spanning both boards, filtered to the two per-board labels
		// so it resolves to EXACTLY the two test cards, grouped by board so each
		// board's row shows as its own List group.
		const created = await api.put('/views', {
			name: 'Views spec ' + Math.floor(Date.now() / 1000),
			filter: { labels: [state.labelA, state.labelB] },
			groupBy: 'board',
			display: 'list',
		})
		const view = created.views[created.views.length - 1]
		state.viewId = view.id
		expect(view.id).toBeTruthy()

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/`)

		// The View appears in the left nav (Views section) and is clickable.
		const navItem = page.locator('.app-navigation a', { hasText: view.name }).first()
		await expect(navItem).toBeVisible({ timeout: 15_000 })
		await navItem.click()

		// Opening it lands on the View surface and lists BOTH boards' cards.
		await expect(page).toHaveURL(new RegExp(`/views/${view.id}`))
		await expect(page.locator('.board-list-row__title', { hasText: state.cardA })).toBeVisible({ timeout: 15_000 })
		await expect(page.locator('.board-list-row__title', { hasText: state.cardB })).toBeVisible({ timeout: 15_000 })

		// Both board group headers render (grouped by board).
		await expect(page.locator('.board-list-group__title', { hasText: /ViewsBoardA/ })).toBeVisible()
		await expect(page.locator('.board-list-group__title', { hasText: /ViewsBoardB/ })).toBeVisible()
	})

	test('List display: clicking a card opens it as an overlay IN the View and closing stays in the View (#3950)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })

		const created = await api.put('/views', {
			name: 'Views list-open ' + Math.floor(Date.now() / 1000),
			filter: { labels: [state.labelA, state.labelB] },
			groupBy: 'board',
			display: 'list',
		})
		const view = created.views[created.views.length - 1]
		const listViewId = view.id
		expect(listViewId).toBeTruthy()

		try {
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/views/${listViewId}`)

			const rowA = page.locator('.board-list-row__title', { hasText: state.cardA })
			await expect(rowA).toBeVisible({ timeout: 15_000 })

			// Clicking the row opens the shared card overlay ON the View (not the board).
			await rowA.click()
			const modal = page.locator('.card-modal-modal')
			await expect(modal).toBeVisible({ timeout: 15_000 })
			await expect(page).toHaveURL(new RegExp(`/views/${listViewId}`))
			expect(page.url()).not.toMatch(/\/board\//)

			// Close → overlay gone, still in the View.
			await page.keyboard.press('Escape')
			await expect(modal).toHaveCount(0, { timeout: 10_000 })
			await expect(page).toHaveURL(new RegExp(`/views/${listViewId}`))
			expect(page.url()).not.toMatch(/\/board\//)
			await expect(rowA).toBeVisible()
		} finally {
			await api.delete(`/views/${listViewId}`).catch(() => {})
		}
	})

	test('Kanban display groups the feed into columns; a tile opens the card IN the View (#3950)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })

		// A View spanning both boards, filtered to the two per-board labels so it
		// resolves to EXACTLY the two test cards, grouped by BOARD and saved with
		// the new Kanban display so it re-seeds Kanban on reload.
		const created = await api.put('/views', {
			name: 'Views kanban ' + Math.floor(Date.now() / 1000),
			filter: { labels: [state.labelA, state.labelB] },
			groupBy: 'board',
			display: 'kanban',
		})
		const view = created.views[created.views.length - 1]
		const kanbanViewId = view.id
		expect(kanbanViewId).toBeTruthy()

		try {
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/views/${kanbanViewId}`)

			// Saved display:'kanban' re-seeds the Kanban surface on load (no click needed),
			// and the Kanban button reads as active.
			const kanbanBtn = page.locator('.view-page__display-btn', { hasText: 'Kanban' })
			await expect(kanbanBtn).toHaveClass(/view-page__display-btn--active/, { timeout: 15_000 })

			// One column per board group (grouped by board) → ≥2 columns with the
			// expected board-name headers.
			const columns = page.locator('.view-kanban-col')
			await expect(columns).toHaveCount(2, { timeout: 15_000 })
			await expect(page.locator('.view-kanban-col__title', { hasText: /ViewsBoardA/ })).toBeVisible()
			await expect(page.locator('.view-kanban-col__title', { hasText: /ViewsBoardB/ })).toBeVisible()

			// Each card renders as a CardTile; both test cards are present.
			await expect(page.locator('.card-tile__title', { hasText: state.cardA })).toBeVisible({ timeout: 10_000 })
			const tileB = page.locator('.card-tile__title', { hasText: state.cardB })
			await expect(tileB).toBeVisible()

			// Tile parity (#3950): the Kanban tile carries the real human ref (KAN-…
			// style prefix + seq) and the label chip renders with its board colour, not
			// a neutral dot — same as the board tiles. Card B's label is green (00ff00).
			await expect(page.locator('.view-kanban-col .card-tile__ref').first()).toBeVisible({ timeout: 10_000 })
			const chipBg = await page.locator('.view-kanban-col__cards .card-tile__label-chip', { hasText: /vlabelB/ })
				.first().evaluate((el) => getComputedStyle(el).backgroundColor)
			// A coloured chip resolves to a non-transparent rgb() background.
			expect(chipBg).toMatch(/^rgb/)
			expect(chipBg).not.toBe('rgba(0, 0, 0, 0)')

			// Clicking a tile opens the card detail as an in-place overlay ON the View
			// (#3950): the shared CardModal → CardDetail opens, the URL STAYS on the
			// View (never swaps to the card's board), and closing returns to the View.
			await tileB.click()
			const modal = page.locator('.card-modal-modal')
			await expect(modal).toBeVisible({ timeout: 15_000 })
			// URL unchanged: still the View, NOT /board/:id/card/:cardId.
			await expect(page).toHaveURL(new RegExp(`/views/${kanbanViewId}`))
			expect(page.url()).not.toMatch(/\/board\//)
			// The overlay shows card B's content.
			await expect(modal.getByText(state.cardB, { exact: false }).first()).toBeVisible({ timeout: 10_000 })

			// Close the overlay (Escape) → the modal is gone and we are STILL in the
			// View at /views/:id, not on any board.
			await page.keyboard.press('Escape')
			await expect(modal).toHaveCount(0, { timeout: 10_000 })
			await expect(page).toHaveURL(new RegExp(`/views/${kanbanViewId}`))
			expect(page.url()).not.toMatch(/\/board\//)
			// The Kanban columns are still rendered underneath (we never left the View).
			await expect(columns.first()).toBeVisible()

			// Switch to List, then back to Kanban via the switcher to prove the toggle
			// works in-session too.
			await page.locator('.view-page__display-btn', { hasText: 'List' }).click()
			await expect(page.locator('.board-list-row__title', { hasText: state.cardA })).toBeVisible({ timeout: 10_000 })
			await kanbanBtn.click()
			await expect(columns.first()).toBeVisible({ timeout: 10_000 })

			// Reload → the saved display:'kanban' still re-seeds Kanban.
			await page.reload()
			await expect(kanbanBtn).toHaveClass(/view-page__display-btn--active/, { timeout: 15_000 })
			await expect(page.locator('.view-kanban-col').first()).toBeVisible({ timeout: 10_000 })
		} finally {
			await api.delete(`/views/${kanbanViewId}`).catch(() => {})
		}
	})

	test('richer filter dimensions + new group-by narrow a View (#3815)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })

		// Seed distinguishing summary fields on the two test cards so the new filter
		// dimensions + group-by have something deterministic to bite on:
		//   - card A: type=bug + one comment (commentCount>0)
		//   - card B: type=feature + no comment
		// Both are owned by the same admin user (creator), so owner grouping/filter
		// keeps both, while type/comments narrow to exactly one.
		await api.patch(`/cards/${state.cardAId}`, { type: 'bug' })
		await api.patch(`/cards/${state.cardBId}`, { type: 'feature' })
		await api.post(`/cards/${state.cardAId}/comments`, { body: 'a comment for filtering' })

		// A View spanning both boards, filtered to the two per-board labels so it
		// resolves to EXACTLY the two test cards, grouped by TYPE (a new group-by).
		const created = await api.put('/views', {
			name: 'Views filters ' + Math.floor(Date.now() / 1000),
			filter: { labels: [state.labelA, state.labelB] },
			groupBy: 'type',
			display: 'list',
		})
		const view = created.views[created.views.length - 1]
		const filterViewId = view.id
		expect(filterViewId).toBeTruthy()

		try {
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/views/${filterViewId}`)

			// Baseline: both cards visible, and the new TYPE group-by renders a "Bug"
			// and a "Feature" group header.
			const rowA = page.locator('.board-list-row__title', { hasText: state.cardA })
			const rowB = page.locator('.board-list-row__title', { hasText: state.cardB })
			await expect(rowA).toBeVisible({ timeout: 15_000 })
			await expect(rowB).toBeVisible({ timeout: 15_000 })
			await expect(page.locator('.board-list-group__title', { hasText: /^Bug$/ })).toBeVisible()
			await expect(page.locator('.board-list-group__title', { hasText: /^Feature$/ })).toBeVisible()

			// ── New filter dimension #1: TYPE (multi-select, OR within) ──────────────
			// Open the progressive drill-in filter, drill into Type, pick Bug.
			await page.locator('.board-filter-bar__trigger').click()
			await page.locator('.board-filter-bar__dim-row[data-dim="types"]').click()
			await page.locator('.board-filter-bar__opt', { hasText: /^Bug$/ }).click()
			// Only the bug card (A) survives; the feature card (B) drops out.
			await expect(rowA).toBeVisible({ timeout: 10_000 })
			await expect(rowB).toHaveCount(0, { timeout: 10_000 })

			// Reset the TYPE facet itself (toggle "Bug" back off) before exercising the
			// next dimension — NOT the panel's global "Clear filters". Clearing is
			// scoped to the facets this control renders (#10091), so on a View it no
			// longer touches the View's own saved label filter; but resetting the one
			// facet under test keeps this step's undo exact and independent of that.
			await page.locator('.board-filter-bar__opt', { hasText: /^Bug$/ }).click()
			await expect(rowB).toBeVisible({ timeout: 10_000 })
			await page.locator('.board-filter-bar__back').click()

			// ── New filter dimension #2: COMMENTS (single-select radio) ──────────────
			await page.locator('.board-filter-bar__dim-row[data-dim="comments"]').click()
			await page.locator('.board-filter-bar__opt', { hasText: /Has comments/ }).click()
			// Only card A (which has a comment) remains.
			await expect(rowA).toBeVisible({ timeout: 10_000 })
			await expect(rowB).toHaveCount(0, { timeout: 10_000 })

			// Reset the COMMENTS facet with its own "Any" radio, close the popover.
			await page.locator('.board-filter-bar__opt', { hasText: /^Any$/ }).click()
			await page.locator('.board-filter-bar__back').click()
			await page.keyboard.press('Escape')
			await expect(rowB).toBeVisible({ timeout: 10_000 })

			// ── New group-by: switch to REVIEW ──────────────────────────────────────
			// Neither card has a review requested, so a "No review" group appears.
			const groupSelect = page.locator('.view-page__select .vs__dropdown-toggle')
			await groupSelect.click()
			await page.locator('.vs__dropdown-option', { hasText: /^Review$/ }).click()
			await expect(page.locator('.board-list-group__title', { hasText: /No review/ })).toBeVisible({ timeout: 10_000 })
		} finally {
			await api.delete(`/views/${filterViewId}`).catch(() => {})
		}
	})

	test('the sort control reorders a View and the choice persists across a reload (#9860)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })

		const stamp = Math.floor(Date.now() / 1000)
		// One board, two cards created in a known order, so the DEFAULT feed order
		// (board, then creation) is Zed then Alpha. Their due dates run the OTHER
		// way round, so sorting by due date must flip the rows - something the
		// unsorted feed cannot produce.
		const board = await api.post('/boards', { title: 'ViewsSortBoard ' + stamp })
		const label = await api.post('/labels', { boardId: board.id, title: 'vsort ' + stamp, color: '0000ff' })
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		const zed = 'ViewsSort Zed ' + stamp
		const alpha = 'ViewsSort Alpha ' + stamp
		const zedCard = await api.post('/cards', { stackId: stack.id, title: zed })
		await api.put(`/cards/${zedCard.id}/labels/${label.id}`)
		await api.patch(`/cards/${zedCard.id}`, { duedate: '2031-02-02T09:00:00+00:00' })
		const alphaCard = await api.post('/cards', { stackId: stack.id, title: alpha })
		await api.put(`/cards/${alphaCard.id}/labels/${label.id}`)
		await api.patch(`/cards/${alphaCard.id}`, { duedate: '2031-01-01T09:00:00+00:00' })

		// Saved with NO sort, exactly like every View that existed before the sort
		// control shipped: the server defaults it, so such a View still loads and
		// still looks the way it always did.
		const created = await api.put('/views', {
			name: 'Views sort ' + stamp,
			filter: { labels: [label.id] },
			groupBy: 'status',
			display: 'list',
		})
		const view = created.views[created.views.length - 1]
		expect(view.sort).toEqual({ mode: 'default', dir: 'asc' })

		try {
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/views/${view.id}`)

			const titles = async () =>
				(await page.locator('.board-list-row__title').allTextContents()).map((s) => s.trim())
			await expect(page.locator('.board-list-row__title').first()).toBeVisible({ timeout: 15_000 })
			await expect.poll(titles, { timeout: 15_000 }).toEqual([zed, alpha])

			// Pick "Due date". Selecting a mode resets to its natural direction
			// (soonest first), which the toolbar label spells out with an arrow.
			const sortMenu = page.locator('.view-page__sort button').first()
			await sortMenu.click()
			// Let the teleported popover settle before clicking the radio.
			await page.waitForTimeout(400)
			await page.locator('.action-radio__text', { hasText: /^Due date$/ }).click()
			await page.waitForTimeout(150)
			await page.keyboard.press('Escape')

			await expect.poll(titles, { timeout: 15_000 }).toEqual([alpha, zed])
			await expect(page.locator('.view-page__sort')).toContainText('Due date ↑')

			// Save it onto the View and reload: the order (and the control) survive.
			await page.locator('.view-page__save').click()
			await page.waitForTimeout(500)
			await page.reload()
			await expect(page.locator('.board-list-row__title').first()).toBeVisible({ timeout: 15_000 })
			await expect.poll(titles, { timeout: 15_000 }).toEqual([alpha, zed])
			await expect(page.locator('.view-page__sort')).toContainText('Due date ↑')

			// It is on the stored record, not just in the page's memory.
			const reread = (await api.get('/views')).views.find((v) => v.id === view.id)
			expect(reread.sort).toEqual({ mode: 'due', dir: 'asc' })
		} finally {
			await api.delete(`/views/${view.id}`).catch(() => {})
			await api.delete(`/boards/${board.id}`).catch(() => {})
		}
	})

	test('archived cards are hidden from a View by default and the Archived facet opts them back in (#10052)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })

		const stamp = Math.floor(Date.now() / 1000)
		// One board, two cards sharing one label, so the View narrows to exactly
		// these two rows (the list is virtualised — an unfiltered cross-board feed
		// would leave them off-screen).
		const board = await api.post('/boards', { title: 'ViewsArchived ' + stamp })
		const label = await api.post('/labels', { boardId: board.id, title: 'varch ' + stamp, color: '9900ff' })
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		const liveTitle = 'ViewsArchived live ' + stamp
		const archivedTitle = 'ViewsArchived archived ' + stamp
		const live = await api.post('/cards', { stackId: stack.id, title: liveTitle })
		const archived = await api.post('/cards', { stackId: stack.id, title: archivedTitle })
		await api.put(`/cards/${live.id}/labels/${label.id}`)
		await api.put(`/cards/${archived.id}/labels/${label.id}`)
		await api.patch(`/cards/${archived.id}`, { archived: true })

		const created = await api.put('/views', {
			name: 'Views archived ' + stamp,
			filter: { labels: [label.id] },
			groupBy: 'status',
			display: 'list',
		})
		const view = created.views[created.views.length - 1]

		try {
			// ── Server side: the feed drops the archived row AND stops counting it ──
			// The count is the half a client-only fix cannot deliver: an archived row
			// left in the payload still eats the cap budget and inflates `total`, so
			// the "showing the first N of M" banner would count invisible cards.
			const feed = await api.get(`/views/cards?fl=${label.id}`)
			expect(feed.cards.map((c) => c.title)).toEqual([liveTitle])
			expect(feed.total).toBe(1)

			// The facet opts them back in, over the same short-key wire format.
			const included = await api.get(`/views/cards?fl=${label.id}&far=include`)
			expect(included.cards.map((c) => c.title).sort()).toEqual([liveTitle, archivedTitle].sort())
			expect(included.total).toBe(2)
			const onlyArchived = await api.get(`/views/cards?fl=${label.id}&far=only`)
			expect(onlyArchived.cards.map((c) => c.title)).toEqual([archivedTitle])
			expect(onlyArchived.total).toBe(1)

			// REGRESSION GUARD: the board payload still ships archived cards. The
			// archived-cards page and its counter are built on them, so a mapper-level
			// exclusion would have silently emptied that page.
			const boardPayload = await api.get(`/boards/${board.id}`)
			const archivedRow = boardPayload.cards.find((c) => c.title === archivedTitle)
			expect(archivedRow).toBeTruthy()
			expect(archivedRow.archived).toBe(true)

			// ── Client side: the same baseline, and the chip that lifts it ──────────
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/views/${view.id}`)

			const rowLive = page.locator('.board-list-row__title', { hasText: liveTitle })
			const rowArchived = page.locator('.board-list-row__title', { hasText: archivedTitle })
			await expect(rowLive).toBeVisible({ timeout: 20_000 })
			await expect(rowArchived).toHaveCount(0)

			// Selecting "Include archived" must travel to the server as `far`, not
			// just re-filter the cached rows — otherwise the cap fix is unproven.
			const includeFeed = page.waitForRequest(
				(r) => r.url().includes('/api/views/cards') && r.url().includes('far=include'),
				{ timeout: 20_000 },
			)
			await page.locator('.board-filter-bar__trigger').click()
			await page.locator('.board-filter-bar__dim-row[data-dim="archived"]').click()
			await page.locator('.board-filter-bar__opt', { hasText: /Include archived/ }).click()
			await includeFeed
			await expect(rowArchived).toBeVisible({ timeout: 15_000 })
			await expect(rowLive).toBeVisible()

			// "Only archived" narrows to it.
			await page.locator('.board-filter-bar__opt', { hasText: /Only archived/ }).click()
			await expect(rowArchived).toBeVisible({ timeout: 15_000 })
			await expect(rowLive).toHaveCount(0, { timeout: 15_000 })

			// Clearing the facet restores the default: the archived card is gone again.
			// Reset the Archived facet itself ("Any") rather than the panel's global
			// "Clear filters" — this step is about THIS facet, and the global button is
			// covered by its own spec (#10091: it clears only rendered facets, so the
			// View's hidden saved label filter survives it).
			await page.locator('.board-filter-bar__opt', { hasText: /^Any$/ }).click()
			await page.keyboard.press('Escape')
			await expect(rowLive).toBeVisible({ timeout: 15_000 })
			await expect(rowArchived).toHaveCount(0, { timeout: 15_000 })

			// Unarchiving puts the card back in the View with no filter at all.
			await api.patch(`/cards/${archived.id}`, { archived: false })
			await page.reload()
			await expect(rowLive).toBeVisible({ timeout: 20_000 })
			await expect(rowArchived).toBeVisible({ timeout: 15_000 })
		} finally {
			await api.delete(`/views/${view.id}`).catch(() => {})
			await api.delete(`/boards/${board.id}`).catch(() => {})
		}
	})

	test('an archived BOARD is out of a View entirely, even with the Archived facet on (#10126)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })

		const stamp = Math.floor(Date.now() / 1000)
		// Two boards, one card each, each tagged with its own label. The View
		// filters to both labels, so it resolves to exactly these two rows and the
		// virtualized list stays deterministic.
		const live = await api.post('/boards', { title: 'ViewsLiveBoard ' + stamp })
		const liveLabel = await api.post('/labels', { boardId: live.id, title: 'vlb ' + stamp, color: '3366ff' })
		const liveStack = await api.post('/stacks', { boardId: live.id, title: 'To do' })
		const liveTitle = 'ViewsLiveBoard card ' + stamp
		const liveCard = await api.post('/cards', { stackId: liveStack.id, title: liveTitle })
		await api.put(`/cards/${liveCard.id}/labels/${liveLabel.id}`)

		const shelved = await api.post('/boards', { title: 'ViewsShelvedBoard ' + stamp })
		const shelvedLabel = await api.post('/labels', { boardId: shelved.id, title: 'vsb ' + stamp, color: 'ff9900' })
		const shelvedStack = await api.post('/stacks', { boardId: shelved.id, title: 'To do' })
		const shelvedTitle = 'ViewsShelvedBoard card ' + stamp
		const shelvedCard = await api.post('/cards', { stackId: shelvedStack.id, title: shelvedTitle })
		await api.put(`/cards/${shelvedCard.id}/labels/${shelvedLabel.id}`)
		// The CARD stays unarchived — only its BOARD is archived. That is the
		// reported case: a shelved board whose cards were never archived one by one.
		await api.patch(`/boards/${shelved.id}`, { archived: true })

		const created = await api.put('/views', {
			name: 'Views archived board ' + stamp,
			filter: { labels: [liveLabel.id, shelvedLabel.id] },
			groupBy: 'board',
			display: 'list',
		})
		const view = created.views[created.views.length - 1]

		try {
			const fl = `${liveLabel.id},${shelvedLabel.id}`

			// ── Server side: the archived board is not in the feed's board set ──
			const feed = await api.get(`/views/cards?fl=${fl}`)
			expect(feed.cards.map((c) => c.title)).toEqual([liveTitle])
			expect(feed.total).toBe(1)

			// … and the archived-CARDS facet cannot reach around it. That facet is
			// about archived cards on boards that are still active, so neither
			// 'include' nor 'only' may surface a row from an archived board.
			const included = await api.get(`/views/cards?fl=${fl}&far=include`)
			expect(included.cards.map((c) => c.title)).toEqual([liveTitle])
			expect(included.cards.map((c) => c.boardId)).not.toContain(shelved.id)
			const onlyArchived = await api.get(`/views/cards?fl=${fl}&far=only`)
			expect(onlyArchived.cards.map((c) => c.title)).toEqual([])
			expect(onlyArchived.total).toBe(0)

			// REGRESSION GUARD: the boards LIST still ships the archived board — the
			// boards page's Archived tab is built on it, so a filter applied to
			// BoardService::findAll() would have silently emptied that page.
			const boards = await api.get('/boards')
			const shelvedRow = boards.find((b) => b.id === shelved.id)
			expect(shelvedRow).toBeTruthy()
			expect(shelvedRow.archived).toBe(true)

			// ── Client side: the same, in the rendered View ─────────────────────
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/views/${view.id}`)

			const rowLive = page.locator('.board-list-row__title', { hasText: liveTitle })
			const rowShelved = page.locator('.board-list-row__title', { hasText: shelvedTitle })
			await expect(rowLive).toBeVisible({ timeout: 20_000 })
			await expect(rowShelved).toHaveCount(0)

			// Turning the Archived facet on still leaves the archived board out.
			const includeFeed = page.waitForRequest(
				(r) => r.url().includes('/api/views/cards') && r.url().includes('far=include'),
				{ timeout: 20_000 },
			)
			await page.locator('.board-filter-bar__trigger').click()
			await page.locator('.board-filter-bar__dim-row[data-dim="archived"]').click()
			await page.locator('.board-filter-bar__opt', { hasText: /Include archived/ }).click()
			await includeFeed
			await expect(rowLive).toBeVisible({ timeout: 15_000 })
			await expect(rowShelved).toHaveCount(0)

			// Unarchiving the board brings its card straight back — proving the row
			// was withheld by the board's archived flag and nothing else.
			await api.patch(`/boards/${shelved.id}`, { archived: false })
			const restored = await api.get(`/views/cards?fl=${fl}`)
			expect(restored.cards.map((c) => c.title).sort()).toEqual([liveTitle, shelvedTitle].sort())
		} finally {
			await api.delete(`/views/${view.id}`).catch(() => {})
			await api.delete(`/boards/${live.id}`).catch(() => {})
			await api.delete(`/boards/${shelved.id}`).catch(() => {})
		}
	})

	test('a View drops archived rows that still arrive in the feed (the client half of #10052)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })

		// The server now excludes archived rows before the cap, so in normal
		// operation none reach the browser — which would leave the client-side
		// baseline untested (and free to rot). A feed CAN still carry one: a
		// response cached before the server half shipped. So the feed is rewritten
		// in flight to carry an archived row, and the View must still not show it.
		const stamp = Math.floor(Date.now() / 1000)
		const board = await api.post('/boards', { title: 'ViewsArchivedClient ' + stamp })
		const label = await api.post('/labels', { boardId: board.id, title: 'varchc ' + stamp, color: '00cc99' })
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		const liveTitle = 'ViewsArchivedClient live ' + stamp
		const ghostTitle = 'ViewsArchivedClient ghost ' + stamp
		const live = await api.post('/cards', { stackId: stack.id, title: liveTitle })
		await api.put(`/cards/${live.id}/labels/${label.id}`)

		const created = await api.put('/views', {
			name: 'Views archived client ' + stamp,
			filter: { labels: [label.id] },
			groupBy: 'status',
			display: 'list',
		})
		const view = created.views[created.views.length - 1]

		try {
			await ncLogin(page)
			// Clone the live row into an archived one, so the injected card differs
			// from a rendered one in exactly the archived flag and nothing else.
			await page.route('**/apps/kanso/api/views/cards*', async (route) => {
				// A request the page abandons (navigation, an in-flight refetch the
				// router cancels) is already handled by the time we get back from
				// fetch(), and fulfilling it then throws. Swallowing that cannot make
				// this test pass vacuously: the "Include archived" step below requires
				// the injected row to actually be there.
				try {
					const response = await route.fetch()
					const body = await response.json()
					const original = (body.cards || []).find((c) => c.title === liveTitle)
					if (original) {
						body.cards.push({ ...original, id: original.id + 1000000, title: ghostTitle, archived: true })
						body.total = body.cards.length
					}
					await route.fulfill({ response, json: body })
				} catch {
					await route.fallback().catch(() => {})
				}
			})

			await page.goto(`${BASE}/index.php/apps/kanso#/views/${view.id}`)
			await expect(page.locator('.board-list-row__title', { hasText: liveTitle })).toBeVisible({ timeout: 20_000 })
			await expect(page.locator('.board-list-row__title', { hasText: ghostTitle })).toHaveCount(0)

			// …and the facet still lets it through when asked for.
			await page.locator('.board-filter-bar__trigger').click()
			await page.locator('.board-filter-bar__dim-row[data-dim="archived"]').click()
			await page.locator('.board-filter-bar__opt', { hasText: /Include archived/ }).click()
			await expect(page.locator('.board-list-row__title', { hasText: ghostTitle })).toBeVisible({ timeout: 15_000 })
		} finally {
			await page.unroute('**/apps/kanso/api/views/cards*').catch(() => {})
			await api.delete(`/views/${view.id}`).catch(() => {})
			await api.delete(`/boards/${board.id}`).catch(() => {})
		}
	})

	test('create a view from the nav (UI, not the API) → opens it → inline rename persists (#3891)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/`)

		// The "New view" create entry is present in the Views nav section (this is the
		// only way to make the first view; it exists even with zero views).
		const newViewEntry = page.locator('.app-nav__view-new').first()
		await expect(newViewEntry).toBeVisible({ timeout: 15_000 })
		await newViewEntry.click()

		// Clicking it creates a view and opens the View surface at /views/:id.
		await expect(page).toHaveURL(/#\/views\/[^/]+$/, { timeout: 15_000 })
		const uiViewId = page.url().split('/views/')[1]
		expect(uiViewId).toBeTruthy()

		// Rename it in place via the editable title; the new name persists to the nav.
		const newName = 'UI View ' + Math.floor(Date.now() / 1000)
		await page.locator('.view-page__title').click()
		const input = page.locator('.view-page__title-input')
		await expect(input).toBeVisible({ timeout: 5_000 })
		await input.fill(newName)
		await input.press('Enter')

		await expect(
			page.locator('.app-navigation').getByText(newName, { exact: true }),
		).toBeVisible({ timeout: 10_000 })

		// Cleanup the UI-created view.
		await api.delete(`/views/${uiViewId}`).catch(() => {})
	})
})
