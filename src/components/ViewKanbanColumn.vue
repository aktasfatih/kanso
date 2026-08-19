<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="view-kanban-col">
		<!-- Column header: mirrors the List's group visual vocabulary (title + count). -->
		<div class="view-kanban-col__header">
			<span class="view-kanban-col__title">{{ group.title }}</span>
			<span class="view-kanban-col__count">{{ cards.length }}</span>
		</div>

		<!-- Own scroll element + virtualizer per column (no stack chrome / DnD / composer). -->
		<div ref="scrollRef" class="view-kanban-col__cards">
			<div
				class="view-kanban-col__virtual-host"
				:style="{ height: virtualizer.getTotalSize() + 'px' }">
				<div
					v-for="vRow in virtualizer.getVirtualItems()"
					:key="cards[vRow.index].id"
					:ref="(el) => measureVirtualEl(el, vRow.index)"
					:data-index="vRow.index"
					class="view-kanban-col__virtual-item"
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
						:board-prefix="cards[vRow.index].boardPrefix || ''"
						@click="$emit('open', cards[vRow.index])" />
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useVirtualizer } from '@tanstack/vue-virtual'
import CardTile from './CardTile.vue'

const props = defineProps({
	/** One group `{ key, title, cards }` from groupCardsByField. */
	group: { type: Object, required: true },
	/** Map<labelId, label> from the view feed's cross-board label union (#3950). */
	labelsById: { type: Map, default: () => new Map() },
})

defineEmits(['open'])

const cards = computed(() => props.group.cards ?? [])

const scrollRef = ref(null)

// Variable-height tiles (labels / meta wrap), so estimate + measure like the
// board column does; measureElement keeps positions exact as tiles render.
const virtualizer = useVirtualizer(computed(() => ({
	count: cards.value.length,
	getScrollElement: () => scrollRef.value,
	estimateSize: () => 96,
	overscan: 6,
	getItemKey: (i) => cards.value[i]?.id ?? i,
})))

function measureVirtualEl(el, index) {
	if (el) virtualizer.value.measureElement(el)
}
</script>

<style scoped>
.view-kanban-col {
	display: flex;
	flex-direction: column;
	min-height: 0;
	height: 100%;
	width: 280px;
	flex: 0 0 280px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large, 12px);
	overflow: hidden;
}

/* The header mirrors the List's group bar look but is a static bar (no toggle). */
.view-kanban-col__header {
	display: flex;
	align-items: center;
	gap: 10px;
	box-sizing: border-box;
	flex: 0 0 auto;
	height: 40px;
	padding: 0 12px;
	background: var(--color-background-dark);
	border-bottom: 1px solid var(--color-border);
	color: var(--color-main-text);
}

.view-kanban-col__title {
	font-weight: 700;
	text-transform: uppercase;
	font-size: 0.8rem;
	letter-spacing: 0.03em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.view-kanban-col__count {
	color: var(--color-text-maxcontrast);
	font-weight: 400;
	font-size: 0.8rem;
	flex: 0 0 auto;
}

.view-kanban-col__cards {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
	padding: 8px;
}

.view-kanban-col__virtual-host {
	position: relative;
	width: 100%;
}

.view-kanban-col__virtual-item {
	padding-bottom: 8px;
}
</style>
