// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { translate as t } from '@nextcloud/l10n'

/**
 * Preset scale labels, mirroring `OCA\Kanso\Db\EstimateScale::PRESETS`. Only
 * used to preview a scale switch before it is saved; a board's own scale
 * (custom labels included) comes from `board.estimateTokens`.
 */
const PRESET_TOKENS = {
	fibonacci: ['1', '2', '3', '5', '8', '13', '21'],
	fibonacci_mod: ['0', '0.5', '1', '2', '3', '5', '8', '13', '20', '40', '100'],
	powers2: ['1', '2', '4', '8', '16', '32'],
	tshirt: ['XS', 'S', 'M', 'L', 'XL'],
	linear: ['1', '2', '3', '4', '5'],
	roman: ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'],
	hours: ['1', '2', '4', '8', '16', '24', '40'],
	days: ['0.5', '1', '2', '3', '5', '10'],
}

const NUMBER_PATTERN = /^\d{1,6}(\.\d{1,2})?$/

/**
 * @param {string} scale
 * @return {string[]}
 */
export function scaleTokens(scale) {
	return PRESET_TOKENS[scale] ?? []
}

/**
 * @param {string} value
 * @return {boolean}
 */
export function isValidNumberEstimate(value) {
	return NUMBER_PATTERN.test(value) && Number(value) <= 100000
}

/**
 * The labels a card on this board may pick, in order. Empty for `none` and
 * for the free-form `number` scale.
 *
 * @param {object|null|undefined} board the board payload
 * @return {string[]}
 */
export function boardEstimateLabels(board) {
	return (board?.estimateTokens ?? []).map((token) => token.label)
}

/**
 * The numeric value of an estimate on this board, or null when it isn't on
 * the board's scale.
 *
 * @param {object|null|undefined} board the board payload
 * @param {string|null|undefined} estimate
 * @return {number|null}
 */
export function boardEstimateValue(board, estimate) {
	if (!estimate) return null
	if (board?.estimateScale === 'number') {
		return isValidNumberEstimate(estimate) ? Number(estimate) : null
	}
	return (board?.estimateTokens ?? []).find((token) => token.label === estimate)?.value ?? null
}

/**
 * @param {string|null|undefined} estimate
 * @return {string}
 */
export function displayEstimate(estimate) {
	return estimate === '0.5' ? '½' : (estimate ?? '')
}

export function getScaleOptions() {
	return [
		{ value: 'none', label: t('kanso', 'None') },
		{ value: 'fibonacci', label: t('kanso', 'Fibonacci (1,2,3,5,8,13)') },
		{ value: 'fibonacci_mod', label: t('kanso', 'Planning poker (0, ½, 1, 2, 3, 5 … 100)') },
		{ value: 'powers2', label: t('kanso', 'Powers of 2 (1, 2, 4, 8, 16, 32)') },
		{ value: 'tshirt', label: t('kanso', 'T-shirt (XS–XL)') },
		{ value: 'linear', label: t('kanso', 'Linear (1–5)') },
		{ value: 'roman', label: t('kanso', 'Roman numerals (I–X)') },
		{ value: 'hours', label: t('kanso', 'Hours') },
		{ value: 'days', label: t('kanso', 'Days (½–10)') },
		{ value: 'number', label: t('kanso', 'Any number') },
		{ value: 'custom', label: t('kanso', 'Custom…') },
	]
}
