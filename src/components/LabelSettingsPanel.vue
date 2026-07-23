<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal
		:show="true"
		:name="t('kanso', 'Label settings')"
		size="small"
		@close="$emit('close')">
		<div class="label-settings">
			<h3 class="label-settings__heading">{{ t('kanso', 'Labels') }}</h3>

			<!-- Existing labels list -->
			<ul class="label-settings__list" role="list">
				<li v-if="labels.length === 0" class="label-settings__empty">
					{{ t('kanso', 'No labels yet. Create one below.') }}
				</li>

				<li
					v-for="label in labels"
					:key="label.id"
					class="label-settings__item">
					<!-- Color swatch / picker trigger -->
					<button
						class="label-settings__swatch"
						:style="label.color ? { background: cssColor(label.color) } : {}"
						:class="{ 'label-settings__swatch--no-color': !label.color }"
						:title="t('kanso', 'Change color')"
						:aria-label="t('kanso', 'Change color of label {title}', { title: label.title })"
						@click="openColorPicker(label)">
						<span v-if="!label.color" class="label-settings__swatch-icon">?</span>
					</button>

					<!-- Color picker popover for this label -->
					<div
						v-if="colorPickerFor === label.id"
						class="label-settings__color-popover"
						role="dialog"
						:aria-label="t('kanso', 'Pick a color')">
						<div class="label-settings__color-grid">
							<button
								v-for="preset in COLOR_PRESETS"
								:key="preset"
								class="label-settings__color-option"
								:style="{ background: cssColor(preset) }"
								:class="{ 'label-settings__color-option--active': label.color === preset }"
								:title="preset"
								:aria-pressed="label.color === preset"
								@click="applyColor(label, preset)" />
							<!-- Clear color -->
							<button
								class="label-settings__color-option label-settings__color-option--clear"
								:title="t('kanso', 'No color')"
								:aria-pressed="!label.color"
								@click="applyColor(label, '')">
								×
							</button>
						</div>
					</div>

					<!-- Inline rename input -->
					<template v-if="editingLabelId === label.id">
						<input
							:ref="(el) => setEditRef(label.id, el)"
							v-model="editingTitle"
							class="label-settings__rename-input"
							type="text"
							:aria-label="t('kanso', 'Rename label')"
							@keydown.enter.prevent="saveRename(label)"
							@keydown.escape="cancelRename"
							@blur="saveRename(label)" />
					</template>
					<template v-else>
						<span class="label-settings__name" @click="startRename(label)">
							{{ label.title }}
						</span>
					</template>

					<!-- Actions -->
					<div class="label-settings__actions">
						<button
							class="label-settings__action-btn"
							:title="t('kanso', 'Rename')"
							:aria-label="t('kanso', 'Rename label {title}', { title: label.title })"
							@click="startRename(label)">
							<PencilIcon :size="14" />
						</button>
						<button
							class="label-settings__action-btn label-settings__action-btn--danger"
							:title="t('kanso', 'Delete')"
							:aria-label="t('kanso', 'Delete label {title}', { title: label.title })"
							:disabled="confirmDeleteId === label.id && isDeleting"
							@click="confirmDelete(label)">
							<DeleteIcon :size="14" />
						</button>
					</div>

					<!-- Inline delete confirm -->
					<div v-if="confirmDeleteId === label.id" class="label-settings__confirm">
						<span>{{ t('kanso', 'Delete "{title}"?', { title: label.title }) }}</span>
						<button class="label-settings__confirm-yes" :disabled="isDeleting" @click="doDelete(label)">
							{{ t('kanso', 'Delete') }}
						</button>
						<button class="label-settings__confirm-no" @click="confirmDeleteId = null">
							{{ t('kanso', 'Cancel') }}
						</button>
						<span v-if="deleteError" class="label-settings__error">{{ deleteError }}</span>
					</div>

					<!-- Rename/color error -->
					<span v-if="labelError[label.id]" class="label-settings__error">
						{{ labelError[label.id] }}
					</span>
				</li>
			</ul>

			<!-- Create new label form -->
			<form class="label-settings__create" @submit.prevent="submitCreate">
				<h4 class="label-settings__create-heading">{{ t('kanso', 'Add label') }}</h4>
				<div class="label-settings__create-row">
					<!-- Color selector for new label -->
					<button
						type="button"
						class="label-settings__swatch"
						:style="newColor ? { background: cssColor(newColor) } : {}"
						:class="{ 'label-settings__swatch--no-color': !newColor }"
						:title="t('kanso', 'Pick color')"
						:aria-label="t('kanso', 'Pick color for new label')"
						@click="showNewColorPicker = !showNewColorPicker">
						<span v-if="!newColor" class="label-settings__swatch-icon">+</span>
					</button>

					<!-- New label color popover -->
					<div
						v-if="showNewColorPicker"
						class="label-settings__color-popover label-settings__color-popover--create"
						role="dialog"
						:aria-label="t('kanso', 'Pick a color')">
						<div class="label-settings__color-grid">
							<button
								v-for="preset in COLOR_PRESETS"
								:key="preset"
								type="button"
								class="label-settings__color-option"
								:style="{ background: cssColor(preset) }"
								:class="{ 'label-settings__color-option--active': newColor === preset }"
								:title="preset"
								:aria-pressed="newColor === preset"
								@click="newColor = preset; showNewColorPicker = false" />
							<button
								type="button"
								class="label-settings__color-option label-settings__color-option--clear"
								:title="t('kanso', 'No color')"
								:aria-pressed="!newColor"
								@click="newColor = ''; showNewColorPicker = false">
								×
							</button>
						</div>
					</div>

					<input
						v-model="newTitle"
						class="label-settings__create-input"
						type="text"
						:placeholder="t('kanso', 'Label name…')"
						:disabled="isCreating"
						:aria-label="t('kanso', 'New label name')"
						@keydown.enter.prevent="submitCreate" />
					<button
						class="label-settings__create-btn"
						type="submit"
						:disabled="!newTitle.trim() || isCreating"
						:aria-label="t('kanso', 'Create label')">
						{{ t('kanso', 'Add') }}
					</button>
				</div>
				<span v-if="createError" class="label-settings__error">{{ createError }}</span>
			</form>
		</div>
	</NcModal>
</template>

<script setup>
import { ref, nextTick } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcModal from '@nextcloud/vue/components/NcModal'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import { useLabels } from '../composables/useLabels.js'
import { cssColor } from '../services/color.js'

const props = defineProps({
	boardId: {
		type: [String, Number],
		required: true,
	},
	labels: {
		type: Array,
		default: () => [],
	},
})

defineEmits(['close'])

// 8-color preset palette — enough for MVP without NcColorPicker complexity.
// Bare hex, matching the wire/DB format (see services/color.js).
const COLOR_PRESETS = [
	'e74c3c', // red
	'e67e22', // orange
	'f1c40f', // yellow
	'2ecc71', // green
	'1abc9c', // teal
	'3498db', // blue
	'9b59b6', // purple
	'34495e', // dark grey
]

const { createLabel, updateLabel, deleteLabel } = useLabels(() => props.boardId)

// ── Create state ─────────────────────────────────────────────────────────────
const newTitle = ref('')
const newColor = ref('')
const isCreating = ref(false)
const createError = ref('')
const showNewColorPicker = ref(false)

async function submitCreate() {
	const title = newTitle.value.trim()
	if (!title) return
	isCreating.value = true
	createError.value = ''
	showNewColorPicker.value = false
	try {
		await createLabel.mutateAsync({ title, color: newColor.value || null })
		newTitle.value = ''
		newColor.value = ''
	} catch (err) {
		createError.value = err?.response?.data?.error || t('kanso', 'Failed to create label.')
	} finally {
		isCreating.value = false
	}
}

// ── Rename state ──────────────────────────────────────────────────────────────
const editingLabelId = ref(null)
const editingTitle = ref('')
const labelError = ref({})
const editRefs = {}

function setEditRef(id, el) {
	if (el) {
		editRefs[id] = el
	} else {
		delete editRefs[id]
	}
}

async function startRename(label) {
	colorPickerFor.value = null
	editingLabelId.value = label.id
	editingTitle.value = label.title
	labelError.value = { ...labelError.value, [label.id]: '' }
	await nextTick()
	editRefs[label.id]?.focus()
	editRefs[label.id]?.select()
}

function cancelRename() {
	editingLabelId.value = null
}

async function saveRename(label) {
	const title = editingTitle.value.trim()
	editingLabelId.value = null
	if (!title || title === label.title) return
	try {
		await updateLabel.mutateAsync({ labelId: label.id, title })
	} catch (err) {
		labelError.value = {
			...labelError.value,
			[label.id]: err?.response?.data?.error || t('kanso', 'Failed to rename label.'),
		}
	}
}

// ── Color picker state ────────────────────────────────────────────────────────
const colorPickerFor = ref(null)

function openColorPicker(label) {
	colorPickerFor.value = colorPickerFor.value === label.id ? null : label.id
}

async function applyColor(label, color) {
	colorPickerFor.value = null
	if (color === label.color) return
	try {
		await updateLabel.mutateAsync({ labelId: label.id, color })
	} catch (err) {
		labelError.value = {
			...labelError.value,
			[label.id]: err?.response?.data?.error || t('kanso', 'Failed to update color.'),
		}
	}
}

// ── Delete state ──────────────────────────────────────────────────────────────
const confirmDeleteId = ref(null)
const isDeleting = ref(false)
const deleteError = ref('')

function confirmDelete(label) {
	confirmDeleteId.value = label.id
	deleteError.value = ''
}

async function doDelete(label) {
	isDeleting.value = true
	deleteError.value = ''
	try {
		await deleteLabel.mutateAsync({ labelId: label.id })
		confirmDeleteId.value = null
	} catch (err) {
		deleteError.value = err?.response?.data?.error || t('kanso', 'Failed to delete label.')
	} finally {
		isDeleting.value = false
	}
}
</script>

<style scoped>
.label-settings {
	padding: 20px 24px 24px;
}

.label-settings__heading {
	font-size: 1.1rem;
	font-weight: 700;
	margin: 0 0 16px;
	color: var(--color-main-text);
}

/* List */
.label-settings__list {
	list-style: none;
	margin: 0 0 24px;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.label-settings__empty {
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
	padding: 8px 0;
}

.label-settings__item {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	padding: 4px 0;
	position: relative;
}

/* Color swatch button */
.label-settings__swatch {
	flex-shrink: 0;
	width: 24px;
	height: 24px;
	border-radius: 50%;
	border: 2px solid var(--color-border);
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 0.8rem;
	font-weight: 700;
	transition: transform 0.1s ease, border-color 0.1s ease;
}

.label-settings__swatch:hover {
	transform: scale(1.15);
	border-color: var(--color-primary);
}

.label-settings__swatch--no-color {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.label-settings__swatch-icon {
	line-height: 1;
	pointer-events: none;
}

/* Color picker popover */
.label-settings__color-popover {
	position: absolute;
	left: 0;
	top: 32px;
	z-index: 100;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 8px;
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
}

.label-settings__color-popover--create {
	top: 100%;
	margin-top: 4px;
}

.label-settings__color-grid {
	display: grid;
	grid-template-columns: repeat(5, 24px);
	gap: 6px;
}

.label-settings__color-option {
	width: 24px;
	height: 24px;
	border-radius: 50%;
	border: 2px solid transparent;
	cursor: pointer;
	transition: transform 0.1s ease, border-color 0.1s ease;
}

.label-settings__color-option:hover {
	transform: scale(1.2);
	border-color: var(--color-main-text);
}

.label-settings__color-option--active {
	border-color: var(--color-main-text) !important;
	transform: scale(1.1);
}

.label-settings__color-option--clear {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 1rem;
	display: flex;
	align-items: center;
	justify-content: center;
}

/* Label name */
.label-settings__name {
	flex: 1;
	font-size: 0.875rem;
	color: var(--color-main-text);
	cursor: pointer;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.label-settings__name:hover {
	color: var(--color-primary);
}

.label-settings__rename-input {
	flex: 1;
	font-size: 0.875rem;
	padding: 3px 8px;
	border: 2px solid var(--color-primary);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	min-width: 0;
}

.label-settings__rename-input:focus {
	outline: none;
}

/* Action buttons */
.label-settings__actions {
	display: flex;
	gap: 4px;
	flex-shrink: 0;
}

.label-settings__action-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 26px;
	height: 26px;
	border: none;
	border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	padding: 0;
	transition: background 0.15s ease, color 0.15s ease;
}

.label-settings__action-btn:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.label-settings__action-btn--danger:hover {
	background: rgba(var(--color-error-rgb, 227, 0, 0), 0.1);
	color: var(--color-error);
}

/* Delete confirm inline */
.label-settings__confirm {
	width: 100%;
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	padding: 6px 0;
	font-size: 0.8rem;
	color: var(--color-main-text);
}

.label-settings__confirm-yes {
	font-size: 0.8rem;
	padding: 3px 10px;
	border-radius: var(--border-radius);
	border: none;
	background: var(--color-error);
	color: #fff;
	cursor: pointer;
}

.label-settings__confirm-yes:disabled {
	opacity: 0.6;
	cursor: default;
}

.label-settings__confirm-no {
	font-size: 0.8rem;
	padding: 3px 10px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
}

/* Errors */
.label-settings__error {
	width: 100%;
	color: var(--color-error);
	font-size: 0.8rem;
}

/* Create form */
.label-settings__create {
	border-top: 1px solid var(--color-border);
	padding-top: 16px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	position: relative;
}

.label-settings__create-heading {
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
	margin: 0;
}

.label-settings__create-row {
	display: flex;
	align-items: center;
	gap: 8px;
	position: relative;
}

.label-settings__create-input {
	flex: 1;
	height: 36px;
	padding: 0 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.875rem;
	transition: border-color 0.15s ease;
}

.label-settings__create-input:focus {
	outline: none;
	border-color: var(--color-primary);
}

.label-settings__create-btn {
	flex-shrink: 0;
	height: 36px;
	padding: 0 14px;
	border-radius: var(--border-radius);
	border: none;
	background: var(--color-primary);
	color: var(--color-primary-text, #fff);
	font-size: 0.875rem;
	font-weight: 600;
	cursor: pointer;
	transition: background 0.15s ease, opacity 0.15s ease;
}

.label-settings__create-btn:hover:not(:disabled) {
	background: var(--color-primary-hover, var(--color-primary));
	opacity: 0.9;
}

.label-settings__create-btn:disabled {
	opacity: 0.5;
	cursor: default;
}
</style>
