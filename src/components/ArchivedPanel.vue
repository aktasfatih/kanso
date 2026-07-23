<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal
		:show="true"
		:name="t('kanso', 'Archived cards')"
		size="normal"
		@close="$emit('close')">
		<div class="archived-panel">
			<!-- Empty state -->
			<template v-if="groupedCards.length === 0">
				<div class="archived-panel__empty">
					{{ t('kanso', 'No archived cards.') }}
				</div>
			</template>

			<!-- Cards grouped by stack -->
			<template v-else>
				<div
					v-for="group in groupedCards"
					:key="group.stack.id"
					class="archived-panel__group">
					<h3 class="archived-panel__stack-name">{{ group.stack.title }}</h3>
					<ul class="archived-panel__card-list">
						<li
							v-for="card in group.cards"
							:key="card.id"
							class="archived-panel__card-item">
							<span class="archived-panel__card-title">{{ card.title }}</span>
							<NcButton
								:disabled="unarchivingId === card.id"
								@click="handleUnarchive(card)">
								<template #icon>
									<ArchiveArrowUpIcon :size="16" />
								</template>
								{{ t('kanso', 'Unarchive') }}
							</NcButton>
						</li>
					</ul>
				</div>
			</template>

			<!-- Unarchive error -->
			<span v-if="unarchiveError" class="archived-panel__error">{{ unarchiveError }}</span>
		</div>
	</NcModal>
</template>

<script setup>
import { ref, computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import ArchiveArrowUpIcon from 'vue-material-design-icons/ArchiveArrowUp.vue'
import { useCardActions } from '../composables/useCardActions.js'
import { useQueryClient } from '@tanstack/vue-query'
import { boardQueryKey } from '../composables/queryKeys.js'
import { updateCard as apiUpdateCard } from '../services/api.js'

const props = defineProps({
	boardId: {
		type: String,
		required: true,
	},
	/** All stacks on the board (non-archived stacks, ordered). */
	stacks: {
		type: Array,
		required: true,
	},
	/** All archived + non-deleted cards from the board payload. */
	archivedCards: {
		type: Array,
		required: true,
	},
})

const emit = defineEmits(['close'])

const queryClient = useQueryClient()
const unarchivingId = ref(null)
const unarchiveError = ref('')

/**
 * Group archived cards by their stack, maintaining the stack sort order.
 * Cards whose stackId doesn't match a known stack still appear under an
 * "Unknown" fallback so nothing is silently swallowed.
 */
const stackMap = computed(() => new Map(props.stacks.map((s) => [s.id, s])))

const groupedCards = computed(() => {
	const groups = new Map()

	for (const card of props.archivedCards) {
		if (!groups.has(card.stackId)) {
			groups.set(card.stackId, [])
		}
		groups.get(card.stackId).push(card)
	}

	const result = []
	// First add groups for stacks we know, in sorted order
	for (const stack of props.stacks) {
		if (groups.has(stack.id)) {
			result.push({ stack, cards: groups.get(stack.id) })
		}
	}
	// Then any cards in stacks not present in props.stacks (edge case)
	for (const [stackId, cards] of groups) {
		if (!stackMap.value.has(stackId)) {
			result.push({
				stack: { id: stackId, title: t('kanso', 'Unknown stack') },
				cards,
			})
		}
	}
	return result
})

async function handleUnarchive(card) {
	unarchiveError.value = ''
	unarchivingId.value = card.id

	const boardKey = boardQueryKey(props.boardId)
	const cardKey = ['card', String(card.id)]

	try {
		// Optimistic: flip archived flag in board cache
		await queryClient.cancelQueries({ queryKey: boardKey })
		await queryClient.cancelQueries({ queryKey: cardKey })

		queryClient.setQueryData(boardKey, (old) => {
			if (!old) return old
			return {
				...old,
				cards: old.cards.map((c) =>
					c.id === card.id ? { ...c, archived: false } : c,
				),
			}
		})

		queryClient.setQueryData(cardKey, (old) => {
			if (!old) return old
			return { ...old, archived: false }
		})

		await apiUpdateCard(card.id, { archived: false })
	} catch (err) {
		unarchiveError.value = err?.response?.data?.error || t('kanso', 'Failed to unarchive card.')
		// Revalidate to restore correct state
		queryClient.invalidateQueries({ queryKey: boardKey })
	} finally {
		unarchivingId.value = null
		queryClient.invalidateQueries({ queryKey: boardKey })
		queryClient.invalidateQueries({ queryKey: cardKey })
	}
}
</script>

<style scoped>
.archived-panel {
	padding: 20px 24px;
	min-height: 120px;
}

.archived-panel__empty {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.archived-panel__group {
	margin-bottom: 20px;
}

.archived-panel__stack-name {
	font-size: 0.8rem;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	color: var(--color-text-maxcontrast);
	margin: 0 0 8px;
}

.archived-panel__card-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.archived-panel__card-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.archived-panel__card-title {
	flex: 1;
	font-size: 0.875rem;
	color: var(--color-main-text);
	word-break: break-word;
	min-width: 0;
}

.archived-panel__error {
	display: block;
	margin-top: 12px;
	color: var(--color-error);
	font-size: 0.8rem;
}
</style>
