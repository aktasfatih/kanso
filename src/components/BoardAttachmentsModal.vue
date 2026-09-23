<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal
		:name="t('kanso', 'Board attachments')"
		size="normal"
		@close="$emit('close')">
		<div class="board-attachments">
			<header class="board-attachments__header">
				<h2 class="board-attachments__title">{{ t('kanso', 'Attachments on this board') }}</h2>
				<span v-if="!isPending && !error" class="board-attachments__count">
					{{ n('kanso', '%n file', '%n files', total) }}
				</span>
			</header>

			<p v-if="error" class="board-attachments__error">
				{{ t('kanso', 'Failed to load the board\'s attachments.') }}
			</p>

			<!-- Loading -->
			<div v-if="isPending" class="board-attachments__loading">
				<NcLoadingIcon :size="28" />
			</div>

			<!-- Empty state -->
			<div v-else-if="!error && items.length === 0" class="board-attachments__empty">
				<PaperclipIcon :size="40" class="board-attachments__empty-icon" />
				<p class="board-attachments__empty-title">{{ t('kanso', 'No attachments yet') }}</p>
				<p class="board-attachments__empty-hint">
					{{ t('kanso', 'Files attached to any card on this board are listed here.') }}
				</p>
			</div>

			<!-- Attachment list -->
			<ul v-else-if="!error" class="board-attachments__list">
				<li v-for="item in items" :key="item.id" class="board-attachments__row">
					<button
						type="button"
						class="board-attachments__open"
						:title="t('kanso', 'Open the card this file is attached to')"
						@click="$emit('open-card', item.cardId)">
						<PaperclipIcon :size="20" class="board-attachments__row-icon" />
						<span class="board-attachments__row-main">
							<span class="board-attachments__filename" :title="item.filename">
								{{ item.filename }}
							</span>
							<span class="board-attachments__meta">
								<!-- hide-status is NOT cosmetic: NcAvatar otherwise GETs
									the user-status API once PER INSTANCE, and this list
									mounts one avatar per row - 40 files from one person
									were 40 requests for that one person (#10738). Same
									flag every other avatar in the app already sets. -->
								<NcAvatar
									:user="item.uploadedBy || ''"
									:display-name="uploader(item)"
									:size="16"
									:hide-status="true"
									:disable-menu="true" />
								<span class="board-attachments__uploader">{{ uploader(item) }}</span>
								<span class="board-attachments__sep">·</span>
								<span>{{ formatBytes(item.size) }}</span>
								<span v-if="exactTimeLabel(item.createdAt)" class="board-attachments__sep">·</span>
								<time
									v-if="exactTimeLabel(item.createdAt)"
									:datetime="isoTimestamp(item.createdAt)"
									:title="exactTimeTitle(item.createdAt)">
									{{ exactTimeLabel(item.createdAt) }}
								</time>
							</span>
						</span>
						<span class="board-attachments__card" :title="item.cardTitle">
							{{ item.cardTitle || t('kanso', 'Untitled') }}
						</span>
					</button>

					<!-- Downloads reuse the per-card endpoint by attachment id. -->
					<a
						class="board-attachments__download"
						:href="cardAttachmentUrl(item.cardId, item.id)"
						:title="t('kanso', 'Download')"
						:aria-label="t('kanso', 'Download {file}', { file: item.filename })"
						download>
						<DownloadIcon :size="20" />
					</a>
				</li>
			</ul>

			<!-- Paging (#10738). The server answers one page and says whether more
				exist for THIS viewer, so "Load more" is the client walking offsets -
				not a raised cap. Every file on the board is reachable this way. -->
			<div v-if="!error && items.length < total" class="board-attachments__footer">
				<span class="board-attachments__shown">
					{{ t('kanso', 'Showing the {shown} most recent of {total} files.', { shown: items.length, total }) }}
				</span>
				<NcButton
					v-if="hasNextPage"
					class="board-attachments__more"
					:disabled="isFetchingNextPage"
					@click="fetchNextPage()">
					{{ isFetchingNextPage ? t('kanso', 'Loading more files…') : t('kanso', 'Load more files') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script setup>
import { computed } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import PaperclipIcon from 'vue-material-design-icons/Paperclip.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import { cardAttachmentUrl } from '../services/api.js'
import { useBoardAttachments } from '../composables/useBoardAttachments.js'
import { exactTimeLabel, exactTimeTitle, isoTimestamp } from '../utils/dateDisplay.js'

const props = defineProps({
	/** Numeric board id whose attachments are listed. */
	boardId: {
		type: [Number, String],
		required: true,
	},
})

defineEmits(['close', 'open-card'])

// The modal is only mounted while it is open, so the query fires on open - ONE
// page of it, and one request regardless of how many files the board holds.
const {
	data,
	isPending,
	error,
	hasNextPage,
	isFetchingNextPage,
	fetchNextPage,
} = useBoardAttachments(computed(() => Number(props.boardId)))

// Every page loaded so far, in order. Row keys are attachment ids, so a row
// that a concurrent upload pushed across the page boundary renders once.
const items = computed(() => (data.value?.pages ?? []).flatMap((page) => page.items ?? []))

// The freshest total is the last page's - each page carries the viewer-scoped
// count as of its own query.
const total = computed(() => {
	const pages = data.value?.pages
	return pages?.length ? (pages[pages.length - 1].total ?? 0) : 0
})

/** Credit a person rather than a raw uid when the server resolved a name. */
function uploader(item) {
	return item.uploadedByName || item.uploadedBy || ''
}

function formatBytes(bytes) {
	const num = Number(bytes) || 0
	if (num < 1024) return `${num} B`
	const units = ['KB', 'MB', 'GB', 'TB']
	let value = num / 1024
	let i = 0
	while (value >= 1024 && i < units.length - 1) {
		value /= 1024
		i++
	}
	return `${value.toFixed(value < 10 ? 1 : 0)} ${units[i]}`
}
</script>

<style scoped>
.board-attachments {
	padding: 20px 24px 24px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-height: 200px;
}

.board-attachments__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.board-attachments__title {
	font-size: 1.1rem;
	font-weight: 700;
	margin: 0;
	color: var(--color-main-text);
}

.board-attachments__count {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.board-attachments__error {
	color: var(--color-error-text);
	font-size: 0.85rem;
	margin: 0;
}

.board-attachments__loading {
	display: flex;
	justify-content: center;
	padding: 32px 0;
}

.board-attachments__empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
	gap: 6px;
	padding: 24px 8px;
	color: var(--color-text-maxcontrast);
}

.board-attachments__empty-icon {
	color: var(--color-text-maxcontrast);
	opacity: 0.7;
}

.board-attachments__empty-title {
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0;
}

.board-attachments__empty-hint {
	font-size: 0.85rem;
	margin: 0;
	max-width: 420px;
}

.board-attachments__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 0;
	padding: 0;
	list-style: none;
	max-height: 60vh;
	overflow-y: auto;
}

.board-attachments__row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 8px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.board-attachments__open {
	flex: 1 1 auto;
	min-width: 0;
	display: flex;
	align-items: center;
	gap: 8px;
	background: none;
	border: none;
	padding: 2px;
	margin: 0;
	text-align: start;
	cursor: pointer;
	color: var(--color-main-text);
	border-radius: var(--border-radius);
}

.board-attachments__open:hover,
.board-attachments__open:focus-visible {
	background: var(--color-background-hover);
}

.board-attachments__row-icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.board-attachments__row-main {
	flex: 1 1 auto;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.board-attachments__filename {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 0.9rem;
}

.board-attachments__meta {
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	min-width: 0;
}

.board-attachments__uploader {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	max-width: 12em;
}

.board-attachments__card {
	flex: 0 1 30%;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	text-align: end;
}

.board-attachments__download {
	flex-shrink: 0;
	display: flex;
	align-items: center;
	padding: 4px;
	border-radius: var(--border-radius);
	color: var(--color-main-text);
}

.board-attachments__download:hover,
.board-attachments__download:focus-visible {
	background: var(--color-background-hover);
}

.board-attachments__footer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
}

.board-attachments__shown {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}
</style>
