<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="swimlane" :class="{ 'swimlane--empty': isEmpty }">
		<!-- Lane header: the grouping value (assignee / label / priority level). -->
		<header class="swimlane__header">
			<span
				v-if="lane.color"
				class="swimlane__color-dot"
				:style="{ background: cssColor(lane.color) }" />
			<h2 class="swimlane__title">{{ lane.title }}</h2>
			<span class="swimlane__count">{{ laneCardCount }}</span>
		</header>

		<!-- Stacks row for this lane. Each StackColumn keeps its own TanStack
		     Virtual instance, so virtualization is preserved per lane per column.
		     Stack drag/rename/settings are intentionally omitted inside lanes —
		     lanes are a view over a shared board; only card DnD survives here. -->
		<div class="swimlane__stacks">
			<StackColumn
				v-for="stack in stacks"
				:key="lane.key + ':' + stack.id"
				:ref="(el) => registerColumnRef(lane.key, stack.id, el)"
				:stack="stack"
				:cards="cardsForStack(stack.id)"
				:labels-by-id="labelsById"
				:board-prefix="boardPrefix"
				:lane-key="lane.key"
				:compact="compact"
				:on-create-card="onCreateCard"
				:on-card-focus="onCardFocus"
				:on-card-hover="onCardHover"
				:collapsed="collapsedStacks.has(stack.id)"
				:on-toggle-collapsed="onToggleCollapsed" />
		</div>
	</section>
</template>

<script setup>
import { computed } from 'vue'
import StackColumn from './StackColumn.vue'
import { cssColor } from '../services/color.js'

const props = defineProps({
	/** Lane descriptor from buildLanes(): { key, title, color, cardsByStack }. */
	lane: {
		type: Object,
		required: true,
	},
	/** Sorted, non-archived stacks (shared across all lanes). */
	stacks: {
		type: Array,
		default: () => [],
	},
	labelsById: {
		type: Map,
		default: () => new Map(),
	},
	boardPrefix: {
		type: String,
		default: '',
	},
	/** (laneKey, stackId, componentInstance|null) → void — column-ref registrar. */
	registerColumnRef: {
		type: Function,
		required: true,
	},
	/**
	 * Async (stackId, title) → Promise — inline card create (still allowed in
	 * lanes). Null for read-only members; forwarded as-is so the column hides
	 * its composer rather than offering a create the server would reject.
	 */
	onCreateCard: {
		type: Function,
		default: null,
	},
	onCardFocus: {
		type: Function,
		default: null,
	},
	onCardHover: {
		type: Function,
		default: null,
	},
	/**
	 * Set<stackId> of per-user collapsed columns (#3677). Collapse is shared
	 * across lanes (a stack is one board column rendered once per lane), so the
	 * same Set drives every lane's copy of the column.
	 */
	collapsedStacks: {
		type: Set,
		default: () => new Set(),
	},
	/** (stackId) → void — toggle a column's collapsed state. */
	onToggleCollapsed: {
		type: Function,
		default: null,
	},
	/** Compact density (#3415) — threaded down to each lane's StackColumn → CardTile. */
	compact: {
		type: Boolean,
		default: false,
	},
})

function cardsForStack(stackId) {
	return props.lane.cardsByStack.get(stackId) ?? []
}

const laneCardCount = computed(() => {
	let n = 0
	for (const cards of props.lane.cardsByStack.values()) n += cards.length
	return n
})

const isEmpty = computed(() => laneCardCount.value === 0)
</script>

<style scoped>
.swimlane {
	display: flex;
	flex-direction: column;
	flex-shrink: 0;
	border-bottom: 1px solid var(--color-border);
}

.swimlane__header {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 24px 6px;
	position: sticky;
	left: 0;
	/* Keep the lane label pinned to the viewport's left edge while the stacks
	   row scrolls horizontally, so you always know which lane you're in. */
	width: fit-content;
}

.swimlane__color-dot {
	flex-shrink: 0;
	width: 12px;
	height: 12px;
	border-radius: 50%;
}

.swimlane__title {
	margin: 0;
	font-size: 0.95rem;
	font-weight: 700;
	color: var(--color-main-text);
	white-space: nowrap;
}

.swimlane__count {
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

.swimlane__stacks {
	display: flex;
	flex-direction: row;
	align-items: flex-start;
	gap: 16px;
	padding: 4px 24px 16px;
}

/* Inside a lane a column must not claim the full viewport height (that is the
   flat-board default); cap it so multiple lanes fit and each column's internal
   virtualized list scrolls within the lane instead.
   Width: swimlane columns are pinned at 280px (not fluid) because each lane
   has its own independent horizontal-scroll container — there is no shared flex
   parent spanning all lanes, so flex-grow cannot align columns across rows. A
   fixed width keeps every lane's column positions in sync visually. */
.swimlane__stacks :deep(.stack-column) {
	flex: 0 0 280px;
	width: 280px;
	min-width: 280px;
	max-width: 280px;
	max-height: 420px;
}
/* Collapsed rails inside a lane stay at their fixed 48px. */
.swimlane__stacks :deep(.stack-column--collapsed) {
	flex: 0 0 48px;
	width: 48px;
	min-width: 48px;
	max-width: 48px;
}

/* Lanes with no cards (e.g. the trailing "No label" lane when everything is
   labelled) collapse their header muted so they read as a thin divider. */
.swimlane--empty .swimlane__title,
.swimlane--empty .swimlane__count {
	opacity: 0.55;
}
</style>
