// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import test from 'node:test'
import assert from 'node:assert/strict'
import { QueryClient } from '@tanstack/vue-query'
import { boardQueryKey } from '../../src/composables/queryKeys.js'
import { resolveCardRoute } from '../../src/router/cardNavigation.js'

const modal = { name: 'card-modal', params: { id: '7', cardId: '9' }, query: { comment: '10' }, hash: '#focus' }
const currentBoard = { name: 'board', params: { id: '7' } }
const board = { board: { id: 7 }, cards: [{ id: 9, boardId: 7 }] }

function client() {
	return new QueryClient({ defaultOptions: { queries: { staleTime: 30_000 } } })
}

test('fresh board card opens without waiting for the navigation resolver', async () => {
	const queryClient = client()
	queryClient.setQueryData(boardQueryKey(7), board)
	try {
		assert.equal(await resolveCardRoute(modal, currentBoard, queryClient, () => { throw new Error('resolver should not run') }), undefined)
	} finally {
		queryClient.clear()
	}
})

test('stale, invalidated, missing and wrong-board data are resolved by the server', async () => {
	for (const scenario of ['stale', 'invalidated', 'missing', 'wrong-board', 'wrong-card-board', 'deleted']) {
		const queryClient = client()
		const data = scenario === 'wrong-board'
			? { ...board, board: { id: 8 } }
			: scenario === 'deleted'
				? { ...board, cards: [{ id: 9, boardId: 7, deletedAt: 123 }] }
				: scenario === 'wrong-card-board'
					? { ...board, cards: [{ id: 9, boardId: 8 }] }
				: board
		if (scenario !== 'missing') queryClient.setQueryData(boardQueryKey(7), data, scenario === 'stale' ? { updatedAt: Date.now() - 31_000 } : undefined)
		if (scenario === 'invalidated') await queryClient.invalidateQueries({ queryKey: boardQueryKey(7), refetchType: 'none' })
		let calls = 0
		try {
			const result = await resolveCardRoute(modal, currentBoard, queryClient, async (id) => {
				calls++
				assert.equal(id, 9)
				return { cardId: 19, boardId: 17 }
			})
			assert.equal(calls, 1, scenario)
			assert.deepEqual(result, { name: 'card-modal', params: { id: 17, cardId: 19 }, query: modal.query, hash: modal.hash, replace: true })
		} finally {
			queryClient.clear()
		}
	}
})

test('external navigation resolves even when a board cache contains the old card', async () => {
	const queryClient = client()
	queryClient.setQueryData(boardQueryKey(7), board)
	try {
		let calls = 0
		await resolveCardRoute(modal, { name: undefined, params: {} }, queryClient, async () => {
			calls++
			return { cardId: 19, boardId: 17 }
		})
		assert.equal(calls, 1)
	} finally {
		queryClient.clear()
	}
})

test('unknown card resolves and retains page context while resolver errors fall through', async () => {
	const route = { name: 'card-page', params: { cardId: '9' }, query: { comment: '10' }, hash: '#focus' }
	const result = await resolveCardRoute(route, null, null, async () => ({ cardId: 19, boardId: 17 }))
	assert.deepEqual(result, { name: 'card-page', params: { cardId: 19 }, query: route.query, hash: route.hash, replace: true })
	assert.equal(await resolveCardRoute(route, null, null, async () => { throw new Error('offline') }), undefined)
	assert.equal(await resolveCardRoute({ name: 'board', params: { id: '7' } }, null, null, () => { throw new Error('not a card route') }), undefined)
})

test('navigation resolution is time bounded', async () => {
	globalThis.window = { _oc_webroot: '', location: { href: 'http://localhost/' }, addEventListener() {}, removeEventListener() {} }
	const axios = (await import('@nextcloud/axios')).default
	const { resolveCardNavigation } = await import('../../src/services/api.js')
	const originalAdapter = axios.defaults.adapter
	let timeout
	axios.defaults.adapter = async (config) => {
		timeout = config.timeout
		return { status: 200, statusText: 'OK', data: { cardId: 9, boardId: 7 }, headers: {}, config }
	}
	try {
		assert.deepEqual(await resolveCardNavigation(9), { cardId: 9, boardId: 7 })
		assert.equal(timeout, 5000)
	} finally {
		axios.defaults.adapter = originalAdapter
	}
})
