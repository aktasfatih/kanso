// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Extraction-only manifest — NOT imported anywhere.
 *
 * Some UI strings are translated at render time via `t('kanso', someVar)` where
 * the argument is a variable (built-in card types, priority levels, board-filter
 * facet labels, swimlane group titles). Because the English source isn't a
 * literal at the call site, the string extractor (scripts/l10n.mjs) can't see it
 * there. Listing the literals here — as real translate calls with a literal
 * string — puts them in the catalog so they get translated like everything else.
 *
 * This module is intentionally never imported, so it is tree-shaken out of the
 * build; it exists solely to be scanned by `npm run l10n:extract`. Keep it in
 * sync with the enum definitions it mirrors (referenced in each comment).
 */

import { translate as t } from '@nextcloud/l10n'

// Built-in card types — src/composables/useCardType.js (CARD_TYPES)
t('kanso', 'Bug')
t('kanso', 'Feature')
t('kanso', 'Task')
t('kanso', 'Chore')

// Priority levels — src/composables/usePriority.js (PRIORITY_LEVELS: label + shortLabel)
t('kanso', 'None')
t('kanso', 'Low')
t('kanso', 'Medium')
t('kanso', 'High')
t('kanso', 'Urgent')
t('kanso', 'Med') // shortLabel for Medium, rendered raw on card tiles

// Board-filter facet labels — src/composables/useBoardFilters.js
t('kanso', 'Overdue')
t('kanso', 'Due this week')
t('kanso', 'No due date')
t('kanso', 'Not done')
t('kanso', 'Done')
t('kanso', 'Waiting on client')
t('kanso', 'Not waiting')
t('kanso', 'Blocked')
t('kanso', 'Not blocked')
t('kanso', 'Has checklist')
t('kanso', 'Checklist incomplete')
t('kanso', 'Checklist complete')
t('kanso', 'No checklist')
t('kanso', 'Started')
t('kanso', 'Starts later')
t('kanso', 'No start date')
t('kanso', 'Top-level')
t('kanso', 'Parent card')
t('kanso', 'Sub-card')
t('kanso', 'Has comments')
t('kanso', 'No comments')

// Review-state facet labels — src/components/BoardFilterBar.vue + src/composables/useSwimlanes.js
t('kanso', 'Needs review')
t('kanso', 'Approved')
t('kanso', 'Changes requested')
t('kanso', 'No review')

// Swimlane status group titles — src/composables/useSwimlanes.js
t('kanso', 'In progress')
t('kanso', 'To do')
