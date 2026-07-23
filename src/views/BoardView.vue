<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="board-view">
		<!-- Header -->
		<div class="board-view__header">
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

			<!-- Label filter chips — rendered after board loads -->
			<div v-if="boardData && boardLabels.length" class="board-view__label-filter" role="group" :aria-label="t('kanso', 'Filter by label')">
				<button
					v-for="label in boardLabels"
					:key="label.id"
					class="board-view__filter-chip"
					:class="{ 'board-view__filter-chip--active': activeFilterIds.has(label.id) }"
					:style="label.color ? { '--chip-color': cssColor(label.color) } : {}"
					:aria-pressed="activeFilterIds.has(label.id)"
					@click="toggleFilterLabel(label.id)">
					{{ label.title }}
				</button>
				<button
					v-if="activeFilterIds.size > 0"
					class="board-view__filter-clear"
					:aria-label="t('kanso', 'Clear label filter')"
					@click="activeFilterIds.clear()">
					{{ t('kanso', 'Clear') }}
				</button>
			</div>

			<!-- Settings (gear) button -->
			<NcButton
				v-if="boardData"
				class="board-view__settings-btn"
				:title="t('kanso', 'Board settings')"
				:aria-label="t('kanso', 'Board settings')"
				@click="showSettings = true">
				<template #icon>
					<CogIcon :size="20" />
				</template>
			</NcButton>
		</div>

		<!-- Label settings panel -->
		<LabelSettingsPanel
			v-if="showSettings && boardData"
			:board-id="props.id"
			:labels="boardLabels"
			@close="showSettings = false" />

		<!-- DnD error banner -->
		<div v-if="moveError" class="board-view__move-error">
			{{ moveError }}
			<button class="board-view__move-error-dismiss" @click="dismissMoveError">×</button>
		</div>

		<!-- Error -->
		<div v-if="isError" class="board-view__error">
			{{ t('kanso', 'Failed to load board.') }}
		</div>

		<!-- Stacks row -->
		<div ref="stacksWrapRef" class="board-view__stacks-wrap">
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
					:stack="stack"
					:cards="cardsForStack(stack.id)"
					:labels-by-id="labelsById"
					:on-create-card="handleCreateCard" />

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

		<!-- Child route: CardModal renders over this view -->
		<router-view />
	</div>
</template>

<script setup>
import { ref, computed, reactive, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import StackColumn from '../components/StackColumn.vue'
import LabelSettingsPanel from '../components/LabelSettingsPanel.vue'
import { useBoard } from '../composables/useBoard.js'
import { useCardMove } from '../composables/useCardMove.js'
import { cssColor } from '../services/color.js'
import { initial, between, after, before } from '../services/sortKey.js'
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
const boardId = computed(() => props.id)
const { data: boardData, isLoading, isError, createStack, createCard } = useBoard(boardId)
const { enqueueMove, lastError: moveError, dismissError: dismissMoveError } = useCardMove(boardId)

const newStackTitle = ref('')
const stackError = ref('')
const stacksWrapRef = ref(null)
let boardCleanup = () => {}

// Label settings panel visibility
const showSettings = ref(false)

// ── Label computed helpers ────────────────────────────────────────────────────

/** All board-level labels from the board payload. */
const boardLabels = computed(() => boardData.value?.labels ?? [])

/** Map<id, label> for O(1) lookup by id — passed to StackColumn → CardTile. */
const labelsById = computed(() => {
	const map = new Map()
	for (const label of boardLabels.value) {
		map.set(label.id, label)
	}
	return map
})

// ── Label filter state ────────────────────────────────────────────────────────
// ANY-of semantics: a card passes if it carries at least one of the active
// filter labels. This matches Deck's behaviour — "show me cards with any of
// these selected tags". If no filter is active, all cards are visible.
// Uses a reactive Set so individual .has() calls remain reactive.
const activeFilterIds = reactive(new Set())

const bySortKey = (a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0)

const sortedStacks = computed(() => {
	if (!boardData.value?.stacks) return []
	return [...boardData.value.stacks]
		.filter((s) => !s.archived)
		.sort(bySortKey)
})

const cardsByStack = computed(() => {
	const map = new Map()
	const filterActive = activeFilterIds.size > 0
	for (const card of boardData.value?.cards ?? []) {
		if (card.archived) continue
		// Label filter: ANY-of semantics — skip cards that don't carry at least
		// one of the selected filter labels when a filter is active.
		if (filterActive) {
			const cardLabelIds = Array.isArray(card.labelIds) ? card.labelIds : []
			const passes = cardLabelIds.some((id) => activeFilterIds.has(id))
			if (!passes) continue
		}
		if (!map.has(card.stackId)) map.set(card.stackId, [])
		map.get(card.stackId).push(card)
	}
	for (const cards of map.values()) cards.sort(bySortKey)
	return map
})

function cardsForStack(stackId) {
	return cardsByStack.value.get(stackId) ?? []
}

onMounted(() => {
	const cleanups = [
		monitorForElements({
			canMonitor: ({ source }) => source.data.type === 'card',
			onDrop({ source, location }) {
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
					// removed — otherwise dropping on the top edge of the card
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
	boardCleanup()
})

function toggleFilterLabel(labelId) {
	if (activeFilterIds.has(labelId)) {
		activeFilterIds.delete(labelId)
	} else {
		activeFilterIds.add(labelId)
	}
}

function goBack() {
	router.push({ name: 'board-list' })
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
	padding: 12px 24px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-main-background);
	flex-shrink: 0;
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

/* Label filter chips */
.board-view__label-filter {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
	margin-left: auto;
}

.board-view__filter-chip {
	display: inline-flex;
	align-items: center;
	height: 26px;
	padding: 0 10px;
	border-radius: 13px;
	border: 2px solid var(--chip-color, var(--color-border));
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.75rem;
	font-weight: 600;
	cursor: pointer;
	transition: background 0.15s ease, color 0.15s ease;
	white-space: nowrap;
}

.board-view__filter-chip:hover {
	background: color-mix(in srgb, var(--chip-color, var(--color-primary)) 15%, transparent);
}

.board-view__filter-chip--active {
	background: var(--chip-color, var(--color-primary));
	color: #fff;
	border-color: var(--chip-color, var(--color-primary));
}

.board-view__filter-clear {
	height: 26px;
	padding: 0 10px;
	border-radius: 13px;
	border: 1px solid var(--color-border);
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 0.75rem;
	cursor: pointer;
	transition: color 0.15s ease;
}

.board-view__filter-clear:hover {
	color: var(--color-main-text);
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
</style>
