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
			lanes.push(toLane({ key: 'priority:' + level.value, title: t('kanso', level.label), color: null, cards }))
		}
	}
	lanes.push(toLane({ key: 'priority:0', title: t('kanso', 'No priority'), color: null, cards: byLevel.get(0) ?? [] }))
	return lanes
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
