// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * motion - the app's reduced-motion preference, for scrolls that JS drives.
 *
 * App.vue carries an unscoped `@media (prefers-reduced-motion: reduce)` block
 * that sets `scroll-behavior: auto !important`, which is the app's motion
 * policy for everything CSS drives. It cannot reach a JS-initiated scroll:
 * per CSSOM-View, an explicit `behavior` option passed to `scrollIntoView()` or
 * `scrollTo()` OVERRIDES the `scroll-behavior` property, `!important` included.
 * So a call site that hardcodes `behavior: 'smooth'` animates regardless of the
 * preference. Those call sites ask this helper instead.
 */

/**
 * The scroll `behavior` to pass to `scrollIntoView()` / `scrollTo()`.
 *
 * Queried at CALL time, never cached at module load: the OS setting can change
 * while the app is open, and a cached answer would keep animating (or keep
 * refusing to) for the rest of the session. `matchMedia` can also be absent
 * (jsdom, old engines) or throw, in which case the animated default stands.
 *
 * @return {'auto'|'smooth'} 'auto' (instant) under reduced motion, else 'smooth'
 */
export function scrollBehavior() {
	try {
		return window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ? 'auto' : 'smooth'
	} catch (e) {
		return 'smooth'
	}
}
