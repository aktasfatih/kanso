// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { translate as t } from '@nextcloud/l10n'

export const PROJECT_LIFECYCLE_OPTIONS = [
	{ value: 'planned', label: t('kanso', 'Planned') },
	{ value: 'in_progress', label: t('kanso', 'In progress') },
	{ value: 'done', label: t('kanso', 'Done') },
]

export function projectLifecycleLabel(status) {
	return PROJECT_LIFECYCLE_OPTIONS.find((option) => option.value === status)?.label
		?? PROJECT_LIFECYCLE_OPTIONS[0].label
}
