// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10070 — the review gate, over the real API and a real database. A card whose
// requested reviews are not all approved must not reach the done state by ANY
// route, and must complete normally the moment they are.
//
// API-level on purpose: the point is that four different write paths converge on
// one gate, and each path is one request. The board carries Review / Done /
// role-less columns so the two-hop laundering route is reachable exactly as a
// user would drag it.

import { test, expect, api, me } from './helpers.js'

const ROLE_NONE = 0
const ROLE_REVIEW = 4
const ROLE_DONE = 5

async function doneAt(cardId) {
	return Number((await api.get(`/cards/${cardId}`)).doneAt)
}

test.describe('Review gate — every route into done', () => {
	const state = { boardId: 0, todoId: 0, reviewId: 0, limboId: 0, doneId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: `Review Gate E2E ${Date.now()}` })
		state.boardId = board.id
		const mk = async (title, role) => {
			const s = await api.post('/stacks', { boardId: board.id, title })
			await api.patch(`/stacks/${s.id}`, { role })
			return s.id
		}
		state.todoId = await mk('To Do', ROLE_NONE)
		state.reviewId = await mk('Review', ROLE_REVIEW)
		// The laundering column: role-less, which is the DEFAULT for any column a
		// user creates, so one is nearly always sitting on the board.
		state.limboId = await mk('Notes', ROLE_NONE)
		state.doneId = await mk('Done', ROLE_DONE)
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	// A fresh card sitting in Review with one unapproved review requested on it.
	async function gatedCard(title) {
		const card = await api.post('/cards', { stackId: state.reviewId, title })
		await api.put(`/cards/${card.id}/reviews/${me}`)
		return card
	}

	test('the API refuses done: true while a review is unapproved', async () => {
		const card = await gatedCard('API done alias')
		const r = await api.raw('PATCH', `/cards/${card.id}`, { done: true })
		expect(r.status).toBe(403)
		expect(await doneAt(card.id)).toBe(0)
	})

	test('the API refuses status: done while a review is unapproved', async () => {
		const card = await gatedCard('API status done')
		const r = await api.raw('PATCH', `/cards/${card.id}`, { status: 'done' })
		expect(r.status).toBe(403)
		expect(await doneAt(card.id)).toBe(0)
	})

	test('the bulk action bar skips the gated card and completes the rest', async () => {
		// The bulk bar's "Mark done" and the `d` shortcut both send done: true.
		const gated = await gatedCard('Bulk gated')
		const free = await api.post('/cards', { stackId: state.reviewId, title: 'Bulk free' })

		const result = await api.post('/cards/bulk', {
			cardIds: [gated.id, free.id],
			action: 'set_status',
			params: { status: 'done' },
		})

		expect(result.ok).toEqual([free.id])
		expect(result.skipped).toEqual([{ id: gated.id, reason: 'forbidden' }])
		expect(await doneAt(gated.id)).toBe(0)
		expect(await doneAt(free.id)).toBeGreaterThan(0)
	})

	test('a hop through a role-less column cannot launder the gate', async () => {
		// The two ordinary drags that used to defeat it: Review → Notes → Done.
		// The first hop is legitimate and must still succeed.
		const card = await gatedCard('Laundered by two drags')

		await api.post(`/cards/${card.id}/move`, { targetStackId: state.limboId })
		expect((await api.get(`/cards/${card.id}`)).stackId).toBe(state.limboId)

		const r = await api.raw('POST', `/cards/${card.id}/move`, { targetStackId: state.doneId })
		expect(r.status).toBe(403)

		const after = await api.get(`/cards/${card.id}`)
		expect(Number(after.doneAt)).toBe(0)
		expect(after.stackId).toBe(state.limboId)
	})

	test('completing the last subtask does not auto-complete a gated parent', async () => {
		const parent = await gatedCard('Gated parent')
		const child = await api.post('/cards', { stackId: state.todoId, title: 'Only subtask' })
		await api.put(`/cards/${child.id}/parent`, { parentCardId: parent.id })

		// The child completes on its own - it carries no reviews.
		await api.patch(`/cards/${child.id}`, { done: true })
		expect(await doneAt(child.id)).toBeGreaterThan(0)

		// The parent stays open rather than being stamped done behind the gate.
		expect(await doneAt(parent.id)).toBe(0)
	})

	test('approving the review lets every one of those routes through', async () => {
		// Not over-broad: the same card, the same requests, once approved.
		const card = await gatedCard('Approved and done')
		const detail = await api.get(`/cards/${card.id}`)
		await api.patch(`/cards/${card.id}/reviews/${detail.reviews[0].id}`, { state: 'approved' })

		// The two-hop route now completes...
		await api.post(`/cards/${card.id}/move`, { targetStackId: state.limboId })
		await api.post(`/cards/${card.id}/move`, { targetStackId: state.doneId })
		expect(await doneAt(card.id)).toBeGreaterThan(0)

		// ...and so does done: true on a card that was never reviewed at all, so a
		// board that does not use reviews is untouched by any of this.
		const plain = await api.post('/cards', { stackId: state.todoId, title: 'No reviews here' })
		await api.patch(`/cards/${plain.id}`, { done: true })
		expect(await doneAt(plain.id)).toBeGreaterThan(0)
	})
})
