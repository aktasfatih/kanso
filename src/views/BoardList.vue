<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="board-list-view">
		<div class="board-list-header">
			<h1 class="board-list-title">{{ t('kanso', 'My Boards') }}</h1>
		</div>

		<!-- Skeleton placeholders while cold-loading -->
		<div v-if="isLoading" class="board-grid">
			<div v-for="n in 6" :key="n" class="board-tile board-tile--skeleton">
				<div class="skeleton-dot" />
				<div class="skeleton-text" />
			</div>
		</div>

		<!-- Error state -->
		<div v-else-if="isError" class="board-list-error">
			<p>{{ t('kanso', 'Failed to load boards. Please try again.') }}</p>
		</div>

		<!-- Empty state -->
		<template v-else-if="boards && boards.length === 0">
			<NcEmptyContent
				:name="t('kanso', 'No boards yet')"
				:description="t('kanso', 'Create your first board to get started.')">
				<template #icon>
					<ViewColumnIcon :size="64" />
				</template>
			</NcEmptyContent>
		</template>

		<!-- Board grid -->
		<div v-else class="board-grid">
			<button
				v-for="board in activeBoards"
				:key="board.id"
				class="board-tile"
				@click="openBoard(board.id)">
				<span
					class="board-tile__color-dot"
					:style="{ background: board.color || 'var(--color-primary)' }" />
				<span class="board-tile__title">{{ board.title }}</span>
			</button>
		</div>

		<!-- New board form -->
		<form class="new-board-form" @submit.prevent="submitNewBoard">
			<input
				v-model="newBoardTitle"
				class="new-board-form__input"
				type="text"
				:placeholder="t('kanso', 'New board name…')"
				:disabled="createBoard.isPending.value"
				@keydown.enter.prevent="submitNewBoard" />
			<NcButton
				type="submit"
				:disabled="!newBoardTitle.trim() || createBoard.isPending.value"
				native-type="submit">
				{{ t('kanso', 'Create board') }}
			</NcButton>
			<p v-if="createError" class="new-board-form__error">{{ createError }}</p>
		</form>
	</div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import ViewColumnIcon from 'vue-material-design-icons/ViewColumn.vue'
import { useBoards } from '../composables/useBoards.js'

const router = useRouter()
const { data: boards, isLoading, isError, createBoard } = useBoards()

const newBoardTitle = ref('')
const createError = ref('')

const activeBoards = computed(() =>
	boards.value ? boards.value.filter((b) => !b.archived) : [],
)

function openBoard(id) {
	router.push({ name: 'board', params: { id } })
}

async function submitNewBoard() {
	const title = newBoardTitle.value.trim()
	if (!title) return
	createError.value = ''
	try {
		await createBoard.mutateAsync({ title })
		newBoardTitle.value = ''
	} catch (err) {
		createError.value =
			err?.response?.data?.error || t('kanso', 'Failed to create board.')
	}
}
</script>

<style scoped>
.board-list-view {
	padding: 24px;
	max-width: 1200px;
	margin: 0 auto;
}

.board-list-header {
	margin-bottom: 24px;
}

.board-list-title {
	font-size: 1.5rem;
	font-weight: 700;
	color: var(--color-main-text);
}

.board-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
	gap: 16px;
	margin-bottom: 32px;
}

.board-tile {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 16px 20px;
	background: var(--color-main-background);
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large);
	cursor: pointer;
	text-align: left;
	transition: border-color 0.15s ease, box-shadow 0.15s ease;
	width: 100%;
}

.board-tile:hover {
	border-color: var(--color-primary);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.board-tile__color-dot {
	flex-shrink: 0;
	width: 12px;
	height: 12px;
	border-radius: 50%;
}

.board-tile__title {
	font-weight: 600;
	color: var(--color-main-text);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* Skeleton shimmer */
.board-tile--skeleton {
	cursor: default;
	pointer-events: none;
}

.board-tile--skeleton:hover {
	border-color: var(--color-border);
	box-shadow: none;
}

@keyframes shimmer {
	0% { background-position: -400px 0; }
	100% { background-position: 400px 0; }
}

.skeleton-dot {
	flex-shrink: 0;
	width: 12px;
	height: 12px;
	border-radius: 50%;
	background: linear-gradient(90deg, var(--color-border) 25%, var(--color-background-hover) 50%, var(--color-border) 75%);
	background-size: 400px 100%;
	animation: shimmer 1.4s infinite linear;
}

.skeleton-text {
	flex: 1;
	height: 14px;
	border-radius: 4px;
	background: linear-gradient(90deg, var(--color-border) 25%, var(--color-background-hover) 50%, var(--color-border) 75%);
	background-size: 400px 100%;
	animation: shimmer 1.4s infinite linear;
}

/* New board form */
.new-board-form {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.new-board-form__input {
	flex: 1 1 200px;
	min-width: 0;
	height: 36px;
	padding: 0 12px;
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.875rem;
}

.new-board-form__input:focus {
	outline: none;
	border-color: var(--color-primary);
}

.new-board-form__error {
	width: 100%;
	color: var(--color-error);
	font-size: 0.8rem;
	margin: 0;
}

.board-list-error {
	color: var(--color-error);
	padding: 16px;
}
</style>
