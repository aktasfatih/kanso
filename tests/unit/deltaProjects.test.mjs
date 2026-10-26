// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// The board delta carries the viewer's OWN projects referenced by its upserted
// cards (id/title/color), so a card whose project membership changed in the
// window renders with the right project colour without a full show() refetch.
// syncBoardDelta must MERGE those into the board cache's `projects` list by id
// (replace-by-id-or-append) - never a full replacement, which would drop the
// projects other cards still resolve their colour from.

import test, { after } from 'node:test'
import assert from 'node:assert/strict'

globalThis.window = {
	_oc_webroot: '',
	location: { href: 'http://localhost/' },
	addEventListener() {},
	removeEventListener() {},
}

const { QueryClient } = await import('@tanstack/vue-query')
const axios = (await import('@nextcloud/axios')).default
const { syncBoardDelta, seedCursor } = await import('../../src/composables/useBoardDelta.js')
const { boardQueryKey } = await import('../../src/composables/queryKeys.js')

const clients = []
after(() => {
	for (const client of clients) {
		client.unmount()
		client.clear()
	}
})

/**
 * Let every already-resolved promise chain run.
 *
 * @return {Promise<void>}
 */
async function flush() {
	for (let i = 0; i < 20; i++) {
		await new Promise((resolve) => setImmediate(resolve))
	}
}

/**
 * @param {object} config the axios request config
 * @param {object} data the response body
 * @return {object} an axios-shaped response
 */
function respond(config, data) {
	return { status: 200, statusText: 'OK', data, headers: {}, config }
}

/**
 * A board with a seeded delta cursor, a cached `projects` list and an axios
 * adapter that serves `/changes` from `delta`.
 *
 * @param {number} boardId distinct per test; the cursor registry is module-scoped
 * @param {object} options
 * @param {object} options.delta the /changes payload
 * @param {Array<object>} options.projects the board cache's current projects
 * @return {{queryClient: import('@tanstack/vue-query').QueryClient}} the rig
 */
function harness(boardId, { delta, projects }) {
	const queryClient = new QueryClient()
	clients.push(queryClient)

	queryClient.setQueryData(boardQueryKey(boardId), {
		cards: [{ id: 1, stackId: 10, sortKey: 'a' }],
		stacks: [{ id: 10 }],
		projects,
	})
	seedCursor(boardId, 1)

	axios.defaults.adapter = async (config) => {
		if (config.url.includes('/changes')) {
			return respond(config, delta)
		}
		return respond(config, { id: boardId, cards: [], stacks: [], cursor: 1 })
	}

	return { queryClient }
}

test('a delta carrying projects merges them into the board cache by id', async () => {
	const boardId = 401
	// The board cache already knows project 5 (referenced by a card OUTSIDE this
	// window) and project 10 in its OLD form.
	const rig = harness(boardId, {
		projects: [
			{ id: 5, title: 'Kept', color: '111111' },
			{ id: 10, title: 'Old title', color: '222222' },
		],
		delta: {
			cursor: 9,
			cards: { upsert: [{ id: 1, stackId: 10, sortKey: 'a', projectIds: [10, 20] }], remove: [] },
			stacks: { upsert: [], remove: [] },
			// The window's upserted card references projects 10 (updated) and 20
			// (new). Project 5 is NOT referenced by this window's cards.
			projects: [
				{ id: 10, title: 'New title', color: '333333' },
				{ id: 20, title: 'Fresh', color: '444444' },
			],
		},
	})

	await syncBoardDelta(rig.queryClient, boardId)
	await flush()

	const projects = rig.queryClient.getQueryData(boardQueryKey(boardId)).projects
	// Project 5 (not in this window) is KEPT, not dropped by a full replacement.
	// Project 10 is REPLACED by its new form. Project 20 is APPENDED.
	assert.equal(projects.length, 3, 'the merge must keep, replace and append')
	assert.deepEqual(projects.find((p) => p.id === 5), { id: 5, title: 'Kept', color: '111111' })
	assert.deepEqual(projects.find((p) => p.id === 10), { id: 10, title: 'New title', color: '333333' })
	assert.deepEqual(projects.find((p) => p.id === 20), { id: 20, title: 'Fresh', color: '444444' })
})

test('a delta with no projects leaves the board cache projects untouched', async () => {
	const boardId = 402
	const rig = harness(boardId, {
		projects: [{ id: 5, title: 'Kept', color: '111111' }],
		delta: {
			cursor: 9,
			cards: { upsert: [{ id: 1, stackId: 10, sortKey: 'a' }], remove: [] },
			stacks: { upsert: [], remove: [] },
			// No `projects` key: the window's cards reference no project.
		},
	})

	await syncBoardDelta(rig.queryClient, boardId)
	await flush()

	const projects = rig.queryClient.getQueryData(boardQueryKey(boardId)).projects
	assert.deepEqual(projects, [{ id: 5, title: 'Kept', color: '111111' }],
		'absent projects key must not touch the cache')
})
