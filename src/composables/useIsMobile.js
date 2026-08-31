// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { readonly, ref } from 'vue'

/**
 * Single source of truth for the app's responsive breakpoints.
 *
 * Before this, the only viewport-aware code was a pair of ad-hoc
 * `matchMedia('(max-width: 680px)')` checks buried in CardDetail.vue. The mobile
 * (PWA) work needs one shared, reactive breakpoint state so nav, board, card and
 * every feature surface flip together — and so we register exactly one listener
 * per query instead of one per component.
 *
 * Breakpoints (max-width, inclusive):
 *  - phone  ≤ 680px  — single-column layout, drawer nav, full-screen sheets.
 *  - tablet ≤ 1024px — narrow layout, but roomier than a phone.
 *
 * 680 matches CardDetail's existing sheet breakpoint so the two never disagree.
 */
export const PHONE_MAX_PX = 680
export const TABLET_MAX_PX = 1024

// Module-level singletons: the matchMedia lists and their reactive mirrors are
// created once and shared by every caller (same pattern as useMyWorkBadges'
// module-level `inboxSeenAt`). Guarded for non-browser/SSR-less environments and
// older engines without matchMedia.
const hasMatchMedia = typeof window !== 'undefined' && typeof window.matchMedia === 'function'

const phoneQuery = hasMatchMedia ? window.matchMedia(`(max-width: ${PHONE_MAX_PX}px)`) : null
const tabletQuery = hasMatchMedia ? window.matchMedia(`(max-width: ${TABLET_MAX_PX}px)`) : null

const isPhone = ref(phoneQuery ? phoneQuery.matches : false)
const isTablet = ref(tabletQuery ? tabletQuery.matches : false)

function bind(query, target) {
	if (!query) return
	const handler = (e) => { target.value = e.matches }
	// addEventListener is the modern API; addListener is the pre-Safari-14
	// fallback. Both fire on the same MediaQueryListEvent shape.
	if (typeof query.addEventListener === 'function') {
		query.addEventListener('change', handler)
	} else if (typeof query.addListener === 'function') {
		query.addListener(handler)
	}
}

bind(phoneQuery, isPhone)
bind(tabletQuery, isTablet)

/**
 * Reactive viewport-class flags shared across the whole app.
 *
 * @return {{ isPhone: import('vue').DeepReadonly<import('vue').Ref<boolean>>,
 *            isTablet: import('vue').DeepReadonly<import('vue').Ref<boolean>>,
 *            isCompact: import('vue').DeepReadonly<import('vue').Ref<boolean>> }}
 *   isPhone: viewport ≤ 680px. isTablet: viewport ≤ 1024px (true on phones too).
 *   isCompact: alias of isTablet — "not a desktop layout".
 */
export function useIsMobile() {
	return {
		isPhone: readonly(isPhone),
		isTablet: readonly(isTablet),
		isCompact: readonly(isTablet),
	}
}
