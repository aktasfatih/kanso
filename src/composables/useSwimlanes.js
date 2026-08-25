// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * useSwimlanes — client-side board grouping into horizontal lanes (#3406).
 *
 * Purely a view transform over the existing board summary payload: every card
 * already carries `labelIds`, `assigneeIds` and `priority` (see
 * BoardController::show), so grouping needs NO new endpoint or query.
 *
 * Grouping modes:
 *   - 'none'      → flat board (no lanes; caller renders the classic stack row)
 *   - 'assignee'  → one lane per assignee uid, plus an "Unassigned" lane
 *   - 'label'     → one lane per board label, plus a "No label" lane
 *   - 'priority'  → one lane per priority level (Urgent→Low), plus a "No priority" lane
 *
 * Multi-value handling (documented choice): a card with MULTIPLE labels or
 * MULTIPLE assignees appears in EACH matching lane — mirroring Jira/Linear
 * swimlane behaviour where a card surfaces under every group it belongs to.
 * Priority is single-valued so a card lands in exactly one priority lane.
 *
 * A card with no value for the active field (no labels / no assignees /
 * priority 0) falls into the trailing catch-all lane.
 *
 * Each lane carries a stable string `key` (used as the DnD lane token and the
 * per-lane column-ref namespace) plus a human `title`. `cardsByStack` is a
 * Map<stackId, card[]> already filtered + display-sorted by the caller; this
 * composable only partitions those cards into lanes, so virtualization and the
 * existing move path per stack are preserved unchanged.
 */

import { translate as t } from '@nextcloud/l10n'
import { PRIORITY_LEVELS } from './usePriority.js'
import { CARD_TYPES } from './useCardType.js'

export const SWIMLANE_MODES = ['none', 'assignee', 'label', 'priority']

/**
 * Build the lane list for a grouping mode.
 *
 * @param {string} mode - one of SWIMLANE_MODES
 * @param {Map<number, object[]>} cardsByStack - filtered + sorted cards per stack
 * @param {object[]} labels - board labels ({ id, title, color })
 * @param {object[]} participants - board participants ({ uid, displayName })
 * @return {Array<{ key: string, title: string, color: ?string, cardsByStack: Map<number, object[]> }>}
 *   The lanes to render, in display order. Empty array when mode === 'none'.
 */
export function buildLanes(mode, cardsByStack, labels, participants) {
	if (mode === 'none' || !SWIMLANE_MODES.includes(mode)) return []

	// Flatten once; each card keeps its stackId so we can re-partition per lane.
	const allCards = []
	for (const cards of cardsByStack.values()) {
		for (const c of cards) allCards.push(c)
	}

	// laneOrder preserves display order; laneDefs holds meta keyed by lane key.
	const laneOrder = []
	const laneDefs = new Map()
	const ensureLane = (key, title, color = null) => {
		if (!laneDefs.has(key)) {
			laneDefs.set(key, { key, title, color, cards: [] })
			laneOrder.push(key)
		}
		return laneDefs.get(key)
	}

	if (mode === 'label') {
		// Seed a lane per board label so empty lanes still show a header, then a
		// trailing "No label" lane. Cards land in every label lane they carry.
		for (const label of labels) {
			ensureLane('label:' + label.id, label.title, label.color ?? null)
		}
		const none = { key: 'label:none', title: t('kanso', 'No label'), color: null, cards: [] }
		for (const card of allCards) {
			const ids = Array.isArray(card.labelIds) ? card.labelIds : []
			if (ids.length === 0) {
				none.cards.push(card)
				continue
			}
			for (const id of ids) {
				const lane = laneDefs.get('label:' + id)
				// Guard: a card may reference a label not in the board list (stale);
				// fall back to the catch-all so it never vanishes from the board.
				if (lane) lane.cards.push(card)
				else none.cards.push(card)
			}
		}
		// Only render label lanes that have cards (avoid a wall of empty lanes on
		// boards with many unused labels); always append the catch-all last.
		const lanes = laneOrder
			.map((k) => laneDefs.get(k))
			.filter((l) => l.cards.length > 0)
		lanes.push(none)
		return lanes.map(toLane)
	}

	if (mode === 'assignee') {
		const nameByUid = new Map()
		for (const p of participants ?? []) nameByUid.set(p.uid, p.displayName || p.uid)
		const none = { key: 'assignee:none', title: t('kanso', 'Unassigned'), color: null, cards: [] }
		for (const card of allCards) {
			const ids = Array.isArray(card.assigneeIds) ? card.assigneeIds : []
			if (ids.length === 0) {
				none.cards.push(card)
				continue
			}
			for (const uid of ids) {
				ensureLane('assignee:' + uid, nameByUid.get(uid) || uid).cards.push(card)
			}
		}
		const lanes = laneOrder.map((k) => laneDefs.get(k))
		// Stable, friendly order: by display title (locale-aware), catch-all last.
		lanes.sort((a, b) => String(a.title).localeCompare(String(b.title)))
		lanes.push(none)
		return lanes.map(toLane)
	}

	// mode === 'priority' — one lane per level, highest first, then "No priority".
	const byLevel = new Map()
	for (const card of allCards) {
		const lvl = Number(card.priority ?? 0)
		if (!byLevel.has(lvl)) byLevel.set(lvl, [])
		byLevel.get(lvl).push(card)
	}
	const lanes = []
	for (const level of [...PRIORITY_LEVELS].filter((l) => l.value > 0).sort((a, b) => b.value - a.value)) {
		const cards = byLevel.get(level.value) ?? []
		if (cards.length > 0) {
			lanes.push(toLane({ key: 'priority:' + level.value, title: level.label, color: null, cards }))
		}
	}
	lanes.push(toLane({ key: 'priority:0', title: t('kanso', 'No priority'), color: null, cards: byLevel.get(0) ?? [] }))
	return lanes
}

/**
 * Group-by fields the cross-board Views surface (#3815) offers. Distinct from
 * SWIMLANE_MODES: Views group a FLAT cross-board card array (no stacks), and add
 * 'status' (done/in-progress/todo), 'board', 'type', 'review', 'due' and 'owner'
 * on top of the shared assignee/priority logic below.
 */
export const VIEW_GROUP_BY = ['status', 'priority', 'assignee', 'board', 'type', 'review', 'due', 'owner']

/** Card status buckets for the 'status' group-by, in display order. */
const STATUS_ORDER = [
	{ key: 'status:in_progress', label: t('kanso', 'In progress'), match: (c) => Number(c.doneAt ?? 0) === 0 && Number(c.startedAt ?? 0) > 0 },
	{ key: 'status:todo', label: t('kanso', 'To do'), match: (c) => Number(c.doneAt ?? 0) === 0 && Number(c.startedAt ?? 0) === 0 },
	{ key: 'status:done', label: t('kanso', 'Done'), match: (c) => Number(c.doneAt ?? 0) > 0 },
]

/** Review-state buckets for the 'review' group-by, in fixed display order. */
const REVIEW_ORDER = [
	{ key: 'review:pending', label: t('kanso', 'Needs review'), match: (c) => c.reviewState === 'pending' },
	{ key: 'review:approved', label: t('kanso', 'Approved'), match: (c) => c.reviewState === 'approved' },
	{ key: 'review:changes_requested', label: t('kanso', 'Changes requested'), match: (c) => c.reviewState === 'changes_requested' },
	{ key: 'review:none', label: t('kanso', 'No review'), match: (c) => c.reviewState == null },
]

/**
 * Group a FLAT card array by an arbitrary field for the cross-board Views
 * surface (#3815). Reuses the assignee/priority partitioning logic from
 * {@see buildLanes} (multi-value assignees surface in each lane; priority is
 * single-valued) and adds 'status' and 'board'. Cards with no value for the
 * field fall into a trailing catch-all group.
 *
 * Returns an ordered list of `{ key, title, cards }` groups (empty groups
 * omitted, catch-all last) - the render shape the generalized List/Timeline
 * consume. It does NOT touch stacks, so it is orthogonal to buildLanes.
 *
 * @param {string} field - one of VIEW_GROUP_BY
 * @param {object[]} cards - the flat cross-board card summaries
 * @param {object} [opts]
 * @param {Map<string,string>} [opts.nameByUid] - assignee uid → display name
 * @param {Map<number,string>} [opts.titleByBoard] - board id → board title
 * @return {Array<{ key: string, title: string, cards: object[] }>}
 */
export function groupCardsByField(field, cards, opts = {}) {
	const all = Array.isArray(cards) ? cards : []

	if (field === 'priority') {
		const byLevel = new Map()
		for (const card of all) {
			const lvl = Number(card.priority ?? 0)
			if (!byLevel.has(lvl)) byLevel.set(lvl, [])
			byLevel.get(lvl).push(card)
		}
		const groups = []
		for (const level of [...PRIORITY_LEVELS].filter((l) => l.value > 0).sort((a, b) => b.value - a.value)) {
			const bucket = byLevel.get(level.value) ?? []
			if (bucket.length > 0) groups.push({ key: 'priority:' + level.value, title: level.label, cards: bucket })
		}
		const none = byLevel.get(0) ?? []
		if (none.length > 0) groups.push({ key: 'priority:0', title: t('kanso', 'No priority'), cards: none })
		return groups
	}

	if (field === 'assignee') {
		const nameByUid = opts.nameByUid ?? new Map()
		const laneOrder = []
		const laneDefs = new Map()
		const none = []
		for (const card of all) {
			const ids = Array.isArray(card.assigneeIds) ? card.assigneeIds : []
			if (ids.length === 0) { none.push(card); continue }
			for (const uid of ids) {
				const key = 'assignee:' + uid
				if (!laneDefs.has(key)) { laneDefs.set(key, { key, title: nameByUid.get(uid) || uid, cards: [] }); laneOrder.push(key) }
				laneDefs.get(key).cards.push(card)
			}
		}
		const groups = laneOrder.map((k) => laneDefs.get(k))
		groups.sort((a, b) => String(a.title).localeCompare(String(b.title)))
		if (none.length > 0) groups.push({ key: 'assignee:none', title: t('kanso', 'Unassigned'), cards: none })
		return groups
	}

	if (field === 'board') {
		const titleByBoard = opts.titleByBoard ?? new Map()
		const laneOrder = []
		const laneDefs = new Map()
		for (const card of all) {
			const bid = Number(card.boardId ?? 0)
			const key = 'board:' + bid
			if (!laneDefs.has(key)) { laneDefs.set(key, { key, title: titleByBoard.get(bid) || card.boardTitle || String(bid), cards: [] }); laneOrder.push(key) }
			laneDefs.get(key).cards.push(card)
		}
		const groups = laneOrder.map((k) => laneDefs.get(k))
		groups.sort((a, b) => String(a.title).localeCompare(String(b.title)))
		return groups
	}

	if (field === 'type') {
		// One group per built-in type, fixed order, plus a trailing "No type".
		const byType = new Map()
		for (const card of all) {
			const tp = card.type || ''
			if (!byType.has(tp)) byType.set(tp, [])
			byType.get(tp).push(card)
		}
		const groups = []
		for (const def of CARD_TYPES) {
			const bucket = byType.get(def.value) ?? []
			if (bucket.length > 0) groups.push({ key: 'type:' + def.value, title: def.label, cards: bucket })
		}
		const none = byType.get('') ?? []
		if (none.length > 0) groups.push({ key: 'type:none', title: t('kanso', 'No type'), cards: none })
		return groups
	}

	if (field === 'review') {
		// Fixed-order review buckets; empty ones omitted.
		const groups = []
		for (const bucket of REVIEW_ORDER) {
			const cardsIn = all.filter(bucket.match)
			if (cardsIn.length > 0) groups.push({ key: bucket.key, title: bucket.label, cards: cardsIn })
		}
		return groups
	}

	if (field === 'due') {
		// Overdue / This week / Later / No due date, mirroring the due FILTER
		// buckets. `now` is read once for a stable window across the pass.
		const now = Date.now()
		const weekAhead = now + 7 * 24 * 60 * 60 * 1000
		const overdue = []
		const week = []
		const later = []
		const none = []
		for (const card of all) {
			const raw = card.duedate
			if (!raw) { none.push(card); continue }
			const time = new Date(raw).getTime()
			if (Number.isNaN(time)) { none.push(card); continue }
			if (time < now) overdue.push(card)
			else if (time <= weekAhead) week.push(card)
			else later.push(card)
		}
		const groups = []
		if (overdue.length > 0) groups.push({ key: 'due:overdue', title: t('kanso', 'Overdue'), cards: overdue })
		if (week.length > 0) groups.push({ key: 'due:week', title: t('kanso', 'This week'), cards: week })
		if (later.length > 0) groups.push({ key: 'due:later', title: t('kanso', 'Later'), cards: later })
		if (none.length > 0) groups.push({ key: 'due:none', title: t('kanso', 'No due date'), cards: none })
		return groups
	}

	if (field === 'owner') {
		// One group per owner uid (single-valued), locale-sorted by display name.
		const nameByUid = opts.nameByUid ?? new Map()
		const laneOrder = []
		const laneDefs = new Map()
		for (const card of all) {
			const uid = card.owner || ''
			const key = 'owner:' + uid
			if (!laneDefs.has(key)) { laneDefs.set(key, { key, title: nameByUid.get(uid) || uid || t('kanso', 'No owner'), cards: [] }); laneOrder.push(key) }
			laneDefs.get(key).cards.push(card)
		}
		const groups = laneOrder.map((k) => laneDefs.get(k))
		groups.sort((a, b) => String(a.title).localeCompare(String(b.title)))
		return groups
	}

	// field === 'status' (default): the three status buckets, in fixed order.
	const groups = []
	for (const bucket of STATUS_ORDER) {
		const cardsIn = all.filter(bucket.match)
		if (cardsIn.length > 0) groups.push({ key: bucket.key, title: bucket.label, cards: cardsIn })
	}
	return groups
}

/**
 * Convert a lane accumulator ({ key, title, color, cards }) into the render
 * shape: cards re-partitioned into a Map<stackId, card[]>, preserving the
 * incoming (already display-sorted) order within each stack.
 */
function toLane(def) {
	const map = new Map()
	for (const card of def.cards) {
		if (!map.has(card.stackId)) map.set(card.stackId, [])
		map.get(card.stackId).push(card)
	}
	return { key: def.key, title: def.title, color: def.color, cardsByStack: map }
}
