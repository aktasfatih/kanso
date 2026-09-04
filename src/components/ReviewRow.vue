<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		class="review-row"
		role="button"
		tabindex="0"
		:aria-label="review.cardTitle"
		@click="emit('open')"
		@keydown.enter.prevent="emit('open')"
		@keydown.space.prevent="emit('open')">
		<!-- Main content area -->
		<div class="review-row__content">
			<!-- Card title + board title -->
			<div class="review-row__titles">
				<span class="review-row__card-title">{{ review.cardTitle }}</span>
				<span class="review-row__board-title">{{ review.boardTitle }}</span>
			</div>

			<!-- Meta: review type chip, requested by, timestamp -->
			<div class="review-row__meta">
				<span
					v-if="review.reviewTypeTitle"
					class="review-row__type-chip"
					:style="typeChipStyle">
					{{ review.reviewTypeTitle }}
				</span>
				<span class="review-row__requested-by">
					{{ t('kanso', 'by {user}', { user: review.requestedBy }) }}
				</span>
				<span class="review-row__time" :title="fullDate">{{ relativeTime }}</span>
			</div>
		</div>

		<!-- State indicator / action buttons -->
		<div class="review-row__actions" @click.stop>
			<!-- Approved: just a badge, no buttons -->
			<span v-if="review.state === 'approved'" class="review-row__state-badge review-row__state-badge--approved">
				<CheckDecagramIcon :size="16" />
				{{ t('kanso', 'Approved') }}
			</span>

			<!-- Pending or changes_requested: verdict buttons -->
			<template v-else>
				<NcButton
					type="success"
					:disabled="isMutating"
					size="small"
					@click="emit('approve')">
					<template #icon>
						<CheckDecagramIcon :size="16" />
					</template>
					{{ t('kanso', 'Approve') }}
				</NcButton>
				<NcButton
					type="error"
					:disabled="isMutating"
					size="small"
					@click="emit('request-changes')">
					<template #icon>
						<AlertDecagramIcon :size="16" />
					</template>
					{{ t('kanso', 'Request changes') }}
				</NcButton>
			</template>
		</div>
	</div>
</template>

<script setup>
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import AlertDecagramIcon from 'vue-material-design-icons/AlertDecagram.vue'
import { cssColor, readableColor } from '../services/color.js'

const props = defineProps({
	review: {
		type: Object,
		required: true,
	},
	isMutating: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['open', 'approve', 'request-changes'])

const typeChipStyle = computed(() => {
	if (!props.review.reviewTypeColor) return {}
	const color = cssColor(props.review.reviewTypeColor)
	return { background: color, color: readableColor(props.review.reviewTypeColor), borderColor: color }
})

const fullDate = computed(() => {
	if (!props.review.createdAt) return ''
	return new Date(props.review.createdAt * 1000).toLocaleString(undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
	})
})

const relativeTime = computed(() => {
	if (!props.review.createdAt) return ''
	const now = Date.now()
	const ms = props.review.createdAt * 1000
	const diffSec = Math.floor((now - ms) / 1000)
	if (diffSec < 60) return t('kanso', 'just now')
	if (diffSec < 3600) return t('kanso', '{n} min ago', { n: Math.floor(diffSec / 60) })
	if (diffSec < 86400) return t('kanso', '{n} hr ago', { n: Math.floor(diffSec / 3600) })
	if (diffSec < 86400 * 7) return t('kanso', '{n} days ago', { n: Math.floor(diffSec / 86400) })
	return new Date(ms).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
})
</script>

<style scoped>
.review-row {
	/* Legible success green for the approved badge: stock green in light,
	 * brighter #3fb950 under dark so text/tint stay readable. */
	--kanso-success-legible: var(--color-success, #46ba61);
	--kanso-success-legible-rgb: 70, 186, 97;

	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 14px;
	cursor: pointer;
	border-radius: var(--border-radius-large, 8px);
}

/* Brighten success green under dark themes (explicit picker + auto). */
body.theme--dark .review-row,
[data-theme-dark] .review-row,
[data-themes*='dark'] .review-row {
	--kanso-success-legible: #3fb950;
	--kanso-success-legible-rgb: 63, 185, 80;
}

@media (prefers-color-scheme: dark) {
	body.theme--default .review-row,
	body:not(.theme--light):not(.theme--dark) .review-row {
		--kanso-success-legible: #3fb950;
		--kanso-success-legible-rgb: 63, 185, 80;
	}
}

.review-row__content {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.review-row__titles {
	display: flex;
	align-items: baseline;
	gap: 8px;
	min-width: 0;
}

.review-row__card-title {
	font-weight: 500;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.review-row__board-title {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.review-row__meta {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 6px;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.review-row__type-chip {
	display: inline-flex;
	align-items: center;
	padding: 1px 7px;
	border-radius: 10px;
	font-size: 0.75rem;
	font-weight: 500;
	border: 1px solid transparent;
}

.review-row__requested-by,
.review-row__time {
	color: var(--color-text-maxcontrast);
}

.review-row__actions {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-shrink: 0;
}

.review-row__state-badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 0.8rem;
	font-weight: 500;
	padding: 3px 8px;
	border-radius: 10px;
}

.review-row__state-badge--approved {
	color: var(--kanso-success-legible);
	background: rgba(var(--kanso-success-legible-rgb), 0.1);
}

/* ── Responsive: stack the row on phones ─────────────────────────────────────
   The action cluster is `flex-shrink: 0` and holds two full-label buttons
   (~257px together), so on a phone-width row it claimed the whole line and the
   ellipsising title column absorbed the entire shortfall — the card title
   rendered as a single glyph and the meta line broke one word per line. Give
   the titles and the actions a full-width row each, exactly as the card modal
   header does at the same breakpoint (CardDetail.vue), and let the titles wrap
   instead of ellipsising to nothing. */
@media (max-width: 680px) {
	.review-row {
		flex-direction: column;
		align-items: stretch;
		gap: 10px;
	}

	/* Card title above board title, each on its own full-width line. */
	.review-row__titles {
		flex-direction: column;
		align-items: stretch;
		gap: 2px;
	}

	.review-row__card-title,
	.review-row__board-title {
		white-space: normal;
		/* `visible` is load-bearing — it resets the base `hidden` that made the
		   crushed title ellipsise. */
		overflow: visible;
		/* A single unbroken word (a URL-ish title) still has to break rather
		   than push the row wide. */
		overflow-wrap: anywhere;
		word-break: normal;
	}

	/* `align-self` keeps the cluster only as wide as its buttons: the row's own
	   `stretch` would give this element — which carries @click.stop — a
	   full-width strip of empty space that silently ate taps meant to open the
	   card. It still wraps onto a second line if the labels outgrow the row
	   (long translations, large font scaling) rather than overflowing it. */
	.review-row__actions {
		align-self: flex-start;
		flex-wrap: wrap;
	}
}
</style>
