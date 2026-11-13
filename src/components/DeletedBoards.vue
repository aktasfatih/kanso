<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="deleted-boards" data-test="deleted-boards">
		<h2>{{ t('kanso', 'Deleted boards') }}</h2>
		<p>{{ t('kanso', 'Owners and managers can restore deleted boards for 30 days. After that, the board and its contents are permanently removed. Archived boards have no deletion deadline.') }}</p>
		<p v-if="message" role="status">{{ message }}</p>
		<p v-if="restoreError" role="alert">{{ restoreError }}</p>
		<p v-if="isLoading">{{ t('kanso', 'Loading deleted boards…') }}</p>
		<div v-else-if="isError" role="alert">
			<p>{{ t('kanso', 'Could not load deleted boards.') }}</p>
			<NcButton @click="refetch()">{{ t('kanso', 'Try again') }}</NcButton>
		</div>
		<template v-else>
			<p v-if="visibleBoards.length === 0">
				{{ search.trim() ? t('kanso', 'No boards match your search.') : t('kanso', 'No deleted boards to restore.') }}
			</p>
			<ul v-else class="deleted-boards__list">
				<li
					v-for="board in visibleBoards"
					:key="board.id"
					class="deleted-boards__row"
					:data-test="`deleted-board-${board.id}`">
					<div class="deleted-boards__details">
						<h3>{{ board.title }}</h3>
						<p v-if="board.archived">{{ t('kanso', 'Restores to Archived.') }}</p>
						<p v-if="board.restoreUntil < now">{{ t('kanso', 'Recovery window expired.') }}</p>
						<template v-else>
							<p>{{ t('kanso', 'Restore before {date}', { date: new Date(board.restoreUntil * 1000).toLocaleString() }) }}</p>
							<p>{{ n('kanso', '%n day left', '%n days left', Math.max(1, Math.ceil((board.restoreUntil - now) / 86400))) }}</p>
						</template>
					</div>
					<NcButton
						class="deleted-boards__restore"
						:disabled="restore.isPending.value || board.restoreUntil < now"
						@click="restoreDeleted(board)">
						{{ t('kanso', 'Restore board') }}
					</NcButton>
				</li>
			</ul>
		</template>
	</section>
</template>

<script setup>
import { computed, ref, onUnmounted } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { useDeletedBoards } from '../composables/useDeletedBoards.js'

const props = defineProps({ search: { type: String, default: '' } })
const { data, isLoading, isError, refetch, restore } = useDeletedBoards()
const visibleBoards = computed(() => (data.value || []).filter((board) =>
	(board.title || '').toLowerCase().includes(props.search.trim().toLowerCase()),
))
const now = ref(Math.floor(Date.now() / 1000))
const timer = setInterval(() => { now.value = Math.floor(Date.now() / 1000) }, 1000)
onUnmounted(() => clearInterval(timer))
const message = ref('')
const restoreError = ref('')

async function restoreDeleted(board) {
	message.value = ''
	restoreError.value = ''
	try {
		await restore.mutateAsync(board.id)
		message.value = board.archived
			? t('kanso', 'Board restored. Find it under Archived.')
			: t('kanso', 'Board restored. Find it under Active.')
	} catch (error) {
		restoreError.value = [403, 404].includes(error?.response?.status)
			? t('kanso', 'This board can no longer be restored. Its recovery window or your access may have ended.')
			: t('kanso', 'Could not restore the board. Try again.')
	}
}
</script>

<style scoped>
.deleted-boards > p {
	margin-block: 12px;
}
.deleted-boards__list {
	list-style: none;
	padding: 0;
}
.deleted-boards__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	padding: 16px 0;
	border-bottom: 1px solid var(--color-border);
}
.deleted-boards__details {
	min-width: 0;
}
.deleted-boards__restore {
	flex-shrink: 0;
}
.deleted-boards__row h3 {
	overflow-wrap: anywhere;
	margin: 0 0 8px;
}
.deleted-boards__row p {
	color: var(--color-text-maxcontrast);
	margin: 4px 0;
}
</style>
