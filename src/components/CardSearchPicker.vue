<!--
  - SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="card-search-picker">
		<input
			ref="searchInputRef"
			v-model="searchTerm"
			class="card-search-picker__input"
			type="text"
			:placeholder="placeholder"
			@keydown.escape.stop="$emit('close')">
		<div v-if="isFetching" class="card-search-picker__status">
			{{ t('kanso', 'Searching…') }}
		</div>
		<ul v-else-if="pickerResults.length" class="card-search-picker__list">
			<li
				v-for="result in pickerResults"
				:key="result.cardId"
				class="card-search-picker__item"
				:class="{ 'card-search-picker__item--disabled': isDisabled(result.cardId) }"
				tabindex="0"
				role="button"
				@click="handlePick(result)"
				@keydown.enter="handlePick(result)">
				<span class="card-search-picker__card-title">{{ result.title }}</span>
				<span v-if="result.boardTitle" class="card-search-picker__meta">{{ result.boardTitle }}</span>
				<span v-else-if="result.snippet" class="card-search-picker__meta">{{ result.snippet }}</span>
				<CheckIcon v-if="isDisabled(result.cardId)" :size="16" class="card-search-picker__check" />
			</li>
		</ul>
		<div v-else-if="debouncedTerm.length >= 2" class="card-search-picker__status">
			{{ t('kanso', 'No cards found.') }}
		</div>
		<div v-else class="card-search-picker__status">
			{{ t('kanso', 'Type at least 2 characters to search.') }}
		</div>
		<span v-if="error" class="card-search-picker__error">{{ error }}</span>
	</div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import { useSearch } from '../composables/useSearch.js'

// A cross-board, ACL-filtered card picker built on the global readable search
// (useSearch with a null boardId = every board the user can read). Extracted
// from ProjectView (#3645) so the "Add to Kanso…" Files action and the project
// card picker share one implementation.
const props = defineProps({
	// Placeholder for the search input.
	placeholder: {
		type: String,
		default: '',
	},
	// Card ids that are already added; rendered checked + non-selectable.
	disabledCardIds: {
		type: Object, // Set<number>
		default: () => new Set(),
	},
	// An error string to surface under the list (e.g. a failed pick).
	error: {
		type: String,
		default: '',
	},
	// Focus the input on mount (e.g. when shown in a popover/dialog).
	autofocus: {
		type: Boolean,
		default: true,
	},
})

const emit = defineEmits(['pick', 'close'])

const searchTerm = ref('')
const searchInputRef = ref(null)

const searchTermRef = computed(() => searchTerm.value)
// boardId null = global, ACL-filtered search across every readable board.
const { results: searchResults, isFetching, debouncedTerm } = useSearch(searchTermRef, ref(null))

// Search returns mixed card + comment rows shaped {type, cardId, boardId, title,
// boardTitle, snippet}; the picker only offers cards, de-duplicated by cardId (a
// card can be matched by both its title and a comment).
const pickerResults = computed(() => {
	const seen = new Set()
	const out = []
	for (const r of searchResults.value) {
		if (r.type !== 'card' || seen.has(r.cardId)) continue
		seen.add(r.cardId)
		out.push(r)
	}
	return out
})

function isDisabled(cardId) {
	return props.disabledCardIds.has(cardId)
}

function handlePick(result) {
	if (isDisabled(result.cardId)) return
	emit('pick', result)
}

const placeholder = computed(() => props.placeholder || t('kanso', 'Search cards across all boards…'))

onMounted(() => {
	if (props.autofocus) {
		nextTick(() => searchInputRef.value?.focus())
	}
})

defineExpose({ focus: () => searchInputRef.value?.focus() })
</script>

<style scoped>
.card-search-picker {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.card-search-picker__input {
	width: 100%;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.9rem;
}

.card-search-picker__input:focus {
	outline: none;
	border-color: var(--color-primary-element);
}

.card-search-picker__status {
	padding: 8px 4px;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.card-search-picker__list {
	list-style: none;
	margin: 0;
	padding: 0;
	max-height: 260px;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.card-search-picker__item {
	display: flex;
	flex-direction: column;
	gap: 1px;
	padding: 8px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
	transition: background 0.1s;
	position: relative;
}

.card-search-picker__item:hover,
.card-search-picker__item:focus-visible {
	background: var(--color-background-hover);
	outline: none;
}

.card-search-picker__item--disabled {
	opacity: 0.55;
	cursor: default;
}

.card-search-picker__card-title {
	font-weight: 500;
	font-size: 0.9rem;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.card-search-picker__meta {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.card-search-picker__check {
	position: absolute;
	right: 10px;
	top: 50%;
	transform: translateY(-50%);
	color: var(--color-success);
}

.card-search-picker__error {
	color: var(--color-error);
	font-size: 0.85rem;
	padding: 0 4px;
}
</style>
