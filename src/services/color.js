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
 * The shared label colour palette (bare 6-hex). Used by every label creator
 * (Board settings and the inline card-modal creator) so the swatches stay
 * identical everywhere.
 */
export const LABEL_COLOR_PRESETS = [
	'e74c3c', 'e67e22', 'f1c40f', '2ecc71',
	'1abc9c', '3498db', '9b59b6', '34495e',
]
