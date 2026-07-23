<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal
		:show="true"
		:name="t('kanso', 'Board settings')"
		size="small"
		@close="$emit('close')">
		<div class="board-settings">
			<!-- Tab bar -->
			<div class="board-settings__tabs" role="tablist">
				<button
					class="board-settings__tab"
					:class="{ 'board-settings__tab--active': activeTab === 'labels' }"
					role="tab"
					:aria-selected="activeTab === 'labels'"
					@click="activeTab = 'labels'">
					{{ t('kanso', 'Labels') }}
				</button>
				<button
					v-if="canShare"
					class="board-settings__tab"
					:class="{ 'board-settings__tab--active': activeTab === 'sharing' }"
					role="tab"
					:aria-selected="activeTab === 'sharing'"
					@click="activeTab = 'sharing'">
					{{ t('kanso', 'Sharing') }}
				</button>
			</div>

			<!-- Labels tab -->
			<div v-show="activeTab === 'labels'" class="board-settings__panel" role="tabpanel">
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
							:disabled="!canManage"
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
							<span
								class="label-settings__name"
								:class="{ 'label-settings__name--readonly': !canManage }"
								@click="canManage && startRename(label)">
								{{ label.title }}
							</span>
						</template>

						<!-- Actions (only when canManage) -->
						<div v-if="canManage" class="label-settings__actions">
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
								:disabled="confirmDeleteLabelId === label.id && isDeletingLabel"
								@click="confirmDeleteLabel(label)">
								<DeleteIcon :size="14" />
							</button>
						</div>

						<!-- Inline delete confirm -->
						<div v-if="confirmDeleteLabelId === label.id" class="label-settings__confirm">
							<span>{{ t('kanso', 'Delete "{title}"?', { title: label.title }) }}</span>
							<button class="label-settings__confirm-yes" :disabled="isDeletingLabel" @click="doDeleteLabel(label)">
								{{ t('kanso', 'Delete') }}
							</button>
							<button class="label-settings__confirm-no" @click="confirmDeleteLabelId = null">
								{{ t('kanso', 'Cancel') }}
							</button>
							<span v-if="deleteLabelError" class="label-settings__error">{{ deleteLabelError }}</span>
						</div>

						<!-- Rename/color error -->
						<span v-if="labelError[label.id]" class="label-settings__error">
							{{ labelError[label.id] }}
						</span>
					</li>
				</ul>

				<!-- Create new label form (only when canManage) -->
				<form v-if="canManage" class="label-settings__create" @submit.prevent="submitCreate">
					<h4 class="label-settings__create-heading">{{ t('kanso', 'Add label') }}</h4>
					<div class="label-settings__create-row">
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

			<!-- Sharing tab -->
			<div v-if="canShare" v-show="activeTab === 'sharing'" class="board-settings__panel" role="tabpanel">
				<!-- Sharee search -->
				<div class="sharing__search-wrap">
					<input
						v-model="searchQuery"
						class="sharing__search-input"
						type="search"
						:placeholder="t('kanso', 'Search users or groups…')"
						:aria-label="t('kanso', 'Search users or groups')" />
					<span v-if="isSearching" class="sharing__search-spinner" aria-hidden="true" />
					<span v-if="searchError" class="sharing__error">{{ searchError }}</span>

					<!-- Dropdown results -->
					<ul v-if="searchResults.length > 0" class="sharing__dropdown" role="listbox">
						<li
							v-for="result in searchResults"
							:key="`${result.type}-${result.id}`"
							class="sharing__dropdown-item"
							role="option"
							:aria-label="result.displayName"
							@click="pickSharee(result)"
							@keydown.enter="pickSharee(result)"
							@keydown.space.prevent="pickSharee(result)">
							<AccountGroupIcon v-if="result.type === 'group'" :size="18" class="sharing__type-icon" />
							<AccountIcon v-else :size="18" class="sharing__type-icon" />
							<span class="sharing__dropdown-name">{{ result.displayName }}</span>
							<span class="sharing__dropdown-type">{{ result.type === 'group' ? t('kanso', 'Group') : t('kanso', 'User') }}</span>
						</li>
					</ul>
				</div>
				<span v-if="addAclError" class="sharing__error sharing__error--add">{{ addAclError }}</span>

				<!-- Current ACL entries -->
				<ul class="sharing__list" role="list">
					<li v-if="acl.length === 0" class="sharing__empty">
						{{ t('kanso', 'Not shared with anyone yet.') }}
					</li>

					<li
						v-for="entry in acl"
						:key="entry.id"
						class="sharing__entry">
						<!-- Avatar / icon -->
						<div class="sharing__avatar-wrap">
							<NcAvatar
								v-if="entry.participantType === 'user'"
								:user="entry.participant"
								:display-name="resolveDisplayName(entry)"
								:size="32"
								:disable-tooltip="false" />
							<span v-else class="sharing__group-icon" :title="t('kanso', 'Group')">
								<AccountGroupIcon :size="20" />
							</span>
						</div>

						<!-- Display name -->
						<span class="sharing__entry-name">{{ resolveDisplayName(entry) }}</span>

						<!-- Permission toggles: Edit (bit 2), Share (bit 4), Manage (bit 8) -->
						<div class="sharing__perms">
							<label
								class="sharing__perm-label"
								:class="{ 'sharing__perm-label--disabled': !canToggleBit(entry, PERM_EDIT) }"
								:title="t('kanso', 'Can edit')">
								<input
									type="checkbox"
									:checked="hasBit(entry.permission, PERM_EDIT)"
									:disabled="!canToggleBit(entry, PERM_EDIT) || patchingAclId === entry.id"
									@change="togglePerm(entry, PERM_EDIT, $event.target.checked)" />
								{{ t('kanso', 'Edit') }}
							</label>
							<label
								class="sharing__perm-label"
								:class="{ 'sharing__perm-label--disabled': !canToggleBit(entry, PERM_SHARE) }"
								:title="t('kanso', 'Can share')">
								<input
									type="checkbox"
									:checked="hasBit(entry.permission, PERM_SHARE)"
									:disabled="!canToggleBit(entry, PERM_SHARE) || patchingAclId === entry.id"
									@change="togglePerm(entry, PERM_SHARE, $event.target.checked)" />
								{{ t('kanso', 'Share') }}
							</label>
							<label
								class="sharing__perm-label"
								:class="{ 'sharing__perm-label--disabled': !canToggleBit(entry, PERM_MANAGE) }"
								:title="t('kanso', 'Can manage')">
								<input
									type="checkbox"
									:checked="hasBit(entry.permission, PERM_MANAGE)"
									:disabled="!canToggleBit(entry, PERM_MANAGE) || patchingAclId === entry.id"
									@change="togglePerm(entry, PERM_MANAGE, $event.target.checked)" />
								{{ t('kanso', 'Manage') }}
							</label>
						</div>

						<!-- Per-entry error -->
						<span v-if="patchAclErrors[entry.id]" class="sharing__error">{{ patchAclErrors[entry.id] }}</span>

						<!-- Delete / inline confirm -->
						<button
							class="sharing__remove-btn"
							:title="t('kanso', 'Remove')"
							:aria-label="t('kanso', 'Remove {name} from board', { name: resolveDisplayName(entry) })"
							:disabled="confirmRemoveAclId === entry.id && isRemovingAcl"
							@click="confirmRemoveAcl(entry)">
							<DeleteIcon :size="14" />
						</button>

						<!-- Inline remove confirm -->
						<div v-if="confirmRemoveAclId === entry.id" class="label-settings__confirm">
							<span>{{ t('kanso', 'Remove "{name}" from board?', { name: resolveDisplayName(entry) }) }}</span>
							<button class="label-settings__confirm-yes" :disabled="isRemovingAcl" @click="doRemoveAcl(entry)">
								{{ t('kanso', 'Remove') }}
							</button>
							<button class="label-settings__confirm-no" @click="confirmRemoveAclId = null">
								{{ t('kanso', 'Cancel') }}
							</button>
							<span v-if="removeAclError" class="label-settings__error">{{ removeAclError }}</span>
						</div>
					</li>
				</ul>

				<!-- Leave board: only for user-type entries matching current user -->
				<div v-if="ownEntry" class="sharing__leave">
					<button
						v-if="!confirmLeave"
						class="sharing__leave-btn"
						@click="confirmLeave = true">
						{{ t('kanso', 'Leave board') }}
					</button>
					<div v-else class="label-settings__confirm">
						<span>{{ t('kanso', 'Leave this board?') }}</span>
						<button class="label-settings__confirm-yes" :disabled="isLeaving" @click="doLeave">
							{{ t('kanso', 'Leave') }}
						</button>
						<button class="label-settings__confirm-no" @click="confirmLeave = false">
							{{ t('kanso', 'Cancel') }}
						</button>
						<span v-if="leaveError" class="label-settings__error">{{ leaveError }}</span>
					</div>
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script setup>
import { ref, computed, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import { useLabels } from '../composables/useLabels.js'
import { useAcl } from '../composables/useAcl.js'
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
	/** acl array from board payload */
	acl: {
		type: Array,
		default: () => [],
	},
	/** requester permission mask from board payload */
	permissions: {
		type: Number,
		default: 0,
	},
	/** participants list for display-name resolution */
	participants: {
		type: Array,
		default: () => [],
	},
	/** current Nextcloud user id */
	currentUserId: {
		type: String,
		default: '',
	},
})

const emit = defineEmits(['close', 'leave'])

// ── Permission constants ──────────────────────────────────────────────────────
const PERM_READ = 1
const PERM_EDIT = 2
const PERM_SHARE = 4
const PERM_MANAGE = 8

const canManage = computed(() => (props.permissions & PERM_MANAGE) !== 0)
const canShare = computed(() => (props.permissions & PERM_SHARE) !== 0)

function hasBit(mask, bit) {
	return (mask & bit) !== 0
}

/**
 * Can the current user toggle a given bit on an entry?
 * Rule mirrors server cap: non-MANAGE holders can only touch bits they themselves hold.
 * MANAGE holders can toggle any bit.
 */
function canToggleBit(entry, bit) {
	if (canManage.value) return true
	return hasBit(props.permissions, bit)
}

// ── Tab state ─────────────────────────────────────────────────────────────────
const activeTab = ref('labels')

// ── Labels composable ─────────────────────────────────────────────────────────
const { createLabel, updateLabel, deleteLabel } = useLabels(() => props.boardId)

// ── ACL composable ────────────────────────────────────────────────────────────
const {
	addAcl,
	patchAcl,
	removeAcl,
	searchQuery,
	searchResults,
	isSearching,
	searchError,
	clearSearch,
} = useAcl(() => props.boardId)

// ── Color presets ─────────────────────────────────────────────────────────────
const COLOR_PRESETS = [
	'e74c3c',
	'e67e22',
	'f1c40f',
	'2ecc71',
	'1abc9c',
	'3498db',
	'9b59b6',
	'34495e',
]

// ── Label: create state ───────────────────────────────────────────────────────
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

// ── Label: rename state ───────────────────────────────────────────────────────
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

// ── Label: color picker state ─────────────────────────────────────────────────
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

// ── Label: delete state ───────────────────────────────────────────────────────
const confirmDeleteLabelId = ref(null)
const isDeletingLabel = ref(false)
const deleteLabelError = ref('')

function confirmDeleteLabel(label) {
	confirmDeleteLabelId.value = label.id
	deleteLabelError.value = ''
}

async function doDeleteLabel(label) {
	isDeletingLabel.value = true
	deleteLabelError.value = ''
	try {
		await deleteLabel.mutateAsync({ labelId: label.id })
		confirmDeleteLabelId.value = null
	} catch (err) {
		deleteLabelError.value = err?.response?.data?.error || t('kanso', 'Failed to delete label.')
	} finally {
		isDeletingLabel.value = false
	}
}

// ── ACL: sharee search + add ──────────────────────────────────────────────────
const addAclError = ref('')

async function pickSharee(result) {
	clearSearch()
	addAclError.value = ''
	try {
		// Default permission: READ | EDIT (3)
		await addAcl.mutateAsync({
			participant: result.id,
			participantType: result.type,
			permission: PERM_READ | PERM_EDIT,
		})
	} catch (err) {
		addAclError.value = err?.response?.data?.error || t('kanso', 'Failed to share board.')
	}
}

// ── ACL: permission toggles ───────────────────────────────────────────────────
const patchingAclId = ref(null)
const patchAclErrors = ref({})

async function togglePerm(entry, bit, checked) {
	patchAclErrors.value = { ...patchAclErrors.value, [entry.id]: '' }
	const newPerm = checked
		? (entry.permission | bit)
		: (entry.permission & ~bit)
	// READ is always forced on by server; keep client consistent
	const finalPerm = newPerm | PERM_READ
	patchingAclId.value = entry.id
	try {
		await patchAcl.mutateAsync({ aclId: entry.id, permission: finalPerm })
	} catch (err) {
		patchAclErrors.value = {
			...patchAclErrors.value,
			[entry.id]: err?.response?.data?.error || t('kanso', 'Failed to update permission.'),
		}
	} finally {
		patchingAclId.value = null
	}
}

// ── ACL: remove entry ─────────────────────────────────────────────────────────
const confirmRemoveAclId = ref(null)
const isRemovingAcl = ref(false)
const removeAclError = ref('')

function confirmRemoveAcl(entry) {
	confirmRemoveAclId.value = entry.id
	removeAclError.value = ''
}

async function doRemoveAcl(entry) {
	isRemovingAcl.value = true
	removeAclError.value = ''
	try {
		await removeAcl.mutateAsync({ aclId: entry.id })
		confirmRemoveAclId.value = null
	} catch (err) {
		removeAclError.value = err?.response?.data?.error || t('kanso', 'Failed to remove share.')
	} finally {
		isRemovingAcl.value = false
	}
}

// ── ACL: leave board ──────────────────────────────────────────────────────────
const router = useRouter()
const confirmLeave = ref(false)
const isLeaving = ref(false)
const leaveError = ref('')

/**
 * The current user's own ACL entry (user-type, participant === currentUserId).
 * Shown only when such an entry exists — i.e., the user is a sharee, not the owner.
 */
const ownEntry = computed(() =>
	props.acl.find(
		(e) => e.participantType === 'user' && e.participant === props.currentUserId,
	),
)

async function doLeave() {
	if (!ownEntry.value) return
	isLeaving.value = true
	leaveError.value = ''
	try {
		await removeAcl.mutateAsync({ aclId: ownEntry.value.id })
		emit('leave')
		router.push({ name: 'board-list' })
	} catch (err) {
		leaveError.value = err?.response?.data?.error || t('kanso', 'Failed to leave board.')
		isLeaving.value = false
	}
}

// ── Display name resolution ───────────────────────────────────────────────────
/**
 * Try to resolve a human display name for an ACL entry.
 * For users: look up in the participants list first; fall back to uid.
 * For groups: use the participant value (gid) — no richer source available.
 */
function resolveDisplayName(entry) {
	if (entry.participantType === 'user') {
		const found = props.participants.find((p) => p.uid === entry.participant)
		if (found?.displayName) return found.displayName
		return entry.participant
	}
	return entry.participant
}
</script>

<style scoped>
.board-settings {
	display: flex;
	flex-direction: column;
	min-height: 200px;
}

/* Tab bar */
.board-settings__tabs {
	display: flex;
	border-bottom: 1px solid var(--color-border);
	padding: 0 24px;
	gap: 0;
}

.board-settings__tab {
	padding: 12px 16px;
	background: none;
	border: none;
	border-bottom: 2px solid transparent;
	margin-bottom: -1px;
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	transition: color 0.15s ease, border-color 0.15s ease;
}

.board-settings__tab:hover {
	color: var(--color-main-text);
}

.board-settings__tab--active {
	color: var(--color-primary);
	border-bottom-color: var(--color-primary);
}

/* Panel */
.board-settings__panel {
	padding: 20px 24px 24px;
}

/* ── Reused label styles (same as original LabelSettingsPanel) ─────────────── */

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

.label-settings__swatch:not(:disabled):hover {
	transform: scale(1.15);
	border-color: var(--color-primary);
}

.label-settings__swatch:disabled {
	cursor: default;
	opacity: 0.6;
}

.label-settings__swatch--no-color {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.label-settings__swatch-icon {
	line-height: 1;
	pointer-events: none;
}

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

.label-settings__name:hover:not(.label-settings__name--readonly) {
	color: var(--color-primary);
}

.label-settings__name--readonly {
	cursor: default;
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

.label-settings__error {
	width: 100%;
	color: var(--color-error);
	font-size: 0.8rem;
}

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

/* ── Sharing tab styles ───────────────────────────────────────────────────────── */

.sharing__search-wrap {
	position: relative;
	margin-bottom: 8px;
}

.sharing__search-input {
	width: 100%;
	height: 36px;
	padding: 0 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.875rem;
	transition: border-color 0.15s ease;
	box-sizing: border-box;
}

.sharing__search-input:focus {
	outline: none;
	border-color: var(--color-primary);
}

.sharing__search-spinner {
	position: absolute;
	right: 10px;
	top: 50%;
	transform: translateY(-50%);
	width: 14px;
	height: 14px;
	border: 2px solid var(--color-border);
	border-top-color: var(--color-primary);
	border-radius: 50%;
	animation: spin 0.7s linear infinite;
}

@keyframes spin {
	to { transform: translateY(-50%) rotate(360deg); }
}

.sharing__dropdown {
	position: absolute;
	top: 100%;
	left: 0;
	right: 0;
	z-index: 200;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
	list-style: none;
	margin: 2px 0 0;
	padding: 4px 0;
	max-height: 200px;
	overflow-y: auto;
}

.sharing__dropdown-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	cursor: pointer;
	font-size: 0.875rem;
	color: var(--color-main-text);
	transition: background 0.1s ease;
}

.sharing__dropdown-item:hover {
	background: var(--color-background-hover);
}

.sharing__type-icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.sharing__dropdown-name {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.sharing__dropdown-type {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.sharing__error {
	display: block;
	color: var(--color-error);
	font-size: 0.8rem;
	margin-top: 4px;
}

.sharing__error--add {
	margin-bottom: 8px;
}

.sharing__list {
	list-style: none;
	margin: 8px 0 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.sharing__empty {
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
	padding: 8px 0;
}

.sharing__entry {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border-dark, var(--color-border));
}

.sharing__entry:last-child {
	border-bottom: none;
}

.sharing__avatar-wrap {
	flex-shrink: 0;
	width: 32px;
	height: 32px;
	display: flex;
	align-items: center;
	justify-content: center;
}

.sharing__group-icon {
	width: 32px;
	height: 32px;
	border-radius: 50%;
	background: var(--color-background-dark);
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--color-text-maxcontrast);
}

.sharing__entry-name {
	flex: 1;
	font-size: 0.875rem;
	color: var(--color-main-text);
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.sharing__perms {
	display: flex;
	gap: 10px;
	flex-shrink: 0;
}

.sharing__perm-label {
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 0.8rem;
	color: var(--color-main-text);
	cursor: pointer;
	user-select: none;
}

.sharing__perm-label--disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.sharing__perm-label input[type='checkbox'] {
	cursor: inherit;
}

.sharing__remove-btn {
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
	flex-shrink: 0;
	transition: background 0.15s ease, color 0.15s ease;
}

.sharing__remove-btn:hover:not(:disabled) {
	background: rgba(var(--color-error-rgb, 227, 0, 0), 0.1);
	color: var(--color-error);
}

.sharing__remove-btn:disabled {
	opacity: 0.5;
	cursor: default;
}

.sharing__leave {
	margin-top: 20px;
	border-top: 1px solid var(--color-border);
	padding-top: 16px;
}

.sharing__leave-btn {
	height: 36px;
	padding: 0 14px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-error);
	background: transparent;
	color: var(--color-error);
	font-size: 0.875rem;
	font-weight: 600;
	cursor: pointer;
	transition: background 0.15s ease;
}

.sharing__leave-btn:hover {
	background: rgba(var(--color-error-rgb, 227, 0, 0), 0.08);
}
</style>
