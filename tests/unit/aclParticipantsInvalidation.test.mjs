// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10669 — sharing a board must repaint the sharer's own assignee picker.
//
// The owner's report was "we have to refresh the page for it to show up under
// assignees". Nothing was wrong on the server: AclService::create appends its
// Change::ENTITY_ACL row exactly as it should. The break was a cache island.
// The participant list — who can be assigned a card — lives under its OWN key
// (['participants', boardId], useAssignees) with a deliberate 3-minute
// staleTime, while all three ACL mutations (useAcl) invalidated only the board
// key. The one action in the whole app that changes who has access was also the
// one action that could not reach the list it changes, so the picker went on
// serving the pre-share names until a hard reload.
//
// This is asserted at the composable level, with BOTH real composables and a
// real QueryClient, because the bug is precisely that two modules disagreed
// about a cache entry. A test that seeded ['participants', '7'] by hand would
// re-spell the key a third time and could pass while the real producer used a
// different one; here useAssignees writes the key and useAcl invalidates it, so
// the assertion is that the two actually meet. The e2e half
// (tests/e2e/share-assignee-live.spec.js) proves the same property through the
// real UI with a real second user.
//
// Rig is boardAccessRevoked.test.mjs's: a `window` stub before any @nextcloud
// import, dynamic imports in that order, the real composables under
// app.runWithContext inside an effectScope (which is what subscribes the query
// observer, so invalidation really refetches), and transport stubbed at the
// axios ADAPTER so the real api.js functions run.

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
const { useAcl } = await import('../../src/composables/useAcl.js')
const { useAssignees } = await import('../../src/composables/useAssignees.js')
const { participantsQueryKey } = await import('../../src/composables/queryKeys.js')

// TanStack's focusManager reads document.visibilityState when it decides
// whether to refetch, so it has to be present and 'visible'.
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
 * A board the viewer is sitting on: a live participants query (the assignee
 * picker's data source) plus the ACL mutations the sharing dialog drives, both
 * real, sharing one QueryClient the way the app's do.
 *
 * The adapter counts participant reads, which is the property under test: the
 * list is refetched because the share settled, not because the staleTime
 * lapsed. `people` is what the server would answer NEXT, so a test can add the
 * new member server-side and then assert the picker caught up.
 *
 * @param {import('node:test').TestContext} t
 * @param {number|string} boardId - distinct per test
 * @return {object} the composables plus counters
 */
function harness(t, boardId) {
	const app = createApp({})
	// gcTime 0 so an unsubscribed query is collected at once instead of parking a
	// five-minute real timer that keeps `node --test` from ever exiting. Every
	// query here is held subscribed for the whole test, so it changes nothing
	// under test. retry:false so a failure is one request, in one tick.
	const queryClient = new QueryClient({
		defaultOptions: {
			queries: { retry: false, gcTime: 0 },
			mutations: { retry: false, gcTime: 0 },
		},
	})
	app.use(VueQueryPlugin, { queryClient })
	clients.push(queryClient)

	const state = { people: [{ uid: 'alice', displayName: 'Alice' }] }
	let participantReads = 0
	let aclWrites = 0

	axios.defaults.adapter = async (config) => {
		if (config.url.includes('/participants')) {
			participantReads++
			return { status: 200, statusText: 'OK', data: state.people, headers: {}, config }
		}
		aclWrites++
		return {
			status: 200,
			statusText: 'OK',
			data: { id: 42, participant: 'bob', permission: 3 },
			headers: {},
			config,
		}
	}

	const scope = effectScope()
	t.after(() => scope.stop())
	const composables = app.runWithContext(() => scope.run(() => ({
		acl: useAcl(() => boardId),
		assignees: useAssignees(() => boardId),
	})))

	return {
		...composables,
		queryClient,
		state,
		participantReads: () => participantReads,
		aclWrites: () => aclWrites,
	}
}

/**
 * The uids the assignee picker would currently offer. Read through the
 * composable's own `participantList`, not the raw query data: since #10704 the
 * cached value is a {items, truncated, limit} page, and the list is the part of
 * it the picker renders.
 */
const offered = (h) => h.assignees.participantList.value.map((p) => p.uid)

test('sharing a board refreshes the assignee picker without a reload', async (t) => {
	const h = harness(t, 10669)
	await flush()

	// The precondition, and why the bug was invisible in the sharing dialog
	// itself: the picker is already populated and already fresh.
	assert.deepEqual(offered(h), ['alice'], 'the picker starts out loaded')
	assert.equal(h.participantReads(), 1)

	// The share lands server-side, so the next participants read carries Bob.
	h.state.people = [
		{ uid: 'alice', displayName: 'Alice' },
		{ uid: 'bob', displayName: 'Bob' },
	]

	await h.acl.addAcl.mutateAsync({ type: 0, participant: 'bob', permission: 3 })
	await flush()

	assert.equal(h.aclWrites(), 1, 'the share must actually have been POSTed')
	assert.equal(h.participantReads(), 2,
		'settling the share must refetch the participant list — the 3-minute '
		+ 'staleTime is a cache policy, not a freshness mechanism, and nothing '
		+ 'else in the app ever invalidates this key')
	assert.deepEqual(offered(h), ['alice', 'bob'],
		'the newly shared-with user must be assignable in the sharer\'s own tab, '
		+ 'without a reload (#10669)')
})

test('revoking a share drops the member from the picker without a reload', async (t) => {
	const h = harness(t, 10670)
	await flush()
	assert.deepEqual(offered(h), ['alice'])

	h.state.people = []
	await h.acl.removeAcl.mutateAsync({ aclId: 42 })
	await flush()

	// The mirror image of the report, and the reason all three mutations settle
	// through the same invalidator: a revoked user must stop being assignable
	// just as promptly as a new one starts.
	assert.equal(h.participantReads(), 2)
	assert.deepEqual(offered(h), [])
})

test('a permission change settles through the same invalidation', async (t) => {
	const h = harness(t, 10671)
	await flush()

	await h.acl.patchAcl.mutateAsync({ aclId: 42, permission: 7 })
	await flush()

	// Today the participants payload is uid + displayName only, so this one is a
	// cheap no-op refetch on a rare admin action rather than a correctness fix.
	// It is here so the three mutations cannot drift apart — the add is the one
	// that was missed last time.
	assert.equal(h.participantReads(), 2)
})

test('the participants key is spelled the same whichever end produces it', () => {
	// The board id reaches these call sites sometimes as a route param (a string)
	// and sometimes off a numeric API field. ['participants', 14] is a DIFFERENT
	// cache entry from ['participants', '14'], so an invalidation spelled with the
	// other type matches nothing and fails silently — exactly the failure mode
	// this card was, one layer down.
	assert.deepEqual(participantsQueryKey(14), ['participants', '14'])
	assert.deepEqual(participantsQueryKey('14'), ['participants', '14'])
	assert.deepEqual(participantsQueryKey(() => 14), ['participants', '14'])
	assert.deepEqual(participantsQueryKey({ value: '14' }), ['participants', '14'])
})
