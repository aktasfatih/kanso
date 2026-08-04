<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="board-tile-content">
		<!-- Title row: color dot + title + star placeholder -->
		<div class="board-tile__head">
			<span
				class="board-tile__color-dot"
				:style="{ background: dotColor }" />
			<span class="board-tile__title">{{ board.title }}</span>
			<!-- Per-user pin toggle (#3632): filled star = pinned, outline = not.
			     Clicking toggles the pin (optimistic) and must NOT bubble to the
			     tile's open-board click. Hidden for archived tiles (no pinning
			     there). -->
			<button
				v-if="showStar"
				type="button"
				class="board-tile__star-btn"
				:class="{ 'board-tile__star-btn--on': isPinned }"
				:aria-pressed="isPinned"
				:title="isPinned ? t('kanso', 'Unpin board') : t('kanso', 'Pin board')"
				:aria-label="isPinned ? t('kanso', 'Unpin board') : t('kanso', 'Pin board')"
				data-test="board-pin-star"
				@click.stop.prevent="$emit('toggle-pin')">
				<StarIcon v-if="isPinned" :size="16" class="board-tile__star board-tile__star--on" />
				<StarOutlineIcon v-else :size="16" class="board-tile__star" />
			</button>
		</div>

		<!-- Meta line: card count + progress + badges + relative time -->
		<div class="board-tile__meta">
			<span class="board-tile__meta-item">
				{{ n('kanso', '%n card', '%n cards', cardCount) }}
			</span>

			<span class="board-tile__sep" aria-hidden="true">·</span>
			<span class="board-tile__progress" :title="t('kanso', '{p}% done', { p: progress })">
				<span class="board-tile__progress-track">
					<span class="board-tile__progress-fill" :style="{ width: progress + '%' }" />
				</span>
				<span class="board-tile__progress-pct">{{ progress }}%</span>
			</span>

			<template v-if="needsReview > 0">
				<span class="board-tile__sep" aria-hidden="true">·</span>
				<span class="board-tile__badge board-tile__badge--review">
					{{ n('kanso', '%n need review', '%n need review', needsReview) }}
				</span>
			</template>

			<template v-if="overdue > 0">
				<span class="board-tile__sep" aria-hidden="true">·</span>
				<span class="board-tile__badge board-tile__badge--overdue">
					{{ n('kanso', '%n overdue', '%n overdue', overdue) }}
				</span>
			</template>

			<template v-if="relativeTime">
				<span class="board-tile__sep" aria-hidden="true">·</span>
				<span class="board-tile__time" :title="fullTime">{{ relativeTime }}</span>
			</template>
		</div>
	</div>
</template>

<script setup>
import { computed } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import StarIcon from 'vue-material-design-icons/Star.vue'
import StarOutlineIcon from 'vue-material-design-icons/StarOutline.vue'
import { cssColor } from '../services/color.js'

const props = defineProps({
	board: { type: Object, required: true },
	// Force the pinned look regardless of payload (used by the dedicated Pinned
	// section, which only renders already-pinned boards).
	pinned: { type: Boolean, default: false },
	// Whether to render the interactive pin star. Off for archived tiles.
	pinnable: { type: Boolean, default: false },
})

defineEmits(['toggle-pin'])

// A board is pinned when the payload says so, OR when the parent forces it (the
// dedicated Pinned section).
const isPinned = computed(() => props.pinned || !!props.board.pinned)
// Show the interactive star only when the parent opts in (pinnable). The
// forced-pinned section also shows it so the user can unpin from there.
const showStar = computed(() => props.pinnable || props.pinned)

const dotColor = computed(() => cssColor(props.board.color) || 'var(--color-primary-element)')

// Stats degrade gracefully when the block is absent (older payloads / #3571 guard).
const stats = computed(() => props.board.stats ?? {})
const cardCount = computed(() => Number(stats.value.cardCount) || 0)
const progress = computed(() => {
	const p = Number(stats.value.progress)
	if (!Number.isFinite(p)) return 0
	return Math.min(100, Math.max(0, Math.round(p)))
})
const needsReview = computed(() => Number(stats.value.needsReview) || 0)
const overdue = computed(() => Number(stats.value.overdue) || 0)

// ── Relative last-modified ────────────────────────────────────────────────────
// lastModified is unix seconds (Board::jsonSerialize).
const lastModifiedMs = computed(() => {
	const s = Number(props.board.lastModified)
	return Number.isFinite(s) && s > 0 ? s * 1000 : 0
})

const relativeTime = computed(() => {
	const ms = lastModifiedMs.value
	if (!ms) return ''
	const diffSec = Math.floor((Date.now() - ms) / 1000)
	if (diffSec < 60) return t('kanso', 'just now')
	if (diffSec < 3600) return t('kanso', '{n} min ago', { n: Math.floor(diffSec / 60) })
	if (diffSec < 86400) return t('kanso', '{n} hr ago', { n: Math.floor(diffSec / 3600) })
	if (diffSec < 86400 * 7) return t('kanso', '{n} days ago', { n: Math.floor(diffSec / 86400) })
	return new Date(ms).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
})

const fullTime = computed(() => {
	const ms = lastModifiedMs.value
	if (!ms) return ''
	return new Date(ms).toLocaleString(undefined, {
		year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
	})
})
</script>

<style scoped>
.board-tile-content {
	display: flex;
	flex-direction: column;
	gap: 10px;
	min-width: 0;
	flex: 1;
}

.board-tile__head {
	display: flex;
	align-items: center;
	gap: 12px;
}

.board-tile__color-dot {
	flex-shrink: 0;
	width: 12px;
	height: 12px;
	border-radius: 50%;
}

.board-tile__title {
	font-weight: 600;
	color: var(--color-main-text);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	min-width: 0;
}

.board-tile__star-btn {
	margin-left: auto;
	flex-shrink: 0;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	padding: 0;
	border: none;
	border-radius: 50%;
	background: transparent;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
}

.board-tile__star-btn:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.board-tile__star-btn--on {
	color: var(--color-primary-element);
}

.board-tile__star {
	color: inherit;
}

.board-tile__star--on {
	color: var(--color-primary-element);
}

.board-tile__meta {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.board-tile__sep {
	color: var(--color-border-dark);
}

.board-tile__progress {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	min-width: 0;
}

.board-tile__progress-track {
	width: 60px;
	height: 6px;
	border-radius: 3px;
	background: var(--color-background-dark, #e0e0e0);
	overflow: hidden;
	flex-shrink: 0;
}

.board-tile__progress-fill {
	display: block;
	height: 100%;
	border-radius: 3px;
	background: var(--color-primary-element);
	transition: width 0.3s ease;
}

.board-tile__progress-pct {
	font-variant-numeric: tabular-nums;
}

.board-tile__badge--review {
	color: var(--color-warning, #c98600);
}

.board-tile__badge--overdue {
	color: var(--color-error);
}
</style>
