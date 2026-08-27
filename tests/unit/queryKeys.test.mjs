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
