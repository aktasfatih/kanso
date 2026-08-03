<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="board-filter-bar">
		<!-- ── Composable filter dropdown ─────────────────────────────────────────
		     A single NcActions menu with one section per dimension. Each section
		     is OR-within (checkboxes / radios), AND is applied across sections in
		     the predicate. Mirrors the old label/priority dropdown, generalised. -->
		<NcActions
			class="board-filter-bar__filter"
			:aria-label="t('kanso', 'Filter cards')"
			:menu-name="count > 0
				? t('kanso', 'Filter · {count}', { count })
				: t('kanso', 'Filter')"
			:primary="count > 0">
			<template #icon>
				<FilterVariantIcon :size="20" />
			</template>

			<!-- Labels (OR within) -->
			<template v-if="labels.length">
				<NcActionCaption :name="t('kanso', 'Label')" />
				<NcActionCheckbox
					v-for="label in labels"
					:key="'l-' + label.id"
					class="board-filter-bar__label-item"
					:style="label.color ? { '--filter-dot-color': '#' + label.color } : { '--filter-dot-color': 'var(--color-border)' }"
					:model-value="state.labels.has(label.id)"
					@update:model-value="toggleSet('labels', label.id)">{{ label.title }}</NcActionCheckbox>
			</template>

			<!-- Assignees (OR within) + the Unassigned sentinel -->
			<template v-if="participants.length">
				<NcActionCaption :name="t('kanso', 'Assignee')" />
				<NcActionCheckbox
					:model-value="state.assignees.has(UNASSIGNED)"
					@update:model-value="toggleSet('assignees', UNASSIGNED)">{{ t('kanso', 'Unassigned') }}</NcActionCheckbox>
				<NcActionCheckbox
					v-for="p in participants"
					:key="'a-' + p.uid"
					:model-value="state.assignees.has(p.uid)"
					@update:model-value="toggleSet('assignees', p.uid)">{{ p.displayName || p.uid }}</NcActionCheckbox>
			</template>

			<!-- Priority (OR within) -->
			<NcActionCaption :name="t('kanso', 'Priority')" />
			<NcActionCheckbox
				v-for="level in priorityLevels"
				:key="'p-' + level.value"
				class="board-filter-bar__priority-item"
				:class="`board-filter-bar__priority-item--${level.value}`"
				:model-value="state.priorities.has(level.value)"
				@update:model-value="toggleSet('priorities', level.value)">{{ t('kanso', level.label) }}</NcActionCheckbox>

			<!-- Due (single-select radio; a re-click of the active one clears it) -->
			<NcActionCaption :name="t('kanso', 'Due date')" />
			<NcActionRadio
				v-for="opt in DUE_OPTIONS"
				:key="'d-' + opt.value"
				:model-value="state.due === opt.value"
				name="kanso-filter-due"
				@update:model-value="setSingle('due', opt.value)">{{ t('kanso', opt.label) }}</NcActionRadio>

			<!-- Done state (tri-state via radios) -->
			<NcActionCaption :name="t('kanso', 'Status')" />
			<NcActionRadio
				v-for="opt in DONE_OPTIONS"
				:key="'s-' + opt.value"
				:model-value="state.done === opt.value"
				name="kanso-filter-done"
				@update:model-value="setSingle('done', opt.value)">{{ t('kanso', opt.label) }}</NcActionRadio>

			<!-- Clear (only when something is active) -->
			<template v-if="count > 0">
				<NcActionSeparator />
				<NcActionButton @click="clearAll">
					<template #icon>
						<FilterVariantRemoveIcon :size="20" />
					</template>
					{{ t('kanso', 'Clear filters') }}
				</NcActionButton>
			</template>
		</NcActions>

		<!-- ── Saved views dropdown ───────────────────────────────────────────────
		     Save the current filter as a named view (per-user NC config), apply a
		     saved view, or delete one. Bookmark icon; highlighted when the active
		     filter matches a saved view. -->
		<NcActions
			class="board-filter-bar__saved"
			:aria-label="t('kanso', 'Saved filters')"
			:menu-name="activeSavedName || t('kanso', 'Saved')">
			<template #icon>
				<BookmarkIcon v-if="activeSavedName" :size="20" />
				<BookmarkOutlineIcon v-else :size="20" />
			</template>

			<template v-if="savedFilters.length">
				<NcActionCaption :name="t('kanso', 'Saved filters')" />
				<NcActionButton
					v-for="view in savedFilters"
					:key="'v-' + view.name"
					:class="{ 'board-filter-bar__saved-item--active': view.name === activeSavedName }"
					@click="$emit('apply-saved', view)">
					<template #icon>
						<CheckIcon v-if="view.name === activeSavedName" :size="20" />
						<BookmarkOutlineIcon v-else :size="20" />
					</template>
					{{ view.name }}
				</NcActionButton>
				<NcActionButton
					v-if="activeSavedName"
					class="board-filter-bar__saved-delete"
					@click="$emit('delete-saved', activeSavedName)">
					<template #icon>
						<DeleteOutlineIcon :size="20" />
					</template>
					{{ t('kanso', 'Delete "{name}"', { name: activeSavedName }) }}
				</NcActionButton>
				<NcActionSeparator />
			</template>

			<!-- Save-as: an inline text input; Enter (or the confirm arrow) saves
			     the current filter under the typed name. Disabled with no active
			     filter, since an empty saved view is meaningless. -->
			<NcActionCaption :name="t('kanso', 'Save current filter')" />
			<NcActionInput
				v-model="saveName"
				:disabled="count === 0"
				:label="t('kanso', 'View name')"
				:label-outside="false"
				:placeholder="t('kanso', 'View name')"
				@submit="submitSave">
				<template #icon>
					<ContentSaveOutlineIcon :size="20" />
				</template>
			</NcActionInput>
			<p v-if="saveError" class="board-filter-bar__save-error">{{ saveError }}</p>
		</NcActions>
	</div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionCheckbox from '@nextcloud/vue/components/NcActionCheckbox'
import NcActionRadio from '@nextcloud/vue/components/NcActionRadio'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcActionInput from '@nextcloud/vue/components/NcActionInput'
import FilterVariantIcon from 'vue-material-design-icons/FilterVariant.vue'
import FilterVariantRemoveIcon from 'vue-material-design-icons/FilterVariantRemove.vue'
import BookmarkIcon from 'vue-material-design-icons/Bookmark.vue'
import BookmarkOutlineIcon from 'vue-material-design-icons/BookmarkOutline.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import DeleteOutlineIcon from 'vue-material-design-icons/DeleteOutline.vue'
import ContentSaveOutlineIcon from 'vue-material-design-icons/ContentSaveOutline.vue'
import { PRIORITY_LEVELS } from '../composables/usePriority.js'
import {
	UNASSIGNED,
	DUE_OPTIONS,
	DONE_OPTIONS,
	useFilterCount,
} from '../composables/useBoardFilters.js'

const props = defineProps({
	/** The reactive filter state (from createFilterState). */
	state: { type: Object, required: true },
	/** Board labels [{ id, title, color }]. */
	labels: { type: Array, default: () => [] },
	/** Board participants [{ uid, displayName }]. */
	participants: { type: Array, default: () => [] },
	/** Saved views [{ name, filter }]. */
	savedFilters: { type: Array, default: () => [] },
	/** Name of the saved view the current filter equals, or '' if none. */
	activeSavedName: { type: String, default: '' },
})

const emit = defineEmits(['save', 'apply-saved', 'delete-saved'])

// Priority levels except "None" (0) — 0 is represented by NOT selecting any
// priority; offering an explicit "None" checkbox would be redundant AND would
// mean "priority == 0" which is better expressed by leaving priority unfiltered.
const priorityLevels = computed(() => PRIORITY_LEVELS.filter((l) => l.value > 0))

const count = useFilterCount(props.state)

const saveName = ref('')
const saveError = ref('')

function toggleSet(dim, value) {
	const set = props.state[dim]
	if (set.has(value)) set.delete(value)
	else set.add(value)
}

// Radio dimensions are single-select but re-selecting the active option clears
// it (tri-state feel): NcActionRadio only emits true on select, so we compare.
function setSingle(dim, value) {
	props.state[dim] = props.state[dim] === value ? null : value
}

function clearAll() {
	props.state.labels.clear()
	props.state.assignees.clear()
	props.state.priorities.clear()
	props.state.due = null
	props.state.done = null
}

function submitSave() {
	const name = saveName.value.trim()
	if (!name) {
		saveError.value = t('kanso', 'Enter a name.')
		return
	}
	saveError.value = ''
	emit('save', name)
	saveName.value = ''
}

// Clear any stale save-error once the user resumes typing.
watch(saveName, () => { saveError.value = '' })
</script>

<style scoped>
.board-filter-bar {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
}

/* Label color dot — same pseudo-element technique as the old dropdown:
   NcActionCheckbox reads its slot as plain text, so the dot is drawn via a
   ::before on the inner text span, coloured by --filter-dot-color. */
.board-filter-bar__label-item:deep(.action-checkbox__text)::before {
	content: '';
	display: inline-block;
	width: 12px;
	height: 12px;
	min-width: 12px;
	border-radius: 50%;
	background: var(--filter-dot-color, var(--color-border));
	margin-right: 6px;
	vertical-align: middle;
}

.board-filter-bar__priority-item--1:deep(.action-checkbox__text)::before,
.board-filter-bar__priority-item--2:deep(.action-checkbox__text)::before,
.board-filter-bar__priority-item--3:deep(.action-checkbox__text)::before,
.board-filter-bar__priority-item--4:deep(.action-checkbox__text)::before {
	content: '';
	display: inline-block;
	width: 12px;
	height: 12px;
	min-width: 12px;
	border-radius: 50%;
	margin-right: 6px;
	vertical-align: middle;
}
.board-filter-bar__priority-item--1:deep(.action-checkbox__text)::before { background: #888; }
.board-filter-bar__priority-item--2:deep(.action-checkbox__text)::before { background: var(--color-primary-element, #0082c9); }
.board-filter-bar__priority-item--3:deep(.action-checkbox__text)::before { background: #e07b00; }
.board-filter-bar__priority-item--4:deep(.action-checkbox__text)::before { background: var(--color-error, #e30000); }

.board-filter-bar__saved-item--active {
	font-weight: 600;
}

.board-filter-bar__save-error {
	color: var(--color-error);
	font-size: 0.8rem;
	margin: 4px 12px;
}
</style>
