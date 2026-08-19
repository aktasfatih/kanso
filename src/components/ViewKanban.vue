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
				@open="openCard" />
		</div>
	</div>
</template>

<script setup>
import { useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import ViewKanbanColumn from './ViewKanbanColumn.vue'

defineProps({
	/**
	 * Ordered group list `[{ key, title, cards }]` from groupCardsByField — the
	 * SAME array List/Timeline consume. Kanban renders one column per group.
	 */
	groups: { type: Array, default: () => [] },
	/**
	 * Map<labelId, label>. Cross-board label metadata isn't loaded, so this is an
	 * empty map (label chips fall back to a neutral colour), mirroring the List.
	 */
	labelsById: { type: Map, default: () => new Map() },
})

const router = useRouter()

// Deep-link to the card modal on its OWN board (cross-board correct): the card's
// boardId — never the current route — drives the target. CardTile's own click
// path uses route.params.id, wrong here, so the click is wired at this level.
function openCard(card) {
	router.push({ name: 'card-modal', params: { id: String(card.boardId), cardId: String(card.id) } })
}
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
