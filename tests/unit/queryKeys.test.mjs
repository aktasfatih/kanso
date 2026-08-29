// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #9859 — the cross-board feed invalidation contract.
//
// Two exported invalidators, deliberately NOT one:
//   invalidateMyWork          → the three My Work feeds only. Driven from the
//                               REALTIME path (main.js), i.e. every notify_push
//                               event, throttled to 30s.
//   invalidateCrossBoardFeeds → those three PLUS the View feed. Driven from the
//                               settle phase of card mutations.
//
// The regression this file exists to catch: someone "simplifies" the pair by
// appending ['view-cards'] to MY_WORK_QUERY_KEYS. That would put the heaviest
// query in the app (enriched card summaries across every readable board) on a
// 30s push-driven cadence — double its own 60s interval — and it would bite
// precisely when the user is sitting on a View, since useViewCards is mounted
// only by ViewPage and invalidateQueries refetches ACTIVE queries.
//
// This is asserted here rather than in Playwright on purpose: the trigger is a
// notify_push event, and push is unavailable in the dev stack and explicitly
// disabled in CI (KANSO_SKIP_NOTIFY_PUSH=1), so a browser test of it would pass
// vacuously and guard nothing. queryKeys.js imports nothing, so the real module
// can be exercised directly here — no bundler, no DOM, no mocks beyond a
// recording stub.

import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

import {
	MY_WORK_QUERY_KEYS,
	VIEW_CARDS_QUERY_KEY,
	invalidateMyWork,
	invalidateCrossBoardFeeds,
} from '../../src/composables/queryKeys.js'

/**
 * Minimal QueryClient stand-in that records the keys it was asked to
 * invalidate, serialised so they compare as plain strings.
 */
function recordingClient() {
	const invalidated = []
	return {
		invalidated,
		invalidateQueries({ queryKey }) {
			invalidated.push(queryKey.join('/'))
		},
	}
}

test('the realtime funnel invalidates the My Work feeds and NOTHING else', () => {
	const client = recordingClient()
	invalidateMyWork(client)

	assert.deepEqual(client.invalidated, ['my-cards', 'my-reviews', 'inbox'])
	assert.ok(
		!client.invalidated.includes(VIEW_CARDS_QUERY_KEY.join('/')),
		'invalidateMyWork must never touch the View feed — it runs on every push event',
	)
})

test('MY_WORK_QUERY_KEYS does not contain the View feed key', () => {
	const keys = MY_WORK_QUERY_KEYS.map((k) => k.join('/'))
	assert.ok(
		!keys.includes(VIEW_CARDS_QUERY_KEY.join('/')),
		'appending view-cards here would put it on the 30s push cadence — use invalidateCrossBoardFeeds instead',
	)
})

test('the mutation settle path invalidates the View feed as well as My Work', () => {
	const client = recordingClient()
	invalidateCrossBoardFeeds(client)

	assert.deepEqual(client.invalidated, ['my-cards', 'my-reviews', 'inbox', 'view-cards'])
})

test('the View feed key is a bare prefix, so it survives future per-view params', () => {
	// invalidateQueries prefix-matches, so ['view-cards'] keeps working if the
	// query later becomes ['view-cards', viewId] / ['view-cards', filterParams].
	assert.deepEqual(VIEW_CARDS_QUERY_KEY, ['view-cards'])
})

test('main.js wires the realtime path to the narrow invalidator only', async () => {
	// The behavioural half above proves invalidateMyWork is narrow; this proves
	// the push funnel is the thing calling it. Source-level because main.js is
	// module top-level side effects (createApp/mount) and cannot be imported.
	const main = await readFile(new URL('../../src/main.js', import.meta.url), 'utf8')

	assert.match(
		main,
		/invalidateMyWork\(queryClient\)/,
		'the throttled push/delta funnel must call the My-Work-only invalidator',
	)
	// A call or an import, not a mention — main.js documents the split in prose.
	assert.doesNotMatch(
		main,
		/invalidateCrossBoardFeeds\s*\(|import\b[^\n]*invalidateCrossBoardFeeds/,
		'main.js must not put the View feed on the realtime path — that is the #9859 regression',
	)
})

// ── #9981 — the mutation-side burst guard ────────────────────────────────────
//
// invalidateCrossBoardFeeds is called from ~20 mutation settle sites. With the
// card overlay open ON a View, ViewPage stays mounted, so ['view-cards'] is an
// ACTIVE query and every one of those calls used to trigger a full cross-board
// refetch. Ticking five checklist items = five of the heaviest reads in the app.
//
// The guard is leading-edge + trailing-debounce, and BOTH halves matter: the
// leading edge is what keeps the View tile repainting on the first edit (the
// property tests/e2e/view-checklist-live.spec.js asserts in a browser), the
// trailing edge is what collapses the rest of the burst.
//
// The throttle keeps burst state in module scope, so each test below imports its
// own fresh copy of the module (a unique ?burst= makes Node treat it as a
// distinct module) rather than leaking a hot window into its neighbours.

let burstCounter = 0
/** A fresh, un-warmed copy of queryKeys.js with its own burst state. */
function freshQueryKeys() {
	return import(`../../src/composables/queryKeys.js?burst=${++burstCounter}`)
}

/** Count of view-cards invalidations recorded by a recordingClient. */
function viewFeedHits(client) {
	return client.invalidated.filter((k) => k === 'view-cards').length
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

test('a burst of mutations refetches the View feed once, immediately', async () => {
	const { invalidateCrossBoardFeeds: invalidate } = await freshQueryKeys()
	const client = recordingClient()

	for (let i = 0; i < 5; i++) invalidate(client)

	// Leading edge only, and it already fired — synchronously, before any await.
	assert.equal(viewFeedHits(client), 1,
		'five settle calls in one tick must not be five cross-board reads')
	// The cheap per-user feeds are deliberately NOT throttled: 5 calls × 3 keys.
	assert.equal(client.invalidated.filter((k) => k === 'my-cards').length, 5,
		'the My Work feeds must stay immediate — only the heavy query is guarded')
})

test('the burst settles with exactly one catch-up refetch', async () => {
	const { invalidateCrossBoardFeeds: invalidate, VIEW_FEED_INVALIDATE_THROTTLE: window }
		= await freshQueryKeys()
	const client = recordingClient()

	for (let i = 0; i < 5; i++) invalidate(client)
	assert.equal(viewFeedHits(client), 1)

	await sleep(window * 2)

	// Leading + one trailing. The trailing edge is what makes the feed agree with
	// the LAST edit of the burst, not just the first.
	assert.equal(viewFeedHits(client), 2,
		'the burst must settle on exactly one catch-up refetch, not one per tick')
})

test('an isolated edit after the window still refetches the View feed at once', async () => {
	const { invalidateCrossBoardFeeds: invalidate, VIEW_FEED_INVALIDATE_THROTTLE: window }
		= await freshQueryKeys()
	const client = recordingClient()

	invalidate(client)
	assert.equal(viewFeedHits(client), 1)

	await sleep(window * 2)

	// A single edit is not a burst: the leading edge must fire on the spot, or
	// the View tile behind the overlay stops repainting promptly.
	invalidate(client)
	assert.equal(viewFeedHits(client), 2,
		'a lone edit outside the burst window must not be deferred')
})

test('the burst window is short enough to read as instant', async () => {
	const { VIEW_FEED_INVALIDATE_THROTTLE: window } = await freshQueryKeys()

	// Bounded on both sides on purpose. Too long and the trailing refetch stops
	// feeling live (view-checklist-live.spec.js would need a longer wait — the
	// signal that this number, not the spec, is wrong). Too short and it stops
	// collapsing anything.
	assert.ok(window >= 200 && window <= 600,
		`View feed burst window out of range: ${window}ms`)
})
