<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<NcSelect
			v-model="selected"
			:input-id="inputId"
			:options="candidates"
			:loading="isFetching"
			:disabled="disabled"
			:filterable="false"
			label-outside
			:placeholder="t('kanso', 'No lead')"
			label="displayName"
			@search="search">
			<template #no-options>{{ t('kanso', 'No users found.') }}</template>
		</NcSelect>
		<span v-if="isError" class="project-lead-picker__error" role="alert">{{ t('kanso', 'Failed to search.') }}</span>
		<p class="project-lead-picker__hint">{{ t('kanso', 'Choosing a lead does not share the project.') }}</p>
	</div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { useQuery } from '@tanstack/vue-query'
import { translate as t } from '@nextcloud/l10n'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { searchProjectLeads } from '../services/api.js'

const props = defineProps({
	modelValue: { type: String, default: '' },
	displayName: { type: String, default: '' },
	inputId: { type: String, required: true },
	disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])
const query = ref('')
const picked = ref(null)
let timer

const { data, isFetching, isError } = useQuery({
	queryKey: computed(() => ['project-leads', query.value]),
	queryFn: () => searchProjectLeads(query.value),
})
const candidates = computed(() => data.value ?? [])
const selected = computed({
	get: () => {
		if (!props.modelValue) return null
		if (picked.value?.id === props.modelValue) return picked.value
		return candidates.value.find((user) => user.id === props.modelValue)
			?? { id: props.modelValue, displayName: props.displayName || props.modelValue }
	},
	set: (user) => {
		picked.value = user
		emit('update:modelValue', user?.id ?? '')
	},
})

function search(value) {
	clearTimeout(timer)
	const text = value.trim()
	timer = setTimeout(() => { query.value = text.length >= 2 ? text : '' }, 250)
}

onBeforeUnmount(() => clearTimeout(timer))
</script>

<style scoped>
.project-lead-picker__hint {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.project-lead-picker__error {
	color: var(--color-error);
}
</style>
