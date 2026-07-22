<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal
		:show="true"
		:name="cardTitle"
		size="normal"
		@close="closeModal">
		<div class="card-modal">
			<!-- Loading state -->
			<div v-if="isLoading" class="card-modal__loading">
				<div class="skeleton-text card-modal__title-skeleton" />
				<div class="skeleton-text card-modal__desc-skeleton" />
			</div>

			<!-- Error state -->
			<div v-else-if="isError" class="card-modal__error">
				{{ t('kanso', 'Failed to load card details.') }}
			</div>

			<!-- Card content -->
			<template v-else-if="cardData">
				<!-- Title -->
				<div class="card-modal__title-row">
					<template v-if="editingTitle">
						<input
							ref="titleInputRef"
							v-model="draftTitle"
							class="card-modal__title-input"
							type="text"
							@keydown.enter.prevent="saveTitle"
							@keydown.escape="cancelTitleEdit"
							@blur="saveTitle" />
					</template>
					<template v-else>
						<h2 class="card-modal__title" @click="startTitleEdit">
							{{ cardData.title }}
						</h2>
						<button class="card-modal__edit-btn" :title="t('kanso', 'Edit title')" @click="startTitleEdit">
							<PencilIcon :size="16" />
						</button>
					</template>
				</div>

				<!-- Due date (read-only display) -->
				<div v-if="cardData.duedate" class="card-modal__meta">
					<CalendarIcon :size="16" />
					<span :class="dueDateClass">{{ formatDue(cardData.duedate) }}</span>
				</div>

				<!-- Description -->
				<div class="card-modal__description-section">
					<label class="card-modal__label">{{ t('kanso', 'Description') }}</label>

					<template v-if="editingDescription">
						<textarea
							v-model="draftDescription"
							class="card-modal__desc-textarea"
							:placeholder="t('kanso', 'Add a description…')"
							rows="8" />
						<div class="card-modal__desc-actions">
							<NcButton type="primary" :disabled="isSaving" @click="saveDescription">
								{{ t('kanso', 'Save') }}
							</NcButton>
							<NcButton @click="cancelDescriptionEdit">
								{{ t('kanso', 'Cancel') }}
							</NcButton>
							<span v-if="saveError" class="card-modal__save-error">{{ saveError }}</span>
						</div>
					</template>

					<template v-else>
						<div
							v-if="cardData.description"
							class="card-modal__desc-view"
							@click="startDescriptionEdit">
							{{ cardData.description }}
						</div>
						<button
							v-else
							class="card-modal__desc-placeholder"
							@click="startDescriptionEdit">
							{{ t('kanso', 'Add a description…') }}
						</button>
					</template>
				</div>
			</template>
		</div>
	</NcModal>
</template>

<script setup>
import { ref, computed, nextTick, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import { useCard } from '../composables/useCard.js'

const props = defineProps({
	cardId: {
		type: String,
		required: true,
	},
})

const router = useRouter()
const route = useRoute()

// Modal is open when this component is mounted — enabled is always true here
const isOpen = ref(true)
const { data: cardData, isLoading, isError, updateCard } = useCard(
	computed(() => props.cardId),
	isOpen,
)

const cardTitle = computed(() => cardData.value?.title || t('kanso', 'Card'))

// Title editing
const editingTitle = ref(false)
const draftTitle = ref('')
const titleInputRef = ref(null)

async function startTitleEdit() {
	draftTitle.value = cardData.value?.title || ''
	editingTitle.value = true
	await nextTick()
	titleInputRef.value?.focus()
	titleInputRef.value?.select()
}

function cancelTitleEdit() {
	editingTitle.value = false
}

async function saveTitle() {
	const title = draftTitle.value.trim()
	if (!title || title === cardData.value?.title) {
		editingTitle.value = false
		return
	}
	try {
		await updateCard.mutateAsync({ data: { title } })
	} catch (err) {
		// Title reverts to the server value on refetch — tell the user why.
		saveError.value = err?.response?.data?.error || t('kanso', 'Could not save the title')
	} finally {
		editingTitle.value = false
	}
}

// Description editing
const editingDescription = ref(false)
const draftDescription = ref('')
const isSaving = ref(false)
const saveError = ref('')

function startDescriptionEdit() {
	draftDescription.value = cardData.value?.description || ''
	editingDescription.value = true
	saveError.value = ''
}

function cancelDescriptionEdit() {
	editingDescription.value = false
	saveError.value = ''
}

async function saveDescription() {
	isSaving.value = true
	saveError.value = ''
	try {
		await updateCard.mutateAsync({ data: { description: draftDescription.value } })
		editingDescription.value = false
	} catch (err) {
		saveError.value =
			err?.response?.data?.error || t('kanso', 'Failed to save.')
	} finally {
		isSaving.value = false
	}
}

// Due date display
const dueDateClass = computed(() => {
	if (!cardData.value?.duedate) return ''
	const due = new Date(cardData.value.duedate)
	const now = new Date()
	if (due < now) return 'card-modal__due--overdue'
	const diff = due - now
	if (diff / (1000 * 60 * 60) <= 24) return 'card-modal__due--soon'
	return ''
})

function formatDue(iso) {
	return new Date(iso).toLocaleDateString(undefined, {
		weekday: 'short',
		year: 'numeric',
		month: 'short',
		day: 'numeric',
	})
}

function closeModal() {
	isOpen.value = false
	router.push({ name: 'board', params: { id: route.params.id } })
}
</script>

<style scoped>
.card-modal {
	padding: 24px;
	min-height: 200px;
}

/* Loading skeletons */
.card-modal__loading {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.card-modal__title-skeleton {
	height: 28px;
	width: 70%;
	border-radius: 4px;
}

.card-modal__desc-skeleton {
	height: 80px;
	border-radius: 4px;
}

@keyframes shimmer {
	0% { background-position: -400px 0; }
	100% { background-position: 400px 0; }
}

.skeleton-text {
	background: linear-gradient(90deg, var(--color-border) 25%, var(--color-background-hover) 50%, var(--color-border) 75%);
	background-size: 400px 100%;
	animation: shimmer 1.4s infinite linear;
}

/* Error */
.card-modal__error {
	color: var(--color-error);
}

/* Title row */
.card-modal__title-row {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 12px;
}

.card-modal__title {
	flex: 1;
	font-size: 1.25rem;
	font-weight: 700;
	color: var(--color-main-text);
	margin: 0;
	cursor: pointer;
	word-break: break-word;
}

.card-modal__title:hover {
	color: var(--color-primary);
}

.card-modal__edit-btn {
	flex-shrink: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	border: none;
	border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	padding: 0;
	transition: background 0.15s ease, color 0.15s ease;
}

.card-modal__edit-btn:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.card-modal__title-input {
	flex: 1;
	font-size: 1.25rem;
	font-weight: 700;
	color: var(--color-main-text);
	border: 2px solid var(--color-primary);
	border-radius: var(--border-radius);
	padding: 4px 8px;
	background: var(--color-main-background);
}

.card-modal__title-input:focus {
	outline: none;
}

/* Meta (due date) */
.card-modal__meta {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-bottom: 20px;
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
}

.card-modal__due--overdue {
	color: var(--color-error);
	font-weight: 600;
}

.card-modal__due--soon {
	color: var(--color-warning, #f0a844);
	font-weight: 600;
}

/* Description */
.card-modal__description-section {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.card-modal__label {
	font-weight: 600;
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.card-modal__desc-view {
	white-space: pre-wrap;
	word-break: break-word;
	color: var(--color-main-text);
	font-size: 0.9rem;
	line-height: 1.6;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	background: var(--color-main-background);
	transition: border-color 0.15s ease;
	min-height: 60px;
}

.card-modal__desc-view:hover {
	border-color: var(--color-primary);
}

.card-modal__desc-placeholder {
	padding: 10px 12px;
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
	cursor: pointer;
	text-align: left;
	width: 100%;
	transition: border-color 0.15s ease, color 0.15s ease;
}

.card-modal__desc-placeholder:hover {
	border-color: var(--color-primary);
	color: var(--color-main-text);
}

.card-modal__desc-textarea {
	width: 100%;
	padding: 10px 12px;
	border: 2px solid var(--color-primary);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.9rem;
	line-height: 1.6;
	resize: vertical;
	font-family: inherit;
}

.card-modal__desc-textarea:focus {
	outline: none;
}

.card-modal__desc-actions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.card-modal__save-error {
	color: var(--color-error);
	font-size: 0.8rem;
}
</style>
