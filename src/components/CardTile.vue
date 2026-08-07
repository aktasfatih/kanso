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
			:class="{ 'card-tile--done': isDone, 'card-tile--selected': selected }"
			:data-card-id="card.id"
			@click="onTileClick"
			@mouseenter="$emit('hover', card.id)"
			@mouseleave="$emit('hover', null)">
			<!-- Selection checkbox - shown in multi-select mode -->
			<input
				v-if="selectionMode"
				type="checkbox"
				class="card-tile__select"
				:checked="selected"
				tabindex="-1"
				@click.stop />
			<!-- Cover band - a solid colour strip at the top of the tile (#3549) -->
			<span
				v-if="card.coverColor"
				class="card-tile__cover"
				:style="{ background: cssColor(card.coverColor) }"
				aria-hidden="true" />
			<!-- Label chips row - only rendered when the card has assigned labels -->
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
			<!-- Human-readable reference id (e.g. KAN-123) - a stable display id -->
			<span v-if="cardHumanId" class="card-tile__ref">{{ cardHumanId }}</span>
			<!-- Card title - max 2 lines with ellipsis overflow -->
			<span class="card-tile__title" :class="{ 'card-tile__title--done': isDone }">{{ card.title }}</span>
			<!-- Single meta row: all badges inline, assignees pushed to the right -->
			<div
				v-if="isInProgress || card.blocked || card.duedate || (card.checklist && card.checklist.total > 0) || (card.childProgress && card.childProgress.total > 0) || card.commentCount > 0 || card.priority > 0 || cardType || (card.assigneeIds && card.assigneeIds.length) || card.reviewState || card.estimate"
				class="card-tile__meta">
				<!-- In-progress status chip -->
				<span
					v-if="isInProgress"
					class="card-tile__inprogress"
					:aria-label="t('kanso', 'In progress')">
					<ProgressClockIcon :size="12" />
					{{ t('kanso', 'In progress') }}
				</span>
				<!-- Blocked badge - shown when the card has an unresolved blocker -->
				<span
					v-if="card.blocked"
					class="card-tile__blocked"
					:aria-label="t('kanso', 'Blocked by another card')">
					<CancelIcon :size="12" />
					{{ t('kanso', 'Blocked') }}
				</span>
				<!-- Type icon (#3402) - icon-first built-in issue type -->
				<span
					v-if="cardType"
					class="card-tile__type"
					:class="`card-tile__type--${cardType.value}`"
					:aria-label="t('kanso', 'Type: {type}', { type: t('kanso', cardType.label) })"
					:title="t('kanso', cardType.label)">
					<BugIcon v-if="cardType.value === 'bug'" :size="14" />
					<StarIcon v-else-if="cardType.value === 'feature'" :size="14" />
					<CheckboxMarkedCircleOutlineIcon v-else-if="cardType.value === 'task'" :size="14" />
					<BroomIcon v-else :size="14" />
				</span>
				<!-- Priority indicator - only when priority > 0 -->
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
				<!-- Estimate chip - shown only when card.estimate is truthy -->
				<span
					v-if="card.estimate"
					class="card-tile__estimate"
					:aria-label="t('kanso', 'Estimate: {value}', { value: card.estimate })">
					{{ card.estimate }}
				</span>
				<!-- Due date chip - suppress overdue/soon when done -->
				<span
					v-if="card.duedate"
					class="card-tile__due"
					:class="dueDateClass">
					<CalendarIcon :size="14" />
					{{ formatDue(card.duedate) }}
				</span>
				<!-- Checklist progress badge - only when the card has checklist items -->
				<span
					v-if="card.checklist && card.checklist.total > 0"
					class="card-tile__checklist"
					:class="{ 'card-tile__checklist--complete': card.checklist.done === card.checklist.total }"
					:aria-label="t('kanso', 'Checklist progress')">
					<CheckboxMarkedOutlineIcon :size="12" />
					{{ card.checklist.done }}/{{ card.checklist.total }}
				</span>
				<!-- Child-progress badge - only when the card has children -->
				<span
					v-if="card.childProgress && card.childProgress.total > 0"
					class="card-tile__children"
					:class="{ 'card-tile__children--complete': card.childProgress.done === card.childProgress.total }"
					:aria-label="t('kanso', 'Sub-card progress')">
					<SitemapIcon :size="12" />
					{{ card.childProgress.done }}/{{ card.childProgress.total }}
				</span>
				<!-- Comment count badge - only when there are comments -->
				<span
					v-if="card.commentCount > 0"
					class="card-tile__comments"
					:aria-label="t('kanso', 'Comments')">
					<CommentMultipleOutlineIcon :size="12" />
					{{ card.commentCount }}
				</span>
				<!-- Review state chip - only when card.reviewState is non-null -->
				<span
					v-if="card.reviewState"
					class="card-tile__review"
					:class="`card-tile__review--${card.reviewState}`"
					:aria-label="t('kanso', 'Review: {state}', { state: card.reviewState })">
					<CheckDecagramIcon v-if="card.reviewState === 'approved'" :size="12" />
					<AlertDecagramIcon v-else-if="card.reviewState === 'changes_requested'" :size="12" />
					<CheckDecagramOutlineIcon v-else :size="12" />
				</span>
				<!-- Assignee avatar stack - pushed to the right via margin-left:auto -->
				<div v-if="card.assigneeIds && card.assigneeIds.length" class="card-tile__assignees" :aria-label="t('kanso', 'Assignees')">
					<NcAvatar
						v-for="uid in visibleAssigneeIds"
						:key="uid"
						:user="uid"
						:size="24"
						:hide-status="true"
						:disable-tooltip="false"
						class="card-tile__avatar" />
					<span v-if="extraAssigneeCount > 0" class="card-tile__avatar-overflow">
						+{{ extraAssigneeCount }}
					</span>
				</div>
			</div>
		</button>

		<!-- Bottom drop indicator -->
		<div v-if="closestEdge === 'bottom'" class="card-tile__drop-line card-tile__drop-line--bottom" />
	</div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import ProgressClockIcon from 'vue-material-design-icons/ProgressClock.vue'
import CheckboxMarkedOutlineIcon from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import CommentMultipleOutlineIcon from 'vue-material-design-icons/CommentMultipleOutline.vue'
import SitemapIcon from 'vue-material-design-icons/Sitemap.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import ArrowUpBoldIcon from 'vue-material-design-icons/ArrowUpBold.vue'
import SignalCellular2Icon from 'vue-material-design-icons/SignalCellular2.vue'
import SignalCellular1Icon from 'vue-material-design-icons/SignalCellular1.vue'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import CheckDecagramOutlineIcon from 'vue-material-design-icons/CheckDecagramOutline.vue'
import AlertDecagramIcon from 'vue-material-design-icons/AlertDecagram.vue'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'
import BugIcon from 'vue-material-design-icons/Bug.vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import CheckboxMarkedCircleOutlineIcon from 'vue-material-design-icons/CheckboxMarkedCircleOutline.vue'
import BroomIcon from 'vue-material-design-icons/Broom.vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import { translate as t } from '@nextcloud/l10n'
import { PRIORITY_LEVELS } from '../composables/usePriority.js'
import { CARD_TYPES } from '../composables/useCardType.js'

import { draggable, dropTargetForElements } from '@atlaskit/pragmatic-drag-and-drop/element/adapter'
import { combine } from '@atlaskit/pragmatic-drag-and-drop/combine'
import { cssColor, readableColor } from '../services/color.js'
import { humanId } from '../services/humanId.js'
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
	/** Board human-id prefix (e.g. "KAN") - composed with card.boardSeq for the ref badge. */
	boardPrefix: {
		type: String,
		default: '',
	},
	/**
	 * Swimlane key this tile is rendered in (#3406), or '' when swimlanes are
	 * off. Threaded into the drag/drop payload so the BoardView monitor can
	 * reject cross-lane drops (which would imply a reassignment — a documented
	 * v1 stretch). Empty string means "no lane" and leaves DnD unchanged.
	 */
	laneKey: {
		type: String,
		default: '',
	},
	/** Multi-select mode: when true, clicking the tile emits 'select' instead of 'click'. */
	selectionMode: {
		type: Boolean,
		default: false,
	},
	/** Whether this tile is currently in the multi-select selection. */
	selected: {
		type: Boolean,
		default: false,
	},
})

// Human-readable reference id (prefix + '-' + boardSeq), null when unassigned.
const cardHumanId = computed(() => humanId(props.boardPrefix, props.card.boardSeq))

const emit = defineEmits(['click', 'hover', 'select'])

function onTileClick(event) {
	if (props.selectionMode) {
		emit('select', { id: props.card.id, shiftKey: event.shiftKey })
	} else {
		emit('click')
	}
}

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
				laneKey: props.laneKey,
			}),
			onDragStart: () => { isDragging.value = true },
			onDrop: () => { isDragging.value = false },
		}),
		dropTargetForElements({
			element: el.value,
			canDrop: ({ source }) => source.data.type === 'card' && source.data.cardId !== props.card.id,
			getData: ({ input, element: el2 }) => attachClosestEdge(
				{ type: 'card', cardId: props.card.id, stackId: props.card.stackId, sortKey: props.card.sortKey, laneKey: props.laneKey },
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
const isInProgress = computed(() => !isDone.value && Number(props.card.startedAt) > 0)

const dueDateClass = computed(() => {
	if (!props.card.duedate) return ''
	// When done, suppress overdue/soon coloring - show chip neutrally
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

// Card type (#3402) metadata for the tile icon, or null for the implicit "none".
const cardType = computed(() => CARD_TYPES.find((tp) => tp.value === props.card.type) ?? null)

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

/* Selected state - outline highlight when card is in multi-select selection */
.card-tile--selected {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 1px;
}

/* Selection checkbox - positioned top-right within the tile */
.card-tile__select {
	position: absolute;
	top: 8px;
	right: 8px;
	width: 16px;
	height: 16px;
	cursor: pointer;
	accent-color: var(--color-primary-element);
	pointer-events: none;
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

/* Cover band - a solid-colour strip bleeding to the tile edges at the top.
 * Negative margins cancel the tile's 10px/12px padding so the band spans the
 * full width and sits flush with the rounded top corners. */
.card-tile__cover {
	display: block;
	height: 8px;
	width: calc(100% + 24px);
	margin: -10px -12px 2px;
	border-top-left-radius: var(--border-radius);
	border-top-right-radius: var(--border-radius);
}

/* Human-id reference badge - small, muted, monospace so it reads as an id */
.card-tile__ref {
	font-size: 0.68rem;
	font-weight: 600;
	letter-spacing: 0.02em;
	color: var(--color-text-maxcontrast);
	font-family: var(--font-face-monospace, monospace);
}

.card-tile__title {
	color: var(--color-main-text);
	font-size: 0.875rem;
	line-height: 1.4;
	word-break: break-word;
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

/* Meta row - all badges on a single flex line; assignees pushed right */
.card-tile__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px;
	width: 100%;
	margin-top: 2px;
}

.card-tile__inprogress {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	font-size: 0.72rem;
	font-weight: 600;
	padding: 1px 6px;
	border-radius: 8px;
	color: var(--color-primary-element-text);
	background: var(--color-primary-element);
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

/* Done state
 *
 * The "muted/done" cue is applied to the tile chrome (background + the
 * struck-through title) rather than as a blanket opacity on the whole tile.
 * A tile-wide `opacity` would fade the meta/label chips on top of their
 * already-muted foreground tokens, dropping their text contrast below WCAG AA
 * and dimming the readableColor() text on dynamic label chips until it is
 * illegible. Keeping the chips at full opacity preserves their designed
 * contrast (NC color tokens + readableColor) in both light and dark themes,
 * while the greyed background and struck title still read clearly as "done".
 */
.card-tile--done {
	background: var(--color-background-dark);
}

.card-tile__title--done {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

/* Colorless chips default to --color-background-dark, which now matches the
 * done tile background - lift them onto the main background so they stay
 * distinguishable against a done tile. */
.card-tile--done .card-tile__label-chip--no-color,
.card-tile--done .card-tile__estimate,
.card-tile--done .card-tile__avatar-overflow {
	background: var(--color-main-background);
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

/* Type icon (#3402) - icon-first, colour-coded per built-in type */
.card-tile__type {
	display: inline-flex;
	align-items: center;
}
.card-tile__type--bug { color: #e74c3c; }
.card-tile__type--feature { color: #27ae60; }
.card-tile__type--task { color: var(--color-primary-element, #0082c9); }
.card-tile__type--chore { color: #7f8c8d; }

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

/* Review state chip */
.card-tile__review {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	font-size: 0.75rem;
	border-radius: 10px;
	padding: 1px 5px;
	border: 1px solid currentColor;
	line-height: 1;
}

.card-tile__review--pending {
	color: var(--color-warning, #f0a844);
	border-color: var(--color-warning, #f0a844);
	background: rgba(240, 168, 68, 0.08);
}

.card-tile__review--approved {
	color: var(--color-success, #46ba61);
	border-color: var(--color-success, #46ba61);
	background: rgba(70, 186, 97, 0.1);
}

.card-tile__review--changes_requested {
	color: var(--color-error, #e30000);
	border-color: var(--color-error, #e30000);
	background: rgba(var(--color-error-rgb, 227, 0, 0), 0.1);
}

/* Blocked badge - muted red attention chip */
.card-tile__blocked {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	font-size: 0.72rem;
	font-weight: 600;
	padding: 1px 6px;
	border-radius: 8px;
	color: var(--color-error, #e30000);
	border: 1px solid var(--color-error, #e30000);
	background: rgba(var(--color-error-rgb, 227, 0, 0), 0.08);
}

/* Estimate chip */
.card-tile__estimate {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	font-size: 0.7rem;
	font-weight: 600;
	border-radius: 10px;
	padding: 1px 7px;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
}

/* Assignee avatar stack - inside .card-tile__meta, pushed to the right */
.card-tile__assignees {
	display: flex;
	align-items: center;
	margin-left: auto;
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
