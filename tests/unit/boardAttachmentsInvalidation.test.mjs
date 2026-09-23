// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10738 — attaching a file must show up in the board's attachment list.
//
// The owner's report: "when I add an attachment and view the attachments on
// this board, it isn't reflected there without me refreshing the page." The
// same cache island as #10669, one feature later. The board-wide listing lives
// under its OWN key (['board-attachments', boardId], useBoardAttachments) with
// a deliberate 30s staleTime, while all three attachment mutations
// (useCardAttachments) invalidated only the per-CARD key — so the one set of
// actions that adds and removes these very rows was also the one set that could
// not reach the list they change.
//
// Asserted at the composable level with BOTH real composables and a real
// QueryClient, for the reason the #10669 test spells out: the bug IS two
// modules disagreeing about a cache entry, so a test that seeded the key by
// hand would re-spell it a third time and could pass while the producer used
// another. Note the deliberate type mismatch in the harness — the listing is
// opened with a NUMBER (BoardAttachmentsModal passes Number(props.boardId))
// while the mutations are wired with a STRING (CardDetail's boardId computed is
// String-coerced). That is the #10669 trap reproduced exactly, and it is why
// both ends go through boardAttachmentsQueryKey().
//
// Rig is aclParticipantsInvalidation.test.mjs's, unchanged.

import test, { after } from 'node:test'
import assert from 'node:assert/strict'

globalThis.window = {
	_oc_webroot: '',
	location: { href: 'http://localhost/' },
	addEventListener() {},
	removeEventListener() {},
}

const { createApp, effectScope } = await import('vue')
const { QueryClient, VueQueryPlugin } = await import('@tanstack/vue-query')
const axios = (await import('@nextcloud/axios')).default
const { useCardAttachments } = await import('../../src/composables/useCardAttachments.js')
const { useBoardAttachments, BOARD_ATTACHMENTS_PAGE_SIZE } = await import('../../src/composables/useBoardAttachments.js')
const { boardAttachmentsQueryKey } = await import('../../src/composables/queryKeys.js')

globalThis.document = {
	hidden: false,
	visibilityState: 'visible',
	addEventListener() {},
	removeEventListener() {},
}

const clients = []
after(() => {
	for (const client of clients) {
		client.unmount()
		client.clear()
	}
})

/**
 * Let every already-resolved promise chain — and Vue's scheduler — run.
 *
 * @return {Promise<void>}
 */
async function flush() {
	for (let i = 0; i < 30; i++) {
		await new Promise((resolve) => setImmediate(resolve))
	}
}

/**
 * A board whose attachment modal is open while a card's attachments are edited:
 * the real board listing query and the real per-card mutations, sharing one
 * QueryClient the way the app's do.
 *
 * The adapter counts BOARD listing reads — the property under test is that the
 * listing is refetched because a mutation settled, not because 30s lapsed — and
 * serves pages out of `state.files`, so a test can change the server's answer
 * and then assert the modal caught up.
 *
 * @param {import('node:test').TestContext} t
 * @param {number} boardId - distinct per test
 * @param {Array} files - the board's attachments, newest first
 * @return {object} the composables plus counters
 */
function harness(t, boardId, files = [{ id: 1, filename: 'spec.pdf', cardId: 7 }]) {
	const app = createApp({})
	const queryClient = new QueryClient({
		defaultOptions: {
			queries: { retry: false, gcTime: 0 },
			mutations: { retry: false, gcTime: 0 },
		},
	})
	app.use(VueQueryPlugin, { queryClient })
	clients.push(queryClient)

	const state = { files: [...files] }
	let boardReads = 0
	let cardWrites = 0
	const offsets = []

	axios.defaults.adapter = async (config) => {
		const url = config.url
		if (url.includes('/attachments') && url.includes('/boards/')) {
			boardReads++
			const limit = Number(config.params?.limit ?? state.files.length)
			const offset = Number(config.params?.offset ?? 0)
			offsets.push(offset)
			const items = state.files.slice(offset, offset + limit)
			return {
				status: 200,
				statusText: 'OK',
				config,
				headers: {},
				data: {
					items,
					total: state.files.length,
					capped: (offset + items.length) < state.files.length,
				},
			}
		}
		if (url.includes('/cards/') && (config.method ?? 'get').toLowerCase() === 'get') {
			return { status: 200, statusText: 'OK', config, headers: {}, data: state.files }
		}
		cardWrites++
		return { status: 200, statusText: 'OK', config, headers: {}, data: { id: 99 } }
	}

	const scope = effectScope()
	t.after(() => scope.stop())
	const composables = app.runWithContext(() => scope.run(() => ({
		// NUMBER here, STRING below - on purpose; see the header.
		board: useBoardAttachments(boardId),
		card: useCardAttachments(() => 7, () => String(boardId)),
	})))

	return {
		...composables,
		queryClient,
		state,
		offsets,
		boardReads: () => boardReads,
		cardWrites: () => cardWrites,
	}
}

/** The filenames the board attachments modal would currently render. */
const listed = (h) =>
	(h.board.data.value?.pages ?? []).flatMap((page) => page.items ?? []).map((a) => a.filename)

test('uploading a file refreshes the board attachment list without a reload', async (t) => {
	const h = harness(t, 10738)
	await flush()

	// The precondition, and why the bug was easy to miss: the listing is already
	// loaded and already fresh, so nothing refetches it on its own.
	assert.deepEqual(listed(h), ['spec.pdf'], 'the listing starts out loaded')
	assert.equal(h.boardReads(), 1)

	// The upload lands server-side, so the next listing read carries it.
	h.state.files = [{ id: 2, filename: 'design.png', cardId: 7 }, { id: 1, filename: 'spec.pdf', cardId: 7 }]

	await h.card.uploadAttachment.mutateAsync({ name: 'design.png' })
	await flush()

	assert.equal(h.cardWrites(), 1, 'the upload must actually have been POSTed')
	assert.equal(h.boardReads(), 2,
		'settling the upload must refetch the board listing — the 30s staleTime is '
		+ 'a cache policy, not a freshness mechanism, and nothing else in the app '
		+ 'ever invalidates this key')
	assert.deepEqual(listed(h), ['design.png', 'spec.pdf'],
		'the uploaded file must appear in the board listing with no reload (#10738)')
})

test('removing a file drops it from the board attachment list without a reload', async (t) => {
	const h = harness(t, 10739)
	await flush()
	assert.deepEqual(listed(h), ['spec.pdf'])

	h.state.files = []
	await h.card.removeAttachment.mutateAsync(1)
	await flush()

	// The mirror image, and the half the report did not mention: a deleted file
	// left a row in the board listing just as stubbornly as a new one was missing.
	assert.equal(h.boardReads(), 2)
	assert.deepEqual(listed(h), [])
})

test('attaching from Files settles through the same invalidation', async (t) => {
	const h = harness(t, 10740)
	await flush()

	h.state.files = [{ id: 3, filename: 'from-files.txt', cardId: 7 }, { id: 1, filename: 'spec.pdf', cardId: 7 }]
	await h.card.attachFromFiles.mutateAsync(4242)
	await flush()

	// All three mutations settle through one invalidator so they cannot drift
	// apart — a missed one is exactly how this bug shipped.
	assert.equal(h.boardReads(), 2)
	assert.deepEqual(listed(h), ['from-files.txt', 'spec.pdf'])
})

test('the listing is paged, and every page past the first is reachable', async (t) => {
	const many = Array.from({ length: BOARD_ATTACHMENTS_PAGE_SIZE + 3 }, (_, i) => ({
		id: 1000 - i, filename: `file-${i}.txt`, cardId: 7,
	}))
	const h = harness(t, 10741, many)
	await flush()

	// Opening the modal is ONE request for ONE page, whatever the board holds.
	assert.equal(h.boardReads(), 1)
	assert.equal(listed(h).length, BOARD_ATTACHMENTS_PAGE_SIZE)
	assert.equal(h.board.hasNextPage.value, true, 'the server said there is more')
	assert.ok(!listed(h).includes(`file-${BOARD_ATTACHMENTS_PAGE_SIZE}.txt`),
		'the row past the first page must NOT be in the first page')

	await h.board.fetchNextPage()
	await flush()

	// The next page is asked for by OFFSET - the rows already in hand - which is
	// the whole of the paging contract with the server.
	assert.deepEqual(h.offsets, [0, BOARD_ATTACHMENTS_PAGE_SIZE])
	assert.equal(listed(h).length, many.length)
	assert.ok(listed(h).includes(`file-${BOARD_ATTACHMENTS_PAGE_SIZE}.txt`),
		'a file past the first page must be reachable (#10738)')
	assert.equal(h.board.hasNextPage.value, false, 'nothing left to ask for')
})

test('the board-attachments key is spelled the same whichever end produces it', () => {
	// The modal resolves the id off a component prop (a Number) while the
	// mutation side derives it from the route param (a String).
	// ['board-attachments', 14] is a DIFFERENT cache entry from
	// ['board-attachments', '14'], so an invalidation spelled with the other type
	// matches nothing and fails silently — the trap #10669 hit one layer down.
	assert.deepEqual(boardAttachmentsQueryKey(14), ['board-attachments', '14'])
	assert.deepEqual(boardAttachmentsQueryKey('14'), ['board-attachments', '14'])
	assert.deepEqual(boardAttachmentsQueryKey(() => 14), ['board-attachments', '14'])
	assert.deepEqual(boardAttachmentsQueryKey({ value: '14' }), ['board-attachments', '14'])
})
