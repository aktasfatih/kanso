<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="my-reviews-view">
		<NcEmptyContent
			:name="t('kanso', 'My Reviews')"
			:description="t('kanso', 'Nothing here yet')">
			<template #icon>
				<CheckDecagramIcon :size="64" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
</script>

<style scoped>
.my-reviews-view {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
	min-height: 300px;
}
</style>
