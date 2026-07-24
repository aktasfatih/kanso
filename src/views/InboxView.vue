<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="inbox-view">
		<NcEmptyContent
			:name="t('kanso', 'Inbox')"
			:description="t('kanso', 'Nothing here yet')">
			<template #icon>
				<BellIcon :size="64" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import BellIcon from 'vue-material-design-icons/Bell.vue'
</script>

<style scoped>
.inbox-view {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
	min-height: 300px;
}
</style>
