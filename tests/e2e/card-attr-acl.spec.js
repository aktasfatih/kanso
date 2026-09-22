// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Card #10703 — the card modal's assign and label controls are writes, so they
// belong to editors only.
//
// The attribute bar rendered the "Assign" pill, the per-assignee "×" and the
// "Label" picker for every viewer, including a member shared in with READ only.
// Clicking any of them hit the server, which correctly answered 403, so the
// only thing the UI achieved was inviting a read-only member to fail.
//
// The server-side denial is unchanged and is pinned here first: hiding a
// control is presentation, never the authorization check.

import { test, expect, api, ncLogin, BASE, me } from './helpers.js'

test.describe('Assign and label controls are editors only (#10703)', () => {
	// A second identity logs in explicitly, so this describe must NOT inherit the
	// shared admin storageState (it would silently stay admin and false-pass).
	test.use({ storageState: { cookies: [], origins: [] }, viewport: { width: 1600, height: 900 } })

	const state = { boardId: 0, cardId: 0, labelId: 0, boardUrl: '' }

	test.beforeAll(async ({ peer }) => {
		const board = await api.post('/boards', { title: 'Card attr ACL ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Inbox' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Read-only card' })
		state.cardId = card.id

		// Seed one assignee and one label, so the READ view of both still has
		// something to show once the write affordances are gone.
		await api.put(`/cards/${card.id}/assignees/${me}`)
		const label = await api.post('/labels', { boardId: board.id, title: 'Shipped', color: 'e11d48' })
		state.labelId = label.id
		await api.put(`/cards/${card.id}/labels/${label.id}`)

		// READ only (1) — no EDIT bit, so canEdit is false for the peer.
		await api.post(`/boards/${board.id}/acl`, {
			participant: peer.user,
			participantType: 'user',
			permission: 1,
		})
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('the server refuses an assign and a label write from a viewer', async ({ peer }) => {
		// The UI gate below only hides a dead affordance — this pins that the real
		// gate is server-side, so hiding the pills never becomes the only check.
		const assign = await peer.api.raw('PUT', `/cards/${state.cardId}/assignees/${peer.user}`)
		expect(assign.status).toBe(403)
		const unassign = await peer.api.raw('DELETE', `/cards/${state.cardId}/assignees/${me}`)
		expect(unassign.status).toBe(403)
		const label = await peer.api.raw('DELETE', `/cards/${state.cardId}/labels/${state.labelId}`)
		expect(label.status).toBe(403)
	})

	test('a viewer gets no Assign pill, no assignee "×" and no label picker', async ({ browser, peer }) => {
		const ctx = await browser.newContext({ viewport: { width: 1600, height: 900 } })
		try {
			const page = await ctx.newPage()
			await ncLogin(page, { user: peer.user, pass: peer.pass })
			await page.goto(state.boardUrl)
			await page.waitForSelector('.board-view__header', { timeout: 15_000 })
			await page.locator('.card-tile').filter({ hasText: 'Read-only card' }).click()
			await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

			const attrbar = page.locator('.card-modal__attrbar')

			// The card still READS in full: the assignee and the label are shown…
			await expect(attrbar.locator('.card-modal__assignee-name')).toHaveCount(1)
			await expect(attrbar.locator('.card-modal__label-chip', { hasText: 'Shipped' })).toBeVisible()

			// …but none of the three write affordances is offered.
			await expect(attrbar.locator('button[data-pill="assign"]')).toHaveCount(0)
			await expect(attrbar.locator('.card-modal__assignee-pill .card-modal__pill-x')).toHaveCount(0)
			await expect(attrbar.locator('button[data-pill="label"]')).toHaveCount(0)
		} finally {
			await ctx.close()
		}
	})
})

test.describe('Assign and label controls stay available to editors (#10703)', () => {
	test.use({ viewport: { width: 1600, height: 900 } })

	const state = { boardId: 0, cardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Card attr editor ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Inbox' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Editable card' })
		state.cardId = card.id
		await api.put(`/cards/${card.id}/assignees/${me}`)
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('an editor still gets the Assign pill, the assignee "×" and the label picker', async ({ page }) => {
		await page.goto(state.boardUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await page.locator('.card-tile').filter({ hasText: 'Editable card' }).click()
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		const attrbar = page.locator('.card-modal__attrbar')
		await expect(attrbar.locator('button[data-pill="assign"]')).toBeVisible()
		await expect(attrbar.locator('.card-modal__assignee-pill .card-modal__pill-x')).toHaveCount(1)
		await expect(attrbar.locator('button[data-pill="label"]')).toBeVisible()

		// And they still work — the gate is presentation, not a new restriction.
		await attrbar.locator('.card-modal__assignee-pill .card-modal__pill-x').click()
		await expect(attrbar.locator('.card-modal__assignee-pill')).toHaveCount(0)
		await expect.poll(
			async () => (await api.get(`/cards/${state.cardId}`)).assigneeIds.length,
			{ timeout: 10_000 },
		).toBe(0)
	})
})
