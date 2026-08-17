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
				     re-filters live; the save button persists it to the View. -->
				<BoardFilterBar
					:state="filterState"
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

				<!-- Display switcher: List | Timeline (Kanban is Phase 2). -->
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

		<!-- List display over the filtered + grouped cross-board cards -->
		<BoardListView
			v-else-if="display === 'list'"
			:groups="groups"
			:labels-by-id="labelsById"
			:board-id="null" />

		<!-- Timeline display over the same groups -->
		<BoardTimelineView
			v-else
			:cards="filteredCards"
			:groups="groups"
			:can-edit="false"
			:board-id="null" />
	</div>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'
import ChartTimelineIcon from 'vue-material-design-icons/ChartTimeline.vue'
import FilterVariantIcon from 'vue-material-design-icons/FilterVariant.vue'
import BoardListView from '../components/BoardListView.vue'
import BoardTimelineView from '../components/BoardTimelineView.vue'
import BoardFilterBar from '../components/BoardFilterBar.vue'
import { useViews } from '../composables/useViews.js'
import { useViewCards } from '../composables/useViewCards.js'
import {
	createFilterState,
	serializeFilter,
	applyFilter,
	makePredicate,
} from '../composables/useBoardFilters.js'
import { groupCardsByField, VIEW_GROUP_BY } from '../composables/useSwimlanes.js'

const props = defineProps({
	/** The saved-view id from the route (/views/:id). */
	id: { type: String, required: true },
})

const { data: viewsData, save, rename } = useViews()
const { data: cardsData, isLoading, isError } = useViewCards()

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
}
const groupByOptions = VIEW_GROUP_BY.map((id) => ({ id, label: GROUP_LABELS[id] }))
const groupBySel = ref(groupByOptions[0])
const groupBy = computed(() => groupBySel.value?.id ?? 'status')
const display = ref('list')
function setDisplay(mode) {
	display.value = mode
}

// The live, editable filter state (reused board filter). Seeded from the saved
// View's opaque blob and re-seeded whenever the resolved View changes - and the
// display mode + group-by are seeded from the View at the same time.
const filterState = createFilterState()
watch(view, (v) => {
	applyFilter(filterState, v ? v.filter : {})
	if (v) {
		display.value = v.display === 'timeline' ? 'timeline' : 'list'
		groupBySel.value = groupByOptions.find((o) => o.id === v.groupBy) ?? groupByOptions[0]
	}
}, { immediate: true })

const cards = computed(() => cardsData.value ?? [])

// Apply the reused board predicate over the cross-board card set (client-side,
// summary fields only). now is read once per recompute for a stable window.
const filteredCards = computed(() => {
	const predicate = makePredicate(filterState, Date.now())
	return cards.value.filter(predicate)
})

// Assignee display names + board titles for the group headers, derived from the
// loaded cross-board cards (no extra request).
const nameByUid = computed(() => {
	const m = new Map()
	for (const c of cards.value) for (const uid of c.assigneeIds || []) if (!m.has(uid)) m.set(uid, uid)
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

// Cross-board assignee facet for the filter control: uids seen across the feed.
const participants = computed(() =>
	[...nameByUid.value.keys()].map((uid) => ({ uid, displayName: uid })),
)

// The generalized List needs a labelsById map; cross-board label metadata isn't
// loaded, so pass an empty map (label dots simply fall back to a neutral colour).
const labelsById = computed(() => new Map())

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
