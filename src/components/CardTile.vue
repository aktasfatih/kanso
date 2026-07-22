<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<button class="card-tile" @click="$emit('click')">
		<span class="card-tile__title">{{ card.title }}</span>
		<span
			v-if="card.duedate"
			class="card-tile__due"
			:class="dueDateClass">
			<CalendarIcon :size="14" />
			{{ formatDue(card.duedate) }}
		</span>
	</button>
</template>

<script setup>
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'

const props = defineProps({
	card: {
		type: Object,
		required: true,
	},
})

defineEmits(['click'])

const dueDateClass = computed(() => {
	if (!props.card.duedate) return ''
	const due = new Date(props.card.duedate)
	const now = new Date()
	if (due < now) return 'card-tile__due--overdue'
	const diff = due - now
	const hoursLeft = diff / (1000 * 60 * 60)
	if (hoursLeft <= 24) return 'card-tile__due--soon'
	return ''
})

function formatDue(iso) {
	const d = new Date(iso)
	return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}
</script>

<style scoped>
.card-tile {
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

.card-tile:hover {
	border-color: var(--color-primary);
	box-shadow: 0 1px 4px rgba(0, 0, 0, 0.06);
}

.card-tile:focus-visible {
	outline: 2px solid var(--color-primary);
	outline-offset: 1px;
}

.card-tile__title {
	color: var(--color-main-text);
	font-size: 0.875rem;
	line-height: 1.4;
	word-break: break-word;
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
	color: var(--color-error);
	border-color: var(--color-error);
	background: rgba(var(--color-error-rgb, 227, 0, 0), 0.08);
}

.card-tile__due--soon {
	color: var(--color-warning, #f0a844);
	border-color: var(--color-warning, #f0a844);
	background: rgba(240, 168, 68, 0.08);
}
</style>
