<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal
		:show="true"
		:name="t('kanso', 'Trash')"
		size="normal"
		@close="$emit('close')">
		<div class="trash-panel">
			<!-- Loading state -->
			<template v-if="trashQuery.isLoading.value">
				<div class="trash-panel__loading">
					{{ t('kanso', 'Loading…') }}
				</div>
			</template>

			<!-- Error state -->
			<template v-else-if="trashQuery.isError.value">
				<div class="trash-panel__error">
					{{ t('kanso', 'Failed to load trash.') }}
				</div>
			</template>

			<!-- Empty state -->
			<template v-else-if="groupedCards.length === 0">
				<div class="trash-panel__empty">
					{{ t('kanso', 'Trash is empty.') }}
				</div>
			</template>

			<!-- Cards grouped by stack -->
			<template v-else>
				<div
					v-for="group in groupedCards"
					:key="group.stackId"
					class="trash-panel__group">
					<h3 class="trash-panel__stack-name">{{ group.stackTitle }}</h3>
					<ul class="trash-panel__card-list">
						<li
							v-for="card in group.cards"
							:key="card.id"
							class="trash-panel__card-item">
							<div class="trash-panel__card-info">
								<span class="trash-panel__card-title">{{ card.title }}</span>
								<span v-if="card.deletedAt" class="trash-panel__card-deleted">
									{{ formatDeletedAt(card.deletedAt) }}
								</span>
							</div>
							<div class="trash-panel__card-actions">
								<!-- Restore: always available to editors -->
								<NcButton
									v-if="canEdit"
									:disabled="restore.isPending.value && restoringId === card.id"
									@click="handleRestore(card)">
									<template #icon>
										<RestoreIcon :size="16" />
									</template>
									{{ t('kanso', 'Restore') }}
								</NcButton>

								<!-- Permanently delete: gated on MANAGE permission -->
								<template v-if="canManage">
									<NcButton
										v-if="confirmPurgeId !== card.id"
										type="error"
										:disabled="purge.isPending.value && purgingId === card.id"
										@click="confirmPurgeId = card.id">
										<template #icon>
											<DeleteForeverIcon :size="16" />
										</template>
										{{ t('kanso', 'Delete permanently') }}
									</NcButton>
									<!-- Inline confirm row -->
									<template v-else>
										<span class="trash-panel__confirm-text">
											{{ t('kanso', 'Are you sure?') }}
										</span>
										<NcButton
											type="error"
											:disabled="purge.isPending.value"
											@click="handlePurge(card)">
											{{ t('kanso', 'Yes, delete') }}
										</NcButton>
										<NcButton @click="confirmPurgeId = null">
											{{ t('kanso', 'Cancel') }}
										</NcButton>
									</template>
								</template>
							</div>
						</li>
					</ul>
				</div>
			</template>

			<!-- Action error banner -->
			<span v-if="actionError" class="trash-panel__action-error">{{ actionError }}</span>
		</div>
	</NcModal>
</template>

<script setup>
import { ref, computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import RestoreIcon from 'vue-material-design-icons/Restore.vue'
import DeleteForeverIcon from 'vue-material-design-icons/DeleteForever.vue'
import { useTrash } from '../composables/useTrash.js'

const props = defineProps({
	/** Board id (string from route params). */
	boardId: {
		type: String,
		required: true,
	},
	/** Non-archived stacks from the board payload, in sorted order. */
	stacks: {
		type: Array,
		required: true,
	},
	/**
	 * Permissions bitmask from the board payload.
	 * READ=1, EDIT=2, SHARE=4, MANAGE=8
	 */
	permissions: {
		type: Number,
		default: 0,
	},
})

const emit = defineEmits(['close'])

// Permission gates — board owner always has MANAGE (8); editors have EDIT (2).
const canEdit = computed(() => (props.permissions & 2) !== 0 || (props.permissions & 8) !== 0)
const canManage = computed(() => (props.permissions & 8) !== 0)

// The panel is always mounted when visible, so enabled is always true here.
// useTrash accepts a Ref<boolean>; wrap the constant in a computed so the type
// contract is satisfied without importing ref() for a trivial constant.
const panelEnabled = computed(() => true)

const boardIdRef = computed(() => props.boardId)

const { trashQuery, restore, purge } = useTrash(boardIdRef, panelEnabled)

// Track which card is currently being actioned (for disabled state).
const restoringId = ref(null)
const purgingId = ref(null)
const confirmPurgeId = ref(null)
const actionError = ref('')

// Build a stack title lookup from props.stacks.
const stackTitleMap = computed(() => new Map(props.stacks.map((s) => [s.id, s.title])))

/**
 * Group trashed cards by stackId, ordered by their position in props.stacks
 * (known stacks first), then unknown stacks.
 */
const groupedCards = computed(() => {
	const cards = trashQuery.data.value
	if (!Array.isArray(cards) || cards.length === 0) return []

	const byStack = new Map()
	for (const card of cards) {
		if (!byStack.has(card.stackId)) {
			byStack.set(card.stackId, [])
		}
		byStack.get(card.stackId).push(card)
	}

	const result = []

	// Known stacks in sorted order
	for (const stack of props.stacks) {
		if (byStack.has(stack.id)) {
			result.push({
				stackId: stack.id,
				stackTitle: stack.title,
				cards: byStack.get(stack.id),
			})
		}
	}

	// Unknown stacks (edge case: stack was deleted after card was trashed)
	for (const [stackId, stackCards] of byStack) {
		if (!stackTitleMap.value.has(stackId)) {
			result.push({
				stackId,
				stackTitle: t('kanso', 'Unknown stack'),
				cards: stackCards,
			})
		}
	}

	return result
})

/**
 * Format the deletedAt timestamp (seconds since epoch or ISO string) into a
 * human-readable relative label using the Intl API.
 */
function formatDeletedAt(deletedAt) {
	try {
		const ms = typeof deletedAt === 'number'
			? deletedAt * 1000
			: new Date(deletedAt).getTime()
		if (!ms || isNaN(ms)) return ''
		const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })
		const diffSec = Math.round((ms - Date.now()) / 1000)
		const absSec = Math.abs(diffSec)
		if (absSec < 60) return rtf.format(diffSec, 'second')
		if (absSec < 3600) return rtf.format(Math.round(diffSec / 60), 'minute')
		if (absSec < 86400) return rtf.format(Math.round(diffSec / 3600), 'hour')
		return rtf.format(Math.round(diffSec / 86400), 'day')
	} catch {
		return ''
	}
}

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
</script>

<style scoped>
.trash-panel {
	padding: 20px 24px;
	min-height: 120px;
}

.trash-panel__loading,
.trash-panel__empty {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.trash-panel__error {
	font-size: 0.9rem;
	color: var(--color-error);
}

.trash-panel__group {
	margin-bottom: 20px;
}

.trash-panel__stack-name {
	font-size: 0.8rem;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	color: var(--color-text-maxcontrast);
	margin: 0 0 8px;
}

.trash-panel__card-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.trash-panel__card-item {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	flex-wrap: wrap;
}

.trash-panel__card-info {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1;
	min-width: 0;
}

.trash-panel__card-title {
	font-size: 0.875rem;
	color: var(--color-main-text);
	word-break: break-word;
}

.trash-panel__card-deleted {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.trash-panel__card-actions {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
	flex-shrink: 0;
}

.trash-panel__confirm-text {
	font-size: 0.8rem;
	color: var(--color-error);
	font-weight: 600;
}

.trash-panel__action-error {
	display: block;
	margin-top: 12px;
	color: var(--color-error);
	font-size: 0.8rem;
}
</style>
