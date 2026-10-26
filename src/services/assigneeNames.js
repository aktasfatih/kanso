// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { inject } from 'vue'

/**
 * Injection key for the uid → display-name map a surface provides so deeply
 * nested avatar stacks (AssigneeAvatars) can name their assignees without
 * threading a prop through the intermediate card components. Same shape as
 * BOARD_CAN_EDIT (./boardPermissions.js) and CARD_FEATURES (./cardFeatures.js).
 *
 * Provided by the cross-board View (ViewPage.vue) from the feed's
 * server-resolved participant vocabulary (#10801): the display name is resolved
 * SERVER-side (one directory lookup per uid), so naming an avatar costs no extra
 * request. A surface with no provider — a board — falls back to the bare uid,
 * exactly what it did before this key existed.
 */
export const ASSIGNEE_NAMES = Symbol('kanso:assigneeNames')

/**
 * Reads the provided uid → display-name map inside a descendant of a provider,
 * and returns a resolver that names a uid, falling back to the uid itself when
 * no name is known (a deleted account, or a surface that provides no names).
 *
 * Accepts either a `ComputedRef<Map>` (what a reactive provider hands over) or a
 * plain `Map`, so a provider can pass whichever is convenient.
 *
 * @return {(uid: string) => string}
 */
export function useAssigneeNames() {
	const names = inject(ASSIGNEE_NAMES, null)
	return (uid) => {
		const map = names?.value ?? names
		if (map instanceof Map && map.has(uid)) {
			return map.get(uid)
		}
		return uid
	}
}
