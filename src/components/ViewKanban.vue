<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="view-kanban">
		<p v-if="groups.length === 0" class="view-kanban__empty">
			{{ t('kanso', 'No cards to show.') }}
		</p>
		<div v-else class="view-kanban__board">
			<ViewKanbanColumn
				v-for="group in groups"
				:key="group.key"
				:group="group"
				:labels-by-id="labelsById"
				@open="$emit('open', $event)" />
		</div>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import ViewKanbanColumn from './ViewKanbanColumn.vue'

defineProps({
	/**
	 * Ordered group list `[{ key, title, cards }]` from groupCardsByField — the
	 * SAME array List/Timeline consume. Kanban renders one column per group.
	 */
	groups: { type: Array, default: () => [] },
	/**
	 * Map<labelId, label> built from the view feed's cross-board label union, so
	 * card-tile chips render the real label colours (matches the board tiles, #3950).
	 */
	labelsById: { type: Map, default: () => new Map() },
})

// The parent ViewPage owns the card-detail overlay (#3950): bubble the clicked card
// up rather than route-navigating to card-modal (which is a child of the board route
// and would swap the whole View out for the board). The card carries its own boardId.
defineEmits(['open'])
</script>

<style scoped>
.view-kanban {
	flex: 1;
	min-height: 0;
	overflow: hidden;
	padding: 8px 24px 16px 52px;
}

.view-kanban__empty {
	color: var(--color-text-maxcontrast);
	padding: 24px 0;
}

/* Horizontal row of columns; scrolls sideways when the columns overflow. */
.view-kanban__board {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	height: 100%;
	overflow-x: auto;
	overflow-y: hidden;
}
</style>
