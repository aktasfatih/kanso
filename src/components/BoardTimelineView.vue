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
					:class="{ 'timeline__zoom-btn--active': zoom === z.key && !fitAll }"
					@click="zoom = z.key; fitAll = false">
					{{ z.label }}
				</button>
				<button
					class="timeline__zoom-btn timeline__zoom-btn--fit"
					:class="{ 'timeline__zoom-btn--active': fitAll }"
					:aria-pressed="fitAll"
					@click="fitAll = !fitAll">
					{{ t('kanso', 'Fit') }}
				</button>
			</div>
			<button
				class="timeline__today-btn"
				:disabled="scheduledRows.length === 0"
				@click="jumpToToday">
				<CalendarTodayIcon :size="16" />
				{{ t('kanso', 'Jump to today') }}
			</button>
			<!-- Dependency arrows toggle (#5896): only offered when the board
			     actually has blocks relations, so a board without dependencies
			     keeps the toolbar unchanged. -->
			<button
				v-if="dependencies.length > 0 && scheduledRows.length > 0"
				class="timeline__today-btn timeline__deps-btn"
				:class="{ 'timeline__deps-btn--active': showDeps }"
				:aria-pressed="showDeps"
				@click="showDeps = !showDeps">
				<ArrowRightBottomIcon :size="16" />
				{{ t('kanso', 'Dependencies') }}
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
		     horizontally-scrollable track (two-tier axis + bars). The BODY is the
		     one and only scroll container, on both axes (#9858): the pane freezes
		     with `position: sticky; left: 0` and the axis pins with
		     `position: sticky; top: 0`, both against that single scrollport, so a
		     bar always stays aligned with its row in the frozen pane. The track
		     wrapper must NOT carry overflow of its own — that would make it a
		     scroll container, which sizes to the flex line instead of its content
		     and clips every row below the fold. -->
		<div v-else ref="bodyRef" class="timeline__body">
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

			<!-- Track wrapper: no overflow of its own (see .timeline__scroll), it
			     only measures the track's visible width. -->
			<div ref="scrollRef" class="timeline__scroll">
				<div ref="trackRef" class="timeline__inner" :class="{ 'timeline__inner--drop-active': dropActive }" :style="{ width: `${trackWidth}px` }">
					<!-- Clipped-edge affordances (#4129): when a card's real date range
					     reaches beyond the rendered window, flag it at the track edge so
					     the outlier is indicated, not silently dropped. Sticky so they
					     stay pinned to the viewport edges as the body scrolls. In Fit
					     mode the window == full extent so these are never shown.
					     They live INSIDE the inner track deliberately (#9858): a sticky
					     box can only travel within its containing block, and only the
					     inner track spans the whole scrollable width AND height — pinned
					     to the wrapper instead, they'd slide out of view long before
					     either end of the scroll range. -->
					<div
						v-if="extendsEarlier"
						class="timeline__edge timeline__edge--start"
						:title="t('kanso', 'Show the full date range')"
						:aria-label="t('kanso', 'Some cards extend earlier than shown')"
						role="button"
						tabindex="0"
						@click="fitAll = true"
						@keydown.enter.prevent="fitAll = true"
						@keydown.space.prevent="fitAll = true">
						<ChevronLeftIcon :size="16" />
					</div>
					<div
						v-if="extendsLater"
						class="timeline__edge timeline__edge--end"
						:title="t('kanso', 'Show the full date range')"
						:aria-label="t('kanso', 'Some cards extend later than shown')"
						role="button"
						tabindex="0"
						@click="fitAll = true"
						@keydown.enter.prevent="fitAll = true"
						@keydown.space.prevent="fitAll = true">
						<ChevronRightIcon :size="16" />
					</div>
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
							:ref="(el) => registerLaneRef(row.card.id, el)"
							class="timeline__lane"
							role="button"
							tabindex="0"
							:title="row.card.title"
							:aria-label="row.card.title"
							@click="openCard(row.card.id)"
							@keydown.enter.prevent="openCard(row.card.id)"
							@keydown.space.prevent="openCard(row.card.id)"
							@mouseenter="hoverLane(row.card.id)"
							@mouseleave="hoverLane(null)">
							<!-- Off-window rows: render an edge marker instead of a (misleading) clipped bar -->
							<template v-if="row.offBefore || row.offAfter">
								<div
									class="timeline__bar-offwindow"
									:class="row.offBefore ? 'timeline__bar-offwindow--start' : 'timeline__bar-offwindow--end'"
									:title="t('kanso', 'Outside the visible range — click Fit to show')"
									@click.stop="fitAll = true">
									<ChevronLeftIcon v-if="row.offBefore" :size="12" />
									<ChevronRightIcon v-else :size="12" />
								</div>
							</template>
							<template v-else>
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
							</template>
						</div>
					</template>

					<!-- Dependency arrows (#5896). An SVG overlay that lives INSIDE the
					     inner track, so it shares the track's coordinate space: it pans
					     with horizontal scroll and scrolls with the body for free.
					     Accessibility: aria-hidden and nothing here is focusable — the
					     authoritative dependency list lives in the card's relations
					     section, and the click below is a mouse-only shortcut for what
					     the blocked card's own lane (a focusable button) already does
					     with Enter, so no affordance is keyboard-only reachable here. -->
					<svg
						v-if="dependencyArrows.length > 0"
						class="timeline__deps"
						aria-hidden="true"
						focusable="false">
						<g
							v-for="arrow in dependencyArrows"
							:key="arrow.key"
							class="timeline__dep"
							:class="{
								'timeline__dep--violated': arrow.violated,
								'timeline__dep--active': hoveredCardId === arrow.from || hoveredCardId === arrow.to,
							}"
							@click.stop="openCard(arrow.to)">
							<title>{{ arrow.title }}</title>
							<path class="timeline__dep-hit" :d="arrow.hit" />
							<path class="timeline__dep-line" :d="arrow.d" />
							<path class="timeline__dep-head" :d="arrow.head" />
						</g>
					</svg>
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
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount, getCurrentInstance } from 'vue'
import { useRouter } from 'vue-router'
import { useQueryClient } from '@tanstack/vue-query'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import CalendarBlankOutlineIcon from 'vue-material-design-icons/CalendarBlankOutline.vue'
import CalendarTodayIcon from 'vue-material-design-icons/CalendarToday.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChevronLeftIcon from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import ArrowRightBottomIcon from 'vue-material-design-icons/ArrowRightBottom.vue'
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
	/**
	 * Board-scoped `blocks` dependency edges (#5896): `[{from, to}]` where card
	 * `from` blocks card `to`. Rides the board payload (`blocksEdges`), already
	 * visibility-masked server-side — an edge touching a card the viewer cannot
	 * see never arrives here at all.
	 *
	 * Board-scoped ONLY, deliberately: a cross-board View (#3815) never passes
	 * this. Relations are same-board, and that mode can surface ONE card in
	 * several groups (group-by-assignee), which the by-card-id lane bookkeeping
	 * below would collapse. Leave it empty there rather than draw a wrong arrow.
	 */
	dependencies: { type: Array, default: () => [] },
})

const emit = defineEmits(['open'])

// When a parent handles `@open` (a cross-board View owns a card-detail overlay,
// #3950), emit the card up and let the parent open it in place — do NOT route to
// card-modal (a child of the board route, which would swap the whole View out for
// the board). Inside an actual board no `@open` is attached, so the classic
// router.push deep-link is preserved unchanged.
// A parent's `@open` presence is fixed at mount (it never toggles at runtime), so
// this is a one-off static read, not a reactive dependency.
const hasOpenHandler = !!getCurrentInstance()?.vnode?.props?.onOpen

const router = useRouter()
const queryClient = useQueryClient()

// The body — the single scroll container, on both axes (#9858). Jump-to-today
// drives this one.
const bodyRef = ref(null)
// The track wrapper. No longer scrolls itself, but its width IS the track's
// visible width (the body's minus the frozen pane), so it stays the thing we
// measure for page-fill sizing and for centering jump-to-today.
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

// Fit mode: shows the full data extent in one viewport-sized view on demand.
// When true, windowStart/windowEnd span the entire data domain; pxPerDay is
// auto-calculated to fit that span in the viewport; ticks/labels coarsen to
// the 'month' density to stay readable at very small px/day.
const fitAll = ref(false)

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
				// Row heights are fixed, but a resize can still reflow the track
				// (and is the cheapest hook for a font/zoom change) - re-measure so
				// the dependency arrows stay glued to their bars (#5896). Coalesced
				// into one rAF so a resize DRAG doesn't read layout every frame, and
				// a no-op measurement publishes nothing (see measureLanes).
				scheduleMeasure()
			})
			resizeObserver.observe(scrollRef.value)
		}
	}
	measureLanes()
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
	if (measureFrame !== 0) {
		cancelAnimationFrame(measureFrame)
		measureFrame = 0
	}
	laneEls.clear()
})
const zoomCfg = computed(() => ZOOMS.find((z) => z.key === zoom.value) ?? ZOOMS[1])

// Effective zoom for DISPLAY purposes (ticks, labels, weekends). In Fit mode we
// coarsen everything to 'month' density regardless of which zoom button is active,
// so we never blow up with hundreds of tick/weekend nodes when px/day is tiny.
const effZoom = computed(() => fitAll.value ? 'month' : zoom.value)
const effZoomCfg = computed(() => ZOOMS.find((z) => z.key === effZoom.value) ?? ZOOMS[2])

const LEFT_PAD = 8
// Below this bar width the in-bar title clips to nothing, so it renders beside the bar.
const LABEL_MIN_WIDTH = 60

// Rendered-window bounds (#4129). A single card spanning many years (e.g.
// 2018→2030) would otherwise blow up totalDays → an enormous trackWidth and huge
// per-day grid/tick/weekend node counts, janking the whole view. We therefore
// RENDER only a fixed window around today (a few months back, a year forward)
// while still anchoring bar positions to the real data extent — outlier bars land
// off-track and are clipped by the scroll container's overflow, and a small
// edge marker signals that cards extend beyond the visible window. This is a
// display limit, not a data cut: no card is dropped, and normal boards whose
// data already fits inside the window render byte-identical (the fallback below).
//
// In Fit mode (fitAll=true) the window expands to the full data extent, letting
// the user see all bars at once. pxPerDay is auto-scaled so the whole span fits
// the viewport — keeping node counts bounded even for multi-year spans.
const WINDOW_BACK_MONTHS = 6
const WINDOW_FORWARD_MONTHS = 12

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

// Day-floored ms of `today` shifted by a whole number of calendar months. Used
// to derive the rendered window edges from today.
function monthShiftedDay(months) {
	const d = new Date(now.value)
	d.setMonth(d.getMonth() + months)
	d.setHours(0, 0, 0, 0)
	return d.getTime()
}

// Rendered window (#4129): the slice of time actually painted (axis, grid, ticks,
// weekends, track width). Defaults to today − BACK … today + FORWARD months, but
// CLAMPS to the real data extent so we never render empty track beyond where any
// card reaches. Crucially, when the whole data extent already fits inside the
// default window we fall through to the raw bounds unchanged — so small/normal
// boards render exactly as before (no behaviour change for the common case).
//
// In Fit mode: window == full data extent so all bars are always on-screen.
const windowStart = computed(() => {
	if (dataStart.value === null) return null
	if (fitAll.value) return dataStart.value
	const back = monthShiftedDay(-WINDOW_BACK_MONTHS)
	// Don't extend earlier than the data actually starts (unless today itself is
	// earlier — then keep today visible), and don't start later than that clamp.
	const today = dayFloor(now.value)
	const earliest = Math.min(dataStart.value, today)
	return Math.max(earliest, back)
})
const windowEnd = computed(() => {
	if (dataEnd.value === null) return null
	if (fitAll.value) return dataEnd.value
	const forward = monthShiftedDay(WINDOW_FORWARD_MONTHS)
	const today = dayFloor(now.value)
	const latest = Math.max(dataEnd.value, today)
	return Math.min(latest, forward)
})

// Rendered axis origin. Anchored to the window start (bars are positioned via
// xForMs() off this origin); outlier cards that start before the window get a
// negative x and are clipped by the scroll container's overflow, rather than
// stretching the track across their full raw span.
const axisStart = computed(() => windowStart.value)

// In Fit mode, pxPerDay is auto-calculated so the whole span fits within the
// viewport (bounded to be non-trivially small but never exceeds the active zoom's
// natural pxPerDay). In normal mode, uses the active zoom's pxPerDay unchanged.
const pxPerDay = computed(() => {
	if (fitAll.value && windowStart.value !== null && windowEnd.value !== null && viewportWidth.value > 0) {
		const days = Math.round((windowEnd.value - windowStart.value) / DAY) + 1
		const fit = (viewportWidth.value - LEFT_PAD * 2) / days
		return Math.min(Math.max(fit, 0.25), zoomCfg.value.pxPerDay)
	}
	return zoomCfg.value.pxPerDay
})

// The date range must cover the viewport even when the window is short: we pad
// trailing empty days so the track never looks truncated. Bounded by the rendered
// window, NOT the raw data domain, so an outlier date can't inflate the node count.
const totalDays = computed(() => {
	if (axisStart.value === null) return 0
	// Span of the rendered window (already clamped to today on both edges above).
	const windowDays = Math.round((windowEnd.value - axisStart.value) / DAY) + 1
	// Days that fit in the current viewport, minus the two side pads.
	const fitDays = viewportWidth.value > 0
		? Math.ceil((viewportWidth.value - LEFT_PAD * 2) / pxPerDay.value)
		: 0
	return Math.max(windowDays, fitDays)
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
//
// In non-Fit mode: rows entirely outside the visible window get offBefore/offAfter
// flags instead of bar geometry, so they render as edge markers (not misleading
// slivers). In Fit mode window==full extent so offBefore/offAfter are always false.
const groups = computed(() => {
	if (axisStart.value === null) return []
	return grouped.value.groups.map((g) => {
		const isColl = isCollapsed(g.stack.id)
		const winStart = axisStart.value
		const winEnd = axisEnd.value
		const rows = isColl ? [] : g.rows.map((r) => {
			const isMilestone = r.startMs === r.endMs
			const overdue = !r.done && r.endMs < now.value

			// Detect rows fully outside the rendered window (#4129 fix): these must
			// not render a clipped bar (which would look like a 1-day sliver at the
			// edge), but instead show a small clickable arrow marker.
			const offBefore = r.endMs < winStart
			const offAfter = r.startMs > winEnd
			if (offBefore || offAfter) {
				return { ...r, offBefore, offAfter, isMilestone, overdue, left: 0, width: 0, labelInside: false }
			}

			// Clip the rendered bar geometry to the visible window (#4129). A card
			// whose real range reaches outside the window would otherwise produce a
			// giant absolutely-positioned node that re-inflates the scroll width —
			// so we render only the on-window slice. done/overdue/milestone are still
			// derived from the RAW range, so the bar's meaning is unchanged; only its
			// painted extent is capped, and the edge markers signal the overflow.
			const visStart = Math.max(r.startMs, winStart)
			const visEnd = Math.min(r.endMs, winEnd)
			const left = xForMs(visStart)
			const width = isMilestone ? 0 : Math.max((Math.round((visEnd - visStart) / DAY) + 1) * pxPerDay.value, pxPerDay.value)
			const labelInside = !isMilestone && width >= LABEL_MIN_WIDTH
			return { ...r, offBefore: false, offAfter: false, left, width, isMilestone, overdue, labelInside }
		})
		return { stack: g.stack, rows, count: g.rows.length }
	})
})

// ── Dependency arrows (#5896) ───────────────────────────────────────────────
// A `blocks` relation is only a red chip on the card tile today; on the
// timeline — where sequencing is the whole point — it was invisible. We draw
// each edge as an orthogonal (elbow) connector leaving the RIGHT edge of the
// blocker and entering the LEFT edge of the blocked card, arrowhead on the
// blocked end.
//
// Coordinates are track-local: x comes straight from the same `left`/`width`
// the bars are painted with (so horizontal scroll and Fit need no extra work —
// the SVG lives inside .timeline__inner and moves with it), y is MEASURED off
// each lane's offsetTop rather than derived from CSS constants, so the lines
// stay glued to their bars even if row metrics change. The x side therefore
// recomputes with `groups` (zoom, resize, collapse, and the minute clock tick
// that refreshes `overdue`); the y side re-measures only when the ROW SET moves.

/** Whether arrows are drawn at all (toolbar toggle; on by default). */
const showDeps = ref(true)
/** Card id of the lane under the pointer - highlights the arrows touching it. */
const hoveredCardId = ref(null)

// True only when arrows can actually be on screen. Every hook below short-circuits
// on it, so a board with no `blocks` relations (the common case) pays NOTHING for
// this feature: no layout reads, and - via hoverLane() - no hover-driven re-render
// of the whole track either.
const depsLive = computed(() => showDeps.value && props.dependencies.length > 0)

// Lane hover feeds the arrow highlight only, so it must not touch reactive state
// when there are no arrows - otherwise every row hover on every board would
// re-render the entire (absolutely-positioned, node-heavy) track.
function hoverLane(cardId) {
	if (!depsLive.value) {
		if (hoveredCardId.value !== null) hoveredCardId.value = null
		return
	}
	hoveredCardId.value = cardId
}

// Horizontal stub off each bar before the connector turns - keeps the line from
// growing straight out of the bar's edge pixel.
const ARROW_STUB = 10
// Horizontal reach of an arrow's hit region, measured LEFT from the arrowhead.
const ARROW_HIT_REACH = 18
// Half-width of a milestone diamond: a 16px square rotated 45deg spans
// 16 * sqrt(2) / 2 across, and translateX(-8px) centres it on `left` - so its
// visual tips sit at left ± 11.3, not left ± 8.
const MILESTONE_HALF = 11.3
// Density guard: a pathological board can't be allowed to paint unbounded SVG
// nodes. Edges beyond this simply aren't drawn (the card chips still carry the
// information, and the relations panel is authoritative).
const MAX_ARROWS = 400

// Live lane elements, keyed by card id, plus their measured box. Measuring is
// cheap (one offsetTop read per rendered row) and only re-runs when the row SET
// changes - not on scroll, and not on the every-minute clock tick.
const laneEls = new Map()
const laneBoxes = ref(new Map())

function registerLaneRef(cardId, el) {
	if (el) laneEls.set(cardId, el)
	else laneEls.delete(cardId)
}

function measureLanes() {
	// Layout reads are only worth taking when something will be drawn with them.
	if (!depsLive.value) return
	const next = new Map()
	for (const [cardId, el] of [...laneEls]) {
		if (!el || !el.isConnected) {
			laneEls.delete(cardId)
			continue
		}
		next.set(cardId, { top: el.offsetTop, height: el.offsetHeight })
	}
	// Only publish a NEW map when the geometry actually moved: assigning an
	// identical map would invalidate dependencyArrows on every resize frame.
	const current = laneBoxes.value
	if (current.size === next.size) {
		let same = true
		for (const [cardId, box] of next) {
			const was = current.get(cardId)
			if (!was || was.top !== box.top || was.height !== box.height) { same = false; break }
		}
		if (same) return
	}
	laneBoxes.value = next
}

// rAF-coalesced measurement for the high-frequency hook (ResizeObserver), so a
// resize drag reads layout once per frame at most.
let measureFrame = 0
function scheduleMeasure() {
	if (!depsLive.value || measureFrame !== 0) return
	measureFrame = requestAnimationFrame(() => {
		measureFrame = 0
		measureLanes()
	})
}

// The rendered row set (collapsed groups contribute nothing), as a signature -
// a change here is exactly when the y-geometry can have moved.
const laneSignature = computed(() =>
	groups.value.map((g) => `${g.stack.id}:${g.rows.map((r) => r.card.id).join(',')}`).join('|'),
)
// flush: 'post' measures INSIDE the same flush that re-rendered the rows, so the
// arrows never paint one frame against the pre-collapse geometry. `depsLive` is
// watched too: flipping the toggle on must (re)measure before anything is drawn.
watch([laneSignature, depsLive], measureLanes, { flush: 'post' })

// Rendered rows by card id - the lookup both arrow endpoints must resolve
// through. A card that is unscheduled, filtered out, or inside a collapsed
// group is simply absent, which is how those cases drop out below.
const rowByCardId = computed(() => {
	const map = new Map()
	for (const g of groups.value) {
		for (const row of g.rows) map.set(row.card.id, row)
	}
	return map
})

/**
 * Elbow path from the blocker's right edge to the blocked card's left edge.
 * With room between them it's the classic 3-segment stub → vertical → entry.
 * When the target starts left of the source's end (the overlapping / violated
 * case) there is no room, so it routes around through the row boundary between
 * the two lanes instead of doubling back over the bars.
 */
function elbowPath(x1, y1, x2, y2, sourceHeight) {
	if (x2 - ARROW_STUB >= x1 + ARROW_STUB) {
		return `M${x1},${y1} L${x1 + ARROW_STUB},${y1} L${x1 + ARROW_STUB},${y2} L${x2},${y2}`
	}
	// Turn at the boundary between the two rows (half a row past the source,
	// off the MEASURED row height), so the detour never crosses either bar.
	const midY = y1 + (y2 >= y1 ? 1 : -1) * (sourceHeight / 2)
	return `M${x1},${y1} L${x1 + ARROW_STUB},${y1} L${x1 + ARROW_STUB},${midY}`
		+ ` L${x2 - ARROW_STUB},${midY} L${x2 - ARROW_STUB},${y2} L${x2},${y2}`
}

/** Solid arrowhead at the blocked end, always pointing right (into the bar). */
function headPath(x, y) {
	return `M${x},${y} L${x - 7},${y - 4.5} L${x - 7},${y + 4.5} Z`
}

/**
 * The connector's pointer target: deliberately JUST the final entry segment, not
 * the whole path. A fat hit-stroke along the full elbow would sit above every
 * lane the vertical run crosses and steal those bars' clicks. This short stub
 * lives inside the BLOCKED card's own lane, immediately left of its bar - a lane
 * holds exactly one bar, so nothing clickable can hide under it, and the lane's
 * own click resolves to the same card anyway.
 */
function hitPath(x, y) {
	return `M${x - ARROW_HIT_REACH},${y} L${x},${y}`
}

const dependencyArrows = computed(() => {
	if (!depsLive.value || axisStart.value === null) return []
	const rows = rowByCardId.value
	const boxes = laneBoxes.value
	const out = []
	for (const edge of props.dependencies) {
		if (out.length >= MAX_ARROWS) break
		const fromId = Number(edge?.from)
		const toId = Number(edge?.to)
		const from = rows.get(fromId)
		const to = rows.get(toId)
		// Either endpoint undated / filtered / in a collapsed group → no arrow.
		// The blocked chip on the card tile remains the fallback signal.
		if (!from || !to) continue
		// Off-window endpoints render as a track-edge marker rather than a bar
		// (#4129). We deliberately SUPPRESS the arrow instead of anchoring it to
		// that marker: a line to a chevron pinned at the viewport edge points at
		// a position that carries no date meaning. Fit mode brings both bars back
		// on-window and the arrow returns.
		if (from.offBefore || from.offAfter || to.offBefore || to.offAfter) continue
		const fromBox = boxes.get(fromId)
		const toBox = boxes.get(toId)
		if (!fromBox || !toBox) continue

		// Anchor to the RENDERED geometry (bars are clipped to the window), so a
		// line never points into empty track.
		const x1 = from.isMilestone ? from.left + MILESTONE_HALF : from.left + from.width
		const x2 = to.isMilestone ? to.left - MILESTONE_HALF : to.left
		const y1 = fromBox.top + fromBox.height / 2
		const y2 = toBox.top + toBox.height / 2

		// The payoff: the blocked card starts before its blocker finishes, so the
		// plan contradicts the dependency. Judged on the RAW dates, never the
		// clipped drawing.
		const violated = to.startMs < from.endMs
		out.push({
			key: `${fromId}-${toId}`,
			from: fromId,
			to: toId,
			d: elbowPath(x1, y1, x2, y2, fromBox.height),
			head: headPath(x2, y2),
			hit: hitPath(x2, y2),
			violated,
			title: violated
				? t('kanso', '{blocker} blocks {blocked} — {blocked} starts before {blocker} finishes', {
					blocker: from.card.title,
					blocked: to.card.title,
				})
				: t('kanso', '{blocker} blocks {blocked}', {
					blocker: from.card.title,
					blocked: to.card.title,
				}),
		})
	}
	return out
})

// Dynamic tick step in Fit mode: ensure labels stay readable when px/day is tiny.
// At very small px/day values a step of 1 or 7 would produce hundreds of ticks;
// we coarsen to at least ~80px apart. Outside Fit, use the zoom's natural stepDays.
const tickStepDays = computed(() => {
	const base = effZoomCfg.value.stepDays
	if (fitAll.value && pxPerDay.value > 0) {
		return Math.max(base, Math.ceil(80 / pxPerDay.value))
	}
	return base
})

const ticks = computed(() => {
	if (axisStart.value === null) return []
	const out = []
	const step = tickStepDays.value
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
// each weekend day; coarser zooms (or Fit mode) would produce hairlines, so it's
// day-zoom only when NOT in Fit mode. effZoom coarsens to 'month' in Fit so
// weekends are automatically disabled there.
const weekendBands = computed(() => {
	if (axisStart.value === null || effZoom.value !== 'day') return []
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

// Clipped-edge affordances (#4129): true when a card's real extent reaches beyond
// the rendered window, so we can flag "cards extend earlier/later" at the track
// edge instead of silently hiding those outliers. Always false in Fit mode (the
// window is the full extent so nothing is ever outside).
const extendsEarlier = computed(() =>
	!fitAll.value && dataStart.value !== null && windowStart.value !== null && dataStart.value < windowStart.value,
)
const extendsLater = computed(() =>
	!fitAll.value && dataEnd.value !== null && windowEnd.value !== null && dataEnd.value > windowEnd.value,
)

function labelForMs(ms) {
	const d = new Date(ms)
	if (effZoom.value === 'month') return d.toLocaleDateString(undefined, { month: 'short', year: '2-digit' })
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
	// A parent-owned overlay (cross-board View, #3950) takes precedence: hand the
	// card up and stay on the View instead of navigating to its board.
	if (hasOpenHandler) {
		const card = props.cards.find((c) => c.id === cardId)
		if (card) emit('open', card)
		return
	}
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
// The BODY is the horizontal scroller (#9858), but the visible width of the track
// is the wrapper's — the body's own clientWidth also spans the frozen pane, which
// would push the centering 150px off.
function jumpToToday() {
	const body = bodyRef.value
	const track = scrollRef.value
	if (!body || !track) return
	nextTick(() => {
		const today = dayFloor(now.value)
		// Fall back to the axis origin if today isn't on the (padded) axis.
		const x = (axisStart.value !== null && today >= axisStart.value && today <= axisEnd.value)
			? xForMs(today)
			: LEFT_PAD
		const target = x - track.clientWidth / 2
		body.scrollTo({ left: Math.max(0, target), behavior: 'smooth' })
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

.timeline__zoom-btn--fit {
	border-left: 1px solid var(--color-border);
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

/* Body = frozen pane + track, and the SINGLE scroll container for both axes
 * (#9858). Vertical AND horizontal scrolling both belong here: it is what makes
 * the sticky pane (left) and the sticky axis (top) pin against the same
 * scrollport, and what lets the track grow to its full row height instead of
 * being clipped to one screenful. */
.timeline__body {
	flex: 1;
	min-height: 0;
	display: flex;
	align-items: stretch;
	overflow: auto;
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
	/* Size to the rows, but never shorter than the frame (#9858): `stretch` would
	 * tie the pane to the flex line, and the pane is what carries `.timeline__pane-head`'s
	 * sticky top — it must be a box that spans the whole scrollable height. */
	align-self: flex-start;
	height: max-content;
	min-height: 100%;
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

/* ── Track wrapper ── */
/* Deliberately overflow-FREE (#9858). It used to carry `overflow-x: auto`, which
 * made it a scroll container: a scroll container is sized by its flex line and
 * clips its content, so the track ended one screenful down while the pane kept
 * listing rows. Now it just shrinks to the space left of the pane (so its
 * clientWidth is still the track's visible width, which `viewportWidth` measures)
 * and lets `.timeline__inner` overflow into the body's scroll area. */
.timeline__scroll {
	position: relative;
	flex: 1;
	min-width: 0;
}

.timeline__inner {
	position: relative;
	min-height: 100%;
}

/* Clipped-edge affordance (#4129): a small chevron pinned to the viewport edge of
 * the scroll container, shown when cards extend beyond the rendered window. Sticky
 * keeps it fixed at the edge as the body scrolls; margin-bottom: -28px collapses
 * its vertical footprint so it overlays the track rather than pushing the axis
 * down. Clickable to activate Fit mode. */
.timeline__edge {
	position: sticky;
	top: 60px;
	z-index: 6;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 22px;
	height: 28px;
	margin-bottom: -28px;
	border-radius: var(--border-radius);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	opacity: 0.9;
	cursor: pointer;
}

.timeline__edge:hover {
	opacity: 1;
}

/* The scrollport's left edge is now the body's, and its first 300px are covered by
 * the frozen pane (#9858) — so this offset clears the pane rather than hiding
 * under it. */
.timeline__edge--start {
	left: 304px;
	float: left;
}

.timeline__edge--end {
	right: 4px;
	float: right;
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
	/* Above the dependency-arrow overlay (also z-index 3, but later in DOM order,
	 * so it would otherwise paint over this marker). */
	z-index: 4;
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

/* Off-window row marker: shown instead of a clipped bar when a card falls
 * entirely before (start edge) or after (end edge) the rendered window.
 * Clickable to turn on Fit mode and reveal the full date range. */
.timeline__bar-offwindow {
	position: absolute;
	top: 8px;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 20px;
	height: 20px;
	border-radius: var(--border-radius);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	opacity: 0.7;
	cursor: pointer;
	z-index: 2;
}

.timeline__bar-offwindow:hover {
	opacity: 1;
}

.timeline__bar-offwindow--start {
	left: 2px;
}

.timeline__bar-offwindow--end {
	right: 2px;
}

/* ── Dependency arrows (#5896) ──
 * An overlay spanning the whole inner track. Deliberately lighter in visual
 * weight than the bars: thin muted strokes that read as annotation, not data.
 * The overlay itself is click-through; only the connectors take the pointer. */
.timeline__deps {
	position: absolute;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	overflow: visible;
	z-index: 3;
	pointer-events: none;
}

.timeline__dep {
	pointer-events: auto;
	cursor: pointer;
}

/* Fat invisible stroke so a 1.5px line is still comfortably hoverable/clickable.
 * Painted only over the short entry stub (see hitPath) — never along the whole
 * elbow, whose vertical run crosses other lanes and would steal their clicks.
 * 14px keeps it well inside the 36px lane it belongs to. */
.timeline__dep-hit {
	fill: none;
	stroke: transparent;
	stroke-width: 14;
	pointer-events: stroke;
}

.timeline__dep-line {
	fill: none;
	stroke: var(--color-border-dark);
	stroke-width: 1.5;
	stroke-linejoin: round;
	pointer-events: none;
}

.timeline__dep-head {
	fill: var(--color-border-dark);
	pointer-events: none;
}

/* Violated dependency: the blocked card starts before its blocker finishes. */
.timeline__dep--violated .timeline__dep-line {
	stroke: var(--color-error);
	stroke-width: 2;
}

.timeline__dep--violated .timeline__dep-head {
	fill: var(--color-error);
}

/* Hovering either bar (or the connector itself) brings its arrows forward. */
.timeline__dep--active .timeline__dep-line,
.timeline__dep:hover .timeline__dep-line {
	stroke: var(--color-primary-element);
	stroke-width: 2.5;
}

.timeline__dep--active .timeline__dep-head,
.timeline__dep:hover .timeline__dep-head {
	fill: var(--color-primary-element);
}

.timeline__dep--violated.timeline__dep--active .timeline__dep-line,
.timeline__dep--violated:hover .timeline__dep-line {
	stroke: var(--color-error);
	stroke-width: 3;
}

.timeline__dep--violated.timeline__dep--active .timeline__dep-head,
.timeline__dep--violated:hover .timeline__dep-head {
	fill: var(--color-error);
}

.timeline__deps-btn--active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-color: var(--color-primary-element);
}

.timeline__deps-btn--active:hover:not(:disabled) {
	background: var(--color-primary-element-hover, var(--color-primary-element));
	color: var(--color-primary-element-text);
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
