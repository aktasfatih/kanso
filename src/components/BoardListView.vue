<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div ref="scrollRef" class="board-list-table">
		<p v-if="rows.length === 0" class="board-list-table__empty">
			{{ t('kanso', 'No cards to show.') }}
		</p>
		<div
			v-else
			class="board-list-table__host"
			:style="{ height: virtualizer.getTotalSize() + 'px' }">
			<div
				v-for="vRow in virtualizer.getVirtualItems()"
				:key="rows[vRow.index].id"
				:data-index="vRow.index"
				class="board-list-table__vrow"
				:style="{ position: 'absolute', top: 0, left: 0, width: '100%', height: `${vRow.size}px`, transform: `translateY(${vRow.start}px)` }">

				<!-- Stack group header -->
				<div v-if="rows[vRow.index].type === 'header'" class="board-list-group">
					<span
						class="board-list-group__dot"
						:style="rows[vRow.index].stack.color ? { background: cssColor(rows[vRow.index].stack.color) } : {}" />
					<span class="board-list-group__title">{{ rows[vRow.index].stack.title }}</span>
					<span class="board-list-group__count">{{ rows[vRow.index].count }}</span>
				</div>

				<!-- Card row -->
				<button
					v-else
					class="board-list-row"
					@click="openCard(rows[vRow.index].card.id)">
					<span
						class="board-list-row__status"
						:class="`board-list-row__status--${statusOf(rows[vRow.index].card)}`"
						:title="statusLabel(rows[vRow.index].card)" />
					<span
						class="board-list-row__title"
						:class="{ 'board-list-row__title--done': isDone(rows[vRow.index].card) }">
						{{ rows[vRow.index].card.title }}
					</span>

					<span class="board-list-row__meta">
						<!-- Labels (colour dots) -->
						<span
							v-for="labelId in (rows[vRow.index].card.labelIds || []).slice(0, 4)"
							:key="labelId"
							class="board-list-row__label-dot"
							:title="labelTitle(labelId)"
							:style="{ background: labelColor(labelId) }" />

						<!-- Priority -->
						<span
							v-if="rows[vRow.index].card.priority > 0"
							class="board-list-row__priority"
							:class="`board-list-row__priority--${rows[vRow.index].card.priority}`">
							{{ priorityLabel(rows[vRow.index].card.priority) }}
						</span>

						<!-- Due date -->
						<span
							v-if="rows[vRow.index].card.duedate"
							class="board-list-row__due"
							:class="{ 'board-list-row__due--overdue': isOverdue(rows[vRow.index].card) }">
							<CalendarIcon :size="14" />
							{{ formatDue(rows[vRow.index].card.duedate) }}
						</span>

						<!-- Checklist -->
						<span
							v-if="rows[vRow.index].card.checklist && rows[vRow.index].card.checklist.total > 0"
							class="board-list-row__count">
							<CheckboxMarkedOutlineIcon :size="14" />
							{{ rows[vRow.index].card.checklist.done }}/{{ rows[vRow.index].card.checklist.total }}
						</span>

						<!-- Comments -->
						<span v-if="rows[vRow.index].card.commentCount > 0" class="board-list-row__count">
							<CommentOutlineIcon :size="14" />
							{{ rows[vRow.index].card.commentCount }}
						</span>

						<!-- Review state -->
						<CheckDecagramIcon
							v-if="rows[vRow.index].card.reviewState === 'approved'"
							:size="15"
							class="board-list-row__review board-list-row__review--approved" />
						<AlertDecagramIcon
							v-else-if="rows[vRow.index].card.reviewState === 'changes_requested'"
							:size="15"
							class="board-list-row__review board-list-row__review--changes" />

						<!-- Assignees -->
						<span class="board-list-row__assignees">
							<NcAvatar
								v-for="uid in (rows[vRow.index].card.assigneeIds || []).slice(0, 3)"
								:key="uid"
								:user="uid"
								:size="24"
								:show-user-status="false" />
						</span>
					</span>
				</button>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import { useVirtualizer } from '@tanstack/vue-virtual'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import CheckboxMarkedOutlineIcon from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import CommentOutlineIcon from 'vue-material-design-icons/CommentOutline.vue'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import AlertDecagramIcon from 'vue-material-design-icons/AlertDecagram.vue'
import { cssColor } from '../services/color.js'
import { PRIORITY_LEVELS } from '../composables/usePriority.js'

const props = defineProps({
	/** Non-archived stacks in display order (already filtered by BoardView). */
	stacks: { type: Array, default: () => [] },
	/** Map<stackId, card[]> — already filter+sort applied by BoardView. */
	cardsByStack: { type: Object, required: true },
	/** Map<labelId, label>. */
	labelsById: { type: Object, required: true },
	boardId: { type: [String, Number], required: true },
})

const router = useRouter()
const scrollRef = ref(null)

// A flat row model: one header per stack, then its cards. This keeps the board's
// structure readable while feeding a single virtualized list.
const rows = computed(() => {
	const out = []
	for (const stack of props.stacks) {
		const cards = props.cardsByStack.get(stack.id) ?? []
		out.push({ type: 'header', id: `h${stack.id}`, stack, count: cards.length })
		for (const card of cards) {
			out.push({ type: 'card', id: `c${card.id}`, card })
		}
	}
	return out
})

// Fixed row heights (a table is uniform) — no per-row measureElement, so the
// virtualizer's positions never thrash on a data refresh.
const HEADER_H = 38
const ROW_H = 48
const virtualizer = useVirtualizer(computed(() => ({
	count: rows.value.length,
	getScrollElement: () => scrollRef.value,
	estimateSize: (i) => (rows.value[i]?.type === 'header' ? HEADER_H : ROW_H),
	overscan: 10,
	getItemKey: (i) => rows.value[i]?.id ?? i,
})))

function openCard(cardId) {
	router.push({ name: 'card-modal', params: { id: String(props.boardId), cardId: String(cardId) } })
}

function isDone(card) {
	return Number(card.doneAt) > 0
}

function statusOf(card) {
	if (Number(card.doneAt) > 0) return 'done'
	if (Number(card.startedAt) > 0) return 'in_progress'
	return 'not_started'
}

function statusLabel(card) {
	return { done: t('kanso', 'Done'), in_progress: t('kanso', 'In progress'), not_started: t('kanso', 'Not started') }[statusOf(card)]
}

function labelColor(labelId) {
	const l = props.labelsById.get(labelId)
	return l && l.color ? cssColor(l.color) : 'var(--color-border-dark)'
}

function labelTitle(labelId) {
	const l = props.labelsById.get(labelId)
	return l ? l.title : ''
}

function priorityLabel(value) {
	return PRIORITY_LEVELS.find((l) => l.value === value)?.label ?? ''
}

function formatDue(duedate) {
	const d = new Date(duedate)
	if (Number.isNaN(d.getTime())) return ''
	return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

function isOverdue(card) {
	if (!card.duedate || isDone(card)) return false
	const d = new Date(card.duedate)
	return !Number.isNaN(d.getTime()) && d.getTime() < Date.now()
}
</script>

<style scoped>
.board-list-table {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
	padding: 8px 24px 24px 52px;
}

.board-list-table__host {
	position: relative;
	width: 100%;
	max-width: 1100px;
}

.board-list-table__empty {
	color: var(--color-text-maxcontrast);
	padding: 24px 0;
}

.board-list-table__vrow {
	overflow: hidden;
}

.board-list-group {
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

.board-list-group__dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: var(--color-primary-element);
	flex: 0 0 auto;
}

.board-list-group__count {
	color: var(--color-text-maxcontrast);
	font-weight: 400;
}

.board-list-row {
	display: flex;
	align-items: center;
	gap: 12px;
	box-sizing: border-box;
	width: 100%;
	height: 100%;
	padding: 6px 8px;
	background: transparent;
	border: none;
	border-bottom: 1px solid var(--color-border);
	border-radius: 0;
	text-align: start;
	cursor: pointer;
	color: var(--color-main-text);
}

.board-list-row:hover {
	background: var(--color-background-hover);
}

.board-list-row__status {
	flex: 0 0 auto;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	border: 1px solid var(--color-border-dark);
}

.board-list-row__status--not_started { background: transparent; }
.board-list-row__status--in_progress { background: var(--color-primary-element); border-color: var(--color-primary-element); }
.board-list-row__status--done { background: var(--color-success, #2fb344); border-color: var(--color-success, #2fb344); }

.board-list-row__title {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.board-list-row__title--done {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

.board-list-row__meta {
	display: flex;
	align-items: center;
	gap: 10px;
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.board-list-row__label-dot {
	width: 12px;
	height: 12px;
	border-radius: 50%;
	flex: 0 0 auto;
}

.board-list-row__priority {
	font-size: 0.75rem;
	font-weight: 600;
	padding: 1px 6px;
	border-radius: 8px;
	background: var(--color-background-dark);
}

.board-list-row__priority--4 { color: var(--color-error); }
.board-list-row__priority--3 { color: var(--color-warning, #c98600); }

.board-list-row__due,
.board-list-row__count {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	white-space: nowrap;
}

.board-list-row__due--overdue {
	color: var(--color-error);
	font-weight: 600;
}

.board-list-row__review--approved { color: var(--color-success, #2fb344); }
.board-list-row__review--changes { color: var(--color-error); }

.board-list-row__assignees {
	display: inline-flex;
}

.board-list-row__assignees > * + * {
	margin-inline-start: -8px;
}
</style>
