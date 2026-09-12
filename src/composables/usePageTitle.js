// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { onScopeDispose, unref, watch } from 'vue'

/**
 * The browser tab title (#125).
 *
 * Nextcloud composes the initial `<title>` server-side in `layout.user.php` from
 * the app name and the instance name, so every Kanso route used to read the same
 * `Kanso - Nextcloud` — useless for a bookmark or for telling two pinned board
 * tabs apart. The router is a HASH router (`createWebHashHistory`), so no
 * navigation ever goes back to the server to re-render that title: it has to be
 * maintained client-side.
 *
 * Two deliberate choices:
 *
 * 1. **The suffix is captured, never spelled out.** `Kanso` is a translated app
 *    name and the instance name is themable (`$theme->getTitle()`), so a literal
 *    `'… - Kanso - Nextcloud'` would be wrong on any themed or non-English
 *    instance. Whatever Nextcloud already put in `document.title` becomes the
 *    base, and a prefix is pushed in front of it with core's own ` - `
 *    separator (Files renders `Photos - Files - Nextcloud`).
 *
 * 2. **It is read ONCE, at module load.** Reading it lazily inside the
 *    composable would capture an already-prefixed title on the second
 *    navigation and compound it (`Board B - Board A - Kanso - Nextcloud`).
 *    Module scope is evaluated before any view has had a chance to write.
 *
 * The titles themselves arrive asynchronously — a board/card/project/view title
 * comes from a TanStack query that is `undefined` at first paint and may never
 * resolve at all (a 403/404 disables the board query permanently). So this is a
 * `watch`, not a one-shot read at navigation time, and an empty title means
 * "fall through", never `undefined - Kanso - Nextcloud`.
 */

/** The server-rendered title, e.g. `Kanso - Nextcloud`. Captured once — see above. */
const BASE_TITLE = (typeof document !== 'undefined' && document.title) || 'Kanso'

/** Nextcloud core's title separator. */
const SEPARATOR = ' - '

/**
 * Active title claims, innermost LAST.
 *
 * A stack rather than a single value because the card route is NESTED: the
 * `card-modal` route renders inside `BoardView`, so both components are mounted
 * and both want the title. Whoever mounted last wins while it is mounted, and
 * removing it restores the one underneath — so closing a card returns the
 * board's title without `CardModal` and `BoardView` knowing about each other.
 * The same holds for the controlled card overlay a cross-board View opens on
 * top of itself.
 *
 * Read like a stack, but NOT released like one — see `release` below.
 *
 * @type {Array<{prefix: string}>}
 */
const claims = []

/**
 * Write the topmost non-empty claim to the document title.
 *
 * Claims with an empty prefix are skipped rather than honoured, which is what
 * makes a still-loading (or permanently failed) query harmless: the title falls
 * through to the next claim down, and to the base title if there is none.
 */
function render() {
	if (typeof document === 'undefined') return
	let prefix = ''
	for (let i = claims.length - 1; i >= 0; i--) {
		if (claims[i].prefix) {
			prefix = claims[i].prefix
			break
		}
	}
	document.title = prefix ? prefix + SEPARATOR + BASE_TITLE : BASE_TITLE
}

/**
 * Read a title source that may be a getter, a ref/computed, or a plain string.
 *
 * @param {Function|import('vue').Ref|string} source - the title source
 * @return {string} the normalized prefix ('' when there is nothing to show)
 */
function readPrefix(source) {
	const value = typeof source === 'function' ? source() : unref(source)
	if (value === null || value === undefined) return ''
	return String(value).trim()
}

/**
 * Claim the browser tab title for as long as the calling component is mounted.
 *
 * Call it once per view, from `setup`. The prefix tracks `source` reactively, so
 * a title that loads late (or gets renamed while the page is open) updates the
 * tab without any further wiring.
 *
 * @param {Function|import('vue').Ref|string} source - the page title: a getter, a ref/computed, or a static string. Empty/nullish means "no title of my own yet".
 */
export function usePageTitle(source) {
	const claim = { prefix: '' }
	claims.push(claim)

	let released = false
	const release = () => {
		if (released) return
		released = true
		// Splice by identity, NOT pop: the claims are pushed in mount order but
		// they are not released in reverse. Vue stops a component's effect scope
		// before unmounting its subtree, so BoardView's claim goes first and the
		// nested card modal's second — popping would drop the wrong one and leave
		// the tab showing a closed card.
		const index = claims.indexOf(claim)
		if (index !== -1) claims.splice(index, 1)
		render()
	}

	// Registered BEFORE the watch, so a source getter that throws on its first
	// (immediate) read still gets its claim cleaned up with the scope rather than
	// leaving a permanent entry on the stack.
	//
	// onScopeDispose rather than onUnmounted: it fires on component unmount just
	// the same, and additionally works when the composable is used inside a
	// standalone effect scope (as the unit test does).
	onScopeDispose(release)

	watch(
		() => readPrefix(source),
		(prefix) => {
			claim.prefix = prefix
			render()
		},
		{ immediate: true },
	)
}
