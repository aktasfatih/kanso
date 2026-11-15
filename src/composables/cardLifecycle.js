// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import {
	fetchBoard, fetchCard, updateCard, deleteCard, restoreCard,
} from '../services/api.js'
import { apiAnswerStatus } from '../services/apiErrors.js'
import { boardQueryKey } from './queryKeys.js'

const clients = new WeakMap()
const lifecycleFields = ['archived', 'boardId', 'stackId', 'sortKey']

function withoutLifecycle(card) {
	return Object.fromEntries(Object.entries(card ?? {}).filter(([key]) => !lifecycleFields.includes(key)))
}

function lifecycleOf(card) {
	return Object.fromEntries(lifecycleFields.filter((key) => key in (card ?? {})).map((key) => [key, card[key]]))
}

function ownWrite(registry, write) {
	registry.writing++
	try {
		return write()
	} finally {
		registry.writing--
	}
}

function registryFor(client) {
	let registry = clients.get(client)
	if (registry) return registry
	registry = { clock: 0, states: new Map(), receipts: new Map(), reads: new Set(), writing: 0 }
	clients.set(client, registry)
	return registry
}

function subscribeToCache(client, registry) {
	if (registry.unsubscribe) return
	registry.unsubscribe = client.getQueryCache().subscribe((event) => {
		if (registry.writing || event.type !== 'updated' || event.action.type !== 'success') return
		const [kind, id] = event.query.queryKey
		const data = event.query.state.data
		for (const state of registry.states.values()) {
			if (kind === 'card' && String(id) === String(state.cardId) && data) {
				observeCachedCard(state, data, state.renderedDetail)
				state.detail = { ...state.detail, ...withoutLifecycle(data) }
				for (const [boardId, summary] of state.summaries) {
					state.summaries.set(boardId, mergeExistingFields(summary, data))
				}
			} else if (kind === 'board' && data?.cards) {
				const boardId = String(id)
				const card = data.cards.find((card) => Number(card.id) === state.cardId)
				if (card) {
					observeCachedCard(state, card, state.renderedBoards.get(boardId))
					state.summaries.set(boardId, { ...state.summaries.get(boardId), ...withoutLifecycle(card) })
					if (state.detail) state.detail = mergeExistingFields(state.detail, card)
				} else if (state.renderedBoards.get(boardId)) {
					state.presence.set(boardId, false)
					state.revision++
				}
			}
		}
	})
}

function releaseIdleStates(registry) {
	for (const [id, state] of registry.states) {
		if (state.pending.length || state.dirty || state.busy) continue
		if ([...registry.reads].some((ticket) => ticket.sequence < state.barrier)) continue
		registry.states.delete(id)
	}
	if (!registry.states.size) {
		registry.unsubscribe?.()
		registry.unsubscribe = undefined
	}
}

function mergeExistingFields(existing, incoming) {
	if (!existing) return existing
	const next = { ...existing }
	for (const key of Object.keys(existing)) {
		if (!lifecycleFields.includes(key) && key in incoming) next[key] = incoming[key]
	}
	return next
}

function observeCachedCard(state, card, previous) {
	const changed = lifecycleFields.filter((key) => key in card && card[key] !== previous?.[key])
	if (!changed.length) return
	for (const key of changed) state.lifecycle[key] = card[key]
	state.revision++
}

function stateFor(client, registry, target) {
	let state = registry.states.get(target.cardId)
	if (state) return state
	const summaries = new Map()
	const presence = new Map()
	for (const [key, board] of client.getQueriesData({ queryKey: ['board'] })) {
		const card = board?.cards?.find((card) => Number(card.id) === target.cardId)
		if (card) summaries.set(String(key[1]), card)
		if (card || String(key[1]) === target.boardId) presence.set(String(key[1]), Boolean(card))
	}
	const detail = client.getQueriesData({ queryKey: ['card'] })
		.find(([key, data]) => String(key[1]) === String(target.cardId) && data)?.[1]
	const seed = detail ?? summaries.get(target.boardId) ?? [...summaries.values()][0]
	state = {
		cardId: target.cardId,
		boardId: target.boardId,
		summaries,
		presence,
		detail,
		lifecycle: { boardId: Number(target.boardId), ...lifecycleOf(seed) },
		exists: true,
		pending: [],
		confirmed: [],
		tail: Promise.resolve(),
		lastIntent: 0,
		barrier: 0,
		revision: 0,
		dirty: false,
		busy: 0,
		renderedBoards: new Map(summaries),
		renderedDetail: detail,
	}
	registry.states.set(target.cardId, state)
	subscribeToCache(client, registry)
	return state
}

function effective(state, boardId) {
	const lifecycle = { ...state.lifecycle }
	let present = boardId === undefined ? state.exists : (state.presence.get(String(boardId)) ?? false)
	for (const operation of [...state.confirmed, ...state.pending]) {
		if (operation.kind === 'archive') lifecycle.archived = operation.archived
		if (operation.kind === 'delete') present = false
		if (operation.kind === 'restore') {
			present = boardId === undefined || String(lifecycle.boardId) === String(boardId)
		}
	}
	return { lifecycle, present }
}

function projectBoardTarget(state, boardId, data) {
	const { lifecycle, present } = effective(state, boardId)
	const existing = data.cards?.find((card) => Number(card.id) === state.cardId)
	const seed = state.summaries.get(String(boardId)) ?? existing
	const card = present && seed ? { ...seed, ...lifecycle } : undefined
	state.renderedBoards.set(String(boardId), card)
	const cards = (data.cards ?? []).filter((card) => Number(card.id) !== state.cardId)
	if (card) {
		const index = existing ? data.cards.indexOf(existing) : cards.length
		cards.splice(index, 0, card)
	}
	return { ...data, cards }
}

function projectCaches(client, registry, state, skip) {
	ownWrite(registry, () => {
		for (const [key, data] of client.getQueriesData({ queryKey: ['board'] })) {
			const boardId = String(key[1])
			if (skip?.boardId === boardId || !state.presence.has(boardId)) continue
			if (data?.cards) client.setQueryData(key, projectBoardTarget(state, boardId, data))
		}
		if (skip?.cardId === state.cardId) return
		const { lifecycle, present } = effective(state)
		const keys = client.getQueriesData({ queryKey: ['card'] })
			.filter(([key]) => String(key[1]) === String(state.cardId)).map(([key]) => key)
		if (!present) {
			state.renderedDetail = undefined
			for (const key of keys) client.removeQueries({ queryKey: key, exact: true })
		} else if (state.detail) {
			if (!keys.length) keys.push(['card', String(state.cardId)])
			for (const key of keys) {
				state.renderedDetail = { ...state.detail, ...lifecycle }
				client.setQueryData(key, state.renderedDetail)
			}
		}
	})
}

export function beginCardLifecycle(client, { cardId, boardId, kind, archived, undoOf }) {
	const target = Object.freeze({
		cardId: Number(cardId),
		boardId: String(boardId),
		cardKey: ['card', String(cardId)],
		boardKey: boardQueryKey(boardId),
	})
	const registry = registryFor(client)
	const previousReceipt = registry.receipts.get(target.cardId)
	if (undoOf !== undefined && (undoOf !== previousReceipt?.operationId || !previousReceipt.valid)) return null
	const state = stateFor(client, registry, target)
	const operationId = ++registry.clock
	if (previousReceipt) previousReceipt.valid = false
	const receipt = {
		operationId, target, valid: true, barrier: operationId,
		archived: kind === 'archive' ? archived : effective(state).lifecycle.archived,
		present: kind !== 'delete',
	}
	registry.receipts.set(target.cardId, receipt)
	let release
	const operation = {
		target, operationId, kind, archived,
		previous: state.tail,
		current: () => registry.receipts.get(target.cardId) === receipt && receipt.valid,
		receipt,
	}
	state.tail = new Promise((resolve) => { release = resolve })
	operation.release = release
	state.lastIntent = operationId
	state.barrier = operationId
	state.pending.push(operation)
	operation.prepared = Promise.all([
		client.cancelQueries({ queryKey: target.boardKey }),
		client.cancelQueries({ predicate: (query) => query.queryKey[0] === 'card' && String(query.queryKey[1]) === String(target.cardId) }),
	]).catch(() => {}).then(() => projectCaches(client, registry, state))
	return operation
}

export function prepareCardLifecycle(_client, operation) {
	return operation.prepared
}

async function reconcile(client, registry, state) {
	for (let attempt = 0; attempt < 3; attempt++) {
		const generation = state.lastIntent
		const revision = state.revision
		const boards = new Set([state.boardId, ...state.summaries.keys()])
		let card
		let absent = false
		try {
			card = await fetchCard(state.cardId)
		} catch (error) {
			absent = [403, 404].includes(apiAnswerStatus(error))
		}
		if (card?.boardId != null) boards.add(String(card.boardId))
		const payloads = await Promise.all([...boards].map(async (boardId) => {
			try {
				return [boardId, await fetchBoard(boardId)]
			} catch {
				return [boardId, undefined]
			}
		}))
		if (generation !== state.lastIntent || state.pending.length) return
		if (revision !== state.revision) {
			if (!state.dirty) return
			continue
		}
		if (card) {
			state.lifecycle = { ...state.lifecycle, ...lifecycleOf(card) }
			state.exists = true
			state.detail = state.detail ? { ...state.detail, ...lifecycleOf(card) } : card
		} else if (absent) {
			state.exists = false
			for (const boardId of state.presence.keys()) state.presence.set(boardId, false)
		}
		for (const [boardId, board] of payloads) {
			if (!board?.cards) continue
			const summary = board.cards.find((card) => Number(card.id) === state.cardId)
			state.presence.set(boardId, Boolean(summary))
			if (summary) {
				const existing = state.summaries.get(boardId)
				state.summaries.set(boardId, existing ? { ...existing, ...lifecycleOf(summary) } : summary)
				state.lifecycle = { ...state.lifecycle, ...lifecycleOf(summary) }
				state.exists = true
			}
		}
		const complete = Boolean(card || absent) && payloads.every(([, board]) => Array.isArray(board?.cards))
		if (complete) {
			state.confirmed = []
			state.dirty = false
		}
		state.barrier = ++registry.clock
		state.revision++
		const receipt = registry.receipts.get(state.cardId)
		if (complete) retireReceipt(receipt, effective(state), state.barrier)
		projectCaches(client, registry, state)
		return
	}
}

export async function executeCardLifecycle(client, operation) {
	const registry = registryFor(client)
	const state = registry.states.get(operation.target.cardId)
	state.busy++
	await operation.prepared
	await operation.previous
	let data
	let failure
	try {
		if (operation.kind === 'archive') data = await updateCard(state.cardId, { archived: operation.archived })
		if (operation.kind === 'delete') data = await deleteCard(state.cardId)
		if (operation.kind === 'restore') data = await restoreCard(state.cardId)
		state.confirmed.push(operation)
		operation.receipt.success = true
	} catch (error) {
		failure = error
	} finally {
		state.pending = state.pending.filter((pending) => pending !== operation)
		state.barrier = ++registry.clock
		operation.completedBarrier = state.barrier
		operation.receipt.barrier = state.barrier
		state.dirty = true
		projectCaches(client, registry, state)
		try {
			if (!state.pending.length) await reconcile(client, registry, state)
		} finally {
			state.busy--
			operation.release()
			releaseIdleStates(registry)
		}
	}
	if (failure) throw failure
	return { data, target: operation.target, operationId: operation.operationId, current: operation.current }
}

export function beginLifecycleRead(client, target = {}) {
	const registry = registryFor(client)
	const ticket = { ...target, sequence: ++registry.clock }
	registry.reads.add(ticket)
	return ticket
}

export function finishLifecycleRead(client, ticket) {
	const registry = registryFor(client)
	registry.reads.delete(ticket)
	releaseIdleStates(registry)
}

export function requiresUnconditionalLifecycleRead(client, boardId) {
	return [...registryFor(client).states.values()].some((state) =>
		(state.pending.length || state.dirty) && (state.boardId === String(boardId) || state.presence.has(String(boardId))),
	)
}

function acceptRead(registry, state, ticket) {
	if (ticket.sequence < state.barrier) return false
	state.revision++
	state.confirmed = state.confirmed.filter((operation) => ticket.sequence < operation.completedBarrier)
	state.barrier = ++registry.clock
	if (!state.pending.length) {
		state.dirty = false
	}
	return true
}

function retireReceipt(receipt, observed, sequence) {
	if (!receipt?.success || sequence < receipt.barrier) return
	if (receipt.present !== observed.present
		|| (observed.present && receipt.archived !== undefined && receipt.archived !== observed.lifecycle.archived)) receipt.valid = false
}

export function projectLifecycleBoard(client, boardId, data, ticket, touchedIds) {
	if (!data?.cards) return data
	const registry = registryFor(client)
	let projected = data
	const incoming = new Map(data.cards.map((card) => [Number(card.id), card]))
	for (const [id, receipt] of registry.receipts) {
		if (touchedIds && !touchedIds.some((cardId) => Number(cardId) === id)) continue
		if (receipt.target.boardId !== String(boardId)) continue
		if (registry.states.get(id)?.pending.length) continue
		const card = incoming.get(id)
		retireReceipt(receipt, { present: Boolean(card), lifecycle: lifecycleOf(card) }, ticket.sequence)
	}
	for (const state of registry.states.values()) {
		if (touchedIds && !touchedIds.some((id) => Number(id) === state.cardId)) continue
		if (state.boardId !== String(boardId) && !state.presence.has(String(boardId)) && !incoming.has(state.cardId)) continue
		if (acceptRead(registry, state, ticket)) {
			const card = data.cards.find((card) => Number(card.id) === state.cardId)
			state.presence.set(String(boardId), Boolean(card))
			if (card) {
				state.summaries.set(String(boardId), card)
				state.lifecycle = { ...state.lifecycle, ...lifecycleOf(card) }
				state.exists = true
			}
			projectCaches(client, registry, state, { boardId: String(boardId) })
		} else {
			ticket.lifecycleDeclined = true
		}
		projected = projectBoardTarget(state, String(boardId), projected)
	}
	return projected
}

export function projectLifecycleCard(client, cardId, data, ticket) {
	const registry = registryFor(client)
	const state = registry.states.get(Number(cardId))
	if (!state?.pending.length) retireReceipt(registry.receipts.get(Number(cardId)), { present: true, lifecycle: lifecycleOf(data) }, ticket.sequence)
	if (!state) return data
	if (acceptRead(registry, state, ticket)) {
		state.detail = data
		state.lifecycle = { ...state.lifecycle, ...lifecycleOf(data) }
		state.exists = true
		if (data.boardId != null) {
			const boardId = String(data.boardId)
			for (const known of state.presence.keys()) state.presence.set(known, known === boardId)
			state.presence.set(boardId, true)
			const summary = state.summaries.get(boardId) ?? [...state.summaries.values()][0]
			if (summary) state.summaries.set(boardId, { ...mergeExistingFields(summary, data), ...lifecycleOf(data) })
		}
		projectCaches(client, registry, state, { cardId: Number(cardId) })
	}
	const { lifecycle, present } = effective(state)
	if (!present) throw new Error('Card changed while it was being read')
	state.renderedDetail = { ...state.detail, ...lifecycle }
	return state.renderedDetail
}

export function projectLifecycleCardError(client, cardId, error, ticket) {
	const registry = registryFor(client)
	const state = registry.states.get(Number(cardId))
	if (![403, 404].includes(apiAnswerStatus(error))) return error
	if (!state) {
		retireReceipt(registry.receipts.get(Number(cardId)), { present: false, lifecycle: {} }, ticket.sequence)
		return error
	}
	if (ticket.sequence < state.barrier) return new Error('Card changed while it was being read')
	if (acceptRead(registry, state, ticket)) {
		state.exists = false
		for (const boardId of state.presence.keys()) state.presence.set(boardId, false)
		retireReceipt(registry.receipts.get(Number(cardId)), { present: false, lifecycle: {} }, ticket.sequence)
		projectCaches(client, registry, state)
	}
	return error
}
