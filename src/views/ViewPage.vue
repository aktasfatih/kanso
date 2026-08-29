<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="view-page">
		<div class="view-page__header kanso-page-header">
			<input
				v-if="view && editingTitle"
				ref="titleInput"
				v-model="titleDraft"
				class="view-page__title-input"
				:aria-label="t('kanso', 'View name')"
				@keyup.enter="commitTitle"
				@keyup.escape="cancelTitle"
				@blur="commitTitle">
			<h1
				v-else
				class="view-page__title"
				:class="{ 'view-page__title--editable': !!view }"
				:title="view ? t('kanso', 'Rename view') : ''"
				@click="startTitleEdit">
				{{ view ? view.name : t('kanso', 'View') }}
			</h1>

			<div class="view-page__controls">
				<!-- Filter editor: the SAME progressive filter control the board uses
				     (#3815 reuses useBoardFilters + BoardFilterBar). Editing the state
				     re-filters the loaded rows live AND refetches the feed with the
				     filter applied server-side (#9862); the save button persists it to
				     the View. The facets are cross-board: `labels` is the union the feed
				     envelope carries, `participants` the server-supplied uid vocabulary.
				     `estimate-scale` is deliberately not passed — an estimate scale is
				     board-scoped and a cross-board View can span two of them. -->
				<BoardFilterBar
					:state="filterState"
					:labels="[...labelsById.values()]"
					:participants="participants"
					@save="onSaveFromBar" />

				<!-- Group-by selector -->
				<div class="view-page__select">
					<label class="view-page__select-label">{{ t('kanso', 'Group by') }}</label>
					<NcSelect
						v-model="groupBySel"
						:options="groupByOptions"
						:clearable="false"
						:searchable="false"
						label="label"
						:aria-label="t('kanso', 'Group by')" />
				</div>

				<!-- Sort control: mode + direction. The order is applied SERVER-side
				     (before the feed's cap) and saved on the View, so it survives a
				     reload. The trigger label carries the direction arrow so the active
				     order reads straight off the toolbar. Sorting orders cards WITHIN
				     each group - group ordering stays owned by the group-by field, and
				     in Timeline it reorders the rows inside each group while the
				     horizontal date axis stays date-driven. -->
				<div class="view-page__select">
					<label class="view-page__select-label">{{ t('kanso', 'Sort') }}</label>
					<NcActions
						class="view-page__sort"
						:menu-name="sortMenuName"
						:aria-label="t('kanso', 'Sort')">
						<template #icon>
							<SortIcon :size="18" />
						</template>
						<NcActionCaption :name="t('kanso', 'Sort by')" />
						<NcActionRadio
							v-for="opt in sortOptions"
							:key="opt.id"
							:model-value="sortMode"
							:value="opt.id"
							name="kanso-view-sort"
							@update:model-value="setSortMode">
							{{ opt.label }}
						</NcActionRadio>
						<template v-if="sortMode !== 'default'">
							<NcActionCaption :name="t('kanso', 'Direction')" />
							<NcActionRadio :model-value="sortDir" value="asc" name="kanso-view-sort-dir" @update:model-value="setSortDir">
								{{ t('kanso', 'Ascending') }}
							</NcActionRadio>
							<NcActionRadio :model-value="sortDir" value="desc" name="kanso-view-sort-dir" @update:model-value="setSortDir">
								{{ t('kanso', 'Descending') }}
							</NcActionRadio>
						</template>
					</NcActions>
				</div>

				<!-- Display switcher: List | Timeline | Kanban. -->
				<div class="view-page__display" role="group" :aria-label="t('kanso', 'Display mode')">
					<button
						class="view-page__display-btn"
						:class="{ 'view-page__display-btn--active': display === 'list' }"
						@click="setDisplay('list')">
						<FormatListBulletedIcon :size="18" />
						{{ t('kanso', 'List') }}
					</button>
					<button
						class="view-page__display-btn"
						:class="{ 'view-page__display-btn--active': display === 'timeline' }"
						@click="setDisplay('timeline')">
						<ChartTimelineIcon :size="18" />
						{{ t('kanso', 'Timeline') }}
					</button>
					<button
						class="view-page__display-btn"
						:class="{ 'view-page__display-btn--active': display === 'kanban' }"
						@click="setDisplay('kanban')">
						<ViewColumnOutlineIcon :size="18" />
						{{ t('kanso', 'Kanban') }}
					</button>
				</div>

				<NcButton
					type="primary"
					:disabled="!view || saving"
					class="view-page__save"
					@click="persistCurrent">
					{{ t('kanso', 'Save') }}
				</NcButton>
			</div>
		</div>

		<!-- Loading / error / missing states -->
		<div v-if="isLoading" class="view-page__state" aria-live="polite">
			<span class="view-page__spinner" aria-hidden="true" />
			<span>{{ t('kanso', 'Loading…') }}</span>
		</div>
		<div v-else-if="isError" class="view-page__state view-page__state--error">
			{{ t('kanso', 'Failed to load. Please try again.') }}
		</div>
		<NcEmptyContent
			v-else-if="!view"
			:name="t('kanso', 'View not found')"
			:description="t('kanso', 'This saved view no longer exists.')">
			<template #icon>
				<FilterVariantIcon :size="64" />
			</template>
		</NcEmptyContent>

		<!-- Resolved view: an optional truncation notice, then the chosen display. -->
		<template v-else>
			<!-- Honest truncation notice: the MATCHING set exceeded the server cap,
			     so the feed carries only the first N of M matches (no silent
			     truncation). -->
			<div
				v-if="capped"
				class="view-page__capped"
				role="status"
				aria-live="polite">
				{{ cappedHint }}
			</div>

			<!-- List display over the filtered + grouped cross-board cards -->
			<BoardListView
				v-if="display === 'list'"
				:groups="groups"
				:labels-by-id="labelsById"
				:board-id="null"
				@open="openCard" />

			<!-- Timeline display over the same groups -->
			<BoardTimelineView
				v-else-if="display === 'timeline'"
				:cards="filteredCards"
				:groups="groups"
				:can-edit="false"
				:board-id="null"
				@open="openCard" />

			<!-- Kanban display: the same groups as columns (display-only, no
			     cross-column drag in v1 — a documented v1 stretch). -->
			<ViewKanban
				v-else
				:groups="groups"
				:labels-by-id="labelsById"
				@open="openCard" />
		</template>

		<!-- Card detail opens as an in-place overlay ON the View (#3950): the SAME
		     CardModal → CardDetail the board uses, but in controlled mode so closing
		     just tears the overlay down and leaves the URL at /views/:id — you stay in
		     the View instead of being dumped on the card's board. -->
		<CardModal
			v-if="selectedCardId"
			:key="selectedCardId"
			:card-id="selectedCardId"
			:board-id="selectedBoardId"
			controlled
			@navigate="navigateCard"
			@close="closeCard" />
	</div>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActionRadio from '@nextcloud/vue/components/NcActionRadio'
import SortIcon from 'vue-material-design-icons/Sort.vue'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'
import ChartTimelineIcon from 'vue-material-design-icons/ChartTimeline.vue'
import ViewColumnOutlineIcon from 'vue-material-design-icons/ViewColumnOutline.vue'
import FilterVariantIcon from 'vue-material-design-icons/FilterVariant.vue'
import BoardListView from '../components/BoardListView.vue'
import BoardTimelineView from '../components/BoardTimelineView.vue'
import ViewKanban from '../components/ViewKanban.vue'
import BoardFilterBar from '../components/BoardFilterBar.vue'
import CardModal from '../components/CardModal.vue'
import { useViews } from '../composables/useViews.js'
import { useViewCards } from '../composables/useViewCards.js'
import {
	createFilterState,
	serializeFilter,
	applyFilter,
	makePredicate,
	filterToQuery,
} from '../composables/useBoardFilters.js'
import { groupCardsByField, VIEW_GROUP_BY } from '../composables/useSwimlanes.js'

const props = defineProps({
	/** The saved-view id from the route (/views/:id). */
	id: { type: String, required: true },
})

// ── Sort (mode + direction) ──────────────────────────────────────────────────
// Unlike the filter and the group-by, the sort is applied SERVER-side: it runs
// before the feed's hard cap, so a sorted View starts at the true first row
// rather than the first row of an arbitrary window. It therefore travels to the
// server with the feed request (and is saved on the View record) instead of
// being a client re-sort. Declared above useViewCards() because it is part of
// that query's key.
//
// Semantics mirror the board's display sort: every mode has a NATURAL direction
// that selecting it resets to, and missing values (no due date, never modified)
// always sort last in BOTH directions - the server owns that rule.
//
// 'manual' and 'estimate' are deliberately not offered: manual is the per-stack
// fractional sort key (meaningless compared across boards) and estimate ranks
// against one board's estimate scale, which a cross-board View can span two of.
const SORT_LABELS = {
	default: t('kanso', 'Default'),
	due: t('kanso', 'Due date'),
	priority: t('kanso', 'Priority'),
	title: t('kanso', 'Title'),
	board: t('kanso', 'Board'),
	created: t('kanso', 'Created'),
	modified: t('kanso', 'Last modified'),
}
const SORT_MODES = Object.keys(SORT_LABELS)
const NATURAL_SORT_DIR = { due: 'asc', priority: 'desc', title: 'asc', board: 'asc', created: 'desc', modified: 'desc' }
const sortOptions = SORT_MODES.map((id) => ({ id, label: SORT_LABELS[id] }))
const sortMode = ref('default')
const sortDir = ref('asc')
function setSortMode(mode) {
	if (!SORT_MODES.includes(mode)) return
	sortMode.value = mode
	// Picking a mode resets to its natural direction (Due soonest-first, Title A→Z…).
	sortDir.value = NATURAL_SORT_DIR[mode] ?? 'asc'
}
function setSortDir(dir) {
	sortDir.value = dir === 'desc' ? 'desc' : 'asc'
}
const sort = computed(() => ({ mode: sortMode.value, dir: sortDir.value }))
// The toolbar label carries the direction arrow, so the active order is legible
// without opening the menu. 'default' has no meaningful direction.
const sortMenuName = computed(() => (sortMode.value === 'default'
	? SORT_LABELS.default
	: `${SORT_LABELS[sortMode.value]} ${sortDir.value === 'asc' ? '↑' : '↓'}`))

// The live, editable filter state (the reused board filter). Declared up here
// because - like the sort - it is part of the feed query's key: the SAME filter
// travels to the server, where it runs before the feed's hard cap so the cap
// slices the matching set rather than the first window of the readable set
// (#9862). It is still applied client-side over the rows that come back, so
// editing a chip re-filters the cached rows on the same tick and the refetch
// only widens the pool underneath - no debounce, no loading flash.
// It is seeded from the saved View's blob by the `view` watch further down.
const filterState = createFilterState()
const filterQuery = computed(() => filterToQuery(serializeFilter(filterState)))

const { data: viewsData, save, rename } = useViews()
const { data: cardsData, isLoading, isError } = useViewCards(sort, filterQuery)

const view = computed(() => (viewsData.value ?? []).find((v) => String(v.id) === String(props.id)) ?? null)

// ── Inline rename of the view title (rename by id — distinct from the filter
// bar's save-as-name, which forks a new view) ────────────────────────────────
const editingTitle = ref(false)
const titleDraft = ref('')
const titleInput = ref(null)
function startTitleEdit() {
	if (!view.value) return
	titleDraft.value = view.value.name
	editingTitle.value = true
	nextTick(() => titleInput.value?.focus())
}
async function commitTitle() {
	if (!editingTitle.value) return
	editingTitle.value = false
	const name = titleDraft.value.trim()
	if (!view.value || name === '' || name === view.value.name) return
	await rename.mutateAsync({ id: view.value.id, name })
}
function cancelTitle() {
	editingTitle.value = false
}

// ── Group-by + display state (declared BEFORE the view watch so the immediate
// run can seed them even when the views list is already cache-warm) ──────────
const GROUP_LABELS = {
	status: t('kanso', 'Status'),
	priority: t('kanso', 'Priority'),
	assignee: t('kanso', 'Assignee'),
	board: t('kanso', 'Board'),
	type: t('kanso', 'Type'),
	review: t('kanso', 'Review'),
	due: t('kanso', 'Due date'),
	owner: t('kanso', 'Owner'),
}
const groupByOptions = VIEW_GROUP_BY.map((id) => ({ id, label: GROUP_LABELS[id] }))
const groupBySel = ref(groupByOptions[0])
const groupBy = computed(() => groupBySel.value?.id ?? 'status')
const display = ref('list')
function setDisplay(mode) {
	display.value = mode
}

// Seed the filter state from the saved View's opaque blob, re-seeded whenever
// the resolved View changes - and the display mode + group-by are seeded from
// the View at the same time.
watch(view, (v) => {
	applyFilter(filterState, v ? v.filter : {})
	if (v) {
		display.value = ['list', 'timeline', 'kanban'].includes(v.display) ? v.display : 'list'
		groupBySel.value = groupByOptions.find((o) => o.id === v.groupBy) ?? groupByOptions[0]
		// A View saved before the sort control shipped has no `sort` at all, and an
		// unknown value must be ignored rather than break the page - both fall back
		// to the default order (which is exactly how such a View looked before).
		sortMode.value = SORT_MODES.includes(v.sort?.mode) ? v.sort.mode : 'default'
		sortDir.value = (v.sort?.dir === 'asc' || v.sort?.dir === 'desc') ? v.sort.dir : 'asc'
	}
}, { immediate: true })

const cards = computed(() => cardsData.value?.cards ?? [])

// Honest truncation hint: when the MATCHING set exceeds the server's hard cap the
// feed carries only the first `limit` of `total` matches. `total` counts matching
// cards, not readable ones (#9862 - the filter runs server-side before the cap),
// so narrowing the filter genuinely brings the number down. Surface that rather
// than silently dropping rows (house rule: no silent truncation).
const capped = computed(() => cardsData.value?.capped === true)
const cappedHint = computed(() =>
	t('kanso', 'Showing the first {shown} of {total} matching cards — refine your filter to see the rest.', {
		shown: cardsData.value?.limit ?? cards.value.length,
		total: cardsData.value?.total ?? cards.value.length,
	}),
)

// Apply the reused board predicate over the cross-board card set (client-side,
// summary fields only). now is read once per recompute for a stable window.
const filteredCards = computed(() => {
	const predicate = makePredicate(filterState, Date.now())
	return cards.value.filter(predicate)
})

// Assignee display names + board titles for the group headers (no extra request).
//
// The uid vocabulary comes from the envelope's `participants`, which the server
// accumulates across the whole readable set BEFORE applying the filter. Deriving
// it from the returned rows instead would make it self-narrowing now that the
// server filters: filter to alice and the facet would collapse to alice, so you
// could never add bob - and at zero matches the facet would vanish outright
// (BoardFilterBar hides it on an empty participants list), leaving no way back.
// The row-derived pass is kept as a fallback so an older/cached envelope without
// `participants` still renders group headers.
const nameByUid = computed(() => {
	const m = new Map()
	for (const uid of cardsData.value?.participants ?? []) if (!m.has(uid)) m.set(uid, uid)
	for (const c of cards.value) {
		for (const uid of c.assigneeIds || []) if (!m.has(uid)) m.set(uid, uid)
		if (c.owner && !m.has(c.owner)) m.set(c.owner, c.owner)
	}
	return m
})
const titleByBoard = computed(() => {
	const m = new Map()
	for (const c of cards.value) if (c.boardId != null && !m.has(Number(c.boardId))) m.set(Number(c.boardId), c.boardTitle)
	return m
})

const groups = computed(() =>
	groupCardsByField(groupBy.value, filteredCards.value, { nameByUid: nameByUid.value, titleByBoard: titleByBoard.value }),
)

// Cross-board assignee/owner facet for the filter control — the server-supplied
// vocabulary above, so it stays complete however narrow the filter gets.
const participants = computed(() =>
	[...nameByUid.value.keys()].map((uid) => ({ uid, displayName: uid })),
)

// Real cross-board labelsById map (#3950): the feed envelope carries the union of
// label metadata (id/title/color) across the readable boards, so card tiles render
// the actual label COLOURS — matching the board tiles — rather than neutral dots.
const labelsById = computed(() => {
	const m = new Map()
	for (const label of cardsData.value?.labels ?? []) {
		if (label && label.id != null) m.set(label.id, label)
	}
	return m
})

// ── Card detail overlay (#3950) ───────────────────────────────────────────────
// Opening a card from any display renders the shared CardModal → CardDetail as an
// in-place overlay here, in controlled mode, so the URL stays /views/:id and closing
// returns to the View (never the card's board). We track the card id + its own board
// id (Views are cross-board — CardDetail needs the board for its board-scoped fetches
// and can also derive it from the loaded card, but passing it avoids a first-paint gap).
const selectedCardId = ref(null)
const selectedBoardId = ref(null)
function openCard(card) {
	selectedCardId.value = String(card.id)
	selectedBoardId.value = card.boardId != null ? String(card.boardId) : null
}
function closeCard() {
	selectedCardId.value = null
	selectedBoardId.value = null
}
// An in-card link (parent/sub-card/relation/cross-ref) targeted another card:
// swap the overlay to it in place (#3950). Its board id isn't known up front — the
// re-mounted CardDetail derives it from the freshly loaded card (nulled here so we
// don't carry the previous card's board into the fetch).
function navigateCard(cardId) {
	selectedBoardId.value = null
	selectedCardId.value = String(cardId)
}

// ── Persist ──────────────────────────────────────────────────────────────────
const saving = ref(false)

// Save the CURRENT filter/group/display back onto this View (same name = upsert).
async function persistCurrent() {
	if (!view.value) return
	saving.value = true
	try {
		await save.mutateAsync({
			name: view.value.name,
			filter: serializeFilter(filterState),
			groupBy: groupBy.value,
			display: display.value,
			sort: sort.value,
		})
	} finally {
		saving.value = false
	}
}

// The filter bar's inline "save" (its name field) upserts under the typed name -
// used to fork the current filter into a differently-named View.
async function onSaveFromBar(name) {
	saving.value = true
	try {
		await save.mutateAsync({
			name,
			filter: serializeFilter(filterState),
			groupBy: groupBy.value,
			display: display.value,
			sort: sort.value,
		})
	} finally {
		saving.value = false
	}
}
</script>

<style scoped>
.view-page {
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 0;
}

.view-page__header {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
	padding: 16px 24px 12px 52px;
}

.view-page__title {
	font-size: 1.4rem;
	font-weight: 600;
	margin: 0;
	border-radius: var(--border-radius);
	padding: 2px 6px;
	margin-inline-start: -6px;
}
.view-page__title--editable {
	cursor: text;
}
.view-page__title--editable:hover {
	background: var(--color-background-hover);
}
.view-page__title-input {
	font-size: 1.4rem;
	font-weight: 600;
	margin: 0 0 0 -6px;
	padding: 2px 6px;
	border: 2px solid var(--color-primary-element);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	min-width: 320px;
}

.view-page__controls {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-inline-start: auto;
	flex-wrap: wrap;
}

.view-page__select {
	display: flex;
	align-items: center;
	gap: 6px;
}

.view-page__select-label {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.view-page__display {
	display: inline-flex;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	overflow: hidden;
}

.view-page__display-btn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	border: none;
	background: transparent;
	padding: 6px 12px;
	cursor: pointer;
	color: var(--color-main-text);
	font-size: 0.85rem;
}

.view-page__display-btn--active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.view-page__state {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--color-text-maxcontrast);
	padding: 32px 52px;
}

.view-page__state--error {
	color: var(--color-error);
}

.view-page__capped {
	flex: 0 0 auto;
	margin: 0 24px 8px 52px;
	padding: 8px 12px;
	border-radius: var(--border-radius);
	background: var(--color-warning, var(--color-background-hover));
	color: var(--color-warning-text, var(--color-main-text));
	font-size: 0.9rem;
}

.view-page__spinner {
	display: inline-block;
	width: 20px;
	height: 20px;
	border: 2px solid var(--color-border);
	border-top-color: var(--color-primary-element);
	border-radius: 50%;
	animation: view-spin 0.7s linear infinite;
}

@keyframes view-spin {
	to { transform: rotate(360deg); }
}
</style>
