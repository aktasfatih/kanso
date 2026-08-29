// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, me, ncLogin, BASE } from './helpers.js'

// #9862 — a View's filter now travels to the server, which applies it BEFORE the
// cross-board feed's 5000-card cap so the cap slices the MATCHING set. That fixes
// the miss-past-the-cap bug, but it creates a second one if the facets are built
// naively: if the assignee/owner options were derived from the RETURNED rows,
// filtering to one person would collapse the facet to that person — and at zero
// matches the facet would disappear entirely (BoardFilterBar hides it on an empty
// participants list), leaving no way to add a second person back.
//
// So the envelope ships the participant VOCABULARY separately from the rows,
// accumulated across the whole readable set before the filter. This spec pins
// that with TWO identities, because a single-user board can never lose anyone
// from the facet and would false-pass.

test.describe('View filter facets survive a narrow server-side filter (#9862)', () => {
	// Two identities take part (the peer owns/holds cards this user filters to), and
	// the browser logs in explicitly — so this describe must NOT inherit the shared
	// admin storageState, which would silently keep us as admin and false-pass.
	test.use({ storageState: { cookies: [], origins: [] }, viewport: { width: 1280, height: 900 } })

	const state = { boardId: 0, labelId: 0, viewId: '', mineTitle: '', theirsTitle: '', viewUrl: '' }

	test.beforeAll(async ({ peer }) => {
		const stamp = Math.floor(Date.now() / 1000)
		const board = await api.post('/boards', { title: 'ViewFacets ' + stamp })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })

		// READ | EDIT so the peer is a real board participant and can be assigned.
		await api.post(`/boards/${board.id}/acl`, {
			participant: peer.user,
			participantType: 'user',
			permission: 3,
			role: 'internal',
		})

		state.mineTitle = 'ViewFacets mine ' + stamp
		state.theirsTitle = 'ViewFacets theirs ' + stamp
		const label = await api.post('/labels', { boardId: board.id, title: 'vfacets ' + stamp, color: '00ccff' })
		state.labelId = label.id
		const mine = await api.post('/cards', { stackId: stack.id, title: state.mineTitle })
		const theirs = await api.post('/cards', { stackId: stack.id, title: state.theirsTitle })
		await api.put(`/cards/${mine.id}/assignees/${me}`)
		await api.put(`/cards/${theirs.id}/assignees/${peer.user}`)
		await api.put(`/cards/${mine.id}/labels/${label.id}`)
		await api.put(`/cards/${theirs.id}/labels/${label.id}`)

		// The View is pre-narrowed to this spec's own label so it resolves to exactly
		// these two cards — the list is virtualised, so an unfiltered cross-board feed
		// would leave them off-screen. The ASSIGNEE filter under test is then layered
		// on top from the UI.
		const created = await api.put('/views', {
			name: 'ViewFacets ' + stamp,
			filter: { labels: [label.id] },
			groupBy: 'status',
			display: 'list',
		})
		state.viewId = created.views[created.views.length - 1].id
		state.viewUrl = `${BASE}/index.php/apps/kanso#/views/${state.viewId}`
	})

	test.afterAll(async () => {
		if (state.viewId) await api.delete(`/views/${state.viewId}`).catch(() => {})
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('the feed endpoint applies the filter server-side and still ships the facet vocabulary', async ({ peer }) => {
		// The UI test below drives the same path through the browser; this pins the
		// wire contract directly against the real endpoint (the unit tests mock the
		// mappers, so only this proves the controller actually binds the short keys).
		const label = state.labelId
		const all = await api.get(`/views/cards?fl=${label}`)
		expect(all.cards.map((c) => c.title).sort()).toEqual([state.mineTitle, state.theirsTitle].sort())
		expect(all.total).toBe(2)

		// Narrowing by assignee is applied by the SERVER: the row count drops…
		const narrowed = await api.get(`/views/cards?fl=${label}&fa=${encodeURIComponent(peer.user)}`)
		expect(narrowed.cards.map((c) => c.title)).toEqual([state.theirsTitle])
		expect(narrowed.total).toBe(1)
		// …and `total` is the MATCHING count, so the capped banner stays honest.
		expect(narrowed.total).toBeLessThan(all.total)

		// The facet vocabulary is NOT narrowed with the rows — it still carries both
		// identities even when the filter matches nothing at all.
		const none = await api.get(`/views/cards?fl=${label}&fa=${encodeURIComponent(peer.user)}&fp=4`)
		expect(none.cards).toEqual([])
		expect(none.total).toBe(0)
		expect(none.participants).toEqual(expect.arrayContaining([me, peer.user]))

		// An unknown key and an unrecognised value are ignored, never rejected and
		// never a reason to drop a row (an older or newer client must still work).
		const tolerant = await api.get(`/views/cards?fl=${label}&fzz=whatever&fd=someday`)
		expect(tolerant.cards.map((c) => c.title).sort()).toEqual([state.mineTitle, state.theirsTitle].sort())
	})

	test('filtering to one assignee still offers everyone — even at zero matches', async ({ page, user, peer }) => {
		await ncLogin(page, { user: user.user, pass: user.pass })

		const rowMine = page.locator('.board-list-row__title', { hasText: state.mineTitle })
		const rowTheirs = page.locator('.board-list-row__title', { hasText: state.theirsTitle })
		const trigger = page.locator('.board-filter-bar__trigger')
		const assigneesDim = page.locator('.board-filter-bar__dim-row[data-dim="assignees"]')
		const optFor = (uid) => page.locator('.board-filter-bar__opt-text', { hasText: new RegExp(`^${uid}$`) })

		await page.goto(state.viewUrl)
		await expect(rowMine).toBeVisible({ timeout: 20_000 })
		await expect(rowTheirs).toBeVisible({ timeout: 15_000 })

		// Baseline: the facet offers both identities.
		await trigger.click()
		await assigneesDim.click()
		await expect(optFor(me)).toBeVisible({ timeout: 10_000 })
		await expect(optFor(peer.user)).toBeVisible()

		// ── The filter really does travel to the server ─────────────────────────
		// Pin the wire contract, not just the rendered result: selecting the peer
		// must issue a feed request carrying the `fa` short key. Without this the
		// spec would still pass on a purely client-side filter — the bug being fixed.
		const filteredFeed = page.waitForRequest(
			(r) => r.url().includes('/api/views/cards') && r.url().includes(`fa=${encodeURIComponent(peer.user)}`),
			{ timeout: 20_000 },
		)
		await optFor(peer.user).click()
		await filteredFeed

		// Only the peer's card survives…
		await expect(rowTheirs).toBeVisible({ timeout: 15_000 })
		await expect(rowMine).toHaveCount(0, { timeout: 15_000 })

		// …and the facet has NOT collapsed to the survivor: this user is still
		// offered, so a second person can be added on top of the first.
		await expect(optFor(me)).toBeVisible()
		await expect(optFor(peer.user)).toBeVisible()
		await optFor(me).click()
		await expect(rowMine).toBeVisible({ timeout: 15_000 })
		await expect(rowTheirs).toBeVisible()

		// ── Zero matches: the hard case ─────────────────────────────────────────
		// Narrow to the peer plus a priority neither card carries, so the server
		// returns NO rows at all. A row-derived facet would vanish here.
		await optFor(me).click()
		await expect(rowMine).toHaveCount(0, { timeout: 15_000 })
		await page.locator('.board-filter-bar__back').click()
		await page.locator('.board-filter-bar__dim-row[data-dim="priorities"]').click()
		await page.locator('.board-filter-bar__opt', { hasText: /Urgent/ }).click()
		await expect(rowTheirs).toHaveCount(0, { timeout: 15_000 })

		// The Assignees dimension row is still listed (it hides on an empty
		// participants list), and still offers BOTH identities.
		await page.locator('.board-filter-bar__back').click()
		await expect(assigneesDim).toBeVisible()
		await assigneesDim.click()
		await expect(optFor(me)).toBeVisible()
		await expect(optFor(peer.user)).toBeVisible()

		// The Owner facet reads the same vocabulary, so it survives too.
		await page.locator('.board-filter-bar__back').click()
		const ownersDim = page.locator('.board-filter-bar__dim-row[data-dim="owners"]')
		await expect(ownersDim).toBeVisible()
		await ownersDim.click()
		await expect(optFor(me)).toBeVisible()
	})
})
