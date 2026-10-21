<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<figure class="burnup" data-test="project-burnup">
		<figcaption class="burnup__head">
			<span class="burnup__title">{{ t('kanso', 'Burn-up') }}</span>
			<ul class="burnup__legend">
				<li><span class="burnup__swatch burnup__swatch--scope" />{{ t('kanso', 'Scope') }}</li>
				<li><span class="burnup__swatch burnup__swatch--done" />{{ t('kanso', 'Done') }}</li>
				<li v-if="projection"><span class="burnup__swatch burnup__swatch--projection" />{{ t('kanso', 'Forecast') }}</li>
				<li v-if="targetX !== null"><span class="burnup__swatch burnup__swatch--target" />{{ t('kanso', 'Target') }}</li>
			</ul>
		</figcaption>

		<div class="burnup__plot">
			<svg
				ref="svgRef"
				:viewBox="`0 0 ${W} ${H}`"
				preserveAspectRatio="none"
				role="img"
				:aria-label="summary"
				@pointermove="onMove"
				@pointerleave="hover = null">
				<line
					v-for="tick in yTicks"
					:key="'g' + tick"
					class="burnup__grid"
					:x1="PAD_L"
					:x2="W - PAD_R"
					:y1="y(tick)"
					:y2="y(tick)" />
				<line
					v-if="targetX !== null"
					class="burnup__target"
					:x1="targetX"
					:x2="targetX"
					:y1="PAD_T"
					:y2="H - PAD_B" />
				<polyline class="burnup__line burnup__line--scope" :points="scopePoints" />
				<polyline class="burnup__line burnup__line--done" :points="donePoints" />
				<line
					v-if="projection"
					class="burnup__projection"
					:x1="projection.x1"
					:y1="projection.y1"
					:x2="projection.x2"
					:y2="projection.y2" />
				<template v-if="hover">
					<line
						class="burnup__crosshair"
						:x1="hover.x"
						:x2="hover.x"
						:y1="PAD_T"
						:y2="H - PAD_B" />
					<circle class="burnup__dot burnup__dot--scope" :cx="hover.x" :cy="y(hover.row.scope)" r="4" />
					<circle class="burnup__dot burnup__dot--done" :cx="hover.x" :cy="y(hover.row.done)" r="4" />
				</template>
			</svg>
			<span
				v-for="tick in yTicks"
				:key="'l' + tick"
				class="burnup__ylabel"
				:style="{ top: pct(y(tick), H) }">{{ tick }}</span>
			<div
				v-if="hover"
				class="burnup__tooltip"
				:style="{ left: pct(hover.x, W) }">
				<strong>{{ formatDate(hover.row.date) }}</strong>
				<span>{{ t('kanso', 'Scope: {n}', { n: hover.row.scope }) }}</span>
				<span>{{ t('kanso', 'Done: {n}', { n: hover.row.done }) }}</span>
			</div>
		</div>
		<div class="burnup__xaxis">
			<span>{{ formatDate(rows[0]?.date) }}</span>
			<span>{{ formatDate(endDate) }}</span>
		</div>
	</figure>
</template>

<script setup>
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'

const props = defineProps({
	plan: { type: Object, required: true },
})

const W = 600
const H = 180
const PAD_L = 28
const PAD_R = 8
const PAD_T = 8
const PAD_B = 8
const DAY = 86400000

const svgRef = ref(null)
const hover = ref(null)

const usePoints = computed(() => props.plan.basis === 'points')
const rows = computed(() => (props.plan.burnup ?? []).map((r) => ({
	date: r.date,
	scope: usePoints.value ? r.scopePoints : r.scope,
	done: usePoints.value ? r.donePoints : r.done,
})))

const toTime = (date) => Date.parse(date + 'T00:00:00Z')

const startTime = computed(() => toTime(rows.value[0]?.date ?? new Date().toISOString().slice(0, 10)))
const lastRow = computed(() => rows.value[rows.value.length - 1] ?? { date: null, scope: 0, done: 0 })
const endDate = computed(() => {
	const candidates = [lastRow.value.date, props.plan.forecast?.date, props.plan.targetDate].filter(Boolean)
	return candidates.sort().pop()
})
const endTime = computed(() => Math.max(toTime(endDate.value ?? lastRow.value.date), startTime.value + DAY))

const maxY = computed(() => Math.max(1, ...rows.value.map((r) => r.scope)))
const yTicks = computed(() => {
	const raw = maxY.value / 4
	const magnitude = 10 ** Math.floor(Math.log10(Math.max(raw, 1)))
	const step = Math.max(1, [1, 2, 5, 10].map((m) => m * magnitude).find((v) => v >= raw))
	const ticks = [0]
	while (ticks[ticks.length - 1] < maxY.value) ticks.push(ticks[ticks.length - 1] + step)
	return ticks
})
const yMax = computed(() => Math.max(maxY.value, yTicks.value[yTicks.value.length - 1] ?? 1))

function x(date) {
	const span = endTime.value - startTime.value
	return PAD_L + ((toTime(date) - startTime.value) / span) * (W - PAD_L - PAD_R)
}

function y(value) {
	return H - PAD_B - (value / yMax.value) * (H - PAD_T - PAD_B)
}

const scopePoints = computed(() => rows.value.map((r) => `${x(r.date)},${y(r.scope)}`).join(' '))
const donePoints = computed(() => rows.value.map((r) => `${x(r.date)},${y(r.done)}`).join(' '))

const projection = computed(() => {
	const date = props.plan.forecast?.date
	if (!date || !lastRow.value.date) return null
	return { x1: x(lastRow.value.date), y1: y(lastRow.value.done), x2: x(date), y2: y(lastRow.value.scope) }
})

const targetX = computed(() => (props.plan.targetDate ? x(props.plan.targetDate) : null))

const summary = computed(() => t('kanso', 'Burn-up chart: {done} of {scope} done', {
	done: lastRow.value.done,
	scope: lastRow.value.scope,
}))

function pct(value, total) {
	return `${(value / total) * 100}%`
}

function formatDate(date) {
	if (!date) return ''
	return new Date(toTime(date)).toLocaleDateString(undefined, { month: 'short', day: 'numeric', timeZone: 'UTC' })
}

function onMove(event) {
	const rect = svgRef.value?.getBoundingClientRect()
	if (!rect || !rows.value.length) return
	const px = ((event.clientX - rect.left) / rect.width) * W
	let best = null
	for (const row of rows.value) {
		const rx = x(row.date)
		if (!best || Math.abs(rx - px) < Math.abs(best.x - px)) best = { x: rx, row }
	}
	hover.value = best
}
</script>

<style scoped>
.burnup {
	margin: 0;
}

.burnup__head {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	justify-content: space-between;
	gap: 8px;
	margin-bottom: 6px;
}

.burnup__title {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.burnup__legend {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	list-style: none;
	margin: 0;
	padding: 0;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.burnup__legend li {
	display: flex;
	align-items: center;
	gap: 6px;
}

.burnup__swatch {
	display: inline-block;
	width: 14px;
	height: 0;
	border-top: 2px solid;
}

.burnup__swatch--scope { border-color: var(--color-text-maxcontrast); }
.burnup__swatch--done { border-color: var(--color-primary-element); }
.burnup__swatch--projection { border-color: var(--color-primary-element); border-top-style: dashed; }
.burnup__swatch--target { border-color: var(--kanso-error-legible, var(--color-error)); border-top-style: dotted; }

.burnup__plot {
	position: relative;
	height: 180px;
}

.burnup__plot svg {
	width: 100%;
	height: 100%;
	display: block;
	overflow: visible;
}

.burnup__grid {
	stroke: var(--color-border);
	stroke-width: 1;
	vector-effect: non-scaling-stroke;
}

.burnup__line {
	fill: none;
	stroke-width: 2;
	stroke-linejoin: round;
	vector-effect: non-scaling-stroke;
}

.burnup__line--scope { stroke: var(--color-text-maxcontrast); }
.burnup__line--done { stroke: var(--color-primary-element); }

.burnup__projection {
	stroke: var(--color-primary-element);
	stroke-width: 2;
	stroke-dasharray: 6 4;
	vector-effect: non-scaling-stroke;
}

.burnup__target {
	stroke: var(--kanso-error-legible, var(--color-error));
	stroke-width: 1.5;
	stroke-dasharray: 2 3;
	vector-effect: non-scaling-stroke;
}

.burnup__crosshair {
	stroke: var(--color-border-dark);
	stroke-width: 1;
	vector-effect: non-scaling-stroke;
}

.burnup__dot {
	stroke: var(--color-main-background);
	stroke-width: 2;
	vector-effect: non-scaling-stroke;
}

.burnup__dot--scope { fill: var(--color-text-maxcontrast); }
.burnup__dot--done { fill: var(--color-primary-element); }

.burnup__ylabel {
	position: absolute;
	left: 0;
	transform: translateY(-50%);
	font-size: 0.7rem;
	color: var(--color-text-maxcontrast);
	pointer-events: none;
}

.burnup__tooltip {
	position: absolute;
	top: 0;
	transform: translateX(-50%);
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 6px 8px;
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
	font-size: 0.78rem;
	white-space: nowrap;
	pointer-events: none;
}

.burnup__xaxis {
	display: flex;
	justify-content: space-between;
	padding-left: 28px;
	font-size: 0.7rem;
	color: var(--color-text-maxcontrast);
}
</style>
