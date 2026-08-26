// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Drag-to-nest hit zones for card drop targets (#5885).
 *
 * A card row is split into THREE zones instead of two:
 *   top edge    → insert above  (reorder, unchanged)
 *   centre band → nest as a sub-card of the hovered card
 *   bottom edge → insert below  (reorder, unchanged)
 *
 * The centre band is only offered when the drop is expected to be accepted by
 * the server, so a user practically never sees an affordance that 400s. The
 * rules mirror CardService::setParent() (one level deep, same board, no
 * self-parent, a card with children cannot become a child) and the CardDetail
 * picker's `availableChildCards` filter. One case can still slip through: the
 * board summary's `childProgress` is visibility-scoped while the server's
 * `CardMapper::hasChildren()` is not, so a card whose only children are hidden
 * from THIS viewer looks childless here. The drop is then rejected server-side
 * and the optimistic patch rolls back with a warning — the same handling any
 * concurrent-edit rejection gets.
 *
 * Both the kanban tile (CardTile.vue) and the list row (BoardListView.vue) use
 * this helper, so the two views share one data contract:
 *
 *   { …base, dropMode: 'nest' }                         ← centre band
 *   attachClosestEdge({ …base, dropMode: 'reorder' })   ← edges (as before)
 *
 * The BoardView card monitor reads `dropMode` to decide between setParent and
 * the plain move mutation.
 */

import { attachClosestEdge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge'

/**
 * Injection key for "this surface can nest by drag".
 *
 * Only a board that owns the card drag monitor AND is in manual sort provides
 * it as true. A cross-board View renders the same CardTile but has no monitor,
 * and a due-date / priority sort ignores card drops entirely — offering the
 * centre band there would promise a nest that silently never happens. Absent
 * provider ⇒ false, so every surface is opt-in.
 */
export const NEST_ENABLED = Symbol('kanso:nestEnabled')

/**
 * Share of the row height given to the centre "nest" band.
 *
 * 40% keeps a comfortable 30% edge zone on each side. Tuned against the two
 * real row heights: the list row is a fixed 36px (→ 14px band, 11px edges) and
 * a kanban tile is 60-110px depending on density and badges.
 */
export const NEST_BAND_RATIO = 0.4

/**
 * Minimum pixels each edge zone must keep. Below this a reorder becomes
 * fiddly, so on a very short row the nest band shrinks (and disappears
 * entirely under ~2x this) rather than eating the edges. Plain reordering must
 * never get harder than it is today.
 */
export const MIN_EDGE_PX = 10

/**
 * Whether dropping `sourceData` onto the card described by `targetData` may
 * create a parent/child link. Mirrors the server's rules so a rejected nest is
 * never offered.
 *
 * Note: a card dropped onto the parent it ALREADY has still returns true — the
 * affordance stays consistent while hovering, and the monitor short-circuits
 * the redundant request. Returning false there would silently turn the drop
 * into an un-nest, which is the opposite of what the pointer is pointing at.
 *
 * @param {object} sourceData drag payload (`source.data`) of the dragged card
 * @param {object} targetData drop-target base data of the hovered card
 * @return {boolean} true when the centre band should offer nesting
 */
export function canNestInto(sourceData, targetData) {
	if (!sourceData || sourceData.type !== 'card') return false
	if (!targetData) return false
	// No self-drop (and therefore no cycle: the hierarchy is one level deep).
	if (Number(sourceData.cardId) === Number(targetData.cardId)) return false
	// One level only — a card that already has a parent cannot become one.
	if (targetData.parentCardId != null) return false
	// A card that has children of its own cannot become a child.
	if (sourceData.hasChildren === true) return false
	return true
}

/**
 * Whether the pointer sits inside the row's centre (nest) band.
 *
 * @param {{ clientY: number }} input pointer input from the drop-target callback
 * @param {Element} element the drop target's element
 * @return {boolean} true when the pointer is in the centre band
 */
export function isInNestBand(input, element) {
	const rect = element.getBoundingClientRect()
	const height = rect.height
	if (!height) return false
	// Shrink the band before the edges: a short row must keep MIN_EDGE_PX of
	// reorder zone top and bottom, even if that leaves no nest band at all.
	const band = Math.min(height * NEST_BAND_RATIO, height - MIN_EDGE_PX * 2)
	if (band <= 0) return false
	const offset = input.clientY - rect.top
	const start = (height - band) / 2
	return offset >= start && offset <= start + band
}

/**
 * The identity fields a card carries as BOTH a drag source and a drop target.
 * Built here so the kanban tile and the list row cannot drift apart: the
 * BoardView monitor reads the same keys from either view.
 *
 * @param {object} card the card summary
 * @param {?string} laneKey swimlane key ('' when swimlanes are off, null in list view)
 * @return {object} the shared drag/drop payload
 */
export function buildCardDragData(card, laneKey) {
	return {
		type: 'card',
		cardId: card.id,
		stackId: card.stackId,
		sortKey: card.sortKey,
		laneKey,
		parentCardId: card.parentCardId ?? null,
		hasChildren: Number(card.childProgress?.total ?? 0) > 0,
	}
}

/**
 * Build the drop-target data for a card row: either the nest payload (centre
 * band, when the relation is allowed) or the classic closest-edge payload.
 *
 * @param {object} args arguments
 * @param {object} args.base the card's identity fields, from buildCardDragData
 * @param {{ clientY: number }} args.input pointer input
 * @param {Element} args.element the drop target's element
 * @param {{ data: object }} args.source the drag source
 * @param {boolean} [args.nestEnabled] whether nesting is offered at all
 * @return {object} the data to return from `getData()`
 */
export function buildCardDropData({ base, input, element, source, nestEnabled = true }) {
	if (nestEnabled && canNestInto(source?.data, base) && isInNestBand(input, element)) {
		return { ...base, dropMode: 'nest' }
	}
	return attachClosestEdge(
		{ ...base, dropMode: 'reorder' },
		{ input, element, allowedEdges: ['top', 'bottom'] },
	)
}
