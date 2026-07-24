<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcContent app-name="kanso">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem
					:name="t('kanso', 'Boards')"
					:to="{ name: 'board-list' }"
					:active="isBoardsActive">
					<template #icon>
						<ViewDashboardIcon :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('kanso', 'My Reviews')"
					:to="{ name: 'my-reviews' }"
					:active="isMyReviewsActive">
					<template #icon>
						<CheckDecagramIcon :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('kanso', 'Inbox')"
					:to="{ name: 'inbox' }"
					:active="isInboxActive">
					<template #icon>
						<BellIcon :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<router-view />
		</NcAppContent>
	</NcContent>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import ViewDashboardIcon from 'vue-material-design-icons/ViewDashboard.vue'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import BellIcon from 'vue-material-design-icons/Bell.vue'

const route = useRoute()

const isBoardsActive = computed(() =>
	route.name === 'board-list' || route.name === 'board' || route.name === 'card-modal',
)
const isMyReviewsActive = computed(() => route.name === 'my-reviews')
const isInboxActive = computed(() => route.name === 'inbox')
</script>
