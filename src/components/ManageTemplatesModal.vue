<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal
		:name="t('kanso', 'Manage card templates')"
		size="normal"
		@close="$emit('close')">
		<div class="manage-templates">
			<header class="manage-templates__header">
				<h2 class="manage-templates__title">{{ t('kanso', 'Card templates') }}</h2>
				<NcButton
					v-if="canEdit"
					type="primary"
					:disabled="creating"
					@click="createNewTemplate">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
					{{ t('kanso', 'New template') }}
				</NcButton>
			</header>

			<p v-if="error" class="manage-templates__error">{{ error }}</p>

			<!-- Loading -->
			<div v-if="loading" class="manage-templates__loading">
				<NcLoadingIcon :size="28" />
			</div>

			<!-- Empty state -->
			<div v-else-if="items.length === 0" class="manage-templates__empty">
				<FileDocumentOutlineIcon :size="40" class="manage-templates__empty-icon" />
				<p class="manage-templates__empty-title">{{ t('kanso', 'No templates yet') }}</p>
				<p class="manage-templates__empty-hint">
					{{ t('kanso', 'Any card can be saved as a template from its ⋯ menu ("Mark as template"). Templates are hidden from the board and reused from a column\'s "＋ From template" menu.') }}
				</p>
			</div>

			<!-- Template list -->
			<ul v-else class="manage-templates__list">
				<li v-for="tpl in items" :key="tpl.id" class="manage-templates__row">
					<FileDocumentOutlineIcon :size="20" class="manage-templates__row-icon" />
					<span class="manage-templates__row-title" :title="tpl.title">
						{{ tpl.title || t('kanso', 'Untitled') }}
					</span>

					<!-- Per-row confirm strip for delete -->
					<template v-if="confirmingId === tpl.id">
						<span class="manage-templates__confirm-label">{{ t('kanso', 'Delete this template?') }}</span>
						<NcButton
							type="error"
							:disabled="busyId === tpl.id"
							@click="doDelete(tpl.id)">
							{{ t('kanso', 'Delete') }}
						</NcButton>
						<NcButton
							:disabled="busyId === tpl.id"
							@click="confirmingId = null">
							{{ t('kanso', 'Cancel') }}
						</NcButton>
					</template>

					<!-- Default per-row actions -->
					<template v-else>
						<NcButton
							type="tertiary"
							:title="t('kanso', 'Edit template')"
							:aria-label="t('kanso', 'Edit template')"
							@click="$emit('edit', tpl.id)">
							<template #icon>
								<PencilIcon :size="20" />
							</template>
						</NcButton>
						<NcButton
							v-if="canEdit"
							type="tertiary"
							:title="t('kanso', 'Turn back into a normal card')"
							:aria-label="t('kanso', 'Unmark as template')"
							:disabled="busyId === tpl.id"
							@click="doUnmark(tpl.id)">
							<template #icon>
								<FileUndoOutlineIcon :size="20" />
							</template>
						</NcButton>
						<NcButton
							v-if="canEdit"
							type="tertiary"
							:title="t('kanso', 'Delete template')"
							:aria-label="t('kanso', 'Delete template')"
							:disabled="busyId === tpl.id"
							@click="confirmingId = tpl.id">
							<template #icon>
								<DeleteIcon :size="20" />
							</template>
						</NcButton>
					</template>
				</li>
			</ul>
		</div>
	</NcModal>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import FileUndoOutlineIcon from 'vue-material-design-icons/FileUndoOutline.vue'
import {
	fetchCardTemplates,
	setCardTemplate,
	deleteCard,
	createCard,
} from '../services/api.js'

const props = defineProps({
	/** Numeric board id whose templates are managed. */
	boardId: {
		type: [Number, String],
		required: true,
	},
	/** Whether the current user may EDIT this board (mutating actions gated). */
	canEdit: {
		type: Boolean,
		default: false,
	},
	/**
	 * A stack id on this board to host a freshly created blank template card
	 * (the template is hidden from the board regardless of stack). Required for
	 * the "New template" button; when absent the button still shows but errors.
	 */
	newTemplateStackId: {
		type: [Number, String],
		default: null,
	},
})

const emit = defineEmits(['close', 'edit'])

const items = ref([])
const loading = ref(true)
const creating = ref(false)
const error = ref('')
const busyId = ref(null)
const confirmingId = ref(null)

/** Reload the board's templates (database-first source of truth). */
async function reload() {
	loading.value = true
	error.value = ''
	try {
		items.value = await fetchCardTemplates(props.boardId)
	} catch (err) {
		error.value = err?.response?.data?.error || t('kanso', 'Failed to load templates.')
	} finally {
		loading.value = false
	}
}

onMounted(reload)

async function doUnmark(cardId) {
	if (busyId.value) return
	busyId.value = cardId
	error.value = ''
	try {
		await setCardTemplate(cardId, false)
		await reload()
	} catch (err) {
		error.value = err?.response?.data?.error || t('kanso', 'Failed to update the template.')
	} finally {
		busyId.value = null
	}
}

async function doDelete(cardId) {
	if (busyId.value) return
	busyId.value = cardId
	error.value = ''
	try {
		await deleteCard(cardId)
		confirmingId.value = null
		await reload()
	} catch (err) {
		error.value = err?.response?.data?.error || t('kanso', 'Failed to delete the template.')
	} finally {
		busyId.value = null
	}
}

/**
 * Create a blank card flagged as a template and open it for editing. The
 * simplest correct path that reuses existing endpoints: create an ordinary card
 * in one of the board's stacks, flag it as a template (setCardTemplate) so it is
 * immediately hidden from the live board, then emit `edit` so BoardView opens it
 * in the CardModal. No new endpoint or migration is needed.
 */
async function createNewTemplate() {
	if (creating.value) return
	if (props.newTemplateStackId == null) {
		error.value = t('kanso', 'Add a column to this board before creating a template.')
		return
	}
	creating.value = true
	error.value = ''
	try {
		const card = await createCard({
			stackId: Number(props.newTemplateStackId),
			title: t('kanso', 'New template'),
		})
		await setCardTemplate(card.id, true)
		await reload()
		emit('edit', card.id)
	} catch (err) {
		error.value = err?.response?.data?.error || t('kanso', 'Failed to create the template.')
	} finally {
		creating.value = false
	}
}

defineExpose({ reload })
</script>

<style scoped>
.manage-templates {
	padding: 20px 24px 24px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-height: 200px;
}

.manage-templates__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.manage-templates__title {
	font-size: 1.1rem;
	font-weight: 700;
	margin: 0;
	color: var(--color-main-text);
}

.manage-templates__error {
	color: var(--color-error);
	font-size: 0.85rem;
	margin: 0;
}

.manage-templates__loading {
	display: flex;
	justify-content: center;
	padding: 32px 0;
}

.manage-templates__empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
	gap: 6px;
	padding: 24px 8px;
	color: var(--color-text-maxcontrast);
}

.manage-templates__empty-icon {
	color: var(--color-text-maxcontrast);
	opacity: 0.7;
}

.manage-templates__empty-title {
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0;
}

.manage-templates__empty-hint {
	font-size: 0.85rem;
	margin: 0;
	max-width: 420px;
}

.manage-templates__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.manage-templates__row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 8px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.manage-templates__row-icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.manage-templates__row-title {
	flex: 1 1 auto;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 0.9rem;
	color: var(--color-main-text);
}

.manage-templates__confirm-label {
	font-size: 0.85rem;
	color: var(--color-error);
	white-space: nowrap;
}
</style>
