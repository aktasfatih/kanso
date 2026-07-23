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

				<!-- Due date — editable via datetime-local input -->
				<div class="card-modal__meta card-modal__meta--due">
					<CalendarIcon :size="16" />
					<span class="card-modal__meta-label">{{ t('kanso', 'Due date') }}</span>
					<input
						class="card-modal__due-input"
						:class="dueDateClass"
						type="datetime-local"
						:value="dueDateInputValue"
						@change="handleDueDateChange" />
					<button
						v-if="cardData.duedate"
						class="card-modal__due-clear"
						:title="t('kanso', 'Clear due date')"
						@click="clearDueDate">
						<CloseIcon :size="14" />
					</button>
				</div>

				<!-- Done toggle -->
				<div class="card-modal__meta card-modal__meta--done">
					<NcCheckboxRadioSwitch
						:model-value="isDone"
						type="checkbox"
						@update:model-value="handleDoneToggle">
						{{ t('kanso', 'Done') }}
					</NcCheckboxRadioSwitch>
					<span v-if="isDone && cardData.doneAt" class="card-modal__done-at">
						{{ formatDoneAt(cardData.doneAt) }}
					</span>
				</div>

				<!-- Labels section -->
				<div class="card-modal__labels-section">
					<label class="card-modal__label">{{ t('kanso', 'Labels') }}</label>
					<div
						v-if="boardLabels.length === 0"
						class="card-modal__labels-empty">
						{{ t('kanso', 'No labels on this board yet.') }}
					</div>
					<div v-else class="card-modal__label-chips" role="group" :aria-label="t('kanso', 'Toggle labels')">
						<button
							v-for="label in boardLabels"
							:key="label.id"
							class="card-modal__label-chip"
							:class="{
								'card-modal__label-chip--assigned': cardLabelIds.has(label.id),
								'card-modal__label-chip--no-color': !label.color,
							}"
							:style="label.color ? { '--label-color': cssColor(label.color) } : {}"
							:aria-pressed="cardLabelIds.has(label.id)"
							:disabled="toggleLabel.isPending.value"
							@click="handleToggleLabel(label)">
							{{ label.title }}
						</button>
					</div>
					<span v-if="labelToggleError" class="card-modal__save-error">{{ labelToggleError }}</span>
				</div>

				<!-- Assignees section -->
				<div class="card-modal__assignees-section">
					<label class="card-modal__label">{{ t('kanso', 'Assignees') }}</label>

					<!-- Current assignees as chips -->
					<div class="card-modal__assignee-chips">
						<span
							v-for="uid in cardAssigneeIds"
							:key="uid"
							class="card-modal__assignee-chip">
							<NcAvatar
								:user="uid"
								:display-name="participantName(uid)"
								:size="24"
								:show-user-status="false"
								:disable-tooltip="false" />
							<span class="card-modal__assignee-name">{{ participantName(uid) }}</span>
							<button
								class="card-modal__assignee-remove"
								:title="t('kanso', 'Remove assignee')"
								:disabled="toggleAssignee.isPending.value"
								@click="handleToggleAssignee(uid, false)">
								<CloseIcon :size="12" />
							</button>
						</span>
						<span v-if="cardAssigneeIds.length === 0" class="card-modal__assignees-empty">
							{{ t('kanso', 'No assignees yet.') }}
						</span>
					</div>

					<!-- Assign dropdown: participants not yet assigned -->
					<div v-if="unassignedParticipants.length > 0" class="card-modal__assign-wrap">
						<button
							class="card-modal__assign-toggle"
							:aria-expanded="assignPickerOpen"
							@click="assignPickerOpen = !assignPickerOpen">
							<AccountPlusIcon :size="16" />
							{{ t('kanso', 'Assign…') }}
						</button>
						<div v-if="assignPickerOpen" class="card-modal__assign-popover">
							<button
								v-for="p in unassignedParticipants"
								:key="p.uid"
								class="card-modal__assign-option"
								:disabled="toggleAssignee.isPending.value"
								@click="handleToggleAssignee(p.uid, true)">
								<NcAvatar
									:user="p.uid"
									:display-name="p.displayName"
									:size="24"
									:show-user-status="false"
									:disable-tooltip="true" />
								<span>{{ p.displayName }}</span>
							</button>
						</div>
					</div>

					<span v-if="assigneeError" class="card-modal__save-error">{{ assigneeError }}</span>
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
import { ref, computed, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import AccountPlusIcon from 'vue-material-design-icons/AccountPlus.vue'
import { useCard } from '../composables/useCard.js'
import { useBoard } from '../composables/useBoard.js'
import { useLabels } from '../composables/useLabels.js'
import { useAssignees } from '../composables/useAssignees.js'
import { cssColor } from '../services/color.js'

const props = defineProps({
	cardId: {
		type: String,
		required: true,
	},
})

const router = useRouter()
const route = useRoute()

// Board id from route params — modal is a child route of /boards/:id
const boardId = computed(() => route.params.id)

// Modal is open when this component is mounted — enabled is always true here
const isOpen = ref(true)
const { data: cardData, isLoading, isError, updateCard } = useCard(
	computed(() => props.cardId),
	isOpen,
)

// Read board data from cache (same queryKey as BoardView — no extra request).
const { data: boardData } = useBoard(boardId)
const boardLabels = computed(() => boardData.value?.labels ?? [])

// Current card's assigned label ids as a Set for O(1) .has() in the template
const cardLabelIds = computed(() => {
	const ids = Array.isArray(cardData.value?.labelIds) ? cardData.value.labelIds : []
	return new Set(ids)
})

// Label toggle mutation
const { toggleLabel } = useLabels(boardId)
const labelToggleError = ref('')

async function handleToggleLabel(label) {
	const assign = !cardLabelIds.value.has(label.id)
	labelToggleError.value = ''
	try {
		await toggleLabel.mutateAsync({
			cardId: Number(props.cardId),
			labelId: label.id,
			assign,
		})
	} catch (err) {
		labelToggleError.value = err?.response?.data?.error || t('kanso', 'Failed to update label.')
	}
}

// ── Assignees ────────────────────────────────────────────────────────────────
const { participants, toggleAssignee } = useAssignees(boardId)
const assigneeError = ref('')
const assignPickerOpen = ref(false)

const cardAssigneeIds = computed(() =>
	Array.isArray(cardData.value?.assigneeIds) ? cardData.value.assigneeIds : [],
)

const participantMap = computed(() => {
	const list = Array.isArray(participants.data.value) ? participants.data.value : []
	return new Map(list.map((p) => [p.uid, p]))
})

function participantName(uid) {
	return participantMap.value.get(uid)?.displayName ?? uid
}

const unassignedParticipants = computed(() => {
	const list = Array.isArray(participants.data.value) ? participants.data.value : []
	const assigned = new Set(cardAssigneeIds.value)
	return list.filter((p) => !assigned.has(p.uid))
})

async function handleToggleAssignee(uid, assign) {
	assigneeError.value = ''
	assignPickerOpen.value = false
	try {
		await toggleAssignee.mutateAsync({
			cardId: Number(props.cardId),
			userId: uid,
			assign,
		})
	} catch (err) {
		assigneeError.value = err?.response?.data?.error || t('kanso', 'Failed to update assignee.')
	}
}

// ── Done toggle ──────────────────────────────────────────────────────────────
const isDone = computed(() => Number(cardData.value?.doneAt) > 0)

async function handleDoneToggle(checked) {
	try {
		await updateCard.mutateAsync({ data: { done: checked } })
	} catch (err) {
		saveError.value = err?.response?.data?.error || t('kanso', 'Failed to update done status.')
	}
}

function formatDoneAt(unixTs) {
	return new Date(unixTs * 1000).toLocaleString(undefined, {
		weekday: 'short',
		year: 'numeric',
		month: 'short',
		day: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
	})
}

// ── Due date (editable) ──────────────────────────────────────────────────────
// datetime-local value needs "YYYY-MM-DDTHH:mm" format
const dueDateInputValue = computed(() => {
	if (!cardData.value?.duedate) return ''
	const d = new Date(cardData.value.duedate)
	const pad = (n) => String(n).padStart(2, '0')
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
})

async function handleDueDateChange(event) {
	const val = event.target.value
	if (!val) return
	const iso = new Date(val).toISOString()
	try {
		await updateCard.mutateAsync({ data: { duedate: iso } })
	} catch (err) {
		saveError.value = err?.response?.data?.error || t('kanso', 'Failed to update due date.')
	}
}

async function clearDueDate() {
	try {
		await updateCard.mutateAsync({ data: { duedate: '' } })
	} catch (err) {
		saveError.value = err?.response?.data?.error || t('kanso', 'Failed to clear due date.')
	}
}

// ── Due date color class (respects done state) ───────────────────────────────
const dueDateClass = computed(() => {
	if (!cardData.value?.duedate) return ''
	// When done, suppress overdue/soon coloring
	if (isDone.value) return ''
	const due = new Date(cardData.value.duedate)
	const now = new Date()
	if (due < now) return 'card-modal__due--overdue'
	const diff = due - now
	if (diff / (1000 * 60 * 60) <= 24) return 'card-modal__due--soon'
	return ''
})

const cardTitle = computed(() => cardData.value?.title || t('kanso', 'Card'))

// ── Title editing ─────────────────────────────────────────────────────────────
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
		saveError.value = err?.response?.data?.error || t('kanso', 'Could not save the title')
	} finally {
		editingTitle.value = false
	}
}

// ── Description editing ───────────────────────────────────────────────────────
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

/* Meta rows (due date, done) */
.card-modal__meta {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-bottom: 16px;
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
}

.card-modal__meta-label {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

/* Due date input */
.card-modal__due-input {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 3px 8px;
	font-size: 0.875rem;
	font-family: inherit;
	cursor: pointer;
}

.card-modal__due-input:focus {
	outline: 2px solid var(--color-primary);
	outline-offset: 1px;
}

.card-modal__due-input.card-modal__due--overdue {
	color: var(--color-error);
	border-color: var(--color-error);
}

.card-modal__due-input.card-modal__due--soon {
	color: var(--color-warning, #f0a844);
	border-color: var(--color-warning, #f0a844);
}

.card-modal__due-clear {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 22px;
	height: 22px;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	padding: 0;
	transition: background 0.15s ease, color 0.15s ease;
}

.card-modal__due-clear:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

/* Done section */
.card-modal__meta--done {
	flex-wrap: wrap;
	gap: 8px;
}

.card-modal__done-at {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

/* Labels */
.card-modal__labels-section {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 20px;
}

.card-modal__labels-empty {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.card-modal__label-chips {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.card-modal__label-chip {
	display: inline-flex;
	align-items: center;
	height: 28px;
	padding: 0 12px;
	border-radius: 14px;
	border: 2px solid var(--label-color, var(--color-border));
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.8rem;
	font-weight: 600;
	cursor: pointer;
	transition: background 0.15s ease, color 0.15s ease, opacity 0.1s ease;
	white-space: nowrap;
}

.card-modal__label-chip:hover:not(:disabled) {
	background: color-mix(in srgb, var(--label-color, var(--color-primary)) 15%, transparent);
}

.card-modal__label-chip--assigned {
	background: var(--label-color, var(--color-primary));
	border-color: var(--label-color, var(--color-primary));
	color: #fff;
}

.card-modal__label-chip--no-color {
	border-color: var(--color-border);
	color: var(--color-main-text);
}

.card-modal__label-chip--no-color.card-modal__label-chip--assigned {
	background: var(--color-background-dark);
	border-color: var(--color-border);
	color: var(--color-main-text);
}

.card-modal__label-chip:disabled {
	opacity: 0.6;
	cursor: default;
}

/* Assignees */
.card-modal__assignees-section {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 20px;
}

.card-modal__assignee-chips {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: center;
}

.card-modal__assignee-chip {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 32px;
	padding: 0 8px 0 4px;
	border-radius: 16px;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	font-size: 0.8rem;
	color: var(--color-main-text);
}

.card-modal__assignee-name {
	font-size: 0.8rem;
	max-width: 120px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.card-modal__assignee-remove {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 18px;
	height: 18px;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	padding: 0;
	transition: background 0.15s ease, color 0.15s ease;
	flex-shrink: 0;
}

.card-modal__assignee-remove:hover:not(:disabled) {
	background: var(--color-error);
	color: #fff;
}

.card-modal__assignee-remove:disabled {
	opacity: 0.5;
	cursor: default;
}

.card-modal__assignees-empty {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

/* Assign picker */
.card-modal__assign-wrap {
	position: relative;
}

.card-modal__assign-toggle {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 30px;
	padding: 0 12px;
	border: 1px dashed var(--color-border);
	border-radius: 15px;
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
	cursor: pointer;
	transition: border-color 0.15s ease, color 0.15s ease;
}

.card-modal__assign-toggle:hover {
	border-color: var(--color-primary);
	color: var(--color-primary);
}

.card-modal__assign-popover {
	position: absolute;
	top: calc(100% + 4px);
	left: 0;
	z-index: 100;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
	min-width: 200px;
	max-height: 240px;
	overflow-y: auto;
	padding: 4px 0;
}

.card-modal__assign-option {
	display: flex;
	align-items: center;
	gap: 10px;
	width: 100%;
	padding: 8px 14px;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.875rem;
	cursor: pointer;
	text-align: left;
	transition: background 0.1s ease;
}

.card-modal__assign-option:hover:not(:disabled) {
	background: var(--color-background-hover);
}

.card-modal__assign-option:disabled {
	opacity: 0.5;
	cursor: default;
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
