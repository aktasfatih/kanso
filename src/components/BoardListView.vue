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

				<!-- Stack group bar: chevron, title, count/WIP, aggregate progress -->
				<button
					v-if="rows[vRow.index].type === 'header'"
					class="board-list-group"
					:aria-expanded="!isCollapsed(rows[vRow.index].key)"
					@click="toggleGroup(rows[vRow.index].key)">
					<ChevronDownIcon
						v-if="!isCollapsed(rows[vRow.index].key)"
						:size="18"
						class="board-list-group__chevron" />
					<ChevronRightIcon
						v-else
						:size="18"
						class="board-list-group__chevron" />
					<span
						class="board-list-group__dot"
						:style="rows[vRow.index].color ? { background: cssColor(rows[vRow.index].color) } : {}" />
					<span class="board-list-group__title">{{ rows[vRow.index].title }}</span>

					<!-- WIP indicator when a limit is set, plain count otherwise -->
					<span
						v-if="rows[vRow.index].wip"
						class="board-list-group__wip"
						:class="{ 'board-list-group__wip--over': rows[vRow.index].wip.over }">
						{{ rows[vRow.index].wip.count }} / {{ rows[vRow.index].wip.limit }}
					</span>
					<span v-else class="board-list-group__count">{{ rows[vRow.index].count }}</span>

					<!-- Per-group hints: overdue / blocked counts, only when nonzero -->
					<span
						v-if="rows[vRow.index].hints && (rows[vRow.index].hints.overdue || rows[vRow.index].hints.blocked)"
						class="board-list-group__hints">
						<span
							v-if="rows[vRow.index].hints.overdue"
							class="board-list-group__hint board-list-group__hint--overdue">
							{{ t('kanso', '{n} overdue', { n: rows[vRow.index].hints.overdue }) }}
						</span>
						<span
							v-if="rows[vRow.index].hints.blocked"
							class="board-list-group__hint">
							{{ t('kanso', '{n} blocked', { n: rows[vRow.index].hints.blocked }) }}
						</span>
					</span>

					<!-- Aggregate done/total progress across the group -->
					<span
						v-if="rows[vRow.index].progress"
						class="board-list-group__progress">
						{{ rows[vRow.index].progress.done }}/{{ rows[vRow.index].progress.total }}
					</span>
				</button>

				<!-- Card row (id first, meta pushed right) -->
				<button
					v-else
					class="board-list-row"
					@click="openCard(rows[vRow.index].card)">
					<span
						class="board-list-row__status"
						:class="`board-list-row__status--${statusOf(rows[vRow.index].card)}`"
						:title="statusLabel(rows[vRow.index].card)" />

					<!-- Human reference id (KAN-123) -->
					<span
						v-if="cardHumanId(rows[vRow.index].card)"
						class="board-list-row__id">
						{{ cardHumanId(rows[vRow.index].card) }}
					</span>

					<!-- Labels (colour dots) -->
					<span
						v-if="(rows[vRow.index].card.labelIds || []).length"
						class="board-list-row__labels">
						<span
							v-for="labelId in (rows[vRow.index].card.labelIds || []).slice(0, 4)"
							:key="labelId"
							class="board-list-row__label-dot"
							:title="labelTitle(labelId)"
							:style="{ background: labelColor(labelId) }" />
					</span>

					<span
						class="board-list-row__title"
						:class="{ 'board-list-row__title--done': isDone(rows[vRow.index].card) }">
						{{ rows[vRow.index].card.title }}
					</span>

					<span class="board-list-row__meta">
						<!-- Priority -->
						<span
							v-if="rows[vRow.index].card.priority > 0"
							class="board-list-row__priority"
							:class="`board-list-row__priority--${rows[vRow.index].card.priority}`">
							{{ priorityLabel(rows[vRow.index].card.priority) }}
						</span>

						<!-- Progress (checklist, else child cards) -->
						<span
							v-if="cardProgress(rows[vRow.index].card)"
							class="board-list-row__count">
							<CheckboxMarkedOutlineIcon :size="14" />
							{{ cardProgress(rows[vRow.index].card).done }}/{{ cardProgress(rows[vRow.index].card).total }}
						</span>

						<!-- Due date -->
						<span
							v-if="rows[vRow.index].card.duedate"
							class="board-list-row__due"
							:class="{ 'board-list-row__due--overdue': isOverdue(rows[vRow.index].card) }">
							<CalendarIcon :size="14" />
							{{ formatDue(rows[vRow.index].card) }}
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
						<span
							v-if="(rows[vRow.index].card.assigneeIds || []).length"
							class="board-list-row__assignees">
							<NcAvatar
								v-for="uid in (rows[vRow.index].card.assigneeIds || []).slice(0, 3)"
								:key="uid"
								:user="uid"
								:size="24"
								:hide-status="true" />
						</span>
					</span>
				</button>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, getCurrentInstance } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import { useVirtualizer } from '@tanstack/vue-virtual'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import CheckboxMarkedOutlineIcon from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import CommentOutlineIcon from 'vue-material-design-icons/CommentOutline.vue'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import AlertDecagramIcon from 'vue-material-design-icons/AlertDecagram.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import { cssColor } from '../services/color.js'
import { humanId } from '../services/humanId.js'
import { PRIORITY_LEVELS } from '../composables/usePriority.js'
import { formatCardDate } from '../utils/dateDisplay.js'

const props = defineProps({
	/** Non-archived stacks in display order (already filtered by BoardView). */
	stacks: { type: Array, default: () => [] },
	/** Map<stackId, card[]> - already filter+sort applied by BoardView. */
	cardsByStack: { type: Object, default: null },
	/**
	 * Generalized group-by mode (#3815): an ordered list of arbitrary groups
	 * `[{ key, title, cards }]` (e.g. cross-board Views grouped by status /
	 * priority / assignee / board). When provided it DRIVES the row model
	 * instead of the stack/cardsByStack path - so the same virtualized list,
	 * collapse and card row render for a board or a cross-board View. Backward
	 * compatible: absent → the classic per-stack rendering is unchanged.
	 */
	groups: { type: Array, default: null },
	/** Map<labelId, label>. */
	labelsById: { type: Object, required: true },
	/** Board human-id prefix (e.g. "KAN") - composed with card.boardSeq. */
	boardPrefix: { type: String, default: '' },
	/**
	 * The deep-link board id for a card. For a single-board list every card
	 * shares this; for a cross-board View pass null and each card's own boardId
	 * is used (see openCard).
	 */
	boardId: { type: [String, Number], default: null },
})

const emit = defineEmits(['open'])

// When a parent handles `@open` (a cross-board View owns a card-detail overlay,
// #3950), emit the card up and let the parent open it in place — do NOT route to
// card-modal (a child of the board route, which would swap the whole View out for
// the board). Inside an actual board no `@open` is attached, so the classic
// router.push deep-link behaviour is preserved unchanged.
// A parent's `@open` presence is fixed at mount (it never toggles at runtime), so
// this is a one-off static read, not a reactive dependency.
const hasOpenHandler = !!getCurrentInstance()?.vnode?.props?.onOpen

const router = useRouter()
const scrollRef = ref(null)

// Persisted collapse scope: per board for the classic path, or a fixed 'views'
// scope for the generalized group-by path (#3815) where there is no board id.
const collapseScope = computed(() => (props.groups ? 'views' : props.boardId))
const collapsedKey = computed(() => `kanso.listCollapsed.${collapseScope.value}`)
const collapsed = ref(loadCollapsed())

function loadCollapsed() {
	try {
		const saved = localStorage.getItem(`kanso.listCollapsed.${props.groups ? 'views' : props.boardId}`)
		if (saved) return new Set(JSON.parse(saved))
	} catch (e) { /* localStorage unavailable - default to all expanded */ }
	return new Set()
}

// Reload persisted state when the scope changes (component reused across boards/views).
watch(collapseScope, () => { collapsed.value = loadCollapsed() })

function isCollapsed(groupKey) {
	return collapsed.value.has(groupKey)
}

function toggleGroup(groupKey) {
	const next = new Set(collapsed.value)
	if (next.has(groupKey)) next.delete(groupKey)
	else next.add(groupKey)
	collapsed.value = next
	try {
		localStorage.setItem(collapsedKey.value, JSON.stringify([...next]))
	} catch (e) { /* localStorage unavailable - collapse is in-memory only */ }
}

// Aggregate done/total across a group's cards. A card counts toward "done" when
// it's marked done; the total is the whole group. Returns null for empty groups.
function groupProgress(cards) {
	if (!cards.length) return null
	const done = cards.reduce((n, c) => n + (Number(c.doneAt) > 0 ? 1 : 0), 0)
	return { done, total: cards.length }
}

// Reactive clock so overdue counts recompute over time (e.g. a board left open
// across midnight) rather than freezing until the card data changes.
const now = ref(Date.now())
let clockTimer = null
onMounted(() => { clockTimer = setInterval(() => { now.value = Date.now() }, 60_000) })
onBeforeUnmount(() => { if (clockTimer !== null) { clearInterval(clockTimer); clockTimer = null } })

// Secondary group hints: overdue (not done, past-due) and blocked counts. Mirrors
// the row-level isOverdue / card.blocked so the header echoes what's in the list.
function groupHints(cards) {
	let overdue = 0
	let blocked = 0
	for (const c of cards) {
		if (isOverdue(c)) overdue++
		if (c.blocked === true) blocked++
	}
	return { overdue, blocked }
}

// Normalize the two grouping sources into one shape: { key, title, color,
// wipLimit, cards }. Classic path = one group per stack (carrying its color +
// WIP limit); generalized path (#3815) = the caller-supplied groups (no
// stack/WIP concept). Everything below renders off this uniform list.
const normalizedGroups = computed(() => {
	if (props.groups) {
		return props.groups.map((g) => ({ key: g.key, title: g.title, color: null, wipLimit: null, cards: g.cards ?? [] }))
	}
	const byStack = props.cardsByStack
	return props.stacks.map((stack) => ({
		key: `stack:${stack.id}`,
		title: stack.title,
		color: stack.color ?? null,
		wipLimit: (typeof stack.wipLimit === 'number' && stack.wipLimit > 0) ? stack.wipLimit : null,
		cards: (byStack && byStack.get(stack.id)) ?? [],
	}))
})

// A flat row model: one header per group, then its cards (unless collapsed).
// Collapsed groups drop their card rows entirely so virtualization stays exact.
const rows = computed(() => {
	const out = []
	for (const group of normalizedGroups.value) {
		const cards = group.cards
		out.push({
			type: 'header',
			id: `h${group.key}`,
			key: group.key,
			title: group.title,
			color: group.color,
			count: cards.length,
			wip: group.wipLimit !== null ? { count: cards.length, limit: group.wipLimit, over: cards.length > group.wipLimit } : null,
			progress: groupProgress(cards),
			hints: groupHints(cards),
		})
		if (isCollapsed(group.key)) continue
		for (const card of cards) {
			out.push({ type: 'card', id: `c${card.id}`, card })
		}
	}
	return out
})

// Fixed row heights (a table is uniform) - no per-row measureElement, so the
// virtualizer's positions never thrash on a data refresh.
const HEADER_H = 40
const ROW_H = 36
const virtualizer = useVirtualizer(computed(() => ({
	count: rows.value.length,
	getScrollElement: () => scrollRef.value,
	estimateSize: (i) => (rows.value[i]?.type === 'header' ? HEADER_H : ROW_H),
	overscan: 10,
	getItemKey: (i) => rows.value[i]?.id ?? i,
})))

function openCard(card) {
	// A parent-owned overlay (cross-board View, #3950) takes precedence: hand the
	// card up and stay on the current surface instead of navigating.
	if (hasOpenHandler) {
		emit('open', card)
		return
	}
	// Prefer the card's own boardId (cross-board Views, #3815); fall back to the
	// single-board prop for the classic per-board list.
	const boardId = card.boardId ?? props.boardId
	router.push({ name: 'card-modal', params: { id: String(boardId), cardId: String(card.id) } })
}

function cardHumanId(card) {
	return humanId(props.boardPrefix, card.boardSeq)
}

// Per-card progress: prefer the checklist, fall back to child-card progress.
function cardProgress(card) {
	if (card.checklist && card.checklist.total > 0) return card.checklist
	if (card.childProgress && card.childProgress.total > 0) return card.childProgress
	return null
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

function formatDue(card) {
	const d = new Date(card.duedate)
	if (Number.isNaN(d.getTime())) return ''
	// All-day dates are stored at UTC midnight; format them in UTC so the row
	// shows the picked calendar day even west of UTC (not the previous day).
	return formatCardDate(card.duedate, card.allDay === true, { month: 'short', day: 'numeric' })
}

function isOverdue(card) {
	if (!card.duedate || isDone(card)) return false
	const d = new Date(card.duedate)
	return !Number.isNaN(d.getTime()) && d.getTime() < now.value
}
</script>

<style scoped>
.board-list-table {
	/* Legible status colours for the row chips (priority, overdue, review). NC's
	 * base --color-error / --color-warning / --color-success dark shades are near
	 * black (#552121 / #3D3010 / #11321A) and vanish as text on the dark list
	 * surface; brighten them under dark themes while keeping the stock values in
	 * light mode (#3905/#4054 pattern). Scoped so they can't leak. */
	--kanso-error-legible: var(--color-error, #e30000);
	--kanso-warning-legible: var(--color-warning, #c98600);
	--kanso-success-legible: var(--color-success, #2fb344);


	flex: 1;
	min-height: 0;
	overflow-y: auto;
	padding: 8px 24px 24px 52px;
}

/* Explicit dark themes (theme picker) + auto (prefers-color-scheme) dark. */
body.theme--dark .board-list-table,
[data-theme-dark] .board-list-table,
[data-themes*='dark'] .board-list-table {
	--kanso-error-legible: #ff6b6b;
	--kanso-warning-legible: #d29922;
	--kanso-success-legible: #5ad07f;
}

@media (prefers-color-scheme: dark) {
	body.theme--default .board-list-table,
	body:not(.theme--light):not(.theme--dark) .board-list-table {
		--kanso-error-legible: #ff6b6b;
		--kanso-warning-legible: #d29922;
		--kanso-success-legible: #5ad07f;
	}
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
	gap: 10px;
	box-sizing: border-box;
	width: 100%;
	height: 100%;
	padding: 0 8px;
	background: var(--color-background-hover);
	border: none;
	border-bottom: 1px solid var(--color-border);
	border-radius: 0;
	text-align: start;
	cursor: pointer;
	color: var(--color-main-text);
}

.board-list-group:hover {
	background: var(--color-background-dark);
}

.board-list-group__chevron {
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
}

.board-list-group__dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background: var(--color-primary-element);
	flex: 0 0 auto;
}

.board-list-group__title {
	font-weight: 700;
	text-transform: uppercase;
	font-size: 0.8rem;
	letter-spacing: 0.03em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.board-list-group__count {
	color: var(--color-text-maxcontrast);
	font-weight: 400;
	font-size: 0.8rem;
	flex: 0 0 auto;
}

.board-list-group__wip {
	display: inline-flex;
	align-items: center;
	height: 20px;
	padding: 0 7px;
	border-radius: 10px;
	font-size: 0.72rem;
	font-weight: 700;
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
}

.board-list-group__wip--over {
	color: color-mix(in srgb, var(--kanso-warning-legible) 85%, var(--color-main-text));
	background: color-mix(in srgb, var(--kanso-warning-legible) 25%, transparent);
	outline: 1px solid color-mix(in srgb, var(--kanso-warning-legible) 50%, transparent);
}

/* Secondary hints (overdue · blocked) sit just after the count, muted; the
   progress badge still anchors to the far end. */
.board-list-group__hints {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	flex: 0 0 auto;
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.board-list-group__hint {
	white-space: nowrap;
}

.board-list-group__hint + .board-list-group__hint::before {
	content: '·';
	margin-inline-end: 6px;
	color: var(--color-text-maxcontrast);
}

.board-list-group__hint--overdue {
	color: color-mix(in srgb, var(--kanso-error-legible) 80%, var(--color-text-maxcontrast));
	font-weight: 600;
}

.board-list-group__progress {
	margin-inline-start: auto;
	font-size: 0.75rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	flex: 0 0 auto;
}

.board-list-row {
	display: flex;
	align-items: center;
	gap: 10px;
	box-sizing: border-box;
	width: 100%;
	height: 100%;
	padding: 0 8px;
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
.board-list-row__status--done { background: var(--kanso-success-legible); border-color: var(--kanso-success-legible); }

.board-list-row__id {
	flex: 0 0 auto;
	width: 70px;
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.72rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.board-list-row__labels {
	display: flex;
	gap: 3px;
	flex: 0 0 auto;
}

.board-list-row__title {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 0.875rem;
}

.board-list-row__title--done {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

.board-list-row__meta {
	display: flex;
	align-items: center;
	gap: 14px;
	flex: 0 0 auto;
	margin-inline-start: auto;
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
}

.board-list-row__label-dot {
	width: 8px;
	height: 8px;
	border-radius: 2px;
	flex: 0 0 auto;
}

.board-list-row__priority {
	font-size: 0.75rem;
	font-weight: 600;
	padding: 1px 6px;
	border-radius: 8px;
	background: var(--color-background-dark);
}

.board-list-row__priority--4 { color: var(--kanso-error-legible); }
.board-list-row__priority--3 { color: var(--kanso-warning-legible); }

.board-list-row__due,
.board-list-row__count {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	white-space: nowrap;
}

.board-list-row__due--overdue {
	color: var(--kanso-error-legible);
	font-weight: 600;
}

.board-list-row__review--approved { color: var(--kanso-success-legible); }
.board-list-row__review--changes { color: var(--kanso-error-legible); }

.board-list-row__assignees {
	display: inline-flex;
}

.board-list-row__assignees > * + * {
	margin-inline-start: -8px;
}
</style>
