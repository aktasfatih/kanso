<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="card-tile-wrap" :class="{ 'card-tile-wrap--dragging': isDragging }">
		<!-- Top drop indicator -->
		<div v-if="closestEdge === 'top'" class="card-tile__drop-line card-tile__drop-line--top" />

		<button
			ref="el"
			class="card-tile"
			:class="{ 'card-tile--done': isDone }"
			:data-card-id="card.id"
			@click="$emit('click')">
			<!-- Label chips row — only rendered when the card has assigned labels -->
			<div v-if="cardLabels.length" class="card-tile__labels" aria-label="Labels">
				<span
					v-for="label in cardLabels"
					:key="label.id"
					class="card-tile__label-chip"
					:class="{ 'card-tile__label-chip--no-color': !label.color }"
					:style="label.color ? { background: cssColor(label.color), color: readableColor(label.color) } : {}"
					:title="label.title">
					{{ label.title }}
				</span>
			</div>
			<span class="card-tile__title" :class="{ 'card-tile__title--done': isDone }">{{ card.title }}</span>
			<!-- Due date chip — suppress overdue/soon when done -->
			<span
				v-if="card.duedate"
				class="card-tile__due"
				:class="dueDateClass">
				<CalendarIcon :size="14" />
				{{ formatDue(card.duedate) }}
			</span>
			<!-- Checklist progress badge — only when the card has checklist items -->
			<span
				v-if="card.checklist && card.checklist.total > 0"
				class="card-tile__checklist"
				:class="{ 'card-tile__checklist--complete': card.checklist.done === card.checklist.total }"
				:aria-label="t('kanso', 'Checklist progress')">
				<CheckboxMarkedOutlineIcon :size="12" />
				{{ card.checklist.done }}/{{ card.checklist.total }}
			</span>
			<!-- Child-progress badge — only when the card has children -->
			<span
				v-if="card.childProgress && card.childProgress.total > 0"
				class="card-tile__children"
				:class="{ 'card-tile__children--complete': card.childProgress.done === card.childProgress.total }"
				:aria-label="t('kanso', 'Sub-card progress')">
				<SitemapIcon :size="12" />
				{{ card.childProgress.done }}/{{ card.childProgress.total }}
			</span>
			<!-- Comment count badge — only when there are comments -->
			<span
				v-if="card.commentCount > 0"
				class="card-tile__comments"
				:aria-label="t('kanso', 'Comments')">
				<CommentMultipleOutlineIcon :size="12" />
				{{ card.commentCount }}
			</span>
			<!-- Priority indicator — only when priority > 0 -->
			<span
				v-if="card.priority > 0"
				class="card-tile__priority"
				:class="`card-tile__priority--${card.priority}`"
				:aria-label="t('kanso', 'Priority: {level}', { level: priorityLabel })">
				<AlertIcon v-if="card.priority === 4" :size="12" />
				<ArrowUpBoldIcon v-else-if="card.priority === 3" :size="12" />
				<SignalCellular2Icon v-else-if="card.priority === 2" :size="12" />
				<SignalCellular1Icon v-else :size="12" />
				{{ priorityLabel }}
			</span>

			<!-- Assignee avatar stack — only when there are assignees -->
			<div v-if="card.assigneeIds && card.assigneeIds.length" class="card-tile__assignees" :aria-label="t('kanso', 'Assignees')">
				<NcAvatar
					v-for="uid in visibleAssigneeIds"
					:key="uid"
					:user="uid"
					:size="24"
					:show-user-status="false"
					:disable-tooltip="false"
					class="card-tile__avatar" />
				<span v-if="extraAssigneeCount > 0" class="card-tile__avatar-overflow">
					+{{ extraAssigneeCount }}
				</span>
			</div>
		</button>

		<!-- Bottom drop indicator -->
		<div v-if="closestEdge === 'bottom'" class="card-tile__drop-line card-tile__drop-line--bottom" />
	</div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import CheckboxMarkedOutlineIcon from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import CommentMultipleOutlineIcon from 'vue-material-design-icons/CommentMultipleOutline.vue'
import SitemapIcon from 'vue-material-design-icons/Sitemap.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import ArrowUpBoldIcon from 'vue-material-design-icons/ArrowUpBold.vue'
import SignalCellular2Icon from 'vue-material-design-icons/SignalCellular2.vue'
import SignalCellular1Icon from 'vue-material-design-icons/SignalCellular1.vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import { translate as t } from '@nextcloud/l10n'
import { PRIORITY_LEVELS } from '../composables/usePriority.js'

/**
 * Given a hex background color return '#000' or '#fff' for readable contrast.
 * Uses the W3C relative luminance formula (sRGB).
 */
function readableColor(hex) {
	if (!hex) return '#000'
	const raw = hex.replace('#', '')
	const [r, g, b] = raw.length === 3
		? [parseInt(raw[0] + raw[0], 16), parseInt(raw[1] + raw[1], 16), parseInt(raw[2] + raw[2], 16)]
		: [parseInt(raw.slice(0, 2), 16), parseInt(raw.slice(2, 4), 16), parseInt(raw.slice(4, 6), 16)]
	const toLinear = (c) => { const s = c / 255; return s <= 0.04045 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4) }
	const L = 0.2126 * toLinear(r) + 0.7152 * toLinear(g) + 0.0722 * toLinear(b)
	return L > 0.179 ? '#000000' : '#ffffff'
}
import { draggable, dropTargetForElements } from '@atlaskit/pragmatic-drag-and-drop/element/adapter'
import { combine } from '@atlaskit/pragmatic-drag-and-drop/combine'
import { cssColor } from '../services/color.js'
import { attachClosestEdge, extractClosestEdge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge'

const props = defineProps({
	card: {
		type: Object,
		required: true,
	},
	labelsById: {
		type: Map,
		default: () => new Map(),
	},
})

defineEmits(['click'])

const el = ref(null)
const isDragging = ref(false)
const closestEdge = ref(null)
let cleanup = () => {}

onMounted(() => {
	if (!el.value) return
	cleanup = combine(
		draggable({
			element: el.value,
			getInitialData: () => ({
				type: 'card',
				cardId: props.card.id,
				stackId: props.card.stackId,
				sortKey: props.card.sortKey,
			}),
			onDragStart: () => { isDragging.value = true },
			onDrop: () => { isDragging.value = false },
		}),
		dropTargetForElements({
			element: el.value,
			canDrop: ({ source }) => source.data.type === 'card' && source.data.cardId !== props.card.id,
			getData: ({ input, element: el2 }) => attachClosestEdge(
				{ type: 'card', cardId: props.card.id, stackId: props.card.stackId, sortKey: props.card.sortKey },
				{ input, element: el2, allowedEdges: ['top', 'bottom'] },
			),
			onDrag: ({ self }) => {
				closestEdge.value = extractClosestEdge(self.data)
			},
			onDragLeave: () => { closestEdge.value = null },
			onDrop: () => { closestEdge.value = null },
		}),
	)
})

onUnmounted(() => {
	cleanup()
})

// Resolve label objects from the labelsById map for the card's assigned labelIds
const cardLabels = computed(() => {
	const ids = Array.isArray(props.card.labelIds) ? props.card.labelIds : []
	return ids.map((id) => props.labelsById.get(id)).filter(Boolean)
})

// Done: doneAt > 0
const isDone = computed(() => Number(props.card.doneAt) > 0)

const dueDateClass = computed(() => {
	if (!props.card.duedate) return ''
	// When done, suppress overdue/soon coloring — show chip neutrally
	if (isDone.value) return ''
	const due = new Date(props.card.duedate)
	const now = new Date()
	if (due < now) return 'card-tile__due--overdue'
	const diff = due - now
	const hoursLeft = diff / (1000 * 60 * 60)
	if (hoursLeft <= 24) return 'card-tile__due--soon'
	return ''
})

function formatDue(iso) {
	const d = new Date(iso)
	return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

// Priority label for the indicator badge
const priorityLabel = computed(() => {
	const level = PRIORITY_LEVELS.find((l) => l.value === Number(props.card.priority ?? 0))
	return level?.shortLabel ?? ''
})

// Assignee avatar stack: max 3 visible + overflow count
const MAX_VISIBLE_ASSIGNEES = 3

const visibleAssigneeIds = computed(() => {
	const ids = Array.isArray(props.card.assigneeIds) ? props.card.assigneeIds : []
	return ids.slice(0, MAX_VISIBLE_ASSIGNEES)
})

const extraAssigneeCount = computed(() => {
	const ids = Array.isArray(props.card.assigneeIds) ? props.card.assigneeIds : []
	return Math.max(0, ids.length - MAX_VISIBLE_ASSIGNEES)
})
</script>

<style scoped>
.card-tile-wrap {
	position: relative;
}

.card-tile-wrap--dragging .card-tile {
	opacity: 0.4;
}

.card-tile__drop-line {
	position: absolute;
	left: 0;
	right: 0;
	height: 2px;
	background: var(--color-primary-element);
	border-radius: 1px;
	z-index: 10;
	pointer-events: none;
}

.card-tile__drop-line--top {
	top: -1px;
}

.card-tile__drop-line--bottom {
	bottom: -1px;
}

.card-tile {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 6px;
	width: 100%;
	padding: 10px 12px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	text-align: left;
	transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.card-tile:hover {
	border-color: var(--color-primary);
	box-shadow: 0 1px 4px rgba(0, 0, 0, 0.06);
}

.card-tile:focus-visible {
	outline: 2px solid var(--color-primary);
	outline-offset: 1px;
}

.card-tile__title {
	color: var(--color-main-text);
	font-size: 0.875rem;
	line-height: 1.4;
	word-break: break-word;
}

.card-tile__due {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	border: 1px solid var(--color-border);
	border-radius: 10px;
	padding: 1px 7px;
}

.card-tile__due--overdue {
	color: var(--color-error);
	border-color: var(--color-error);
	background: rgba(var(--color-error-rgb, 227, 0, 0), 0.08);
}

.card-tile__due--soon {
	color: var(--color-warning, #f0a844);
	border-color: var(--color-warning, #f0a844);
	background: rgba(240, 168, 68, 0.08);
}

/* Label chips */
.card-tile__labels {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-bottom: 2px;
}

.card-tile__label-chip {
	display: inline-block;
	max-width: 120px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 0.7rem;
	font-weight: 600;
	line-height: 1;
	padding: 3px 7px;
	border-radius: 10px;
	letter-spacing: 0.02em;
	user-select: none;
}

.card-tile__label-chip--no-color {
	background: var(--color-background-dark);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
}

/* Done state */
.card-tile--done {
	opacity: 0.6;
}

.card-tile__title--done {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

/* Checklist progress badge */
.card-tile__checklist {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	border: 1px solid var(--color-border);
	border-radius: 10px;
	padding: 1px 7px;
}

.card-tile__checklist--complete {
	color: var(--color-success, #46ba61);
	border-color: var(--color-success, #46ba61);
	background: rgba(70, 186, 97, 0.1);
}

/* Child-progress badge */
.card-tile__children {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	border: 1px solid var(--color-border);
	border-radius: 10px;
	padding: 1px 7px;
}

.card-tile__children--complete {
	color: var(--color-success, #46ba61);
	border-color: var(--color-success, #46ba61);
	background: rgba(70, 186, 97, 0.1);
}

/* Comment count badge */
.card-tile__comments {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	border: 1px solid var(--color-border);
	border-radius: 10px;
	padding: 1px 7px;
}

/* Priority indicator badge */
.card-tile__priority {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	font-size: 0.7rem;
	font-weight: 600;
	border-radius: 10px;
	padding: 1px 7px;
	border: 1px solid currentColor;
}

/* Low: grey */
.card-tile__priority--1 {
	color: #888;
	border-color: #888;
	background: rgba(136, 136, 136, 0.1);
}

/* Medium: blue */
.card-tile__priority--2 {
	color: var(--color-primary-element, #0082c9);
	border-color: var(--color-primary-element, #0082c9);
	background: rgba(0, 130, 201, 0.1);
}

/* High: orange */
.card-tile__priority--3 {
	color: #e07b00;
	border-color: #e07b00;
	background: rgba(224, 123, 0, 0.1);
}

/* Urgent: red */
.card-tile__priority--4 {
	color: var(--color-error, #e30000);
	border-color: var(--color-error, #e30000);
	background: rgba(var(--color-error-rgb, 227, 0, 0), 0.1);
}

/* Assignee avatar stack */
.card-tile__assignees {
	display: flex;
	align-items: center;
	margin-top: 2px;
}

.card-tile__avatar {
	margin-left: -6px;
	flex-shrink: 0;
	aspect-ratio: 1;
	border: 2px solid var(--color-main-background);
	border-radius: 50%;
}

.card-tile__assignees .card-tile__avatar:first-child {
	margin-left: 0;
}

.card-tile__avatar-overflow {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	flex-shrink: 0;
	aspect-ratio: 1;
	border-radius: 50%;
	background: var(--color-background-dark);
	border: 2px solid var(--color-main-background);
	color: var(--color-text-maxcontrast);
	font-size: 0.65rem;
	font-weight: 700;
	margin-left: -6px;
}
</style>
