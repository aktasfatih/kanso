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
					:active="route.name === 'board-list'"
					:allow-collapse="boards.length > 0"
					:open="true">
					<template #icon>
						<ViewDashboardIcon :size="20" />
					</template>
					<!-- The user's boards, so you can jump between them without
					     returning to the board list. -->
					<template #default>
						<NcAppNavigationItem
							v-for="board in boards"
							:key="board.id"
							:name="board.title"
							:to="{ name: 'board', params: { id: String(board.id) } }"
							:active="isBoardActive(board.id)">
							<template #icon>
								<span
									class="app-nav__board-dot"
									:style="{ background: board.color ? '#' + board.color : 'var(--color-primary-element)' }" />
							</template>
						</NcAppNavigationItem>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('kanso', 'My tasks')"
					:to="{ name: 'my-cards' }"
					:active="isMyCardsActive">
					<template #icon>
						<FormatListChecksIcon :size="20" />
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
import FormatListChecksIcon from 'vue-material-design-icons/FormatListChecks.vue'
import BellIcon from 'vue-material-design-icons/Bell.vue'
import { useBoards } from './composables/useBoards.js'

const route = useRoute()

const { data: boardsData } = useBoards()
// Non-archived boards, listed under the Boards nav entry so the user can jump
// between them. Reactive to the shared boards query (create/rename/delete reflect).
const boards = computed(() => (boardsData.value ?? []).filter((b) => !b.archived))

function isBoardActive(boardId) {
	return (route.name === 'board' || route.name === 'card-modal')
		&& String(route.params.id) === String(boardId)
}
const isMyCardsActive = computed(() => route.name === 'my-cards')
const isMyReviewsActive = computed(() => route.name === 'my-reviews')
const isInboxActive = computed(() => route.name === 'inbox')
</script>

<style scoped>
.app-nav__board-dot {
	display: inline-block;
	width: 14px;
	height: 14px;
	border-radius: 50%;
	border: 1px solid var(--color-border-dark);
}
</style>
