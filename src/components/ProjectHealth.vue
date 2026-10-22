<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="health" data-test="project-health" :aria-label="t('kanso', 'Project health')">
		<div class="health__status-row">
			<span class="health__pill" :class="'health__pill--' + tone" data-test="project-status">
				<component :is="statusIcon" :size="16" />
				{{ statusLabel }}
			</span>
			<span class="health__explain">{{ statusExplain }}</span>
		</div>

		<div class="health__grid">
			<div class="health__metric">
				<span class="health__label">{{ t('kanso', 'Progress') }}</span>
				<span class="health__value" data-test="project-progress">
					{{ t('kanso', '{done} of {total} cards', { done: plan.done, total: plan.total }) }}
				</span>
				<div class="health__bar" role="progressbar" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100">
					<div class="health__bar-fill" :style="{ width: percent + '%' }" />
				</div>
				<span v-if="plan.points" class="health__sub">
					{{ n('kanso', '{done} of {total} point', '{done} of {total} points', plan.points.total, { done: fmt(plan.points.done), total: fmt(plan.points.total) }) }}
				</span>
			</div>

			<div class="health__metric">
				<span class="health__label">{{ t('kanso', 'Remaining') }}</span>
				<span class="health__value">{{ n('kanso', '{count} card', '{count} cards', plan.open, { count: plan.open }) }}</span>
				<span v-if="plan.points" class="health__sub">
					{{ n('kanso', '{n} point', '{n} points', plan.points.remaining, { n: fmt(plan.points.remaining) }) }}
				</span>
			</div>

			<div class="health__metric">
				<span class="health__label">{{ t('kanso', 'Pace') }}</span>
				<span class="health__value">{{ paceLabel }}</span>
				<span class="health__sub">
					{{ n('kanso', 'average of the last week', 'average of the last {count} weeks', plan.throughput.weeks, { count: plan.throughput.weeks }) }}
				</span>
			</div>

			<div class="health__metric">
				<span class="health__label">{{ t('kanso', 'Forecast') }}</span>
				<span class="health__value" data-test="project-forecast">{{ forecastLabel }}</span>
				<span v-if="rangeLabel" class="health__sub">{{ rangeLabel }}</span>
			</div>

			<div class="health__metric">
				<label class="health__label" :for="startId">{{ t('kanso', 'Start date') }}</label>
				<input
					:id="startId"
					class="health__date"
					type="date"
					data-test="project-start-date"
					:value="plan.startDate ?? ''"
					:max="plan.targetDate ?? undefined"
					@change="emit('update-dates', { startDate: $event.target.value })">
			</div>

			<div class="health__metric">
				<label class="health__label" :for="targetId">{{ t('kanso', 'Target date') }}</label>
				<input
					:id="targetId"
					class="health__date"
					type="date"
					data-test="project-target-date"
					:value="plan.targetDate ?? ''"
					:min="plan.startDate ?? undefined"
					@change="emit('update-dates', { targetDate: $event.target.value })">
				<span v-if="daysLeftLabel" class="health__sub" :class="{ 'health__sub--late': plan.daysLeft < 0 }">{{ daysLeftLabel }}</span>
			</div>
		</div>

		<div v-if="chips.length || unestimatedNote" class="health__chips">
			<span
				v-for="chip in chips"
				:key="chip.key"
				class="health__chip"
				:class="'health__chip--' + chip.tone"
				:data-test="'project-chip-' + chip.key">
				<component :is="chip.icon" :size="14" />
				{{ chip.label }}
			</span>
			<span v-if="unestimatedNote" class="health__note">{{ unestimatedNote }}</span>
		</div>

		<span v-if="error" class="health__error" role="alert">{{ error }}</span>
	</section>
</template>

<script setup>
import { computed } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import ClockAlertOutlineIcon from 'vue-material-design-icons/ClockAlertOutline.vue'
import BlockHelperIcon from 'vue-material-design-icons/BlockHelper.vue'
import ProgressClockIcon from 'vue-material-design-icons/ProgressClock.vue'
import { projectStatus, formatPlanDate } from '../services/projectStatus.js'

const props = defineProps({
	plan: { type: Object, required: true },
	projectId: { type: [String, Number], required: true },
	error: { type: String, default: '' },
})

const emit = defineEmits(['update-dates'])

const startId = computed(() => `project-${props.projectId}-start`)
const targetId = computed(() => `project-${props.projectId}-target`)

const status = computed(() => projectStatus(props.plan.status))
const tone = computed(() => status.value.tone)
const statusIcon = computed(() => status.value.icon)
const statusLabel = computed(() => status.value.label)
const statusExplain = computed(() => status.value.explain)

const percent = computed(() => {
	if (props.plan.points) {
		return props.plan.points.total > 0 ? Math.round((props.plan.points.done / props.plan.points.total) * 100) : 0
	}
	return props.plan.total > 0 ? Math.round((props.plan.done / props.plan.total) * 100) : 0
})

function fmt(value) {
	return Number.isInteger(value) ? String(value) : value.toFixed(1)
}

const formatDate = (date) => formatPlanDate(date)

const paceLabel = computed(() => {
	const rate = fmt(props.plan.throughput.perWeek)
	return props.plan.basis === 'points'
		? n('kanso', '{n} point / week', '{n} points / week', props.plan.throughput.perWeek, { n: rate })
		: n('kanso', '{n} card / week', '{n} cards / week', props.plan.throughput.perWeek, { n: rate })
})

const forecastLabel = computed(() => {
	if (props.plan.status === 'done') return t('kanso', 'Finished')
	return props.plan.forecast ? formatDate(props.plan.forecast.date) : '—'
})

const rangeLabel = computed(() => {
	const f = props.plan.forecast
	if (!f || f.earliest === f.latest) return ''
	return f.latest
		? t('kanso', 'between {from} and {to}', { from: formatDate(f.earliest), to: formatDate(f.latest) })
		: t('kanso', '{from} at the earliest', { from: formatDate(f.earliest) })
})

const daysLeftLabel = computed(() => {
	const d = props.plan.daysLeft
	if (d === null || d === undefined || props.plan.status === 'done') return ''
	if (d === 0) return t('kanso', 'today')
	return d > 0
		? n('kanso', 'in {count} day', 'in {count} days', d, { count: d })
		: n('kanso', '{count} day ago', '{count} days ago', -d, { count: -d })
})

const chips = computed(() => {
	const list = []
	if (props.plan.overdue) {
		list.push({ key: 'overdue', tone: 'error', icon: ClockAlertOutlineIcon, label: n('kanso', '{count} overdue', '{count} overdue', props.plan.overdue, { count: props.plan.overdue }) })
	}
	if (props.plan.blocked) {
		list.push({ key: 'blocked', tone: 'warning', icon: BlockHelperIcon, label: n('kanso', '{count} blocked', '{count} blocked', props.plan.blocked, { count: props.plan.blocked }) })
	}
	if (props.plan.inProgress) {
		list.push({ key: 'in-progress', tone: 'neutral', icon: ProgressClockIcon, label: n('kanso', '{count} in progress', '{count} in progress', props.plan.inProgress, { count: props.plan.inProgress }) })
	}
	return list
})

const unestimatedNote = computed(() => {
	const p = props.plan.points
	if (!p || !p.unestimatedCount) return ''
	return n(
		'kanso',
		'{count} card has no estimate and counts as the typical estimate of {points}.',
		'{count} cards have no estimate and each counts as the typical estimate of {points}.',
		p.unestimatedCount,
		{ count: p.unestimatedCount, points: fmt(p.assumedPerCard) },
	)
})
</script>

<style scoped>
.health {
	display: flex;
	flex-direction: column;
	gap: 14px;
	padding: 16px;
	margin-bottom: 20px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

.health__status-row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 10px;
}

.health__pill {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 4px 10px;
	border-radius: var(--border-radius-pill, 999px);
	font-weight: 600;
	font-size: 0.9rem;
}

.health__pill--success { color: var(--kanso-success-legible); background: var(--kanso-success-tint); }
.health__pill--warning { color: var(--kanso-warning-legible); background: var(--kanso-warning-tint); }
.health__pill--error { color: var(--kanso-error-legible); background: var(--kanso-error-tint); }
.health__pill--neutral { color: var(--kanso-neutral-legible); background: var(--color-background-hover); }

.health__explain {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.health__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
	gap: 14px 18px;
}

.health__metric {
	display: flex;
	flex-direction: column;
	gap: 3px;
	min-width: 0;
}

.health__label {
	font-size: 0.75rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: var(--color-text-maxcontrast);
}

.health__value {
	font-size: 1.05rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.health__sub {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.health__sub--late {
	color: var(--kanso-error-legible);
}

.health__bar {
	height: 6px;
	border-radius: 3px;
	background: var(--color-background-dark);
	overflow: hidden;
}

.health__bar-fill {
	height: 100%;
	border-radius: 3px;
	background: var(--color-primary-element);
}

.health__date {
	width: 100%;
	max-width: 170px;
	min-height: 34px;
	margin: 0;
}

.health__chips {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.health__chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 999px);
	font-size: 0.8rem;
	font-weight: 500;
}

.health__chip--error { color: var(--kanso-error-legible); background: var(--kanso-error-tint); }
.health__chip--warning { color: var(--kanso-warning-legible); background: var(--kanso-warning-tint); }
.health__chip--neutral { color: var(--kanso-neutral-legible); background: var(--color-background-hover); }

.health__note {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.health__error {
	font-size: 0.85rem;
	color: var(--kanso-error-legible);
}
</style>
