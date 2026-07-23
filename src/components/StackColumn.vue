<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div ref="columnRef" class="stack-column" :class="{ 'stack-column--dragging': isStackDragging }">
		<!-- Left / right stack drop indicators — same visual language as the card tile drop line -->
		<div v-if="stackDropEdge === 'left'" class="stack-column__drop-line stack-column__drop-line--left" />
		<div v-if="stackDropEdge === 'right'" class="stack-column__drop-line stack-column__drop-line--right" />

		<!-- Column header — drag handle for stack reordering -->
		<div ref="headerRef" class="stack-column__header">
			<span class="stack-column__title">{{ stack.title }}</span>
			<span class="stack-column__badge">{{ cards.length }}</span>
		</div>

		<!-- Inline card composer at TOP — signature rapid-entry UX -->
		<form class="card-composer" @submit.prevent="submitCard">
			<input
				ref="composerInputRef"
				v-model="newCardTitle"
				class="card-composer__input"
				type="text"
				:placeholder="t('kanso', 'Add card…')"
				:disabled="isPending"
				@keydown.enter.prevent="submitCard" />
			<p v-if="composerError" class="card-composer__error">{{ composerError }}</p>
		</form>

		<!--
			Card list — own scrollable element.
			This element is both the scroll container for TanStack Virtual and the
			column-level drop target / auto-scroll target.
		-->
		<div
			ref="cardListRef"
			class="stack-column__cards"
			:class="{ 'stack-column__cards--drop-over': isDropOver && cards.length === 0 }">

			<!--
				Empty stack placeholder — always present when there are no cards.
				Rendered ALONGSIDE (not instead of) the virtual-host so the scroll
				container keeps its height during the 0→1 card transition and the
				virtualizer never loses its scrollRect.
			-->
			<div v-if="cards.length === 0" class="stack-column__empty-placeholder" />

			<!--
				Virtualized list: always in the DOM (no v-if guard) so that the
				virtualizer's ResizeObserver is never detached and reattached during
				the 0→1 card transition. When count=0 the virtual-host has height 0
				and renders no items — the placeholder above fills the space.
			-->
			<div
				class="stack-column__virtual-host"
				:style="{ height: virtualizer.getTotalSize() + 'px' }">
				<div
					v-for="vRow in virtualizer.getVirtualItems()"
					:key="cards[vRow.index].id"
					:ref="(el) => measureVirtualEl(el, vRow.index)"
					:data-index="vRow.index"
					class="stack-column__virtual-item"
					:style="{
						position: 'absolute',
						top: 0,
						left: 0,
						width: '100%',
						transform: 'translateY(' + vRow.start + 'px)',
					}">
					<CardTile
						:card="cards[vRow.index]"
						:labels-by-id="labelsById"
						@click="openCard(cards[vRow.index].id)" />
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import CardTile from './CardTile.vue'
import { draggable, dropTargetForElements } from '@atlaskit/pragmatic-drag-and-drop/element/adapter'
import { combine } from '@atlaskit/pragmatic-drag-and-drop/combine'
import { autoScrollForElements } from '@atlaskit/pragmatic-drag-and-drop-auto-scroll/element'
import { attachClosestEdge, extractClosestEdge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge'
import { useVirtualizer } from '@tanstack/vue-virtual'

const props = defineProps({
	stack: {
		type: Object,
		required: true,
	},
	cards: {
		type: Array,
		default: () => [],
	},
	/** Async fn (stackId, title) → Promise — provided by parent BoardView */
	onCreateCard: {
		type: Function,
		required: true,
	},
	/** Map<labelId, label> from the board payload — passed down from BoardView */
	labelsById: {
		type: Map,
		default: () => new Map(),
	},
	/**
	 * Optional callback (cardId: number) → void — called when a card tile is
	 * clicked so BoardView can keep focusedCardId in sync with mouse navigation.
	 */
	onCardFocus: {
		type: Function,
		default: null,
	},
})

const router = useRouter()
const route = useRoute()

const composerInputRef = ref(null)
const cardListRef = ref(null)
const columnRef = ref(null)
const headerRef = ref(null)
const newCardTitle = ref('')
const composerError = ref('')
const isPending = ref(false)
const isDropOver = ref(false)
const isStackDragging = ref(false)
const stackDropEdge = ref(null)
let cleanup = () => {}

// ── TanStack Virtual ──────────────────────────────────────────────────────────
// Pass options as a computed so that cardListRef.value is in the reactive
// dependency graph — when the scroll element mounts, the virtualizer's internal
// watch sees the change and calls _willUpdate() to initialize scroll tracking.
const virtualizerOptions = computed(() => ({
	count: props.cards.length,
	// Reading cardListRef.value inside this computed means Vue will re-evaluate
	// the computed (and re-run the virtualizer's internal watch on getScrollElement)
	// when cardListRef changes from null → DOM element after mount.
	getScrollElement: () => cardListRef.value,
	estimateSize: () => 90,
	overscan: 6,
	gap: 8,
	// Key the size cache by card id, not index: on a same-length reorder an
	// index-keyed cache would briefly apply the old occupant's height to the
	// new one, jumping the scroll position.
	getItemKey: (index) => props.cards[index]?.id ?? index,
}))

const virtualizer = useVirtualizer(virtualizerOptions)

// Belt-and-suspenders: explicitly call _willUpdate when the scroll element
// appears (covers any edge case where the computed re-evaluation is batched
// after the virtualizer's internal watch fires).
watch(cardListRef, (el) => {
	if (el) virtualizer.value._willUpdate()
})

/**
 * Function-ref for each virtual item wrapper.
 * TanStack Virtual's measureElement expects the DOM element so it can attach
 * a ResizeObserver for dynamic-height tracking.
 */
function measureVirtualEl(el, index) {
	if (!el) return
	virtualizer.value.measureElement(el)
}

// ── Drop targets & auto-scroll ────────────────────────────────────────────────
onMounted(() => {
	if (!cardListRef.value) return
	cleanup = combine(
		// Column-level drop target: catches drops on the empty space below all cards
		dropTargetForElements({
			element: cardListRef.value,
			canDrop: ({ source }) => source.data.type === 'card',
			getData: () => ({ type: 'column', stackId: props.stack.id }),
			onDragEnter: () => { isDropOver.value = true },
			onDragLeave: () => { isDropOver.value = false },
			onDrop: () => { isDropOver.value = false },
		}),
		// Auto-scroll the card list when dragging near the edges
		autoScrollForElements({
			element: cardListRef.value,
		}),
		// Stack reordering: only the header is the drag handle …
		draggable({
			element: headerRef.value,
			getInitialData: () => ({ type: 'stack', stackId: props.stack.id }),
			onDragStart: () => { isStackDragging.value = true },
			onDrop: () => { isStackDragging.value = false },
		}),
		// … but the whole column is the drop target (left/right edges).
		dropTargetForElements({
			element: columnRef.value,
			canDrop: ({ source }) => source.data.type === 'stack' && source.data.stackId !== props.stack.id,
			getData: ({ input, element: el }) => attachClosestEdge(
				{ type: 'stack', stackId: props.stack.id },
				{ input, element: el, allowedEdges: ['left', 'right'] },
			),
			onDrag: ({ self }) => {
				stackDropEdge.value = extractClosestEdge(self.data)
			},
			onDragLeave: () => { stackDropEdge.value = null },
			onDrop: () => { stackDropEdge.value = null },
		}),
	)
})

onUnmounted(() => {
	cleanup()
})

function openCard(cardId) {
	// Notify BoardView so keyboard focusedCardId stays in sync with mouse clicks
	props.onCardFocus?.(cardId)
	router.push({
		name: 'card-modal',
		params: { id: route.params.id, cardId },
	})
}

// ── Exposed API for BoardView keyboard navigation ─────────────────────────────

/**
 * Scroll the virtualizer to the given card index and return after layout.
 * BoardView calls this before focusing the tile DOM element.
 */
function scrollToIndex(index) {
	virtualizer.value.scrollToIndex(index, { align: 'auto' })
}

/**
 * Focus the inline card composer input for rapid card creation.
 */
function focusComposer() {
	composerInputRef.value?.focus()
}

defineExpose({ scrollToIndex, focusComposer })

async function submitCard() {
	const title = newCardTitle.value.trim()
	if (!title) return
	composerError.value = ''
	isPending.value = true
	try {
		await props.onCreateCard(props.stack.id, title)
		newCardTitle.value = ''
		// Re-focus for rapid entry — the signature UX
		composerInputRef.value?.focus()
	} catch (err) {
		composerError.value =
			err?.response?.data?.error || t('kanso', 'Failed to create card.')
	} finally {
		isPending.value = false
	}
}
</script>

<style scoped>
.stack-column {
	position: relative;
	flex-shrink: 0;
	width: 280px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	max-height: calc(100vh - 140px);
}

.stack-column--dragging {
	opacity: 0.4;
}

/* Stack drop indicators — same visual language as .card-tile__drop-line */
.stack-column__drop-line {
	position: absolute;
	top: 0;
	bottom: 0;
	width: 2px;
	background: var(--color-primary-element);
	border-radius: 1px;
	z-index: 10;
	pointer-events: none;
}

.stack-column__drop-line--left {
	left: -9px;
}

.stack-column__drop-line--right {
	right: -9px;
}

.stack-column__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding-bottom: 8px;
	border-bottom: 1px solid var(--color-border);
	cursor: grab;
}

.stack-column__title {
	font-weight: 600;
	font-size: 0.95rem;
	color: var(--color-main-text);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	min-width: 0;
}

.stack-column__badge {
	flex-shrink: 0;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 20px;
	height: 20px;
	padding: 0 6px;
	border-radius: 10px;
	background: var(--color-border);
	color: var(--color-text-maxcontrast);
	font-size: 0.75rem;
	font-weight: 600;
}

/* Card composer */
.card-composer {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.card-composer__input {
	width: 100%;
	height: 34px;
	padding: 0 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.875rem;
	transition: border-color 0.15s ease;
}

.card-composer__input:focus {
	outline: none;
	border-color: var(--color-primary);
}

.card-composer__error {
	color: var(--color-error);
	font-size: 0.75rem;
	margin: 0;
}

/* Card list — own scrollable element */
.stack-column__cards {
	overflow-y: auto;
	flex: 1;
	min-height: 0;
	transition: background 0.15s ease;
	/* No flex gap here — gap is handled by the virtualizer's gap option so the
	   measurer sees the true inter-item spacing. */
}

.stack-column__cards--drop-over {
	background: rgba(var(--color-primary-element-rgb, 0, 130, 201), 0.06);
	border-radius: var(--border-radius);
}

/* Virtual host: sized to totalSize so the scroll track is correct */
.stack-column__virtual-host {
	position: relative;
	width: 100%;
}

/* Each absolutely-positioned virtual row wrapper */
.stack-column__virtual-item {
	/* height is dynamic — measured by TanStack Virtual per item */
}

.stack-column__empty-placeholder {
	min-height: 48px;
	border-radius: var(--border-radius);
}
</style>
