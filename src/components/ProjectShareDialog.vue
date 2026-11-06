<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="t('kanso', 'Share project')"
		:open="true"
		data-test="project-share-dialog"
		@closing="emit('close')">
		<div class="project-share">
			<p class="project-share__hint">
				{{ t('kanso', 'People you share with see this project’s details and its whole discussion, including earlier notes. They only see the cards they can already access on their boards.') }}
			</p>

			<div class="project-share__search">
				<input
					ref="searchInput"
					v-model="searchQuery"
					class="project-share__search-input"
					type="search"
					data-test="project-share-search"
					:placeholder="t('kanso', 'Search users or groups…')"
					:aria-label="t('kanso', 'Search users or groups')">
				<span v-if="searchError" class="project-share__error" role="alert">{{ searchError }}</span>
				<span v-else-if="isSearching" class="project-share__empty" aria-live="polite">{{ t('kanso', 'Searching…') }}</span>
				<span
					v-else-if="searchFinished && searchResults.length === 0"
					class="project-share__empty"
					aria-live="polite">{{ t('kanso', 'No users or groups found.') }}</span>

				<ul v-if="searchResults.length > 0" class="project-share__results">
					<li v-for="result in searchResults" :key="`${result.type}-${result.id}`">
						<button
							type="button"
							class="project-share__result"
							data-test="project-share-result"
							:aria-busy="addShare.isPending.value"
							@click="pickSharee(result)">
							<AccountGroupIcon v-if="result.type === 'group'" :size="18" />
							<AccountIcon v-else :size="18" />
							<span class="project-share__name">{{ result.displayName }}</span>
							<span v-if="result.id !== result.displayName" class="project-share__id">{{ result.id }}</span>
							<span class="project-share__type">{{ result.type === 'group' ? t('kanso', 'Group') : t('kanso', 'User') }}</span>
						</button>
					</li>
				</ul>
			</div>

			<span v-if="error" class="project-share__error" role="alert">{{ error }}</span>

			<div v-if="shares.isLoading.value" class="project-share__empty" aria-live="polite">
				{{ t('kanso', 'Loading…') }}
			</div>
			<div v-else-if="shares.isError.value" class="project-share__error" role="alert">
				{{ t('kanso', 'Failed to load shares.') }}
			</div>
			<ul v-else class="project-share__list">
				<li v-if="shareList.length === 0" class="project-share__empty">
					{{ t('kanso', 'Not shared with anyone yet.') }}
				</li>
				<li
					v-for="share in shareList"
					:key="share.id"
					class="project-share__entry"
					data-test="project-share-entry">
					<AccountGroupIcon v-if="share.participantType === 'group'" :size="18" />
					<AccountIcon v-else :size="18" />
					<span class="project-share__name">{{ share.displayName }}</span>
					<span v-if="share.participant !== share.displayName" class="project-share__id">{{ share.participant }}</span>
					<select
						class="project-share__level"
						data-test="project-share-level"
						:aria-label="t('kanso', 'Access level for {name}', { name: share.displayName }, RAW_TEXT)"
						:value="share.permission"
						:aria-busy="patchShare.isPending.value"
						@change="changeLevel(share, Number($event.target.value))">
						<option :value="PERMISSION_VIEW">{{ t('kanso', 'Can view') }}</option>
						<option :value="PERMISSION_EDIT">{{ t('kanso', 'Can edit') }}</option>
					</select>
					<button
						type="button"
						class="project-share__remove"
						data-test="project-share-remove"
						:aria-label="t('kanso', 'Remove {name}', { name: share.displayName }, RAW_TEXT)"
						:title="t('kanso', 'Remove {name}', { name: share.displayName }, RAW_TEXT)"
						:aria-busy="removeShare.isPending.value"
						@click="remove(share)">
						<CloseIcon :size="18" />
					</button>
				</li>
			</ul>
		</div>
	</NcDialog>
</template>

<script setup>
import { computed, ref, toRef } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import { useProjectShares } from '../composables/useProjectShares.js'

const PERMISSION_VIEW = 1
const PERMISSION_EDIT = 3

// Vue escapes these bindings, so t() escaping too would print "R&amp;D".
const RAW_TEXT = { escape: false, sanitize: false }

const props = defineProps({
	projectId: { type: [String, Number], required: true },
})

const emit = defineEmits(['close'])

const {
	shares,
	addShare,
	patchShare,
	removeShare,
	searchQuery,
	searchResults,
	isSearching,
	searchError,
	searchFinished,
	clearSearch,
} = useProjectShares(toRef(props, 'projectId'), ref(true))

const shareList = computed(() => shares.data.value ?? [])
const error = ref('')
const searchInput = ref(null)

async function pickSharee(result) {
	if (addShare.isPending.value) return
	error.value = ''
	try {
		await addShare.mutateAsync({
			participant: result.id,
			participantType: result.type,
			permission: PERMISSION_VIEW,
		})
		clearSearch()
		searchInput.value?.focus()
	} catch (err) {
		error.value = err?.response?.data?.error || t('kanso', 'Failed to share project.')
	}
}

async function changeLevel(share, permission) {
	error.value = ''
	try {
		await patchShare.mutateAsync({ shareId: share.id, permission })
	} catch (err) {
		error.value = err?.response?.data?.error || t('kanso', 'Failed to update access.')
	}
}

async function remove(share) {
	if (removeShare.isPending.value) return
	error.value = ''
	try {
		await removeShare.mutateAsync(share.id)
		searchInput.value?.focus()
	} catch (err) {
		error.value = err?.response?.data?.error || t('kanso', 'Failed to remove access.')
	}
}
</script>

<style scoped>
.project-share {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-bottom: 12px;
}

.project-share__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.project-share__search-input {
	width: 100%;
	margin: 0;
}

.project-share__results,
.project-share__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.project-share__results {
	margin-top: 4px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	max-height: 200px;
	overflow-y: auto;
}

.project-share__result {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
	min-height: 44px;
	margin: 0;
	padding: 4px 8px;
	border: none;
	border-radius: 0;
	background: none;
	font-weight: normal;
	text-align: start;
	cursor: pointer;
}

.project-share__result:hover,
.project-share__result:focus-visible {
	background: var(--color-background-hover);
}

.project-share__entry {
	display: flex;
	align-items: center;
	gap: 8px;
	min-height: 44px;
	border-bottom: 1px solid var(--color-border);
}

.project-share__entry:last-child {
	border-bottom: none;
}

.project-share__name {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.project-share__type,
.project-share__id {
	color: var(--color-text-maxcontrast);
}

.project-share__id {
	max-width: 30%;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.project-share__level {
	margin: 0;
	min-width: 0;
}

.project-share__remove {
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	width: 44px;
	height: 44px;
	margin: 0;
	padding: 0;
	border: none;
	border-radius: var(--border-radius);
	background: none;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}

.project-share__remove:hover:not(:disabled) {
	background: var(--color-background-hover);
	color: var(--color-error);
}

.project-share__empty {
	color: var(--color-text-maxcontrast);
}

.project-share__error {
	color: var(--color-error);
}
</style>
