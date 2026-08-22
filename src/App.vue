<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcContent app-name="kanso">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem
					v-if="isNavVisible('my-tasks')"
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
					v-if="isNavVisible('my-reviews')"
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
					v-if="isNavVisible('inbox')"
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
				<!-- Cross-board saved "Views" (#3815): named saved filters over ALL
				     readable boards, each opening a List/Timeline surface. Always
				     shown (even with none yet) so the first view can be created from
				     here — the "New view" entry at the bottom; otherwise it lists
				     views like the boards list. -->
				<NcAppNavigationItem
					v-if="isNavVisible('views')"
					:name="t('kanso', 'Views')"
					:allow-collapse="true"
					:open="viewsSectionOpen"
					@click="onViewsHeaderClick"
					@update:open="viewsSectionOpen = $event">
					<template #icon>
						<FilterVariantIcon :size="20" />
					</template>
					<template v-if="views.length > 0" #counter>
						<NcCounterBubble :count="views.length" />
					</template>
					<template #default>
						<NcAppNavigationItem
							v-for="v in views"
							:key="v.id"
							:name="v.name"
							:to="{ name: 'view', params: { id: String(v.id) } }"
							:active="isViewActive(v.id)">
							<template #icon>
								<FilterVariantIcon :size="18" />
							</template>
							<template #actions>
								<NcActionButton close-after-click @click="promptRenameView(v)">
									<template #icon>
										<PencilOutlineIcon :size="20" />
									</template>
									{{ t('kanso', 'Rename') }}
								</NcActionButton>
								<NcActionButton close-after-click @click="confirmDeleteView(v)">
									<template #icon>
										<DeleteOutlineIcon :size="20" />
									</template>
									{{ t('kanso', 'Delete') }}
								</NcActionButton>
							</template>
						</NcAppNavigationItem>
						<!-- Create entry — the only way to make the first view (#3891). -->
						<NcAppNavigationItem
							class="app-nav__view-new"
							:name="views.length > 0 ? t('kanso', 'New view') : t('kanso', 'Create your first view')"
							:loading="creatingView"
							@click="createView">
							<template #icon>
								<PlusIcon :size="18" />
							</template>
						</NcAppNavigationItem>
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem
					v-if="isNavVisible('projects')"
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
					:allow-collapse="navBoards.length > 0"
					:open="boardsSectionOpen"
					@update:open="boardsSectionOpen = $event">
					<template #icon>
						<ViewDashboardIcon :size="20" />
					</template>
					<!-- The user's PINNED boards (#3632), organized into per-user
					     folders (#3529), so you can jump between the ones you care
					     about without returning to the board list. Folders render
					     first (collapsible, collapse state persisted per user), then
					     any Ungrouped boards. A star toggle on each item pins/unpins.
					     Zero-pins fallback: a user with no pins sees ALL boards. -->
					<template #default>
						<NcAppNavigationItem
							v-for="group in navGroups"
							:key="'g' + group.id"
							:name="group.name"
							:allow-collapse="true"
							:open="!collapsedIds.has(group.id)"
							@update:open="(open) => setGroupOpen(group.id, open)">
							<template #icon>
								<FolderOutlineIcon :size="20" />
							</template>
							<template #counter>
								<NcCounterBubble :count="group.boards.length" />
							</template>
							<template #default>
								<NcAppNavigationItem
									v-for="board in group.boards"
									:key="board.id"
									:name="board.title"
									:to="{ name: 'board', params: { id: String(board.id) } }"
									:active="isBoardActive(board.id)">
									<template #icon>
										<span
											class="app-nav__board-dot"
											:style="{ background: board.color ? '#' + board.color : 'var(--color-primary-element)' }" />
									</template>
									<template #actions>
										<NcActionButton close-after-click @click="togglePin(board)">
											<template #icon>
												<StarIcon v-if="board.pinned" :size="20" />
												<StarOutlineIcon v-else :size="20" />
											</template>
											{{ board.pinned ? t('kanso', 'Unpin board') : t('kanso', 'Pin board') }}
										</NcActionButton>
									</template>
								</NcAppNavigationItem>
								<p v-if="group.boards.length === 0" class="app-nav__folder-empty">
									{{ t('kanso', 'No boards in this folder') }}
								</p>
							</template>
						</NcAppNavigationItem>
						<NcAppNavigationItem
							v-for="board in ungroupedBoards"
							:key="board.id"
							:name="board.title"
							:to="{ name: 'board', params: { id: String(board.id) } }"
							:active="isBoardActive(board.id)">
							<template #icon>
								<span
									class="app-nav__board-dot"
									:style="{ background: board.color ? '#' + board.color : 'var(--color-primary-element)' }" />
							</template>
							<template #actions>
								<NcActionButton close-after-click @click="togglePin(board)">
									<template #icon>
										<StarIcon v-if="board.pinned" :size="20" />
										<StarOutlineIcon v-else :size="20" />
									</template>
									{{ board.pinned ? t('kanso', 'Unpin board') : t('kanso', 'Pin board') }}
								</NcActionButton>
							</template>
						</NcAppNavigationItem>
					</template>
				</NcAppNavigationItem>
			</template>
			<!-- Always-visible help affordance (#3901). Lives in the nav footer so
			     it's reachable from anywhere in the app (any board/view/page), not
			     only inside a board. A single "Help" menu holds two external links:
			     file a bug/issue, and reach the MCP setup docs. NcActionLink renders
			     a real <a> with target="_blank" and rel="…noreferrer noopener". -->
			<template #footer>
				<div class="app-nav__footer">
					<!-- App-settings entry (#69): opens the Kanso settings dialog where
					     the user chooses which left-nav sections are shown. -->
					<NcButton
						type="tertiary"
						:aria-label="t('kanso', 'Kanso settings')"
						data-test="open-settings"
						@click="settingsOpen = true">
						<template #icon>
							<CogIcon :size="20" />
						</template>
						{{ t('kanso', 'Settings') }}
					</NcButton>
					<NcActions :force-menu="true" :aria-label="t('kanso', 'Help')">
						<template #icon>
							<HelpCircleOutlineIcon :size="20" />
						</template>
						<NcActionLink
							:href="ISSUES_URL"
							target="_blank"
							data-test="menu-file-issue">
							<template #icon>
								<BugOutlineIcon :size="20" />
							</template>
							{{ t('kanso', 'File an issue') }}
						</NcActionLink>
						<NcActionLink
							:href="MCP_SETUP_URL"
							target="_blank"
							data-test="menu-setup-mcp">
							<template #icon>
								<ConnectionIcon :size="20" />
							</template>
							{{ t('kanso', 'Set up MCP') }}
						</NcActionLink>
					</NcActions>
				</div>
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<router-view />
		</NcAppContent>
		<!-- Per-user app settings (#69). Minimal slice: choose which top-level
		     sidebar sections are shown. Boards is intentionally not toggleable
		     (always shown) so navigation can't be stranded. -->
		<NcAppSettingsDialog
			:open="settingsOpen"
			:show-navigation="true"
			:name="t('kanso', 'Kanso settings')"
			@update:open="settingsOpen = $event">
			<NcAppSettingsSection id="sidebar" :name="t('kanso', 'Sidebar')">
				<p class="app-settings__intro">
					{{ t('kanso', 'Choose which sections appear in the left sidebar.') }}
				</p>
				<NcCheckboxRadioSwitch
					v-for="section in navSections"
					:key="section.key"
					type="switch"
					:model-value="isNavVisible(section.key)"
					@update:model-value="toggleNavSection(section.key)">
					{{ section.label }}
				</NcCheckboxRadioSwitch>
			</NcAppSettingsSection>
		</NcAppSettingsDialog>
	</NcContent>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import { getSettings, updateSettings } from './services/api.js'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppSettingsDialog from '@nextcloud/vue/components/NcAppSettingsDialog'
import NcAppSettingsSection from '@nextcloud/vue/components/NcAppSettingsSection'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionLink from '@nextcloud/vue/components/NcActionLink'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import ViewDashboardIcon from 'vue-material-design-icons/ViewDashboard.vue'
import FormatListChecksIcon from 'vue-material-design-icons/FormatListChecks.vue'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import BellOutlineIcon from 'vue-material-design-icons/BellOutline.vue'
import FolderMultipleOutlineIcon from 'vue-material-design-icons/FolderMultipleOutline.vue'
import FolderOutlineIcon from 'vue-material-design-icons/FolderOutline.vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import StarOutlineIcon from 'vue-material-design-icons/StarOutline.vue'
import FilterVariantIcon from 'vue-material-design-icons/FilterVariant.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PencilOutlineIcon from 'vue-material-design-icons/PencilOutline.vue'
import DeleteOutlineIcon from 'vue-material-design-icons/DeleteOutline.vue'
import HelpCircleOutlineIcon from 'vue-material-design-icons/HelpCircleOutline.vue'
import BugOutlineIcon from 'vue-material-design-icons/BugOutline.vue'
import ConnectionIcon from 'vue-material-design-icons/Connection.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import { useBoards } from './composables/useBoards.js'
import { useBoardGroups } from './composables/useBoardGroups.js'
import { useMyWorkBadges } from './composables/useMyWorkBadges.js'
import { useViews } from './composables/useViews.js'

const route = useRoute()
const router = useRouter()

// Help-menu destinations (#3901). Static external URLs — deliberately NOT
// translated (they're addresses, not copy). Rendered by the nav-footer help
// menu as real anchors opening in a new tab.
const ISSUES_URL = 'https://github.com/aktasfatih/kanso/issues'
const MCP_SETUP_URL = 'https://github.com/aktasfatih/kanso/tree/main/mcp'

// Default-board-on-start: only when the app opened on the board list (never
// override a deep link). On first load, if the user picked a default board and
// it still exists (non-archived), redirect there once.
onMounted(async () => {
	// Load the per-user folder collapse state (non-blocking for the redirect below).
	loadCollapsed()
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

const { data: boardsData, togglePin } = useBoards()
// Non-archived boards, listed under the Boards nav entry so the user can jump
// between them. Reactive to the shared boards query (create/rename/delete reflect).
const boards = computed(() => (boardsData.value ?? []).filter((b) => !b.archived))

// The curated nav set (#3632): the user's PINNED boards. Zero-pins fallback — a
// user who has pinned nothing sees ALL their boards, so the nav is never empty
// for a new user. Pinning drives which boards appear here.
const navBoards = computed(() => {
	const pinned = boards.value.filter((b) => b.pinned)
	return pinned.length > 0 ? pinned : boards.value
})

// Per-user folders (#3529). The folder definitions come from their own query;
// membership rides each board's `groupId` on the boards payload. Folders render
// in their stored order, each holding its member boards from the curated nav set.
const { data: groupsData } = useBoardGroups()
const navGroups = computed(() => {
	const list = Array.isArray(groupsData.value) ? groupsData.value : []
	return list.map((g) => ({
		id: g.id,
		name: g.name,
		boards: navBoards.value.filter((b) => Number(b.groupId) === Number(g.id)),
	}))
})
// Boards in no folder (groupId null/undefined) render after the folders.
const ungroupedBoards = computed(() =>
	navBoards.value.filter((b) => b.groupId === null || b.groupId === undefined),
)

// Collapse state persisted per user (NC IConfig via settings). Loaded once on
// mount; each toggle writes the new collapsed-id set back.
const collapsedIds = ref(new Set())
// Whether the Boards nav section is expanded. Transient (not persisted) and
// defaults to open, so the curated nav is always visible on load. The #3628
// persisted collapse-all was removed in favour of pinning (#3632).
const boardsSectionOpen = ref(true)
async function loadCollapsed() {
	try {
		const s = await getSettings()
		collapsedIds.value = new Set((s.collapsedBoardGroups ?? []).map(Number))
		hiddenNav.value = new Set(s.hiddenNavSections ?? [])
	} catch {
		// Non-fatal: folders just start expanded and all sections stay visible.
	}
}
function setGroupOpen(groupId, open) {
	const next = new Set(collapsedIds.value)
	if (open) {
		next.delete(Number(groupId))
	} else {
		next.add(Number(groupId))
	}
	collapsedIds.value = next
	// Fire-and-forget persist; the local set already reflects the change.
	updateSettings({ collapsedBoardGroups: [...next] }).catch(() => {})
}

// Per-user show/hide of top-level nav sections (#69). A Set of hidden section
// keys, loaded from settings on mount (in loadCollapsed) and persisted on each
// toggle. Boards is never in this set — it has no toggle and is always shown.
const hiddenNav = ref(new Set())
const settingsOpen = ref(false)
// The toggleable sections, in nav order, with their display names. This is the
// frontend mirror of the backend ALLOWED_NAV allow-list.
const navSections = computed(() => [
	{ key: 'my-tasks', label: t('kanso', 'My Tasks') },
	{ key: 'my-reviews', label: t('kanso', 'My Reviews') },
	{ key: 'inbox', label: t('kanso', 'Inbox') },
	{ key: 'views', label: t('kanso', 'Views') },
	{ key: 'projects', label: t('kanso', 'Projects') },
])
function isNavVisible(key) {
	return !hiddenNav.value.has(key)
}
// Optimistically flip a section's visibility and persist; revert the local Set
// if the write fails so the UI stays consistent with the server.
function toggleNavSection(key) {
	const previous = hiddenNav.value
	const next = new Set(previous)
	if (next.has(key)) {
		next.delete(key)
	} else {
		next.add(key)
	}
	hiddenNav.value = next
	updateSettings({ hiddenNavSections: [...next] }).catch(() => {
		hiddenNav.value = previous
	})
}

function isBoardActive(boardId) {
	return (route.name === 'board' || route.name === 'card-modal')
		&& String(route.params.id) === String(boardId)
}
const isMyTasksActive = computed(() => route.name === 'my-cards')
const isMyReviewsActive = computed(() => route.name === 'my-reviews')
const isInboxActive = computed(() => route.name === 'inbox')
const isProjectsActive = computed(() => route.name === 'projects' || route.name === 'project')

// Cross-board saved "Views" (#3815). The nav section lists the user's views and
// offers rename/delete; each item opens /views/:id. Kept mounted so the list
// warms once. viewsSectionOpen is transient (defaults open).
const { data: viewsData, save: saveView, rename: renameView, remove: removeView } = useViews()
const views = computed(() => viewsData.value ?? [])
const viewsSectionOpen = ref(true)
function isViewActive(id) {
	return route.name === 'view' && String(route.params.id) === String(id)
}
// The "Views" header is a collapsible section with no page of its own. Without
// this, NcAppNavigationItem renders it as an <a href="#"> that only preventDefaults
// for real router links, so a bare click fell through to the hash router and
// navigated to "/" (the boards page). Swallow the navigation and just toggle the
// section instead.
function onViewsHeaderClick(event) {
	event?.preventDefault?.()
	viewsSectionOpen.value = !viewsSectionOpen.value
}

// Create a new (empty, all-boards) view and open it so the user can set its
// filter / group-by / display and rename it in place. This is the entry point a
// user with zero views needs — without it the feature is unreachable (#3891).
const creatingView = ref(false)
async function createView() {
	if (creatingView.value) return
	creatingView.value = true
	try {
		// Unique default name so the upsert-by-name create never collides with an
		// existing view; the user renames it from the view header.
		const base = t('kanso', 'New view')
		const taken = new Set(views.value.map((v) => v.name))
		let name = base
		for (let n = 2; taken.has(name); n++) name = `${base} ${n}`
		const list = await saveView.mutateAsync({ name, filter: {}, groupBy: 'status', display: 'list' })
		viewsSectionOpen.value = true
		const created = (list ?? []).find((v) => v.name === name)
		if (created) router.push({ name: 'view', params: { id: String(created.id) } })
	} finally {
		creatingView.value = false
	}
}
function promptRenameView(view) {
	// eslint-disable-next-line no-alert
	const name = window.prompt(t('kanso', 'Rename view'), view.name)
	if (name === null) return
	const trimmed = name.trim()
	if (trimmed === '' || trimmed === view.name) return
	renameView.mutate({ id: view.id, name: trimmed })
}
function confirmDeleteView(view) {
	// eslint-disable-next-line no-alert
	if (!window.confirm(t('kanso', 'Delete view "{name}"?', { name: view.name }))) return
	// If the deleted view is open, fall back to the board list.
	if (isViewActive(view.id)) router.push({ name: 'board-list' })
	removeView.mutate(view.id)
}

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

.app-nav__folder-empty {
	margin: 0;
	padding: 4px 0 4px 44px;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	font-style: italic;
}

/* Nav-footer help affordance (#3901): keep the trigger tucked to the start so
   it reads as a quiet, always-present help control rather than a primary CTA. */
.app-nav__footer {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 4px;
}

/* Settings-dialog intro copy: quiet, legible in light and dark via NC vars. */
.app-settings__intro {
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast);
}
</style>

<!--
	App-wide reduced-motion guard (#3415). A single global rule (deliberately
	UNSCOPED) that respects the OS "reduce motion" setting across the whole Kanso
	app at once: it neutralizes every transition, animation (incl. the shimmer
	skeletons), and smooth-scroll. This is the canonical place for the app's
	motion policy — a later a11y card references it, so it is kept clean and
	global rather than per-component. Individual components no longer need their
	own reduced-motion guards.
-->
<style>
@media (prefers-reduced-motion: reduce) {
	*,
	::before,
	::after {
		transition-duration: 0.01ms !important;
		animation: none !important;
		scroll-behavior: auto !important;
	}
}
</style>
