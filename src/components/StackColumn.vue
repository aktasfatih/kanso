<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="stack-column">
		<!-- Column header -->
		<div class="stack-column__header">
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

		<!-- Card list — structured as its own scrollable div for future TanStack Virtual drop-in -->
		<div class="stack-column__cards">
			<CardTile
				v-for="card in cards"
				:key="card.id"
				:card="card"
				@click="openCard(card.id)" />
		</div>
	</div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import CardTile from './CardTile.vue'

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
})

const router = useRouter()
const route = useRoute()

const composerInputRef = ref(null)
const newCardTitle = ref('')
const composerError = ref('')
const isPending = ref(false)

function openCard(cardId) {
	router.push({
		name: 'card-modal',
		params: { id: route.params.id, cardId },
	})
}

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

.stack-column__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding-bottom: 8px;
	border-bottom: 1px solid var(--color-border);
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

/* Card list — own scrollable element for future TanStack Virtual drop-in */
.stack-column__cards {
	display: flex;
	flex-direction: column;
	gap: 8px;
	overflow-y: auto;
	flex: 1;
	min-height: 0;
}
</style>
