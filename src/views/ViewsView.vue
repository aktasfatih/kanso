<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="views-view kanso-page-view">
		<!-- Header: title + the reused filter bar (doubles as the View editor:
		     save = create/update a View, apply-saved = open one, delete-saved =
		     remove one). -->
		<div class="views-view__header">
			<h1 class="views-view__title">
				<FilterVariantIcon :size="20" />
				{{ headerTitle }}
				<span v-if="!isLoading" class="views-view__count">{{ matchedCards.length }}</span>
			</h1>

			<BoardFilterBar
				class="views-view__filter"
				:state="filterState"
				:labels="labels"
				:participants="participants"
				:saved-filters="views"
				:active-saved-name="activeViewName"
				estimate-scale="none"
				@save="handleSave"
				@apply-saved="handleApply"
				@delete-saved="handleDelete" />
		</div>

		<div v-if="filterError" class="views-view__action-error">{{ filterError }}</div>

		<!-- Loading -->
		<div v-if="isLoading" class="views-view__loading" aria-live="polite">
			<span class="views-view__spinner" aria-hidden="true" />
			<span>{{ t('kanso', 'Loading…') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="isError" class="views-view__error">
			{{ t('kanso', 'Failed to load cards. Please try again.') }}
		</div>

		<!-- Empty: no readable cards at all -->
		<NcEmptyContent
			v-else-if="openCards.length === 0"
			:name="t('kanso', 'No cards')"
			:description="t('kanso', 'Cards from every board you can read will appear here.')">
			<template #icon>
				<FilterVariantIcon :size="64" />
			</template>
		</NcEmptyContent>

		<!-- Empty: filter matched nothing -->
		<NcEmptyContent
			v-else-if="rows.length === 0"
			:name="t('kanso', 'No matching cards')"
			:description="t('kanso', 'No cards match this view’s filter.')">
			<template #icon>
				<FilterVariantIcon :size="64" />
			</template>
		</NcEmptyContent>

		<!-- Virtualized list: per-board group headers + card rows -->
		<div v-else ref="scrollRef" class="views-view__scroll">
			<div
				class="views-view__host"
				:style="{ height: virtualizer.getTotalSize() + 'px' }">
				<div
					v-for="vRow in virtualizer.getVirtualItems()"
					:key="rows[vRow.index].id"
					:data-index="vRow.index"
					class="views-view__vrow"
					:style="{ position: 'absolute', top: 0, left: 0, width: '100%', height: `${vRow.size}px`, transform: `translateY(${vRow.start}px)` }">

					<!-- Board group header -->
					<div v-if="rows[vRow.index].type === 'header'" class="views-view__group">
						<span class="views-view__group-title">{{ rows[vRow.index].boardTitle }}</span>
						<span class="views-view__group-count">{{ rows[vRow.index].count }}</span>
					</div>

					<!-- Card row -->
					<div v-else class="views-view__row">
						<button
							class="views-view__row-open"
							:title="t('kanso', 'Open card')"
							@click="openCard(rows[vRow.index].card)">
							<span class="views-view__row-title">{{ rows[vRow.index].card.title }}</span>
							<span class="views-view__row-meta">
								<span
									v-for="labelId in (rows[vRow.index].card.labelIds || []).slice(0, 4)"
									:key="labelId"
									class="views-view__label-dot"
									:title="labelTitle(labelId)"
									:style="{ background: labelColor(labelId) }" />
								<span
									v-if="rows[vRow.index].card.priority > 0"
									class="views-view__priority"
									:class="`views-view__priority--${rows[vRow.index].card.priority}`">
									{{ priorityLabel(rows[vRow.index].card.priority) }}
								</span>
								<span
									v-if="rows[vRow.index].card.duedate"
									class="views-view__due">
									<CalendarIcon :size="14" />
									{{ formatDue(rows[vRow.index].card.duedate) }}
								</span>
							</span>
						</button>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import { useVirtualizer } from '@tanstack/vue-virtual'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import FilterVariantIcon from 'vue-material-design-icons/FilterVariant.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import BoardFilterBar from '../components/BoardFilterBar.vue'
import { cssColor } from '../services/color.js'
import { PRIORITY_LEVELS } from '../composables/usePriority.js'
import {
	createFilterState,
	serializeFilter,
	applyFilter,
	makePredicate,
} from '../composables/useBoardFilters.js'
import { useViews, useViewCards, useViewMutations } from '../composables/useViews.js'

const props = defineProps({
	/** The View's unique name, or the '__new__' sentinel for a fresh filter. */
	name: { type: String, required: true },
})

const NEW_VIEW = '__new__'

const router = useRouter()

// The reused client-side filter (predicate + serialisation). Same composable the
// board filter bar uses — a View is exactly a persisted board filter, run
// cross-board.
const filterState = createFilterState()
const filterPredicate = computed(() => makePredicate(filterState, Date.now()))

// View definitions (drive the bar's saved list + the active highlight).
const { data: viewsData } = useViews()
const views = computed(() => viewsData.value ?? [])

// The readable cross-board card feed + facet catalogs. ACL-restricted server-side.
const { data: feed, isLoading, isError } = useViewCards()
const openCards = computed(() =>
	// Archived cards ride the summary shape (byte-identical to the board payload)
	// but are not live work — filter them here as the board render does.
	(feed.value?.cards ?? []).filter((c) => !c.archived),
)
const labels = computed(() => feed.value?.labels ?? [])
const participants = computed(() => feed.value?.participants ?? [])

const { save, remove } = useViewMutations()
const filterError = ref('')

/** Whether the route targets an existing (named) View or a fresh one. */
const isNamed = computed(() => props.name && props.name !== NEW_VIEW)

const headerTitle = computed(() =>
	isNamed.value ? props.name : t('kanso', 'New view'),
)

// The name of the saved View the live filter currently equals (for the bar's
// active-highlight), or '' when it matches none.
const activeViewName = computed(() => {
	const current = JSON.stringify(serializeFilter(filterState))
	const match = views.value.find((v) => JSON.stringify(v.filter ?? {}) === current)
	return match?.name ?? ''
})

// Load the routed View's filter into the state whenever the route (or the loaded
// definitions) change. A '__new__' route clears to an empty filter; an unknown
// name (deleted elsewhere) also clears rather than throwing.
watch(
	[() => props.name, views],
	([name, list]) => {
		if (!name || name === NEW_VIEW) {
			applyFilter(filterState, {})
			return
		}
		const view = list.find((v) => v.name === name)
		applyFilter(filterState, view?.filter ?? {})
	},
	{ immediate: true },
)

// ── The filtered, board-grouped virtual row model ──────────────────────────────
const matchedCards = computed(() => openCards.value.filter(filterPredicate.value))

/**
 * Flat row model for the virtualizer: one header per board that has matching
 * cards, followed by that board's cards. Boards keep first-seen order (the feed
 * is already board-ordered).
 */
const rows = computed(() => {
	const byBoard = new Map()
	for (const card of matchedCards.value) {
		if (!byBoard.has(card.boardId)) byBoard.set(card.boardId, { title: card.boardTitle, cards: [] })
		byBoard.get(card.boardId).cards.push(card)
	}
	const out = []
	for (const [boardId, group] of byBoard) {
		out.push({ type: 'header', id: `h${boardId}`, boardTitle: group.title || t('kanso', 'Board'), count: group.cards.length })
		for (const card of group.cards) out.push({ type: 'card', id: `c${card.id}`, card })
	}
	return out
})

const scrollRef = ref(null)
const HEADER_H = 38
const ROW_H = 52
const virtualizer = useVirtualizer(computed(() => ({
	count: rows.value.length,
	getScrollElement: () => scrollRef.value,
	estimateSize: (i) => (rows.value[i]?.type === 'header' ? HEADER_H : ROW_H),
	overscan: 12,
	getItemKey: (i) => rows.value[i]?.id ?? i,
})))

// ── Editor actions (create / open / delete a View) ─────────────────────────────
async function handleSave(name) {
	filterError.value = ''
	try {
		await save.mutateAsync({ name, filter: serializeFilter(filterState) })
		// Land on the just-saved View so the nav highlights it and refreshes reflect.
		router.push({ name: 'view', params: { name } })
	} catch (err) {
		filterError.value = err?.response?.data?.error || t('kanso', 'Failed to save the view.')
	}
}

function handleApply(view) {
	// Navigate to the View (the route watcher loads its filter). Keeps the URL a
	// shareable pointer to the View.
	router.push({ name: 'view', params: { name: view.name } })
}

async function handleDelete(name) {
	filterError.value = ''
	try {
		await remove.mutateAsync(name)
		router.push({ name: 'view', params: { name: NEW_VIEW } })
	} catch (err) {
		filterError.value = err?.response?.data?.error || t('kanso', 'Failed to delete the view.')
	}
}

function openCard(card) {
	router.push({ name: 'card-modal', params: { id: String(card.boardId), cardId: String(card.id) }, query: { from: 'views' } })
}

// ── Row metadata helpers ───────────────────────────────────────────────────────
const labelsById = computed(() => {
	const m = new Map()
	for (const l of labels.value) m.set(l.id, l)
	return m
})

function labelColor(labelId) {
	const l = labelsById.value.get(labelId)
	return l && l.color ? cssColor(l.color) : 'var(--color-border-dark)'
}

function labelTitle(labelId) {
	const l = labelsById.value.get(labelId)
	return l ? l.title : ''
}

function priorityLabel(value) {
	const level = PRIORITY_LEVELS.find((l) => l.value === value)
	return level ? t('kanso', level.label) : ''
}

function formatDue(duedate) {
	const d = new Date(duedate)
	if (Number.isNaN(d.getTime())) return ''
	return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}
</script>

<style scoped>
.views-view {
	display: flex;
	flex-direction: column;
	height: 100%;
	overflow: hidden;
}

.views-view__header {
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 12px 24px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-main-background);
	flex-shrink: 0;
}

.views-view__title {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 1.2rem;
	font-weight: 700;
	color: var(--color-main-text);
	margin: 0;
}

.views-view__count {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-hover);
	border-radius: 12px;
	padding: 1px 9px;
}

.views-view__filter {
	margin-left: auto;
}

.views-view__action-error {
	flex-shrink: 0;
	padding: 8px 24px;
	background: rgba(var(--color-error-rgb, 227, 0, 0), 0.1);
	color: var(--color-error);
	font-size: 0.875rem;
}

.views-view__loading {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--color-text-maxcontrast);
	padding: 32px;
}

.views-view__spinner {
	display: inline-block;
	width: 20px;
	height: 20px;
	border: 2px solid var(--color-border);
	border-top-color: var(--color-primary-element);
	border-radius: 50%;
	animation: views-view-spin 0.7s linear infinite;
}

@keyframes views-view-spin {
	to { transform: rotate(360deg); }
}

.views-view__error {
	color: var(--color-error);
	padding: 24px 32px;
}

.views-view__scroll {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
	padding: 8px 24px 24px;
}

.views-view__host {
	position: relative;
	width: 100%;
	max-width: 1100px;
}

.views-view__vrow {
	overflow: hidden;
}

.views-view__group {
	display: flex;
	align-items: center;
	gap: 8px;
	box-sizing: border-box;
	height: 100%;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
	border-bottom: 1px solid var(--color-border);
	text-transform: uppercase;
	font-size: 0.8rem;
	letter-spacing: 0.03em;
}

.views-view__group-count {
	color: var(--color-text-maxcontrast);
	font-weight: 400;
}

.views-view__row {
	display: flex;
	align-items: center;
	gap: 12px;
	box-sizing: border-box;
	height: 100%;
	border-bottom: 1px solid var(--color-border);
}

.views-view__row-open {
	display: flex;
	align-items: center;
	gap: 12px;
	flex: 1;
	min-width: 0;
	height: 100%;
	padding: 6px 8px;
	background: transparent;
	border: none;
	border-radius: 0;
	text-align: start;
	cursor: pointer;
	color: var(--color-main-text);
}

.views-view__row-open:hover {
	background: var(--color-background-hover);
}

.views-view__row-title {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.views-view__row-meta {
	display: flex;
	align-items: center;
	gap: 10px;
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.views-view__label-dot {
	width: 12px;
	height: 12px;
	border-radius: 50%;
	flex: 0 0 auto;
}

.views-view__priority {
	font-size: 0.75rem;
	font-weight: 600;
	padding: 1px 6px;
	border-radius: 8px;
	background: var(--color-background-dark);
}

.views-view__priority--4 { color: var(--color-error); }
.views-view__priority--3 { color: var(--color-warning, #c98600); }

.views-view__due {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	white-space: nowrap;
}
</style>
