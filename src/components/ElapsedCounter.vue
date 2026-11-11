<!--
  - SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!-- Owns the per-second tick so it re-renders only itself, never the card detail's inputs. -->
<template>
	<span>{{ format(Math.max(0, now - startedAt)) }}</span>
</template>

<script setup>
import { onBeforeUnmount, ref } from 'vue'

defineProps({
	startedAt: { type: Number, required: true },
	format: { type: Function, required: true },
})

const now = ref(Math.floor(Date.now() / 1000))
const interval = setInterval(() => {
	now.value = Math.floor(Date.now() / 1000)
}, 1000)
onBeforeUnmount(() => clearInterval(interval))
</script>
