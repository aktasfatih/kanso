// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { translate as t } from '@nextcloud/l10n'

/**
 * Map from estimateScale name to its allowed token strings.
 * MUST mirror the backend exactly.
 */
const SCALE_TOKEN_MAP = {
	none: [],
	fibonacci: ['1', '2', '3', '5', '8', '13', '21'],
	tshirt: ['XS', 'S', 'M', 'L', 'XL'],
	linear: ['1', '2', '3', '4', '5'],
	hours: ['1', '2', '4', '8', '16', '24', '40'],
}

/**
 * Returns the array of allowed token strings for the given scale.
 * Returns [] for unknown or 'none' scales.
 *
 * @param {string} scale
 * @returns {string[]}
 */
export function scaleTokens(scale) {
	return SCALE_TOKEN_MAP[scale] ?? []
}

/**
 * Ordered list of scale options for the board settings selector.
 * Labels are deferred as functions so t() is called at render time (i18n ready).
 */
export function getScaleOptions() {
	return [
		{ value: 'none', label: t('kanso', 'None') },
		{ value: 'fibonacci', label: t('kanso', 'Fibonacci (1,2,3,5,8,13)') },
		{ value: 'tshirt', label: t('kanso', 'T-shirt (XS–XL)') },
		{ value: 'linear', label: t('kanso', 'Linear (1–5)') },
		{ value: 'hours', label: t('kanso', 'Hours') },
	]
}
