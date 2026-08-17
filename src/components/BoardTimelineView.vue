<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="timeline">
		<!-- Zoom control + jump-to-today + legend -->
		<div class="timeline__toolbar">
			<span class="timeline__toolbar-label">{{ t('kanso', 'Zoom') }}</span>
			<div class="timeline__zoom" role="group" :aria-label="t('kanso', 'Timeline zoom')">
				<button
					v-for="z in ZOOMS"
					:key="z.key"
					class="timeline__zoom-btn"
					:class="{ 'timeline__zoom-btn--active': zoom === z.key }"
					@click="zoom = z.key">
					{{ z.label }}
				</button>
			</div>
			<button
				class="timeline__today-btn"
				:disabled="scheduledRows.length === 0"
				@click="jumpToToday">
				<CalendarTodayIcon :size="16" />
				{{ t('kanso', 'Jump to today') }}
			</button>

			<div v-if="scheduledRows.length > 0" class="timeline__legend">
				<span class="timeline__legend-item">
					<span class="timeline__legend-swatch timeline__legend-swatch--not-started" />
					{{ t('kanso', 'Not started') }}
				</span>
				<span class="timeline__legend-item">
					<span class="timeline__legend-swatch timeline__legend-swatch--in-progress" />
					{{ t('kanso', 'In progress') }}
				</span>
				<span class="timeline__legend-item">
					<span class="timeline__legend-swatch timeline__legend-swatch--overdue" />
					{{ t('kanso', 'Overdue') }}
				</span>
				<span class="timeline__legend-item">
					<span class="timeline__legend-swatch timeline__legend-swatch--done" />
					{{ t('kanso', 'Done') }}
				</span>
				<span class="timeline__legend-item">
					<span class="timeline__legend-swatch timeline__legend-swatch--milestone" />
					{{ t('kanso', 'Single date') }}
				</span>
			</div>
		</div>

		<div v-if="scheduledRows.length === 0" class="timeline__empty">
			<CalendarBlankOutlineIcon :size="40" />
			<p>{{ t('kanso', 'No cards have a start or due date yet. Add dates on a card to place it on the timeline.') }}</p>
		</div>

		<!-- Timeline body: a frozen left pane (id/title/assignee) beside a
		     horizontally-scrollable track (two-tier axis + bars). Both scroll
		     vertically together as one flex row; only the track scrolls sideways,
		     so a bar always stays aligned with its row in the frozen pane. -->
		<div v-else class="timeline__body">
			<!-- Frozen left pane -->
			<div class="timeline__pane">
				<div class="timeline__pane-head">{{ t('kanso', 'Card') }}</div>
				<template v-for="grp in groups" :key="`p${grp.stack.id}`">
					<div
						class="timeline__group-row"
						role="button"
						tabindex="0"
						:aria-expanded="!isCollapsed(grp.stack.id)"
						@click="toggleGroup(grp.stack.id)"
						@keydown.enter.prevent="toggleGroup(grp.stack.id)"
						@keydown.space.prevent="toggleGroup(grp.stack.id)">
						<ChevronRightIcon v-if="isCollapsed(grp.stack.id)" class="timeline__group-chevron" :size="16" />
						<ChevronDownIcon v-else class="timeline__group-chevron" :size="16" />
						<span
							class="timeline__group-dot"
							:style="grp.stack.color ? { background: cssColor(grp.stack.color) } : {}" />
						<span class="timeline__group-title">{{ grp.stack.title }}</span>
						<span class="timeline__group-count">{{ grp.count }}</span>
					</div>
					<div
						v-for="row in grp.rows"
						:key="`p${row.card.id}`"
						class="timeline__pane-row"
						role="button"
						tabindex="0"
						:title="row.card.title"
						@click="openCard(row.card.id)"
						@keydown.enter.prevent="openCard(row.card.id)"
						@keydown.space.prevent="openCard(row.card.id)">
						<span
							class="timeline__pane-status"
							:class="`timeline__pane-status--${statusOf(row)}`" />
						<span v-if="cardHumanId(row.card)" class="timeline__pane-id">{{ cardHumanId(row.card) }}</span>
						<span class="timeline__pane-title">{{ row.card.title }}</span>
						<span
							v-if="(row.card.assigneeIds || []).length"
							class="timeline__pane-assignees">
							<NcAvatar
								v-for="uid in (row.card.assigneeIds || []).slice(0, 3)"
								:key="uid"
								:user="uid"
								:size="22"
								:hide-status="true" />
						</span>
					</div>
				</template>
			</div>

			<!-- Scrollable track -->
			<div ref="scrollRef" class="timeline__scroll">
				<div ref="trackRef" class="timeline__inner" :class="{ 'timeline__inner--drop-active': dropActive }" :style="{ width: `${trackWidth}px` }">
					<!-- Drop affordance: a vertical guide at the day under the cursor
					     while an unscheduled card is dragged over the track. -->
					<div
						v-if="dropGuideX !== null"
						class="timeline__drop-guide"
						:style="{ left: `${dropGuideX}px` }" />
					<!-- Weekend shading (behind everything) -->
					<div
						v-for="wk in weekendBands"
						:key="`w${wk.x}`"
						class="timeline__weekend"
						:style="{ left: `${wk.x}px`, width: `${wk.width}px` }" />

					<!-- Gridlines -->
					<div
						v-for="tick in ticks"
						:key="`g${tick.ms}`"
						class="timeline__grid"
						:style="{ left: `${tick.x}px` }" />

					<!-- Today marker -->
					<div v-if="todayX !== null" class="timeline__today" :style="{ left: `${todayX}px` }" />

					<!-- Two-tier axis: months over weeks/days -->
					<div class="timeline__axis">
						<div class="timeline__axis-months">
							<div
								v-for="m in monthTicks"
								:key="`m${m.ms}`"
								class="timeline__axis-month"
								:style="{ left: `${m.x}px`, width: `${m.width}px` }">
								{{ m.label }}
							</div>
						</div>
						<div class="timeline__axis-ticks">
							<div
								v-for="tick in ticks"
								:key="`t${tick.ms}`"
								class="timeline__tick"
								:style="{ left: `${tick.x}px` }">
								{{ tick.label }}
							</div>
						</div>
					</div>

					<!-- Rows, grouped by stack: a group header row then its card rows,
					     mirroring the frozen pane 1:1 for vertical alignment. -->
					<template v-for="grp in groups" :key="`t${grp.stack.id}`">
						<div class="timeline__group-track" />
						<div
							v-for="row in grp.rows"
							:key="`t${row.card.id}`"
							class="timeline__lane"
							role="button"
							tabindex="0"
							:title="row.card.title"
							:aria-label="row.card.title"
							@click="openCard(row.card.id)"
							@keydown.enter.prevent="openCard(row.card.id)"
							@keydown.space.prevent="openCard(row.card.id)">
							<div
								v-if="row.isMilestone"
								class="timeline__milestone"
								:class="{ 'timeline__bar--done': row.done, 'timeline__bar--started': row.started, 'timeline__bar--overdue': row.overdue }"
								:style="{ left: `${row.left}px` }">
								<span class="timeline__label timeline__label--after">{{ row.card.title }}</span>
							</div>
							<template v-else>
								<div
									class="timeline__bar"
									:class="{ 'timeline__bar--done': row.done, 'timeline__bar--started': row.started, 'timeline__bar--overdue': row.overdue }"
									:style="{ left: `${row.left}px`, width: `${row.width}px` }">
									<span v-if="row.labelInside" class="timeline__label">{{ row.card.title }}</span>
								</div>
								<span
									v-if="!row.labelInside"
									class="timeline__bar-outside-label"
									:style="{ left: `${row.left + row.width + 6}px` }">{{ row.card.title }}</span>
							</template>
						</div>
					</template>
				</div>
			</div>
		</div>

		<!-- Unscheduled cards -->
		<details v-if="unscheduled.length > 0" class="timeline__unscheduled">
			<summary>
				{{ n('kanso', '%n unscheduled card', '%n unscheduled cards', unscheduled.length) }}
				<span v-if="canEdit" class="timeline__unscheduled-hint">{{ t('kanso', 'Drag one onto the track to schedule it') }}</span>
			</summary>
			<ul class="timeline__unscheduled-list">
				<li v-for="card in unscheduled" :key="card.id">
					<button
						:ref="(el) => registerUnscheduledRef(card.id, el)"
						class="timeline__unscheduled-row"
						:class="{ 'timeline__unscheduled-row--draggable': canEdit, 'timeline__unscheduled-row--dragging': draggingCardId === card.id }"
						@click="openCard(card.id)">{{ card.title }}</button>
				</li>
			</ul>
		</details>
	</div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { useQueryClient } from '@tanstack/vue-query'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import CalendarBlankOutlineIcon from 'vue-material-design-icons/CalendarBlankOutline.vue'
import CalendarTodayIcon from 'vue-material-design-icons/CalendarToday.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import { draggable, dropTargetForElements } from '@atlaskit/pragmatic-drag-and-drop/element/adapter'
import { updateCard as apiUpdateCard } from '../services/api.js'
import { boardQueryKey } from '../composables/queryKeys.js'
import { cssColor } from '../services/color.js'
import { humanId } from '../services/humanId.js'

const props = defineProps({
	/** Filtered, non-archived cards (start_date/duedate carried in the summary). */
	cards: { type: Array, default: () => [] },
	/** Non-archived stacks in display order (for group headers + row grouping). */
	stacks: { type: Array, default: () => [] },
	/** Map<stackId, card[]> from BoardView - same source the List view groups on. */
	cardsByStack: { type: Object, default: null },
	/**
	 * Generalized group-by mode (#3815): an ordered list of arbitrary groups
	 * `[{ key, title, cards }]` (cross-board Views grouped by status / priority /
	 * assignee / board). When provided it drives the timeline row grouping instead
	 * of the stack path. Backward compatible: absent → the classic stack (or flat
	 * single-group) rendering is unchanged.
	 */
	groups: { type: Array, default: null },
	/** Board human-id prefix (e.g. "KAN") - composed with card.boardSeq. */
	boardPrefix: { type: String, default: '' },
	/** Whether the current user may edit cards (board permission bit 2). Gates
	 *  drag-to-schedule: read-only viewers get no draggable footer cards. */
	canEdit: { type: Boolean, default: false },
	/**
	 * The single-board id (classic per-board timeline). Null for a cross-board
	 * View (#3815), where each card carries its own boardId and drag-to-schedule
	 * is disabled (canEdit=false), so no board-scoped mutation ever runs.
	 */
	boardId: { type: [String, Number], default: null },
})

const router = useRouter()
const queryClient = useQueryClient()

// The horizontally-scrolling track container (for jump-to-today + page-fill sizing).
const scrollRef = ref(null)
// The inner track element (drop target for scheduling unscheduled cards).
const trackRef = ref(null)
// Live viewport width of the track, so a short date range can be padded to fill it.
const viewportWidth = ref(0)
let resizeObserver = null

// Collapsed stack ids, persisted per board (mirrors the List view's kanso.listCollapsed).
const collapsedKey = computed(() => `kanso.timelineCollapsed.${props.boardId}`)
const collapsed = ref(loadCollapsed())

function loadCollapsed() {
	try {
		const saved = localStorage.getItem(`kanso.timelineCollapsed.${props.boardId}`)
		if (saved) return new Set(JSON.parse(saved))
	} catch (e) { /* localStorage unavailable - default to all expanded */ }
	return new Set()
}

// Reload persisted state when the board changes (component is reused across boards).
watch(() => props.boardId, () => { collapsed.value = loadCollapsed() })

function isCollapsed(stackId) {
	return collapsed.value.has(stackId)
}

function toggleGroup(stackId) {
	const next = new Set(collapsed.value)
	if (next.has(stackId)) next.delete(stackId)
	else next.add(stackId)
	collapsed.value = next
	try {
		localStorage.setItem(collapsedKey.value, JSON.stringify([...next]))
	} catch (e) { /* localStorage unavailable - collapse is in-memory only */ }
}

const DAY = 86_400_000
const ZOOMS = [
	{ key: 'day', label: t('kanso', 'Day'), pxPerDay: 34, stepDays: 1 },
	{ key: 'week', label: t('kanso', 'Week'), pxPerDay: 12, stepDays: 7 },
	{ key: 'month', label: t('kanso', 'Month'), pxPerDay: 4, stepDays: 30 },
]
const zoom = ref('week')

// Reactive clock so the "today" marker and overdue flags recompute over time
// (e.g. a board left open across midnight). A plain Date.now() inside a computed
// has no reactive dependency and would stay frozen until `cards` changed.
const now = ref(Date.now())
let clockTimer = null
function tickNow() {
	now.value = Date.now()
}
onMounted(() => {
	clockTimer = setInterval(tickNow, 60_000)
	document.addEventListener('visibilitychange', tickNow)
	window.addEventListener('focus', tickNow)
	if (scrollRef.value) {
		viewportWidth.value = scrollRef.value.clientWidth
		if (typeof ResizeObserver !== 'undefined') {
			resizeObserver = new ResizeObserver((entries) => {
				for (const entry of entries) viewportWidth.value = entry.contentRect.width
			})
			resizeObserver.observe(scrollRef.value)
		}
	}
})
onBeforeUnmount(() => {
	if (clockTimer !== null) {
		clearInterval(clockTimer)
		clockTimer = null
	}
	document.removeEventListener('visibilitychange', tickNow)
	window.removeEventListener('focus', tickNow)
	if (resizeObserver) {
		resizeObserver.disconnect()
		resizeObserver = null
	}
})
const zoomCfg = computed(() => ZOOMS.find((z) => z.key === zoom.value) ?? ZOOMS[1])
const pxPerDay = computed(() => zoomCfg.value.pxPerDay)

const LEFT_PAD = 8
// Below this bar width the in-bar title clips to nothing, so it renders beside the bar.
const LABEL_MIN_WIDTH = 60

/** Midnight (local) of a date value, in ms, or null. */
function dayFloor(value) {
	if (!value) return null
	const d = new Date(value)
	if (Number.isNaN(d.getTime())) return null
	d.setHours(0, 0, 0, 0)
	return d.getTime()
}

/** Layout metrics for one card: its ms range + done/started state, or null when unscheduled. */
function layoutOf(card) {
	const start = dayFloor(card.startDate)
	const due = dayFloor(card.duedate)
	if (start === null && due === null) return null
	const s = start ?? due
	const e = due ?? start
	const done = Number(card.doneAt) > 0
	return { card, startMs: Math.min(s, e), endMs: Math.max(s, e), done, started: !done && Number(card.startedAt) > 0 }
}

// Group cards by stack (using the same Map BoardView feeds the List view), keeping
// stack display order. Each group carries only its scheduled rows; unscheduled
// cards are collected separately for the footer. Falls back to the flat `cards`
// prop (single synthetic group) when no stack grouping is supplied.
const grouped = computed(() => {
	const groupsOut = []
	const unsched = []
	if (props.groups) {
		// Generalized group-by (#3815): each caller group becomes a synthetic
		// "stack" (string id = collapse key, no color), keeping the pane/track
		// grouping render identical. A card may appear in MULTIPLE groups (e.g.
		// group-by-assignee surfaces a multi-assignee card in each lane), so the
		// flat unscheduled footer is de-duplicated by id to avoid duplicate keys.
		const seenUnsched = new Set()
		for (const group of props.groups) {
			const rows = []
			for (const card of group.cards ?? []) {
				const l = layoutOf(card)
				if (l === null) {
					if (!seenUnsched.has(card.id)) { seenUnsched.add(card.id); unsched.push(card) }
				} else {
					rows.push(l)
				}
			}
			if (rows.length) groupsOut.push({ stack: { id: group.key, title: group.title, color: null }, rows })
		}
	} else if (props.stacks.length && props.cardsByStack) {
		for (const stack of props.stacks) {
			const cards = props.cardsByStack.get(stack.id) ?? []
			const rows = []
			for (const card of cards) {
				const l = layoutOf(card)
				if (l === null) unsched.push(card)
				else rows.push(l)
			}
			if (rows.length) groupsOut.push({ stack, rows })
		}
	} else {
		const rows = []
		for (const card of props.cards) {
			const l = layoutOf(card)
			if (l === null) unsched.push(card)
			else rows.push(l)
		}
		if (rows.length) groupsOut.push({ stack: { id: 0, title: t('kanso', 'Cards'), color: null }, rows })
	}
	return { groups: groupsOut, unscheduled: unsched }
})

const scheduledRows = computed(() => grouped.value.groups.flatMap((g) => g.rows))
const unscheduled = computed(() => grouped.value.unscheduled)

// Real extent of the scheduled cards (the dates bars are anchored to). Collapsed
// groups still count toward the extent so the axis doesn't jump when a group folds.
const dataStart = computed(() => {
	const items = scheduledRows.value
	if (items.length === 0) return null
	return items.reduce((min, r) => Math.min(min, r.startMs), Infinity)
})
const dataEnd = computed(() => {
	const items = scheduledRows.value
	if (items.length === 0) return null
	return items.reduce((max, r) => Math.max(max, r.endMs), -Infinity)
})

// Rendered axis origin. When today falls before the earliest scheduled card we
// extend the origin backwards (leading pad) so the today marker stays on-screen;
// otherwise the axis starts at the real earliest date. Bars are always positioned
// via xForMs() off this origin, so real positions are never distorted.
const axisStart = computed(() => {
	if (dataStart.value === null) return null
	const today = dayFloor(now.value)
	return Math.min(dataStart.value, today)
})

// The date range must cover the viewport even when the data is short: we pad
// trailing empty days (and include today) so the track never looks truncated.
const totalDays = computed(() => {
	if (axisStart.value === null) return 0
	const today = dayFloor(now.value)
	// Extend the real end to at least today, so a short board near today still
	// paints a continuous track up to the marker.
	const dataDays = Math.round((Math.max(dataEnd.value, today) - axisStart.value) / DAY) + 1
	// Days that fit in the current viewport, minus the two side pads.
	const fitDays = viewportWidth.value > 0
		? Math.ceil((viewportWidth.value - LEFT_PAD * 2) / pxPerDay.value)
		: 0
	return Math.max(dataDays, fitDays)
})

const trackWidth = computed(() => {
	const w = LEFT_PAD * 2 + totalDays.value * pxPerDay.value
	// Guarantee the inner track is never narrower than its viewport (belt-and-braces
	// against sub-pixel rounding), so it always fills horizontally.
	return viewportWidth.value > 0 ? Math.max(w, viewportWidth.value) : w
})

// Rendered end of the axis (last padded day), so the two-tier month axis, gridlines
// and weekend shading extend across the whole filled width, not just the real data.
const axisEnd = computed(() => {
	if (axisStart.value === null) return null
	return axisStart.value + (totalDays.value - 1) * DAY
})

function xForMs(ms) {
	return LEFT_PAD + Math.round((ms - axisStart.value) / DAY) * pxPerDay.value
}

// Inverse of xForMs: an x-offset inside the inner track → the day-floor ms under
// it. Used by drag-to-schedule to turn a drop position into a due date. Clamped
// to the rendered axis so a drop just past either edge still lands on a real day.
function msForTrackX(xInTrack) {
	if (axisStart.value === null) return null
	const day = Math.floor((xInTrack - LEFT_PAD) / pxPerDay.value)
	const clampedDay = Math.max(0, Math.min(day, totalDays.value - 1))
	return axisStart.value + clampedDay * DAY
}

// Per-group with computed bar geometry, ready to render row-for-row against the pane.
// A collapsed group keeps its header (with the true card count) but drops its card
// rows entirely — the SAME reduced `rows` array feeds both the frozen pane and the
// track, so their row sequences stay identical and pane↔track alignment is exact.
const groups = computed(() => {
	if (axisStart.value === null) return []
	return grouped.value.groups.map((g) => {
		const isColl = isCollapsed(g.stack.id)
		const rows = isColl ? [] : g.rows.map((r) => {
			const left = xForMs(r.startMs)
			const isMilestone = r.startMs === r.endMs
			const width = isMilestone ? 0 : Math.max((Math.round((r.endMs - r.startMs) / DAY) + 1) * pxPerDay.value, pxPerDay.value)
			const overdue = !r.done && r.endMs < now.value
			const labelInside = !isMilestone && width >= LABEL_MIN_WIDTH
			return { ...r, left, width, isMilestone, overdue, labelInside }
		})
		return { stack: g.stack, rows, count: g.rows.length }
	})
})

const ticks = computed(() => {
	if (axisStart.value === null) return []
	const out = []
	const step = zoomCfg.value.stepDays
	for (let day = 0; day < totalDays.value; day += step) {
		const ms = axisStart.value + day * DAY
		out.push({ ms, x: LEFT_PAD + day * pxPerDay.value, label: labelForMs(ms) })
	}
	return out
})

// Top axis tier: one band per calendar month spanned by the range, sized to its
// visible slice of the track.
const monthTicks = computed(() => {
	if (axisStart.value === null) return []
	const out = []
	const end = axisEnd.value
	let cursor = new Date(axisStart.value)
	cursor.setDate(1)
	cursor.setHours(0, 0, 0, 0)
	while (cursor.getTime() <= end) {
		const monthStart = cursor.getTime()
		const next = new Date(cursor)
		next.setMonth(next.getMonth() + 1)
		const monthEnd = next.getTime() - DAY
		const from = Math.max(monthStart, axisStart.value)
		const to = Math.min(monthEnd, end)
		const x = xForMs(from)
		const width = xForMs(to) - x + pxPerDay.value
		out.push({
			ms: monthStart,
			x,
			width,
			label: new Date(monthStart).toLocaleDateString(undefined, { month: 'long', year: 'numeric' }),
		})
		cursor = next
	}
	return out
})

// Weekend day columns (Sat/Sun) shaded in the track background. Day zoom paints
// each weekend day; coarser zooms would produce hairlines, so it's day-zoom only.
const weekendBands = computed(() => {
	if (axisStart.value === null || zoom.value !== 'day') return []
	const out = []
	for (let day = 0; day < totalDays.value; day++) {
		const d = new Date(axisStart.value + day * DAY)
		const dow = d.getDay()
		if (dow === 0 || dow === 6) {
			out.push({ x: LEFT_PAD + day * pxPerDay.value, width: pxPerDay.value })
		}
	}
	return out
})

const todayX = computed(() => {
	if (axisStart.value === null) return null
	const t0 = dayFloor(now.value)
	if (t0 < axisStart.value || t0 > axisEnd.value) return null
	return xForMs(t0)
})

function labelForMs(ms) {
	const d = new Date(ms)
	if (zoom.value === 'month') return d.toLocaleDateString(undefined, { month: 'short', year: '2-digit' })
	return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

function statusOf(row) {
	if (row.done) return 'done'
	if (row.started) return 'in_progress'
	return 'not_started'
}

function cardHumanId(card) {
	return humanId(props.boardPrefix, card.boardSeq)
}

function openCard(cardId) {
	// Cross-board Views (#3815): resolve each card's own boardId for the deep
	// link; fall back to the single-board prop for the classic per-board timeline.
	let boardId = props.boardId
	if (props.groups) {
		const found = props.cards.find((c) => c.id === cardId)
		if (found && found.boardId != null) boardId = found.boardId
	}
	router.push({ name: 'card-modal', params: { id: String(boardId), cardId: String(cardId) } })
}

// Scroll the track so today's marker is centered (or as close as the range allows).
function jumpToToday() {
	const el = scrollRef.value
	if (!el) return
	nextTick(() => {
		const today = dayFloor(now.value)
		// Fall back to the axis origin if today isn't on the (padded) axis.
		const x = (axisStart.value !== null && today >= axisStart.value && today <= axisEnd.value)
			? xForMs(today)
			: LEFT_PAD
		const target = x - el.clientWidth / 2
		el.scrollTo({ left: Math.max(0, target), behavior: 'smooth' })
	})
}

// ── Drag-to-schedule ────────────────────────────────────────────────────────
// Unscheduled footer cards are Pragmatic-DnD draggables; the track is a drop
// target. Dropping a card sets its duedate to the day under the cursor, turning
// it into a single-date (diamond) card. Scoped to payload type
// 'timeline-unscheduled' so it never interferes with the board's own card/stack
// monitors (which use type 'card').

// Which footer card is being dragged (for the drag-visual on the source).
const draggingCardId = ref(null)
// x-offset (inside the inner track) of the drop guide line, or null when idle.
const dropGuideX = ref(null)
// Whether an unscheduled card is currently hovering the track (subtle highlight).
const dropActive = ref(false)

// Per-card draggable cleanups, keyed by card id. Rebuilt as the footer's ref
// callbacks fire; all torn down on unmount.
const unschedCleanups = new Map()

function boardKey() {
	return boardQueryKey(props.boardId)
}

// Optimistically patch the board summary cache with a new duedate for one card,
// then reconcile with the server. Mirrors useCardActions.setArchived: snapshot →
// patch → rollback-on-error → invalidate-on-settled. Only this card's duedate is
// touched, so concurrent in-flight mutations on other cards are never clobbered.
async function scheduleCard(cardId, dueMs) {
	if (!props.canEdit || axisStart.value === null) return
	const iso = new Date(dueMs).toISOString()
	const key = boardKey()
	const numericId = Number(cardId)

	await queryClient.cancelQueries({ queryKey: key })
	const previousBoard = queryClient.getQueryData(key)
	queryClient.setQueryData(key, (old) => {
		if (!old || !Array.isArray(old.cards)) return old
		return {
			...old,
			cards: old.cards.map((c) => (c.id === numericId ? { ...c, duedate: iso } : c)),
		}
	})

	try {
		await apiUpdateCard(numericId, { duedate: iso })
	} catch (e) {
		if (previousBoard !== undefined) queryClient.setQueryData(key, previousBoard)
	} finally {
		queryClient.invalidateQueries({ queryKey: key })
	}
}

// Compute the day-floor ms under a drag input (client coords) over the track.
function dropMsFromInput(clientX) {
	const inner = trackRef.value
	if (!inner) return null
	const rect = inner.getBoundingClientRect()
	return msForTrackX(clientX - rect.left)
}

// Update the visual guide to the day under the cursor while dragging over the track.
function updateGuide(clientX) {
	const inner = trackRef.value
	if (!inner || axisStart.value === null) { dropGuideX.value = null; return }
	const rect = inner.getBoundingClientRect()
	const ms = msForTrackX(clientX - rect.left)
	dropGuideX.value = ms === null ? null : xForMs(ms)
}

// Ref callback for each footer card button: (re)wire it as a draggable. Vue calls
// this with the element on mount/update and null on unmount.
function registerUnscheduledRef(cardId, el) {
	const prev = unschedCleanups.get(cardId)
	if (prev) { prev(); unschedCleanups.delete(cardId) }
	if (!el || !props.canEdit) return
	const cleanup = draggable({
		element: el,
		getInitialData: () => ({ type: 'timeline-unscheduled', cardId }),
		onDragStart: () => { draggingCardId.value = cardId },
		onDrop: () => { draggingCardId.value = null },
	})
	unschedCleanups.set(cardId, cleanup)
}

let trackDropCleanup = () => {}

// The inner track only renders when there are scheduled cards (the v-else body),
// so it can mount/unmount as the board's date coverage changes. Wire the drop
// target whenever the element appears and tear it down when it goes away.
watch(trackRef, (el) => {
	trackDropCleanup()
	trackDropCleanup = () => {}
	if (!el) return
	trackDropCleanup = dropTargetForElements({
		element: el,
		canDrop: ({ source }) => props.canEdit && source.data.type === 'timeline-unscheduled',
		onDrag: ({ location }) => {
			dropActive.value = true
			updateGuide(location.current.input.clientX)
		},
		onDragLeave: () => { dropActive.value = false; dropGuideX.value = null },
		onDrop: ({ source, location }) => {
			dropActive.value = false
			dropGuideX.value = null
			const cardId = source.data.cardId
			const dueMs = dropMsFromInput(location.current.input.clientX)
			if (cardId != null && dueMs !== null) scheduleCard(cardId, dueMs)
		},
	})
}, { immediate: true })

onBeforeUnmount(() => {
	trackDropCleanup()
	for (const cleanup of unschedCleanups.values()) cleanup()
	unschedCleanups.clear()
})
</script>

<style scoped>
.timeline {
	flex: 1;
	min-height: 0;
	display: flex;
	flex-direction: column;
	padding: 8px 24px 16px 52px;
	overflow: hidden;
}

.timeline__toolbar {
	display: flex;
	align-items: center;
	gap: 10px;
	margin-bottom: 8px;
}

.timeline__toolbar-label {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.timeline__zoom {
	display: inline-flex;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	overflow: hidden;
}

.timeline__zoom-btn {
	border: none;
	background: transparent;
	padding: 4px 12px;
	cursor: pointer;
	color: var(--color-main-text);
}

.timeline__zoom-btn--active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

/* ── Jump-to-today ── */
.timeline__today-btn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 32px;
	padding: 0 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-weight: 600;
	font-size: 0.8rem;
	white-space: nowrap;
	cursor: pointer;
}

.timeline__today-btn:hover:not(:disabled) {
	background: var(--color-background-hover);
}

.timeline__today-btn:disabled {
	opacity: 0.5;
	cursor: default;
}

/* ── Legend ── */
.timeline__legend {
	margin-inline-start: auto;
	display: flex;
	align-items: center;
	gap: 14px;
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	flex-wrap: wrap;
}

.timeline__legend-item {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	white-space: nowrap;
}

.timeline__legend-swatch {
	width: 20px;
	height: 8px;
	border-radius: 4px;
	flex: 0 0 auto;
}

.timeline__legend-swatch--not-started {
	background: var(--color-background-dark);
}

.timeline__legend-swatch--in-progress {
	background: var(--color-primary-element);
}

.timeline__legend-swatch--overdue {
	background: var(--color-error);
}

.timeline__legend-swatch--done {
	background: var(--color-success);
}

.timeline__legend-swatch--milestone {
	width: 10px;
	height: 10px;
	border-radius: 2px;
	background: var(--color-text-maxcontrast);
	transform: rotate(45deg);
}

.timeline__empty {
	flex: 1;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 12px;
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 24px;
}

.timeline__empty p {
	margin: 0;
	max-width: 420px;
}

/* Body = frozen pane + scrollable track, scrolling vertically as one unit. */
.timeline__body {
	flex: 1;
	min-height: 0;
	display: flex;
	align-items: stretch;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, var(--border-radius));
}

/* ── Frozen left pane ── */
.timeline__pane {
	flex: 0 0 300px;
	width: 300px;
	box-sizing: border-box;
	border-right: 1px solid var(--color-border-dark);
	position: sticky;
	left: 0;
	z-index: 4;
	background: var(--color-main-background);
}

.timeline__pane-head {
	position: sticky;
	top: 0;
	z-index: 1;
	height: 52px;
	display: flex;
	align-items: flex-end;
	padding: 0 16px 8px;
	box-sizing: border-box;
	font-size: 0.7rem;
	font-weight: 700;
	letter-spacing: 0.03em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	background: var(--color-main-background);
	border-bottom: 1px solid var(--color-border);
}

.timeline__group-row {
	height: 32px;
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 0 16px;
	box-sizing: border-box;
	background: var(--color-background-hover);
	border-bottom: 1px solid var(--color-border);
	cursor: pointer;
}

.timeline__group-row:hover {
	background: color-mix(in srgb, var(--color-background-hover) 60%, var(--color-background-dark));
}

.timeline__group-chevron {
	color: var(--color-text-maxcontrast);
	flex: 0 0 auto;
	display: inline-flex;
}

.timeline__group-dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background: var(--color-primary-element);
	flex: 0 0 auto;
}

.timeline__group-title {
	font-size: 0.75rem;
	font-weight: 700;
	letter-spacing: 0.03em;
	text-transform: uppercase;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.timeline__group-count {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	flex: 0 0 auto;
}

.timeline__pane-row {
	height: 36px;
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 0 16px;
	box-sizing: border-box;
	border-bottom: 1px solid var(--color-border);
	cursor: pointer;
}

.timeline__pane-row:hover {
	background: var(--color-background-hover);
}

.timeline__pane-status {
	flex: 0 0 auto;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	border: 1px solid var(--color-border-dark);
	box-sizing: border-box;
}

.timeline__pane-status--not_started { background: transparent; }
.timeline__pane-status--in_progress { background: var(--color-primary-element); border-color: var(--color-primary-element); }
.timeline__pane-status--done { background: var(--color-success, #2fb344); border-color: var(--color-success, #2fb344); }

.timeline__pane-id {
	flex: 0 0 auto;
	width: 62px;
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.72rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.timeline__pane-title {
	flex: 1;
	min-width: 0;
	font-size: 0.8rem;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.timeline__pane-assignees {
	display: inline-flex;
	flex: 0 0 auto;
}

.timeline__pane-assignees > * + * {
	margin-inline-start: -8px;
}

/* ── Scrollable track ── */
.timeline__scroll {
	flex: 1;
	min-width: 0;
	overflow-x: auto;
	overflow-y: hidden;
}

.timeline__inner {
	position: relative;
	min-height: 100%;
}

.timeline__weekend {
	position: absolute;
	top: 52px;
	bottom: 0;
	background: var(--color-background-hover);
	z-index: 0;
}

.timeline__grid {
	position: absolute;
	top: 52px;
	bottom: 0;
	width: 1px;
	background: var(--color-border);
	opacity: 0.5;
	z-index: 0;
}

.timeline__today {
	position: absolute;
	top: 48px;
	bottom: 0;
	width: 2px;
	background: var(--color-error);
	opacity: 0.7;
	z-index: 3;
}

/* A small cap at the top of the today line makes it read as a clear marker. */
.timeline__today::before {
	content: '';
	position: absolute;
	top: -4px;
	left: 50%;
	width: 8px;
	height: 8px;
	transform: translateX(-50%);
	border-radius: 50%;
	background: var(--color-error);
}

/* ── Two-tier axis ── */
.timeline__axis {
	position: sticky;
	top: 0;
	height: 52px;
	z-index: 2;
	background: var(--color-main-background);
	border-bottom: 1px solid var(--color-border);
}

.timeline__axis-months {
	position: relative;
	height: 24px;
}

.timeline__axis-month {
	position: absolute;
	top: 0;
	height: 24px;
	display: flex;
	align-items: center;
	padding-left: 8px;
	box-sizing: border-box;
	border-right: 1px solid var(--color-border);
	font-size: 0.75rem;
	font-weight: 700;
	white-space: nowrap;
	overflow: hidden;
}

.timeline__axis-ticks {
	position: relative;
	height: 28px;
}

.timeline__tick {
	position: absolute;
	top: 6px;
	font-size: 0.72rem;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	padding-left: 6px;
}

/* ── Rows ── */
.timeline__group-track {
	position: relative;
	height: 32px;
	background: color-mix(in srgb, var(--color-background-hover) 70%, transparent);
	border-bottom: 1px solid var(--color-border);
	z-index: 1;
}

.timeline__lane {
	position: relative;
	height: 36px;
	box-sizing: border-box;
	border-bottom: 1px solid var(--color-border);
	cursor: pointer;
	z-index: 1;
}

.timeline__lane:hover .timeline__bar,
.timeline__lane:hover .timeline__milestone {
	filter: brightness(1.08);
}

.timeline__bar {
	position: absolute;
	top: 6px;
	height: 24px;
	border-radius: 6px;
	/* Default = scheduled but not started (muted). */
	background: var(--color-background-dark);
	color: var(--color-main-text);
	display: flex;
	align-items: center;
	padding: 0 8px;
	overflow: hidden;
	box-sizing: border-box;
}

.timeline__bar--started {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.timeline__bar--done {
	background: var(--color-success);
	color: var(--color-primary-element-text);
}

.timeline__bar--overdue {
	background: var(--color-error);
	color: var(--color-primary-element-text);
}

.timeline__milestone {
	position: absolute;
	top: 10px;
	width: 16px;
	height: 16px;
	transform: translateX(-8px) rotate(45deg);
	background: var(--color-text-maxcontrast);
	border-radius: 3px;
}

.timeline__milestone.timeline__bar--started {
	background: var(--color-primary-element);
}

.timeline__milestone.timeline__bar--done {
	background: var(--color-success);
}

.timeline__milestone.timeline__bar--overdue {
	background: var(--color-error);
}

.timeline__label {
	font-size: 0.8rem;
	font-weight: 500;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

/* Title rendered beside a too-narrow bar (see LABEL_MIN_WIDTH). */
.timeline__bar-outside-label {
	position: absolute;
	top: 50%;
	transform: translateY(-50%);
	max-width: 240px;
	font-size: 0.8rem;
	color: var(--color-main-text);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	pointer-events: none;
}

.timeline__label--after {
	position: absolute;
	left: 14px;
	top: 50%;
	transform: translateY(-50%) rotate(-45deg);
	transform-origin: left center;
	color: var(--color-main-text);
}

.timeline__unscheduled {
	margin-top: 12px;
	flex: 0 0 auto;
}

.timeline__unscheduled summary {
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.timeline__unscheduled-hint {
	margin-inline-start: 8px;
	font-weight: 400;
	font-size: 0.78rem;
	opacity: 0.85;
}

.timeline__unscheduled-list {
	list-style: none;
	padding: 8px 0 0;
	margin: 0;
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.timeline__unscheduled-row {
	border: 1px solid var(--color-border);
	background: transparent;
	border-radius: var(--border-radius);
	padding: 4px 10px;
	cursor: pointer;
	color: var(--color-main-text);
}

.timeline__unscheduled-row:hover {
	background: var(--color-background-hover);
}

/* Draggable footer card: grab cursor + a faint dashed edge hinting it can be
 * picked up and dropped onto the track to schedule it. */
.timeline__unscheduled-row--draggable {
	cursor: grab;
	border-style: dashed;
}

.timeline__unscheduled-row--draggable:active {
	cursor: grabbing;
}

.timeline__unscheduled-row--dragging {
	opacity: 0.4;
}

/* Subtle track highlight while an unscheduled card hovers over it. */
.timeline__inner--drop-active {
	background: color-mix(in srgb, var(--color-primary-element) 6%, transparent);
}

/* Vertical guide at the day the card would land on. */
.timeline__drop-guide {
	position: absolute;
	top: 52px;
	bottom: 0;
	width: 2px;
	background: var(--color-primary-element);
	opacity: 0.9;
	z-index: 5;
	pointer-events: none;
}

.timeline__drop-guide::before {
	content: '';
	position: absolute;
	top: -3px;
	left: 50%;
	width: 9px;
	height: 9px;
	transform: translateX(-50%) rotate(45deg);
	background: var(--color-primary-element);
}
</style>
