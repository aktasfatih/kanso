<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="project-view">
		<!-- Loading state -->
		<div v-if="isLoadingCards && !cards.length" class="project-view__loading" aria-live="polite">
			<span class="project-view__spinner" aria-hidden="true" />
			<span>{{ t('kanso', 'Loading project…') }}</span>
		</div>

		<!-- Error state -->
		<div v-else-if="isError" class="project-view__error">
			{{ t('kanso', 'Failed to load project cards. Please try again.') }}
		</div>

		<template v-else>
			<!-- Header -->
			<div class="project-view__header">
				<div class="project-view__header-left">
					<button class="project-view__back" :title="t('kanso', 'Back to projects')" @click="router.push({ name: 'projects' })">
						<ChevronLeftIcon :size="20" />
					</button>
					<span
						v-if="project?.color"
						class="project-view__dot"
						:style="{ background: '#' + project.color }" />
					<h1 class="project-view__title">{{ project?.title ?? t('kanso', 'Project') }}</h1>
					<!-- eslint-disable-next-line vue/no-v-html — renderMarkdown sanitises via DOMPurify -->
					<div
						v-if="project?.description"
						class="project-view__desc"
						v-html="renderMarkdown(project.description)" />
				</div>

				<div class="project-view__header-actions">
					<button
						class="project-view__analytics-btn"
						:title="t('kanso', 'Project analytics')"
						:aria-label="t('kanso', 'Project analytics')"
						@click="goToStats">
						<ChartBarIcon :size="20" />
					</button>

					<NcActions :force-menu="true">
						<NcActionButton :close-after-click="true" @click="openEditDialog">
							<template #icon>
								<PencilIcon :size="20" />
							</template>
							{{ t('kanso', 'Edit project') }}
						</NcActionButton>
						<NcActionSeparator />
						<NcActionButton :close-after-click="true" @click="handleDelete">
							<template #icon>
								<TrashCanIcon :size="20" />
							</template>
							{{ t('kanso', 'Delete project') }}
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<span v-if="actionError" class="project-view__action-error">{{ actionError }}</span>

			<!-- Add card control -->
			<div class="project-view__add-row">
				<button
					class="project-view__add-btn"
					:aria-expanded="showPicker"
					@click="togglePicker">
					<PlusIcon :size="16" />
					{{ t('kanso', 'Add card') }}
				</button>

				<div v-if="showPicker" class="project-view__picker">
					<input
						ref="searchInputRef"
						v-model="searchTerm"
						class="project-view__picker-input"
						type="text"
						:placeholder="t('kanso', 'Search cards across all boards…')"
						@keydown.escape.stop="closePicker">
					<div v-if="isFetching" class="project-view__picker-searching">
						{{ t('kanso', 'Searching…') }}
					</div>
					<ul v-else-if="pickerResults.length" class="project-view__picker-list">
						<li
							v-for="result in pickerResults"
							:key="result.cardId"
							class="project-view__picker-item"
							:class="{ 'project-view__picker-item--added': isCardInProject(result.cardId) }"
							tabindex="0"
							role="button"
							@click="handlePickCard(result)"
							@keydown.enter="handlePickCard(result)">
							<span class="project-view__picker-card-title">{{ result.title }}</span>
							<span v-if="result.snippet" class="project-view__picker-meta">{{ result.snippet }}</span>
							<CheckIcon v-if="isCardInProject(result.cardId)" :size="16" class="project-view__picker-check" />
						</li>
					</ul>
					<div v-else-if="debouncedTerm.length >= 2" class="project-view__picker-empty">
						{{ t('kanso', 'No cards found.') }}
					</div>
					<div v-else class="project-view__picker-hint">
						{{ t('kanso', 'Type at least 2 characters to search.') }}
					</div>
					<span v-if="addError" class="project-view__picker-error">{{ addError }}</span>
				</div>
			</div>

			<!-- Empty state -->
			<NcEmptyContent
				v-if="!cards.length && !isLoadingCards"
				:name="t('kanso', 'No cards in this project')"
				:description="t('kanso', 'Use the Add card button above to collect cards from any board.')">
				<template #icon>
					<FolderMultipleOutlineIcon :size="64" />
				</template>
			</NcEmptyContent>

			<!-- Cards grouped by board -->
			<template v-else>
				<section
					v-for="group in groups"
					:key="group.boardId"
					class="project-view__section">
					<h2 class="project-view__section-title">{{ group.boardTitle }}</h2>
					<ul class="project-view__list">
						<li
							v-for="card in group.cards"
							:key="card.id"
							class="project-view__row"
							tabindex="0"
							role="button"
							@click="openCard(card)"
							@keydown.enter="openCard(card)">
							<div class="project-view__row-main">
								<span class="project-view__card-title">{{ card.title }}</span>
								<span class="project-view__meta">
									<span class="project-view__stack">{{ card.stackTitle }}</span>
								</span>
							</div>
							<span
								v-if="card.duedate"
								class="project-view__due">
								{{ formatDue(card.duedate) }}
							</span>
							<NcActions :force-menu="false">
								<NcActionButton
									:close-after-click="true"
									@click.stop="handleRemoveCard(card.id)">
									<template #icon>
										<CloseIcon :size="20" />
									</template>
									{{ t('kanso', 'Remove from project') }}
								</NcActionButton>
							</NcActions>
						</li>
					</ul>
				</section>
			</template>
		</template>

		<!-- Edit project dialog -->
		<NcDialog
			v-if="showEditDialog"
			:name="t('kanso', 'Edit project')"
			:open="showEditDialog"
			@closing="closeEditDialog">
			<div class="project-view__form">
				<label class="project-view__form-label" for="edit-project-title">
					{{ t('kanso', 'Title') }}
					<span aria-hidden="true" class="project-view__required">*</span>
				</label>
				<input
					id="edit-project-title"
					ref="editTitleInputRef"
					v-model="editTitle"
					class="project-view__form-input"
					type="text"
					:placeholder="t('kanso', 'Project title')"
					:disabled="updateMutation.isPending.value"
					@keydown.enter.prevent="submitEdit">

				<label class="project-view__form-label" for="edit-project-desc">
					{{ t('kanso', 'Description') }}
				</label>
				<div class="project-view__md-toolbar" role="toolbar" :aria-label="t('kanso', 'Formatting')">
					<button type="button" class="project-view__md-btn" :title="t('kanso', 'Bold')" @mousedown.prevent @click="mdToolbar.bold()"><FormatBoldIcon :size="18" /></button>
					<button type="button" class="project-view__md-btn" :title="t('kanso', 'Italic')" @mousedown.prevent @click="mdToolbar.italic()"><FormatItalicIcon :size="18" /></button>
					<button type="button" class="project-view__md-btn" :title="t('kanso', 'Heading')" @mousedown.prevent @click="mdToolbar.heading()"><FormatHeaderPoundIcon :size="18" /></button>
					<span class="project-view__md-sep" />
					<button type="button" class="project-view__md-btn" :title="t('kanso', 'Bulleted list')" @mousedown.prevent @click="mdToolbar.bulletList()"><FormatListBulletedIcon :size="18" /></button>
					<button type="button" class="project-view__md-btn" :title="t('kanso', 'Quote')" @mousedown.prevent @click="mdToolbar.quote()"><FormatQuoteCloseIcon :size="18" /></button>
					<span class="project-view__md-sep" />
					<button type="button" class="project-view__md-btn" :title="t('kanso', 'Inline code')" @mousedown.prevent @click="mdToolbar.inlineCode()"><CodeTagsIcon :size="18" /></button>
					<button type="button" class="project-view__md-btn" :title="t('kanso', 'Link')" @mousedown.prevent @click="mdToolbar.link()"><LinkVariantIcon :size="18" /></button>
					<span class="project-view__md-toolbar-spacer" />
					<button
						type="button"
						class="project-view__md-btn"
						:class="{ 'project-view__md-btn--active': showDescPreview }"
						:aria-pressed="showDescPreview"
						:title="t('kanso', 'Toggle preview')"
						@mousedown.prevent
						@click="showDescPreview = !showDescPreview"><EyeOutlineIcon :size="18" /></button>
				</div>
				<textarea
					id="edit-project-desc"
					ref="descTextareaRef"
					v-model="editDescription"
					class="project-view__form-input project-view__form-textarea"
					rows="6"
					:placeholder="t('kanso', 'Optional description — markdown supported')"
					:disabled="updateMutation.isPending.value" />
				<div v-if="showDescPreview" class="project-view__desc-preview">
					<span class="project-view__desc-preview-label">{{ t('kanso', 'Preview') }}</span>
					<!-- eslint-disable-next-line vue/no-v-html — renderMarkdown sanitises via DOMPurify -->
					<div class="project-view__desc-rendered" v-html="renderMarkdown(editDescription)" />
				</div>

				<label class="project-view__form-label">{{ t('kanso', 'Color') }}</label>
				<div class="project-view__color-grid">
					<button
						v-for="preset in COLOR_PRESETS"
						:key="preset"
						type="button"
						class="project-view__color-swatch"
						:class="{ 'project-view__color-swatch--active': editColor === preset }"
						:style="{ background: '#' + preset }"
						:title="'#' + preset"
						:aria-pressed="editColor === preset"
						@click="editColor = editColor === preset ? '' : preset" />
					<button
						type="button"
						class="project-view__color-swatch project-view__color-swatch--clear"
						:class="{ 'project-view__color-swatch--active': !editColor }"
						:title="t('kanso', 'No color')"
						:aria-pressed="!editColor"
						@click="editColor = ''">
						×
					</button>
				</div>

				<span v-if="editError" class="project-view__form-error">{{ editError }}</span>
			</div>

			<template #actions>
				<NcButton :disabled="updateMutation.isPending.value || !editTitle.trim()" type="primary" @click="submitEdit">
					{{ t('kanso', 'Save') }}
				</NcButton>
				<NcButton @click="closeEditDialog">
					{{ t('kanso', 'Cancel') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script setup>
import { ref, computed, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import TrashCanIcon from 'vue-material-design-icons/TrashCan.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import ChevronLeftIcon from 'vue-material-design-icons/ChevronLeft.vue'
import ChartBarIcon from 'vue-material-design-icons/ChartBar.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import FolderMultipleOutlineIcon from 'vue-material-design-icons/FolderMultipleOutline.vue'
import FormatBoldIcon from 'vue-material-design-icons/FormatBold.vue'
import FormatItalicIcon from 'vue-material-design-icons/FormatItalic.vue'
import FormatHeaderPoundIcon from 'vue-material-design-icons/FormatHeaderPound.vue'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'
import FormatQuoteCloseIcon from 'vue-material-design-icons/FormatQuoteClose.vue'
import CodeTagsIcon from 'vue-material-design-icons/CodeTags.vue'
import LinkVariantIcon from 'vue-material-design-icons/LinkVariant.vue'
import EyeOutlineIcon from 'vue-material-design-icons/EyeOutline.vue'
import { useProjects } from '../composables/useProjects.js'
import { useProjectCards } from '../composables/useProject.js'
import { useSearch } from '../composables/useSearch.js'
import { renderMarkdown } from '../services/markdown.js'
import { useMarkdownToolbar } from '../composables/useMarkdownToolbar.js'

const COLOR_PRESETS = [
	'e53935', 'f4511e', 'f6bf26', '33b679', '0b8043',
	'039be5', '3f51b5', '7986cb', '8e24aa', '616161',
]

const props = defineProps({
	id: {
		type: String,
		required: true,
	},
})

const router = useRouter()

// Resolve the project from the list query (already in cache)
const { data: projectsData, update: updateMutation, remove: removeMutation } = useProjects()
const project = computed(() => (projectsData.value ?? []).find((p) => String(p.id) === String(props.id)) ?? null)

const {
	data: cardsData,
	isLoading: isLoadingCards,
	isError,
	addCard,
	removeCard,
} = useProjectCards(computed(() => props.id))

const cards = computed(() => cardsData.value ?? [])

// Group cards by boardTitle, preserving server order within each group
const groups = computed(() => {
	const map = new Map()
	for (const card of cards.value) {
		const key = card.boardId
		if (!map.has(key)) {
			map.set(key, { boardId: card.boardId, boardTitle: card.boardTitle, cards: [] })
		}
		map.get(key).cards.push(card)
	}
	return Array.from(map.values())
})

function formatDue(iso) {
	try {
		return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
	} catch {
		return iso
	}
}

function openCard(card) {
	router.push({ name: 'card-modal', params: { id: String(card.boardId), cardId: String(card.id) } })
}

function goToStats() {
	router.push({ name: 'project-stats', params: { id: String(props.id) } })
}

// ── Card picker (cross-board search) ────────────────────────────────────────
const showPicker = ref(false)
const searchTerm = ref('')
const searchInputRef = ref(null)
const addError = ref('')

// useSearch expects a ref for term; boardId null = global search
const searchTermRef = computed(() => searchTerm.value)
const { results: searchResults, isFetching, debouncedTerm } = useSearch(searchTermRef, ref(null))

// Search returns mixed card + comment rows shaped {type, cardId, boardId, title,
// snippet}; the picker only offers cards, de-duplicated by cardId (a card can be
// matched by both its title and a comment).
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

const cardIdsInProject = computed(() => new Set((cardsData.value ?? []).map((c) => c.id)))

function isCardInProject(cardId) {
	return cardIdsInProject.value.has(cardId)
}

function togglePicker() {
	showPicker.value = !showPicker.value
	if (showPicker.value) {
		searchTerm.value = ''
		addError.value = ''
		nextTick(() => searchInputRef.value?.focus())
	}
}

function closePicker() {
	showPicker.value = false
}

async function handlePickCard(result) {
	if (isCardInProject(result.cardId)) return
	addError.value = ''
	try {
		await addCard.mutateAsync(result.cardId)
	} catch (err) {
		addError.value = err?.response?.data?.error || t('kanso', 'Failed to add card.')
	}
}

async function handleRemoveCard(cardId) {
	actionError.value = ''
	try {
		await removeCard.mutateAsync(cardId)
	} catch (err) {
		actionError.value = err?.response?.data?.error || t('kanso', 'Failed to remove card.')
	}
}

// ── Delete project ───────────────────────────────────────────────────────────
const actionError = ref('')

async function handleDelete() {
	actionError.value = ''
	try {
		await removeMutation.mutateAsync(Number(props.id))
		router.push({ name: 'projects' })
	} catch (err) {
		actionError.value = err?.response?.data?.error || t('kanso', 'Failed to delete project.')
	}
}

// ── Edit project dialog ──────────────────────────────────────────────────────
const showEditDialog = ref(false)
const editTitle = ref('')
const editDescription = ref('')
const editColor = ref('')
const editError = ref('')
const editTitleInputRef = ref(null)
const descTextareaRef = ref(null)
const showDescPreview = ref(false)

// Markdown formatting toolbar over the description textarea (mutates the markdown
// string in place; the preview reuses the same renderMarkdown as the read view).
const mdToolbar = useMarkdownToolbar({
	getText: () => editDescription.value,
	setText: (v) => { editDescription.value = v },
	textareaRef: descTextareaRef,
})

function openEditDialog() {
	editTitle.value = project.value?.title ?? ''
	editDescription.value = project.value?.description ?? ''
	editColor.value = project.value?.color ?? ''
	editError.value = ''
	showDescPreview.value = false
	showEditDialog.value = true
	nextTick(() => editTitleInputRef.value?.focus())
}

function closeEditDialog() {
	showEditDialog.value = false
}

async function submitEdit() {
	const title = editTitle.value.trim()
	if (!title || updateMutation.isPending.value) return
	editError.value = ''
	try {
		await updateMutation.mutateAsync({
			id: Number(props.id),
			title,
			description: editDescription.value.trim() || undefined,
			color: editColor.value || undefined,
		})
		closeEditDialog()
	} catch (err) {
		editError.value = err?.response?.data?.error || t('kanso', 'Failed to update project.')
	}
}
</script>

<style scoped>
.project-view {
	padding: 24px 32px;
	max-width: 860px;
}

.project-view__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 20px;
	gap: 12px;
}

.project-view__header-left {
	display: flex;
	align-items: center;
	gap: 10px;
	min-width: 0;
}

.project-view__back {
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	display: flex;
	align-items: center;
	border-radius: var(--border-radius);
	padding: 4px;
	transition: color 0.15s, background 0.15s;
	flex-shrink: 0;
}

.project-view__back:hover {
	color: var(--color-main-text);
	background: var(--color-background-hover);
}

.project-view__header-actions {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
}

.project-view__analytics-btn {
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: var(--border-radius);
	padding: 6px;
	transition: color 0.15s, background 0.15s;
}

.project-view__analytics-btn:hover {
	color: var(--color-main-text);
	background: var(--color-background-hover);
}

.project-view__dot {
	flex-shrink: 0;
	width: 14px;
	height: 14px;
	border-radius: 50%;
	border: 1px solid var(--color-border-dark);
}

.project-view__title {
	font-size: 1.5rem;
	font-weight: 600;
	margin: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.project-view__desc {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	max-width: 340px;
	/* Markdown can be multi-block; clamp to two lines in the header so long
	   descriptions truncate gracefully instead of being clipped mid-block. */
	overflow: hidden;
	display: -webkit-box;
	-webkit-box-orient: vertical;
	-webkit-line-clamp: 2;
	line-clamp: 2;
}

/* Markdown produces block <p>/<ul>… — flatten it to inline flow so the two-line
   clamp above reads as a compact snippet rather than stacked blocks. */
.project-view__desc :deep(p),
.project-view__desc :deep(ul),
.project-view__desc :deep(ol) { margin: 0; padding: 0; display: inline; }
.project-view__desc :deep(li) { display: inline; margin-right: 0.6em; }
.project-view__desc :deep(code) {
	background: var(--color-border);
	border-radius: 3px;
	padding: 1px 4px;
	font-size: 0.85em;
}

.project-view__loading {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--color-text-maxcontrast);
	padding: 32px 0;
}

.project-view__spinner {
	display: inline-block;
	width: 20px;
	height: 20px;
	border: 2px solid var(--color-border);
	border-top-color: var(--color-primary-element);
	border-radius: 50%;
	animation: project-spin 0.7s linear infinite;
}

@keyframes project-spin {
	to { transform: rotate(360deg); }
}

.project-view__error {
	color: var(--color-error);
	padding: 16px 0;
}

.project-view__action-error {
	display: block;
	color: var(--color-error);
	font-size: 0.85rem;
	margin-bottom: 12px;
}

.project-view__add-row {
	position: relative;
	margin-bottom: 20px;
}

.project-view__add-btn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px;
	border: 1px dashed var(--color-border-dark);
	border-radius: var(--border-radius-large, 8px);
	background: none;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	transition: color 0.15s, border-color 0.15s, background 0.15s;
}

.project-view__add-btn:hover {
	color: var(--color-main-text);
	border-color: var(--color-primary-element);
	background: var(--color-background-hover);
}

.project-view__picker {
	position: absolute;
	top: calc(100% + 6px);
	left: 0;
	z-index: 100;
	width: 420px;
	max-width: calc(100vw - 48px);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	box-shadow: 0 4px 24px rgba(0, 0, 0, 0.12);
	padding: 8px;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.project-view__picker-input {
	width: 100%;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.9rem;
}

.project-view__picker-input:focus {
	outline: none;
	border-color: var(--color-primary-element);
}

.project-view__picker-searching,
.project-view__picker-empty,
.project-view__picker-hint {
	padding: 8px 4px;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.project-view__picker-list {
	list-style: none;
	margin: 0;
	padding: 0;
	max-height: 260px;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.project-view__picker-item {
	display: flex;
	flex-direction: column;
	gap: 1px;
	padding: 8px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
	transition: background 0.1s;
	position: relative;
}

.project-view__picker-item:hover,
.project-view__picker-item:focus-visible {
	background: var(--color-background-hover);
	outline: none;
}

.project-view__picker-item--added {
	opacity: 0.55;
	cursor: default;
}

.project-view__picker-card-title {
	font-weight: 500;
	font-size: 0.9rem;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.project-view__picker-meta {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.project-view__picker-check {
	position: absolute;
	right: 10px;
	top: 50%;
	transform: translateY(-50%);
	color: var(--color-success);
}

.project-view__picker-error {
	color: var(--color-error);
	font-size: 0.85rem;
	padding: 0 4px;
}

.project-view__section {
	margin-bottom: 32px;
}

.project-view__section-title {
	font-size: 0.85rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	color: var(--color-text-maxcontrast);
	margin: 0 0 10px;
}

.project-view__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.project-view__row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 14px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
	cursor: pointer;
	transition: background 0.15s;
}

.project-view__row:hover,
.project-view__row:focus-visible {
	background: var(--color-border-dark);
	outline: none;
}

.project-view__row-main {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1;
	min-width: 0;
}

.project-view__card-title {
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.project-view__meta {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.project-view__due {
	flex-shrink: 0;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.project-view__form {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 4px 0;
}

.project-view__form-label {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}

.project-view__required {
	color: var(--color-error);
	margin-left: 2px;
}

.project-view__form-input {
	width: 100%;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.9rem;
}

.project-view__form-input:focus {
	outline: none;
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.project-view__color-grid {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: 2px;
}

.project-view__color-swatch {
	width: 24px;
	height: 24px;
	border-radius: 50%;
	border: 2px solid transparent;
	cursor: pointer;
	transition: border-color 0.1s, transform 0.1s;
}

.project-view__color-swatch:hover {
	transform: scale(1.15);
}

.project-view__color-swatch--active {
	border-color: var(--color-main-text);
}

.project-view__color-swatch--clear {
	background: var(--color-background-hover);
	border-color: var(--color-border);
	font-size: 14px;
	line-height: 1;
	color: var(--color-text-maxcontrast);
	display: flex;
	align-items: center;
	justify-content: center;
}

.project-view__form-error {
	color: var(--color-error);
	font-size: 0.85rem;
}

.project-view__form-textarea {
	resize: vertical;
	min-height: 120px;
	line-height: 1.6;
	font-family: inherit;
}

.project-view__md-toolbar {
	display: flex;
	align-items: center;
	gap: 2px;
	margin-bottom: 6px;
	padding: 3px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-background-hover);
	flex-wrap: wrap;
}

.project-view__md-toolbar-spacer { flex: 1 1 auto; }

.project-view__md-sep {
	width: 1px;
	align-self: stretch;
	margin: 2px 4px;
	background: var(--color-border);
}

.project-view__md-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	border: none;
	border-radius: 6px;
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
}

.project-view__md-btn:hover { background: var(--color-background-dark); }

.project-view__md-btn--active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.project-view__desc-preview {
	margin: 8px 0 0;
	padding: 10px 14px;
	border: 1px dashed var(--color-border);
	border-radius: 10px;
	background: var(--color-main-background);
}

.project-view__desc-preview-label {
	display: block;
	margin-bottom: 4px;
	font-size: 0.7rem;
	font-weight: 700;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}

.project-view__desc-rendered {
	font-size: 0.9375rem;
	line-height: 1.65;
	color: var(--color-main-text);
}

.project-view__desc-rendered :deep(p) { margin: 0 0 0.7em; }
.project-view__desc-rendered :deep(p:last-child) { margin-bottom: 0; }
.project-view__desc-rendered :deep(code) {
	background: var(--color-border);
	border-radius: 3px;
	padding: 2px 5px;
	font-size: 0.875em;
}
.project-view__desc-rendered :deep(pre) {
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 10px 12px;
	overflow: auto;
}
</style>
