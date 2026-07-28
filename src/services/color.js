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
