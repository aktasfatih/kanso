<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="board-view">
		<!-- Header -->
		<div ref="headerRef" class="board-view__header">
			<NcButton class="board-view__back" @click="goBack">
				<template #icon>
					<ArrowLeftIcon :size="20" />
				</template>
				{{ t('kanso', 'All boards') }}
			</NcButton>
			<h1 v-if="boardData" class="board-view__title">
				<span
					v-if="boardData.board.color"
					class="board-view__color-dot"
					:style="{ background: boardData.board.color }" />
				{{ boardData.board.title }}
			</h1>
			<div v-else-if="isLoading" class="board-view__title-skeleton skeleton-text" />

			<!-- In-board search - scoped to the current board; only rendered once
			     the board payload is available so boardId is valid. -->
			<SearchBox
				v-if="boardData"
				ref="searchBoxRef"
				class="board-view__search"
				:board-id="props.id" />

			<!-- View switch - Board (columns) vs List (table). Persisted per board. -->
			<NcActions
				v-if="boardData"
				class="board-view__view-menu"
				:menu-name="viewModeLabel">
				<template #icon>
					<ChartTimelineIcon v-if="viewMode === 'timeline'" :size="20" />
					<FormatListBulletedIcon v-else-if="viewMode === 'list'" :size="20" />
					<ViewColumnIcon v-else :size="20" />
				</template>
				<NcActionRadio
					:model-value="viewMode === 'board'"
					name="kanso-viewmode"
					@change="setViewMode('board')">
					{{ t('kanso', 'Board') }}
				</NcActionRadio>
				<NcActionRadio
					:model-value="viewMode === 'list'"
					name="kanso-viewmode"
					@change="setViewMode('list')">
					{{ t('kanso', 'List') }}
				</NcActionRadio>
				<NcActionRadio
					:model-value="viewMode === 'timeline'"
					name="kanso-viewmode"
					@change="setViewMode('timeline')">
					{{ t('kanso', 'Timeline') }}
				</NcActionRadio>
			</NcActions>

			<!-- Display sort - a view-only reorder within each stack (Board + List). -->
			<NcActions
				v-if="boardData"
				class="board-view__sort-menu"
				:menu-name="t('kanso', 'Sort: {mode}', { mode: sortModeLabel })">
				<template #icon>
					<SortIcon :size="20" />
				</template>
				<NcActionRadio :model-value="sortMode === 'manual'" name="kanso-sort" @change="setSortMode('manual')">
					{{ t('kanso', 'Manual') }}
				</NcActionRadio>
				<NcActionRadio :model-value="sortMode === 'priority'" name="kanso-sort" @change="setSortMode('priority')">
					{{ t('kanso', 'Priority') }}
				</NcActionRadio>
				<NcActionRadio :model-value="sortMode === 'due'" name="kanso-sort" @change="setSortMode('due')">
					{{ t('kanso', 'Due date') }}
				</NcActionRadio>
				<NcActionRadio :model-value="sortMode === 'title'" name="kanso-sort" @change="setSortMode('title')">
					{{ t('kanso', 'Title') }}
				</NcActionRadio>
			</NcActions>

			<!-- Filter dropdown - compact NcActions menu replacing the old chip row.
			     Only rendered when the board has at least one label OR always when
			     priority filtering is desired (priority filter is always available).
			     Filter dimensions: labels (OR within), priority levels (OR within).
			     AND is applied across the two filter types. -->
			<NcActions
				v-if="boardData"
				class="board-view__filter-menu"
				:aria-label="t('kanso', 'Filter cards')"
				:menu-name="totalActiveFilters > 0
					? t('kanso', 'Filter · {count}', { count: totalActiveFilters })
					: t('kanso', 'Filter')"
				:primary="totalActiveFilters > 0">
				<template #icon>
					<FilterVariantIcon :size="20" />
				</template>

				<!-- ── Label filter section ───────────────────────────────────────
				     One NcActionCheckbox per board label.
				     NcActionCheckbox (NC Vue 9) reads the default slot as plain text
				     via ActionGlobalMixin.getText(); a wrapper span would be silently
				     dropped. The color dot is therefore injected via a CSS custom
				     property (--filter-dot-color) set on the root li element and a
				     :deep(::before) pseudo-element that draws the circle. -->
				<NcActionCheckbox
					v-for="label in boardLabels"
					:key="label.id"
					class="board-view__filter-label-item"
					:style="label.color ? { '--filter-dot-color': '#' + label.color } : { '--filter-dot-color': 'var(--color-border)' }"
					:model-value="activeFilterIds.has(label.id)"
					@update:model-value="toggleFilterLabel(label.id)">{{ label.title }}</NcActionCheckbox>

				<!-- ── Priority filter section ────────────────────────────────────
				     One checkbox per priority level > 0 (None is implicit when all
				     priority filters are inactive). OR within the priority set. -->
				<NcActionCheckbox
					v-for="level in PRIORITY_LEVELS.filter((l) => l.value > 0)"
					:key="'priority-' + level.value"
					class="board-view__filter-priority-item"
					:class="`board-view__filter-priority-item--${level.value}`"
					:model-value="activePriorityLevels.has(level.value)"
					@update:model-value="toggleFilterPriority(level.value)">{{ t('kanso', level.label) }}</NcActionCheckbox>

				<!-- ── Clear action (hidden when no filters active) ───────────── -->
				<NcActionButton
					v-if="totalActiveFilters > 0"
					@click="clearAllFilters">
					<template #icon>
						<FilterVariantRemoveIcon :size="20" />
					</template>
					{{ t('kanso', 'Clear filters') }}
				</NcActionButton>
			</NcActions>

			<!-- Archived cards page button - only shown when ≥1 archived card -->
			<NcButton
				v-if="boardData && archivedCards.length > 0"
				class="board-view__archived-btn"
				:title="t('kanso', 'View archived cards')"
				:aria-label="t('kanso', 'View archived cards ({count})', { count: archivedCards.length })"
				@click="goToArchived">
				<template #icon>
					<ArchiveIcon :size="20" />
				</template>
				{{ archivedCards.length }}
			</NcButton>

			<!-- Watch / unwatch this board - subscribes to a "new card created"
			     notification. Uses the same eye icon as the card-level watcher:
			     eye-off (crossed) when watching, plain eye outline when not. -->
			<NcButton
				v-if="boardData"
				class="board-view__watch-btn"
				:type="isBoardSubscribed ? 'secondary' : 'tertiary'"
				:title="isBoardSubscribed
					? t('kanso', 'Watching this board (click to stop)')
					: t('kanso', 'Watch this board for new cards')"
				:aria-label="isBoardSubscribed ? t('kanso', 'Unwatch board') : t('kanso', 'Watch board')"
				:aria-pressed="isBoardSubscribed ? 'true' : 'false'"
				@click="toggleBoardWatch">
				<template #icon>
					<EyeOffOutlineIcon v-if="isBoardSubscribed" :size="20" />
					<EyeOutlineIcon v-else :size="20" />
				</template>
			</NcButton>

			<!-- Trash button - always visible when board is loaded; opens the Trash page -->
			<NcButton
				v-if="boardData"
				class="board-view__trash-btn"
				:title="t('kanso', 'View deleted cards')"
				:aria-label="t('kanso', 'View deleted cards')"
				@click="goToTrash">
				<template #icon>
					<DeleteIcon :size="20" />
				</template>
			</NcButton>

			<!-- Analytics button - navigates to the board stats page -->
			<NcButton
				v-if="boardData"
				class="board-view__analytics-btn"
				:title="t('kanso', 'Board analytics')"
				:aria-label="t('kanso', 'Board analytics')"
				@click="goToStats">
				<template #icon>
					<ChartBarIcon :size="20" />
				</template>
			</NcButton>

			<!-- Settings (gear) button - toggles the right-docked settings panel -->
			<NcButton
				v-if="boardData"
				class="board-view__settings-btn"
				:title="t('kanso', 'Board settings')"
				:aria-label="t('kanso', 'Board settings')"
				:aria-expanded="showSettings ? 'true' : 'false'"
				@click="showSettings = !showSettings">
				<template #icon>
					<CogIcon :size="20" />
				</template>
			</NcButton>
		</div>

		<!-- Board settings modal (Labels + Sharing tabs) -->
		<BoardSettingsModal
			v-if="showSettings && boardData"
			:board-id="props.id"
			:labels="boardLabels"
			:review-types="boardData.reviewTypes ?? []"
			:acl="boardData.acl ?? []"
			:permissions="boardData.permissions ?? 0"
			:participants="participants.data.value ?? []"
			:current-user-id="currentUserId"
			:stacks="boardData.stacks ?? []"
			:cards="boardData.cards ?? []"
			@close="showSettings = false"
			@leave="showSettings = false" />

		<!-- DnD / shortcut error banner -->
		<div v-if="moveError || shortcutError" class="board-view__move-error">
			{{ moveError || shortcutError }}
			<button class="board-view__move-error-dismiss" @click="dismissActionError">×</button>
		</div>

		<!-- Error -->
		<div v-if="isError" class="board-view__error">
			{{ t('kanso', 'Failed to load board.') }}
		</div>

		<!-- Stacks row (Board view). Kept mounted under v-show so its drag-and-drop
		     monitors stay attached when the List view is showing. -->
		<div v-show="viewMode === 'board'" ref="stacksWrapRef" class="board-view__stacks-wrap">
			<!-- Skeleton stacks on cold load -->
			<template v-if="isLoading">
				<div v-for="n in 3" :key="n" class="stack-skeleton">
					<div class="skeleton-text stack-skeleton__title" />
					<div v-for="m in 3" :key="m" class="skeleton-card" />
				</div>
			</template>

			<!-- Actual stacks -->
			<template v-else-if="boardData">
				<StackColumn
					v-for="stack in sortedStacks"
					:key="stack.id"
					:ref="(el) => registerColumnRef(stack.id, el)"
					:stack="stack"
					:cards="cardsForStack(stack.id)"
					:labels-by-id="labelsById"
					:board-prefix="boardData.board.prefix"
					:new-cards-on-top="boardData.board.newCardsOnTop === true"
					:on-create-card="handleCreateCard"
					:on-delete-stack="handleDeleteStack"
					:on-restore-stack="handleRestoreStack"
					:on-rename-stack="handleRenameStack"
					:on-set-role="handleSetRole"
					:on-set-wip="handleSetWip"
					:on-set-color="handleSetColor"
					:on-card-focus="(cardId) => { focusedCardId = cardId }"
					:on-card-hover="(cardId) => { hoveredCardId = cardId }" />

				<!-- Add stack inline input -->
				<div class="add-stack">
					<form @submit.prevent="submitNewStack">
						<input
							v-model="newStackTitle"
							class="add-stack__input"
							type="text"
							:placeholder="t('kanso', 'Add stack…')"
							:disabled="createStack.isPending.value"
							@keydown.enter.prevent="submitNewStack" />
						<p v-if="stackError" class="add-stack__error">{{ stackError }}</p>
					</form>
				</div>
			</template>
		</div>

		<!-- List view - a virtualized, stack-grouped table over the same filtered
		     cards. Read-oriented: rows open the card modal. -->
		<BoardListView
			v-if="viewMode === 'list' && boardData"
			:stacks="sortedStacks"
			:cards-by-stack="cardsByStack"
			:labels-by-id="labelsById"
			:board-id="props.id" />

		<!-- Timeline (Gantt) view - cards on a date axis by start→due. -->
		<BoardTimelineView
			v-if="viewMode === 'timeline' && boardData"
			:cards="allVisibleCards"
			:board-id="props.id" />

		<!-- Keyboard shortcuts overlay -->
		<NcModal
			v-if="showShortcuts"
			:name="t('kanso', 'Keyboard shortcuts')"
			@close="showShortcuts = false">
			<div class="shortcuts-modal">
				<table class="shortcuts-modal__table">
					<tbody>
						<tr>
							<td class="shortcuts-modal__key"><kbd>↓</kbd> / <kbd>↑</kbd> · <kbd>j</kbd> / <kbd>k</kbd></td>
							<td>{{ t('kanso', 'Navigate cards up / down') }}</td>
						</tr>
						<tr>
							<td class="shortcuts-modal__key"><kbd>→</kbd> / <kbd>←</kbd> · <kbd>l</kbd> / <kbd>h</kbd></td>
							<td>{{ t('kanso', 'Move to next / previous stack') }}</td>
						</tr>
						<tr>
							<td class="shortcuts-modal__key"><kbd>n</kbd></td>
							<td>{{ t('kanso', 'Add new card in focused stack') }}</td>
						</tr>
						<tr>
							<td class="shortcuts-modal__key"><kbd>e</kbd></td>
							<td>{{ t('kanso', 'Open focused card') }}</td>
						</tr>
						<tr>
							<td class="shortcuts-modal__key"><kbd>Space</kbd></td>
							<td>{{ t('kanso', 'Quick preview of the hovered / focused card') }}</td>
						</tr>
						<tr>
							<td class="shortcuts-modal__key"><kbd>d</kbd></td>
							<td>{{ t('kanso', 'Toggle done on focused card') }}</td>
						</tr>
						<tr>
							<td class="shortcuts-modal__key"><kbd>0</kbd>–<kbd>4</kbd></td>
							<td>{{ t('kanso', 'Set priority on focused card (0=None, 1=Low … 4=Urgent)') }}</td>
						</tr>
						<tr>
							<td class="shortcuts-modal__key"><kbd>?</kbd></td>
							<td>{{ t('kanso', 'Show / hide this shortcuts overlay') }}</td>
						</tr>
						<tr>
							<td class="shortcuts-modal__key"><kbd>/</kbd></td>
							<td>{{ t('kanso', 'Focus search') }}</td>
						</tr>
						<tr>
							<td class="shortcuts-modal__key"><kbd>Ctrl</kbd>+<kbd>K</kbd></td>
							<td>{{ t('kanso', 'Open command palette') }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</NcModal>

		<!-- Quick-look card preview (Space on a hovered/focused card).
		     A transparent backdrop captures click-away; the panel itself is
		     read-only and dismisses on Escape / Space / mouse-leave. -->
		<template v-if="previewCard">
			<div class="card-preview-backdrop" @click="closePreview" />
			<CardPreview
				:card="previewCard"
				:labels-by-id="labelsById"
				:board-prefix="boardData?.board?.prefix ?? ''"
				:participants="participants.data.value ?? []"
				:anchor-rect="previewAnchorRect"
				@close="closePreview"
				@open="openPreviewCard" />
		</template>

		<!-- Command palette (Ctrl/Cmd+K) -->
		<CommandPalette
			:open="showCommandPalette"
			@close="showCommandPalette = false" />

		<!-- Child route: CardModal renders over this view -->
		<router-view />
	</div>
</template>

<script setup>
import { ref, computed, reactive, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionCheckbox from '@nextcloud/vue/components/NcActionCheckbox'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionRadio from '@nextcloud/vue/components/NcActionRadio'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import ArchiveIcon from 'vue-material-design-icons/Archive.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import FilterVariantIcon from 'vue-material-design-icons/FilterVariant.vue'
import FilterVariantRemoveIcon from 'vue-material-design-icons/FilterVariantRemove.vue'
import EyeOutlineIcon from 'vue-material-design-icons/EyeOutline.vue'
import EyeOffOutlineIcon from 'vue-material-design-icons/EyeOffOutline.vue'
import ViewColumnIcon from 'vue-material-design-icons/ViewColumn.vue'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'
import SortIcon from 'vue-material-design-icons/Sort.vue'
import ChartTimelineIcon from 'vue-material-design-icons/ChartTimeline.vue'
import ChartBarIcon from 'vue-material-design-icons/ChartBar.vue'
import StackColumn from '../components/StackColumn.vue'
import BoardListView from '../components/BoardListView.vue'
import BoardTimelineView from '../components/BoardTimelineView.vue'
import SearchBox from '../components/SearchBox.vue'
import { PRIORITY_LEVELS } from '../composables/usePriority.js'
import BoardSettingsModal from '../components/BoardSettingsModal.vue'
import CommandPalette from '../components/CommandPalette.vue'
import CardPreview from '../components/CardPreview.vue'
import { useBoard } from '../composables/useBoard.js'
import { useBoardSubscription } from '../composables/useBoardSubscription.js'
import { boardQueryKey } from '../composables/queryKeys.js'
import { useAssignees } from '../composables/useAssignees.js'
import { useCardMove } from '../composables/useCardMove.js'
import { useQueryClient } from '@tanstack/vue-query'
import { cssColor } from '../services/color.js'
import { initial, between, after, before } from '../services/sortKey.js'
import { updateCard as apiUpdateCard, moveStack as apiMoveStack } from '../services/api.js'
import { monitorForElements } from '@atlaskit/pragmatic-drag-and-drop/element/adapter'
import { extractClosestEdge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge'
import { autoScrollForElements } from '@atlaskit/pragmatic-drag-and-drop-auto-scroll/element'
import { combine } from '@atlaskit/pragmatic-drag-and-drop/combine'

const props = defineProps({
	id: {
		type: String,
		required: true,
	},
})

const router = useRouter()
const route = useRoute()
const queryClient = useQueryClient()
const boardId = computed(() => props.id)

// View mode (Board columns / List table / Timeline), persisted per board per user.
const VIEW_MODES = ['board', 'list', 'timeline']
const viewMode = ref('board')
try {
	const saved = localStorage.getItem(`kanso.viewMode.${props.id}`)
	if (saved && VIEW_MODES.includes(saved)) viewMode.value = saved
} catch (e) { /* localStorage unavailable - default to board */ }
function setViewMode(mode) {
	viewMode.value = mode
	try {
		localStorage.setItem(`kanso.viewMode.${props.id}`, mode)
	} catch (e) { /* ignore persistence failure */ }
}
const viewModeLabel = computed(() => ({
	board: t('kanso', 'Board'),
	list: t('kanso', 'List'),
	timeline: t('kanso', 'Timeline'),
}[viewMode.value] ?? t('kanso', 'Board')))

// Display sort - a VIEW-ONLY reorder of how cards render within each stack. Never
// rewrites sort keys; 'manual' is the persisted fractional order. Persisted per
// board per user. While a non-manual sort is active, card drag-reorder is
// suppressed (see the card onDrop guard) so manual order is preserved.
const SORT_MODES = ['manual', 'priority', 'due', 'title']
const sortMode = ref('manual')
try {
	const saved = localStorage.getItem(`kanso.sortMode.${props.id}`)
	if (saved && SORT_MODES.includes(saved)) sortMode.value = saved
} catch (e) { /* default to manual */ }
function setSortMode(mode) {
	sortMode.value = mode
	try {
		localStorage.setItem(`kanso.sortMode.${props.id}`, mode)
	} catch (e) { /* ignore persistence failure */ }
}
const sortModeLabel = computed(() => ({
	manual: t('kanso', 'Manual'),
	priority: t('kanso', 'Priority'),
	due: t('kanso', 'Due date'),
	title: t('kanso', 'Title'),
}[sortMode.value] ?? t('kanso', 'Manual')))

/**
 * View-only comparator for the active display sort. Every non-manual mode falls
 * back to the fractional sort key as a stable tiebreaker.
 */
function sortCards(cards) {
	const arr = [...cards]
	if (sortMode.value === 'priority') {
		return arr.sort((a, b) => (Number(b.priority ?? 0) - Number(a.priority ?? 0)) || bySortKey(a, b))
	}
	if (sortMode.value === 'due') {
		const due = (c) => {
			if (!c.duedate) return Infinity
			const t2 = new Date(c.duedate).getTime()
			return Number.isNaN(t2) ? Infinity : t2
		}
		return arr.sort((a, b) => (due(a) - due(b)) || bySortKey(a, b))
	}
	if (sortMode.value === 'title') {
		return arr.sort((a, b) => String(a.title).localeCompare(String(b.title)) || bySortKey(a, b))
	}
	return arr.sort(bySortKey)
}
const { data: boardData, isLoading, isError, createStack, createCard, updateStack, deleteStack, restoreStack } = useBoard(boardId)
const { enqueueMove, lastError: moveError, dismissError: dismissMoveError } = useCardMove(boardId)
const { toggle: boardWatchToggle } = useBoardSubscription(boardId)
const isBoardSubscribed = computed(() => boardData.value?.subscription?.subscribed ?? false)
function toggleBoardWatch() {
	boardWatchToggle.mutate({ subscribed: !isBoardSubscribed.value })
}
const { participants } = useAssignees(boardId)

// Resolve current Nextcloud user id - OC.getCurrentUser() is always available in NC apps
const currentUserId = (() => {
	try {
		return window.OC?.getCurrentUser?.()?.uid ?? ''
	} catch {
		return ''
	}
})()

const newStackTitle = ref('')
const stackError = ref('')
const stacksWrapRef = ref(null)
let boardCleanup = () => {}

// Label settings panel visibility
const showSettings = ref(false)

// ── Command palette visibility ────────────────────────────────────────────────
const showCommandPalette = ref(false)

// ── Keyboard shortcuts overlay ────────────────────────────────────────────────
const showShortcuts = ref(false)
const shortcutError = ref('')

// ── Search box ref (for programmatic focus via '/' shortcut) ─────────────────
const searchBoxRef = ref(null)
const headerRef = ref(null)

function dismissActionError() {
	dismissMoveError()
	shortcutError.value = ''
}

// ── Keyboard navigation state ─────────────────────────────────────────────────
/** Currently keyboard-focused card id (number | null). */
const focusedCardId = ref(null)

/** Currently mouse-hovered card id (number | null). Feeds the Space quick-preview. */
const hoveredCardId = ref(null)

// ── Quick-look preview state ──────────────────────────────────────────────────
/** Card id the floating preview is open for (number | null). */
const previewCardId = ref(null)
/** Anchor rect (the originating tile's bounding box) for positioning the panel. */
const previewAnchorRect = ref(null)

/** Map<stackId, StackColumn component instance> - populated by function refs. */
const columnRefs = new Map()

function registerColumnRef(stackId, el) {
	if (el) {
		columnRefs.set(stackId, el)
	} else {
		columnRefs.delete(stackId)
	}
}

// ── Label computed helpers ────────────────────────────────────────────────────

/** All board-level labels from the board payload. */
const boardLabels = computed(() => boardData.value?.labels ?? [])

/** Map<id, label> for O(1) lookup by id - passed to StackColumn → CardTile. */
const labelsById = computed(() => {
	const map = new Map()
	for (const label of boardLabels.value) {
		map.set(label.id, label)
	}
	return map
})

// ── Label filter state ────────────────────────────────────────────────────────
// ANY-of semantics: a card passes if it carries at least one of the active
// filter labels. This matches Deck's behaviour - "show me cards with any of
// these selected tags". If no filter is active, all cards are visible.
// Uses a reactive Set so individual .has() calls remain reactive.
const activeFilterIds = reactive(new Set())

// ── Priority filter state ─────────────────────────────────────────────────────
// ANY-of semantics within priority: a card passes if its priority is in the
// selected set. AND is applied across label and priority filters.
const activePriorityLevels = reactive(new Set())

// Total active filter count - used for the filter button badge.
const totalActiveFilters = computed(
	() => activeFilterIds.size + activePriorityLevels.size,
)

const bySortKey = (a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0)

const sortedStacks = computed(() => {
	if (!boardData.value?.stacks) return []
	return [...boardData.value.stacks]
		.filter((s) => !s.archived)
		.sort(bySortKey)
})

/**
 * Archived (but not deleted) cards sourced from the board payload.
 * The board GET already returns archived cards in the cards array - we just
 * need to surface them here. No additional API call is required.
 */
const archivedCards = computed(() =>
	(boardData.value?.cards ?? []).filter((c) => c.archived),
)

const cardsByStack = computed(() => {
	const map = new Map()
	const labelFilterActive = activeFilterIds.size > 0
	const priorityFilterActive = activePriorityLevels.size > 0
	for (const card of boardData.value?.cards ?? []) {
		if (card.archived) continue
		// Label filter (OR within): skip cards that don't carry at least one
		// of the selected filter labels when a label filter is active.
		if (labelFilterActive) {
			const cardLabelIds = Array.isArray(card.labelIds) ? card.labelIds : []
			const passes = cardLabelIds.some((id) => activeFilterIds.has(id))
			if (!passes) continue
		}
		// Priority filter (OR within): skip cards whose priority is not in the
		// selected set. AND'd with the label filter above.
		if (priorityFilterActive) {
			const cardPriority = Number(card.priority ?? 0)
			if (!activePriorityLevels.has(cardPriority)) continue
		}
		if (!map.has(card.stackId)) map.set(card.stackId, [])
		map.get(card.stackId).push(card)
	}
	for (const [stackId, cards] of map) map.set(stackId, sortCards(cards))
	return map
})

// All filtered, non-archived cards flattened - the Timeline view's input.
const allVisibleCards = computed(() => {
	const out = []
	for (const cards of cardsByStack.value.values()) out.push(...cards)
	return out
})

function cardsForStack(stackId) {
	return cardsByStack.value.get(stackId) ?? []
}

// ── Quick-look preview helpers ────────────────────────────────────────────────
// The preview reads its meta straight from the board summary card already in the
// cache; only the description is lazily fetched inside CardPreview via useCard.
const boardCardsById = computed(() => {
	const map = new Map()
	for (const c of boardData.value?.cards ?? []) map.set(c.id, c)
	return map
})

/** The board summary card the preview is open for, or null. */
const previewCard = computed(() =>
	previewCardId.value == null ? null : (boardCardsById.value.get(previewCardId.value) ?? null),
)

/**
 * Toggle the quick-look preview for a given card id. Called from the Space
 * branch in handleKeydown. Capturing the tile's rect anchors the panel; if the
 * tile isn't in the DOM (edge case) the preview still opens, centered.
 */
function togglePreview(cardId) {
	if (cardId == null) return
	if (previewCardId.value === cardId) {
		closePreview()
		return
	}
	const el = document.querySelector(`[data-card-id="${cardId}"]`)
	previewAnchorRect.value = el ? el.getBoundingClientRect() : null
	previewCardId.value = cardId
}

function closePreview() {
	previewCardId.value = null
	previewAnchorRect.value = null
}

/** Open the full card modal for the previewed card, dismissing the preview. */
function openPreviewCard() {
	const id = previewCardId.value
	closePreview()
	if (id == null) return
	router.push({ name: 'card-modal', params: { id: props.id, cardId: id } })
}

// If the previewed card disappears from the board (archived/deleted/filtered),
// close the preview so it can't dangle over a stale rect.
watch(previewCard, (card) => {
	if (previewCardId.value != null && !card) closePreview()
})

// ── Keyboard navigation helpers (declared after cardsByStack + sortedStacks) ──
// NOTE: function declarations are hoisted and can reference these computeds
// safely. Only computed() and watch() calls must follow their dependencies.

/**
 * Derive (stackIdx, cardIdx) for a given cardId from the current sortedStacks
 * + cardsByStack. Returns null when the card is not found (e.g. after archive).
 */
function findCardPosition(cardId) {
	if (cardId == null) return null
	for (let si = 0; si < sortedStacks.value.length; si++) {
		const stack = sortedStacks.value[si]
		const cards = cardsByStack.value.get(stack.id) ?? []
		const ci = cards.findIndex((c) => c.id === cardId)
		if (ci !== -1) return { stackIdx: si, cardIdx: ci }
	}
	return null
}

/** Non-empty stacks in sorted order (for left/right navigation). */
const nonEmptyStacks = computed(() =>
	sortedStacks.value.filter((s) => (cardsByStack.value.get(s.id) ?? []).length > 0),
)

/**
 * After computing target stackId + cardIdx, focus the card:
 * 1. scroll virtualizer to the index
 * 2. wait for nextTick + rAF
 * 3. querySelector and .focus()
 */
let navSeq = 0

async function navigateTo(stackId, cardIdx) {
	const cards = cardsByStack.value.get(stackId) ?? []
	if (!cards.length) return
	const clamped = Math.max(0, Math.min(cardIdx, cards.length - 1))
	const card = cards[clamped]
	focusedCardId.value = card.id
	const col = columnRefs.get(stackId)
	if (col) col.scrollToIndex(clamped)
	// Rapid keypresses race here: only the newest navigation may focus,
	// or an older one resolving late would drag DOM focus backwards.
	const seq = ++navSeq
	await nextTick()
	await new Promise((resolve) => requestAnimationFrame(resolve))
	if (seq !== navSeq) return
	document.querySelector(`[data-card-id="${card.id}"]`)?.focus()
}

// Clear focusedCardId / hoveredCardId when the card disappears from cardsByStack
// (archived, deleted, filtered out). Without this the hovered id could dangle
// after the tile unmounts (no mouseleave fires) and a later Space would target a
// stale id. The previewCard computed also guards, but clearing here keeps the
// hover anchor honest.
watch(cardsByStack, () => {
	if (focusedCardId.value != null && !findCardPosition(focusedCardId.value)) {
		focusedCardId.value = null
	}
	if (hoveredCardId.value != null && !findCardPosition(hoveredCardId.value)) {
		hoveredCardId.value = null
	}
})

function handleKeydown(e) {
	// Guard: composing (IME)
	if (e.isComposing) return
	// Cmd/Ctrl+K → open command palette.
	// Handled BEFORE the modifier-key early-return guard (same technique as '?'
	// and '/' which are handled before the overlay-open guard).
	// Respects the same typing-context guard: don't trigger while the user is
	// typing in an input/textarea/contenteditable.
	if ((e.ctrlKey || e.metaKey) && e.key === 'k' && !e.altKey) {
		const target = e.target
		if (!target.closest('input, textarea, [contenteditable]')) {
			e.preventDefault()
			showCommandPalette.value = !showCommandPalette.value
			return
		}
	}
	// Guard: modifier keys held (but allow Shift for '?')
	if (e.ctrlKey || e.metaKey || e.altKey) return
	// Guard: typing context
	const target = e.target
	if (target.closest('input, textarea, [contenteditable]')) return
	// Guard: card modal child route active
	if (route.name === 'card-modal') return
	// '?' toggles the shortcuts overlay in BOTH directions, so it must be
	// handled before the overlay-open guard below.
	if (e.key === '?') {
		e.preventDefault()
		showShortcuts.value = !showShortcuts.value
		return
	}
	// '/' focuses the search box - handled before the overlay-open guard so it
	// always works as long as no input-like element is already focused.
	if (e.key === '/') {
		e.preventDefault()
		searchBoxRef.value?.focus()
		return
	}

	// ── Quick-look preview (Space) ────────────────────────────────────────────
	// Space peeks the hovered (mouse) or keyboard-focused card in a floating
	// read-only panel. preventDefault stops the board scrolling. The typing guard
	// above has already bailed, so a space typed in the composer still inserts a
	// space. When a preview is already open, Space / Escape close it and Enter
	// opens the full card - handled here, before the overlay-open guard below.
	if (previewCardId.value != null) {
		if (e.key === ' ' || e.key === 'Spacebar' || e.key === 'Escape') {
			e.preventDefault()
			closePreview()
			return
		}
		if (e.key === 'Enter') {
			e.preventDefault()
			openPreviewCard()
			return
		}
	}
	if (e.key === ' ' || e.key === 'Spacebar') {
		e.preventDefault()
		togglePreview(hoveredCardId.value ?? focusedCardId.value)
		return
	}

	// Guard: settings modal or shortcuts overlay open
	if (showSettings.value || showShortcuts.value) return

	// Vim-style aliases for the arrow navigation below. The typing-context
	// guard above has already bailed, so h/j/k/l still type normally in
	// inputs/textareas/contenteditables — here they only alias the arrows.
	const VIM_KEYS = { j: 'ArrowDown', k: 'ArrowUp', l: 'ArrowRight', h: 'ArrowLeft' }
	const key = VIM_KEYS[e.key] ?? e.key

	if (key === 'ArrowDown' || key === 'ArrowUp') {
		e.preventDefault()
		const pos = findCardPosition(focusedCardId.value)
		if (!pos) {
			// Seed to first card of the first non-empty stack
			const first = nonEmptyStacks.value[0]
			if (first) navigateTo(first.id, 0)
			return
		}
		const { stackIdx, cardIdx } = pos
		const stack = sortedStacks.value[stackIdx]
		const cards = cardsByStack.value.get(stack.id) ?? []
		const nextIdx = key === 'ArrowDown'
			? Math.min(cardIdx + 1, cards.length - 1)
			: Math.max(cardIdx - 1, 0)
		navigateTo(stack.id, nextIdx)
		return
	}

	if (key === 'ArrowRight' || key === 'ArrowLeft') {
		e.preventDefault()
		const pos = findCardPosition(focusedCardId.value)
		// Determine current stack index among NON-EMPTY stacks for left/right
		const ne = nonEmptyStacks.value
		if (!ne.length) return
		let neIdx
		if (!pos) {
			neIdx = key === 'ArrowRight' ? 0 : ne.length - 1
		} else {
			const curStackId = sortedStacks.value[pos.stackIdx].id
			neIdx = ne.findIndex((s) => s.id === curStackId)
			if (neIdx === -1) neIdx = 0
			neIdx = key === 'ArrowRight'
				? Math.min(neIdx + 1, ne.length - 1)
				: Math.max(neIdx - 1, 0)
		}
		const targetStack = ne[neIdx]
		const targetCards = cardsByStack.value.get(targetStack.id) ?? []
		// Clamp card index to new stack's length
		const clampedCardIdx = pos ? Math.min(pos.cardIdx, targetCards.length - 1) : 0
		navigateTo(targetStack.id, clampedCardIdx)
		return
	}

	if (key === 'n') {
		e.preventDefault()
		// Focus composer of the focused card's stack, or first stack
		const pos = findCardPosition(focusedCardId.value)
		let stackId
		if (pos) {
			stackId = sortedStacks.value[pos.stackIdx].id
		} else {
			const first = sortedStacks.value[0]
			if (!first) return
			stackId = first.id
		}
		const col = columnRefs.get(stackId)
		if (col) col.focusComposer()
		return
	}

	if (key === 'e') {
		e.preventDefault()
		if (focusedCardId.value == null) return
		router.push({
			name: 'card-modal',
			params: { id: props.id, cardId: focusedCardId.value },
		})
		return
	}

	if (key === 'd') {
		e.preventDefault()
		if (focusedCardId.value == null) return
		const id = focusedCardId.value
		// Look up current done state from cardsByStack cache
		let isDone = false
		outer: for (const cards of cardsByStack.value.values()) {
			for (const c of cards) {
				if (c.id === id) {
					isDone = Number(c.doneAt) > 0
					break outer
				}
			}
		}
		apiUpdateCard(id, { done: !isDone })
			.catch((err) => {
				shortcutError.value =
					err?.response?.data?.error || t('kanso', 'Failed to update the card.')
			})
			.finally(() => {
				queryClient.invalidateQueries({ queryKey: boardQueryKey(props.id) })
			})
		return
	}

	// Keys 1–4 set priority on the focused card (1=Low, 2=Med, 3=High, 4=Urgent).
	// Key 0 clears priority (sets to None). Skip when no card is focused.
	if ((key === '0' || key === '1' || key === '2' || key === '3' || key === '4') && focusedCardId.value != null) {
		e.preventDefault()
		const priority = Number(key)
		const id = focusedCardId.value
		apiUpdateCard(id, { priority })
			.catch((err) => {
				shortcutError.value =
					err?.response?.data?.error || t('kanso', 'Failed to set priority.')
			})
			.finally(() => {
				queryClient.invalidateQueries({ queryKey: boardQueryKey(props.id) })
			})
		return
	}
}

// Publish the toolbar height as a CSS var so the (teleported) settings panel can
// dock BELOW the toolbar instead of over it — otherwise the panel covers the gear
// button and a second gear click can't toggle it closed.
let toolbarResizeObserver = null
function publishToolbarHeight() {
	const h = headerRef.value ? headerRef.value.offsetHeight : 0
	document.documentElement.style.setProperty('--kanso-board-toolbar-height', `${h}px`)
}

onMounted(() => {
	document.addEventListener('keydown', handleKeydown)

	if (headerRef.value && typeof ResizeObserver !== 'undefined') {
		toolbarResizeObserver = new ResizeObserver(publishToolbarHeight)
		toolbarResizeObserver.observe(headerRef.value)
	}
	publishToolbarHeight()

	const cleanups = [
		monitorForElements({
			canMonitor: ({ source }) => source.data.type === 'card',
			onDrop({ source, location }) {
				// A non-manual display sort is view-only - dropping must not rewrite
				// the fractional order, so ignore card drops until Manual is active.
				if (sortMode.value !== 'manual') return
				const { cardId, stackId: sourceStackId } = source.data

				// Walk drop targets innermost-first to find what we landed on
				const targets = location.current.dropTargets
				if (!targets.length) return

				let targetStackId = null
				let afterCardId = null
				let optimisticKey = null

				// Find card-level target (innermost) and column-level target
				const cardTarget = targets.find((t) => t.data.type === 'card')
				const columnTarget = targets.find((t) => t.data.type === 'column')

				if (cardTarget) {
					const edge = extractClosestEdge(cardTarget.data)
					const targetCardId = cardTarget.data.cardId
					const targetStackId2 = cardTarget.data.stackId
					targetStackId = targetStackId2

					// Resolve neighbors as if the dragged card were already
					// removed - otherwise dropping on the top edge of the card
					// below yields the dragged card as its own anchor (400).
					const stackCards = (cardsByStack.value.get(targetStackId2) ?? [])
						.filter((c) => c.id !== cardId)
					const targetIdx = stackCards.findIndex((c) => c.id === targetCardId)
					const targetCard = stackCards[targetIdx]

					if (!targetCard) return // stale, or dropped onto itself

					if (edge === 'top') {
						// Insert before targetCard
						const prevCard = targetIdx > 0 ? stackCards[targetIdx - 1] : null
						afterCardId = prevCard?.id ?? null
						try {
							optimisticKey = prevCard
								? between(prevCard.sortKey, targetCard.sortKey)
								: before(targetCard.sortKey)
						} catch {
							// Keys too close or overflow; fall back to server truth via invalidation
							optimisticKey = targetCard.sortKey // will be fixed on reconcile
						}
					} else {
						// Insert after targetCard (bottom edge)
						const nextCard = targetIdx < stackCards.length - 1 ? stackCards[targetIdx + 1] : null
						afterCardId = targetCard.id
						try {
							optimisticKey = nextCard
								? between(targetCard.sortKey, nextCard.sortKey)
								: after(targetCard.sortKey)
						} catch {
							optimisticKey = targetCard.sortKey
						}
					}
				} else if (columnTarget) {
					// Drop on empty column space → append to end (excluding the
					// dragged card so it can't become its own anchor)
					targetStackId = columnTarget.data.stackId
					const stackCards = (cardsByStack.value.get(targetStackId) ?? [])
						.filter((c) => c.id !== cardId)
					const lastCard = stackCards.length > 0 ? stackCards[stackCards.length - 1] : null
					afterCardId = lastCard?.id ?? null
					try {
						optimisticKey = lastCard ? after(lastCard.sortKey) : initial()
					} catch {
						optimisticKey = initial()
					}
				} else {
					return // No valid target
				}

				// No-op guard: check if card is already in this position
				if (targetStackId === sourceStackId) {
					const stackCards = cardsByStack.value.get(targetStackId) ?? []
					const draggedIdx = stackCards.findIndex((c) => c.id === cardId)
					const cardBefore = draggedIdx > 0 ? stackCards[draggedIdx - 1] : null
					const currentAfterCardId = cardBefore?.id ?? null
					if (currentAfterCardId === afterCardId) return // already in this position
				}

				enqueueMove({ cardId, targetStackId, afterCardId, optimisticKey })
			},
		}),
		// Stack reordering: header-dragged columns dropped on another column's
		// left/right edge. Single-flight plain optimistic patch (no queue) -
		// stack moves are rare compared to card moves.
		monitorForElements({
			canMonitor: ({ source }) => source.data.type === 'stack',
			onDrop({ source, location }) {
				const draggedStackId = source.data.stackId

				const stackTarget = location.current.dropTargets.find((t) => t.data.type === 'stack')
				if (!stackTarget) return

				const edge = extractClosestEdge(stackTarget.data)
				const targetStackId = stackTarget.data.stackId

				// Resolve neighbours as if the dragged stack were already removed -
				// otherwise dropping on the near edge of an adjacent column yields
				// the dragged stack as its own anchor (400).
				const stacks = sortedStacks.value.filter((s) => s.id !== draggedStackId)
				const targetIdx = stacks.findIndex((s) => s.id === targetStackId)
				const targetStack = stacks[targetIdx]
				if (!targetStack) return // stale, or dropped onto itself

				// left edge → land before target (after its predecessor);
				// right edge → land after the target itself.
				const afterStack = edge === 'left'
					? (targetIdx > 0 ? stacks[targetIdx - 1] : null)
					: targetStack
				const afterStackId = afterStack?.id ?? null

				// No-op guard: already directly after that anchor
				const all = sortedStacks.value
				const draggedIdx = all.findIndex((s) => s.id === draggedStackId)
				if (draggedIdx === -1) return
				const currentAfterId = draggedIdx > 0 ? all[draggedIdx - 1].id : null
				if (currentAfterId === afterStackId) return

				// Optimistic client-side sort key, mirroring the card path
				let optimisticKey
				try {
					if (afterStack === null) {
						optimisticKey = stacks.length > 0 ? before(stacks[0].sortKey) : initial()
					} else if (edge === 'left') {
						optimisticKey = between(afterStack.sortKey, targetStack.sortKey)
					} else {
						const nextStack = targetIdx < stacks.length - 1 ? stacks[targetIdx + 1] : null
						optimisticKey = nextStack
							? between(targetStack.sortKey, nextStack.sortKey)
							: after(targetStack.sortKey)
					}
				} catch {
					// Keys too close or overflow; server truth arrives on reconcile
					optimisticKey = targetStack.sortKey
				}

				const key = boardQueryKey(props.id)
				const patchStackKey = (sortKey) => {
					queryClient.setQueryData(key, (old) => {
						if (!old) return old
						return {
							...old,
							stacks: old.stacks.map((s) =>
								s.id === draggedStackId ? { ...s, sortKey } : s,
							),
						}
					})
				}

				// Cancel in-flight board fetches so they can't clobber the patch
				queryClient.cancelQueries({ queryKey: key })
				// Snapshot the prior board so a failed move reverts immediately
				// (mirrors the snapshot-in-onMutate → restore-in-onError pattern),
				// rather than waiting on a refetch round-trip.
				const previousBoard = queryClient.getQueryData(key)
				patchStackKey(optimisticKey)

				apiMoveStack(draggedStackId, afterStackId)
					.then((updated) => {
						patchStackKey(updated.sortKey)
					})
					.catch((err) => {
						const serverError = err?.response?.data?.error
						shortcutError.value = serverError === 'rebalance_required'
							? t('kanso', 'Board ordering needs a refresh.')
							: t('kanso', 'Failed to move stack. Please try again.')
						if (previousBoard !== undefined) {
							queryClient.setQueryData(key, previousBoard)
						}
						queryClient.invalidateQueries({ queryKey: key })
					})
			},
		}),
	]

	// Auto-scroll the horizontal stacks container
	if (stacksWrapRef.value) {
		cleanups.push(
			autoScrollForElements({
				element: stacksWrapRef.value,
			}),
		)
	}

	boardCleanup = combine(...cleanups)
})

onUnmounted(() => {
	document.removeEventListener('keydown', handleKeydown)
	boardCleanup()
	if (toolbarResizeObserver) {
		toolbarResizeObserver.disconnect()
		toolbarResizeObserver = null
	}
	document.documentElement.style.removeProperty('--kanso-board-toolbar-height')
})

function toggleFilterLabel(labelId) {
	if (activeFilterIds.has(labelId)) {
		activeFilterIds.delete(labelId)
	} else {
		activeFilterIds.add(labelId)
	}
}

function toggleFilterPriority(level) {
	if (activePriorityLevels.has(level)) {
		activePriorityLevels.delete(level)
	} else {
		activePriorityLevels.add(level)
	}
}

function clearAllFilters() {
	activeFilterIds.clear()
	activePriorityLevels.clear()
}

function goBack() {
	router.push({ name: 'board-list' })
}

function goToStats() {
	router.push({ name: 'board-stats', params: { id: props.id } })
}

function goToArchived() {
	router.push({ name: 'board-archived', params: { id: props.id } })
}

function goToTrash() {
	router.push({ name: 'board-trash', params: { id: props.id } })
}

async function submitNewStack() {
	const title = newStackTitle.value.trim()
	if (!title) return
	stackError.value = ''
	try {
		await createStack.mutateAsync({ boardId: Number(props.id), title })
		newStackTitle.value = ''
	} catch (err) {
		stackError.value =
			err?.response?.data?.error || t('kanso', 'Failed to create stack.')
	}
}

async function handleCreateCard(stackId, title) {
	await createCard.mutateAsync({ stackId, title })
}

async function handleDeleteStack(stackId) {
	await deleteStack.mutateAsync(stackId)
}

async function handleRestoreStack(stackId) {
	await restoreStack.mutateAsync(stackId)
}

async function handleRenameStack(stackId, title) {
	await updateStack.mutateAsync({ stackId, data: { title } })
}

async function handleSetRole(stackId, role) {
	await updateStack.mutateAsync({ stackId, data: { role } })
}

async function handleSetWip(stackId, wipLimit) {
	await updateStack.mutateAsync({ stackId, data: { wipLimit } })
}

async function handleSetColor(stackId, color) {
	await updateStack.mutateAsync({ stackId, data: { color } })
}
</script>

<style scoped>
.board-view {
	display: flex;
	flex-direction: column;
	height: 100%;
	overflow: hidden;
}

.board-view__header {
	display: flex;
	align-items: center;
	gap: 16px;
	/* Extra left padding reserves room for the NcAppNavigation toggle, which is
	   pinned to the top-left of the app content area and would otherwise overlap
	   the "All boards" button. */
	padding: 12px 24px 12px 52px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-main-background);
	flex-shrink: 0;
	/* Sit above the right-docked settings panel (z-index 1800) so the toolbar
	   buttons (incl. the gear that toggles the panel) stay clickable and the
	   opaque header visually caps the panel below it. */
	position: relative;
	z-index: 1801;
}

.board-view__back {
	flex-shrink: 0;
}

.board-view__title {
	display: flex;
	align-items: center;
	gap: 10px;
	font-size: 1.2rem;
	font-weight: 700;
	color: var(--color-main-text);
	margin: 0;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.board-view__color-dot {
	flex-shrink: 0;
	width: 14px;
	height: 14px;
	border-radius: 50%;
}

.board-view__title-skeleton {
	width: 200px;
	height: 20px;
	border-radius: 4px;
}

/* Search box - pushed to the right edge of the title area via margin-left: auto */
.board-view__search {
	margin-left: auto;
	flex-shrink: 0;
}

/* Filter dropdown button - sits after the search box */
.board-view__filter-menu {
	flex-shrink: 0;
}

/* Color dot injected as a ::before pseudo-element on the NcActionCheckbox text span.
   NcActionCheckbox (NC Vue 9) only accepts plain text in its default slot (the slot
   content is extracted with getText() as a string); rich HTML is silently dropped.
   Instead we set --filter-dot-color on each NcActionCheckbox root and draw the dot
   via :deep() targeting the inner .action-checkbox__text span.

   The dot is a true circle: width == height, border-radius:50%, flex-shrink is N/A
   because it uses display:inline-block with explicit fixed dimensions. */
.board-view__filter-label-item:deep(.action-checkbox__text)::before {
	content: '';
	display: inline-block;
	width: 12px;
	height: 12px;
	min-width: 12px;
	border-radius: 50%;
	background: var(--filter-dot-color, var(--color-border));
	margin-right: 6px;
	vertical-align: middle;
	flex-shrink: 0;
}

/* Priority filter items - color-coded dot via ::before, mirroring label dot pattern */
.board-view__filter-priority-item--1:deep(.action-checkbox__text)::before {
	content: '';
	display: inline-block;
	width: 12px;
	height: 12px;
	min-width: 12px;
	border-radius: 50%;
	background: #888;
	margin-right: 6px;
	vertical-align: middle;
}

.board-view__filter-priority-item--2:deep(.action-checkbox__text)::before {
	content: '';
	display: inline-block;
	width: 12px;
	height: 12px;
	min-width: 12px;
	border-radius: 50%;
	background: var(--color-primary-element, #0082c9);
	margin-right: 6px;
	vertical-align: middle;
}

.board-view__filter-priority-item--3:deep(.action-checkbox__text)::before {
	content: '';
	display: inline-block;
	width: 12px;
	height: 12px;
	min-width: 12px;
	border-radius: 50%;
	background: #e07b00;
	margin-right: 6px;
	vertical-align: middle;
}

.board-view__filter-priority-item--4:deep(.action-checkbox__text)::before {
	content: '';
	display: inline-block;
	width: 12px;
	height: 12px;
	min-width: 12px;
	border-radius: 50%;
	background: var(--color-error, #e30000);
	margin-right: 6px;
	vertical-align: middle;
}

/* Settings gear button */
.board-view__settings-btn {
	flex-shrink: 0;
	margin-left: 4px;
}

.board-view__error {
	padding: 16px 24px;
	color: var(--color-error);
}

.board-view__move-error {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 24px;
	background: rgba(var(--color-error-rgb, 227, 0, 0), 0.1);
	color: var(--color-error);
	font-size: 0.875rem;
	flex-shrink: 0;
}

.board-view__move-error-dismiss {
	background: none;
	border: none;
	color: var(--color-error);
	cursor: pointer;
	font-size: 1.2rem;
	line-height: 1;
	padding: 0 4px;
}

/* Stacks scrollable row */
.board-view__stacks-wrap {
	display: flex;
	flex-direction: row;
	align-items: flex-start;
	gap: 16px;
	padding: 20px 24px;
	overflow-x: auto;
	overflow-y: hidden;
	flex: 1;
}

/* Skeleton stacks */
.stack-skeleton {
	flex-shrink: 0;
	width: 280px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.stack-skeleton__title {
	height: 18px;
	width: 60%;
	border-radius: 4px;
	margin-bottom: 4px;
}

.skeleton-card {
	height: 52px;
	border-radius: var(--border-radius);
	background: linear-gradient(90deg, var(--color-border) 25%, var(--color-background-hover) 50%, var(--color-border) 75%);
	background-size: 400px 100%;
	animation: shimmer 1.4s infinite linear;
}

@keyframes shimmer {
	0% { background-position: -400px 0; }
	100% { background-position: 400px 0; }
}

.skeleton-text {
	background: linear-gradient(90deg, var(--color-border) 25%, var(--color-background-hover) 50%, var(--color-border) 75%);
	background-size: 400px 100%;
	animation: shimmer 1.4s infinite linear;
}

/* Add stack */
.add-stack {
	flex-shrink: 0;
	width: 240px;
}

.add-stack__input {
	width: 100%;
	height: 36px;
	padding: 0 12px;
	border: 2px dashed var(--color-border);
	border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.875rem;
	transition: border-color 0.15s ease;
}

.add-stack__input:focus {
	outline: none;
	border-color: var(--color-primary);
	border-style: solid;
}

.add-stack__error {
	color: var(--color-error);
	font-size: 0.8rem;
	margin: 4px 0 0;
}

/* Keyboard shortcuts modal */
.shortcuts-modal {
	padding: 16px 24px 24px;
}

.shortcuts-modal__table {
	width: 100%;
	border-collapse: collapse;
}

.shortcuts-modal__table tr + tr td {
	padding-top: 10px;
}

.shortcuts-modal__key {
	width: 120px;
	padding-right: 16px;
	white-space: nowrap;
	vertical-align: top;
}

.shortcuts-modal__key kbd {
	display: inline-block;
	padding: 2px 7px;
	font-size: 0.8rem;
	font-family: monospace;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: 4px;
	color: var(--color-main-text);
}

/* Archived cards badge button */
.board-view__archived-btn {
	flex-shrink: 0;
}

/* Trash button */
.board-view__trash-btn {
	flex-shrink: 0;
}

/* Quick-look preview click-away backdrop - transparent, sits just under the
   panel (panel z-index 2100) and above the board content. */
.card-preview-backdrop {
	position: fixed;
	inset: 0;
	z-index: 2099;
	background: transparent;
}
</style>
