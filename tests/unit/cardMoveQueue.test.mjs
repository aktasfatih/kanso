// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10008 — WHEN the move queue's error banner is allowed to clear itself.
//
// Moves are serialised: each enqueueMove() chains onto the previous server call.
// So "clear the banner when a move succeeds" is the wrong hook — drag A fails,
// its card is visibly reverted and the banner explains why, and then drag B,
// which the user started BEFORE A came back, lands one round trip later and
// wipes the explanation off a revert that is still on screen. The right hook is
// the user's next gesture: clearing at the TOP of enqueueMove() retires the old
// banner when the user acts, and lets A's message outlive an already-queued B.
//
// Exercised for real rather than at the source level: useCardMove is a plain
// composable, so it runs under a Vue app context with no DOM and no bundler
// (app.runWithContext supplies the injected QueryClient without mounting). Only
// the transport is stubbed, and at the axios ADAPTER — the real composable, the
// real FIFO queue, the real services/api.js call and the real optimistic
// patch/rollback all run.
//
// `window` has to exist before @nextcloud/router is imported; the stub is
// deliberately not a DOM (defining `document` would pull Vue's browser runtime
// in and need a real one).

import test, { after } from 'node:test'
import assert from 'node:assert/strict'

globalThis.window = {
	_oc_webroot: '',
	location: { href: 'http://localhost/' },
	addEventListener() {},
	removeEventListener() {},
}

// Dynamic, and in this order, on purpose: static `import` declarations are
// hoisted and evaluated BEFORE any statement in the module, so a static import
// of the composable would reach @nextcloud/router before the stub above exists.
const { createApp } = await import('vue')
const { QueryClient, VueQueryPlugin } = await import('@tanstack/vue-query')
const axios = (await import('@nextcloud/axios')).default
const { useCardMove } = await import('../../src/composables/useCardMove.js')

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

// Every cached query arms a garbage-collection timer, which would keep the test
// process alive for the full gcTime after the last assertion. Tear them down.
const clients = []
after(() => {
	for (const client of clients) {
		client.unmount()
		client.clear()
	}
})

/** A 403 with a server-shaped body, i.e. the review gate refusing a move. */
function refusal(message) {
	return { response: { status: 403, data: { error: message } } }
}

/**
 * A composable wired to its own QueryClient, seeded with one card, plus an
 * axios adapter that answers each move request from `outcomes` in order.
 *
 * @param {number} boardId - distinct per test; the pending-move registry is module-scoped
 * @param {Array<Error|object|null>} outcomes - per call: a rejection value, or null to succeed
 */
function harness(boardId, outcomes) {
	const app = createApp({})
	const queryClient = new QueryClient()
	app.use(VueQueryPlugin, { queryClient })
	clients.push(queryClient)

	queryClient.setQueryData(['board', String(boardId)], {
		cards: [
			{ id: 1, stackId: 10, sortKey: 'a' },
			{ id: 2, stackId: 10, sortKey: 'b' },
		],
	})

	let call = 0
	axios.defaults.adapter = async (config) => {
		const outcome = outcomes[call++]
		if (outcome) throw outcome
		return {
			status: 200,
			statusText: 'OK',
			data: { id: 1, stackId: 20, sortKey: 'm', lastModified: 1 },
			headers: {},
			config,
		}
	}

	return { queryClient, ...app.runWithContext(() => useCardMove(boardId)) }
}

/** One drag: card `cardId` dropped into stack 20. */
function drag(enqueueMove, cardId) {
	enqueueMove({ cardId, targetStackId: 20, afterCardId: null, optimisticKey: 'z' })
}

test('a queued move that lands does NOT retire the failed move\'s banner', async () => {
	// Drag A fails; drag B was already queued behind it and succeeds. B's enqueue
	// happened before A came back, so it is not the user reacting to A's failure —
	// A's revert is still on screen and must keep its explanation.
	const { enqueueMove, lastError } = harness(1, [refusal('Card is under review.'), null])

	drag(enqueueMove, 1) // A — will be refused
	drag(enqueueMove, 2) // B — queued behind A, will land

	await sleep(200)

	assert.equal(lastError.value, 'Card is under review.',
		'a later queued success wiped the failed move\'s banner while its revert '
		+ 'was still on screen')
})

test('starting a new move retires the previous failure\'s banner immediately', async () => {
	const { enqueueMove, lastError } = harness(2, [refusal('Card is under review.'), null])

	drag(enqueueMove, 1)
	await sleep(200)
	assert.equal(lastError.value, 'Card is under review.', 'the failure must raise the banner')

	// The next gesture clears it on the spot — synchronously, at enqueue, not a
	// round trip later when the server answers.
	drag(enqueueMove, 2)
	assert.equal(lastError.value, null,
		'a new drag is the user moving on; the stale banner must go with the gesture, '
		+ 'not with the response')

	await sleep(200)
	assert.equal(lastError.value, null)
})

test('a refused move still raises the banner and reverts that card only', async () => {
	// The clear moving to the top of enqueueMove() must not cost the banner its
	// reason to exist, nor the per-card rollback.
	const { enqueueMove, lastError, queryClient } = harness(3, [refusal('Card is under review.')])

	drag(enqueueMove, 1)
	assert.equal(
		queryClient.getQueryData(['board', '3']).cards.find((c) => c.id === 1).stackId,
		20,
		'the optimistic patch must move the card straight away',
	)

	await sleep(200)

	assert.equal(lastError.value, 'Card is under review.')
	const cards = queryClient.getQueryData(['board', '3']).cards
	assert.equal(cards.find((c) => c.id === 1).stackId, 10, 'the refused card must be reverted')
	assert.equal(cards.find((c) => c.id === 2).stackId, 10, 'the untouched card must not move')
})

test('the manual dismiss still clears the banner', async () => {
	const { enqueueMove, lastError, dismissError } = harness(4, [refusal('Card is under review.')])

	drag(enqueueMove, 1)
	await sleep(200)
	assert.equal(lastError.value, 'Card is under review.')

	dismissError()
	assert.equal(lastError.value, null)
})
