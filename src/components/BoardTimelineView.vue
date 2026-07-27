<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="timeline">
		<!-- Zoom control -->
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
		</div>

		<p v-if="scheduled.length === 0" class="timeline__empty">
			{{ t('kanso', 'No cards have a start or due date yet. Add dates on a card to place it on the timeline.') }}
		</p>

		<!-- Scrollable timeline: sticky date axis + one lane per scheduled card. -->
		<div v-else class="timeline__scroll">
			<div class="timeline__inner" :style="{ width: `${trackWidth}px` }">
				<!-- Gridlines + axis -->
				<div class="timeline__axis">
					<div
						v-for="tick in ticks"
						:key="tick.ms"
						class="timeline__tick"
						:style="{ left: `${tick.x}px` }">
						{{ tick.label }}
					</div>
				</div>
				<div
					v-for="tick in ticks"
					:key="`g${tick.ms}`"
					class="timeline__grid"
					:style="{ left: `${tick.x}px` }" />

				<!-- Today marker -->
				<div v-if="todayX !== null" class="timeline__today" :style="{ left: `${todayX}px` }" />

				<!-- Lanes -->
				<div
					v-for="row in scheduled"
					:key="row.card.id"
					class="timeline__lane"
					:title="row.card.title"
					@click="openCard(row.card.id)">
					<div
						v-if="row.isMilestone"
						class="timeline__milestone"
						:class="{ 'timeline__bar--done': row.done, 'timeline__bar--started': row.started }"
						:style="{ left: `${row.left}px` }">
						<span class="timeline__label timeline__label--after">{{ row.card.title }}</span>
					</div>
					<div
						v-else
						class="timeline__bar"
						:class="{ 'timeline__bar--done': row.done, 'timeline__bar--started': row.started, 'timeline__bar--overdue': row.overdue }"
						:style="{ left: `${row.left}px`, width: `${row.width}px` }">
						<span class="timeline__label">{{ row.card.title }}</span>
					</div>
				</div>
			</div>
		</div>

		<!-- Unscheduled cards -->
		<details v-if="unscheduled.length > 0" class="timeline__unscheduled">
			<summary>{{ n('kanso', '%n unscheduled card', '%n unscheduled cards', unscheduled.length) }}</summary>
			<ul class="timeline__unscheduled-list">
				<li v-for="card in unscheduled" :key="card.id">
					<button class="timeline__unscheduled-row" @click="openCard(card.id)">{{ card.title }}</button>
				</li>
			</ul>
		</details>
	</div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

const props = defineProps({
	/** Filtered, non-archived cards (start_date/duedate carried in the summary). */
	cards: { type: Array, default: () => [] },
	boardId: { type: [String, Number], required: true },
})

const router = useRouter()

const DAY = 86_400_000
const ZOOMS = [
	{ key: 'day', label: t('kanso', 'Day'), pxPerDay: 34, stepDays: 1 },
	{ key: 'week', label: t('kanso', 'Week'), pxPerDay: 12, stepDays: 7 },
	{ key: 'month', label: t('kanso', 'Month'), pxPerDay: 4, stepDays: 30 },
]
const zoom = ref('week')
const zoomCfg = computed(() => ZOOMS.find((z) => z.key === zoom.value) ?? ZOOMS[1])
const pxPerDay = computed(() => zoomCfg.value.pxPerDay)

const LEFT_PAD = 8

/** Midnight (local) of a date value, in ms, or null. */
function dayFloor(value) {
	if (!value) return null
	const d = new Date(value)
	if (Number.isNaN(d.getTime())) return null
	d.setHours(0, 0, 0, 0)
	return d.getTime()
}

// Split into scheduled (has a start and/or due) and unscheduled.
const scheduledRaw = computed(() => {
	const out = []
	const unsched = []
	for (const card of props.cards) {
		const start = dayFloor(card.startDate)
		const due = dayFloor(card.duedate)
		if (start === null && due === null) {
			unsched.push(card)
			continue
		}
		// A range needs both ends; otherwise it's a single-day milestone.
		const s = start ?? due
		const e = due ?? start
		const done = Number(card.doneAt) > 0
		out.push({ card, startMs: Math.min(s, e), endMs: Math.max(s, e), done, started: !done && Number(card.startedAt) > 0 })
	}
	out.sort((a, b) => a.startMs - b.startMs || a.endMs - b.endMs)
	return { scheduled: out, unscheduled: unsched }
})

const unscheduled = computed(() => scheduledRaw.value.unscheduled)

const axisStart = computed(() => {
	const items = scheduledRaw.value.scheduled
	if (items.length === 0) return null
	return items.reduce((min, r) => Math.min(min, r.startMs), Infinity)
})
const axisEnd = computed(() => {
	const items = scheduledRaw.value.scheduled
	if (items.length === 0) return null
	return items.reduce((max, r) => Math.max(max, r.endMs), -Infinity)
})

const totalDays = computed(() => {
	if (axisStart.value === null) return 0
	return Math.round((axisEnd.value - axisStart.value) / DAY) + 1
})

const trackWidth = computed(() => LEFT_PAD * 2 + totalDays.value * pxPerDay.value)

function xForMs(ms) {
	return LEFT_PAD + Math.round((ms - axisStart.value) / DAY) * pxPerDay.value
}

const scheduled = computed(() => {
	if (axisStart.value === null) return []
	return scheduledRaw.value.scheduled.map((r) => {
		const left = xForMs(r.startMs)
		const isMilestone = r.startMs === r.endMs
		const width = isMilestone ? 0 : Math.max((Math.round((r.endMs - r.startMs) / DAY) + 1) * pxPerDay.value, pxPerDay.value)
		const overdue = !r.done && r.endMs < Date.now()
		return { ...r, left, width, isMilestone, overdue }
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

const todayX = computed(() => {
	if (axisStart.value === null) return null
	const t0 = dayFloor(Date.now())
	if (t0 < axisStart.value || t0 > axisEnd.value) return null
	return xForMs(t0)
})

function labelForMs(ms) {
	const d = new Date(ms)
	if (zoom.value === 'month') return d.toLocaleDateString(undefined, { month: 'short', year: '2-digit' })
	return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

function openCard(cardId) {
	router.push({ name: 'card-modal', params: { id: String(props.boardId), cardId: String(cardId) } })
}
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

.timeline__empty {
	color: var(--color-text-maxcontrast);
	padding: 24px 0;
	max-width: 640px;
}

.timeline__scroll {
	flex: 1;
	min-height: 0;
	overflow: auto;
}

.timeline__inner {
	position: relative;
	padding-top: 28px;
	min-height: 100%;
}

.timeline__axis {
	position: sticky;
	top: 0;
	height: 28px;
	z-index: 2;
}

.timeline__tick {
	position: absolute;
	top: 0;
	transform: translateX(-50%);
	font-size: 0.72rem;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	background: var(--color-main-background);
	padding: 0 3px;
}

.timeline__grid {
	position: absolute;
	top: 28px;
	bottom: 0;
	width: 1px;
	background: var(--color-border);
	opacity: 0.5;
}

.timeline__today {
	position: absolute;
	top: 28px;
	bottom: 0;
	width: 2px;
	background: var(--color-error);
	opacity: 0.7;
	z-index: 1;
}

.timeline__lane {
	position: relative;
	height: 34px;
	cursor: pointer;
}

.timeline__lane:hover .timeline__bar,
.timeline__lane:hover .timeline__milestone {
	filter: brightness(1.08);
}

.timeline__bar {
	position: absolute;
	top: 5px;
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
	background: var(--color-success, #2fb344);
	color: #fff;
}

.timeline__bar--overdue {
	background: var(--color-error);
	color: #fff;
}

.timeline__milestone {
	position: absolute;
	top: 9px;
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
	background: var(--color-success, #2fb344);
}

.timeline__label {
	font-size: 0.8rem;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
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
</style>
