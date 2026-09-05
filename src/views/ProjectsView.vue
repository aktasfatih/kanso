<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="projects-view">
		<div class="projects-view__header">
			<h1 class="projects-view__title">{{ t('kanso', 'Projects') }}</h1>
			<NcButton type="primary" @click="openCreateDialog">
				<template #icon>
					<PlusIcon :size="18" />
				</template>
				{{ t('kanso', 'New project') }}
			</NcButton>
		</div>

		<!-- Loading state -->
		<div v-if="isLoading" class="projects-view__loading" aria-live="polite">
			<span class="projects-view__spinner" aria-hidden="true" />
			<span>{{ t('kanso', 'Loading projects…') }}</span>
		</div>

		<!-- Error state -->
		<div v-else-if="isError" class="projects-view__error">
			{{ t('kanso', 'Failed to load projects. Please try again.') }}
		</div>

		<!-- Empty state -->
		<NcEmptyContent
			v-else-if="!projects.length"
			:name="t('kanso', 'No projects yet')"
			:description="t('kanso', 'Projects let you collect cards from any board into a single view.')">
			<template #icon>
				<FolderMultipleOutlineIcon :size="64" />
			</template>
		</NcEmptyContent>

		<!-- Project list -->
		<ul v-else class="projects-view__list">
			<li
				v-for="project in projects"
				:key="project.id"
				class="projects-view__row"
				tabindex="0"
				role="button"
				@click="openProject(project)"
				@keydown.enter="openProject(project)">
				<span
					class="projects-view__dot"
					:style="project.color ? { background: '#' + project.color } : {}"
					:class="{ 'projects-view__dot--default': !project.color }" />
				<div class="projects-view__row-main">
					<span class="projects-view__project-title">{{ project.title }}</span>
					<span v-if="project.description" class="projects-view__project-desc">{{ project.description }}</span>
				</div>
				<ChevronRightIcon :size="18" class="projects-view__row-chevron" />
			</li>
		</ul>

		<!-- Create project dialog -->
		<NcDialog
			v-if="showCreateDialog"
			:name="t('kanso', 'New project')"
			:open="showCreateDialog"
			@closing="closeCreateDialog">
			<div class="projects-view__form">
				<label class="projects-view__form-label" for="project-title">
					{{ t('kanso', 'Title') }}
					<span aria-hidden="true" class="projects-view__required">*</span>
				</label>
				<input
					id="project-title"
					ref="titleInputRef"
					v-model="newTitle"
					class="projects-view__form-input"
					type="text"
					maxlength="255"
					:placeholder="t('kanso', 'Project title')"
					:disabled="create.isPending.value"
					@keydown.enter.prevent="submitCreate">

				<label class="projects-view__form-label" for="project-desc">
					{{ t('kanso', 'Description') }}
				</label>
				<input
					id="project-desc"
					v-model="newDescription"
					class="projects-view__form-input"
					type="text"
					:placeholder="t('kanso', 'Optional description')"
					:disabled="create.isPending.value">

				<label class="projects-view__form-label">{{ t('kanso', 'Color') }}</label>
				<div class="projects-view__color-grid">
					<button
						v-for="preset in COLOR_PRESETS"
						:key="preset"
						type="button"
						class="projects-view__color-swatch"
						:class="{ 'projects-view__color-swatch--active': newColor === preset }"
						:style="{ background: '#' + preset }"
						:title="'#' + preset"
						:aria-pressed="newColor === preset"
						@click="newColor = newColor === preset ? '' : preset" />
					<button
						type="button"
						class="projects-view__color-swatch projects-view__color-swatch--clear"
						:class="{ 'projects-view__color-swatch--active': !newColor }"
						:title="t('kanso', 'No color')"
						:aria-pressed="!newColor"
						@click="newColor = ''">
						×
					</button>
				</div>

				<span v-if="createError" class="projects-view__form-error">{{ createError }}</span>
			</div>

			<template #actions>
				<NcButton :disabled="create.isPending.value || !newTitle.trim()" type="primary" @click="submitCreate">
					{{ t('kanso', 'Create') }}
				</NcButton>
				<NcButton @click="closeCreateDialog">
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
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import FolderMultipleOutlineIcon from 'vue-material-design-icons/FolderMultipleOutline.vue'
import { useProjects } from '../composables/useProjects.js'

const COLOR_PRESETS = [
	'e53935', 'f4511e', 'f6bf26', '33b679', '0b8043',
	'039be5', '3f51b5', '7986cb', '8e24aa', '616161',
]

const router = useRouter()
const { data, isLoading, isError, create } = useProjects()

const projects = computed(() => data.value ?? [])

// Create dialog state
const showCreateDialog = ref(false)
const newTitle = ref('')
const newDescription = ref('')
const newColor = ref('')
const createError = ref('')
const titleInputRef = ref(null)

function openCreateDialog() {
	newTitle.value = ''
	newDescription.value = ''
	newColor.value = ''
	createError.value = ''
	showCreateDialog.value = true
	nextTick(() => titleInputRef.value?.focus())
}

function closeCreateDialog() {
	showCreateDialog.value = false
}

async function submitCreate() {
	const title = newTitle.value.trim()
	if (!title || create.isPending.value) return
	createError.value = ''
	try {
		const project = await create.mutateAsync({
			title,
			description: newDescription.value.trim() || undefined,
			color: newColor.value || undefined,
		})
		closeCreateDialog()
		router.push({ name: 'project', params: { id: String(project.id) } })
	} catch (err) {
		createError.value = err?.response?.data?.error || t('kanso', 'Failed to create project.')
	}
}

function openProject(project) {
	router.push({ name: 'project', params: { id: String(project.id) } })
}
</script>

<style scoped>
.projects-view {
	padding: 24px 32px;
	max-width: 860px;
}

.projects-view__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 24px;
}

.projects-view__title {
	font-size: 1.5rem;
	font-weight: 600;
	margin: 0;
}

.projects-view__loading {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--color-text-maxcontrast);
	padding: 32px 0;
}

.projects-view__spinner {
	display: inline-block;
	width: 20px;
	height: 20px;
	border: 2px solid var(--color-border);
	border-top-color: var(--color-primary-element);
	border-radius: 50%;
	animation: projects-spin 0.7s linear infinite;
}

@keyframes projects-spin {
	to { transform: rotate(360deg); }
}

.projects-view__error {
	color: var(--color-error);
	padding: 16px 0;
}

.projects-view__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.projects-view__row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 14px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
	cursor: pointer;
	transition: background 0.15s;
}

.projects-view__row:hover,
.projects-view__row:focus-visible {
	background: var(--color-border-dark);
	outline: none;
}

.projects-view__dot {
	flex-shrink: 0;
	width: 14px;
	height: 14px;
	border-radius: 50%;
	border: 1px solid var(--color-border-dark);
}

.projects-view__dot--default {
	background: var(--color-primary-element);
}

.projects-view__row-main {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1;
	min-width: 0;
}

.projects-view__project-title {
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.projects-view__project-desc {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.projects-view__row-chevron {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.projects-view__form {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 4px 0;
}

.projects-view__form-label {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}

.projects-view__required {
	color: var(--color-error);
	margin-left: 2px;
}

.projects-view__form-input {
	width: 100%;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.9rem;
}

.projects-view__form-input:focus {
	outline: none;
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.projects-view__color-grid {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: 2px;
}

.projects-view__color-swatch {
	width: 24px;
	height: 24px;
	border-radius: 50%;
	border: 2px solid transparent;
	cursor: pointer;
	transition: border-color 0.1s, transform 0.1s;
}

.projects-view__color-swatch:hover {
	transform: scale(1.15);
}

.projects-view__color-swatch--active {
	border-color: var(--color-main-text);
}

.projects-view__color-swatch--clear {
	background: var(--color-background-hover);
	border-color: var(--color-border);
	font-size: 14px;
	line-height: 1;
	color: var(--color-text-maxcontrast);
	display: flex;
	align-items: center;
	justify-content: center;
}

.projects-view__form-error {
	color: var(--color-error);
	font-size: 0.85rem;
}
</style>
