<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		class="search-box"
		:class="{ 'search-box--compact': compact, 'search-box--has-term': term.length > 0 }"
		role="search">
		<!-- Input row with magnify icon -->
		<div class="search-box__input-wrap" @click="focusInput">
			<MagnifyIcon class="search-box__magnify" :size="18" aria-hidden="true" />
			<input
				ref="inputRef"
				v-model="term"
				class="search-box__input"
				type="search"
				autocomplete="off"
				:placeholder="t('kanso', 'Search cards…')"
				:aria-label="t('kanso', 'Search cards in this board')"
				aria-autocomplete="list"
				:aria-expanded="dropdownOpen ? 'true' : 'false'"
				aria-controls="search-box-results"
				:aria-activedescendant="activeIndex >= 0 ? `search-result-${activeIndex}` : undefined"
				@keydown.escape.prevent="close"
				@keydown.arrow-down.prevent="moveActive(1)"
				@keydown.arrow-up.prevent="moveActive(-1)"
				@keydown.enter.prevent="selectActive"
				@blur="handleBlur" />
			<!-- Spinner -->
			<span v-if="isFetching" class="search-box__spinner" aria-hidden="true" />
			<!-- Clear button -->
			<button
				v-else-if="term.length > 0"
				class="search-box__clear"
				type="button"
				:aria-label="t('kanso', 'Clear search')"
				@mousedown.prevent
				@click="clearSearch">
				<CloseIcon :size="16" aria-hidden="true" />
			</button>
		</div>

		<!-- Results dropdown -->
		<ul
			v-if="dropdownOpen"
			id="search-box-results"
			ref="dropdownRef"
			class="search-box__dropdown"
			role="listbox"
			:aria-label="t('kanso', 'Search results')">

			<!-- Fetching placeholder (term ≥2 chars but debounce not yet settled) -->
			<li v-if="isFetching && results.length === 0" class="search-box__status" role="status">
				{{ t('kanso', 'Searching…') }}
			</li>

			<!-- Empty state -->
			<li
				v-else-if="!isFetching && debouncedTerm.length >= 2 && results.length === 0"
				class="search-box__status search-box__status--empty"
				role="status">
				{{ t('kanso', 'No matches for "{q}"', { q: debouncedTerm }) }}
			</li>

			<!-- Result rows -->
			<li
				v-for="(result, idx) in results"
				:id="`search-result-${idx}`"
				:key="resultKey(result, idx)"
				class="search-box__result"
				:class="{ 'search-box__result--active': activeIndex === idx }"
				role="option"
				:aria-selected="activeIndex === idx"
				@mousedown.prevent
				@click="selectResult(result)"
				@mousemove="activeIndex = idx">
				<!-- Type icon -->
				<component
					:is="result.type === 'comment' ? CommentIcon : CardIcon"
					class="search-box__result-type-icon"
					:size="16"
					:aria-label="result.type === 'comment' ? t('kanso', 'Comment') : t('kanso', 'Card')"
					aria-hidden="true" />
				<div class="search-box__result-body">
					<!-- Card title (highlight the matched portion) -->
					<!-- eslint-disable-next-line vue/no-v-html -->
					<span class="search-box__result-title" v-html="highlightTitle(result.title)" />
					<!-- Snippet -->
					<span v-if="result.snippet" class="search-box__result-snippet">
						{{ truncate(result.snippet, 80) }}
					</span>
					<!-- Label for comment hits to distinguish from card hits -->
					<span v-if="result.type === 'comment'" class="search-box__result-badge">
						{{ t('kanso', 'comment') }}
					</span>
				</div>
			</li>
		</ul>
	</div>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import CardIcon from 'vue-material-design-icons/Card.vue'
import CommentIcon from 'vue-material-design-icons/CommentOutline.vue'
import { useSearch } from '../composables/useSearch.js'

const props = defineProps({
	/** Numeric or string board id - scopes the search. */
	boardId: {
		type: [String, Number],
		required: true,
	},
	/** Compact (narrow header): collapse to a magnify icon until focused/non-empty. */
	compact: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['open-card'])

const router = useRouter()

// ── Local state ────────────────────────────────────────────────────────────────
const term = ref('')
const activeIndex = ref(-1)
const inputRef = ref(null)

// Focus the input when the (collapsed) compact box is clicked, so tapping the
// magnify icon expands it (via :focus-within) and lets you type immediately.
function focusInput() {
	inputRef.value?.focus()
}
const dropdownRef = ref(null)

// ── Search composable ──────────────────────────────────────────────────────────
const boardIdRef = computed(() => props.boardId)
const { results, isFetching, debouncedTerm } = useSearch(term, boardIdRef)

// ── Dropdown visibility ────────────────────────────────────────────────────────
// Open whenever the input has ≥2 chars (even while fetching) or when there are
// results to show. Close on blur (after a tick so mousedown-on-result fires first).
const dropdownOpen = computed(
	() =>
		term.value.length >= 2
		&& (isFetching.value || results.value.length > 0 || debouncedTerm.value.length >= 2),
)

// Reset active highlight whenever result set changes
watch(results, () => {
	activeIndex.value = -1
})

// ── Helpers ────────────────────────────────────────────────────────────────────

function resultKey(result, idx) {
	return `${result.type}-${result.cardId}-${result.commentId ?? ''}-${idx}`
}

function truncate(str, max) {
	if (!str || str.length <= max) return str
	return str.slice(0, max).trimEnd() + '…'
}

/**
 * Wrap occurrences of debouncedTerm in the title with <mark> for visual
 * highlighting. DOMPurify is already a dep but the input here is the server-
 * returned card title - we only inject the search term substring, never raw
 * HTML, so we escape the title manually before wrapping.
 */
function highlightTitle(rawTitle) {
	if (!rawTitle) return ''
	const q = debouncedTerm.value
	if (!q || q.length < 2) return escapeHtml(rawTitle)
	const escaped = escapeHtml(rawTitle)
	const pattern = new RegExp(`(${escapeRegex(escapeHtml(q))})`, 'gi')
	return escaped.replace(pattern, '<mark class="search-box__highlight">$1</mark>')
}

function escapeHtml(str) {
	return str
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#39;')
}

function escapeRegex(str) {
	return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

// ── Keyboard navigation ────────────────────────────────────────────────────────

function moveActive(delta) {
	if (!dropdownOpen.value || results.value.length === 0) return
	const max = results.value.length - 1
	activeIndex.value = Math.max(0, Math.min(activeIndex.value + delta, max))
	scrollActiveIntoView()
}

function selectActive() {
	if (activeIndex.value >= 0 && results.value[activeIndex.value]) {
		selectResult(results.value[activeIndex.value])
	}
}

async function scrollActiveIntoView() {
	await nextTick()
	const el = document.getElementById(`search-result-${activeIndex.value}`)
	el?.scrollIntoView({ block: 'nearest' })
}

// ── Actions ────────────────────────────────────────────────────────────────────

function selectResult(result) {
	router.push({
		name: 'card-modal',
		params: { id: String(result.boardId), cardId: String(result.cardId) },
	})
	close()
}

function close() {
	term.value = ''
	activeIndex.value = -1
}

function clearSearch() {
	term.value = ''
	activeIndex.value = -1
	nextTick(() => inputRef.value?.focus())
}

function handleBlur() {
	// Delay so that a mousedown on a result fires its @click first
	setTimeout(() => {
		if (document.activeElement !== inputRef.value) {
			activeIndex.value = -1
		}
	}, 150)
}

// ── Expose for parent focus management ────────────────────────────────────────
defineExpose({ focus: () => inputRef.value?.focus() })
</script>

<style scoped>
.search-box {
	position: relative;
	flex-shrink: 0;
}

/* Compact (narrow header): collapse to just the magnify icon to reclaim space,
   then expand on focus or while a query is present. Pure CSS — the input stays
   mounted so focus/typing keeps working; only its box width animates. */
.search-box--compact {
	flex-shrink: 1;
	min-width: 0;
}

.search-box--compact .search-box__input-wrap {
	min-width: 0;
	width: 40px;
	transition: width 0.15s ease;
}

.search-box--compact .search-box__input {
	/* Hide the text field until expanded so a stray click lands on the icon. */
	opacity: 0;
}

.search-box--compact:focus-within .search-box__input-wrap,
.search-box--compact.search-box--has-term .search-box__input-wrap {
	width: min(240px, 60vw);
}

.search-box--compact:focus-within .search-box__input,
.search-box--compact.search-box--has-term .search-box__input {
	opacity: 1;
}

.search-box__input-wrap {
	display: flex;
	align-items: center;
	gap: 6px;
	height: 36px;
	padding: 0 8px;
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-pill, 18px);
	background: var(--color-main-background);
	transition: border-color 0.15s ease;
	min-width: 200px;
}

.search-box__input-wrap:focus-within {
	border-color: var(--color-primary-element, #0082c9);
}

.search-box__magnify {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.search-box__input {
	flex: 1;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.875rem;
	outline: none;
	min-width: 0;
}

/* Remove browser default clear button on search inputs */
.search-box__input::-webkit-search-cancel-button {
	display: none;
}

.search-box__clear {
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
	width: 20px;
	height: 20px;
}

.search-box__clear:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

/* Spinner */
.search-box__spinner {
	display: inline-block;
	flex-shrink: 0;
	width: 16px;
	height: 16px;
	border: 2px solid var(--color-border);
	border-top-color: var(--color-primary-element, #0082c9);
	border-radius: 50%;
	animation: search-spin 0.6s linear infinite;
}

@keyframes search-spin {
	to { transform: rotate(360deg); }
}

/* Dropdown */
.search-box__dropdown {
	position: absolute;
	top: calc(100% + 4px);
	left: 0;
	right: 0;
	min-width: 320px;
	max-height: 400px;
	overflow-y: auto;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
	z-index: 1000;
	list-style: none;
	margin: 0;
	padding: 4px 0;
}

.search-box__status {
	padding: 12px 16px;
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
}

.search-box__status--empty {
	text-align: center;
}

/* Individual result row */
.search-box__result {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 8px 16px;
	cursor: pointer;
	transition: background 0.1s ease;
}

.search-box__result:hover,
.search-box__result--active {
	background: var(--color-background-hover);
}

.search-box__result-type-icon {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
	margin-top: 2px;
}

.search-box__result-body {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	flex: 1;
}

.search-box__result-title {
	font-size: 0.875rem;
	color: var(--color-main-text);
	font-weight: 500;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.search-box__result-snippet {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.search-box__result-badge {
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

/* Highlight mark */
:deep(.search-box__highlight) {
	background: rgba(var(--color-primary-rgb, 0, 130, 201), 0.2);
	color: var(--color-main-text);
	border-radius: 2px;
	padding: 0 1px;
}
</style>
