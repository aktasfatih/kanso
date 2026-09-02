<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="board-filter-bar">
		<!-- ── Progressive drill-in filter (#3785, Phase 2) ───────────────────────
		     One popover whose panel swaps between (a) the ROOT — a list of the
		     filter dimensions with an active-value summary + count badge, plus the
		     Saved-views section folded in — and (b) a single dimension's values with
		     a back header. @nextcloud/vue's NcActions can't nest submenus, so this is
		     a small custom NcPopover: the trigger is the header Filter button, the
		     panel content is driven by `activeDim`. All the underlying filter
		     state/predicate/URL/saved-views logic is unchanged (useBoardFilters). -->
		<NcPopover
			class="board-filter-bar__filter"
			:shown="open"
			:focus-trap="false"
			no-auto-focus
			@update:shown="onShownChange">
			<template #trigger="{ attrs }">
				<NcButton
					v-bind="attrs"
					class="board-filter-bar__trigger"
					:type="count > 0 ? 'primary' : 'tertiary'"
					:aria-label="triggerLabel">
					<template #icon>
						<FilterVariantIcon :size="20" />
					</template>
					<template v-if="!iconOnly">{{ triggerLabel }}</template>
				</NcButton>
			</template>

			<div class="board-filter-bar__panel">
				<!-- ── ROOT: dimension list + saved views ───────────────────────── -->
				<template v-if="!activeDim">
					<ul class="board-filter-bar__dims" role="menu">
						<li v-for="dim in visibleDimensions" :key="dim.key" role="none">
							<button
								type="button"
								role="menuitem"
								class="board-filter-bar__dim-row"
								:class="`board-filter-bar__dim-row--${dim.key}`"
								:data-dim="dim.key"
								@click="activeDim = dim.key">
								<span class="board-filter-bar__dim-name">{{ dim.label }}</span>
								<span
									v-if="dim.summary"
									class="board-filter-bar__dim-summary"
									:title="dim.summary">{{ dim.summary }}</span>
								<span
									v-if="dim.count > 0"
									class="board-filter-bar__dim-badge">{{ dim.count }}</span>
								<ChevronRightIcon class="board-filter-bar__dim-chevron" :size="20" />
							</button>
						</li>
					</ul>

					<!-- Clear all — gated on the dimensions this control actually
					     RENDERS, not on `count`. `count` deliberately includes hidden
					     dimensions (under-reporting an active constraint is the worse
					     failure), but a Clear button that clears nothing visible is a
					     dead affordance, so it only appears when there is something on
					     screen to clear. -->
					<template v-if="visibleCount > 0">
						<div class="board-filter-bar__sep" role="separator" />
						<button
							type="button"
							class="board-filter-bar__action board-filter-bar__clear"
							@click="clearAll">
							<FilterVariantRemoveIcon :size="20" />
							<span>{{ t('kanso', 'Clear filters') }}</span>
						</button>
					</template>

					<!-- ── Saved views (folded in from the old Saved dropdown) ────
					     Only when this surface actually has saved views to offer. A
					     surface that passes none (the View page) would otherwise render
					     an empty "Views" section whose one entry is a second, unlabelled
					     clear-all button. -->
					<template v-if="savedFilters.length">
						<div class="board-filter-bar__sep" role="separator" />
						<p class="board-filter-bar__caption">{{ t('kanso', 'Views') }}</p>
						<button
							type="button"
							class="board-filter-bar__action board-filter-bar__saved-item"
							:class="{ 'board-filter-bar__saved-item--active': !activeSavedName && count === 0 }"
							@click="clearAll">
							<CheckIcon v-if="!activeSavedName && count === 0" :size="20" />
							<FilterVariantRemoveIcon v-else :size="20" />
							<span>{{ t('kanso', 'Default (no filter)') }}</span>
						</button>

						<button
							v-for="view in savedFilters"
							:key="'v-' + view.name"
							type="button"
							class="board-filter-bar__action board-filter-bar__saved-item"
							:class="{ 'board-filter-bar__saved-item--active': view.name === activeSavedName }"
							@click="$emit('apply-saved', view)">
							<CheckIcon v-if="view.name === activeSavedName" :size="20" />
							<BookmarkOutlineIcon v-else :size="20" />
							<span>{{ view.name }}</span>
						</button>
						<button
							v-if="activeSavedName"
							type="button"
							class="board-filter-bar__action board-filter-bar__saved-delete"
							@click="$emit('delete-saved', activeSavedName)">
							<DeleteOutlineIcon :size="20" />
							<span>{{ t('kanso', 'Delete "{name}"', { name: activeSavedName }) }}</span>
						</button>
					</template>

					<!-- Save-as: type a name, Enter (or the confirm arrow) saves the
					     current filter. Disabled with no active filter. -->
					<div class="board-filter-bar__sep" role="separator" />
					<p class="board-filter-bar__caption">{{ t('kanso', 'Save current filter') }}</p>
					<div class="board-filter-bar__save-row">
						<NcTextField
							v-model="saveName"
							class="board-filter-bar__save-input"
							:disabled="count === 0"
							:label="t('kanso', 'View name')"
							:placeholder="t('kanso', 'View name')"
							:label-outside="true"
							@keydown.enter.prevent="submitSave" />
						<NcButton
							type="tertiary"
							:disabled="count === 0 || !saveName.trim()"
							:aria-label="t('kanso', 'Save filter')"
							@click="submitSave">
							<template #icon>
								<ContentSaveOutlineIcon :size="20" />
							</template>
						</NcButton>
					</div>
					<p v-if="saveError" class="board-filter-bar__save-error">{{ saveError }}</p>
				</template>

				<!-- ── DIMENSION drill-in: back header + only this dimension's values -->
				<template v-else>
					<div class="board-filter-bar__drill-header">
						<NcButton
							type="tertiary"
							class="board-filter-bar__back"
							:aria-label="t('kanso', 'Back to filters')"
							@click="activeDim = null">
							<template #icon>
								<ArrowLeftIcon :size="20" />
							</template>
						</NcButton>
						<span class="board-filter-bar__drill-title">{{ activeDimMeta.label }}</span>
					</div>

					<!-- Labels (OR within) -->
					<ul v-if="activeDim === 'labels'" class="board-filter-bar__opts" role="menu">
						<li v-for="label in labels" :key="'l-' + label.id" role="none">
							<button
								type="button"
								role="menuitemcheckbox"
								:aria-checked="state.labels.has(label.id) ? 'true' : 'false'"
								class="board-filter-bar__opt board-filter-bar__label-item"
								:style="{ '--filter-dot-color': label.color ? '#' + label.color : 'var(--color-border)' }"
								@click="toggleSet('labels', label.id)">
								<span class="board-filter-bar__opt-dot" />
								<span class="board-filter-bar__opt-text">{{ label.title }}</span>
								<CheckIcon v-if="state.labels.has(label.id)" class="board-filter-bar__opt-check" :size="20" />
							</button>
						</li>
					</ul>

					<!-- Assignees (OR within) + Unassigned sentinel -->
					<ul v-else-if="activeDim === 'assignees'" class="board-filter-bar__opts" role="menu">
						<li role="none">
							<button
								type="button"
								role="menuitemcheckbox"
								:aria-checked="state.assignees.has(UNASSIGNED) ? 'true' : 'false'"
								class="board-filter-bar__opt"
								@click="toggleSet('assignees', UNASSIGNED)">
								<span class="board-filter-bar__opt-text">{{ t('kanso', 'Unassigned') }}</span>
								<CheckIcon v-if="state.assignees.has(UNASSIGNED)" class="board-filter-bar__opt-check" :size="20" />
							</button>
						</li>
						<li v-for="p in participants" :key="'a-' + p.uid" role="none">
							<button
								type="button"
								role="menuitemcheckbox"
								:aria-checked="state.assignees.has(p.uid) ? 'true' : 'false'"
								class="board-filter-bar__opt"
								@click="toggleSet('assignees', p.uid)">
								<span class="board-filter-bar__opt-text">{{ p.displayName || p.uid }}</span>
								<CheckIcon v-if="state.assignees.has(p.uid)" class="board-filter-bar__opt-check" :size="20" />
							</button>
						</li>
					</ul>

					<!-- Priority (OR within) -->
					<ul v-else-if="activeDim === 'priorities'" class="board-filter-bar__opts" role="menu">
						<li v-for="level in priorityLevels" :key="'p-' + level.value" role="none">
							<button
								type="button"
								role="menuitemcheckbox"
								:aria-checked="state.priorities.has(level.value) ? 'true' : 'false'"
								class="board-filter-bar__opt board-filter-bar__priority-item"
								:class="`board-filter-bar__priority-item--${level.value}`"
								@click="toggleSet('priorities', level.value)">
								<span class="board-filter-bar__opt-dot" />
								<span class="board-filter-bar__opt-text">{{ level.label }}</span>
								<CheckIcon v-if="state.priorities.has(level.value)" class="board-filter-bar__opt-check" :size="20" />
							</button>
						</li>
					</ul>

					<!-- Type (OR within) - built-in card types (#3402) -->
					<ul v-else-if="activeDim === 'types'" class="board-filter-bar__opts" role="menu">
						<li v-for="tp in cardTypes" :key="'t-' + tp.value" role="none">
							<button
								type="button"
								role="menuitemcheckbox"
								:aria-checked="state.types.has(tp.value) ? 'true' : 'false'"
								class="board-filter-bar__opt board-filter-bar__type-item"
								:class="`board-filter-bar__type-item--${tp.value}`"
								@click="toggleSet('types', tp.value)">
								<span class="board-filter-bar__opt-dot" />
								<span class="board-filter-bar__opt-text">{{ tp.label }}</span>
								<CheckIcon v-if="state.types.has(tp.value)" class="board-filter-bar__opt-check" :size="20" />
							</button>
						</li>
					</ul>

					<!-- Estimate (OR within) - scale tokens + Unestimated sentinel -->
					<ul v-else-if="activeDim === 'estimates'" class="board-filter-bar__opts" role="menu">
						<li role="none">
							<button
								type="button"
								role="menuitemcheckbox"
								:aria-checked="state.estimates.has(UNESTIMATED) ? 'true' : 'false'"
								class="board-filter-bar__opt"
								@click="toggleSet('estimates', UNESTIMATED)">
								<span class="board-filter-bar__opt-text">{{ t('kanso', 'Unestimated') }}</span>
								<CheckIcon v-if="state.estimates.has(UNESTIMATED)" class="board-filter-bar__opt-check" :size="20" />
							</button>
						</li>
						<li v-for="token in estimateTokens" :key="'e-' + token" role="none">
							<button
								type="button"
								role="menuitemcheckbox"
								:aria-checked="state.estimates.has(token) ? 'true' : 'false'"
								class="board-filter-bar__opt"
								@click="toggleSet('estimates', token)">
								<span class="board-filter-bar__opt-text">{{ token }}</span>
								<CheckIcon v-if="state.estimates.has(token)" class="board-filter-bar__opt-check" :size="20" />
							</button>
						</li>
					</ul>

					<!-- Owners (OR within) - reuses the participants list (#3815). No
					     "unassigned" sentinel: owner is always set. -->
					<ul v-else-if="activeDim === 'owners'" class="board-filter-bar__opts" role="menu">
						<li v-for="p in participants" :key="'o-' + p.uid" role="none">
							<button
								type="button"
								role="menuitemcheckbox"
								:aria-checked="state.owners.has(p.uid) ? 'true' : 'false'"
								class="board-filter-bar__opt"
								@click="toggleSet('owners', p.uid)">
								<span class="board-filter-bar__opt-text">{{ p.displayName || p.uid }}</span>
								<CheckIcon v-if="state.owners.has(p.uid)" class="board-filter-bar__opt-check" :size="20" />
							</button>
						</li>
					</ul>

					<!-- Review state (OR within) - pending / approved / changes /
					     no-review sentinel. -->
					<ul v-else-if="activeDim === 'reviews'" class="board-filter-bar__opts" role="menu">
						<li v-for="rv in reviewOptions" :key="'r-' + rv.value" role="none">
							<button
								type="button"
								role="menuitemcheckbox"
								:aria-checked="state.reviews.has(rv.value) ? 'true' : 'false'"
								class="board-filter-bar__opt"
								@click="toggleSet('reviews', rv.value)">
								<span class="board-filter-bar__opt-text">{{ rv.label }}</span>
								<CheckIcon v-if="state.reviews.has(rv.value)" class="board-filter-bar__opt-check" :size="20" />
							</button>
						</li>
					</ul>

					<!-- Single-select dimensions (Due / Status / Client status /
					     Blocked / Checklist / Start date / Sub-card / Comments): a
					     radio group with an explicit "Any" (value '') clear. -->
					<ul v-else class="board-filter-bar__opts" role="menu">
						<li role="none">
							<button
								type="button"
								role="menuitemradio"
								:aria-checked="!currentSingleValue ? 'true' : 'false'"
								class="board-filter-bar__opt"
								@click="setSingleRadio(activeDim, '')">
								<span class="board-filter-bar__opt-text">{{ t('kanso', 'Any') }}</span>
								<CheckIcon v-if="!currentSingleValue" class="board-filter-bar__opt-check" :size="20" />
							</button>
						</li>
						<li v-for="opt in activeDimMeta.options" :key="'o-' + opt.value" role="none">
							<button
								type="button"
								role="menuitemradio"
								:aria-checked="currentSingleValue === opt.value ? 'true' : 'false'"
								class="board-filter-bar__opt"
								@click="setSingleRadio(activeDim, opt.value)">
								<span class="board-filter-bar__opt-text">{{ opt.label }}</span>
								<CheckIcon v-if="currentSingleValue === opt.value" class="board-filter-bar__opt-check" :size="20" />
							</button>
						</li>
					</ul>
				</template>
			</div>
		</NcPopover>
	</div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import FilterVariantIcon from 'vue-material-design-icons/FilterVariant.vue'
import FilterVariantRemoveIcon from 'vue-material-design-icons/FilterVariantRemove.vue'
import BookmarkOutlineIcon from 'vue-material-design-icons/BookmarkOutline.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import DeleteOutlineIcon from 'vue-material-design-icons/DeleteOutline.vue'
import ContentSaveOutlineIcon from 'vue-material-design-icons/ContentSaveOutline.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import { PRIORITY_LEVELS } from '../composables/usePriority.js'
import { CARD_TYPES } from '../composables/useCardType.js'
import { scaleTokens } from '../services/estimateScales.js'
import {
	UNASSIGNED,
	UNESTIMATED,
	REVIEW_NONE,
	DUE_OPTIONS,
	DONE_OPTIONS,
	WAITING_OPTIONS,
	BLOCKED_OPTIONS,
	CHECKLIST_OPTIONS,
	START_OPTIONS,
	SUBCARD_OPTIONS,
	COMMENTS_OPTIONS,
	ARCHIVED_OPTIONS,
	useFilterCount,
} from '../composables/useBoardFilters.js'

const props = defineProps({
	/** The reactive filter state (from createFilterState). */
	state: { type: Object, required: true },
	/** Board labels [{ id, title, color }]. */
	labels: { type: Array, default: () => [] },
	/** Board participants [{ uid, displayName }]. */
	participants: { type: Array, default: () => [] },
	/** Saved views [{ name, filter }]. */
	savedFilters: { type: Array, default: () => [] },
	/** Name of the saved view the current filter equals, or '' if none. */
	activeSavedName: { type: String, default: '' },
	/** The board's estimation scale key (e.g. 'fibonacci'); 'none' hides the facet. */
	estimateScale: { type: String, default: 'none' },
	/** Icon-only trigger (narrow header) — the label text is dropped, the menu is
	    identical. */
	iconOnly: { type: Boolean, default: false },
	/** Offer the `archived` opt-in facet. Off by default because a board hides
	    archived cards unconditionally (BoardView drops them before the predicate
	    ever runs), so the facet would be a control that does nothing there. The
	    View surface, which owns its own archived baseline, opts in. */
	showArchived: { type: Boolean, default: false },
})

const emit = defineEmits(['save', 'apply-saved', 'delete-saved'])

// Priority levels except "None" (0) — 0 is expressed by NOT selecting any.
const priorityLevels = computed(() => PRIORITY_LEVELS.filter((l) => l.value > 0))

// Built-in card types facet (#3402). "None" = leaving the dimension unfiltered.
const cardTypes = CARD_TYPES

// Estimate facet tokens for the board's scale (empty ⇒ facet hidden).
const estimateTokens = computed(() => scaleTokens(props.estimateScale))

// Review-state facet options (multi-select), fixed order. The 'none' sentinel
// (REVIEW_NONE) matches cards with no review requested.
const reviewOptions = [
	{ value: 'pending', label: t('kanso', 'Needs review') },
	{ value: 'approved', label: t('kanso', 'Approved') },
	{ value: 'changes_requested', label: t('kanso', 'Changes requested') },
	{ value: REVIEW_NONE, label: t('kanso', 'No review') },
]

const count = useFilterCount(props.state)

// Popover open + which dimension is drilled into (null = root list).
const open = ref(false)
const activeDim = ref(null)

const saveName = ref('')
const saveError = ref('')

const triggerLabel = computed(() =>
	count.value > 0
		? t('kanso', 'Filter · {count}', { count: count.value })
		: t('kanso', 'Filter'),
)

// Summary text for a multi-select (Set) dimension: joined labels, truncated.
function setSummary(set, resolveLabel) {
	if (!set.size) return ''
	const parts = [...set].map(resolveLabel).filter(Boolean)
	if (parts.length <= 2) return parts.join(', ')
	return t('kanso', '{first}, +{n} more', { first: parts.slice(0, 2).join(', '), n: parts.length - 2 })
}

const labelTitleById = computed(() => {
	const m = new Map()
	for (const l of props.labels) m.set(l.id, l.title)
	return m
})
const participantNameByUid = computed(() => {
	const m = new Map()
	for (const p of props.participants) m.set(p.uid, p.displayName || p.uid)
	return m
})

function singleSummary(value, options) {
	if (!value) return ''
	const opt = options.find((o) => o.value === value)
	return opt ? opt.label : ''
}

// The dimension metadata driving both the root list and each drill-in panel.
// `count`/`summary` are computed live off the state. `options` is only set for
// the single-select radio dimensions (due/done/waiting).
const dimensions = computed(() => {
	const s = props.state
	return [
		{
			key: 'labels',
			label: t('kanso', 'Labels'),
			show: props.labels.length > 0,
			count: s.labels.size,
			summary: setSummary(s.labels, (id) => labelTitleById.value.get(id)),
		},
		{
			key: 'assignees',
			label: t('kanso', 'Assignees'),
			show: props.participants.length > 0,
			count: s.assignees.size,
			summary: setSummary(s.assignees, (uid) =>
				uid === UNASSIGNED ? t('kanso', 'Unassigned') : participantNameByUid.value.get(uid) || uid),
		},
		{
			key: 'priorities',
			label: t('kanso', 'Priority'),
			show: true,
			count: s.priorities.size,
			summary: setSummary(s.priorities, (v) => {
				const lvl = PRIORITY_LEVELS.find((l) => l.value === v)
				return lvl ? lvl.label : ''
			}),
		},
		{
			key: 'types',
			label: t('kanso', 'Type'),
			show: true,
			count: s.types.size,
			summary: setSummary(s.types, (v) => {
				const tp = CARD_TYPES.find((c) => c.value === v)
				return tp ? tp.label : ''
			}),
		},
		{
			key: 'estimates',
			label: t('kanso', 'Estimate'),
			show: estimateTokens.value.length > 0,
			count: s.estimates.size,
			summary: setSummary(s.estimates, (tok) => tok === UNESTIMATED ? t('kanso', 'Unestimated') : tok),
		},
		{
			key: 'owners',
			label: t('kanso', 'Owner'),
			show: props.participants.length > 0,
			count: s.owners.size,
			summary: setSummary(s.owners, (uid) => participantNameByUid.value.get(uid) || uid),
		},
		{
			key: 'reviews',
			label: t('kanso', 'Review'),
			show: true,
			count: s.reviews.size,
			summary: setSummary(s.reviews, (v) => {
				const rv = reviewOptions.find((o) => o.value === v)
				return rv ? rv.label : ''
			}),
		},
		{
			key: 'due',
			label: t('kanso', 'Due date'),
			show: true,
			options: DUE_OPTIONS,
			count: s.due ? 1 : 0,
			summary: singleSummary(s.due, DUE_OPTIONS),
		},
		{
			key: 'done',
			label: t('kanso', 'Status'),
			show: true,
			options: DONE_OPTIONS,
			count: s.done ? 1 : 0,
			summary: singleSummary(s.done, DONE_OPTIONS),
		},
		{
			key: 'waiting',
			label: t('kanso', 'Client status'),
			show: true,
			options: WAITING_OPTIONS,
			count: s.waiting ? 1 : 0,
			summary: singleSummary(s.waiting, WAITING_OPTIONS),
		},
		{
			key: 'blocked',
			label: t('kanso', 'Blocked'),
			show: true,
			options: BLOCKED_OPTIONS,
			count: s.blocked ? 1 : 0,
			summary: singleSummary(s.blocked, BLOCKED_OPTIONS),
		},
		{
			key: 'checklist',
			label: t('kanso', 'Checklist'),
			show: true,
			options: CHECKLIST_OPTIONS,
			count: s.checklist ? 1 : 0,
			summary: singleSummary(s.checklist, CHECKLIST_OPTIONS),
		},
		{
			key: 'startDate',
			label: t('kanso', 'Start date'),
			show: true,
			options: START_OPTIONS,
			count: s.startDate ? 1 : 0,
			summary: singleSummary(s.startDate, START_OPTIONS),
		},
		{
			key: 'subcard',
			label: t('kanso', 'Sub-cards'),
			show: true,
			options: SUBCARD_OPTIONS,
			count: s.subcard ? 1 : 0,
			summary: singleSummary(s.subcard, SUBCARD_OPTIONS),
		},
		{
			key: 'comments',
			label: t('kanso', 'Comments'),
			show: true,
			options: COMMENTS_OPTIONS,
			count: s.comments ? 1 : 0,
			summary: singleSummary(s.comments, COMMENTS_OPTIONS),
		},
		{
			key: 'archived',
			label: t('kanso', 'Archived'),
			show: props.showArchived,
			options: ARCHIVED_OPTIONS,
			count: s.archived ? 1 : 0,
			summary: singleSummary(s.archived, ARCHIVED_OPTIONS),
		},
	]
})

const visibleDimensions = computed(() => dimensions.value.filter((d) => d.show))

// Active constraints among the dimensions this control RENDERS. Distinct from
// `count` (which spans every dimension, hidden ones included) and used only to
// gate the Clear button — see the template.
const visibleCount = computed(() =>
	visibleDimensions.value.reduce((n, d) => n + d.count, 0))

const activeDimMeta = computed(() =>
	dimensions.value.find((d) => d.key === activeDim.value) ?? {})

// The current value of the drilled-in single-select dimension. All single-select
// dimensions store their value directly under a state key matching activeDim.
const SINGLE_SELECT_DIMS = ['due', 'done', 'waiting', 'blocked', 'checklist', 'startDate', 'subcard', 'comments', 'archived']
const currentSingleValue = computed(() =>
	SINGLE_SELECT_DIMS.includes(activeDim.value) ? props.state[activeDim.value] : null,
)

function toggleSet(dim, value) {
	const set = props.state[dim]
	if (set.has(value)) set.delete(value)
	else set.add(value)
}

// Single-select radio dimensions (due / done / waiting). '' → null (empty state).
function setSingleRadio(dim, value) {
	props.state[dim] = value || null
}

// Clear every dimension this control RENDERS — and only those. The invariant is
// that a control may only clear what it shows (#10091): a surface can hide a
// dimension while still holding a constraint on it, and wiping that invisibly is
// destructive with no way back. The View page is the case that bites — it hides
// the Labels facet on purpose (board-scoped label ids collide across boards) yet
// seeds the View's own saved label filter into this same state, so a blanket
// clear silently destroyed the View's identity and widened the page to the whole
// unfiltered cross-board feed, recoverable only by reloading. Driving off
// `visibleDimensions` also keeps this in step with the dimension list instead of
// being a hand-maintained parallel copy of it.
function clearAll() {
	for (const dim of visibleDimensions.value) {
		if (SINGLE_SELECT_DIMS.includes(dim.key)) props.state[dim.key] = null
		else props.state[dim.key].clear()
	}
}

function submitSave() {
	const name = saveName.value.trim()
	if (!name) {
		saveError.value = t('kanso', 'Enter a name.')
		return
	}
	if (count.value === 0) return
	saveError.value = ''
	emit('save', name)
	saveName.value = ''
}

function onShownChange(shown) {
	open.value = shown
	if (!shown) resetToRoot()
}

// Always reopen at the root dimension list, never a stale drilled-in panel.
function resetToRoot() {
	activeDim.value = null
}

// Clear any stale save-error once the user resumes typing.
watch(saveName, () => { saveError.value = '' })
</script>

<style scoped>
.board-filter-bar {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
}

.board-filter-bar__panel {
	min-width: 260px;
	max-width: 320px;
	max-height: min(70vh, 520px);
	overflow-y: auto;
	padding: 4px;
	box-sizing: border-box;
}

/* ── Root dimension list ─────────────────────────────────────────────────── */
.board-filter-bar__dims {
	list-style: none;
	margin: 0;
	padding: 0;
}

.board-filter-bar__dim-row,
.board-filter-bar__action,
.board-filter-bar__opt {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	font-size: inherit;
	text-align: left;
	padding: 8px 10px;
	border-radius: var(--border-radius, 8px);
	cursor: pointer;
	min-height: 40px;
	box-sizing: border-box;
}

.board-filter-bar__dim-row:hover,
.board-filter-bar__action:hover,
.board-filter-bar__opt:hover,
.board-filter-bar__dim-row:focus-visible,
.board-filter-bar__action:focus-visible,
.board-filter-bar__opt:focus-visible {
	background: var(--color-background-hover);
	outline: none;
}

.board-filter-bar__dim-name {
	flex: 0 0 auto;
	font-weight: 500;
}

.board-filter-bar__dim-summary {
	flex: 1 1 auto;
	min-width: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	text-align: right;
}

.board-filter-bar__dim-badge {
	flex: 0 0 auto;
	min-width: 18px;
	height: 18px;
	padding: 0 5px;
	border-radius: 9px;
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	font-size: 0.75em;
	line-height: 18px;
	text-align: center;
}

.board-filter-bar__dim-chevron {
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
	margin-left: auto;
}
/* When a summary is present it already takes the trailing space, so the chevron
   should not push away with auto-margin. */
.board-filter-bar__dim-summary + .board-filter-bar__dim-badge + .board-filter-bar__dim-chevron,
.board-filter-bar__dim-summary + .board-filter-bar__dim-chevron {
	margin-left: 4px;
}

/* ── Drill-in header ─────────────────────────────────────────────────────── */
.board-filter-bar__drill-header {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 2px 2px 6px;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 4px;
}

.board-filter-bar__drill-title {
	font-weight: 600;
}

/* ── Option rows ─────────────────────────────────────────────────────────── */
.board-filter-bar__opts {
	list-style: none;
	margin: 0;
	padding: 0;
}

.board-filter-bar__opt-text {
	flex: 1 1 auto;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.board-filter-bar__opt-check {
	flex: 0 0 auto;
	color: var(--color-primary-element, #0082c9);
	margin-left: auto;
}

.board-filter-bar__opt-dot {
	flex: 0 0 auto;
	display: inline-block;
	width: 12px;
	height: 12px;
	border-radius: 50%;
	background: var(--filter-dot-color, var(--color-border));
}

/* Priority dot colours (mirror the tile/badge palette). */
.board-filter-bar__priority-item--1 .board-filter-bar__opt-dot { background: #888; }
.board-filter-bar__priority-item--2 .board-filter-bar__opt-dot { background: var(--color-primary-element, #0082c9); }
.board-filter-bar__priority-item--3 .board-filter-bar__opt-dot { background: #e07b00; }
.board-filter-bar__priority-item--4 .board-filter-bar__opt-dot { background: var(--color-error, #e30000); }

/* Type dot colours (mirror the tile/pill type colours). */
.board-filter-bar__type-item--bug .board-filter-bar__opt-dot { background: #e74c3c; }
.board-filter-bar__type-item--feature .board-filter-bar__opt-dot { background: #27ae60; }
.board-filter-bar__type-item--task .board-filter-bar__opt-dot { background: var(--color-primary-element, #0082c9); }
.board-filter-bar__type-item--chore .board-filter-bar__opt-dot { background: #7f8c8d; }

/* ── Saved views ─────────────────────────────────────────────────────────── */
.board-filter-bar__caption {
	margin: 6px 10px 2px;
	font-size: 0.8em;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.03em;
}

.board-filter-bar__saved-item--active {
	font-weight: 600;
}

.board-filter-bar__sep {
	height: 1px;
	background: var(--color-border);
	margin: 4px 0;
}

.board-filter-bar__save-row {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 0 6px 2px;
}

.board-filter-bar__save-input {
	flex: 1 1 auto;
}

.board-filter-bar__save-error {
	color: var(--color-error);
	font-size: 0.8rem;
	margin: 4px 12px;
}
</style>
