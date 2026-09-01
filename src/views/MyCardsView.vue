<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="my-cards-view">
		<div v-if="!embedded" class="my-cards-view__header kanso-page-header">
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

		<!-- Empty state — the copy says WHY it is empty: with a board filter on,
		     "no tasks anywhere" would be a claim the view cannot make. -->
		<NcEmptyContent
			v-else-if="!filteredCards.length"
			:name="emptyName"
			:description="emptyDescription">
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
						@keydown.enter="openCard(card)"
						@keydown.space.prevent="openCard(card)">
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

		<!-- The feed is capped server-side. Say so, rather than letting a
		     truncated window read as the whole of someone's workload. The
		     wording is about what was LOADED, not about the rows on screen, so
		     it stays true with the board filter applied. -->
		<p
			v-if="!isLoading && !isError && truncated"
			class="my-cards-view__truncation"
			role="status">
			{{ t('kanso', 'Only the first {count} assigned cards are loaded, so some of your tasks are missing from this list.', { count: limit }) }}
		</p>
	</div>
</template>

<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import FormatListChecksIcon from 'vue-material-design-icons/FormatListChecks.vue'
import { useMyCards } from '../composables/useMyCards.js'
import { myCardsFeed } from '../services/myCardsFeed.js'

const props = defineProps({
	embedded: { type: Boolean, default: false },
	boardFilter: { type: Number, default: null },
})

const router = useRouter()
const { data, isLoading, isError } = useMyCards()

/** { cards, truncated, limit } — the server caps the feed and reports the cap. */
const feed = computed(() => myCardsFeed(data.value))

const cards = computed(() => feed.value.cards)

/** True when more cards are assigned than the server returned. */
const truncated = computed(() => feed.value.truncated)

/** The server-side row cap, for the truncation notice. */
const limit = computed(() => feed.value.limit)

/** Cards after applying the optional board filter from the hub. */
const filteredCards = computed(() =>
	props.boardFilter === null
		? cards.value
		: cards.value.filter((c) => c.boardId === props.boardFilter),
)

/**
 * Empty-state copy. The block is gated on the FILTERED list, so with a board
 * filter applied the unfiltered wording ("no tasks assigned to you") would
 * state something false — the user may well have tasks on other boards.
 */
const emptyName = computed(() =>
	props.boardFilter === null
		? t('kanso', 'No tasks assigned to you')
		: t('kanso', 'No tasks on this board'),
)

const emptyDescription = computed(() =>
	props.boardFilter === null
		? t('kanso', 'Cards assigned to you across your boards will appear here.')
		: t('kanso', 'Nothing on the selected board is assigned to you. Choose “All boards” to see everything assigned to you.'),
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
	// Thread the origin so CardModal can return to My Work on close instead of
	// dumping the user on the board. Embedded surfaces live under the /my-work
	// hub (carry the tab so it re-opens on the right one); standalone uses its
	// own route name.
	const from = props.embedded ? 'my-work' : 'my-cards'
	const query = props.embedded ? { from, tab: 'tasks' } : { from }
	router.push({ name: 'card-modal', params: { id: card.boardId, cardId: card.id }, query })
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
}

/* Rows are tabbable buttons, so keyboard focus MUST be visible (WCAG 2.4.7).
   Same ring as the sibling Inbox rows. */
.my-cards-view__row:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
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

.my-cards-view__truncation {
	margin: 0;
	padding: 12px 0 0;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}
</style>
