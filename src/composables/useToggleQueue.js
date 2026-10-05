// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { ref } from 'vue'

/**
 * Returned by a queue's `enqueue` when that very same row is already in flight
 * or waiting its turn - the double-submit a held-down Enter produces (#10705).
 * Nothing was sent, so the caller must not announce an outcome for it.
 *
 * @type {symbol}
 */
export const TOGGLE_ALREADY_PENDING = Symbol('kanso:toggle-already-pending')

/**
 * A FIFO queue for one multi-select picker's toggles.
 *
 * Both attribute-bar pickers in the card modal stay open across picks (#10603),
 * so adding a second and third value is two more clicks - and two clicks are
 * quicker than one round trip on anything but a fast link. Those clicks used to
 * be DISCARDED: each picker held a single "a toggle is pending" id for the whole
 * list and returned early while it was set, so a pick taken during the previous
 * write's round trip sent no request at all. The row stayed unticked, no error
 * was shown, and the only evidence was the value the user had just picked not
 * being on the card.
 *
 * Measured in the browser, three clicks with nothing awaited between them:
 *   - assignees (#10799): 2 of 3 picks reached the server with the write delayed
 *     1200ms, 3 of 3 with no added latency.
 *   - labels (#10920): 1 of 3 with the write delayed 800ms, and 2 of 3 with NO
 *     added latency at all - the label picker's rows sit closer together, so
 *     even a local round trip loses a pick.
 *
 * So a pick for a DIFFERENT row is queued behind the one on the wire instead of
 * dropped, exactly like useCardMove's FIFO move queue (`queue = queue.then(...)`,
 * useCardMove.js:154) - the established in-repo answer to "the user gestured
 * again before the server answered". Serial, not parallel: the optimistic
 * patch/rollback in the mutations' onMutate/onError snapshots the cache, and
 * overlapping snapshots is how a rollback resurrects a value a newer pick had
 * already replaced.
 *
 * A second click on the SAME row while that row is writing is still dropped.
 * That is the double-submit the pending flag was introduced for (#10705) -
 * `aria-busy` leaves the row in the focus order, so a held-down Enter repeats
 * it - and it is per-row here, which is the part that was wrong before.
 *
 * ONE copy, two callers (useAssignees, useLabels). A second hand-written copy is
 * what src/main.js:192-197 warns about: duplicated mechanisms make BOTH copies
 * unfalsifiable, since deleting either leaves the other working and every test
 * green. Queueing and "don't submit the same row twice" are a single mechanism
 * here, in one place, so neither can be true while the other is false.
 *
 * @param {object} options Wiring for one picker.
 * @param {(vars: object) => Promise<*>} options.mutate Sends one toggle - the
 *   picker's `mutateAsync`. Called with the vars handed to `enqueue`.
 * @param {(vars: object) => string} options.keyOf Identifies the ROW a toggle
 *   belongs to (e.g. `${cardId}:${userId}`). Two toggles sharing a key are the
 *   same row, and the second is the double-submit above.
 * @return {{enqueue: (vars: object) => Promise<*>, isPending: (vars: object) => boolean}}
 */
export function createToggleQueue({ mutate, keyOf }) {
	const pending = ref(new Set())
	let queue = Promise.resolve()

	/**
	 * Whether this row is on the wire or waiting its turn. Reactive: the Set
	 * lives in a ref, so a template reading this re-renders when a toggle starts
	 * or finishes.
	 *
	 * @param {object} vars The same shape `enqueue` takes.
	 * @return {boolean}
	 */
	function isPending(vars) {
		return pending.value.has(keyOf(vars))
	}

	/**
	 * Queue one toggle behind whatever is already running.
	 *
	 * @param {object} vars Passed through to `mutate` untouched.
	 * @return {Promise<*>} this toggle's own outcome - resolves with the mutation
	 *   result, with TOGGLE_ALREADY_PENDING when it was a same-row double-submit,
	 *   or rejects with this toggle's error.
	 */
	function enqueue(vars) {
		const key = keyOf(vars)
		if (pending.value.has(key)) {
			return Promise.resolve(TOGGLE_ALREADY_PENDING)
		}
		pending.value.add(key)
		const run = async () => {
			try {
				return await mutate(vars)
			} finally {
				pending.value.delete(key)
			}
		}
		// Chained onto BOTH outcomes of the previous toggle: one refused toggle
		// must not strand every toggle queued behind it. The caller gets this
		// toggle's own promise (so its error is still its own), while the chain
		// carries a swallowed copy so a rejection never becomes an unhandled one.
		const settled = queue.then(run, run)
		queue = settled.then(() => {}, () => {})
		return settled
	}

	return { enqueue, isPending }
}
