<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		class="archived-view kanso-page-view"
		:class="{ 'archived-view--selecting': bulk.selectionMode.value }">
		<!-- Header: back affordance + title + in-view filter -->
		<div class="archived-view__header">
			<NcButton class="archived-view__back" @click="goBack">
				<template #icon>
					<ArrowLeftIcon :size="20" />
				</template>
				{{ boardTitle || t('kanso', 'Board') }}
			</NcButton>
			<h1 class="archived-view__title">
				<ArchiveIcon :size="20" />
				{{ t('kanso', 'Archived cards') }}
				<span v-if="!isLoading" class="archived-view__count">{{ archivedCards.length }}</span>
			</h1>

			<!-- In-view text filter (title match). -->
			<div v-if="!isLoading && archivedCards.length > 0" class="archived-view__filter">
				<MagnifyIcon :size="18" class="archived-view__filter-icon" />
				<input
					v-model="filterText"
					class="archived-view__filter-input"
					type="text"
					:placeholder="t('kanso', 'Filter archived…')"
					:aria-label="t('kanso', 'Filter archived cards')" />
			</div>

			<!-- Multi-select (#10440): the same mode the board offers, so a column
			     archived in ONE click (#10430) can be restored in one action instead
			     of one click per card. Deliberately the board's own strings and
			     toggle semantics — this is the same gesture on another surface, not
			     a second interaction to learn. -->
			<NcButton
				v-if="!isLoading && archivedCards.length > 0"
				class="archived-view__select-toggle"
				:type="bulk.selectionMode.value ? 'secondary' : 'tertiary'"
				:aria-pressed="bulk.selectionMode.value ? 'true' : 'false'"
				@click="bulk.selectionMode.value ? bulk.exitMode() : bulk.enterMode()">
				<template #icon>
					<SelectMultipleIcon :size="20" />
				</template>
				{{ bulk.selectionMode.value ? t('kanso', 'Exit multi-select') : t('kanso', 'Select multiple cards') }}
			</NcButton>
		</div>

		<!-- Loading -->
		<div v-if="isLoading" class="archived-view__loading" aria-live="polite">
			<span class="archived-view__spinner" aria-hidden="true" />
			<span>{{ t('kanso', 'Loading…') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="isError" class="archived-view__error">
			{{ t('kanso', 'Failed to load board.') }}
		</div>

		<!-- Empty state (no archived cards at all) -->
		<div v-else-if="archivedCards.length === 0" class="archived-view__empty">
			<ArchiveIcon :size="48" class="archived-view__empty-icon" />
			<p class="archived-view__empty-title">{{ t('kanso', 'No archived cards') }}</p>
			<p class="archived-view__empty-sub">
				{{ t('kanso', 'Cards you archive from the board will show up here.') }}
			</p>
		</div>

		<!-- Empty filter result -->
		<div v-else-if="rows.length === 0" class="archived-view__empty">
			<p class="archived-view__empty-sub">
				{{ t('kanso', 'No archived cards match “{query}”.', { query: filterText }) }}
			</p>
		</div>

		<!-- Virtualized list: stack group headers + card rows -->
		<div v-else ref="scrollRef" class="archived-view__scroll">
			<div
				class="archived-view__host"
				:style="{ height: virtualizer.getTotalSize() + 'px' }">
				<div
					v-for="vRow in virtualizer.getVirtualItems()"
					:key="rows[vRow.index].id"
					:data-index="vRow.index"
					class="archived-view__vrow"
					:style="{ position: 'absolute', top: 0, left: 0, width: '100%', height: `${vRow.size}px`, transform: `translateY(${vRow.start}px)` }">

					<!-- Stack group header -->
					<div v-if="rows[vRow.index].type === 'header'" class="archived-view__group">
						<span
							class="archived-view__group-dot"
							:style="rows[vRow.index].stack.color ? { background: cssColor(rows[vRow.index].stack.color) } : {}" />
						<span class="archived-view__group-title">{{ rows[vRow.index].stack.title }}</span>
						<span class="archived-view__group-count">{{ rows[vRow.index].count }}</span>
						<!-- Select every archived card of this column (#10440) — the
						     counterpart to the column ⋯ menu's "Select N cards"
						     (#10485), and what makes "I archived this column, put it
						     back" two clicks rather than one per card. Same semantics
						     as there: it names the count it will select, and it is
						     ADDITIVE, so a selection built in another group survives.
						     Offered only inside selection mode, which the header
						     toggle is the single way into. -->
						<button
							v-if="bulk.selectionMode.value"
							class="archived-view__group-select"
							@click="selectGroup(rows[vRow.index])">
							{{ selectGroupLabel(rows[vRow.index].count) }}
						</button>
					</div>

					<!-- Card row -->
					<div
						v-else
						class="archived-view__row"
						:class="{ 'archived-view__row--selected': isRowSelected(rows[vRow.index].card.id) }">
						<!-- Selection tick. The row button beside it is the control
						     that assistive tech sees (it carries the pressed state);
						     this box shows the state and is clickable itself.
						     `.prevent` is load-bearing: without it the browser's own
						     activation flips `checked` first, and a click that leaves
						     the model UNCHANGED (re-covering a row with a second
						     shift-click) would then leave the tick contradicting both
						     the row's tint and the bar's count, because Vue skips
						     patching a prop whose bound value did not change. -->
						<input
							v-if="bulk.selectionMode.value"
							type="checkbox"
							class="archived-view__row-select"
							:checked="isRowSelected(rows[vRow.index].card.id)"
							tabindex="-1"
							aria-hidden="true"
							@click.stop.prevent="onRowSelect(rows[vRow.index].card.id, $event)" />
						<button
							class="archived-view__row-open"
							:title="bulk.selectionMode.value ? undefined : t('kanso', 'Open card')"
							:aria-pressed="bulk.selectionMode.value ? (isRowSelected(rows[vRow.index].card.id) ? 'true' : 'false') : undefined"
							@click="onRowActivate(rows[vRow.index].card.id, $event)">
							<span class="archived-view__row-title">{{ rows[vRow.index].card.title }}</span>
							<span class="archived-view__row-meta">
								<span
									v-for="labelId in (rows[vRow.index].card.labelIds || []).slice(0, 4)"
									:key="labelId"
									class="archived-view__label-dot"
									:title="labelTitle(labelId)"
									:style="{ background: labelColor(labelId) }" />
								<span
									v-if="rows[vRow.index].card.priority > 0"
									class="archived-view__priority"
									:class="`archived-view__priority--${rows[vRow.index].card.priority}`">
									{{ priorityLabel(rows[vRow.index].card.priority) }}
								</span>
								<span
									v-if="rows[vRow.index].card.duedate"
									class="archived-view__due">
									<CalendarIcon :size="14" />
									{{ formatDue(rows[vRow.index].card.duedate) }}
								</span>
							</span>
						</button>
						<!-- Single-row restore. Withdrawn inside selection mode: a row
						     must not offer two contradictory actions at once, and
						     unarchiving a SELECTED row behind the bar's back would
						     leave a dead id in the selection — over-reported in the
						     count, then sent as a no-op. -->
						<NcButton
							v-if="!bulk.selectionMode.value"
							:disabled="unarchivingId === rows[vRow.index].card.id"
							@click="handleUnarchive(rows[vRow.index].card)">
							<template #icon>
								<ArchiveArrowUpIcon :size="16" />
							</template>
							{{ t('kanso', 'Unarchive') }}
						</NcButton>
					</div>
				</div>
			</div>
		</div>

		<!-- Action error banner -->
		<div v-if="unarchiveError" class="archived-view__action-error">
			{{ unarchiveError }}
		</div>

		<!-- The shared multi-select bar, holding the one bulk action this page has
		     (#10440). Chunking to the server's per-request cap is useBulkSelect's
		     job, so a 400-card archive restores in four requests rather than one
		     rejected one. -->
		<BulkSelectionBar
			v-if="bulk.selectionMode.value"
			:count="bulk.selectedCount.value"
			@close="bulk.exitMode()">
			<NcButton
				type="primary"
				:disabled="bulk.applying.value || bulk.selectedCount.value === 0"
				@click="handleRestoreSelected">
				<template #icon>
					<ArchiveArrowUpIcon :size="20" />
				</template>
				{{ t('kanso', 'Restore selected') }}
			</NcButton>
		</BulkSelectionBar>
	</div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showSuccess, showWarning } from '@nextcloud/dialogs'
import { useVirtualizer } from '@tanstack/vue-virtual'
import { useQueryClient } from '@tanstack/vue-query'
import NcButton from '@nextcloud/vue/components/NcButton'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import ArchiveIcon from 'vue-material-design-icons/Archive.vue'
import ArchiveArrowUpIcon from 'vue-material-design-icons/ArchiveArrowUp.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import SelectMultipleIcon from 'vue-material-design-icons/SelectMultiple.vue'
import BulkSelectionBar from '../components/BulkSelectionBar.vue'
import { useBulkSelect } from '../composables/useBulkSelect.js'
import { useBoard } from '../composables/useBoard.js'
import { usePageTitle } from '../composables/usePageTitle.js'
import { boardQueryKey } from '../composables/queryKeys.js'
import { updateCard as apiUpdateCard } from '../services/api.js'
import { cssColor } from '../services/color.js'
import { PRIORITY_LEVELS } from '../composables/usePriority.js'

const props = defineProps({
	id: {
		type: String,
		required: true,
	},
})

const router = useRouter()
const queryClient = useQueryClient()
const boardId = computed(() => props.id)

// The board GET already returns archived cards in its cards array, so we reuse
// the board query rather than adding a dedicated endpoint. Gated exactly like
// the board (board READ) — a failed READ surfaces the error state below.
const { data: boardData, isLoading, isError } = useBoard(boardId)

const boardTitle = computed(() => boardData.value?.board?.title ?? '')

// Browser tab title (#125): whose archive this is. Empty while the board query
// is in flight, which leaves the bare app name rather than a half-built title.
usePageTitle(() => (boardTitle.value
	? boardTitle.value + ' · ' + t('kanso', 'Archived')
	: ''))

const bySortKey = (a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0)

const sortedStacks = computed(() => {
	if (!boardData.value?.stacks) return []
	return [...boardData.value.stacks].filter((s) => !s.archived).sort(bySortKey)
})

const stackMap = computed(() => new Map(sortedStacks.value.map((s) => [s.id, s])))

/** All archived (but not deleted) cards from the board payload. */
const archivedCards = computed(() =>
	(boardData.value?.cards ?? []).filter((c) => c.archived),
)

/** Label id → label lookup for row metadata. */
const labelsById = computed(() => {
	const map = new Map()
	for (const l of boardData.value?.labels ?? []) map.set(l.id, l)
	return map
})

// ── In-view text filter (title, case-insensitive) ─────────────────────────────
const filterText = ref('')

const filteredCards = computed(() => {
	const q = filterText.value.trim().toLowerCase()
	if (!q) return archivedCards.value
	return archivedCards.value.filter((c) => String(c.title).toLowerCase().includes(q))
})

/**
 * Flat row model for the virtualizer: one header per stack that still has
 * matching archived cards, followed by its cards. Cards in an unknown stack
 * (stack deleted after archive) fall under an "Unknown stack" group so nothing
 * is silently dropped.
 */
const rows = computed(() => {
	const byStack = new Map()
	for (const card of filteredCards.value) {
		if (!byStack.has(card.stackId)) byStack.set(card.stackId, [])
		byStack.get(card.stackId).push(card)
	}

	// A header carries its group's card ids (not just the count) so "select this
	// whole group" reads them off the ROW MODEL — every matching card, including
	// the ones the virtualizer has not mounted.
	const out = []
	for (const stack of sortedStacks.value) {
		const cards = byStack.get(stack.id)
		if (!cards || !cards.length) continue
		out.push({ type: 'header', id: `h${stack.id}`, stack, count: cards.length, cardIds: cards.map((c) => c.id) })
		for (const card of cards) out.push({ type: 'card', id: `c${card.id}`, card })
	}
	// Unknown stacks (edge case): stack removed after the card was archived.
	for (const [stackId, cards] of byStack) {
		if (stackMap.value.has(stackId)) continue
		const stack = { id: stackId, title: t('kanso', 'Unknown stack'), color: null }
		out.push({ type: 'header', id: `h${stackId}`, stack, count: cards.length, cardIds: cards.map((c) => c.id) })
		for (const card of cards) out.push({ type: 'card', id: `c${card.id}`, card })
	}
	return out
})

// Fixed heights (uniform rows) — the virtualizer positions never thrash on a
// data refresh, mirroring BoardListView.
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

// ── Actions ───────────────────────────────────────────────────────────────────
// Card id whose single-row Unarchive is in flight, and the banner shared by the
// single-card and the bulk path (latest outcome wins).
const unarchivingId = ref(null)
const unarchiveError = ref('')

// ── Multi-select (#10440) ─────────────────────────────────────────────────────
// The board's selection store, unchanged: a Set of card ids, shift-ranges, and
// a bulk apply chunked to the server's MAX_CARDS. Nothing here is archive-
// specific except which action the bar offers.
const bulk = useBulkSelect(boardId, queryClient)

/**
 * The card rows' ids in display order — the list a shift-range is resolved
 * against. Taken from `rows` (the data model) rather than from the DOM, so a
 * range whose endpoints sit either side of the virtualized window still covers
 * everything in between; the virtualizer only indexes into this list, it never
 * shortens it.
 */
const orderedCardIds = computed(() =>
	rows.value.filter((r) => r.type === 'card').map((r) => r.card.id),
)

/**
 * Whether a row is ticked. Selection mode is part of the question so a stale
 * selection can never paint rows after the mode is left.
 *
 * @param {number} cardId - the row's card id
 * @return {boolean} true when the row is selected and the mode is on
 */
function isRowSelected(cardId) {
	return bulk.selectionMode.value && bulk.isSelected(cardId)
}

/**
 * Add/remove a row, or extend the range from the last one on a shift-click —
 * the board's gesture, applied to this list's order.
 *
 * @param {number} cardId - the row's card id
 * @param {MouseEvent} event - the originating click
 */
function onRowSelect(cardId, event) {
	if (event?.shiftKey) {
		bulk.selectRange(orderedCardIds.value, cardId)
	} else {
		bulk.toggle(cardId)
	}
}

/**
 * A click on the row: selects while multi-select is on, opens the card
 * otherwise — exactly how a card tile behaves on the board.
 *
 * @param {number} cardId - the row's card id
 * @param {MouseEvent} event - the originating click
 */
function onRowActivate(cardId, event) {
	if (bulk.selectionMode.value) {
		onRowSelect(cardId, event)
		return
	}
	openCard(cardId)
}

/**
 * Label for a group's select-all. A group's rows are the FILTERED ones, so under
 * an active filter the button must not claim the whole column — the same honesty
 * the column ⋯ menu buys with the same two strings (#10485).
 *
 * @param {number} count - cards the click will select
 * @return {string} the button's label
 */
function selectGroupLabel(count) {
	return filterText.value.trim()
		? n('kanso', 'Select %n visible card', 'Select %n visible cards', count)
		: n('kanso', 'Select %n card', 'Select %n cards', count)
}

/**
 * Select every archived card under one group header.
 *
 * @param {{cardIds: number[]}} row - the header row that was clicked
 */
function selectGroup(row) {
	if (!row.cardIds?.length) return
	bulk.addMany(row.cardIds)
}

// Nothing left to select: leave the mode rather than strand the bar over the
// empty state — restoring everything is the expected end of this flow.
watch(() => archivedCards.value.length, (count) => {
	if (count === 0 && bulk.selectionMode.value) bulk.exitMode()
})

/**
 * Restore the whole selection in one action. Reports like every other bulk path
 * (BoardView.runBulkAction): a summary toast on success, a warning naming the
 * cards that were skipped per-card server-side, and — because a big selection is
 * applied in chunks — a warning naming what DID land when a later chunk fails.
 * No optimistic write: the rows leave the list on the invalidation
 * `useBulkSelect` already runs, which is the database-first path.
 *
 * The SELECTION is independent of the in-view filter, exactly as it is of the
 * board's filter: narrowing the filter hides rows without un-selecting them, and
 * the bar keeps naming the real count, so nothing is restored silently. What the
 * filter does narrow is the two things derived from the visible rows — a
 * shift-range and a group's select-all — which is the board's behaviour too.
 */
async function handleRestoreSelected() {
	unarchiveError.value = ''
	try {
		const result = await bulk.apply('unarchive')
		const ok = result?.ok?.length ?? 0
		const skipped = result?.skipped?.length ?? 0
		if (skipped > 0) {
			showWarning(t('kanso', '{ok} updated, {skipped} skipped', { ok, skipped }))
		} else if (ok > 0) {
			showSuccess(t('kanso', '{ok} cards updated', { ok }))
		}
	} catch (err) {
		unarchiveError.value = err?.response?.data?.error || t('kanso', 'Bulk action failed.')
		const partialOk = err?.partial?.ok?.length ?? 0
		if (partialOk > 0) {
			showWarning(t('kanso', '{ok} cards updated before the action failed', { ok: partialOk }))
		}
	}
}

// ── Single-row unarchive ──────────────────────────────────────────────────────
async function handleUnarchive(card) {
	unarchiveError.value = ''
	unarchivingId.value = card.id

	const boardKey = boardQueryKey(props.id)
	const cardKey = ['card', String(card.id)]

	try {
		// Optimistic: flip the archived flag in the board cache so the row leaves
		// this list immediately; db-first reconcile follows via invalidation.
		await queryClient.cancelQueries({ queryKey: boardKey })
		await queryClient.cancelQueries({ queryKey: cardKey })

		queryClient.setQueryData(boardKey, (old) => {
			if (!old) return old
			return {
				...old,
				cards: old.cards.map((c) =>
					c.id === card.id ? { ...c, archived: false } : c,
				),
			}
		})
		queryClient.setQueryData(cardKey, (old) => {
			if (!old) return old
			return { ...old, archived: false }
		})

		await apiUpdateCard(card.id, { archived: false })
	} catch (err) {
		unarchiveError.value = err?.response?.data?.error || t('kanso', 'Failed to unarchive card.')
		queryClient.invalidateQueries({ queryKey: boardKey })
	} finally {
		unarchivingId.value = null
		queryClient.invalidateQueries({ queryKey: boardKey })
		queryClient.invalidateQueries({ queryKey: cardKey })
	}
}

function openCard(cardId) {
	router.push({ name: 'card-modal', params: { id: String(props.id), cardId: String(cardId) } })
}

function goBack() {
	router.push({ name: 'board', params: { id: props.id } })
}

// ── Row metadata helpers ──────────────────────────────────────────────────────
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
	return level ? level.label : ''
}

function formatDue(duedate) {
	const d = new Date(duedate)
	if (Number.isNaN(d.getTime())) return ''
	return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}
</script>

<style scoped>
.archived-view {
	display: flex;
	flex-direction: column;
	height: 100%;
	overflow: hidden;
}

/* The selection bar is `position: fixed` over the bottom of the viewport, so it
   would cover the last rows AND the action-error banner (the page's last in-flow
   child — the one that reports a failed restore). Shortening the PAGE, not just
   the scroller, is what keeps both clear of it; only paid while the bar is up. */
.archived-view--selecting {
	box-sizing: border-box;
	padding-bottom: 76px;
}

/* ── Header ─────────────────────────────────────────────────────────────────── */
.archived-view__header {
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 12px 24px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-main-background);
	flex-shrink: 0;
}

.archived-view__back {
	flex-shrink: 0;
}

.archived-view__title {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 1.2rem;
	font-weight: 700;
	color: var(--color-main-text);
	margin: 0;
}

.archived-view__count {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-hover);
	border-radius: 12px;
	padding: 1px 9px;
}

.archived-view__filter {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-left: auto;
	padding: 4px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 16px);
	background: var(--color-main-background);
}

.archived-view__filter-icon {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.archived-view__filter-input {
	border: none;
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.875rem;
	width: 180px;
}

.archived-view__filter-input:focus {
	outline: none;
}

.archived-view__select-toggle {
	flex-shrink: 0;
}

/* ── Loading / error / empty ─────────────────────────────────────────────────── */
.archived-view__loading {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--color-text-maxcontrast);
	padding: 32px;
}

.archived-view__spinner {
	display: inline-block;
	width: 20px;
	height: 20px;
	border: 2px solid var(--color-border);
	border-top-color: var(--color-primary-element);
	border-radius: 50%;
	animation: archived-view-spin 0.7s linear infinite;
}

@keyframes archived-view-spin {
	to { transform: rotate(360deg); }
}

.archived-view__error {
	color: var(--color-error-text);
	padding: 24px 32px;
}

.archived-view__empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 6px;
	flex: 1;
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 48px 24px;
}

.archived-view__empty-icon {
	color: var(--color-border-dark);
	margin-bottom: 8px;
}

.archived-view__empty-title {
	font-size: 1.05rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0;
}

.archived-view__empty-sub {
	margin: 0;
	font-size: 0.9rem;
}

/* ── Virtualized list ─────────────────────────────────────────────────────────── */
.archived-view__scroll {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
	padding: 8px 24px 24px;
}

.archived-view__host {
	position: relative;
	width: 100%;
	max-width: 1100px;
}

.archived-view__vrow {
	overflow: hidden;
}

.archived-view__group {
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

.archived-view__group-dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: var(--color-primary-element);
	flex: 0 0 auto;
}

.archived-view__group-count {
	color: var(--color-text-maxcontrast);
	font-weight: 400;
}

/* Group select-all. A plain button, not an NcButton: the header row's height is
   a FIXED virtualizer measurement (HEADER_H = 38), and the library's 44px control
   would overflow it. `min-height: 24px` still clears the WCAG 2.2 target-size
   floor inside that cell, and the group's own `text-transform`/`letter-spacing`
   are reset so the label is not shouted like the column name beside it. */
.archived-view__group-select {
	margin-left: auto;
	min-height: 24px;
	padding: 2px 8px;
	background: transparent;
	border: none;
	border-radius: var(--border-radius, 4px);
	color: var(--color-primary-element);
	font: inherit;
	font-weight: 600;
	text-transform: none;
	letter-spacing: normal;
	cursor: pointer;
}

.archived-view__group-select:hover {
	background: var(--color-background-hover);
}

.archived-view__row {
	display: flex;
	align-items: center;
	gap: 12px;
	box-sizing: border-box;
	height: 100%;
	border-bottom: 1px solid var(--color-border);
}

/* A tint, where the board's tile uses an outline ring: these rows are adjacent
   and each sits in a fixed-height, overflow-hidden virtualizer cell, so an
   outline would be clipped and would fight the row's bottom border. */
.archived-view__row--selected {
	background: var(--color-primary-element-light, rgba(0, 130, 201, 0.15));
}

/* Same geometry and accent as the board tile's box (CardTile), so the tick reads
   as the same control and follows the instance theme instead of the UA's blue. */
.archived-view__row-select {
	flex: 0 0 auto;
	width: 16px;
	height: 16px;
	margin: 0 0 0 4px;
	accent-color: var(--color-primary-element);
	cursor: pointer;
}

.archived-view__row-open {
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

.archived-view__row-open:hover {
	background: var(--color-background-hover);
}

.archived-view__row-title {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.archived-view__row-meta {
	display: flex;
	align-items: center;
	gap: 10px;
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.archived-view__label-dot {
	width: 12px;
	height: 12px;
	border-radius: 50%;
	flex: 0 0 auto;
}

.archived-view__priority {
	font-size: 0.75rem;
	font-weight: 600;
	padding: 1px 6px;
	border-radius: 8px;
	background: var(--color-background-dark);
}

.archived-view__priority--4 { color: var(--color-error-text); }
.archived-view__priority--3 { color: var(--color-warning-text, #c98600); }

.archived-view__due {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	white-space: nowrap;
}

/* ── Action error ─────────────────────────────────────────────────────────────── */
.archived-view__action-error {
	flex-shrink: 0;
	padding: 8px 24px;
	background: rgba(var(--color-error-rgb, 227, 0, 0), 0.1);
	color: var(--color-error-text);
	font-size: 0.875rem;
}
</style>
