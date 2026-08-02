<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="trash-view">
		<!-- Header: back affordance + title + in-view filter -->
		<div class="trash-view__header">
			<NcButton class="trash-view__back" @click="goBack">
				<template #icon>
					<ArrowLeftIcon :size="20" />
				</template>
				{{ boardTitle || t('kanso', 'Board') }}
			</NcButton>
			<h1 class="trash-view__title">
				<DeleteIcon :size="20" />
				{{ t('kanso', 'Trash') }}
				<span v-if="!trashQuery.isLoading.value" class="trash-view__count">{{ trashCards.length }}</span>
			</h1>

			<!-- In-view text filter (title match). -->
			<div v-if="!trashQuery.isLoading.value && trashCards.length > 0" class="trash-view__filter">
				<MagnifyIcon :size="18" class="trash-view__filter-icon" />
				<input
					v-model="filterText"
					class="trash-view__filter-input"
					type="text"
					:placeholder="t('kanso', 'Filter trash…')"
					:aria-label="t('kanso', 'Filter deleted cards')" />
			</div>
		</div>

		<!-- Loading -->
		<div v-if="trashQuery.isLoading.value" class="trash-view__loading" aria-live="polite">
			<span class="trash-view__spinner" aria-hidden="true" />
			<span>{{ t('kanso', 'Loading…') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="trashQuery.isError.value" class="trash-view__error">
			{{ t('kanso', 'Failed to load trash.') }}
		</div>

		<!-- Empty state (trash empty) -->
		<div v-else-if="trashCards.length === 0" class="trash-view__empty">
			<DeleteIcon :size="48" class="trash-view__empty-icon" />
			<p class="trash-view__empty-title">{{ t('kanso', 'Trash is empty') }}</p>
			<p class="trash-view__empty-sub">
				{{ t('kanso', 'Cards you delete from the board land here until they are restored or permanently removed.') }}
			</p>
		</div>

		<!-- Empty filter result -->
		<div v-else-if="rows.length === 0" class="trash-view__empty">
			<p class="trash-view__empty-sub">
				{{ t('kanso', 'No deleted cards match “{query}”.', { query: filterText }) }}
			</p>
		</div>

		<!-- Virtualized list: stack group headers + card rows -->
		<div v-else ref="scrollRef" class="trash-view__scroll">
			<div
				class="trash-view__host"
				:style="{ height: virtualizer.getTotalSize() + 'px' }">
				<div
					v-for="vRow in virtualizer.getVirtualItems()"
					:key="rows[vRow.index].id"
					:data-index="vRow.index"
					class="trash-view__vrow"
					:style="{ position: 'absolute', top: 0, left: 0, width: '100%', height: `${vRow.size}px`, transform: `translateY(${vRow.start}px)` }">

					<!-- Stack group header -->
					<div v-if="rows[vRow.index].type === 'header'" class="trash-view__group">
						<span
							class="trash-view__group-dot"
							:style="rows[vRow.index].stack.color ? { background: cssColor(rows[vRow.index].stack.color) } : {}" />
						<span class="trash-view__group-title">{{ rows[vRow.index].stack.title }}</span>
						<span class="trash-view__group-count">{{ rows[vRow.index].count }}</span>
					</div>

					<!-- Card row -->
					<div v-else class="trash-view__row">
						<div class="trash-view__row-info">
							<span class="trash-view__row-title">{{ rows[vRow.index].card.title }}</span>
							<span v-if="rows[vRow.index].card.deletedAt" class="trash-view__row-deleted">
								{{ formatDeletedAt(rows[vRow.index].card.deletedAt) }}
							</span>
						</div>
						<div class="trash-view__row-actions">
							<!-- Restore: available to editors. -->
							<NcButton
								v-if="canEdit"
								:disabled="restore.isPending.value && restoringId === rows[vRow.index].card.id"
								@click="handleRestore(rows[vRow.index].card)">
								<template #icon>
									<RestoreIcon :size="16" />
								</template>
								{{ t('kanso', 'Restore') }}
							</NcButton>

							<!-- Permanently delete: MANAGE-gated. -->
							<template v-if="canManage">
								<NcButton
									v-if="confirmPurgeId !== rows[vRow.index].card.id"
									type="error"
									:disabled="purge.isPending.value && purgingId === rows[vRow.index].card.id"
									@click="confirmPurgeId = rows[vRow.index].card.id">
									<template #icon>
										<DeleteForeverIcon :size="16" />
									</template>
									{{ t('kanso', 'Delete permanently') }}
								</NcButton>
								<template v-else>
									<span class="trash-view__confirm-text">{{ t('kanso', 'Are you sure?') }}</span>
									<NcButton
										type="error"
										:disabled="purge.isPending.value"
										@click="handlePurge(rows[vRow.index].card)">
										{{ t('kanso', 'Yes, delete') }}
									</NcButton>
									<NcButton @click="confirmPurgeId = null">
										{{ t('kanso', 'Cancel') }}
									</NcButton>
								</template>
							</template>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Action error banner -->
		<div v-if="actionError" class="trash-view__action-error">
			{{ actionError }}
		</div>
	</div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import { useVirtualizer } from '@tanstack/vue-virtual'
import { useQueryClient } from '@tanstack/vue-query'
import NcButton from '@nextcloud/vue/components/NcButton'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import RestoreIcon from 'vue-material-design-icons/Restore.vue'
import DeleteForeverIcon from 'vue-material-design-icons/DeleteForever.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import { useBoard } from '../composables/useBoard.js'
import { useTrash } from '../composables/useTrash.js'
import { boardQueryKey } from '../composables/queryKeys.js'
import { cssColor } from '../services/color.js'

const props = defineProps({
	id: {
		type: String,
		required: true,
	},
})

const router = useRouter()
const queryClient = useQueryClient()
const boardId = computed(() => props.id)

// Board query supplies stack titles + permissions (may already be warm in the
// cache from BoardView; if cold it fetches, gated by board READ like the board).
const { data: boardData } = useBoard(boardId)

// The board title falls back to the cache directly so it renders even before the
// board query resolves on a deep-link cold load.
const boardTitle = computed(() =>
	boardData.value?.board?.title
	?? queryClient.getQueryData(boardQueryKey(props.id))?.board?.title
	?? '',
)

// Permissions bitmask: READ=1, EDIT=2, SHARE=4, MANAGE=8.
const permissions = computed(() => Number(boardData.value?.permissions ?? 0))
const canEdit = computed(() => (permissions.value & 2) !== 0 || (permissions.value & 8) !== 0)
const canManage = computed(() => (permissions.value & 8) !== 0)

const bySortKey = (a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0)

const sortedStacks = computed(() => {
	if (!boardData.value?.stacks) return []
	return [...boardData.value.stacks].filter((s) => !s.archived).sort(bySortKey)
})

const stackMap = computed(() => new Map(sortedStacks.value.map((s) => [s.id, s])))

// Trash list is fetched from GET /api/boards/{id}/trash. The view is always the
// active surface, so the query is always enabled.
const enabled = computed(() => true)
const { trashQuery, restore, purge } = useTrash(boardId, enabled)

const trashCards = computed(() => {
	const cards = trashQuery.data.value
	return Array.isArray(cards) ? cards : []
})

// ── In-view text filter (title, case-insensitive) ─────────────────────────────
const filterText = ref('')

const filteredCards = computed(() => {
	const q = filterText.value.trim().toLowerCase()
	if (!q) return trashCards.value
	return trashCards.value.filter((c) => String(c.title).toLowerCase().includes(q))
})

/**
 * Flat row model for the virtualizer: one header per stack that still has
 * matching trashed cards, then its cards. Cards whose stack was deleted after
 * the card was trashed fall under an "Unknown stack" group.
 */
const rows = computed(() => {
	const byStack = new Map()
	for (const card of filteredCards.value) {
		if (!byStack.has(card.stackId)) byStack.set(card.stackId, [])
		byStack.get(card.stackId).push(card)
	}

	const out = []
	for (const stack of sortedStacks.value) {
		const cards = byStack.get(stack.id)
		if (!cards || !cards.length) continue
		out.push({ type: 'header', id: `h${stack.id}`, stack, count: cards.length })
		for (const card of cards) out.push({ type: 'card', id: `c${card.id}`, card })
	}
	for (const [stackId, cards] of byStack) {
		if (stackMap.value.has(stackId)) continue
		const stack = { id: stackId, title: t('kanso', 'Unknown stack'), color: null }
		out.push({ type: 'header', id: `h${stackId}`, stack, count: cards.length })
		for (const card of cards) out.push({ type: 'card', id: `c${card.id}`, card })
	}
	return out
})

const scrollRef = ref(null)
const HEADER_H = 38
const ROW_H = 56
const virtualizer = useVirtualizer(computed(() => ({
	count: rows.value.length,
	getScrollElement: () => scrollRef.value,
	estimateSize: (i) => (rows.value[i]?.type === 'header' ? HEADER_H : ROW_H),
	overscan: 12,
	getItemKey: (i) => rows.value[i]?.id ?? i,
})))

// ── Actions ───────────────────────────────────────────────────────────────────
const restoringId = ref(null)
const purgingId = ref(null)
const confirmPurgeId = ref(null)
const actionError = ref('')

async function handleRestore(card) {
	actionError.value = ''
	restoringId.value = card.id
	try {
		await restore.mutateAsync(card.id)
	} catch (err) {
		actionError.value = err?.response?.data?.error || t('kanso', 'Failed to restore card.')
	} finally {
		restoringId.value = null
	}
}

async function handlePurge(card) {
	actionError.value = ''
	confirmPurgeId.value = null
	purgingId.value = card.id
	try {
		await purge.mutateAsync(card.id)
	} catch (err) {
		actionError.value = err?.response?.data?.error || t('kanso', 'Failed to permanently delete card.')
	} finally {
		purgingId.value = null
	}
}

function goBack() {
	router.push({ name: 'board', params: { id: props.id } })
}

/**
 * Human-readable relative label for the deletedAt timestamp (seconds since epoch
 * or ISO string), via Intl.RelativeTimeFormat.
 */
function formatDeletedAt(deletedAt) {
	try {
		const ms = typeof deletedAt === 'number' ? deletedAt * 1000 : new Date(deletedAt).getTime()
		if (!ms || Number.isNaN(ms)) return ''
		const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })
		const diffSec = Math.round((ms - Date.now()) / 1000)
		const absSec = Math.abs(diffSec)
		if (absSec < 60) return t('kanso', 'Deleted {when}', { when: rtf.format(diffSec, 'second') })
		if (absSec < 3600) return t('kanso', 'Deleted {when}', { when: rtf.format(Math.round(diffSec / 60), 'minute') })
		if (absSec < 86400) return t('kanso', 'Deleted {when}', { when: rtf.format(Math.round(diffSec / 3600), 'hour') })
		return t('kanso', 'Deleted {when}', { when: rtf.format(Math.round(diffSec / 86400), 'day') })
	} catch {
		return ''
	}
}
</script>

<style scoped>
.trash-view {
	display: flex;
	flex-direction: column;
	height: 100%;
	overflow: hidden;
}

/* ── Header ─────────────────────────────────────────────────────────────────── */
.trash-view__header {
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 12px 24px 12px 52px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-main-background);
	flex-shrink: 0;
}

.trash-view__back {
	flex-shrink: 0;
}

.trash-view__title {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 1.2rem;
	font-weight: 700;
	color: var(--color-main-text);
	margin: 0;
}

.trash-view__count {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-hover);
	border-radius: 12px;
	padding: 1px 9px;
}

.trash-view__filter {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-left: auto;
	padding: 4px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 16px);
	background: var(--color-main-background);
}

.trash-view__filter-icon {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.trash-view__filter-input {
	border: none;
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.875rem;
	width: 180px;
}

.trash-view__filter-input:focus {
	outline: none;
}

/* ── Loading / error / empty ─────────────────────────────────────────────────── */
.trash-view__loading {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--color-text-maxcontrast);
	padding: 32px;
}

.trash-view__spinner {
	display: inline-block;
	width: 20px;
	height: 20px;
	border: 2px solid var(--color-border);
	border-top-color: var(--color-primary-element);
	border-radius: 50%;
	animation: trash-view-spin 0.7s linear infinite;
}

@keyframes trash-view-spin {
	to { transform: rotate(360deg); }
}

.trash-view__error {
	color: var(--color-error);
	padding: 24px 32px;
}

.trash-view__empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 6px;
	flex: 1;
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 48px 24px;
}

.trash-view__empty-icon {
	color: var(--color-border-dark);
	margin-bottom: 8px;
}

.trash-view__empty-title {
	font-size: 1.05rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0;
}

.trash-view__empty-sub {
	margin: 0;
	font-size: 0.9rem;
	max-width: 420px;
}

/* ── Virtualized list ─────────────────────────────────────────────────────────── */
.trash-view__scroll {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
	padding: 8px 24px 24px 52px;
}

.trash-view__host {
	position: relative;
	width: 100%;
	max-width: 1100px;
}

.trash-view__vrow {
	overflow: hidden;
}

.trash-view__group {
	display: flex;
	align-items: center;
	gap: 8px;
	box-sizing: border-box;
	height: 100%;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
	border-bottom: 1px solid var(--color-border);
	text-transform: uppercase;
	font-size: 0.8rem;
	letter-spacing: 0.03em;
}

.trash-view__group-dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: var(--color-primary-element);
	flex: 0 0 auto;
}

.trash-view__group-count {
	color: var(--color-text-maxcontrast);
	font-weight: 400;
}

.trash-view__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	box-sizing: border-box;
	height: 100%;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.trash-view__row-info {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1;
	min-width: 0;
}

.trash-view__row-title {
	font-size: 0.9rem;
	color: var(--color-main-text);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.trash-view__row-deleted {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.trash-view__row-actions {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-shrink: 0;
}

.trash-view__confirm-text {
	font-size: 0.8rem;
	color: var(--color-error);
	font-weight: 600;
}

/* ── Action error ─────────────────────────────────────────────────────────────── */
.trash-view__action-error {
	flex-shrink: 0;
	padding: 8px 24px 8px 52px;
	background: rgba(var(--color-error-rgb, 227, 0, 0), 0.1);
	color: var(--color-error);
	font-size: 0.875rem;
}
</style>
