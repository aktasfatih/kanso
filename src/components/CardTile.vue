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
			:class="{ 'card-tile--done': isDone, 'card-tile--selected': selected, 'card-tile--compact': compact, 'card-tile--nest-target': isNestTarget }"
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
			<!-- Cover band - a solid colour strip at the top of the tile (#3549).
				 Hidden when the board switched cover colours off (#5894): the stored
				 colour is untouched and comes back when it is switched on again. -->
			<span
				v-if="card.coverColor && cardFeatures.coverColor"
				class="card-tile__cover"
				:style="{ background: cssColor(card.coverColor) }"
				aria-hidden="true" />
			<!-- Label chips row - only rendered when the card has assigned labels -->
			<div v-if="cardLabels.length" class="card-tile__labels" :aria-label="t('kanso', 'Labels')">
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
				v-if="card.parentCardId || isInProgress || card.blocked || card.waitingOnExternal || card.recurring || (card.timerRunning && cardFeatures.timeTracking) || card.duedate || (card.checklist && card.checklist.total > 0) || (card.childProgress && card.childProgress.total > 0) || card.commentCount > 0 || card.priority > 0 || cardType || (card.assigneeIds && card.assigneeIds.length) || card.reviewState || card.estimate || isRestricted"
				class="card-tile__meta">
				<!-- Sub-card marker: this card hangs under another one. The board draws
				     no indent (columns are flat), so without this a sub-card is
				     indistinguishable from any other card. The parent's title is not in
				     the board summary and is deliberately not fetched for it — the
				     board payload stays summary-only, and the panel names the parent. -->
				<span
					v-if="card.parentCardId"
					class="card-tile__subcard"
					role="img"
					:aria-label="t('kanso', 'Sub-card')"
					:title="t('kanso', 'Sub-card')">
					<SubdirectoryArrowRightIcon :size="14" />
				</span>
				<!-- In-progress status chip -->
				<span
					v-if="isInProgress"
					class="card-tile__inprogress"
					:aria-label="t('kanso', 'In progress')">
					<ProgressClockIcon :size="12" />
					{{ t('kanso', 'In progress') }}
				</span>
				<!-- Visibility badge (#3743) - internal/private cards carry a lock
				     so viewers know the card is scoped (public cards show nothing) -->
				<span
					v-if="isRestricted"
					class="card-tile__visibility"
					:aria-label="card.visibility === 'private' ? t('kanso', 'Private card') : t('kanso', 'Internal card')"
					:title="card.visibility === 'private' ? t('kanso', 'Private — only you') : t('kanso', 'Internal — only your side of the board')">
					<LockOutlineIcon :size="12" />
					{{ card.visibility === 'private' ? t('kanso', 'Private') : t('kanso', 'Internal') }}
				</span>
				<!-- Blocked badge - shown when the card has an unresolved blocker -->
				<span
					v-if="card.blocked"
					class="card-tile__blocked"
					:aria-label="t('kanso', 'Blocked by another card')">
					<CancelIcon :size="12" />
					{{ t('kanso', 'Blocked') }}
				</span>
				<!-- Waiting-on-client chip (#3746) - derived server-side from the
				     card's open steps assigned to the external side; appears and
				     clears purely from step state, no stored flag -->
				<span
					v-if="card.waitingOnExternal"
					class="card-tile__waiting"
					:aria-label="t('kanso', 'Waiting on client')"
					:title="waitingTitle">
					<AccountClockIcon :size="12" />
					{{ waitingLabel }}
				</span>
				<!-- Type icon (#3402) - icon-first built-in issue type -->
				<span
					v-if="cardType"
					class="card-tile__type"
					:class="`card-tile__type--${cardType.value}`"
					:aria-label="t('kanso', 'Type: {type}', { type: cardType.label })"
					:title="cardType.label">
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
				<!-- Recurring badge (#61) - the card carries a live recurrence rule.
				     A single boolean rides the board summary; the rule itself is
				     loaded only when the card is opened. -->
				<span
					v-if="card.recurring"
					class="card-tile__recurring"
					:aria-label="t('kanso', 'Recurring source')"
					:title="t('kanso', 'Recurring source')">
					<RepeatIcon :size="14" />
				</span>
				<!-- Timer running badge (#73): card's automatic timer is ticking.
				     The boolean rides the board summary; pulses green to draw the
				     eye without adding a text label that clutters compact tiles. -->
				<span
					v-if="card.timerRunning && cardFeatures.timeTracking"
					class="card-tile__timer-running"
					:aria-label="t('kanso', 'Timer running')"
					:title="t('kanso', 'Timer running')">
					<TimerOutlineIcon :size="14" />
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
						:size="compact ? 20 : 24"
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

		<!-- Nest hint (#5885): an indented ghost row previewing where the dragged
		     card lands as a sub-card. Absolutely positioned so it never changes the
		     tile's measured height (the virtualizer caches those). -->
		<div v-if="isNestTarget" class="card-tile__nest-hint" aria-hidden="true">
			{{ t('kanso', 'Drop to nest as sub-card') }}
		</div>
	</div>
</template>

<script setup>
import { ref, computed, inject, watch, onMounted, onUnmounted } from 'vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import RepeatIcon from 'vue-material-design-icons/Repeat.vue'
import TimerOutlineIcon from 'vue-material-design-icons/TimerOutline.vue'
import ProgressClockIcon from 'vue-material-design-icons/ProgressClock.vue'
import CheckboxMarkedOutlineIcon from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import CommentMultipleOutlineIcon from 'vue-material-design-icons/CommentMultipleOutline.vue'
import SitemapIcon from 'vue-material-design-icons/Sitemap.vue'
import SubdirectoryArrowRightIcon from 'vue-material-design-icons/SubdirectoryArrowRight.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import ArrowUpBoldIcon from 'vue-material-design-icons/ArrowUpBold.vue'
import SignalCellular2Icon from 'vue-material-design-icons/SignalCellular2.vue'
import SignalCellular1Icon from 'vue-material-design-icons/SignalCellular1.vue'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import CheckDecagramOutlineIcon from 'vue-material-design-icons/CheckDecagramOutline.vue'
import AlertDecagramIcon from 'vue-material-design-icons/AlertDecagram.vue'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'
import AccountClockIcon from 'vue-material-design-icons/AccountClock.vue'
import LockOutlineIcon from 'vue-material-design-icons/LockOutline.vue'
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
import { formatCardDate } from '../utils/dateDisplay.js'
import { extractClosestEdge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge'
import { buildCardDragData, buildCardDropData, NEST_ENABLED } from '../services/cardNesting.js'
import { useCardFeatures } from '../services/cardFeatures.js'
import { useBoardCanEdit } from '../services/boardPermissions.js'

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
	/**
	 * Compact density (#3415): a per-user, view-only toggle that tightens the
	 * tile — smaller padding, a single-line title, smaller chips — so more cards
	 * fit on screen. Purely presentational; no card-data change.
	 */
	compact: {
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
// #5885: the pointer is in this tile's centre band and the dragged card may be
// nested under it. Mutually exclusive with closestEdge — the two affordances
// must never render together, or the user can't tell which drop they'll get.
const isNestTarget = ref(false)
let cleanup = () => {}

// Whether this surface offers drag-to-nest at all (#5885). Provided only by a
// board that owns the card drag monitor and is in manual sort; defaults to
// false everywhere else so no dead affordance is ever rendered.
const nestEnabled = inject(NEST_ENABLED, computed(() => false))

// Built-in card sections this board still shows (#5894). Injected, not a
// prop, so it reaches the tile through StackColumn / SwimlaneRow untouched;
// a surface with no provider (a cross-board View) gets all-enabled.
const cardFeatures = useCardFeatures()

// Whether the viewer may EDIT this board. A read-only member's move is refused
// server-side (CardService::move asserts PERMISSION_EDIT), so picking the tile
// up can only end in a rollback — don't offer the grab at all. Defaults to true
// on a surface with no provider (a cross-board View), which keeps its drag.
const canEditBoard = useBoardCanEdit()

// The drag handle is held apart from `cleanup` because it is the one
// registration that comes and goes: a board ACL change reaches the client on the
// periodic board refetch, not through a remount, so a one-shot read at mount
// would leave a promoted member without a grab and a demoted one with a dead
// one. The drop target is untouched by the flip and stays registered.
let dragCleanup = () => {}

/** (Re-)wire the tile's drag handle to the current permission. */
function syncDragHandle() {
	dragCleanup()
	// Destroying the draggable means its onDrop will never run, so a flip that
	// lands mid-drag would latch `card-tile-wrap--dragging` on forever. Clear it
	// here — the flag belongs to the registration we just tore down.
	//
	// Deliberately NOT the pendingRebinds deferral BoardListView uses: that one
	// defers rebinding a DROP TARGET, because pragmatic only re-evaluates a drop
	// target on a native dragover, so re-creating one under a parked pointer
	// silently kills the drop. This is a DRAGGABLE, and the tile's drop target is
	// left registered through the flip, so the drop still resolves through the
	// board-level monitorForElements. The only consequence here is the stale
	// class.
	isDragging.value = false
	dragCleanup = () => {}
	if (!el.value || !canEditBoard.value) return
	dragCleanup = draggable({
		element: el.value,
		getInitialData: () => buildCardDragData(props.card, props.laneKey),
		onDragStart: () => { isDragging.value = true },
		onDrop: () => { isDragging.value = false },
	})
}

watch(canEditBoard, syncDragHandle)

onMounted(() => {
	if (!el.value) return
	syncDragHandle()
	cleanup = combine(
		dropTargetForElements({
			element: el.value,
			canDrop: ({ source }) => source.data.type === 'card' && source.data.cardId !== props.card.id,
			getData: ({ input, element: el2, source }) => buildCardDropData({
				base: buildCardDragData(props.card, props.laneKey),
				input,
				element: el2,
				source,
				nestEnabled: nestEnabled.value === true,
			}),
			onDrag: ({ self }) => {
				const nesting = self.data.dropMode === 'nest'
				if (isNestTarget.value !== nesting) isNestTarget.value = nesting
				closestEdge.value = nesting ? null : extractClosestEdge(self.data)
			},
			onDragLeave: () => { closestEdge.value = null; isNestTarget.value = false },
			onDrop: () => { closestEdge.value = null; isNestTarget.value = false },
		}),
	)
})

onUnmounted(() => {
	cleanup()
	dragCleanup()
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
	// All-day dates are stored at UTC midnight; format them in UTC so the chip
	// shows the picked calendar day even west of UTC (not the previous day).
	return formatCardDate(iso, props.card.allDay === true, { month: 'short', day: 'numeric' })
}

// Priority label for the indicator badge
const priorityLabel = computed(() => {
	const level = PRIORITY_LEVELS.find((l) => l.value === Number(props.card.priority ?? 0))
	return level?.shortLabel ?? ''
})

// Card type (#3402) metadata for the tile icon, or null for the implicit "none".
// Internal/private cards carry a lock badge (#3743); public shows nothing.
const isRestricted = computed(() => props.card.visibility === 'internal' || props.card.visibility === 'private')

// Waiting-on-client chip (#3746): relative age since the OLDEST open external
// step was assigned (waitingSince is epoch seconds from the board summary).
// Below one hour the age is omitted rather than showing a noisy "0h".
const waitingAge = computed(() => {
	const since = Number(props.card.waitingSince ?? 0)
	if (!since) return ''
	const elapsed = Math.max(0, Math.floor(Date.now() / 1000) - since)
	const days = Math.floor(elapsed / 86400)
	if (days > 0) return t('kanso', '{n}d', { n: days })
	const hours = Math.floor(elapsed / 3600)
	return hours > 0 ? t('kanso', '{n}h', { n: hours }) : ''
})

const waitingLabel = computed(() => (waitingAge.value
	? t('kanso', 'Waiting on client') + ' · ' + waitingAge.value
	: t('kanso', 'Waiting on client')))

const waitingTitle = computed(() => {
	const since = Number(props.card.waitingSince ?? 0)
	if (!since) return t('kanso', 'Waiting on client')
	return t('kanso', 'Waiting on client since {date}', {
		date: new Date(since * 1000).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }),
	})
})

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

/* ── Nest drop affordance (#5885) ─────────────────────────────────────────────
 * Dropping on a card's CENTRE nests the dragged card under it, dropping on an
 * edge reorders. The two must read differently before the pointer is released:
 * reorder keeps the thin 2px edge line above, nesting highlights the whole
 * target tile with a dashed outline and an inset left bar — the same "indent
 * guide" vocabulary the list view uses for a child row. */
/* Doubled class so the highlight also wins over `.card-tile--done`'s background
 * (same single-class specificity, and --done is declared later in this file). */
.card-tile.card-tile--nest-target {
	outline: 2px dashed var(--color-primary-element);
	outline-offset: 1px;
	background: color-mix(in srgb, var(--color-primary-element) 10%, var(--color-main-background));
	box-shadow: inset 6px 0 0 0 var(--color-primary-element);
}

/* The "it lands here" label. Absolutely positioned INSIDE the tile bounds: it
 * must not change the tile's measured height (StackColumn's virtualizer caches
 * those per card) and must not spill past the column's scroll container, which
 * would clip it on the last tile and jitter the scroll height mid-drag. */
.card-tile__nest-hint {
	position: absolute;
	inset-inline-end: 6px;
	bottom: 5px;
	max-width: calc(100% - 32px);
	z-index: 11;
	pointer-events: none;
	display: flex;
	align-items: center;
	height: 18px;
	padding: 0 6px;
	border-radius: var(--border-radius);
	border: 1px dashed var(--color-primary-element);
	background: var(--color-main-background);
	color: var(--color-primary-element);
	font-size: 0.68rem;
	font-weight: 600;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
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
	/* Legible status colours for small foreground elements (bug icon, overdue
	 * date, urgent priority, blocked chip, warning/amber review + priority chips).
	 * NC's --color-error / --color-warning dark shades are near black on the dark
	 * tile surface (#3905/#4054); brighten them under dark themes while keeping
	 * the stock values in light mode. Scoped to the tile so they can't leak. */
	--kanso-error-legible: var(--color-error, #e30000);
	--kanso-error-legible-rgb: var(--color-error-rgb, 227, 0, 0);
	--kanso-warning-legible: var(--color-warning, #e07b00);
	/* Legible success green twin: stock --color-success in light, a brighter
	 * green (#3fb950) under dark so "complete"/"approved"/"feature" pills stay
	 * readable on the dark tile surface. */
	--kanso-success-legible: var(--color-success, #46ba61);
	--kanso-success-legible-rgb: 70, 186, 97;

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

/* Explicit dark themes (theme picker) + auto (prefers-color-scheme) dark.
 * #ff6b6b reads clearly on the dark surface and still passes AA as border/icon. */
body.theme--dark .card-tile,
[data-theme-dark] .card-tile,
[data-themes*='dark'] .card-tile {
	--kanso-error-legible: #ff6b6b;
	--kanso-error-legible-rgb: 255, 107, 107;
	--kanso-warning-legible: #d29922;
	--kanso-success-legible: #3fb950;
	--kanso-success-legible-rgb: 63, 185, 80;
}

@media (prefers-color-scheme: dark) {
	body.theme--default .card-tile,
	body:not(.theme--light):not(.theme--dark) .card-tile {
		--kanso-error-legible: #ff6b6b;
		--kanso-error-legible-rgb: 255, 107, 107;
		--kanso-warning-legible: #d29922;
		--kanso-success-legible: #3fb950;
		--kanso-success-legible-rgb: 63, 185, 80;
	}
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

/* Meta row - all badges on a single flex line; assignees pushed right.
 * A min-height reserves the row's vertical space so late-loading chips /
 * avatars (which render after the summary paint) don't shift the tile height
 * and jolt the virtualizer's measured rows (#3415). 24px == the avatar height,
 * the tallest inline meta item. */
.card-tile__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px;
	width: 100%;
	margin-top: 2px;
	min-height: 24px;
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
	color: var(--kanso-error-legible);
	border-color: var(--kanso-error-legible);
	background: rgba(var(--kanso-error-legible-rgb), 0.08);
}

.card-tile__due--soon {
	color: var(--kanso-warning-legible);
	border-color: var(--kanso-warning-legible);
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
	color: var(--kanso-success-legible);
	border-color: var(--kanso-success-legible);
	background: rgba(var(--kanso-success-legible-rgb), 0.1);
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
	color: var(--kanso-success-legible);
	border-color: var(--kanso-success-legible);
	background: rgba(var(--kanso-success-legible-rgb), 0.1);
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
/* Type icons use theme tokens so they keep WCAG contrast in both light and dark
   themes (bare #e74c3c/#27ae60/#7f8c8d fail contrast on the dark surface). */
.card-tile__type--bug { color: var(--kanso-error-legible); }
.card-tile__type--feature { color: var(--kanso-success-legible); }
.card-tile__type--task { color: var(--color-primary-element, #0082c9); }
.card-tile__type--chore { color: var(--color-text-maxcontrast, #7f8c8d); }

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

/* Low: grey — token keeps contrast on the dark surface where #888 fails. */
.card-tile__priority--1 {
	color: var(--color-text-maxcontrast, #767676);
	border-color: var(--color-text-maxcontrast, #767676);
	background: rgba(136, 136, 136, 0.1);
}

/* Medium: blue */
.card-tile__priority--2 {
	color: var(--color-primary-element, #0082c9);
	border-color: var(--color-primary-element, #0082c9);
	background: rgba(0, 130, 201, 0.1);
}

/* High: orange — --kanso-warning-legible keeps ≥4.5:1 text contrast in both
   themes; stays distinct from Urgent's red. */
.card-tile__priority--3 {
	color: var(--kanso-warning-legible);
	border-color: var(--kanso-warning-legible);
	background: rgba(224, 123, 0, 0.1);
}

/* Urgent: red */
.card-tile__priority--4 {
	color: var(--kanso-error-legible);
	border-color: var(--kanso-error-legible);
	background: rgba(var(--kanso-error-legible-rgb), 0.1);
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
	color: var(--kanso-warning-legible);
	border-color: var(--kanso-warning-legible);
	background: rgba(240, 168, 68, 0.08);
}

.card-tile__review--approved {
	color: var(--kanso-success-legible);
	border-color: var(--kanso-success-legible);
	background: rgba(var(--kanso-success-legible-rgb), 0.1);
}

.card-tile__review--changes_requested {
	color: var(--kanso-error-legible);
	border-color: var(--kanso-error-legible);
	background: rgba(var(--kanso-error-legible-rgb), 0.1);
}

/* Visibility badge (#3743) - neutral lock chip for internal/private cards */
.card-tile__visibility {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	font-size: 0.72rem;
	font-weight: 600;
	padding: 1px 6px;
	border-radius: 8px;
	color: var(--color-text-maxcontrast);
	border: 1px solid var(--color-border-dark);
	background: var(--color-background-hover);
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
	color: var(--kanso-error-legible);
	border: 1px solid var(--kanso-error-legible);
	background: rgba(var(--kanso-error-legible-rgb), 0.08);
}

/* Waiting-on-client chip (#3746) - amber "ball is with the client" signal.
 * --kanso-warning-legible adapts per theme and keeps text contrast; distinct
 * from the blocked chip's red (blocked = stuck, waiting = parked with the other
 * side). */
.card-tile__waiting {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	font-size: 0.72rem;
	font-weight: 600;
	padding: 1px 6px;
	border-radius: 8px;
	color: var(--kanso-warning-legible);
	border: 1px solid var(--kanso-warning-legible);
	background: rgba(240, 168, 68, 0.1);
}

/* Sub-card marker - a muted ↳ glyph in the same neutral vocabulary as the
 * recurring badge: an attribute of the card, not an alert. It leads the meta
 * row so "this belongs under another card" is the first thing read. */
.card-tile__subcard {
	display: inline-flex;
	align-items: center;
	color: var(--color-text-maxcontrast);
}

/* Recurring badge (#61) - a muted repeat glyph; neutral so it reads as an
 * attribute of the card, not an alert. */
.card-tile__recurring {
	display: inline-flex;
	align-items: center;
	color: var(--color-text-maxcontrast);
}

/* Timer running badge (#73) - a pulsing green clock icon signals that the
 * card's automatic timer is currently ticking. The pulse is subtle (opacity
 * only, no layout shift) so it catches the eye without distracting. */
.card-tile__timer-running {
	display: inline-flex;
	align-items: center;
	color: var(--kanso-success-legible);
	animation: kanso-timer-pulse 2s ease-in-out infinite;
}

@keyframes kanso-timer-pulse {
	0%, 100% { opacity: 1; }
	50% { opacity: 0.45; }
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

/* ── Compact density (#3415) ───────────────────────────────────────────────────
 * A per-user, view-only toggle that tightens every tile so more cards fit on
 * screen: less padding, a single-line title, a shorter meta row, and smaller
 * chips / avatars. Purely presentational — the tile keeps every badge, it just
 * renders denser. The compact tile is measurably shorter, so StackColumn feeds
 * the virtualizer a smaller estimateSize and forces a re-measure when density
 * flips (see StackColumn.vue). */
.card-tile--compact {
	gap: 3px;
	padding: 6px 8px;
}

.card-tile--compact .card-tile__cover {
	height: 5px;
	width: calc(100% + 16px);
	margin: -6px -8px 1px;
}

.card-tile--compact .card-tile__title {
	font-size: 0.83rem;
	line-height: 1.3;
	-webkit-line-clamp: 1;
}

.card-tile--compact .card-tile__labels {
	margin-bottom: 0;
}

/* Compact chips read one tick smaller with tighter padding. */
.card-tile--compact .card-tile__label-chip {
	font-size: 0.66rem;
	padding: 2px 6px;
}

/* A shorter reserved meta row in compact mode; still tall enough for the
 * (smaller) avatars so late-loading chips don't shift the row. */
.card-tile--compact .card-tile__meta {
	margin-top: 0;
	min-height: 20px;
}

.card-tile--compact .card-tile__due,
.card-tile--compact .card-tile__checklist,
.card-tile--compact .card-tile__children,
.card-tile--compact .card-tile__comments,
.card-tile--compact .card-tile__priority,
.card-tile--compact .card-tile__estimate,
.card-tile--compact .card-tile__review,
.card-tile--compact .card-tile__inprogress,
.card-tile--compact .card-tile__blocked,
.card-tile--compact .card-tile__waiting {
	font-size: 0.68rem;
	padding: 0 5px;
}

.card-tile--compact .card-tile__ref {
	font-size: 0.64rem;
}

/* Compact: shrink the icon-only recurring badge to match the denser meta row. */
.card-tile--compact .card-tile__recurring :deep(svg) {
	width: 12px;
	height: 12px;
}

/* Compact: match the timer-running icon size to the recurring badge. */
.card-tile--compact .card-tile__timer-running :deep(svg) {
	width: 12px;
	height: 12px;
}

/* Compact: same treatment for the sub-card marker. */
.card-tile--compact .card-tile__subcard :deep(svg) {
	width: 12px;
	height: 12px;
}

/* Smaller overflow badge to match the 20px compact avatars (NcAvatar itself is
 * sized via its :size prop above). */
.card-tile--compact .card-tile__avatar-overflow {
	width: 20px;
	height: 20px;
	font-size: 0.6rem;
}
</style>
