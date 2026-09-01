<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
	Project analytics (#3568): cross-board mirror of BoardStats.vue over a
	project's ACL-resolved card set. Reuses the same CSS-bar components (no chart
	lib). Board-specific panels are intentionally absent — no "cards by stack"
	(stacks belong to one board) and no estimate-total / points panels (a project
	may span boards on different estimate scales, so points are never summed).
-->
<template>
	<div class="board-stats kanso-page-view">
		<!-- Header -->
		<div class="board-stats__header">
			<NcButton class="board-stats__back" @click="goBack">
				<template #icon>
					<ArrowLeftIcon :size="20" />
				</template>
				{{ projectTitle || t('kanso', 'Project') }}
			</NcButton>
			<h1 class="board-stats__title">{{ t('kanso', 'Analytics') }}</h1>
		</div>

		<!-- Loading -->
		<div v-if="isLoading" class="board-stats__loading" aria-live="polite">
			<span class="board-stats__spinner" aria-hidden="true" />
			<span>{{ t('kanso', 'Loading stats…') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="isError" class="board-stats__error">
			{{ t('kanso', 'Failed to load analytics. Please try again.') }}
		</div>

		<!-- Content -->
		<div v-else-if="stats" class="board-stats__body">

			<!-- ── Stat cards row ─────────────────────────────────────────── -->
			<section class="board-stats__section">
				<h2 class="board-stats__section-title">{{ t('kanso', 'At a glance') }}</h2>
				<div class="board-stats__stat-cards">
					<div class="board-stats__stat-card">
						<span class="board-stats__stat-value">{{ stats.cardCount ?? 0 }}</span>
						<span class="board-stats__stat-label">{{ t('kanso', 'Cards in project') }}</span>
					</div>
					<div class="board-stats__stat-card">
						<span class="board-stats__stat-value">{{ stats.overdue }}</span>
						<span class="board-stats__stat-label">{{ t('kanso', 'Overdue') }}</span>
					</div>
					<div class="board-stats__stat-card">
						<span class="board-stats__stat-value">{{ stats.aging?.count ?? 0 }}</span>
						<span class="board-stats__stat-label">
							{{ t('kanso', 'Aging (>{days}d)', { days: stats.aging?.days ?? 14 }) }}
						</span>
					</div>
					<div class="board-stats__stat-card">
						<span class="board-stats__stat-value">{{ stats.commentActivity }}</span>
						<span class="board-stats__stat-label">{{ t('kanso', 'Comments (30d)') }}</span>
					</div>
					<div class="board-stats__stat-card">
						<span class="board-stats__stat-value">
							{{ stats.checklist?.done ?? 0 }}/{{ stats.checklist?.total ?? 0 }}
						</span>
						<span class="board-stats__stat-label">{{ t('kanso', 'Checklist done') }}</span>
					</div>
				</div>
			</section>

			<!-- ── Cards by priority ──────────────────────────────────────── -->
			<section v-if="stats.byPriority?.length" class="board-stats__section">
				<h2 class="board-stats__section-title">{{ t('kanso', 'Cards by priority') }}</h2>
				<ul class="board-stats__bar-list" :aria-label="t('kanso', 'Cards by priority')">
					<li
						v-for="row in stats.byPriority"
						:key="row.priority"
						class="board-stats__bar-row">
						<span class="board-stats__bar-label">{{ resolvePriorityLabel(row.priority) }}</span>
						<div class="board-stats__bar-track">
							<div
								class="board-stats__bar-fill board-stats__bar-fill--priority"
								:class="`board-stats__bar-fill--p${row.priority}`"
								:style="{ width: pct(row.count, maxByPriority) + '%' }" />
						</div>
						<span class="board-stats__bar-count">{{ row.count }}</span>
					</li>
				</ul>
			</section>

			<!-- ── Cards by assignee ──────────────────────────────────────── -->
			<section v-if="stats.byAssignee?.length" class="board-stats__section">
				<h2 class="board-stats__section-title">{{ t('kanso', 'Cards by assignee') }}</h2>
				<ul class="board-stats__bar-list" :aria-label="t('kanso', 'Cards by assignee')">
					<li
						v-for="row in stats.byAssignee"
						:key="row.uid"
						class="board-stats__bar-row">
						<span class="board-stats__bar-label">{{ row.uid }}</span>
						<div class="board-stats__bar-track">
							<div
								class="board-stats__bar-fill board-stats__bar-fill--assignee"
								:style="{ width: pct(row.count, maxByAssignee) + '%' }" />
						</div>
						<span class="board-stats__bar-count">{{ row.count }}</span>
					</li>
				</ul>
			</section>

			<!-- ── Cards by label ─────────────────────────────────────────── -->
			<!-- A label id is only board-unique, so each bar is keyed on the
			     (board, label) pair and labelled with its board so distinct
			     same-id labels from different boards never collapse into one. -->
			<section v-if="stats.byLabel?.length" class="board-stats__section">
				<h2 class="board-stats__section-title">{{ t('kanso', 'Cards by label') }}</h2>
				<ul class="board-stats__bar-list" :aria-label="t('kanso', 'Cards by label')">
					<li
						v-for="row in stats.byLabel"
						:key="`${row.boardId}:${row.labelId}`"
						class="board-stats__bar-row">
						<span class="board-stats__bar-label" :title="labelRowTitle(row)">{{ labelRowTitle(row) }}</span>
						<div class="board-stats__bar-track">
							<div
								class="board-stats__bar-fill board-stats__bar-fill--label"
								:style="{ width: pct(row.count, maxByLabel) + '%' }" />
						</div>
						<span class="board-stats__bar-count">{{ row.count }}</span>
					</li>
				</ul>
			</section>

			<!-- ── Velocity (completed per week) ──────────────────────────── -->
			<section v-if="velocity" class="board-stats__section">
				<h2 class="board-stats__section-title">{{ t('kanso', 'Velocity — completed per week') }}</h2>
				<div class="board-stats__stat-cards">
					<div class="board-stats__stat-card">
						<span class="board-stats__stat-value">
							{{ velocity.cardsPerWeek }}
							<span
								class="board-stats__trend"
								:class="`board-stats__trend--${velocity.cardsTrend}`"
								:title="trendTitle(velocity.cardsTrend)">{{ trendGlyph(velocity.cardsTrend) }}</span>
						</span>
						<span class="board-stats__stat-label">{{ t('kanso', 'Cards / week (avg)') }}</span>
					</div>
				</div>
				<div class="board-stats__timeline" :aria-label="t('kanso', 'Cards completed per week')">
					<template v-if="velocity.weekly.length">
						<div
							v-for="w in velocity.weekly"
							:key="w.week"
							class="board-stats__timeline-col board-stats__timeline-col--wide"
							:title="velocityBarTitle(w)">
							<div
								class="board-stats__timeline-bar"
								:style="{ height: pct(w.cards, maxVelocityCards) + '%' }" />
						</div>
					</template>
					<span v-else class="board-stats__timeline-empty">{{ t('kanso', 'No data') }}</span>
				</div>
				<div class="board-stats__timeline-labels">
					<span>{{ velocity.weekly[0]?.week ?? '' }}</span>
					<span>{{ t('kanso', 'now') }}</span>
				</div>
			</section>

			<!-- ── Cycle time (create → done) ─────────────────────────────── -->
			<section v-if="cycleTime" class="board-stats__section">
				<h2 class="board-stats__section-title">
					{{ t('kanso', 'Cycle time — creation to done ({days}d)', { days: cycleTime.windowDays ?? 28 }) }}
				</h2>
				<div v-if="cycleTime.sampleSize > 0" class="board-stats__stat-cards">
					<div class="board-stats__stat-card">
						<span class="board-stats__stat-value">{{ cycleTime.medianDays }}</span>
						<span class="board-stats__stat-label">{{ t('kanso', 'Median days') }}</span>
					</div>
					<div class="board-stats__stat-card">
						<span class="board-stats__stat-value">{{ cycleTime.averageDays }}</span>
						<span class="board-stats__stat-label">{{ t('kanso', 'Average days') }}</span>
					</div>
					<div class="board-stats__stat-card">
						<span class="board-stats__stat-value">{{ cycleTime.sampleSize }}</span>
						<span class="board-stats__stat-label">{{ t('kanso', 'Cards measured') }}</span>
					</div>
				</div>
				<span v-else class="board-stats__timeline-empty">{{ t('kanso', 'No cards completed in the window') }}</span>
			</section>

			<!-- ── Throughput (done cards last 30d) ───────────────────────── -->
			<section class="board-stats__section">
				<h2 class="board-stats__section-title">{{ t('kanso', 'Throughput — done per day (30d)') }}</h2>
				<div class="board-stats__timeline" :aria-label="t('kanso', 'Throughput per day')">
					<template v-if="throughputDays.length">
						<div
							v-for="d in throughputDays"
							:key="d.day"
							class="board-stats__timeline-col"
							:title="d.day + ': ' + d.count">
							<div
								class="board-stats__timeline-bar"
								:style="{ height: pct(d.count, maxThroughput) + '%' }" />
						</div>
					</template>
					<span v-else class="board-stats__timeline-empty">{{ t('kanso', 'No data') }}</span>
				</div>
				<div class="board-stats__timeline-labels">
					<span>{{ throughputStart }}</span>
					<span>{{ throughputEnd }}</span>
				</div>
			</section>

			<!-- ── Created cards last 30d ─────────────────────────────────── -->
			<section class="board-stats__section">
				<h2 class="board-stats__section-title">{{ t('kanso', 'Cards created per day (30d)') }}</h2>
				<div class="board-stats__timeline" :aria-label="t('kanso', 'Cards created per day')">
					<template v-if="createdDays.length">
						<div
							v-for="d in createdDays"
							:key="d.day"
							class="board-stats__timeline-col"
							:title="d.day + ': ' + d.count">
							<div
								class="board-stats__timeline-bar board-stats__timeline-bar--created"
								:style="{ height: pct(d.count, maxCreated) + '%' }" />
						</div>
					</template>
					<span v-else class="board-stats__timeline-empty">{{ t('kanso', 'No data') }}</span>
				</div>
				<div class="board-stats__timeline-labels">
					<span>{{ createdStart }}</span>
					<span>{{ createdEnd }}</span>
				</div>
			</section>

		</div>
	</div>
</template>

<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import { useProjectStats } from '../composables/useProjectStats.js'
import { useProjects } from '../composables/useProjects.js'
import { useProjectCards } from '../composables/useProject.js'
import { PRIORITY_LEVELS } from '../composables/usePriority.js'

const props = defineProps({
	id: {
		type: String,
		required: true,
	},
})

const router = useRouter()
const projectId = computed(() => props.id)

const { data: stats, isLoading, isError } = useProjectStats(projectId)

// Resolve the project title from the already-cached projects list query.
const { data: projectsData } = useProjects()
const projectTitle = computed(() =>
	(projectsData.value ?? []).find((p) => String(p.id) === String(props.id))?.title ?? '',
)

function resolvePriorityLabel(priority) {
	const level = PRIORITY_LEVELS.find((l) => l.value === Number(priority))
	return level ? level.label : String(priority)
}

// The project's cards are already cached (ProjectView populated them); use them
// to map a boardId → board title for the byLabel bars. Label names are not in
// the analytics DTO (a label id is only board-unique), so each bar is qualified
// by its board, which disambiguates same-id labels across boards.
const { data: cardsData } = useProjectCards(projectId)
const boardTitleById = computed(() => {
	const map = new Map()
	for (const card of cardsData.value ?? []) {
		if (!map.has(card.boardId)) map.set(card.boardId, card.boardTitle)
	}
	return map
})

function labelRowTitle(row) {
	const board = boardTitleById.value.get(row.boardId)
	const label = t('kanso', 'Label {id}', { id: row.labelId })
	return board ? `${board} · ${label}` : label
}

// ── Max values for bar width calculation ─────────────────────────────────────
const maxByPriority = computed(() => Math.max(1, ...((stats.value?.byPriority ?? []).map((r) => r.count))))
const maxByAssignee = computed(() => Math.max(1, ...((stats.value?.byAssignee ?? []).map((r) => r.count))))
const maxByLabel = computed(() => Math.max(1, ...((stats.value?.byLabel ?? []).map((r) => r.count))))

// ── Timeline helpers ──────────────────────────────────────────────────────────
function expand30Days(sparse) {
	if (!sparse || !sparse.length) return []
	const today = new Date()
	const days = []
	for (let i = 29; i >= 0; i--) {
		const d = new Date(today)
		d.setDate(d.getDate() - i)
		days.push(d.toISOString().slice(0, 10))
	}
	const map = Object.fromEntries(sparse.map((r) => [r.day, r.count]))
	return days.map((day) => ({ day, count: map[day] ?? 0 }))
}

const throughputDays = computed(() => expand30Days(stats.value?.throughput ?? []))
const createdDays = computed(() => expand30Days(stats.value?.created ?? []))
const maxThroughput = computed(() => Math.max(1, ...throughputDays.value.map((d) => d.count)))
const maxCreated = computed(() => Math.max(1, ...createdDays.value.map((d) => d.count)))

const throughputStart = computed(() => throughputDays.value[0]?.day ?? '')
const throughputEnd = computed(() => throughputDays.value[throughputDays.value.length - 1]?.day ?? '')
const createdStart = computed(() => createdDays.value[0]?.day ?? '')
const createdEnd = computed(() => createdDays.value[createdDays.value.length - 1]?.day ?? '')

// ── Velocity & cycle time ─────────────────────────────────────────────────────
const velocity = computed(() => stats.value?.velocity ?? null)
const cycleTime = computed(() => stats.value?.cycleTime ?? null)
const maxVelocityCards = computed(() =>
	Math.max(1, ...((velocity.value?.weekly ?? []).map((w) => w.cards))),
)

function trendGlyph(dir) {
	if (dir === 'up') return '▲'
	if (dir === 'down') return '▼'
	return '▬'
}

function trendTitle(dir) {
	if (dir === 'up') return t('kanso', 'Up vs previous period')
	if (dir === 'down') return t('kanso', 'Down vs previous period')
	return t('kanso', 'Flat vs previous period')
}

function velocityBarTitle(w) {
	return `${w.week}: ${n('kanso', '%n card', '%n cards', w.cards)}`
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function pct(value, max) {
	if (!max || max <= 0) return 0
	return Math.round((value / max) * 100)
}

function goBack() {
	router.push({ name: 'project', params: { id: props.id } })
}
</script>

<style scoped>
.board-stats {
	display: flex;
	flex-direction: column;
	height: 100%;
	overflow-y: auto;
}

/* ── Header ─────────────────────────────────────────────────────────────────── */
.board-stats__header {
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 12px 24px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-main-background);
	flex-shrink: 0;
	position: sticky;
	top: 0;
	z-index: 10;
}

.board-stats__back {
	flex-shrink: 0;
}

.board-stats__title {
	font-size: 1.2rem;
	font-weight: 700;
	color: var(--color-main-text);
	margin: 0;
}

/* ── Loading / error ─────────────────────────────────────────────────────────── */
.board-stats__loading {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--color-text-maxcontrast);
	padding: 32px;
}

.board-stats__spinner {
	display: inline-block;
	width: 20px;
	height: 20px;
	border: 2px solid var(--color-border);
	border-top-color: var(--color-primary-element);
	border-radius: 50%;
	animation: board-stats-spin 0.7s linear infinite;
}

@keyframes board-stats-spin {
	to { transform: rotate(360deg); }
}

.board-stats__error {
	color: var(--color-error);
	padding: 24px 32px;
}

/* ── Body ─────────────────────────────────────────────────────────────────────── */
.board-stats__body {
	padding: 24px 32px;
	max-width: 900px;
	display: flex;
	flex-direction: column;
	gap: 40px;
}

/* ── Section ─────────────────────────────────────────────────────────────────── */
.board-stats__section-title {
	font-size: 0.8rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.07em;
	color: var(--color-text-maxcontrast);
	margin: 0 0 12px;
}

/* ── Stat cards ──────────────────────────────────────────────────────────────── */
.board-stats__stat-cards {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
}

.board-stats__stat-card {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 4px;
	padding: 16px 24px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	min-width: 130px;
}

.board-stats__stat-value {
	font-size: 2rem;
	font-weight: 700;
	color: var(--color-main-text);
	line-height: 1;
}

.board-stats__stat-label {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

/* ── Bar list ────────────────────────────────────────────────────────────────── */
.board-stats__bar-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.board-stats__bar-row {
	display: flex;
	align-items: center;
	gap: 10px;
}

.board-stats__bar-label {
	display: flex;
	align-items: center;
	gap: 6px;
	width: 160px;
	flex-shrink: 0;
	font-size: 0.875rem;
	color: var(--color-main-text);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.board-stats__bar-track {
	flex: 1;
	height: 10px;
	background: var(--color-background-dark, #e0e0e0);
	border-radius: 5px;
	overflow: hidden;
}

.board-stats__bar-fill {
	height: 100%;
	border-radius: 5px;
	background: var(--color-primary-element);
	transition: width 0.3s ease;
	min-width: 2px;
}

.board-stats__bar-fill--priority.board-stats__bar-fill--p1 { background: #5ec269; }
.board-stats__bar-fill--priority.board-stats__bar-fill--p2 { background: #f0a020; }
.board-stats__bar-fill--priority.board-stats__bar-fill--p3 { background: #e8672a; }
.board-stats__bar-fill--priority.board-stats__bar-fill--p4 { background: #d0332a; }
.board-stats__bar-fill--priority.board-stats__bar-fill--p0 { background: var(--color-border-dark); }

.board-stats__bar-fill--assignee { background: var(--color-primary-element-light, #6bbde6); }
.board-stats__bar-fill--label { background: #8b6bbf; }

.board-stats__bar-count {
	width: 36px;
	text-align: right;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

/* ── Timeline ─────────────────────────────────────────────────────────────────── */
.board-stats__timeline {
	display: flex;
	align-items: flex-end;
	gap: 3px;
	height: 80px;
	padding: 0;
}

.board-stats__timeline-col {
	flex: 1;
	height: 100%;
	display: flex;
	align-items: flex-end;
}

.board-stats__timeline-bar {
	width: 100%;
	background: var(--color-primary-element);
	border-radius: 2px 2px 0 0;
	min-height: 2px;
	transition: height 0.3s ease;
}

.board-stats__timeline-bar--created {
	background: #5ec269;
}

.board-stats__timeline-col--wide {
	min-width: 8px;
}

.board-stats__timeline-empty {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	align-self: center;
}

/* ── Trend indicator ───────────────────────────────────────────────────────── */
.board-stats__trend {
	font-size: 1rem;
	margin-left: 6px;
	vertical-align: middle;
}

.board-stats__trend--up { color: #5ec269; }
.board-stats__trend--down { color: #d0332a; }
.board-stats__trend--flat { color: var(--color-text-maxcontrast); }

.board-stats__timeline-labels {
	display: flex;
	justify-content: space-between;
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}
</style>
