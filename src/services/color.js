// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Colors are stored and transferred as bare 6-hex strings (the server's
 * ColorValidator rejects anything else, including a leading '#'). The '#'
 * exists only at the CSS boundary - always go through this helper when
 * binding a color into a style.
 */
export function cssColor(color) {
	return color ? '#' + color : null
}

/**
 * Black or white text that reads legibly on the given background colour,
 * chosen by WCAG relative luminance (sRGB → linear, threshold 0.179). Accepts
 * a bare or '#'-prefixed 3- or 6-hex string; falls back to black when empty.
 *
 * @param {string} hex background colour
 * @returns {string} '#000000' or '#ffffff' (or '#000' for an empty input)
 */
export function readableColor(hex) {
	if (!hex) return '#000'
	const raw = hex.replace('#', '')
	const [r, g, b] = raw.length === 3
		? [parseInt(raw[0] + raw[0], 16), parseInt(raw[1] + raw[1], 16), parseInt(raw[2] + raw[2], 16)]
		: [parseInt(raw.slice(0, 2), 16), parseInt(raw.slice(2, 4), 16), parseInt(raw.slice(4, 6), 16)]
	const toLinear = (c) => { const s = c / 255; return s <= 0.04045 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4) }
	const L = 0.2126 * toLinear(r) + 0.7152 * toLinear(g) + 0.0722 * toLinear(b)
	return L > 0.179 ? '#000000' : '#ffffff'
}

/**
 * The shared label colour palette (bare 6-hex). Used by every label creator
 * (Board settings and the inline card-modal creator) so the swatches stay
 * identical everywhere.
 */
export const LABEL_COLOR_PRESETS = [
	'e74c3c', 'e67e22', 'f1c40f', '2ecc71',
	'1abc9c', '3498db', '9b59b6', '34495e',
]
