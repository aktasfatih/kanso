<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="my-work-view">
		<!-- Hub header: title + tab control + board filter -->
		<div class="my-work-view__header">
			<h1 class="my-work-view__title">{{ t('kanso', 'My Work') }}</h1>

			<div class="my-work-view__controls">
				<!-- Segmented tab control -->
				<div
					class="my-work-view__tabs"
					role="tablist"
					:aria-label="t('kanso', 'Work type')">
					<button
						type="button"
						role="tab"
						:aria-selected="tab === 'tasks'"
						:class="['my-work-view__tab', { 'my-work-view__tab--active': tab === 'tasks' }]"
						@click="tab = 'tasks'">
						{{ t('kanso', 'My tasks') }}
					</button>
					<button
						type="button"
						role="tab"
						:aria-selected="tab === 'reviews'"
						:class="['my-work-view__tab', { 'my-work-view__tab--active': tab === 'reviews' }]"
						@click="tab = 'reviews'">
						{{ t('kanso', 'Reviews') }}
					</button>
					<button
						type="button"
						role="tab"
						:aria-selected="tab === 'inbox'"
						:class="['my-work-view__tab', { 'my-work-view__tab--active': tab === 'inbox' }]"
						@click="tab = 'inbox'">
						{{ t('kanso', 'Inbox') }}
					</button>
				</div>

				<!-- Board filter -->
				<NcSelect
					v-model="selectedBoardOption"
					class="my-work-view__board-filter"
					:options="boardOptions"
					:placeholder="t('kanso', 'All boards')"
					:clearable="false"
					label="label"
					input-id="my-work-board-filter" />
			</div>
		</div>

		<!-- Active sub-view — receives embedded + boardFilter props -->
		<MyCardsView
			v-if="tab === 'tasks'"
			:embedded="true"
			:board-filter="boardFilter" />
		<MyReviewsView
			v-else-if="tab === 'reviews'"
			:embedded="true"
			:board-filter="boardFilter" />
		<InboxView
			v-else
			:embedded="true"
			:board-filter="boardFilter" />
	</div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { useBoards } from '../composables/useBoards.js'
import MyCardsView from './MyCardsView.vue'
import MyReviewsView from './MyReviewsView.vue'
import InboxView from './InboxView.vue'

/** Active tab: 'tasks' | 'reviews' | 'inbox' */
const tab = ref('tasks')

// Board options for the filter dropdown
const { data: boardsData } = useBoards()
const boards = computed(() => (boardsData.value ?? []).filter((b) => !b.archived))

const ALL_BOARDS_OPTION = { label: t('kanso', 'All boards'), value: null }

const boardOptions = computed(() => [
	ALL_BOARDS_OPTION,
	...boards.value.map((b) => ({ label: b.title, value: b.id })),
])

/** The currently selected NcSelect option object. */
const selectedBoardOption = ref(ALL_BOARDS_OPTION)

/** The boardId to pass as :board-filter, or null for "all". */
const boardFilter = computed(() => selectedBoardOption.value?.value ?? null)
</script>

<style scoped>
.my-work-view {
	padding: 24px 32px;
	max-width: 860px;
}

.my-work-view__header {
	margin-bottom: 24px;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 16px;
}

.my-work-view__title {
	font-size: 1.5rem;
	font-weight: 600;
	margin: 0;
	flex: 1 1 auto;
}

.my-work-view__controls {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

/* Segmented tab strip */
.my-work-view__tabs {
	display: flex;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-large, 8px);
	overflow: hidden;
}

.my-work-view__tab {
	padding: 6px 14px;
	font-size: 0.875rem;
	font-weight: 500;
	background: transparent;
	border: none;
	border-right: 1px solid var(--color-border-dark);
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	transition: background 0.15s, color 0.15s;
	white-space: nowrap;
}

.my-work-view__tab:last-child {
	border-right: none;
}

.my-work-view__tab:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.my-work-view__tab--active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.my-work-view__tab--active:hover {
	background: var(--color-primary-element-hover, var(--color-primary-element));
	color: var(--color-primary-element-text);
}

/* Board filter select — constrain width */
.my-work-view__board-filter {
	min-width: 160px;
	max-width: 220px;
}
</style>
