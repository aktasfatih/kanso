<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="board-list-view">
		<div class="board-list-header">
			<h1 class="board-list-title">{{ t('kanso', 'My Boards') }}</h1>
			<NcButton class="board-list-import-btn" @click="openImport">
				<template #icon>
					<ImportIcon :size="20" />
				</template>
				{{ t('kanso', 'Import from Deck') }}
			</NcButton>
		</div>

		<!-- Import-from-Deck modal -->
		<NcModal v-if="showImport" size="normal" @close="showImport = false">
			<div class="deck-import">
				<h2 class="deck-import__title">{{ t('kanso', 'Import from Deck') }}</h2>
				<p class="deck-import__hint">
					{{ t('kanso', 'Each Deck board is copied into a new Kanso board you own: stacks, cards, labels and assignees. Your Deck boards are left untouched.') }}
				</p>

				<p v-if="importLoading" class="deck-import__state">{{ t('kanso', 'Loading your Deck boards…') }}</p>
				<p v-else-if="importLoadError" class="deck-import__error">{{ importLoadError }}</p>
				<p v-else-if="!deckAvailable" class="deck-import__state">
					{{ t('kanso', 'The Deck app is not installed on this server, so there is nothing to import.') }}
				</p>
				<p v-else-if="deckBoards.length === 0" class="deck-import__state">
					{{ t('kanso', 'No Deck boards found to import.') }}
				</p>

				<ul v-else class="deck-import__list">
					<li v-for="db in deckBoards" :key="db.id" class="deck-import__row">
						<span
							class="deck-import__dot"
							:style="{ background: db.color ? '#' + db.color : 'var(--color-primary-element)' }" />
						<span class="deck-import__name">{{ db.title }}</span>
						<span class="deck-import__count">{{ n('kanso', '%n card', '%n cards', db.cardCount) }}</span>
						<NcButton
							type="primary"
							:disabled="importingId !== null"
							@click="doImport(db)">
							{{ importingId === db.id ? t('kanso', 'Importing…') : t('kanso', 'Import') }}
						</NcButton>
					</li>
				</ul>
				<p v-if="importError" class="deck-import__error">{{ importError }}</p>
			</div>
		</NcModal>

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

		<!-- Archived boards -->
		<div v-if="!isLoading && archivedBoards.length > 0" class="board-list__archived">
			<button class="board-list__archived-toggle" @click="showArchived = !showArchived">
				<ChevronDownIcon v-if="showArchived" :size="18" />
				<ChevronRightIcon v-else :size="18" />
				{{ n('kanso', '%n archived board', '%n archived boards', archivedBoards.length) }}
			</button>
			<ul v-if="showArchived" class="board-list__archived-list">
				<li v-for="board in archivedBoards" :key="board.id" class="board-list__archived-row">
					<span class="board-tile__color-dot" :style="{ background: board.color || 'var(--color-primary)' }" />
					<span class="board-list__archived-name">{{ board.title }}</span>
					<NcButton :disabled="updateBoard.isPending.value" @click="unarchiveBoard(board.id)">
						<template #icon>
							<ArchiveArrowUpIcon :size="18" />
						</template>
						{{ t('kanso', 'Unarchive') }}
					</NcButton>
				</li>
			</ul>
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
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { useQueryClient } from '@tanstack/vue-query'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import ViewColumnIcon from 'vue-material-design-icons/ViewColumn.vue'
import ImportIcon from 'vue-material-design-icons/Import.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ArchiveArrowUpIcon from 'vue-material-design-icons/ArchiveArrowUp.vue'
import { useBoards } from '../composables/useBoards.js'
import { fetchDeckImportBoards, importDeckBoard } from '../services/api.js'

const router = useRouter()
const queryClient = useQueryClient()
const { data: boards, isLoading, isError, createBoard, updateBoard } = useBoards()

const newBoardTitle = ref('')
const createError = ref('')

// ── Import from Deck ─────────────────────────────────────────────────────────
const showImport = ref(false)
const importLoading = ref(false)
const importLoadError = ref('')
const deckAvailable = ref(false)
const deckBoards = ref([])
const importingId = ref(null)
const importError = ref('')

async function openImport() {
	showImport.value = true
	importError.value = ''
	importLoadError.value = ''
	importLoading.value = true
	try {
		const res = await fetchDeckImportBoards()
		deckAvailable.value = res.available
		deckBoards.value = res.boards ?? []
	} catch (err) {
		importLoadError.value = t('kanso', 'Could not load your Deck boards.')
	} finally {
		importLoading.value = false
	}
}

async function doImport(db) {
	importError.value = ''
	importingId.value = db.id
	try {
		const res = await importDeckBoard(db.id)
		await queryClient.invalidateQueries({ queryKey: ['boards'] })
		showImport.value = false
		router.push({ name: 'board', params: { id: res.boardId } })
	} catch (err) {
		importError.value = err?.response?.data?.error || t('kanso', 'Failed to import that board.')
	} finally {
		importingId.value = null
	}
}

const activeBoards = computed(() =>
	boards.value ? boards.value.filter((b) => !b.archived) : [],
)
const archivedBoards = computed(() =>
	boards.value ? boards.value.filter((b) => b.archived) : [],
)
const showArchived = ref(false)

function openBoard(id) {
	router.push({ name: 'board', params: { id } })
}

async function unarchiveBoard(id) {
	await updateBoard.mutateAsync({ id, data: { archived: false } })
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
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.board-list-title {
	font-size: 1.5rem;
	font-weight: 700;
	color: var(--color-main-text);
}

/* ── Import from Deck ─────────────────────────────────────────────────────── */
.deck-import {
	padding: 24px;
}

.deck-import__title {
	font-size: 1.25rem;
	font-weight: 700;
	margin-bottom: 8px;
}

.deck-import__hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.deck-import__state {
	color: var(--color-text-maxcontrast);
	padding: 12px 0;
}

.deck-import__error {
	color: var(--color-error);
	padding-top: 8px;
}

.deck-import__list {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.deck-import__row {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px;
	border-radius: var(--border-radius);
}

.deck-import__row:hover {
	background: var(--color-background-hover);
}

.deck-import__dot {
	flex: 0 0 auto;
	width: 14px;
	height: 14px;
	border-radius: 50%;
	border: 1px solid var(--color-border-dark);
}

.deck-import__name {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-weight: 500;
}

.deck-import__count {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
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
