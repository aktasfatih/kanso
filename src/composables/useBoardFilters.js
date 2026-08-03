// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * useBoardFilters — the composable board filter bar (#3407).
 *
 * A generalization of the old label/priority filter dropdown into a composable,
 * multi-dimension bar. Purely CLIENT-SIDE over the board summary payload: every
 * card already carries `labelIds`, `assigneeIds`, `duedate`, `doneAt` and
 * `priority` (see BoardController::show), so filtering needs NO new endpoint or
 * query. This is NOT a query language — it's a fixed set of dimensions.
 *
 * Dimensions (each independent):
 *   - labels:      Set<number>  → OR within (card matches if it carries ANY)
 *   - assignees:   Set<string>  → OR within (uid), plus the sentinel '__none__'
 *                                 which matches cards with NO assignee.
 *   - priorities:  Set<number>  → OR within (0..4). 0 = "No priority".
 *   - types:       Set<string>  → OR within (built-in card type: bug/feature/
 *                                 task/chore). '' (none) is expressed by leaving
 *                                 the dimension unfiltered (#3402).
 *   - due:         string|null  → one of 'overdue' | 'week' | 'none' | null.
 *   - done:        string|null  → 'done' | 'open' | null (tri-state).
 *
 * Semantics: AND across dimensions, OR within a dimension — standard Linear
 * behaviour. An empty dimension imposes no constraint.
 *
 * The filter is kept as a plain serialisable object (`state`) so the SAME shape
 * drives (a) the predicate, (b) URL query-param sync (shareable links) and
 * (c) saved views persisted in NC user config. Sets are stored as reactive Sets
 * for O(1) membership + fine-grained reactivity; (de)serialisation converts to
 * sorted arrays.
 */

import { reactive, computed } from 'vue'

/** Sentinel assignee id meaning "no assignee". */
export const UNASSIGNED = '__none__'

/** Built-in card types selectable as a filter facet (#3402). */
export const FILTERABLE_TYPES = ['bug', 'feature', 'task', 'chore']

/** Due-date filter options (value + i18n label key). */
export const DUE_OPTIONS = [
	{ value: 'overdue', label: 'Overdue' },
	{ value: 'week', label: 'Due this week' },
	{ value: 'none', label: 'No due date' },
]

/** Done-state filter options (tri-state: null = both). */
export const DONE_OPTIONS = [
	{ value: 'open', label: 'Not done' },
	{ value: 'done', label: 'Done' },
]

/**
 * A fresh, empty filter state. Sets for the multi-select dimensions, scalars for
 * the single-select ones.
 */
export function createFilterState() {
	return reactive({
		labels: new Set(),
		assignees: new Set(),
		priorities: new Set(),
		types: new Set(),
		due: null,
		done: null,
	})
}

/**
 * Serialise a filter state to a plain, sorted, JSON-friendly object. Empty
 * dimensions are omitted so the encoded form (URL / saved view) stays compact.
 * @param {object} s - a filter state
 * @return {object}
 */
export function serializeFilter(s) {
	const out = {}
	if (s.labels.size) out.labels = [...s.labels].sort((a, b) => a - b)
	if (s.assignees.size) out.assignees = [...s.assignees].sort()
	if (s.priorities.size) out.priorities = [...s.priorities].sort((a, b) => a - b)
	if (s.types.size) out.types = [...s.types].sort()
	if (s.due) out.due = s.due
	if (s.done) out.done = s.done
	return out
}

/**
 * Apply a serialised filter object onto an existing reactive state in place
 * (clearing first). Tolerant of partial / malformed input — unknown keys are
 * ignored and bad values are dropped, so a hand-edited URL never throws.
 * @param {object} state - the reactive state to mutate
 * @param {object} obj - a serialised filter (from URL or a saved view)
 */
export function applyFilter(state, obj) {
	state.labels.clear()
	state.assignees.clear()
	state.priorities.clear()
	state.types.clear()
	state.due = null
	state.done = null
	if (!obj || typeof obj !== 'object') return
	if (Array.isArray(obj.labels)) {
		for (const id of obj.labels) {
			const n = Number(id)
			if (Number.isFinite(n)) state.labels.add(n)
		}
	}
	if (Array.isArray(obj.assignees)) {
		for (const uid of obj.assignees) {
			if (typeof uid === 'string' && uid) state.assignees.add(uid)
		}
	}
	if (Array.isArray(obj.priorities)) {
		for (const p of obj.priorities) {
			const n = Number(p)
			if (Number.isInteger(n) && n >= 0 && n <= 4) state.priorities.add(n)
		}
	}
	if (Array.isArray(obj.types)) {
		for (const tp of obj.types) {
			if (FILTERABLE_TYPES.includes(tp)) state.types.add(tp)
		}
	}
	if (DUE_OPTIONS.some((o) => o.value === obj.due)) state.due = obj.due
	if (DONE_OPTIONS.some((o) => o.value === obj.done)) state.done = obj.done
}

/**
 * Encode a serialised filter object into flat URL query params (readable, not a
 * DSL): labels=1,2 · assignees=alice,bob · priorities=3,4 · due=overdue ·
 * done=open. Multi-value dimensions are comma-joined. Returns a params object
 * suitable for spreading into a route `query`. Keys absent when the dimension
 * is empty so they drop out of the URL entirely.
 * @param {object} ser - serialised filter (from serializeFilter)
 * @return {Record<string,string>}
 */
export function filterToQuery(ser) {
	const q = {}
	if (ser.labels?.length) q.fl = ser.labels.join(',')
	if (ser.assignees?.length) q.fa = ser.assignees.join(',')
	if (ser.priorities?.length) q.fp = ser.priorities.join(',')
	if (ser.types?.length) q.ft = ser.types.join(',')
	if (ser.due) q.fd = ser.due
	if (ser.done) q.fs = ser.done
	return q
}

/**
 * Decode URL query params back into a serialised filter object (inverse of
 * filterToQuery). Tolerant of missing / malformed params.
 * @param {Record<string, any>} query - route query (values may be string|string[])
 * @return {object}
 */
export function queryToFilter(query) {
	const first = (v) => (Array.isArray(v) ? v[0] : v)
	const csv = (v) => String(first(v) ?? '').split(',').map((x) => x.trim()).filter(Boolean)
	const out = {}
	if (query.fl != null) out.labels = csv(query.fl)
	if (query.fa != null) out.assignees = csv(query.fa)
	if (query.fp != null) out.priorities = csv(query.fp)
	if (query.ft != null) out.types = csv(query.ft)
	if (query.fd != null) out.due = String(first(query.fd))
	if (query.fs != null) out.done = String(first(query.fs))
	return out
}

/**
 * True if any dimension of the serialised filter carries a constraint.
 * @param {object} ser
 */
export function filterIsEmpty(ser) {
	return !ser.labels?.length
		&& !ser.assignees?.length
		&& !ser.priorities?.length
		&& !ser.types?.length
		&& !ser.due
		&& !ser.done
}

/**
 * Build a card → boolean predicate for a filter state. AND across dimensions,
 * OR within each. Reads only summary fields already present on the card.
 *
 * `now` is injected (defaults to Date.now()) so the "this week" window is stable
 * within one evaluation pass and unit-testable.
 * @param {object} s - reactive filter state
 * @param {number} [now]
 * @return {(card: object) => boolean}
 */
export function makePredicate(s, now = Date.now()) {
	const hasLabels = s.labels.size > 0
	const hasAssignees = s.assignees.size > 0
	const hasPriorities = s.priorities.size > 0
	const hasTypes = s.types.size > 0
	const due = s.due
	const done = s.done

	// "This week" = now .. end of the 7th day ahead (inclusive), i.e. the next
	// seven days. Overdue = strictly before now. Both compare against duedate.
	const weekAhead = now + 7 * 24 * 60 * 60 * 1000

	return (card) => {
		// Labels (OR within): card must carry at least one selected label.
		if (hasLabels) {
			const ids = Array.isArray(card.labelIds) ? card.labelIds : []
			if (!ids.some((id) => s.labels.has(id))) return false
		}
		// Assignees (OR within): match any selected uid, or the UNASSIGNED
		// sentinel for cards with no assignee.
		if (hasAssignees) {
			const ids = Array.isArray(card.assigneeIds) ? card.assigneeIds : []
			const wantsUnassigned = s.assignees.has(UNASSIGNED)
			const matchesUid = ids.some((uid) => s.assignees.has(uid))
			const matchesNone = wantsUnassigned && ids.length === 0
			if (!matchesUid && !matchesNone) return false
		}
		// Priorities (OR within): card.priority (0..4) in the selected set.
		if (hasPriorities) {
			if (!s.priorities.has(Number(card.priority ?? 0))) return false
		}
		// Types (OR within): card's built-in type in the selected set (#3402).
		// A typeless card ('') never matches a type facet.
		if (hasTypes) {
			if (!s.types.has(card.type ?? '')) return false
		}
		// Due (single-select): overdue / this-week / no-due-date.
		if (due) {
			const raw = card.duedate
			if (due === 'none') {
				if (raw) return false
			} else {
				if (!raw) return false
				const t = new Date(raw).getTime()
				if (Number.isNaN(t)) return false
				if (due === 'overdue' && !(t < now)) return false
				if (due === 'week' && !(t >= now && t <= weekAhead)) return false
			}
		}
		// Done state (tri-state): doneAt > 0 means done.
		if (done) {
			const isDone = Number(card.doneAt ?? 0) > 0
			if (done === 'done' && !isDone) return false
			if (done === 'open' && isDone) return false
		}
		return true
	}
}

/**
 * Convenience: a computed count of active filter constraints for a badge.
 * @param {object} s - reactive filter state
 */
export function useFilterCount(s) {
	return computed(() =>
		s.labels.size
		+ s.assignees.size
		+ s.priorities.size
		+ s.types.size
		+ (s.due ? 1 : 0)
		+ (s.done ? 1 : 0),
	)
}
