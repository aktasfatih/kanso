// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { computed, inject } from 'vue'

/**
 * Injection key for "the viewer may EDIT this board" — PERMISSION_EDIT, bit 2 of
 * the board payload's `permissions` (see `OCA\Kanso\Service\PermissionService`).
 *
 * Provided by BoardView so deeply nested write affordances (a card tile's drag
 * handle, a list row's drag handle, a column's reorder handle) can be gated
 * without threading a prop through StackColumn / SwimlaneRow. The server already
 * refuses every one of those writes; this only stops the UI from offering a
 * gesture that can end in nothing but an error toast.
 *
 * Same shape as NEST_ENABLED (./cardNesting.js) and CARD_FEATURES
 * (./cardFeatures.js): a `ComputedRef<boolean>`.
 */
export const BOARD_CAN_EDIT = Symbol('kanso:boardCanEdit')

/**
 * Reads the provided edit flag inside a descendant of BoardView.
 *
 * The default is **true**, unlike NEST_ENABLED's false. Surfaces that render the
 * same components without a provider — a cross-board View's kanban columns
 * (ViewKanbanColumn.vue) and its list (ViewPage.vue) — carry rows from several
 * boards and do their own permission handling; defaulting to false there would
 * silently disable drag on a board the user can perfectly well edit. Gating is
 * therefore opt-in per surface: only a board that knows the viewer lacks EDIT
 * provides `false`.
 *
 * @return {import('vue').ComputedRef<boolean>} whether write affordances render
 */
export function useBoardCanEdit() {
	return inject(BOARD_CAN_EDIT, computed(() => true))
}
