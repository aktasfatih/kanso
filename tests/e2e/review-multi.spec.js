// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, me } from './helpers.js'

// #3468 (multiple reviews per card, incl. same reviewer + different type) and
// #3469 (request-changes reason → comment), driven through the public API.
test.describe('Multiple reviews + request-changes reason', () => {
	let boardId = 0
	let cardId = 0
	let typeId = 0

	test.beforeAll(async () => {
		boardId = (await api.post('/boards', { title: 'Review-Multi E2E' })).id
		const stackId = (await api.post('/stacks', { boardId, title: 'Do' })).id
		cardId = (await api.post('/cards', { stackId, title: 'Review me' })).id
		typeId = (await api.post('/review-types', { boardId, title: 'QA', color: '31CC7C' })).id
	})

	test.afterAll(async () => {
		if (boardId) await api.delete(`/boards/${boardId}`).catch(() => {})
	})

	test('same reviewer can hold two review types; request-changes posts a reason comment', async () => {
		// Two reviews from the SAME reviewer: one untyped, one typed (#3468).
		await api.put(`/cards/${cardId}/reviews/${me}`)
		await api.put(`/cards/${cardId}/reviews/${me}`, { reviewTypeId: typeId })

		let card = await api.get(`/cards/${cardId}`)
		const mine = card.reviews.filter((r) => r.reviewer === me)
		expect(mine).toHaveLength(2)
		const untyped = mine.find((r) => !r.reviewTypeId)
		const typed = mine.find((r) => r.reviewTypeId === typeId)
		expect(untyped).toBeTruthy()
		expect(typed).toBeTruthy()

		// Request changes on the typed review WITH a reason (#3469).
		await api.patch(`/cards/${cardId}/reviews/${typed.id}`, {
			state: 'changes_requested',
			reason: 'Please add tests',
		})

		card = await api.get(`/cards/${cardId}`)
		expect(card.reviews.find((r) => r.id === typed.id).state).toBe('changes_requested')

		// The reason landed as a comment by the reviewer.
		const comments = await api.get(`/cards/${cardId}/comments`)
		const reasonComment = comments.find((c) => (c.body || '').includes('Please add tests'))
		expect(reasonComment).toBeTruthy()
		expect(reasonComment.body).toContain('Requested changes')
		expect(reasonComment.author).toBe(me)

		// Withdraw one review by id - the other remains.
		await api.delete(`/cards/${cardId}/reviews/${untyped.id}`)
		card = await api.get(`/cards/${cardId}`)
		expect(card.reviews.filter((r) => r.reviewer === me)).toHaveLength(1)
	})
})
