// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE, me, currentAuth } from './helpers.js'

test.describe('My tasks (#3441)', () => {
	const state = { boardId: 0, stackId: 0, assignedCardId: 0, unassignedCardId: 0, title: '', emptyBoardId: 0, emptyBoardTitle: '', doneCardId: 0, doneTitle: '' }

	test.beforeAll(async () => {
		state.title = 'MyTask ' + Math.floor(Date.now() / 1000)
		const board = await api.post('/boards', { title: 'MyTasks ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'To do' })).id
		state.assignedCardId = (await api.post('/cards', { stackId: state.stackId, title: state.title })).id
		state.unassignedCardId = (await api.post('/cards', { stackId: state.stackId, title: 'Not mine ' + Math.floor(Date.now() / 1000) })).id
		// Assign only the first card to the current user.
		await api.put(`/cards/${state.assignedCardId}/assignees/${me}`)

		// A second board where nothing is assigned to me — the hub's board
		// filter pointed at it must not claim I have no tasks anywhere.
		state.emptyBoardTitle = 'MyTasksEmpty ' + Math.floor(Date.now() / 1000)
		state.emptyBoardId = (await api.post('/boards', { title: state.emptyBoardTitle })).id

		// A card I completed just now — the fixture for the opt-in "recently
		// done" section (#10061). Assigned first, then marked done, so it is
		// out of the open feed and inside the recency window.
		state.doneTitle = 'MyDoneTask ' + Math.floor(Date.now() / 1000)
		state.doneCardId = (await api.post('/cards', { stackId: state.stackId, title: state.doneTitle })).id
		await api.put(`/cards/${state.doneCardId}/assignees/${me}`)
		await api.patch(`/cards/${state.doneCardId}`, { done: true })
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
		if (state.emptyBoardId) await api.delete(`/boards/${state.emptyBoardId}`).catch(() => {})
	})

	test('my-cards returns only cards assigned to me', async () => {
		const cards = await api.get('/my-cards')
		const ids = cards.map((c) => c.id)
		expect(ids).toContain(state.assignedCardId)
		expect(ids).not.toContain(state.unassignedCardId)
		const mine = cards.find((c) => c.id === state.assignedCardId)
		expect(mine.boardId).toBe(state.boardId)
		expect(mine.boardTitle).toBeTruthy()
	})

	test('a done card drops out of my tasks', async () => {
		const doneCardId = (await api.post('/cards', { stackId: state.stackId, title: 'Finish me ' + Math.floor(Date.now() / 1000) })).id
		await api.put(`/cards/${doneCardId}/assignees/${me}`)
		expect((await api.get('/my-cards')).map((c) => c.id)).toContain(doneCardId)

		await api.patch(`/cards/${doneCardId}`, { done: true })
		expect((await api.get('/my-cards')).map((c) => c.id)).not.toContain(doneCardId)
	})

	test('My tasks panel lists the card and deep-links to it', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/my-tasks`)

		const row = page.locator('.my-cards-view__row', { hasText: state.title })
		await expect(row).toBeVisible({ timeout: 15_000 })

		await row.click()
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 10_000 })
		await expect(page).toHaveURL(new RegExp(`/board/${state.boardId}/card/${state.assignedCardId}`))
	})

	// #10068/1 — the rows are tabbable role=button elements, so keyboard focus
	// must be visible (WCAG 2.4.7). The style used to set `outline: none`.
	test('a keyboard-focused row shows a focus ring', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/my-tasks`)

		const row = page.locator('.my-cards-view__row', { hasText: state.title })
		await expect(row).toBeVisible({ timeout: 15_000 })

		// Reach the row by keyboard so :focus-visible applies.
		await page.keyboard.press('Tab')
		await row.focus()

		const ring = await row.evaluate((el) => {
			const style = getComputedStyle(el)
			return {
				focusVisible: el.matches(':focus-visible'),
				outlineStyle: style.outlineStyle,
				outlineWidth: parseFloat(style.outlineWidth),
			}
		})
		expect(ring.focusVisible).toBe(true)
		expect(ring.outlineStyle).not.toBe('none')
		expect(ring.outlineWidth).toBeGreaterThan(0)
	})

	// #10068/2 — role="button" must respond to Space, not only Enter.
	test('Space on a focused row opens the card', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/my-tasks`)

		const row = page.locator('.my-cards-view__row', { hasText: state.title })
		await expect(row).toBeVisible({ timeout: 15_000 })

		await row.focus()
		await page.keyboard.press('Space')

		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 10_000 })
		await expect(page).toHaveURL(new RegExp(`/board/${state.boardId}/card/${state.assignedCardId}`))
	})

	// #10068/3 — the empty block fires AFTER the board filter, so unfiltered
	// copy ("no tasks assigned to you") would state something false.
	test('filtering to a board with nothing assigned says so, not "no tasks anywhere"', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/my-work`)
		await expect(page.locator('.my-cards-view')).toBeVisible({ timeout: 15_000 })

		// Filter the hub to the board where nothing is assigned to me.
		await page.locator('#my-work-board-filter').click()
		await page.locator('#my-work-board-filter').fill(state.emptyBoardTitle)
		await page.locator('li[role="option"]', { hasText: state.emptyBoardTitle }).first().click()

		const empty = page.locator('.my-cards-view .empty-content')
		await expect(empty).toBeVisible({ timeout: 10_000 })
		await expect(empty).toContainText('No tasks on this board')
		await expect(empty).not.toContainText('No tasks assigned to you')
	})

	// #10068/4 — the 200-row cap used to be silent, and the nav badge counted
	// the same truncated array (a permanently frozen, wrong "200"). The
	// response is stubbed at the cap so the assertion doesn't need 201 cards.
	test('a capped feed is announced on the page and as a "200+" nav badge', async ({ page }) => {
		const capped = Array.from({ length: 200 }, (_, i) => ({
			id: 900000 + i,
			boardId: state.boardId,
			boardTitle: 'Capped board',
			stackTitle: 'To do',
			title: 'Capped task ' + i,
			duedate: null,
			priority: 0,
			doneAt: 0,
			startedAt: 0,
			parentCardId: null,
		}))
		await page.route('**/apps/kanso/api/my-cards', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				headers: { 'X-Kanso-Limit': '200', 'X-Kanso-Truncated': '1' },
				body: JSON.stringify(capped),
			}),
		)

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/my-tasks`)

		await expect(page.locator('.my-cards-view__truncation')).toContainText(
			'Only the first 200 assigned cards are loaded',
			{ timeout: 15_000 },
		)

		const tasksEntry = page.locator('.app-navigation-entry-wrapper', {
			has: page.locator('.app-navigation-entry-link', { hasText: 'My Tasks' }),
		})
		await expect(tasksEntry.locator('.app-navigation-entry__counter-wrapper')).toHaveText('200+', {
			timeout: 10_000,
		})
	})

	// ---- recently done: the opt-in second feed (#10061) --------------------
	//
	// The product requirement is that completed work is visible but "shouldn't
	// list everything unless we ask for it". So: a separate endpoint, bounded
	// by a recency window AND a row cap, and requested only on expand.

	test('recently-done returns my completed cards, and the open feed still does not', async () => {
		const done = await api.get('/my-cards/recently-done')
		const doneIds = done.map((c) => c.id)
		expect(doneIds).toContain(state.doneCardId)
		// Someone else's completed card is not mine to see here either.
		expect(doneIds).not.toContain(state.unassignedCardId)

		// The default feed is untouched: open work only, exactly as before.
		const open = (await api.get('/my-cards')).map((c) => c.id)
		expect(open).toContain(state.assignedCardId)
		expect(open).not.toContain(state.doneCardId)
	})

	test('recently-done reports the bounds it was built with', async () => {
		// Both bounds are reported so the section can say what it covers rather
		// than presenting a bounded slice as everything the user ever finished.
		const r = await fetch(`${BASE}/index.php/apps/kanso/api/my-cards/recently-done`, {
			headers: { 'OCS-APIREQUEST': 'true', Authorization: currentAuth },
		})
		expect(r.ok).toBe(true)
		expect(Number(r.headers.get('x-kanso-done-window-days'))).toBeGreaterThan(0)
		expect(Number(r.headers.get('x-kanso-limit'))).toBeGreaterThan(0)
		expect(r.headers.get('x-kanso-truncated')).toBe('0')
	})

	test('a completed card hidden from the viewer never reaches their recently-done feed', async ({ peer }) => {
		// The denial case, at the SQL level. Assignment grants no visibility
		// (#3743): a card narrowed to the provider side AFTER it was assigned
		// keeps its assignee row, so only the visibility scope on the query can
		// keep it out of the external member's feed. (Revoking board access
		// cannot be the probe here — AclService::delete cascades the assignee
		// rows away, so it would pass without any scoping at all.)
		const token = 'MyDoneVis ' + Math.floor(Date.now() / 1000)
		const board = await api.post('/boards', { title: token })
		try {
			const stack = await api.post('/stacks', { boardId: board.id, title: 'Lane' })
			// The peer joins as an EXTERNAL member — the side a provider-internal
			// card is not visible from.
			await api.post(`/boards/${board.id}/acl`, {
				participant: peer.user,
				participantType: 'user',
				permission: 3,
				role: 'external',
			})
			const card = await api.post('/cards', { stackId: stack.id, title: token })
			await api.put(`/cards/${card.id}/assignees/${peer.user}`)
			await api.patch(`/cards/${card.id}`, { done: true })

			// Visible (public) → the completed card is in the peer's feed.
			expect((await peer.api.get('/my-cards/recently-done')).map((c) => c.id)).toContain(card.id)

			// Narrowed to provider-internal → hidden from the external peer, even
			// though they are still assigned to it and still read the board.
			await api.patch(`/cards/${card.id}`, { visibility: 'internal' })
			const after = await peer.api.get('/my-cards/recently-done')
			expect(after.map((c) => c.id)).not.toContain(card.id)
			expect(JSON.stringify(after)).not.toContain(token)

			// A filter, not a delete: re-widening restores it.
			await api.patch(`/cards/${card.id}`, { visibility: 'public' })
			expect((await peer.api.get('/my-cards/recently-done')).map((c) => c.id)).toContain(card.id)
		} finally {
			await api.delete(`/boards/${board.id}`).catch(() => {})
		}
	})

	test('a completed card on a board I cannot read is not in my recently-done feed', async ({ peer }) => {
		// The readable-board-set half of the same boundary: the peer's private
		// board is never in my findAll(), so nothing on it can be queried.
		const token = 'MyDonePeer ' + Math.floor(Date.now() / 1000)
		const board = await peer.api.post('/boards', { title: token })
		try {
			const stack = await peer.api.post('/stacks', { boardId: board.id, title: 'Lane' })
			const card = await peer.api.post('/cards', { stackId: stack.id, title: token })
			await peer.api.put(`/cards/${card.id}/assignees/${peer.user}`)
			await peer.api.patch(`/cards/${card.id}`, { done: true })

			expect((await peer.api.get('/my-cards/recently-done')).map((c) => c.id)).toContain(card.id)

			const mine = await api.get('/my-cards/recently-done')
			expect(mine.map((c) => c.id)).not.toContain(card.id)
			expect(JSON.stringify(mine)).not.toContain(token)
		} finally {
			await peer.api.delete(`/boards/${board.id}`).catch(() => {})
		}
	})

	test('the recently done section requests nothing until it is expanded', async ({ page }) => {
		// THE acceptance criterion: the default page load must not pay for the
		// completed set. The counter is installed before navigation, so any
		// mount-time or poll-driven fetch would be caught.
		let hits = 0
		page.on('request', (r) => {
			if (r.url().includes('/api/my-cards/recently-done')) hits++
		})

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/my-tasks`)

		const toggle = page.locator('.my-cards-view__done-toggle')
		await expect(toggle).toBeVisible({ timeout: 15_000 })
		await expect(toggle).toHaveAttribute('aria-expanded', 'false')
		// Sit on the loaded page long enough for a stray fetch to show up.
		await page.waitForTimeout(2000)
		expect(hits, 'nothing may be requested before the user asks').toBe(0)

		await toggle.click()
		await expect(page.locator('.my-cards-view__row--done', { hasText: state.doneTitle }))
			.toBeVisible({ timeout: 15_000 })
		await expect(toggle).toHaveAttribute('aria-expanded', 'true')
		expect(hits, 'expanding fetches it exactly once').toBe(1)

		// Collapsing and re-expanding must not re-request it either.
		await toggle.click()
		await toggle.click()
		await page.waitForTimeout(1500)
		expect(hits).toBe(1)
	})

	test('recently done tasks never reach the My Tasks nav badge', async ({ page }) => {
		// The badge means "tasks needing attention". Folding completed work in
		// would make it wrong in a new way, so expanding the section must not
		// move it by a single unit.
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/my-tasks`)

		const counter = page
			.locator('.app-navigation-entry-wrapper', {
				has: page.locator('.app-navigation-entry-link', { hasText: 'My Tasks' }),
			})
			.locator('.app-navigation-entry__counter-wrapper')

		const toggle = page.locator('.my-cards-view__done-toggle')
		await expect(toggle).toBeVisible({ timeout: 15_000 })
		await expect(counter).toBeVisible({ timeout: 15_000 })
		const before = (await counter.textContent()).trim()
		// The badge counts the OPEN feed and nothing else.
		expect(before).toBe(String((await api.get('/my-cards')).length))

		await toggle.click()
		await expect(page.locator('.my-cards-view__row--done', { hasText: state.doneTitle }))
			.toBeVisible({ timeout: 15_000 })
		await page.waitForTimeout(1500)

		expect((await counter.textContent()).trim()).toBe(before)
	})
})
