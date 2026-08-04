// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Board backgrounds (#3528). A board's background is stored server-side as a
 * small CURATED preset KEY (the server's BackgroundValidator rejects anything
 * else) - never free-form CSS, so there is no CSS-injection surface. The key →
 * gradient mapping lives ONLY here on the client; the key set must stay in
 * lockstep with `lib/Service/BackgroundValidator::PRESETS`.
 */

/**
 * Ordered list of { key, label, css } presets. `css` is a `background` shorthand
 * value applied behind the board view. Colours are chosen soft enough that the
 * columns/cards (which sit on an opaque surface on top) stay readable.
 */
export const BACKGROUND_PRESETS = [
	{ key: 'sunset', label: 'Sunset', css: 'linear-gradient(135deg, #ff9a9e 0%, #fad0c4 100%)' },
	{ key: 'ocean', label: 'Ocean', css: 'linear-gradient(135deg, #a1c4fd 0%, #c2e9fb 100%)' },
	{ key: 'forest', label: 'Forest', css: 'linear-gradient(135deg, #96e6a1 0%, #d4fc79 100%)' },
	{ key: 'lavender', label: 'Lavender', css: 'linear-gradient(135deg, #a18cd1 0%, #fbc2eb 100%)' },
	{ key: 'peach', label: 'Peach', css: 'linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%)' },
	{ key: 'slate', label: 'Slate', css: 'linear-gradient(135deg, #607d8b 0%, #cfd8dc 100%)' },
	{ key: 'aurora', label: 'Aurora', css: 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)' },
	{ key: 'ember', label: 'Ember', css: 'linear-gradient(135deg, #f6d365 0%, #fda085 100%)' },
]

const PRESET_BY_KEY = Object.fromEntries(BACKGROUND_PRESETS.map((p) => [p.key, p]))

/**
 * Maps a stored background key to its CSS `background` value. Returns null for
 * an empty / unknown key (defence in depth - an unknown key never becomes CSS).
 *
 * @param {?string} key the stored preset key
 * @returns {?string} the CSS background value, or null
 */
export function backgroundCss(key) {
	if (!key) return null
	return PRESET_BY_KEY[key]?.css ?? null
}
