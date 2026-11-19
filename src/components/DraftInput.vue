<!--
  - SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!-- An input that commits on blur/change: model changes flow in, but never over what the user is typing.
     Uncontrolled on purpose - a `:value` binding would be re-applied on every re-render. -->
<template>
	<input ref="input" @input="markDateInteraction" @keydown="onKeydown" @blur="dateInteracted = false">
</template>

<script setup>
import { onMounted, ref, watch } from 'vue'

const props = defineProps({
	modelValue: { type: [String, Number], default: null },
})

const text = (v) => (v == null ? '' : String(v))
const input = ref(null)
let synced = text(props.modelValue)
let dateInteracted = false

function markDateInteraction() {
	if (['date', 'datetime-local'].includes(input.value?.type)) dateInteracted = true
}

function onKeydown(event) {
	if (!event.ctrlKey && !event.metaKey && !event.altKey
		&& (event.key.length === 1 || ['Backspace', 'Delete', 'ArrowUp', 'ArrowDown'].includes(event.key))) {
		markDateInteraction()
	}
}

onMounted(() => {
	input.value.value = synced
})

watch(() => props.modelValue, (value) => {
	const next = text(value)
	const el = input.value
	// Native date inputs expose an empty value while only some segments are filled.
	const editing = el && el.ownerDocument.activeElement === el
		&& (dateInteracted || (el.value !== synced && el.value !== next))
	synced = next
	if (el && !editing) el.value = next
})
</script>
