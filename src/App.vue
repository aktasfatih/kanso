<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcContent app-name="kanso">
		<NcAppContent>
			<NcEmptyContent :name="t('kanso', 'Kanso')"
				:description="t('kanso', 'Fast kanban boards — coming together.')">
				<template #icon>
					<ViewColumnIcon />
				</template>
			</NcEmptyContent>
		</NcAppContent>
	</NcContent>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import ViewColumnIcon from 'vue-material-design-icons/ViewColumn.vue'

export default {
	name: 'App',
	components: {
		NcAppContent,
		NcContent,
		NcEmptyContent,
		ViewColumnIcon,
	},
	methods: {
		t,
	},
}
</script>
