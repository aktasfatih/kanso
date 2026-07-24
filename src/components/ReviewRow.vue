<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="review-row" @click="emit('open')" role="listitem">
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
import { cssColor } from '../services/color.js'

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
	return { background: color, color: '#fff', borderColor: color }
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
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 14px;
	cursor: pointer;
	border-radius: var(--border-radius-large, 8px);
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
	color: var(--color-success);
	background: var(--color-success-hover, rgba(70, 150, 50, 0.1));
}
</style>
