<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="my-cards-view">
		<div v-if="!embedded" class="my-cards-view__header">
			<h1 class="my-cards-view__title">{{ t('kanso', 'My tasks') }}</h1>
		</div>

		<!-- Loading state -->
		<div v-if="isLoading" class="my-cards-view__loading" aria-live="polite">
			<span class="my-cards-view__spinner" aria-hidden="true" />
			<span>{{ t('kanso', 'Loading tasks…') }}</span>
		</div>

		<!-- Error state -->
		<div v-else-if="isError" class="my-cards-view__error">
			{{ t('kanso', 'Failed to load tasks. Please try again.') }}
		</div>

		<!-- Empty state -->
		<NcEmptyContent
			v-else-if="!filteredCards.length"
			:name="t('kanso', 'No tasks assigned to you')"
			:description="t('kanso', 'Cards assigned to you across your boards will appear here.')">
			<template #icon>
				<FormatListChecksIcon :size="64" />
			</template>
		</NcEmptyContent>

		<!-- Grouped sections -->
		<template v-else>
			<section
				v-for="group in filteredGroups"
				v-show="group.cards.length"
				:key="group.key"
				class="my-cards-view__section">
				<h2
					class="my-cards-view__section-title"
					:class="{ 'my-cards-view__section-title--overdue': group.key === 'overdue' }">
					{{ group.label }} <span class="my-cards-view__count">{{ group.cards.length }}</span>
				</h2>
				<ul class="my-cards-view__list">
					<li
						v-for="card in group.cards"
						:key="card.id"
						class="my-cards-view__row"
						tabindex="0"
						role="button"
						@click="openCard(card)"
						@keydown.enter="openCard(card)">
						<div class="my-cards-view__row-main">
							<span class="my-cards-view__card-title">{{ card.title }}</span>
							<span class="my-cards-view__meta">
								<span class="my-cards-view__board">{{ card.boardTitle }}</span>
								<span v-if="card.stackTitle" class="my-cards-view__stack">· {{ card.stackTitle }}</span>
							</span>
						</div>
						<span
							v-if="card.duedate"
							class="my-cards-view__due"
							:class="{ 'my-cards-view__due--overdue': group.key === 'overdue' }">
							{{ formatDue(card.duedate) }}
						</span>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import FormatListChecksIcon from 'vue-material-design-icons/FormatListChecks.vue'
import { useMyCards } from '../composables/useMyCards.js'

const props = defineProps({
	embedded: { type: Boolean, default: false },
	boardFilter: { type: Number, default: null },
})

const router = useRouter()
const { data, isLoading, isError } = useMyCards()

const cards = computed(() => data.value ?? [])

/** Cards after applying the optional board filter from the hub. */
const filteredCards = computed(() =>
	props.boardFilter === null
		? cards.value
		: cards.value.filter((c) => c.boardId === props.boardFilter),
)

/** Local midnight boundaries used to bucket cards by due date. */
function dayBounds() {
	const start = new Date()
	start.setHours(0, 0, 0, 0)
	const tomorrow = new Date(start)
	tomorrow.setDate(tomorrow.getDate() + 1)
	return { start, tomorrow }
}

const filteredGroups = computed(() => {
	const { start, tomorrow } = dayBounds()
	const overdue = []
	const today = []
	const upcoming = []
	const none = []

	for (const card of filteredCards.value) {
		if (!card.duedate) {
			none.push(card)
			continue
		}
		const due = new Date(card.duedate)
		if (due < start) overdue.push(card)
		else if (due < tomorrow) today.push(card)
		else upcoming.push(card)
	}

	return [
		{ key: 'overdue', label: t('kanso', 'Overdue'), cards: overdue },
		{ key: 'today', label: t('kanso', 'Today'), cards: today },
		{ key: 'upcoming', label: t('kanso', 'Upcoming'), cards: upcoming },
		{ key: 'none', label: t('kanso', 'No due date'), cards: none },
	]
})

function formatDue(iso) {
	try {
		return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
	} catch {
		return iso
	}
}

function openCard(card) {
	router.push({ name: 'card-modal', params: { id: card.boardId, cardId: card.id } })
}
</script>

<style scoped>
.my-cards-view {
	padding: 24px 32px;
	max-width: 860px;
}

.my-cards-view__header {
	margin-bottom: 24px;
}

.my-cards-view__title {
	font-size: 1.5rem;
	font-weight: 600;
	margin: 0;
}

.my-cards-view__loading {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--color-text-maxcontrast);
	padding: 32px 0;
}

.my-cards-view__spinner {
	display: inline-block;
	width: 20px;
	height: 20px;
	border: 2px solid var(--color-border);
	border-top-color: var(--color-primary-element);
	border-radius: 50%;
	animation: my-cards-spin 0.7s linear infinite;
}

@keyframes my-cards-spin {
	to { transform: rotate(360deg); }
}

.my-cards-view__error {
	color: var(--color-error);
	padding: 16px 0;
}

.my-cards-view__section {
	margin-bottom: 36px;
}

.my-cards-view__section-title {
	font-size: 0.85rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	color: var(--color-text-maxcontrast);
	margin: 0 0 10px;
	display: flex;
	align-items: center;
	gap: 8px;
}

.my-cards-view__section-title--overdue {
	color: var(--color-error);
}

.my-cards-view__count {
	font-weight: 400;
	color: var(--color-text-maxcontrast);
}

.my-cards-view__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.my-cards-view__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 10px 14px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
	cursor: pointer;
	transition: background 0.15s;
}

.my-cards-view__row:hover,
.my-cards-view__row:focus-visible {
	background: var(--color-border-dark);
	outline: none;
}

.my-cards-view__row-main {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.my-cards-view__card-title {
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.my-cards-view__meta {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.my-cards-view__stack {
	margin-left: 4px;
}

.my-cards-view__due {
	flex-shrink: 0;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.my-cards-view__due--overdue {
	color: var(--color-error);
	font-weight: 600;
}
</style>
