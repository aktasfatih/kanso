// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { fetchBoard, fetchCard, setCardParent } from '../services/api.js'
import { boardQueryKey } from './queryKeys.js'

const clients = new WeakMap()
const parentId = (id) => id == null ? null : Number(id)
const summaryFields = ['id', 'title', 'stackId', 'doneAt', 'archived', 'duedate', 'startDate', 'allDay']
const summary = (card) => Object.fromEntries(summaryFields.filter((key) => key in (card ?? {})).map((key) => [key, card[key]]))
const progress = (cards, id) => {
	const children = cards.filter((card) => parentId(card.parentCardId) === Number(id))
	return { total: children.length, done: children.filter((card) => Number(card.doneAt) > 0).length }
}

function registryFor(client) {
	let registry = clients.get(client)
	if (!registry) {
		registry = { clock: 0, boards: new Map(), tails: new Map(), reads: new Set() }
		clients.set(client, registry)
	}
	return registry
}

function cardData(client, id) {
	return client.getQueriesData({ queryKey: ['card'] }).find(([key, data]) => Number(key[1]) === Number(id) && data)?.[1]
}

function effective(state) {
	const latest = [...state.confirmed, ...state.pending].at(-1)
	return latest ? latest.parentCardId : state.parentCardId
}

function templateProgress(board, id, optimistic = false) {
	const children = [...board.children.values()].filter((state) => state.template && state.present && (optimistic ? effective(state) : state.parentCardId) === id)
	return { total: children.length, done: children.filter((state) => Number(state.summary.doneAt) > 0).length }
}

function rememberParent(board, id, data) {
	if (id == null || board.parents.has(id)) return
	const card = data?.cards?.find((card) => Number(card.id) === id)
	const visible = progress(data?.cards ?? [], id)
	const templates = templateProgress(board, id)
	const known = { total: visible.total + templates.total, done: visible.done + templates.done }
	board.parents.set(id, {
		total: Math.max(0, (card?.childProgress?.total ?? known.total) - known.total),
		done: Math.max(0, (card?.childProgress?.done ?? known.done) - known.done),
	})
}

function projectBoard(board, data) {
	const cards = data.cards.map((card) => {
		const state = board.children.get(Number(card.id))
		return state ? { ...card, parentCardId: state.present ? effective(state) : null } : card
	})
	return { ...data, cards: cards.map((card) => {
		const residual = board.parents.get(Number(card.id))
		if (!residual) return card
		const known = progress(cards, card.id)
		const templates = templateProgress(board, Number(card.id), true)
		return { ...card, childProgress: { total: known.total + residual.total + templates.total, done: known.done + residual.done + templates.done } }
	}) }
}

function childSummary(client, board, state, existing) {
	const card = cardData(client, state.cardId)
		?? client.getQueryData(board.key)?.cards.find((card) => Number(card.id) === state.cardId)
	return { ...state.summary, ...existing, ...summary(card) }
}

function projectCard(client, board, id, data) {
	if (String(data.boardId) !== String(board.key[1])) return data
	let result = data
	const state = board.children.get(Number(id))
	if (state) {
		const nextParent = state.present ? effective(state) : null
		const parent = cardData(client, nextParent) ?? client.getQueryData(board.key)?.cards.find((card) => Number(card.id) === nextParent)
		result = { ...result, parentCardId: nextParent, parent: nextParent == null ? null : parent ? summary(parent) : null }
	}
	if (board.parents.has(Number(id)) && Array.isArray(result.children)) {
		let children = result.children
		for (const child of board.children.values()) {
			const existing = children.find((card) => Number(card.id) === child.cardId)
			const index = children.findIndex((card) => Number(card.id) === child.cardId)
			children = children.filter((card) => Number(card.id) !== child.cardId)
			if (child.present && effective(child) === Number(id)) {
				children.splice(index < 0 ? children.length : index, 0, childSummary(client, board, child, existing))
			}
		}
		result = { ...result, children, childProgress: { total: children.length, done: children.filter((card) => Number(card.doneAt) > 0).length } }
	}
	return result
}

function projectCaches(client, board, skip) {
	const data = client.getQueryData(board.key)
	if (data?.cards && skip !== 'board') client.setQueryData(board.key, projectBoard(board, data))
	for (const [key, data] of client.getQueriesData({ queryKey: ['card'] })) {
		if (!data || Number(key[1]) === skip) continue
		if (board.children.has(Number(key[1])) || board.parents.has(Number(key[1]))) client.setQueryData(key, projectCard(client, board, key[1], data))
	}
}

export function beginHierarchyChange(client, target) {
	const registry = registryFor(client)
	const boardId = String(target.boardId)
	let board = registry.boards.get(boardId)
	if (!board) {
		board = { key: boardQueryKey(boardId), children: new Map(), parents: new Map(), barrier: 0, dirty: false }
		registry.boards.set(boardId, board)
	}
	const cardId = Number(target.cardId)
	const data = client.getQueryData(board.key)
	let state = board.children.get(cardId)
	if (!state) {
		const card = cardData(client, cardId) ?? data?.cards?.find((card) => Number(card.id) === cardId)
		state = { cardId, present: true, template: Boolean(card?.isTemplate), parentCardId: parentId(card && 'parentCardId' in card ? card.parentCardId : target.previousParentCardId), summary: summary(card ?? { id: cardId }), pending: [], confirmed: [], covered: false }
		board.children.set(cardId, state)
		const residual = board.parents.get(state.parentCardId)
		if (state.template && residual) {
			board.parents.set(state.parentCardId, {
				total: Math.max(0, residual.total - 1),
				done: Math.max(0, residual.done - (Number(state.summary.doneAt) > 0 ? 1 : 0)),
			})
		}
	}
	rememberParent(board, effective(state), data)
	rememberParent(board, parentId(target.parentCardId), data)
	let release
	const tail = new Promise((done) => { release = done })
	const operation = Object.freeze({
		boardId, cardId, parentCardId: parentId(target.parentCardId), previous: registry.tails.get(cardId) ?? Promise.resolve(),
		release, tail,
		prepared: Promise.all([
			client.cancelQueries({ queryKey: board.key }),
			client.cancelQueries({ predicate: (query) => query.queryKey[0] === 'card' && (Number(query.queryKey[1]) === cardId || board.parents.has(Number(query.queryKey[1]))) }),
		]).then(() => projectCaches(client, board)),
	})
	registry.tails.set(cardId, tail)
	state.covered = false
	state.pending.push(operation)
	board.barrier = ++registry.clock
	return operation
}

function releaseIdle(registry) {
	for (const [id, board] of registry.boards) {
		if (board.dirty || [...board.children.values()].some((state) => state.pending.length)) continue
		if ([...registry.reads].some((ticket) => ticket.sequence < board.barrier)) continue
		registry.boards.delete(id)
	}
}

export function beginHierarchyRead(client) {
	const registry = registryFor(client)
	const ticket = { sequence: ++registry.clock }
	registry.reads.add(ticket)
	return ticket
}

export function finishHierarchyRead(client, ticket) {
	const registry = registryFor(client)
	registry.reads.delete(ticket)
	releaseIdle(registry)
}

export function requiresUnconditionalHierarchyRead(client, boardId) {
	return registryFor(client).boards.has(String(boardId))
}

function acceptRead(registry, board, ticket) {
	if (ticket.sequence < board.barrier) {
		ticket.hierarchyDeclined = true
		return false
	}
	board.barrier = ++registry.clock
	return true
}

export function projectHierarchyBoard(client, boardId, data, ticket, touchedIds) {
	const registry = registryFor(client)
	const board = registry.boards.get(String(boardId))
	if (!board || !data?.cards) return data
	const touched = touchedIds && new Set(touchedIds.map(Number))
	const relevant = !touched || [...touched].some((id) => board.children.has(id) || board.parents.has(id))
	if (relevant && acceptRead(registry, board, ticket)) {
		for (const state of board.children.values()) {
			if (touched && !touched.has(state.cardId)) continue
			const card = data.cards.find((card) => Number(card.id) === state.cardId)
			if (!card && !state.template) {
				state.present = false
				state.parentCardId = null
				state.confirmed = []
			}
			if (card || !state.template) state.covered = true
			if (!card) continue
			state.present = true
			state.confirmed = []
			state.parentCardId = parentId(card.parentCardId)
			state.summary = { ...state.summary, ...summary(card) }
			rememberParent(board, state.parentCardId, data)
		}
		// Progress also includes template children omitted from the board's card list.
		const baseline = data.cards.map((card) => {
			const state = board.children.get(Number(card.id))
			return state ? { ...card, parentCardId: state.parentCardId } : card
		})
		for (const [id] of board.parents) {
			if (touched && !touched.has(id)) continue
			if ([...board.children.values()].some((state) => state.template && (state.pending.length || state.confirmed.length))) continue
			const card = data.cards.find((card) => Number(card.id) === id)
			if (!card?.childProgress) continue
			const visible = progress(baseline, id)
			const templates = templateProgress(board, id)
			const known = { total: visible.total + templates.total, done: visible.done + templates.done }
			board.parents.set(id, { total: Math.max(0, card.childProgress.total - known.total), done: Math.max(0, card.childProgress.done - known.done) })
		}
		if (!touched && [...board.children.values()].every((state) => !state.pending.length && !state.confirmed.length && state.covered)) board.dirty = false
		projectCaches(client, board, 'board')
	}
	return projectBoard(board, data)
}

export function projectHierarchyCard(client, cardId, data, ticket) {
	const registry = registryFor(client)
	const board = registry.boards.get(String(data.boardId))
	if (!board?.children.has(Number(cardId)) && !board?.parents.has(Number(cardId))) return data
	if (acceptRead(registry, board, ticket)) {
		const state = board.children.get(Number(cardId))
		if (state) {
			state.covered = true
			state.present = String(data.boardId) === String(board.key[1])
			state.confirmed = []
			state.parentCardId = parentId(data.parentCardId)
			state.summary = { ...state.summary, ...summary(data) }
			rememberParent(board, state.parentCardId, client.getQueryData(board.key))
		}
		for (const child of board.children.values()) {
			if (!Array.isArray(data.children)) continue
			if (data.children.some((card) => Number(card.id) === child.cardId)) child.parentCardId = Number(cardId)
			else if (child.parentCardId === Number(cardId)) child.parentCardId = null
		}
		if ([...board.children.values()].every((state) => state.covered && !state.pending.length && !state.confirmed.length)) board.dirty = false
		projectCaches(client, board, Number(cardId))
	}
	return projectCard(client, board, cardId, data)
}

async function reconcile(client, board, operation) {
	if (board.children.get(operation.cardId).template) await recoverDetail(client, board, operation)
	const ticket = beginHierarchyRead(client)
	try {
		const data = await fetchBoard(operation.boardId)
		const projected = projectHierarchyBoard(client, operation.boardId, data, ticket)
		if (!ticket.hierarchyDeclined) {
			client.setQueryData(board.key, (old) => old && { ...old, cards: old.cards.map((card) => {
				const incoming = projected.cards.find((row) => Number(row.id) === Number(card.id))
				if (!incoming) return card
				return { ...card, ...(board.children.has(Number(card.id)) ? { parentCardId: incoming.parentCardId } : {}), ...(board.parents.has(Number(card.id)) ? { childProgress: incoming.childProgress } : {}) }
			}) })
		}
	} catch {
		await recoverDetail(client, board, operation)
	} finally {
		finishHierarchyRead(client, ticket)
	}
}

async function recoverDetail(client, board, operation) {
	const ticket = beginHierarchyRead(client)
	try {
		projectHierarchyCard(client, operation.cardId, await fetchCard(operation.cardId), ticket)
		projectCaches(client, board)
	} catch {
		// Keep the last known membership until an ordinary read can recover it.
	} finally {
		finishHierarchyRead(client, ticket)
	}
}

export async function executeHierarchyChange(client, operation) {
	const registry = registryFor(client)
	const board = registry.boards.get(operation.boardId)
	const state = board.children.get(operation.cardId)
	await operation.prepared
	await operation.previous
	try {
		const result = await setCardParent(operation.cardId, operation.parentCardId)
		state.confirmed.push(operation)
		return result
	} finally {
		state.pending = state.pending.filter((pending) => pending !== operation)
		board.barrier = ++registry.clock
		board.dirty = true
		projectCaches(client, board)
		try {
			if (!state.pending.length) await reconcile(client, board, operation)
		} finally {
			operation.release()
			if (registry.tails.get(operation.cardId) === operation.tail) registry.tails.delete(operation.cardId)
			releaseIdle(registry)
		}
	}
}
