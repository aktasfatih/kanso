<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal
		v-if="open"
		class="command-palette-modal"
		:name="t('kanso', 'Command palette')"
		:show-maximize="false"
		size="normal"
		@close="$emit('close')">
		<div class="command-palette" role="dialog" aria-modal="true" :aria-label="t('kanso', 'Command palette')">
			<!-- Search input row -->
			<div class="command-palette__input-wrap">
				<MagnifyIcon class="command-palette__magnify" :size="18" aria-hidden="true" />
				<input
					ref="inputRef"
					v-model="query"
					class="command-palette__input"
					type="search"
					autocomplete="off"
					:placeholder="t('kanso', 'Search boards and cards…')"
					:aria-label="t('kanso', 'Search boards and cards')"
					aria-autocomplete="list"
					aria-controls="command-palette-results"
					:aria-activedescendant="activeIndex >= 0 ? `cp-result-${activeIndex}` : undefined"
					@keydown.escape.prevent="$emit('close')"
					@keydown.arrow-down.prevent="moveActive(1)"
					@keydown.arrow-up.prevent="moveActive(-1)"
					@keydown.enter.prevent="selectActive" />
				<span v-if="isFetchingCards" class="command-palette__spinner" aria-hidden="true" />
				<button
					v-else-if="query.length > 0"
					class="command-palette__clear"
					type="button"
					:aria-label="t('kanso', 'Clear search')"
					@mousedown.prevent
					@click="query = ''">
					<CloseIcon :size="16" aria-hidden="true" />
				</button>
			</div>

			<!-- Results list -->
			<ul
				id="command-palette-results"
				ref="listRef"
				class="command-palette__results"
				role="listbox"
				:aria-label="t('kanso', 'Results')">

				<!-- Loading state (cards still fetching, boards already filtered) -->
				<li
					v-if="isFetchingCards && query.length >= 2 && flatResults.length === 0"
					class="command-palette__status"
					role="status">
					{{ t('kanso', 'Searching…') }}
				</li>

				<!-- Empty state: query entered but no results from either source -->
				<li
					v-else-if="!isFetchingCards && query.length >= 2 && flatResults.length === 0"
					class="command-palette__status command-palette__status--empty"
					role="status">
					{{ t('kanso', 'No results for "{q}"', { q: query }) }}
				</li>

				<!-- Empty query hint -->
				<li
					v-else-if="query.length === 0 && matchingBoards.length === 0"
					class="command-palette__status"
					role="status">
					{{ t('kanso', 'Type to search boards and cards…') }}
				</li>

				<template v-for="(section, sIdx) in sections" :key="sIdx">
					<!-- Section heading -->
					<li class="command-palette__section-heading" role="presentation" aria-hidden="true">
						{{ section.label }}
					</li>

					<!-- Section results -->
					<li
						v-for="item in section.items"
						:id="`cp-result-${item.flatIndex}`"
						:key="item.key"
						class="command-palette__result"
						:class="{ 'command-palette__result--active': activeIndex === item.flatIndex }"
						role="option"
						:aria-selected="activeIndex === item.flatIndex"
						@mousedown.prevent
						@click="selectItem(item)"
						@mousemove="activeIndex = item.flatIndex">
						<component
							:is="item.icon"
							class="command-palette__result-icon"
							:size="16"
							aria-hidden="true" />
						<div class="command-palette__result-body">
							<!-- eslint-disable-next-line vue/no-v-html -->
							<span class="command-palette__result-title" v-html="item.highlightedTitle" />
							<span v-if="item.snippet" class="command-palette__result-snippet">{{ item.snippet }}</span>
							<span v-if="item.badge" class="command-palette__result-badge">{{ item.badge }}</span>
						</div>
					</li>
				</template>
			</ul>

			<!-- Keyboard hint footer -->
			<div class="command-palette__footer" aria-hidden="true">
				<span class="command-palette__hint"><kbd>↑</kbd> <kbd>↓</kbd> {{ t('kanso', 'navigate') }}</span>
				<span class="command-palette__hint"><kbd>Enter</kbd> {{ t('kanso', 'open') }}</span>
				<span class="command-palette__hint"><kbd>Esc</kbd> {{ t('kanso', 'close') }}</span>
			</div>
		</div>
	</NcModal>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import NcModal from '@nextcloud/vue/components/NcModal'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import ViewDashboardIcon from 'vue-material-design-icons/ViewDashboard.vue'
import CardIcon from 'vue-material-design-icons/Card.vue'
import CommentIcon from 'vue-material-design-icons/CommentOutline.vue'
import { useBoards } from '../composables/useBoards.js'
import { useSearch } from '../composables/useSearch.js'

const props = defineProps({
	open: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['close'])

const router = useRouter()

// ── Local state ────────────────────────────────────────────────────────────────
const query = ref('')
const activeIndex = ref(-1)
const inputRef = ref(null)
const listRef = ref(null)

// ── Board data (client-side filtered) ─────────────────────────────────────────
const { data: boardsData } = useBoards()

const allBoards = computed(() => boardsData.value ?? [])

// ── Card/comment search (server-side, cross-board) ─────────────────────────────
// useSearch requires a boardId; passing null/undefined disables the board scope
// so the server returns cross-board results. The API already supports omitting
// boardId to search across all accessible boards.
const CROSS_BOARD_ID = null
const crossBoardId = ref(CROSS_BOARD_ID)
const { results: cardResults, isFetching: isFetchingCards, debouncedTerm } = useSearch(query, crossBoardId)

// ── Client-side board filtering ────────────────────────────────────────────────
function escapeRegex(str) {
	return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

function escapeHtml(str) {
	if (!str) return ''
	return str
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#39;')
}

function highlightMatch(rawTitle, term) {
	if (!rawTitle) return ''
	const escaped = escapeHtml(rawTitle)
	if (!term || term.length < 1) return escaped
	const pattern = new RegExp(`(${escapeRegex(escapeHtml(term))})`, 'gi')
	return escaped.replace(pattern, '<mark class="command-palette__highlight">$1</mark>')
}

function truncate(str, max) {
	if (!str || str.length <= max) return str
	return str.slice(0, max).trimEnd() + '…'
}

const matchingBoards = computed(() => {
	const q = query.value.trim().toLowerCase()
	if (!q) {
		// Empty query: show up to 5 most recent boards (preserve server order)
		return allBoards.value.slice(0, 5)
	}
	return allBoards.value.filter((b) =>
		b.title?.toLowerCase().includes(q),
	)
})

// ── Merged / sectioned results ─────────────────────────────────────────────────

/**
 * Build a flat list + sections for rendering and keyboard navigation.
 * Sections: Boards first, then Cards/Comments from server search.
 */
const sections = computed(() => {
	const q = query.value.trim()
	const result = []
	let flatIdx = 0

	// Board section
	if (matchingBoards.value.length > 0) {
		result.push({
			label: t('kanso', 'Boards'),
			items: matchingBoards.value.map((board) => ({
				key: `board-${board.id}`,
				flatIndex: flatIdx++,
				icon: ViewDashboardIcon,
				highlightedTitle: highlightMatch(board.title, q),
				snippet: null,
				badge: null,
				// navigation payload
				type: 'board',
				boardId: board.id,
				cardId: null,
			})),
		})
	}

	// Card/comment section (only when query ≥ 2 chars - mirrors SearchBox)
	if (debouncedTerm.value.length >= 2 && cardResults.value.length > 0) {
		result.push({
			label: t('kanso', 'Cards'),
			items: cardResults.value.map((r) => ({
				key: `card-${r.type}-${r.cardId}-${r.commentId ?? ''}`,
				flatIndex: flatIdx++,
				icon: r.type === 'comment' ? CommentIcon : CardIcon,
				highlightedTitle: highlightMatch(r.title, debouncedTerm.value),
				snippet: r.snippet ? truncate(r.snippet, 80) : null,
				badge: r.type === 'comment' ? t('kanso', 'comment') : null,
				// navigation payload
				type: 'card',
				boardId: r.boardId,
				cardId: r.cardId,
			})),
		})
	}

	return result
})

// Flat result list for keyboard navigation bounds
const flatResults = computed(() => sections.value.flatMap((s) => s.items))

// Reset active index when results change
watch(flatResults, () => {
	activeIndex.value = -1
})

// Auto-focus input when palette opens; reset state when it closes
watch(
	() => props.open,
	async (isOpen) => {
		if (isOpen) {
			query.value = ''
			activeIndex.value = -1
			await nextTick()
			inputRef.value?.focus()
		}
	},
)

// ── Keyboard navigation ────────────────────────────────────────────────────────

function moveActive(delta) {
	if (flatResults.value.length === 0) return
	const max = flatResults.value.length - 1
	if (activeIndex.value < 0) {
		activeIndex.value = delta > 0 ? 0 : max
	} else {
		activeIndex.value = Math.max(0, Math.min(activeIndex.value + delta, max))
	}
	scrollActiveIntoView()
}

async function scrollActiveIntoView() {
	await nextTick()
	const el = document.getElementById(`cp-result-${activeIndex.value}`)
	el?.scrollIntoView({ block: 'nearest' })
}

function selectActive() {
	if (activeIndex.value >= 0) {
		const item = flatResults.value[activeIndex.value]
		if (item) selectItem(item)
	} else if (flatResults.value.length > 0) {
		// Enter with no explicit active: pick first result
		selectItem(flatResults.value[0])
	}
}

// ── Navigation ─────────────────────────────────────────────────────────────────

function selectItem(item) {
	if (item.type === 'board') {
		router.push({ name: 'board', params: { id: String(item.boardId) } })
	} else {
		// card or comment - mirror SearchBox.selectResult exactly
		router.push({
			name: 'card-modal',
			params: { id: String(item.boardId), cardId: String(item.cardId) },
		})
	}
	emit('close')
}
</script>

<style scoped>
/* Palette content - sits inside NcModal's content area */
.command-palette {
	display: flex;
	flex-direction: column;
	min-height: 0;
	max-height: 70vh;
}

/* Input row */
.command-palette__input-wrap {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 16px;
	border-bottom: 1px solid var(--color-border);
	flex-shrink: 0;
}

.command-palette__magnify {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.command-palette__input {
	flex: 1;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	font-size: 1rem;
	outline: none;
	min-width: 0;
}

.command-palette__input::-webkit-search-cancel-button {
	display: none;
}

.command-palette__clear {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 0;
	border: none;
	background: none;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	flex-shrink: 0;
	border-radius: 50%;
	width: 24px;
	height: 24px;
}

.command-palette__clear:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

/* Spinner */
.command-palette__spinner {
	display: inline-block;
	flex-shrink: 0;
	width: 16px;
	height: 16px;
	border: 2px solid var(--color-border);
	border-top-color: var(--color-primary-element, #0082c9);
	border-radius: 50%;
	animation: cp-spin 0.6s linear infinite;
}

@keyframes cp-spin {
	to { transform: rotate(360deg); }
}

/* Results list */
.command-palette__results {
	flex: 1;
	overflow-y: auto;
	list-style: none;
	margin: 0;
	padding: 4px 0;
	min-height: 0;
}

/* Status / empty */
.command-palette__status {
	padding: 20px 16px;
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
	text-align: center;
}

/* Section heading */
.command-palette__section-heading {
	padding: 6px 16px 2px;
	font-size: 0.7rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	color: var(--color-text-maxcontrast);
	user-select: none;
}

/* Individual result row */
.command-palette__result {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 8px 16px;
	cursor: pointer;
	transition: background 0.1s ease;
}

.command-palette__result:hover,
.command-palette__result--active {
	background: var(--color-background-hover);
}

.command-palette__result-icon {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
	margin-top: 2px;
}

.command-palette__result-body {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	flex: 1;
}

.command-palette__result-title {
	font-size: 0.875rem;
	color: var(--color-main-text);
	font-weight: 500;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.command-palette__result-snippet {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.command-palette__result-badge {
	display: inline-block;
	font-size: 0.7rem;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: 3px;
	padding: 0 4px;
	line-height: 1.5;
	align-self: flex-start;
}

/* Highlight mark for query matches */
:deep(.command-palette__highlight) {
	background: rgba(var(--color-primary-rgb, 0, 130, 201), 0.2);
	color: var(--color-main-text);
	border-radius: 2px;
	padding: 0 1px;
}

/* Footer keyboard hints */
.command-palette__footer {
	display: flex;
	gap: 16px;
	padding: 8px 16px;
	border-top: 1px solid var(--color-border);
	flex-shrink: 0;
}

.command-palette__hint {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.command-palette__hint kbd {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	padding: 1px 5px;
	border: 1px solid var(--color-border);
	border-radius: 3px;
	background: var(--color-background-hover);
	font-family: inherit;
	font-size: 0.7rem;
	color: var(--color-main-text);
}
</style>
