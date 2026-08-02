<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="board-list-view">
		<!-- ── Header ─────────────────────────────────────────────────────── -->
		<div class="board-list-header">
			<h1 class="board-list-title">{{ t('kanso', 'My Boards') }}</h1>
			<span class="board-list-count">{{ activeBoards.length }}</span>

			<div class="board-list-search">
				<MagnifyIcon :size="18" class="board-list-search__icon" />
				<input
					v-model="search"
					class="board-list-search__input"
					type="text"
					:placeholder="t('kanso', 'Search boards…')"
					:aria-label="t('kanso', 'Search boards')">
			</div>

			<!-- Active / Archived segmented toggle -->
			<div class="board-list-segmented" role="group" :aria-label="t('kanso', 'Filter boards')">
				<button
					class="board-list-segmented__opt"
					:class="{ 'board-list-segmented__opt--active': !showArchived }"
					:aria-pressed="!showArchived"
					@click="showArchived = false">
					{{ t('kanso', 'Active') }}
				</button>
				<button
					class="board-list-segmented__opt"
					:class="{ 'board-list-segmented__opt--active': showArchived }"
					:aria-pressed="showArchived"
					@click="showArchived = true">
					{{ t('kanso', 'Archived') }}
					<span v-if="archivedBoards.length > 0" class="board-list-segmented__badge">{{ archivedBoards.length }}</span>
				</button>
			</div>

			<!-- Import dropdown -->
			<NcActions class="board-list-import" :menu-name="t('kanso', 'Import')">
				<template #icon>
					<ImportIcon :size="20" />
				</template>
				<NcActionCaption :name="t('kanso', 'Import a board')" />
				<NcActionButton close-after-click @click="openImport">
					<template #icon>
						<ViewDashboardOutlineIcon :size="20" />
					</template>
					{{ t('kanso', 'Nextcloud Deck') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="triggerJsonImport">
					<template #icon>
						<CodeJsonIcon :size="20" />
					</template>
					{{ t('kanso', 'Kanso export (.json)') }}
				</NcActionButton>
				<NcActionButton :disabled="true">
					{{ t('kanso', 'Trello (coming soon)') }}
				</NcActionButton>
				<NcActionButton :disabled="true">
					{{ t('kanso', 'GitHub Projects (coming soon)') }}
				</NcActionButton>
				<NcActionButton :disabled="true">
					{{ t('kanso', 'CSV file (coming soon)') }}
				</NcActionButton>
			</NcActions>

			<!-- Hidden file input backing the "Kanso export (.json)" import action -->
			<input
				ref="jsonImportInput"
				type="file"
				accept="application/json,.json"
				class="board-list__hidden-file"
				data-test="kanso-import-file"
				@change="onJsonImportChange">
			<p v-if="jsonImportError" class="board-list__import-error" data-test="kanso-import-error">
				{{ jsonImportError }}
			</p>

			<!-- Create board -->
			<NcButton type="primary" @click="showCreate = !showCreate">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('kanso', 'Create board') }}
			</NcButton>
		</div>

		<!-- Inline create-board form (revealed by the Create board button) -->
		<form v-if="showCreate" class="new-board-form" @submit.prevent="submitNewBoard">
			<input
				ref="newBoardInput"
				v-model="newBoardTitle"
				class="new-board-form__input"
				type="text"
				:placeholder="t('kanso', 'New board name…')"
				:disabled="createBoard.isPending.value"
				@keydown.enter.prevent="submitNewBoard">
			<NcButton
				type="primary"
				:disabled="!newBoardTitle.trim() || createBoard.isPending.value"
				native-type="submit">
				{{ t('kanso', 'Create board') }}
			</NcButton>
			<p v-if="createError" class="new-board-form__error">{{ createError }}</p>
		</form>

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

		<!-- ── Body ───────────────────────────────────────────────────────── -->
		<div class="board-list-body">
			<!-- Skeleton placeholders while cold-loading -->
			<div v-if="isLoading" class="board-grid">
				<div v-for="i in 6" :key="i" class="board-tile board-tile--skeleton">
					<div class="skeleton-dot" />
					<div class="skeleton-text" />
				</div>
			</div>

			<!-- Error state -->
			<div v-else-if="isError" class="board-list-error">
				<p>{{ t('kanso', 'Failed to load boards. Please try again.') }}</p>
			</div>

			<!-- Empty state (no boards at all) -->
			<template v-else-if="!boards || boards.length === 0">
				<NcEmptyContent
					:name="t('kanso', 'No boards yet')"
					:description="t('kanso', 'Create your first board to get started.')">
					<template #icon>
						<ViewColumnIcon :size="64" />
					</template>
				</NcEmptyContent>
			</template>

			<template v-else>
				<!-- Pinned section — scaffolding only; hidden until per-user
				     pinning ships (#3572). No pin API exists yet, so pinnedBoards
				     is always empty and this block never renders today. -->
				<section v-if="!showArchived && pinnedBoards.length > 0" class="board-section">
					<h2 class="board-section__label">{{ t('kanso', 'Pinned') }}</h2>
					<div class="board-grid">
						<button
							v-for="board in pinnedBoards"
							:key="board.id"
							class="board-tile board-tile--pinned"
							@click="openBoard(board.id)">
							<BoardTileContent :board="board" pinned />
						</button>
					</div>
				</section>

				<!-- All boards / Archived -->
				<section class="board-section">
					<h2 class="board-section__label">
						{{ showArchived ? t('kanso', 'Archived') : t('kanso', 'All boards') }}
					</h2>

					<p v-if="visibleBoards.length === 0" class="board-section__empty">
						{{ search.trim()
							? t('kanso', 'No boards match your search.')
							: (showArchived ? t('kanso', 'No archived boards.') : t('kanso', 'No boards yet.')) }}
					</p>

					<div v-else class="board-grid">
						<!-- Archived tiles carry a restore action instead of navigating. -->
						<template v-if="showArchived">
							<div
								v-for="board in visibleBoards"
								:key="board.id"
								class="board-tile board-tile--archived board-list__archived-row">
								<BoardTileContent :board="board" />
								<div class="board-tile__actions">
									<NcButton :disabled="updateBoard.isPending.value" @click="unarchiveBoard(board.id)">
										<template #icon>
											<ArchiveArrowUpIcon :size="18" />
										</template>
										{{ t('kanso', 'Unarchive') }}
									</NcButton>
								</div>
							</div>
						</template>
						<template v-else>
							<button
								v-for="board in visibleBoards"
								:key="board.id"
								class="board-tile"
								@click="openBoard(board.id)">
								<BoardTileContent :board="board" />
							</button>
						</template>
					</div>
				</section>
			</template>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, nextTick, watch } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { useQueryClient } from '@tanstack/vue-query'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import ViewColumnIcon from 'vue-material-design-icons/ViewColumn.vue'
import ViewDashboardOutlineIcon from 'vue-material-design-icons/ViewDashboardOutline.vue'
import ImportIcon from 'vue-material-design-icons/Import.vue'
import CodeJsonIcon from 'vue-material-design-icons/CodeJson.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import ArchiveArrowUpIcon from 'vue-material-design-icons/ArchiveArrowUp.vue'
import BoardTileContent from '../components/BoardTileContent.vue'
import { useBoards } from '../composables/useBoards.js'
import { fetchDeckImportBoards, importDeckBoard, importBoard } from '../services/api.js'

const router = useRouter()
const queryClient = useQueryClient()
const { data: boards, isLoading, isError, createBoard, updateBoard } = useBoards()

// ── Header controls ──────────────────────────────────────────────────────────
const search = ref('')
const showArchived = ref(false)
const showCreate = ref(false)
const newBoardInput = ref(null)

const newBoardTitle = ref('')
const createError = ref('')

// ── Board partitions ─────────────────────────────────────────────────────────
const activeBoards = computed(() =>
	boards.value ? boards.value.filter((b) => !b.archived) : [],
)
const archivedBoards = computed(() =>
	boards.value ? boards.value.filter((b) => b.archived) : [],
)

// Per-user pinning ships separately (#3572); there's no pin state on the
// payload yet, so this is always empty and the Pinned section stays hidden.
const pinnedBoards = computed(() =>
	activeBoards.value.filter((b) => b.pinned),
)

function matchesSearch(board) {
	const q = search.value.trim().toLowerCase()
	if (!q) return true
	return (board.title || '').toLowerCase().includes(q)
}

// The main grid: archived set or the (non-pinned) active set, filtered by search.
const visibleBoards = computed(() => {
	const base = showArchived.value
		? archivedBoards.value
		: activeBoards.value.filter((b) => !b.pinned)
	return base.filter(matchesSearch)
})

// ── Navigation & mutations ───────────────────────────────────────────────────
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
		showCreate.value = false
	} catch (err) {
		createError.value =
			err?.response?.data?.error || t('kanso', 'Failed to create board.')
	}
}

// Reveal + focus the create form when the header button is clicked.
async function focusCreateInput() {
	await nextTick()
	newBoardInput.value?.focus()
}
// Keep focus behaviour when the form is opened.
watch(showCreate, (open) => {
	if (open) focusCreateInput()
})

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

// ── Import from a Kanso export (.json) ────────────────────────────────────────
const jsonImportInput = ref(null)
const jsonImportError = ref('')

function triggerJsonImport() {
	jsonImportError.value = ''
	jsonImportInput.value?.click()
}

async function onJsonImportChange(event) {
	const file = event.target.files?.[0]
	// Reset the input so re-picking the same file fires change again.
	event.target.value = ''
	if (!file) return
	jsonImportError.value = ''
	try {
		const text = await file.text()
		const res = await importBoard(text)
		await queryClient.invalidateQueries({ queryKey: ['boards'] })
		router.push({ name: 'board', params: { id: res.boardId } })
	} catch (err) {
		jsonImportError.value =
			err?.response?.data?.error || t('kanso', 'Could not import that file.')
	}
}
</script>

<style scoped>
.board-list-view {
	padding: 24px;
	max-width: 1200px;
	margin: 0 auto;
}

/* ── Header ─────────────────────────────────────────────────────────────── */
.board-list-header {
	margin-bottom: 24px;
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.board-list-title {
	font-size: 1.5rem;
	font-weight: 700;
	color: var(--color-main-text);
	margin: 0;
}

.board-list-count {
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

.board-list-search {
	margin-left: auto;
	display: flex;
	align-items: center;
	gap: 6px;
	height: 36px;
	padding: 0 10px;
	min-width: 220px;
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-pill, 100px);
	box-sizing: border-box;
	color: var(--color-text-maxcontrast);
}

.board-list-search:focus-within {
	border-color: var(--color-primary-element);
}

.board-list-search__icon {
	flex: 0 0 auto;
}

.board-list-search__input {
	flex: 1;
	min-width: 0;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.875rem;
}

.board-list-search__input:focus {
	outline: none;
}

/* ── Segmented toggle ───────────────────────────────────────────────────── */
.board-list-segmented {
	display: flex;
	align-items: center;
	height: 36px;
	padding: 2px;
	border-radius: var(--border-radius-pill, 100px);
	background: var(--color-background-hover);
	box-sizing: border-box;
}

.board-list-segmented__opt {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 32px;
	padding: 0 14px;
	border: none;
	border-radius: var(--border-radius-pill, 100px);
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
	cursor: pointer;
}

.board-list-segmented__opt--active {
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-weight: 600;
	box-shadow: var(--shadow-card-hover, 0 1px 3px rgba(0, 0, 0, 0.15));
}

.board-list-segmented__badge {
	font-size: 0.8rem;
}

/* ── Body ───────────────────────────────────────────────────────────────── */
.board-list-body {
	display: flex;
	flex-direction: column;
	gap: 28px;
}

.board-section {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.board-section__label {
	font-size: 0.8rem;
	font-weight: 700;
	letter-spacing: 0.06em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.board-section__empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
	padding: 8px 0;
}

/* ── Grid & tiles ───────────────────────────────────────────────────────── */
.board-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
	gap: 16px;
}

.board-tile {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 16px 20px;
	background: var(--color-main-background);
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large);
	cursor: pointer;
	text-align: left;
	transition: border-color 0.15s ease, box-shadow 0.15s ease;
	width: 100%;
	box-sizing: border-box;
}

button.board-tile:hover {
	border-color: var(--color-primary-element);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.board-tile--pinned {
	border-color: var(--color-primary-element);
}

.board-tile--archived {
	cursor: default;
	gap: 12px;
	opacity: 0.72;
	background: var(--color-background-hover);
}

.board-tile__actions {
	display: flex;
	justify-content: flex-end;
}

/* ── Skeleton ───────────────────────────────────────────────────────────── */
.board-tile--skeleton {
	cursor: default;
	pointer-events: none;
	flex-direction: row;
	align-items: center;
	gap: 12px;
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

/* ── New board form ─────────────────────────────────────────────────────── */
.new-board-form {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin-bottom: 24px;
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
	border-color: var(--color-primary-element);
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

.board-list__hidden-file {
	display: none;
}

.board-list__import-error {
	width: 100%;
	color: var(--color-error);
	font-size: 0.8rem;
	margin: 0;
}

/* ── Import from Deck modal ─────────────────────────────────────────────── */
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
</style>
