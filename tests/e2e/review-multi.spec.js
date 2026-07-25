// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const AUTH = 'Basic ' + Buffer.from('admin:admin').toString('base64')

async function api(method, path, body) {
	const r = await fetch(API + path, {
		method,
		headers: { ...HEADERS, Authorization: AUTH },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`${method} ${path} → ${r.status}: ${await r.text()}`)
	const text = await r.text()
	return text ? JSON.parse(text) : null
}

// #3468 (multiple reviews per card, incl. same reviewer + different type) and
// #3469 (request-changes reason → comment), driven through the public API.
test.describe('Multiple reviews + request-changes reason', () => {
	let boardId = 0
	let cardId = 0
	let typeId = 0

	test.beforeAll(async () => {
		boardId = (await api('POST', '/boards', { title: 'Review-Multi E2E' })).id
		const stackId = (await api('POST', '/stacks', { boardId, title: 'Do' })).id
		cardId = (await api('POST', '/cards', { stackId, title: 'Review me' })).id
		typeId = (await api('POST', '/review-types', { boardId, title: 'QA', color: '31CC7C' })).id
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`).catch(() => {})
	})

	test('same reviewer can hold two review types; request-changes posts a reason comment', async () => {
		// Two reviews from the SAME reviewer: one untyped, one typed (#3468).
		await api('PUT', `/cards/${cardId}/reviews/admin`)
		await api('PUT', `/cards/${cardId}/reviews/admin`, { reviewTypeId: typeId })

		let card = await api('GET', `/cards/${cardId}`)
		const mine = card.reviews.filter((r) => r.reviewer === 'admin')
		expect(mine).toHaveLength(2)
		const untyped = mine.find((r) => !r.reviewTypeId)
		const typed = mine.find((r) => r.reviewTypeId === typeId)
		expect(untyped).toBeTruthy()
		expect(typed).toBeTruthy()

		// Request changes on the typed review WITH a reason (#3469).
		await api('PATCH', `/cards/${cardId}/reviews/${typed.id}`, {
			state: 'changes_requested',
			reason: 'Please add tests',
		})

		card = await api('GET', `/cards/${cardId}`)
		expect(card.reviews.find((r) => r.id === typed.id).state).toBe('changes_requested')

		// The reason landed as a comment by the reviewer.
		const comments = await api('GET', `/cards/${cardId}/comments`)
		const reasonComment = comments.find((c) => (c.body || '').includes('Please add tests'))
		expect(reasonComment).toBeTruthy()
		expect(reasonComment.body).toContain('Requested changes')
		expect(reasonComment.author).toBe('admin')

		// Withdraw one review by id — the other remains.
		await api('DELETE', `/cards/${cardId}/reviews/${untyped.id}`)
		card = await api('GET', `/cards/${cardId}`)
		expect(card.reviews.filter((r) => r.reviewer === 'admin')).toHaveLength(1)
	})
})
