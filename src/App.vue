<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcContent app-name="kanso">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem
					:name="t('kanso', 'My Tasks')"
					:to="{ name: 'my-cards' }"
					:active="isMyTasksActive">
					<template #icon>
						<FormatListChecksIcon :size="20" />
					</template>
					<template v-if="tasksCount > 0" #counter>
						<NcCounterBubble :count="tasksCount" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('kanso', 'My Reviews')"
					:to="{ name: 'my-reviews' }"
					:active="isMyReviewsActive">
					<template #icon>
						<CheckDecagramIcon :size="20" />
					</template>
					<template v-if="reviewsCount > 0" #counter>
						<NcCounterBubble :count="reviewsCount" type="highlighted" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('kanso', 'Inbox')"
					:to="{ name: 'inbox' }"
					:active="isInboxActive">
					<template #icon>
						<BellOutlineIcon :size="20" />
					</template>
					<template v-if="inboxCount > 0" #counter>
						<NcCounterBubble :count="inboxCount" type="highlighted" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('kanso', 'Projects')"
					:to="{ name: 'projects' }"
					:active="isProjectsActive">
					<template #icon>
						<FolderMultipleOutlineIcon :size="20" />
					</template>
				</NcAppNavigationItem>
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
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<router-view />
		</NcAppContent>
	</NcContent>
</template>

<script setup>
import { computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import { getSettings } from './services/api.js'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import ViewDashboardIcon from 'vue-material-design-icons/ViewDashboard.vue'
import FormatListChecksIcon from 'vue-material-design-icons/FormatListChecks.vue'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import BellOutlineIcon from 'vue-material-design-icons/BellOutline.vue'
import FolderMultipleOutlineIcon from 'vue-material-design-icons/FolderMultipleOutline.vue'
import { useBoards } from './composables/useBoards.js'
import { useMyWorkBadges } from './composables/useMyWorkBadges.js'

const route = useRoute()
const router = useRouter()

// Default-board-on-start: only when the app opened on the board list (never
// override a deep link). On first load, if the user picked a default board and
// it still exists (non-archived), redirect there once.
onMounted(async () => {
	// Wait for the router to resolve the initial route before reading its name.
	await router.isReady()
	if (route.name !== 'board-list') return
	let defaultBoardId = null
	try {
		defaultBoardId = (await getSettings()).defaultBoardId
	} catch {
		return
	}
	if (!defaultBoardId) return
	const stop = watch(
		boards,
		(list) => {
			if (route.name !== 'board-list') { stop(); return } // user navigated away
			if (!Array.isArray(list) || list.length === 0) return // still loading
			if (list.some((b) => Number(b.id) === Number(defaultBoardId))) {
				router.replace({ name: 'board', params: { id: String(defaultBoardId) } })
			}
			stop()
		},
		{ immediate: true },
	)
})

const { data: boardsData } = useBoards()
// Non-archived boards, listed under the Boards nav entry so the user can jump
// between them. Reactive to the shared boards query (create/rename/delete reflect).
const boards = computed(() => (boardsData.value ?? []).filter((b) => !b.archived))

function isBoardActive(boardId) {
	return (route.name === 'board' || route.name === 'card-modal')
		&& String(route.params.id) === String(boardId)
}
const isMyTasksActive = computed(() => route.name === 'my-cards')
const isMyReviewsActive = computed(() => route.name === 'my-reviews')
const isInboxActive = computed(() => route.name === 'inbox')
const isProjectsActive = computed(() => route.name === 'projects' || route.name === 'project')

// Badge counts for the three My Work nav items. Reuses the existing feed
// queries from the shared query cache (no new polling); mounting the nav warms
// them once for the whole app.
const { tasksCount, reviewsCount, inboxCount } = useMyWorkBadges()
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
