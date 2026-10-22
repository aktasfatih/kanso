// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { translate as t } from '@nextcloud/l10n'
import CheckCircleOutlineIcon from 'vue-material-design-icons/CheckCircleOutline.vue'
import AlertOutlineIcon from 'vue-material-design-icons/AlertOutline.vue'
import AlertOctagonOutlineIcon from 'vue-material-design-icons/AlertOctagonOutline.vue'
import ClockAlertOutlineIcon from 'vue-material-design-icons/ClockAlertOutline.vue'
import HelpCircleOutlineIcon from 'vue-material-design-icons/HelpCircleOutline.vue'

/**
 * Presentation of a project plan `status` (see lib/Service/ProjectForecast.php).
 *
 * @param {string} status
 * @return {{tone: string, icon: object, label: string, explain: string}}
 */
export function projectStatus(status) {
	switch (status) {
	case 'on_track':
		return { tone: 'success', icon: CheckCircleOutlineIcon, label: t('kanso', 'On track'), explain: t('kanso', 'Even your slower weeks would finish before the target date.') }
	case 'done':
		return { tone: 'success', icon: CheckCircleOutlineIcon, label: t('kanso', 'Done'), explain: t('kanso', 'Every card in this project is done.') }
	case 'at_risk':
		return { tone: 'warning', icon: AlertOutlineIcon, label: t('kanso', 'At risk'), explain: t('kanso', 'Your slower weeks would miss the target date.') }
	case 'off_track':
		return { tone: 'error', icon: AlertOctagonOutlineIcon, label: t('kanso', 'Off track'), explain: t('kanso', 'Even your best weeks would miss the target date.') }
	case 'late':
		return { tone: 'error', icon: ClockAlertOutlineIcon, label: t('kanso', 'Late'), explain: t('kanso', 'The target date has passed and work is still open.') }
	case 'no_target':
		return { tone: 'neutral', icon: HelpCircleOutlineIcon, label: t('kanso', 'No target date'), explain: t('kanso', 'Set a target date to see whether you are on track.') }
	default:
		return { tone: 'neutral', icon: HelpCircleOutlineIcon, label: t('kanso', 'Not enough data yet'), explain: t('kanso', 'Finish a couple of cards to get a forecast.') }
	}
}

/**
 * @param {string|null} date YYYY-MM-DD
 * @param {boolean} withYear
 * @return {string}
 */
export function formatPlanDate(date, withYear = true) {
	if (!date) return ''
	return new Date(date + 'T00:00:00Z').toLocaleDateString(undefined, {
		month: 'short',
		day: 'numeric',
		...(withYear ? { year: 'numeric' } : {}),
		timeZone: 'UTC',
	})
}
