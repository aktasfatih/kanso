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
 *
 * Drag-out (un-nest) rides the SAME reorder edges, but only on a surface that
 * renders the hierarchy. A reorder drop carries `parentChange` — the parent the
 * dropped card takes at that position — computed by {@see reorderParentChange}
 * from the row's own level (`rowParentId`):
 *
 *   rowParentId === undefined  the view has no levels (kanban): parentChange is
 *                              always null, so a column move or a reorder can
 *                              never break a sub-card relation the board does
 *                              not even draw.
 *   rowParentId === null       a top-level position: dropping a sub-card here
 *                              clears its parent (the user literally dragged it
 *                              out of the indent).
 *   rowParentId === <id>       inside <id>'s children: reordering a sub-card
 *                              among its siblings keeps its parent; dropping a
 *                              card from elsewhere adopts it into that parent.
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
 * The parent a dragged card is DRAWN under right now: its row's own level when
 * the view draws levels, else its stored parent.
 *
 * The two differ for a sub-card whose parent lives in another column — the list
 * only indents a child under a parent in the same group, so that card renders
 * flush-left even though it stores a parent.
 *
 * @param {object} sourceData drag payload (`source.data`) of the dragged card
 * @return {?number} the rendered parent id, or null for a top-level row
 */
export function renderedParentOf(sourceData) {
	if (!sourceData) return null
	return sourceData.rowParentId !== undefined
		? (sourceData.rowParentId ?? null)
		: (sourceData.parentCardId ?? null)
}

/**
 * The parent change a plain REORDER drop at a given row implies, or null when
 * the drop leaves the card's parent exactly as it is.
 *
 * This is the inverse of the centre-band nest: dragging an indented sub-card row
 * out to a top-level position takes it out of its parent. It is offered ONLY on
 * a surface that draws the hierarchy (list view), which is why `rowParentId`
 * defaults to undefined — a kanban tile renders a sub-card exactly like any
 * other card, so a column move or reorder there must never silently detach it.
 *
 * Levels are compared as RENDERED, never as stored: the source's own drawn level
 * rides the drag payload as `rowParentId`. A sub-card whose parent sits in
 * another column is drawn flush-left (the list only indents a child under a
 * parent in the SAME group), and reordering it there must not take its parent
 * away — the user is not dragging it out of any indent they can see. Only the
 * stored parent is consulted to skip a write that would change nothing.
 *
 * Dropping onto the card's OWN parent row is never an un-nest either: the
 * pointer is still on the parent, and the slot right under a parent row is
 * where its first child goes — the pixel boundary it shares with the first
 * child row's top edge must not mean two opposite things.
 *
 * The server's rules are mirrored, so an offered change is never one that 400s:
 * no self-parent, and a card that has children of its own cannot become a child
 * (one level deep). The third server rule — the prospective parent must not
 * itself be a child — holds by construction here: `rowParentId` is only ever
 * taken from a row the list drew as top-level. A blocked adoption degrades to a
 * plain reorder rather than to an un-nest, never to a surprise.
 *
 * @param {object} sourceData drag payload (`source.data`) of the dragged card
 * @param {number|null|undefined} rowParentId parent of the row being dropped on
 *   (null = top-level row, undefined = this view has no hierarchy)
 * @param {?number} [rowCardId] the hovered row's own card id
 * @return {?{parentCardId: ?number, intent: 'unnest'|'reparent'}} null when the parent stays put
 */
export function reorderParentChange(sourceData, rowParentId, rowCardId = null) {
	if (rowParentId === undefined) return null
	if (!sourceData || sourceData.type !== 'card') return null
	const from = renderedParentOf(sourceData)
	const stored = sourceData.parentCardId ?? null
	const to = rowParentId ?? null
	if (from === null && to === null) return null
	if (from !== null && to !== null && Number(from) === Number(to)) return null
	if (to === null) {
		if (stored === null) return null // not a sub-card: nothing to take it out of
		// Still on its own parent's row — that is not "out".
		if (rowCardId != null && Number(rowCardId) === Number(stored)) return null
		return { parentCardId: null, intent: 'unnest' }
	}
	// Already stored under this parent (only the indent was missing) — no write.
	if (stored !== null && Number(stored) === Number(to)) return null
	// Becoming (or changing) a parent: same guards as canNestInto.
	if (Number(to) === Number(sourceData.cardId)) return null
	if (sourceData.hasChildren === true) return null
	return { parentCardId: Number(to), intent: 'reparent' }
}

/**
 * The identity fields a card carries as BOTH a drag source and a drop target.
 * Built here so the kanban tile and the list row cannot drift apart: the
 * BoardView monitor reads the same keys from either view.
 *
 * `rowParentId` is the level the row is DRAWN at, which is not always the stored
 * parent (a sub-card whose parent is in another column renders flush-left). Only
 * a view that draws levels passes it; everywhere else it stays undefined.
 *
 * @param {object} card the card summary
 * @param {?string} laneKey swimlane key ('' when swimlanes are off, null in list view)
 * @param {number|null|undefined} [rowParentId] the rendered level of this card's row
 * @return {object} the shared drag/drop payload
 */
export function buildCardDragData(card, laneKey, rowParentId = undefined) {
	return {
		type: 'card',
		cardId: card.id,
		stackId: card.stackId,
		sortKey: card.sortKey,
		laneKey,
		parentCardId: card.parentCardId ?? null,
		rowParentId,
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
 * @param {number|null|undefined} [args.rowParentId] the row's own level — see
 *   {@see reorderParentChange}. Omitted on a view that has no hierarchy.
 * @return {object} the data to return from `getData()`
 */
export function buildCardDropData({ base, input, element, source, nestEnabled = true, rowParentId }) {
	if (nestEnabled && canNestInto(source?.data, base) && isInNestBand(input, element)) {
		return { ...base, dropMode: 'nest', parentChange: null, landsNested: true }
	}
	// Changing a card's level by drag is gated on the same flag as nesting: only a
	// surface that owns the card monitor and is in manual sort can carry it out,
	// so nowhere else may show an affordance promising it.
	const parentChange = nestEnabled
		? reorderParentChange(source?.data, rowParentId, base?.cardId ?? null)
		: null
	return attachClosestEdge(
		{
			...base,
			dropMode: 'reorder',
			parentChange,
			// The level the card ACTUALLY lands at, so the drop indicator can be
			// drawn at the indent the card will take — including when an adoption
			// was refused and the drop quietly stays a plain top-level reorder.
			landsNested: parentChange
				? parentChange.parentCardId !== null
				: renderedParentOf(source?.data) !== null,
		},
		{ input, element, allowedEdges: ['top', 'bottom'] },
	)
}
