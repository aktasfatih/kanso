<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		class="board-view"
		:class="{ 'board-view--has-background': boardBackground }"
		:style="boardBackground ? { '--board-background': boardBackground } : {}">
		<!-- Header -->
		<div ref="headerRef" class="board-view__header">
			<NcButton class="board-view__back" :aria-label="t('kanso', 'All boards')" @click="goBack">
				<template #icon>
					<ArrowLeftIcon :size="20" />
				</template>
				<template v-if="!isNarrow">{{ t('kanso', 'All boards') }}</template>
			</NcButton>
			<h1 v-if="boardData" class="board-view__title">
				<span
					v-if="boardData.board.color"
					class="board-view__color-dot"
					:style="{ background: boardData.board.color }" />
				<span class="board-view__title-text">{{ boardData.board.title }}</span>
			</h1>
			<div v-else-if="isLoading" class="board-view__title-skeleton skeleton-text" />

			<!-- In-board search - scoped to the current board; only rendered once
			     the board payload is available so boardId is valid. -->
			<SearchBox
				v-if="boardData"
				ref="searchBoxRef"
				class="board-view__search"
				:board-id="props.id"
				:compact="isNarrow" />

			<!-- View switch - Board (columns) vs List (table). Persisted per board. -->
			<NcActions
				v-if="boardData && !isNarrow"
				class="board-view__view-menu"
				:menu-name="viewModeLabel">
				<template #icon>
					<ChartTimelineIcon v-if="viewMode === 'timeline'" :size="20" />
					<FormatListBulletedIcon v-else-if="viewMode === 'list'" :size="20" />
					<ViewColumnIcon v-else :size="20" />
				</template>
				<NcActionRadio
					:model-value="viewMode"
					value="board"
					name="kanso-viewmode"
					@update:model-value="setViewMode">
					{{ t('kanso', 'Board') }}
				</NcActionRadio>
				<NcActionRadio
					:model-value="viewMode"
					value="list"
					name="kanso-viewmode"
					@update:model-value="setViewMode">
					{{ t('kanso', 'List') }}
				</NcActionRadio>
				<NcActionRadio
					:model-value="viewMode"
					value="timeline"
					name="kanso-viewmode"
					@update:model-value="setViewMode">
					{{ t('kanso', 'Timeline') }}
				</NcActionRadio>
			</NcActions>

			<!-- Display sort - a view-only reorder within each stack (Board + List). -->
			<NcActions
				v-if="boardData && !isNarrow"
				class="board-view__sort-menu"
				:menu-name="t('kanso', 'Sort: {mode}', { mode: sortModeMenuName })">
				<template #icon>
					<SortIcon :size="20" />
				</template>
				<!-- Radio GROUP idiom: model-value is the group's current value and each
				     radio carries its own `value` (checked = model === value). This
				     reflects the active mode when the menu reopens, and switches on a
				     single click via @update:model-value (a boolean-per-radio +
				     @change binding shows nothing selected and needs a double click). -->
				<NcActionRadio :model-value="sortMode" value="manual" name="kanso-sort" @update:model-value="setSortMode">
					{{ t('kanso', 'Manual') }}
				</NcActionRadio>
				<NcActionRadio :model-value="sortMode" value="priority" name="kanso-sort" @update:model-value="setSortMode">
					{{ t('kanso', 'Priority') }}
				</NcActionRadio>
				<NcActionRadio :model-value="sortMode" value="due" name="kanso-sort" @update:model-value="setSortMode">
					{{ t('kanso', 'Due date') }}
				</NcActionRadio>
				<NcActionRadio :model-value="sortMode" value="title" name="kanso-sort" @update:model-value="setSortMode">
					{{ t('kanso', 'Title') }}
				</NcActionRadio>
				<NcActionRadio
					v-if="boardData?.board?.estimateScale && boardData.board.estimateScale !== 'none'"
					:model-value="sortMode"
					value="estimate"
					name="kanso-sort"
					@update:model-value="setSortMode">
					{{ t('kanso', 'Estimate') }}
				</NcActionRadio>
				<!-- Direction toggle — hidden for Manual (which has no direction). -->
				<template v-if="sortMode !== 'manual'">
					<NcActionSeparator />
					<NcActionRadio :model-value="sortDir" value="asc" name="kanso-sort-dir" @update:model-value="setSortDir">
						{{ t('kanso', 'Ascending') }}
					</NcActionRadio>
					<NcActionRadio :model-value="sortDir" value="desc" name="kanso-sort-dir" @update:model-value="setSortDir">
						{{ t('kanso', 'Descending') }}
					</NcActionRadio>
				</template>
			</NcActions>

			<!-- Compact density toggle (#3415) — a per-user, view-only switch that
			     tightens every card tile so more cards fit on screen. Persisted per
			     board per user. A pressed icon button sits with the other view
			     controls; aria-pressed reflects the state for assistive tech. -->
			<NcButton
				v-if="boardData && !isNarrow"
				class="board-view__density-toggle"
				type="tertiary"
				:pressed="isCompact"
				:aria-pressed="isCompact ? 'true' : 'false'"
				:aria-label="isCompact ? t('kanso', 'Switch to comfortable density') : t('kanso', 'Switch to compact density')"
				:title="isCompact ? t('kanso', 'Comfortable density') : t('kanso', 'Compact density')"
				@click="toggleDensity">
				<template #icon>
					<ViewCompactIcon v-if="isCompact" :size="20" />
					<ViewAgendaIcon v-else :size="20" />
				</template>
			</NcButton>

			<!-- Project chat (#3748): a plain deep link (typically a Talk room)
			     set in board settings (MANAGE); visible to every member when set.
			     NcButton renders an <a> with rel="nofollow noreferrer noopener"
			     when href is set; opens in a new tab. -->
			<NcButton
				v-if="boardChatUrl"
				class="board-view__chat-btn"
				type="tertiary"
				:href="boardChatUrl"
				target="_blank"
				:aria-label="t('kanso', 'Open project chat in a new tab')"
				:title="t('kanso', 'Project chat')"
				data-test="board-chat-btn">
				<template #icon>
					<ForumOutlineIcon :size="20" />
				</template>
			</NcButton>

			<!-- Composable filter bar (#3407) — labels / assignees / due / done /
			     priority, AND across dimensions & OR within, plus saved named views
			     (per-user NC config) and URL-query sharing. Generalizes the old
			     label/priority dropdown. Purely client-side over the summary payload. -->
			<BoardFilterBar
				v-if="boardData"
				class="board-view__filter-menu"
				:state="filterState"
				:labels="boardLabels"
				:participants="participants.data.value ?? []"
				:saved-filters="savedFilters"
				:active-saved-name="activeSavedName"
				:estimate-scale="boardData?.board?.estimateScale ?? 'none'"
				:compact="isNarrow"
				@save="handleSaveFilter"
				@apply-saved="handleApplySavedFilter"
				@delete-saved="handleDeleteSavedFilter" />
			<div v-if="filterError" class="board-view__filter-error">{{ filterError }}</div>

			<!-- Consolidated "More" overflow (variant 1a): the secondary board
			     actions live behind a single ⋯ menu so the toolbar reads as one
			     bar. Every action here stays fully reachable and keeps its handler,
			     permission gate, and any keyboard shortcut (e.g. the settings panel
			     is still toggled by its own control). Swimlanes (a view-only Board
			     grouping) are the radio group at the top; the rest are actions. -->
			<NcActions
				v-if="boardData"
				v-model:open="moreMenuOpen"
				class="board-view__more-menu"
				:menu-name="isNarrow ? undefined : t('kanso', 'More')"
				:aria-label="t('kanso', 'More board actions')">
				<template #icon>
					<DotsHorizontalIcon :size="20" />
				</template>

				<!-- Add column — moved off the board (was a persistent trailing input)
				     into this menu so the board stays uncluttered. Editors only.
				     Clicking reveals an on-demand composer at the end of the board and
				     focuses it (a text INPUT here would strip the menu's role=menuitem
				     semantics, so this stays a plain action button). -->
				<!-- Responsive consolidation (#mobile): when the header is too narrow
				     for the full toolbar, the view / sort / density controls collapse
				     in here so nothing is pushed off-screen. Same handlers/state as the
				     toolbar versions; distinct radio-group names avoid any overlap. -->
				<template v-if="isNarrow">
					<NcActionCaption :name="t('kanso', 'View')" />
					<NcActionRadio :model-value="viewMode" value="board" name="kanso-viewmode-m" @update:model-value="setViewMode">
						{{ t('kanso', 'Board') }}
					</NcActionRadio>
					<NcActionRadio :model-value="viewMode" value="list" name="kanso-viewmode-m" @update:model-value="setViewMode">
						{{ t('kanso', 'List') }}
					</NcActionRadio>
					<NcActionRadio :model-value="viewMode" value="timeline" name="kanso-viewmode-m" @update:model-value="setViewMode">
						{{ t('kanso', 'Timeline') }}
					</NcActionRadio>

					<NcActionCaption :name="t('kanso', 'Sort')" />
					<NcActionRadio :model-value="sortMode" value="manual" name="kanso-sort-m" @update:model-value="setSortMode">
						{{ t('kanso', 'Manual') }}
					</NcActionRadio>
					<NcActionRadio :model-value="sortMode" value="priority" name="kanso-sort-m" @update:model-value="setSortMode">
						{{ t('kanso', 'Priority') }}
					</NcActionRadio>
					<NcActionRadio :model-value="sortMode" value="due" name="kanso-sort-m" @update:model-value="setSortMode">
						{{ t('kanso', 'Due date') }}
					</NcActionRadio>
					<NcActionRadio :model-value="sortMode" value="title" name="kanso-sort-m" @update:model-value="setSortMode">
						{{ t('kanso', 'Title') }}
					</NcActionRadio>
					<NcActionRadio
						v-if="boardData?.board?.estimateScale && boardData.board.estimateScale !== 'none'"
						:model-value="sortMode"
						value="estimate"
						name="kanso-sort-m"
						@update:model-value="setSortMode">
						{{ t('kanso', 'Estimate') }}
					</NcActionRadio>
					<template v-if="sortMode !== 'manual'">
						<NcActionRadio :model-value="sortDir" value="asc" name="kanso-sort-dir-m" @update:model-value="setSortDir">
							{{ t('kanso', 'Ascending') }}
						</NcActionRadio>
						<NcActionRadio :model-value="sortDir" value="desc" name="kanso-sort-dir-m" @update:model-value="setSortDir">
							{{ t('kanso', 'Descending') }}
						</NcActionRadio>
					</template>

					<NcActionButton :aria-pressed="isCompact ? 'true' : 'false'" @click="toggleDensity">
						<template #icon>
							<ViewCompactIcon v-if="isCompact" :size="20" />
							<ViewAgendaIcon v-else :size="20" />
						</template>
						{{ isCompact ? t('kanso', 'Comfortable density') : t('kanso', 'Compact density') }}
					</NcActionButton>
					<NcActionSeparator />
				</template>

				<template v-if="canEditBoard">
					<NcActionButton
						class="board-view__add-column-btn"
						:close-after-click="true"
						@click="revealAddColumn">
						<template #icon>
							<PlusIcon :size="20" />
						</template>
						{{ t('kanso', 'Add column') }}
					</NcActionButton>
					<NcActionSeparator />
				</template>

				<!-- Swimlanes - client-side grouping of the Board view into
				     horizontal lanes by assignee / label / priority. View-only over
				     the summary payload; persisted per board per user. Only shown in
				     Board view. -->
				<template v-if="viewMode === 'board'">
					<NcActionCaption :name="t('kanso', 'Swimlanes')" />
					<NcActionRadio
						:model-value="swimlaneMode"
						value="none"
						name="kanso-swimlane"
						@update:model-value="setSwimlaneMode">
						{{ t('kanso', 'No swimlanes') }}
					</NcActionRadio>
					<NcActionRadio
						:model-value="swimlaneMode"
						value="assignee"
						name="kanso-swimlane"
						@update:model-value="setSwimlaneMode">
						{{ t('kanso', 'Group by assignee') }}
					</NcActionRadio>
					<NcActionRadio
						:model-value="swimlaneMode"
						value="label"
						name="kanso-swimlane"
						@update:model-value="setSwimlaneMode">
						{{ t('kanso', 'Group by label') }}
					</NcActionRadio>
					<NcActionRadio
						:model-value="swimlaneMode"
						value="priority"
						name="kanso-swimlane"
						@update:model-value="setSwimlaneMode">
						{{ t('kanso', 'Group by priority') }}
					</NcActionRadio>
					<NcActionSeparator />
				</template>

				<!-- Archived cards page - only offered when ≥1 archived card. The
				     visible label already carries the count, so no separate aria-label
				     is set (that would override the accessible name). -->
				<NcActionButton
					v-if="archivedCards.length > 0"
					class="board-view__archived-btn"
					@click="goToArchived">
					<template #icon>
						<ArchiveIcon :size="20" />
					</template>
					{{ t('kanso', 'Archived cards ({count})', { count: archivedCards.length }) }}
				</NcActionButton>

				<!-- Watch / unwatch this board - subscribes to a "new card created"
				     notification. Uses the same eye icon as the card-level watcher:
				     eye-off (crossed) when watching, plain eye outline when not. -->
				<NcActionButton
					class="board-view__watch-btn"
					:aria-pressed="isBoardSubscribed ? 'true' : 'false'"
					@click="moreMenuOpen = false; toggleBoardWatch()">
					<template #icon>
						<EyeOffOutlineIcon v-if="isBoardSubscribed" :size="20" />
						<EyeOutlineIcon v-else :size="20" />
					</template>
					{{ isBoardSubscribed ? t('kanso', 'Unwatch board') : t('kanso', 'Watch board') }}
				</NcActionButton>

				<!-- Trash - opens the routed Trash page -->
				<NcActionButton
					class="board-view__trash-btn"
					@click="goToTrash">
					<template #icon>
						<DeleteIcon :size="20" />
					</template>
					{{ t('kanso', 'Deleted cards') }}
				</NcActionButton>

				<!-- Analytics - navigates to the board stats page -->
				<NcActionButton
					class="board-view__analytics-btn"
					@click="goToStats">
					<template #icon>
						<ChartBarIcon :size="20" />
					</template>
					{{ t('kanso', 'Board analytics') }}
				</NcActionButton>

				<!-- Multi-select mode toggle -->
				<NcActionButton
					class="board-view__multiselect-btn"
					:aria-pressed="bulk.selectionMode.value ? 'true' : 'false'"
					@click="moreMenuOpen = false; bulk.selectionMode.value ? bulk.exitMode() : bulk.enterMode()">
					<template #icon>
						<SelectMultipleIcon :size="20" />
					</template>
					{{ bulk.selectionMode.value ? t('kanso', 'Exit multi-select') : t('kanso', 'Select multiple cards') }}
				</NcActionButton>

				<NcActionSeparator />

				<!-- Settings - toggles the right-docked settings panel. Closing the
				     overflow menu first is required: the panel is a non-blocking
				     docked drawer, so a lingering menu popover would sit over it and
				     swallow clicks / Escape. -->
				<NcActionButton
					class="board-view__settings-btn"
					:aria-expanded="showSettings ? 'true' : 'false'"
					@click="moreMenuOpen = false; showSettings = !showSettings">
					<template #icon>
						<CogIcon :size="20" />
					</template>
					{{ t('kanso', 'Board settings') }}
				</NcActionButton>
			</NcActions>
		</div>

		<!-- Board settings modal (Labels + Sharing tabs) -->
		<BoardSettingsModal
			v-if="showSettings && boardData"
			:board-id="props.id"
			:labels="boardLabels"
			:review-types="boardData.reviewTypes ?? []"
			:card-fields="boardData.cardFields ?? []"
			:acl="boardData.acl ?? []"
			:permissions="boardData.permissions ?? 0"
			:participants="participants.data.value ?? []"
			:current-user-id="currentUserId"
			:stacks="boardData.stacks ?? []"
			:cards="boardData.cards ?? []"
			@close="showSettings = false"
			@leave="showSettings = false" />

		<!-- Card-template manager (#3634): view / edit / delete / unmark / create the
		     board's templates. Templates are hidden from the board, so this modal is
		     how they are found and managed. Opened from a column's "＋ From template"
		     menu. -->
		<ManageTemplatesModal
			v-if="showManageTemplates && boardData"
			:board-id="Number(props.id)"
			:can-edit="canEditBoard"
			:new-template-stack-id="firstStackId"
			@edit="openTemplateForEdit"
			@close="showManageTemplates = false" />

		<!-- Screen-reader live region: announces the user's own card moves and
		     label/assignee changes (drag-and-drop has no visible SR feedback). -->
		<div class="board-view__sr-only" aria-live="polite" role="status">
			{{ announceMessage }}
		</div>

		<!-- DnD / shortcut error banner -->
		<div v-if="moveError || shortcutError" class="board-view__move-error">
			{{ moveError || shortcutError }}
			<button class="board-view__move-error-dismiss" @click="dismissActionError">×</button>
		</div>

		<!-- Error - status-aware (#3662): a gone/forbidden board explains itself and
		     links back to the boards list; a transient failure offers a retry. -->
		<div v-if="isError" class="board-view__error">
			<p class="board-view__error-msg">{{ boardErrorMessage }}</p>
			<div class="board-view__error-actions">
				<NcButton v-if="!boardIsGoneOrForbidden" type="primary" @click="boardRefetch">
					{{ t('kanso', 'Retry') }}
				</NcButton>
				<NcButton :type="boardIsGoneOrForbidden ? 'primary' : 'tertiary'" @click="router.push({ name: 'board-list' })">
					{{ t('kanso', 'Go to boards') }}
				</NcButton>
			</div>
		</div>

		<!-- Stacks row (Board view). Kept mounted under v-show so its drag-and-drop
		     monitors stay attached when the List view is showing. -->
		<div v-show="viewMode === 'board' && swimlaneMode === 'none'" ref="stacksWrapRef" class="board-view__stacks-wrap">
			<!-- Skeleton stacks on cold load -->
			<template v-if="isLoading">
				<div v-for="n in 3" :key="n" class="stack-skeleton">
					<div class="skeleton-text stack-skeleton__title" />
					<div v-for="m in 3" :key="m" class="skeleton-card" />
				</div>
			</template>

			<!-- Actual stacks (flat board - no swimlanes) -->
			<template v-else-if="boardData && swimlaneMode === 'none'">
				<StackColumn
					v-for="stack in sortedStacks"
					:key="stack.id"
					:ref="(el) => registerColumnRef(stack.id, el)"
					:stack="stack"
					:cards="cardsForStack(stack.id)"
					:labels-by-id="labelsById"
					:board-prefix="boardData.board.prefix"
					:new-cards-on-top="boardData.board.newCardsOnTop === true"
					:on-create-card="handleCreateCard"
					:on-fetch-templates="handleFetchTemplates"
					:on-create-from-template="handleCreateFromTemplate"
					:on-manage-templates="() => { showManageTemplates = true }"
					:on-delete-stack="handleDeleteStack"
					:on-restore-stack="handleRestoreStack"
					:on-rename-stack="handleRenameStack"
					:on-set-role="handleSetRole"
					:on-set-wip="handleSetWip"
					:on-set-color="handleSetColor"
					:on-card-focus="(cardId) => { focusedCardId = cardId }"
					:on-card-hover="(cardId) => { hoveredCardId = cardId }"
					:selection-mode="bulk.selectionMode.value"
					:selected-ids="bulk.selected.value"
					:on-card-select="handleCardSelect"
					:collapsed="isStackCollapsed(stack.id)"
					:on-toggle-collapsed="toggleStackCollapsed"
					:compact="isCompact" />

				<!-- Column composer (#3413). No longer a PERSISTENT trailing input: it
				     appears only when revealed from the ⋯ More menu ("Add column"), or
				     as onboarding on a brand-new empty board so a fresh board isn't a
				     dead end. Editors only. Esc or an empty blur collapses it again. -->
				<div
					v-if="canEditBoard && (showAddColumn || sortedStacks.length === 0)"
					class="add-stack add-stack--empty">
					<p v-if="sortedStacks.length === 0" class="add-stack__hint" data-test="empty-board-hint">
						{{ t('kanso', 'Start by adding a column, e.g. “To do”.') }}
					</p>
					<form @submit.prevent="submitNewStack">
						<input
							ref="addColumnInputRef"
							v-model="newStackTitle"
							class="add-stack__input"
							type="text"
							maxlength="100"
							:placeholder="t('kanso', 'Add column…')"
							:disabled="createStack.isPending.value"
							@keydown.enter.prevent="submitNewStack"
							@keydown.esc.prevent="collapseAddColumn"
							@blur="onAddColumnBlur" />
						<p v-if="stackError" class="add-stack__error">{{ stackError }}</p>
					</form>
				</div>
			</template>
		</div>

		<!-- Swimlane (grouped) board: horizontal lanes, each holding the full
		     stacks row for the lane's cards. Its own vertical scroll region so
		     lanes stack top-to-bottom while each lane scrolls horizontally. -->
		<div
			v-show="viewMode === 'board' && swimlaneMode !== 'none'"
			class="board-view__swimlanes-wrap">
			<template v-if="boardData && swimlaneMode !== 'none'">
				<SwimlaneRow
					v-for="lane in lanes"
					:key="lane.key"
					:lane="lane"
					:stacks="sortedStacks"
					:labels-by-id="labelsById"
					:board-prefix="boardData.board.prefix"
					:register-column-ref="registerLaneColumnRef"
					:on-create-card="handleCreateCard"
					:on-card-focus="(cardId) => { focusedCardId = cardId }"
					:on-card-hover="(cardId) => { hoveredCardId = cardId }"
					:collapsed-stacks="collapsedStacks"
					:on-toggle-collapsed="toggleStackCollapsed"
					:compact="isCompact" />
				<p v-if="lanes.length === 0" class="board-view__swimlanes-empty">
					{{ t('kanso', 'No cards to group.') }}
				</p>
			</template>
		</div>

		<!-- List view - a virtualized, stack-grouped table over the same filtered
		     cards. Read-oriented: rows open the card modal. -->
		<BoardListView
			v-if="viewMode === 'list' && boardData"
			:stacks="sortedStacks"
			:cards-by-stack="cardsByStack"
			:labels-by-id="labelsById"
			:board-prefix="boardData?.board?.prefix ?? ''"
			:board-id="props.id" />

		<!-- Timeline (Gantt) view - cards on a date axis by start→due. -->
		<BoardTimelineView
			v-if="viewMode === 'timeline' && boardData"
			:cards="allVisibleCards"
			:stacks="sortedStacks"
			:cards-by-stack="cardsByStack"
			:board-prefix="boardData?.board?.prefix ?? ''"
			:can-edit="canEditBoard"
			:board-id="props.id" />

		<!-- Keyboard shortcuts overlay -->
		<NcModal
			v-if="showShortcuts"
			:name="t('kanso', 'Keyboard shortcuts')"
			@close="showShortcuts = false">
			<div class="shortcuts-modal">
				<table class="shortcuts-modal__table">
					<tbody>
						<tr>
							<td class="shortcuts-modal__key"><kbd>↓</kbd> / <kbd>↑</kbd> · <kbd>j</kbd> / <kbd>k</kbd></td>
							<td>{{ t('kanso', 'Navigate cards up / down') }}</td>
						</tr>
						<tr>
							<td class="shortcuts-modal__key"><kbd>→</kbd> / <kbd>←</kbd> · <kbd>l</kbd> / <kbd>h</kbd></td>
							<td>{{ t('kanso', 'Move to next / previous stack') }}</td>
						</tr>
						<tr>
							<td class="shortcuts-modal__key"><kbd>n</kbd></td>
							<td>{{ t('kanso', 'Add new card in focused stack') }}</td>
						</tr>
						<tr>
							<td class="shortcuts-modal__key"><kbd>e</kbd></td>
							<td>{{ t('kanso', 'Open focused card') }}</td>
						</tr>
						<tr>
							<td class="shortcuts-modal__key"><kbd>Space</kbd></td>
							<td>{{ t('kanso', 'Quick preview of the hovered / focused card') }}</td>
						</tr>
						<tr>
							<td class="shortcuts-modal__key"><kbd>d</kbd></td>
							<td>{{ t('kanso', 'Toggle done on focused card') }}</td>
						</tr>
						<tr>
							<td class="shortcuts-modal__key"><kbd>0</kbd>–<kbd>4</kbd></td>
							<td>{{ t('kanso', 'Set priority on focused card (0=None, 1=Low … 4=Urgent)') }}</td>
						</tr>
						<tr>
							<td class="shortcuts-modal__key"><kbd>?</kbd></td>
							<td>{{ t('kanso', 'Show / hide this shortcuts overlay') }}</td>
						</tr>
						<tr>
							<td class="shortcuts-modal__key"><kbd>/</kbd></td>
							<td>{{ t('kanso', 'Focus search') }}</td>
						</tr>
						<tr>
							<td class="shortcuts-modal__key"><kbd>Ctrl</kbd>+<kbd>K</kbd></td>
							<td>{{ t('kanso', 'Open command palette') }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</NcModal>

		<!-- Quick-look card preview (Space on a hovered/focused card).
		     A transparent backdrop captures click-away; the panel itself is
		     read-only and dismisses on Escape / Space / mouse-leave. -->
		<template v-if="previewCard">
			<div class="card-preview-backdrop" @click="closePreview" />
			<CardPreview
				:card="previewCard"
				:labels-by-id="labelsById"
				:board-prefix="boardData?.board?.prefix ?? ''"
				:participants="participants.data.value ?? []"
				:anchor-rect="previewAnchorRect"
				@close="closePreview"
				@open="openPreviewCard" />
		</template>

		<!-- Command palette (Ctrl/Cmd+K) -->
		<CommandPalette
			:open="showCommandPalette"
			@close="showCommandPalette = false" />

		<!-- Child route: CardModal renders over this view -->
		<router-view />

		<!-- Bulk action bar - shown when multi-select mode is active -->
		<BulkActionBar
			v-if="bulk.selectionMode.value"
			:count="bulk.selectedCount.value"
			:stacks="sortedStacks"
			:labels="boardLabels"
			:participants="participants.data.value ?? []"
			:applying="bulk.applying.value"
			@move="onBulkMove"
			@add-label="onBulkAddLabel"
			@remove-label="onBulkRemoveLabel"
			@assign="onBulkAssign"
			@set-due="onBulkSetDue"
			@archive="onBulkArchive"
			@delete="onBulkDelete"
			@close="bulk.exitMode()" />

		<!-- One-time keyboard-shortcut discoverability hint (#3413): a subtle,
		     dismissible nudge shown once after the user first opens a board.
		     Dismissal is persisted per user (settings key), so it stays hidden. -->
		<div
			v-if="showShortcutsHint && boardData"
			class="board-view__shortcuts-hint"
			data-test="shortcuts-hint"
			role="status">
			<button
				class="board-view__shortcuts-hint-open"
				data-test="shortcuts-hint-open"
				@click="openShortcutsFromHint">
				{{ t('kanso', 'Tip: press ? for keyboard shortcuts') }}
			</button>
			<button
				class="board-view__shortcuts-hint-dismiss"
				:aria-label="t('kanso', 'Dismiss')"
				data-test="shortcuts-hint-dismiss"
				@click="dismissShortcutsHint">×</button>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showWarning } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionRadio from '@nextcloud/vue/components/NcActionRadio'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import DotsHorizontalIcon from 'vue-material-design-icons/DotsHorizontal.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import ArchiveIcon from 'vue-material-design-icons/Archive.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import EyeOutlineIcon from 'vue-material-design-icons/EyeOutline.vue'
import EyeOffOutlineIcon from 'vue-material-design-icons/EyeOffOutline.vue'
import ViewColumnIcon from 'vue-material-design-icons/ViewColumn.vue'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'
import SortIcon from 'vue-material-design-icons/Sort.vue'
import ViewCompactIcon from 'vue-material-design-icons/ViewCompact.vue'
import ViewAgendaIcon from 'vue-material-design-icons/ViewAgenda.vue'
import ChartTimelineIcon from 'vue-material-design-icons/ChartTimeline.vue'
import ChartBarIcon from 'vue-material-design-icons/ChartBar.vue'
import SelectMultipleIcon from 'vue-material-design-icons/SelectMultiple.vue'
import ForumOutlineIcon from 'vue-material-design-icons/ForumOutline.vue'
import StackColumn from '../components/StackColumn.vue'
import BulkActionBar from '../components/BulkActionBar.vue'
import { useBulkSelect } from '../composables/useBulkSelect.js'
import SwimlaneRow from '../components/SwimlaneRow.vue'
import { buildLanes, SWIMLANE_MODES } from '../composables/useSwimlanes.js'
import BoardListView from '../components/BoardListView.vue'
import BoardTimelineView from '../components/BoardTimelineView.vue'
import SearchBox from '../components/SearchBox.vue'
import BoardFilterBar from '../components/BoardFilterBar.vue'
import {
	createFilterState,
	serializeFilter,
	applyFilter,
	makePredicate,
	filterToQuery,
	queryToFilter,
	filterIsEmpty,
} from '../composables/useBoardFilters.js'
import {
	fetchSavedFilters as apiFetchSavedFilters,
	saveSavedFilter as apiSaveSavedFilter,
	deleteSavedFilter as apiDeleteSavedFilter,
} from '../services/api.js'
import BoardSettingsModal from '../components/BoardSettingsModal.vue'
import ManageTemplatesModal from '../components/ManageTemplatesModal.vue'
import CommandPalette from '../components/CommandPalette.vue'
import CardPreview from '../components/CardPreview.vue'
import { useBoard } from '../composables/useBoard.js'
import { useBoardSubscription } from '../composables/useBoardSubscription.js'
import { boardQueryKey, invalidateMyWork } from '../composables/queryKeys.js'
import { useAssignees } from '../composables/useAssignees.js'
import { useCardMove } from '../composables/useCardMove.js'
import { provideAnnouncer } from '../composables/useAnnouncer.js'
import { useQueryClient } from '@tanstack/vue-query'
import { cssColor } from '../services/color.js'
import { scaleTokens } from '../services/estimateScales.js'
import { backgroundCss } from '../services/backgrounds.js'
import { initial, between, after, before } from '../services/sortKey.js'
import { updateCard as apiUpdateCard, moveStack as apiMoveStack, fetchCardTemplates as apiFetchCardTemplates, createCardFromTemplate as apiCreateCardFromTemplate, getSettings, updateSettings } from '../services/api.js'
import { monitorForElements } from '@atlaskit/pragmatic-drag-and-drop/element/adapter'
import { extractClosestEdge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge'
import { autoScrollForElements } from '@atlaskit/pragmatic-drag-and-drop-auto-scroll/element'
import { combine } from '@atlaskit/pragmatic-drag-and-drop/combine'

const props = defineProps({
	id: {
		type: String,
		required: true,
	},
})

const router = useRouter()
const route = useRoute()
const queryClient = useQueryClient()
const boardId = computed(() => props.id)
const bulk = useBulkSelect(boardId, queryClient)

// View mode (Board columns / List table / Timeline), persisted per board per user.
const VIEW_MODES = ['board', 'list', 'timeline']
const viewMode = ref('board')
try {
	const saved = localStorage.getItem(`kanso.viewMode.${props.id}`)
	if (saved && VIEW_MODES.includes(saved)) viewMode.value = saved
} catch (e) { /* localStorage unavailable - default to board */ }
function setViewMode(mode) {
	viewMode.value = mode
	try {
		localStorage.setItem(`kanso.viewMode.${props.id}`, mode)
	} catch (e) { /* ignore persistence failure */ }
}
const viewModeLabel = computed(() => ({
	board: t('kanso', 'Board'),
	list: t('kanso', 'List'),
	timeline: t('kanso', 'Timeline'),
}[viewMode.value] ?? t('kanso', 'Board')))

// Display sort - a VIEW-ONLY reorder of how cards render within each stack. Never
// rewrites sort keys; 'manual' is the persisted fractional order. Persisted per
// board per user. While a non-manual sort is active, card drag-reorder is
// suppressed (see the card onDrop guard) so manual order is preserved.
const SORT_MODES = ['manual', 'priority', 'due', 'title', 'estimate']
// Each non-manual mode has a "natural" default direction (the intuitive first
// orientation): priority urgent-first, due soonest-first, title A→Z, estimate
// biggest-first. Selecting a mode resets to its natural direction; the user can
// then flip it. 'manual' has no direction (it IS the persisted fractional order).
const NATURAL_SORT_DIR = { priority: 'desc', due: 'asc', title: 'asc', estimate: 'desc' }
const sortMode = ref('manual')
const sortDir = ref('asc')
try {
	const saved = localStorage.getItem(`kanso.sortMode.${props.id}`)
	if (saved && SORT_MODES.includes(saved)) sortMode.value = saved
} catch (e) { /* default to manual */ }
try {
	const savedDir = localStorage.getItem(`kanso.sortDir.${props.id}`)
	sortDir.value = (savedDir === 'asc' || savedDir === 'desc')
		? savedDir
		: (NATURAL_SORT_DIR[sortMode.value] ?? 'asc')
} catch (e) { sortDir.value = NATURAL_SORT_DIR[sortMode.value] ?? 'asc' }
function setSortMode(mode) {
	sortMode.value = mode
	try {
		localStorage.setItem(`kanso.sortMode.${props.id}`, mode)
	} catch (e) { /* ignore persistence failure */ }
	// Selecting a mode resets to its natural direction (Title → A→Z, etc.).
	if (mode !== 'manual') setSortDir(NATURAL_SORT_DIR[mode] ?? 'asc')
}
function setSortDir(dir) {
	sortDir.value = dir
	try {
		localStorage.setItem(`kanso.sortDir.${props.id}`, dir)
	} catch (e) { /* ignore persistence failure */ }
}
const sortModeLabel = computed(() => ({
	manual: t('kanso', 'Manual'),
	priority: t('kanso', 'Priority'),
	due: t('kanso', 'Due date'),
	title: t('kanso', 'Title'),
	estimate: t('kanso', 'Estimate'),
}[sortMode.value] ?? t('kanso', 'Manual')))
// Menu-name suffix: an arrow so the active direction is visible on the toolbar
// without opening the menu. Manual has no direction.
const sortModeMenuName = computed(() => sortMode.value === 'manual'
	? sortModeLabel.value
	: `${sortModeLabel.value} ${sortDir.value === 'asc' ? '↑' : '↓'}`)

// Swimlanes - client-side grouping of the Board view into horizontal lanes by
// assignee / label / priority (#3406). Purely a view over the board summary
// payload (cards already carry labelIds / assigneeIds / priority) - NO extra
// endpoint. Persisted per board per user, mirroring viewMode / sortMode.
const swimlaneMode = ref('none')
try {
	const saved = localStorage.getItem(`kanso.swimlaneMode.${props.id}`)
	if (saved && SWIMLANE_MODES.includes(saved)) swimlaneMode.value = saved
} catch (e) { /* default to none */ }
function setSwimlaneMode(mode) {
	swimlaneMode.value = mode
	try {
		localStorage.setItem(`kanso.swimlaneMode.${props.id}`, mode)
	} catch (e) { /* ignore persistence failure */ }
}

// Compact density (#3415): a per-user, view-only boolean that tightens every
// card tile (smaller padding, single-line title, smaller chips) so more cards
// fit on screen. Purely presentational — no card-data / board-schema change.
// Persisted per board per user, mirroring viewMode / sortMode / swimlaneMode.
// Threaded down to StackColumn (which feeds the virtualizer a smaller estimate
// and re-measures on flip) → CardTile.
const density = ref('comfortable')
try {
	const saved = localStorage.getItem(`kanso.density.${props.id}`)
	if (saved === 'compact' || saved === 'comfortable') density.value = saved
} catch (e) { /* default to comfortable */ }
const isCompact = computed(() => density.value === 'compact')
function setDensity(mode) {
	density.value = mode
	try {
		localStorage.setItem(`kanso.density.${props.id}`, mode)
	} catch (e) { /* ignore persistence failure */ }
}
function toggleDensity() {
	setDensity(isCompact.value ? 'comfortable' : 'compact')
}
// Reload persisted density when the board changes (component is reused).
watch(() => props.id, () => {
	try {
		const saved = localStorage.getItem(`kanso.density.${props.id}`)
		density.value = (saved === 'compact' || saved === 'comfortable') ? saved : 'comfortable'
	} catch (e) { density.value = 'comfortable' }
})

// Per-user collapsed columns (#3677). A collapsed stack renders as a narrow
// rail (title + card count) instead of its full card list. Purely presentational
// and view-only: no card-data / board-schema change. Persisted per board per user
// as a Set<stackId>, mirroring the List/Timeline group-collapse convention
// (kanso.listCollapsed / kanso.timelineCollapsed). Collapse is applied with a CSS
// class + v-show (never v-if) so each StackColumn keeps its virtualizer and drop
// targets mounted — a collapsed rail stays a valid card/stack drop target.
const collapsedStackKey = computed(() => `kanso.stackCollapsed.${props.id}`)
function loadCollapsedStacks() {
	try {
		const saved = localStorage.getItem(collapsedStackKey.value)
		if (saved) return new Set(JSON.parse(saved))
	} catch (e) { /* localStorage unavailable - default to all expanded */ }
	return new Set()
}
const collapsedStacks = ref(loadCollapsedStacks())
// Reload persisted state when the board changes (component is reused across boards).
watch(() => props.id, () => { collapsedStacks.value = loadCollapsedStacks() })

function isStackCollapsed(stackId) {
	return collapsedStacks.value.has(stackId)
}

function toggleStackCollapsed(stackId) {
	const next = new Set(collapsedStacks.value)
	if (next.has(stackId)) next.delete(stackId)
	else next.add(stackId)
	collapsedStacks.value = next
	try {
		localStorage.setItem(collapsedStackKey.value, JSON.stringify([...next]))
	} catch (e) { /* localStorage unavailable - collapse is in-memory only */ }
}

/** Expand a collapsed stack (used when a card is dropped onto its rail). */
function expandStack(stackId) {
	if (!collapsedStacks.value.has(stackId)) return
	const next = new Set(collapsedStacks.value)
	next.delete(stackId)
	collapsedStacks.value = next
	try {
		localStorage.setItem(collapsedStackKey.value, JSON.stringify([...next]))
	} catch (e) { /* localStorage unavailable - collapse is in-memory only */ }
}
/**
 * View-only comparator for the active display sort. Every non-manual mode falls
 * back to the fractional sort key as a stable tiebreaker.
 */
function sortCards(cards) {
	const arr = [...cards]
	if (sortMode.value === 'manual') return arr.sort(bySortKey)

	// Each mode maps a card to a comparable value, or null when the field is
	// "missing" (no due date / no estimate). Missing values ALWAYS sort last,
	// independent of direction; present values flip with sortDir. `compare` is
	// always ascending — sortDir applies the sign. Ties fall back to the
	// fractional sort key.
	let valueOf
	let compare
	if (sortMode.value === 'priority') {
		// 0 ("no priority") is a real low value here, not "missing".
		valueOf = (c) => Number(c.priority ?? 0)
		compare = (a, b) => a - b
	} else if (sortMode.value === 'due') {
		valueOf = (c) => {
			if (!c.duedate) return null
			const t2 = new Date(c.duedate).getTime()
			return Number.isNaN(t2) ? null : t2
		}
		compare = (a, b) => a - b
	} else if (sortMode.value === 'title') {
		valueOf = (c) => String(c.title ?? '')
		compare = (a, b) => a.localeCompare(b)
	} else if (sortMode.value === 'estimate') {
		// Rank by ordinal position in the board's scale (NOT string value: "13"
		// vs "2" mis-orders, and XS…XL has no numeric value). Unestimated /
		// off-scale tokens are "missing" → sorted last in both directions.
		const tokens = scaleTokens(boardData.value?.board?.estimateScale ?? 'none')
		valueOf = (c) => {
			const i = c.estimate ? tokens.indexOf(c.estimate) : -1
			return i === -1 ? null : i
		}
		compare = (a, b) => a - b
	} else {
		return arr.sort(bySortKey)
	}

	const mult = sortDir.value === 'asc' ? 1 : -1
	return arr.sort((a, b) => {
		const va = valueOf(a)
		const vb = valueOf(b)
		const ma = va === null
		const mb = vb === null
		if (ma || mb) {
			if (ma && mb) return bySortKey(a, b)
			return ma ? 1 : -1 // missing always last, regardless of direction
		}
		return (compare(va, vb) * mult) || bySortKey(a, b)
	})
}
const { data: boardData, isLoading, isError, error: boardError, refetch: boardRefetch, createStack, createCard, updateStack, deleteStack, restoreStack } = useBoard(boardId)

// Status-aware board error copy (#3662). A dead deep-link/notification to a
// deleted board 404s; a revoked share 403s. Both should read as an explanatory
// message with a way back to the boards list - not the generic error box. A
// transient network/5xx failure stays retryable.
const boardErrorStatus = computed(() => boardError.value?.response?.status ?? null)
const boardIsGoneOrForbidden = computed(() =>
	boardErrorStatus.value === 404 || boardErrorStatus.value === 403)
const boardErrorMessage = computed(() => {
	if (boardErrorStatus.value === 403) {
		return t('kanso', 'This board no longer exists or you no longer have access.')
	}
	if (boardErrorStatus.value === 404) {
		return t('kanso', 'This board no longer exists.')
	}
	return t('kanso', 'Couldn\'t load this board. Please try again.')
})
const { enqueueMove, lastError: moveError, dismissError: dismissMoveError } = useCardMove(boardId)

// Screen-reader announcer (aria-live="polite"). Provided here so descendants
// (CardModal via router-view) can announce their own actions through one region.
const { message: announceMessage, announce } = provideAnnouncer()

/** Human title of a stack by id, for announcements. */
function stackTitleById(stackId) {
	return sortedStacks.value.find((s) => s.id === stackId)?.title ?? ''
}
const { toggle: boardWatchToggle } = useBoardSubscription(boardId)
// The chosen background preset resolved to its CSS gradient (null = none). The
// key → CSS mapping lives client-side; an unknown key resolves to null.
const boardBackground = computed(() => backgroundCss(boardData.value?.board?.background))
// Project chat link (#3748): server-validated to http/https; empty/unset = no
// button. Shown to every member (setting it is MANAGE-gated in board settings).
const boardChatUrl = computed(() => boardData.value?.board?.chatUrl || null)
const isBoardSubscribed = computed(() => boardData.value?.subscription?.subscribed ?? false)
function toggleBoardWatch() {
	boardWatchToggle.mutate({ subscribed: !isBoardSubscribed.value })
}
const { participants } = useAssignees(boardId)

// Resolve current Nextcloud user id - OC.getCurrentUser() is always available in NC apps
const currentUserId = (() => {
	try {
		return window.OC?.getCurrentUser?.()?.uid ?? ''
	} catch {
		return ''
	}
})()

const newStackTitle = ref('')
const stackError = ref('')
const stacksWrapRef = ref(null)
let boardCleanup = () => {}

// Label settings panel visibility
const showSettings = ref(false)

// The consolidated ⋯ "More" overflow menu open state. Controlled so in-place
// actions (settings panel, multi-select, watch) can dismiss the menu explicitly
// — otherwise its popover lingers over the docked settings panel and eats clicks.
const moreMenuOpen = ref(false)

// ── Card-template manager (#3634) ─────────────────────────────────────────────
// Board-scoped modal to view / edit / delete / unmark / create templates (which
// are hidden from the live board). Opened from a column's "＋ From template" menu.
const showManageTemplates = ref(false)

/** Whether the current user may EDIT this board (bit 2). Gates template mutations. */
const canEditBoard = computed(() => ((boardData.value?.permissions ?? 0) & 2) !== 0)

/** First (sorted, non-archived) stack id — hosts a freshly created blank template. */
const firstStackId = computed(() => sortedStacks.value[0]?.id ?? null)

/**
 * Open a template card in the existing CardModal for editing. Reuses the board's
 * card-open route path; the manager modal stays closed behind it so returning
 * from the card lands back on the board.
 */
function openTemplateForEdit(cardId) {
	showManageTemplates.value = false
	router.push({ name: 'card-modal', params: { id: props.id, cardId } })
}

// ── Command palette visibility ────────────────────────────────────────────────
const showCommandPalette = ref(false)

// ── Keyboard shortcuts overlay ────────────────────────────────────────────────
const showShortcuts = ref(false)
const shortcutError = ref('')

// ── One-time "press ? for shortcuts" discoverability hint (#3413) ─────────────
// A subtle, dismissible nudge shown once after the user first opens a board.
// Whether it has been dismissed is persisted PER USER via NC config (the shared
// `dismissed_hints` settings key), so it never re-appears on any device.
const SHORTCUTS_HINT_ID = 'shortcuts-discoverability'
const showShortcutsHint = ref(false)

async function loadShortcutsHint() {
	try {
		const s = await getSettings()
		const dismissed = Array.isArray(s?.dismissedHints) ? s.dismissedHints : []
		if (!dismissed.includes(SHORTCUTS_HINT_ID)) {
			showShortcutsHint.value = true
		}
	} catch {
		// Non-fatal: just don't show the hint if settings can't be read.
	}
}

async function dismissShortcutsHint() {
	showShortcutsHint.value = false
	try {
		const s = await getSettings()
		const dismissed = Array.isArray(s?.dismissedHints) ? s.dismissedHints : []
		if (!dismissed.includes(SHORTCUTS_HINT_ID)) {
			await updateSettings({ dismissedHints: [...dismissed, SHORTCUTS_HINT_ID] })
		}
	} catch {
		// Non-fatal: it will re-appear next load, which is acceptable.
	}
}

// Opening the overlay from the hint both shows it and permanently dismisses the hint.
function openShortcutsFromHint() {
	showShortcuts.value = true
	dismissShortcutsHint()
}

// ── Search box ref (for programmatic focus via '/' shortcut) ─────────────────
const searchBoxRef = ref(null)
const headerRef = ref(null)

// Responsive header (#mobile). When the header is too narrow to hold the full
// toolbar with labels, the secondary controls (view mode, sort, density) collapse
// into the ⋯ More menu and the back button + filter go icon-only, so nothing is
// ever pushed off-screen. Driven by the header's OWN width (a ResizeObserver, see
// onHeaderResize) — not a viewport media query — because the available width also
// changes when the app-navigation sidebar opens/closes, not just on real mobile.
const NARROW_HEADER_PX = 860
const isNarrow = ref(false)

function dismissActionError() {
	dismissMoveError()
	shortcutError.value = ''
}

// ── Keyboard navigation state ─────────────────────────────────────────────────
/** Currently keyboard-focused card id (number | null). */
const focusedCardId = ref(null)

/** Currently mouse-hovered card id (number | null). Feeds the Space quick-preview. */
const hoveredCardId = ref(null)

// ── Quick-look preview state ──────────────────────────────────────────────────
/** Card id the floating preview is open for (number | null). */
const previewCardId = ref(null)
/** Anchor rect (the originating tile's bounding box) for positioning the panel. */
const previewAnchorRect = ref(null)

/** Map<stackId, StackColumn component instance> - populated by function refs. */
const columnRefs = new Map()

function registerColumnRef(stackId, el) {
	if (el) {
		columnRefs.set(stackId, el)
	} else {
		columnRefs.delete(stackId)
	}
}

/**
 * Lane-scoped column-ref registrar (swimlane mode). Keyboard card-navigation is
 * flat-board only, so lane columns are stored under a composite key that never
 * collides with the flat stackId keys - keeping the flat map clean while still
 * letting the components mount/unmount their refs cleanly.
 */
function registerLaneColumnRef(laneKey, stackId, el) {
	const key = `${laneKey}::${stackId}`
	if (el) {
		columnRefs.set(key, el)
	} else {
		columnRefs.delete(key)
	}
}

// ── Label computed helpers ────────────────────────────────────────────────────

/** All board-level labels from the board payload. */
const boardLabels = computed(() => boardData.value?.labels ?? [])

/** Map<id, label> for O(1) lookup by id - passed to StackColumn → CardTile. */
const labelsById = computed(() => {
	const map = new Map()
	for (const label of boardLabels.value) {
		map.set(label.id, label)
	}
	return map
})

// ── Composable filter state (#3407) ───────────────────────────────────────────
// A generalization of the old label/priority dropdown into a multi-dimension
// filter bar: labels / assignees / due / done / priority. AND across dimensions,
// OR within each (see useBoardFilters). Purely client-side over the summary
// payload. The active filter is mirrored to the URL query (shareable links) and
// can be saved as a named per-user view.
const filterState = createFilterState()
// A live predicate rebuilt whenever the filter changes. `now` is captured once
// per rebuild so the "this week" / "overdue" windows stay stable across a pass.
const filterPredicate = computed(() => makePredicate(filterState, Date.now()))
const totalActiveFilters = computed(() => {
	const s = filterState
	return s.labels.size + s.assignees.size + s.priorities.size
		+ (s.due ? 1 : 0) + (s.done ? 1 : 0)
})

// ── Saved views (#3407) ───────────────────────────────────────────────────────
// Per-user, per-board named filter snapshots persisted in NC user config.
const savedFilters = ref([])
const filterError = ref('')

// Load this board's saved views once the board id is known / changes.
watch(boardId, async (id) => {
	savedFilters.value = []
	if (!id) return
	try {
		const res = await apiFetchSavedFilters(id)
		savedFilters.value = Array.isArray(res?.filters) ? res.filters : []
	} catch { /* non-fatal: saved views just stay empty */ }
}, { immediate: true })

// The name of the saved view the current filter equals (for the highlight), or
// '' when the live filter matches no saved view.
const activeSavedName = computed(() => {
	const current = JSON.stringify(serializeFilter(filterState))
	const match = savedFilters.value.find(
		(v) => JSON.stringify(v.filter ?? {}) === current,
	)
	return match?.name ?? ''
})

async function handleSaveFilter(name) {
	filterError.value = ''
	const filter = serializeFilter(filterState)
	try {
		const res = await apiSaveSavedFilter(props.id, name, filter)
		savedFilters.value = Array.isArray(res?.filters) ? res.filters : []
	} catch (err) {
		filterError.value = err?.response?.data?.error || t('kanso', 'Failed to save the filter.')
	}
}

function handleApplySavedFilter(view) {
	applyFilter(filterState, view?.filter ?? {})
	// The filterState watcher below reflects it into the URL.
}

async function handleDeleteSavedFilter(name) {
	filterError.value = ''
	try {
		const res = await apiDeleteSavedFilter(props.id, name)
		savedFilters.value = Array.isArray(res?.filters) ? res.filters : []
	} catch (err) {
		filterError.value = err?.response?.data?.error || t('kanso', 'Failed to delete the filter.')
	}
}

// ── URL ↔ filter sync (shareable links) ───────────────────────────────────────
// Two watchers keep the live filter and the URL query in lock-step. There is NO
// mutation guard flag: the feedback loop is broken purely by value-equality —
// each watcher no-ops when the two sides already encode the same filter, so an
// edit propagates exactly one hop and then settles. Only the five filter keys
// (fl/fa/fp/fd/fs) are owned here; other query params are preserved untouched.
const FILTER_QUERY_KEYS = ['fl', 'fa', 'fp', 'fd', 'fs']

// Apply the URL's filter params onto the state (shared link / back-forward).
function applyUrlToFilter() {
	applyFilter(filterState, queryToFilter(route.query))
}

// Push the live filter into the URL query (replace, so filtering doesn't spam
// browser history). No-ops when the URL already reflects the filter.
watch(filterState, () => {
	const q = filterToQuery(serializeFilter(filterState))
	// Preserve any non-filter query params already on the route.
	const preserved = {}
	for (const [k, v] of Object.entries(route.query)) {
		if (!FILTER_QUERY_KEYS.includes(k)) preserved[k] = v
	}
	const next = { ...preserved, ...q }
	if (JSON.stringify(next) !== JSON.stringify(route.query)) {
		router.replace({ query: next }).catch(() => {})
	}
}, { deep: true })

// External URL changes (shared link load, back/forward): re-apply onto state,
// but only when the URL encodes a different filter than the live state — else
// this would fight the watcher above. Compare on the canonical query form so a
// hand-typed order or string/number difference doesn't count as a change.
watch(() => route.query, () => {
	const desired = JSON.stringify(queryToFilter(route.query))
	const currentAsQuery = JSON.stringify(queryToFilter(filterToQuery(serializeFilter(filterState))))
	if (desired !== currentAsQuery) {
		applyUrlToFilter()
	}
}, { deep: true })

const bySortKey = (a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0)

const sortedStacks = computed(() => {
	if (!boardData.value?.stacks) return []
	return [...boardData.value.stacks]
		.filter((s) => !s.archived)
		.sort(bySortKey)
})

/**
 * Archived (but not deleted) cards sourced from the board payload.
 * The board GET already returns archived cards in the cards array - we just
 * need to surface them here. No additional API call is required.
 */
const archivedCards = computed(() =>
	(boardData.value?.cards ?? []).filter((c) => c.archived),
)

const cardsByStack = computed(() => {
	const map = new Map()
	const passes = filterPredicate.value
	for (const card of boardData.value?.cards ?? []) {
		if (card.archived) continue
		// Composable filter (#3407): AND across dimensions, OR within each.
		// filter-first, then group (swimlanes read this already-filtered map).
		if (!passes(card)) continue
		if (!map.has(card.stackId)) map.set(card.stackId, [])
		map.get(card.stackId).push(card)
	}
	for (const [stackId, cards] of map) map.set(stackId, sortCards(cards))
	return map
})

// All filtered, non-archived cards flattened - the Timeline view's input.
const allVisibleCards = computed(() => {
	const out = []
	for (const cards of cardsByStack.value.values()) out.push(...cards)
	return out
})

function cardsForStack(stackId) {
	return cardsByStack.value.get(stackId) ?? []
}

// Swimlane partition of the (already filtered + display-sorted) cardsByStack.
// Empty array when swimlanes are off, so the flat board path is untouched.
const lanes = computed(() => {
	if (swimlaneMode.value === 'none') return []
	return buildLanes(
		swimlaneMode.value,
		cardsByStack.value,
		boardLabels.value,
		participants.data.value ?? [],
	)
})

// ── Quick-look preview helpers ────────────────────────────────────────────────
// The preview reads its meta straight from the board summary card already in the
// cache; only the description is lazily fetched inside CardPreview via useCard.
const boardCardsById = computed(() => {
	const map = new Map()
	for (const c of boardData.value?.cards ?? []) map.set(c.id, c)
	return map
})

/** The board summary card the preview is open for, or null. */
const previewCard = computed(() =>
	previewCardId.value == null ? null : (boardCardsById.value.get(previewCardId.value) ?? null),
)

/**
 * Toggle the quick-look preview for a given card id. Called from the Space
 * branch in handleKeydown. Capturing the tile's rect anchors the panel; if the
 * tile isn't in the DOM (edge case) the preview still opens, centered.
 */
function togglePreview(cardId) {
	if (cardId == null) return
	if (previewCardId.value === cardId) {
		closePreview()
		return
	}
	const el = document.querySelector(`[data-card-id="${cardId}"]`)
	previewAnchorRect.value = el ? el.getBoundingClientRect() : null
	previewCardId.value = cardId
}

function closePreview() {
	previewCardId.value = null
	previewAnchorRect.value = null
}

/** Open the full card modal for the previewed card, dismissing the preview. */
function openPreviewCard() {
	const id = previewCardId.value
	closePreview()
	if (id == null) return
	router.push({ name: 'card-modal', params: { id: props.id, cardId: id } })
}

// If the previewed card disappears from the board (archived/deleted/filtered),
// close the preview so it can't dangle over a stale rect.
watch(previewCard, (card) => {
	if (previewCardId.value != null && !card) closePreview()
})

// ── Keyboard navigation helpers (declared after cardsByStack + sortedStacks) ──
// NOTE: function declarations are hoisted and can reference these computeds
// safely. Only computed() and watch() calls must follow their dependencies.

/**
 * Derive (stackIdx, cardIdx) for a given cardId from the current sortedStacks
 * + cardsByStack. Returns null when the card is not found (e.g. after archive).
 */
function findCardPosition(cardId) {
	if (cardId == null) return null
	for (let si = 0; si < sortedStacks.value.length; si++) {
		const stack = sortedStacks.value[si]
		const cards = cardsByStack.value.get(stack.id) ?? []
		const ci = cards.findIndex((c) => c.id === cardId)
		if (ci !== -1) return { stackIdx: si, cardIdx: ci }
	}
	return null
}

/** Non-empty stacks in sorted order (for left/right navigation). */
const nonEmptyStacks = computed(() =>
	sortedStacks.value.filter((s) => (cardsByStack.value.get(s.id) ?? []).length > 0),
)

/**
 * After computing target stackId + cardIdx, focus the card:
 * 1. scroll virtualizer to the index
 * 2. wait for nextTick + rAF
 * 3. querySelector and .focus()
 */
let navSeq = 0

async function navigateTo(stackId, cardIdx) {
	const cards = cardsByStack.value.get(stackId) ?? []
	if (!cards.length) return
	const clamped = Math.max(0, Math.min(cardIdx, cards.length - 1))
	const card = cards[clamped]
	focusedCardId.value = card.id
	const col = columnRefs.get(stackId)
	if (col) col.scrollToIndex(clamped)
	// Rapid keypresses race here: only the newest navigation may focus,
	// or an older one resolving late would drag DOM focus backwards.
	const seq = ++navSeq
	await nextTick()
	await new Promise((resolve) => requestAnimationFrame(resolve))
	if (seq !== navSeq) return
	document.querySelector(`[data-card-id="${card.id}"]`)?.focus()
}

// Clear focusedCardId / hoveredCardId when the card disappears from cardsByStack
// (archived, deleted, filtered out). Without this the hovered id could dangle
// after the tile unmounts (no mouseleave fires) and a later Space would target a
// stale id. The previewCard computed also guards, but clearing here keeps the
// hover anchor honest.
watch(cardsByStack, () => {
	if (focusedCardId.value != null && !findCardPosition(focusedCardId.value)) {
		focusedCardId.value = null
	}
	if (hoveredCardId.value != null && !findCardPosition(hoveredCardId.value)) {
		hoveredCardId.value = null
	}
})

function handleKeydown(e) {
	// Guard: composing (IME)
	if (e.isComposing) return
	// Cmd/Ctrl+K → open command palette.
	// Handled BEFORE the modifier-key early-return guard (same technique as '?'
	// and '/' which are handled before the overlay-open guard).
	// Respects the same typing-context guard: don't trigger while the user is
	// typing in an input/textarea/contenteditable.
	if ((e.ctrlKey || e.metaKey) && e.key === 'k' && !e.altKey) {
		const target = e.target
		if (!target.closest('input, textarea, [contenteditable]')) {
			e.preventDefault()
			showCommandPalette.value = !showCommandPalette.value
			return
		}
	}
	// Guard: modifier keys held (but allow Shift for '?')
	if (e.ctrlKey || e.metaKey || e.altKey) return
	// Guard: typing context
	const target = e.target
	if (target.closest('input, textarea, [contenteditable]')) return
	// Guard: card modal child route active
	if (route.name === 'card-modal') return
	// '?' toggles the shortcuts overlay in BOTH directions, so it must be
	// handled before the overlay-open guard below.
	if (e.key === '?') {
		e.preventDefault()
		showShortcuts.value = !showShortcuts.value
		return
	}
	// '/' focuses the search box - handled before the overlay-open guard so it
	// always works as long as no input-like element is already focused.
	if (e.key === '/') {
		e.preventDefault()
		searchBoxRef.value?.focus()
		return
	}

	// ── Quick-look preview (Space) ────────────────────────────────────────────
	// Space peeks the hovered (mouse) or keyboard-focused card in a floating
	// read-only panel. preventDefault stops the board scrolling. The typing guard
	// above has already bailed, so a space typed in the composer still inserts a
	// space. When a preview is already open, Space / Escape close it and Enter
	// opens the full card - handled here, before the overlay-open guard below.
	if (previewCardId.value != null) {
		if (e.key === ' ' || e.key === 'Spacebar' || e.key === 'Escape') {
			e.preventDefault()
			closePreview()
			return
		}
		if (e.key === 'Enter') {
			e.preventDefault()
			openPreviewCard()
			return
		}
	}
	if (e.key === ' ' || e.key === 'Spacebar') {
		e.preventDefault()
		togglePreview(hoveredCardId.value ?? focusedCardId.value)
		return
	}

	// Guard: settings modal or shortcuts overlay open
	if (showSettings.value || showShortcuts.value) return

	// Vim-style aliases for the arrow navigation below. The typing-context
	// guard above has already bailed, so h/j/k/l still type normally in
	// inputs/textareas/contenteditables — here they only alias the arrows.
	const VIM_KEYS = { j: 'ArrowDown', k: 'ArrowUp', l: 'ArrowRight', h: 'ArrowLeft' }
	const key = VIM_KEYS[e.key] ?? e.key

	if (key === 'ArrowDown' || key === 'ArrowUp') {
		e.preventDefault()
		const pos = findCardPosition(focusedCardId.value)
		if (!pos) {
			// Seed to first card of the first non-empty stack
			const first = nonEmptyStacks.value[0]
			if (first) navigateTo(first.id, 0)
			return
		}
		const { stackIdx, cardIdx } = pos
		const stack = sortedStacks.value[stackIdx]
		const cards = cardsByStack.value.get(stack.id) ?? []
		const nextIdx = key === 'ArrowDown'
			? Math.min(cardIdx + 1, cards.length - 1)
			: Math.max(cardIdx - 1, 0)
		navigateTo(stack.id, nextIdx)
		return
	}

	if (key === 'ArrowRight' || key === 'ArrowLeft') {
		e.preventDefault()
		const pos = findCardPosition(focusedCardId.value)
		// Determine current stack index among NON-EMPTY stacks for left/right
		const ne = nonEmptyStacks.value
		if (!ne.length) return
		let neIdx
		if (!pos) {
			neIdx = key === 'ArrowRight' ? 0 : ne.length - 1
		} else {
			const curStackId = sortedStacks.value[pos.stackIdx].id
			neIdx = ne.findIndex((s) => s.id === curStackId)
			if (neIdx === -1) neIdx = 0
			neIdx = key === 'ArrowRight'
				? Math.min(neIdx + 1, ne.length - 1)
				: Math.max(neIdx - 1, 0)
		}
		const targetStack = ne[neIdx]
		const targetCards = cardsByStack.value.get(targetStack.id) ?? []
		// Clamp card index to new stack's length
		const clampedCardIdx = pos ? Math.min(pos.cardIdx, targetCards.length - 1) : 0
		navigateTo(targetStack.id, clampedCardIdx)
		return
	}

	if (key === 'n') {
		e.preventDefault()
		// Focus composer of the focused card's stack, or first stack
		const pos = findCardPosition(focusedCardId.value)
		let stackId
		if (pos) {
			stackId = sortedStacks.value[pos.stackIdx].id
		} else {
			const first = sortedStacks.value[0]
			if (!first) return
			stackId = first.id
		}
		const col = columnRefs.get(stackId)
		if (col) col.focusComposer()
		return
	}

	if (key === 'e') {
		e.preventDefault()
		if (focusedCardId.value == null) return
		router.push({
			name: 'card-modal',
			params: { id: props.id, cardId: focusedCardId.value },
		})
		return
	}

	if (key === 'd') {
		e.preventDefault()
		if (focusedCardId.value == null) return
		const id = focusedCardId.value
		// Look up current done state from cardsByStack cache
		let isDone = false
		outer: for (const cards of cardsByStack.value.values()) {
			for (const c of cards) {
				if (c.id === id) {
					isDone = Number(c.doneAt) > 0
					break outer
				}
			}
		}
		apiUpdateCard(id, { done: !isDone })
			.catch((err) => {
				shortcutError.value =
					err?.response?.data?.error || t('kanso', 'Failed to update the card.')
			})
			.finally(() => {
				queryClient.invalidateQueries({ queryKey: boardQueryKey(props.id) })
				// Done-state changes My Tasks membership (#3766).
				invalidateMyWork(queryClient)
			})
		return
	}

	// Keys 1–4 set priority on the focused card (1=Low, 2=Med, 3=High, 4=Urgent).
	// Key 0 clears priority (sets to None). Skip when no card is focused.
	if ((key === '0' || key === '1' || key === '2' || key === '3' || key === '4') && focusedCardId.value != null) {
		e.preventDefault()
		const priority = Number(key)
		const id = focusedCardId.value
		apiUpdateCard(id, { priority })
			.catch((err) => {
				shortcutError.value =
					err?.response?.data?.error || t('kanso', 'Failed to set priority.')
			})
			.finally(() => {
				queryClient.invalidateQueries({ queryKey: boardQueryKey(props.id) })
			})
		return
	}
}

// Publish the toolbar height as a CSS var so the (teleported) settings panel can
// dock BELOW the toolbar instead of over it — otherwise the panel covers the gear
// button and a second gear click can't toggle it closed.
let toolbarResizeObserver = null
function onHeaderResize() {
	if (!headerRef.value) return
	document.documentElement.style.setProperty('--kanso-board-toolbar-height', `${headerRef.value.offsetHeight}px`)
	// Header width is parent-driven (flex-shrink:0), so toggling isNarrow doesn't
	// change it — no observer feedback loop.
	isNarrow.value = headerRef.value.offsetWidth < NARROW_HEADER_PX
}

onMounted(() => {
	// Apply any filter params already in the URL (a shared link opened cold).
	applyUrlToFilter()

	// First-run keyboard-shortcut discoverability nudge (#3413).
	loadShortcutsHint()

	document.addEventListener('keydown', handleKeydown)

	if (headerRef.value && typeof ResizeObserver !== 'undefined') {
		toolbarResizeObserver = new ResizeObserver(onHeaderResize)
		toolbarResizeObserver.observe(headerRef.value)
	}
	onHeaderResize()

	const cleanups = [
		monitorForElements({
			canMonitor: ({ source }) => source.data.type === 'card',
			onDrop({ source, location }) {
				// A non-manual display sort is view-only - dropping must not rewrite
				// the fractional order, so ignore card drops until Manual is active.
				if (sortMode.value !== 'manual') return
				const { cardId, stackId: sourceStackId } = source.data

				// Walk drop targets innermost-first to find what we landed on
				const targets = location.current.dropTargets
				if (!targets.length) return

				let targetStackId = null
				let afterCardId = null
				let optimisticKey = null

				// Find card-level target (innermost) and column-level target
				const cardTarget = targets.find((t) => t.data.type === 'card')
				const columnTarget = targets.find((t) => t.data.type === 'column')

				// Swimlane guard (#3406): within-lane card reordering / cross-stack
				// moves are allowed, but a CROSS-LANE drop would change the grouping
				// field (reassign label/assignee/priority) - a documented v1 stretch
				// that is disabled. laneKey is '' when swimlanes are off, so this is a
				// no-op on the flat board. Reject when source and target lanes differ.
				const sourceLaneKey = source.data.laneKey ?? ''
				const targetLaneKey = (cardTarget?.data.laneKey ?? columnTarget?.data.laneKey) ?? ''
				if (sourceLaneKey !== targetLaneKey) return

				if (cardTarget) {
					const edge = extractClosestEdge(cardTarget.data)
					const targetCardId = cardTarget.data.cardId
					const targetStackId2 = cardTarget.data.stackId
					targetStackId = targetStackId2

					// Resolve neighbors as if the dragged card were already
					// removed - otherwise dropping on the top edge of the card
					// below yields the dragged card as its own anchor (400).
					const stackCards = (cardsByStack.value.get(targetStackId2) ?? [])
						.filter((c) => c.id !== cardId)
					const targetIdx = stackCards.findIndex((c) => c.id === targetCardId)
					const targetCard = stackCards[targetIdx]

					if (!targetCard) return // stale, or dropped onto itself

					if (edge === 'top') {
						// Insert before targetCard
						const prevCard = targetIdx > 0 ? stackCards[targetIdx - 1] : null
						afterCardId = prevCard?.id ?? null
						try {
							optimisticKey = prevCard
								? between(prevCard.sortKey, targetCard.sortKey)
								: before(targetCard.sortKey)
						} catch {
							// Keys too close or overflow; fall back to server truth via invalidation
							optimisticKey = targetCard.sortKey // will be fixed on reconcile
						}
					} else {
						// Insert after targetCard (bottom edge)
						const nextCard = targetIdx < stackCards.length - 1 ? stackCards[targetIdx + 1] : null
						afterCardId = targetCard.id
						try {
							optimisticKey = nextCard
								? between(targetCard.sortKey, nextCard.sortKey)
								: after(targetCard.sortKey)
						} catch {
							optimisticKey = targetCard.sortKey
						}
					}
				} else if (columnTarget) {
					// Drop on empty column space → append to end (excluding the
					// dragged card so it can't become its own anchor)
					targetStackId = columnTarget.data.stackId
					const stackCards = (cardsByStack.value.get(targetStackId) ?? [])
						.filter((c) => c.id !== cardId)
					const lastCard = stackCards.length > 0 ? stackCards[stackCards.length - 1] : null
					afterCardId = lastCard?.id ?? null
					try {
						optimisticKey = lastCard ? after(lastCard.sortKey) : initial()
					} catch {
						optimisticKey = initial()
					}
				} else {
					return // No valid target
				}

				// No-op guard: check if card is already in this position
				if (targetStackId === sourceStackId) {
					const stackCards = cardsByStack.value.get(targetStackId) ?? []
					const draggedIdx = stackCards.findIndex((c) => c.id === cardId)
					const cardBefore = draggedIdx > 0 ? stackCards[draggedIdx - 1] : null
					const currentAfterCardId = cardBefore?.id ?? null
					if (currentAfterCardId === afterCardId) return // already in this position
				}

				// A card dropped onto a collapsed column's rail lands at the end of
				// that stack (columnTarget branch above); expand it so the moved card
				// is visible rather than silently disappearing into a collapsed rail.
				expandStack(targetStackId)

				enqueueMove({ cardId, targetStackId, afterCardId, optimisticKey })

				// Announce the user's own move to assistive tech.
				const movedTitle = (cardsByStack.value.get(targetStackId) ?? [])
					.find((c) => c.id === cardId)?.title
					|| allVisibleCards.value.find((c) => c.id === cardId)?.title
					|| t('kanso', 'Card')
				announce(t('kanso', '{card} moved to {stack}', {
					card: movedTitle,
					stack: stackTitleById(targetStackId),
				}))
			},
		}),
		// Stack reordering: header-dragged columns dropped on another column's
		// left/right edge. Single-flight plain optimistic patch (no queue) -
		// stack moves are rare compared to card moves.
		monitorForElements({
			canMonitor: ({ source }) => source.data.type === 'stack',
			onDrop({ source, location }) {
				const draggedStackId = source.data.stackId

				const stackTarget = location.current.dropTargets.find((t) => t.data.type === 'stack')
				if (!stackTarget) return

				const edge = extractClosestEdge(stackTarget.data)
				const targetStackId = stackTarget.data.stackId

				// Resolve neighbours as if the dragged stack were already removed -
				// otherwise dropping on the near edge of an adjacent column yields
				// the dragged stack as its own anchor (400).
				const stacks = sortedStacks.value.filter((s) => s.id !== draggedStackId)
				const targetIdx = stacks.findIndex((s) => s.id === targetStackId)
				const targetStack = stacks[targetIdx]
				if (!targetStack) return // stale, or dropped onto itself

				// left edge → land before target (after its predecessor);
				// right edge → land after the target itself.
				const afterStack = edge === 'left'
					? (targetIdx > 0 ? stacks[targetIdx - 1] : null)
					: targetStack
				const afterStackId = afterStack?.id ?? null

				// No-op guard: already directly after that anchor
				const all = sortedStacks.value
				const draggedIdx = all.findIndex((s) => s.id === draggedStackId)
				if (draggedIdx === -1) return
				const currentAfterId = draggedIdx > 0 ? all[draggedIdx - 1].id : null
				if (currentAfterId === afterStackId) return

				// Optimistic client-side sort key, mirroring the card path
				let optimisticKey
				try {
					if (afterStack === null) {
						optimisticKey = stacks.length > 0 ? before(stacks[0].sortKey) : initial()
					} else if (edge === 'left') {
						optimisticKey = between(afterStack.sortKey, targetStack.sortKey)
					} else {
						const nextStack = targetIdx < stacks.length - 1 ? stacks[targetIdx + 1] : null
						optimisticKey = nextStack
							? between(targetStack.sortKey, nextStack.sortKey)
							: after(targetStack.sortKey)
					}
				} catch {
					// Keys too close or overflow; server truth arrives on reconcile
					optimisticKey = targetStack.sortKey
				}

				const key = boardQueryKey(props.id)
				const patchStackKey = (sortKey) => {
					queryClient.setQueryData(key, (old) => {
						if (!old) return old
						return {
							...old,
							stacks: old.stacks.map((s) =>
								s.id === draggedStackId ? { ...s, sortKey } : s,
							),
						}
					})
				}

				// Cancel in-flight board fetches so they can't clobber the patch
				queryClient.cancelQueries({ queryKey: key })
				// Snapshot the prior board so a failed move reverts immediately
				// (mirrors the snapshot-in-onMutate → restore-in-onError pattern),
				// rather than waiting on a refetch round-trip.
				const previousBoard = queryClient.getQueryData(key)
				patchStackKey(optimisticKey)

				apiMoveStack(draggedStackId, afterStackId)
					.then((updated) => {
						patchStackKey(updated.sortKey)
					})
					.catch((err) => {
						const serverError = err?.response?.data?.error
						shortcutError.value = serverError === 'rebalance_required'
							? t('kanso', 'Board ordering needs a refresh.')
							: t('kanso', 'Failed to move stack. Please try again.')
						if (previousBoard !== undefined) {
							queryClient.setQueryData(key, previousBoard)
						}
						queryClient.invalidateQueries({ queryKey: key })
					})
			},
		}),
	]

	// Auto-scroll the horizontal stacks container
	if (stacksWrapRef.value) {
		cleanups.push(
			autoScrollForElements({
				element: stacksWrapRef.value,
			}),
		)
	}

	boardCleanup = combine(...cleanups)
})

onUnmounted(() => {
	document.removeEventListener('keydown', handleKeydown)
	boardCleanup()
	if (toolbarResizeObserver) {
		toolbarResizeObserver.disconnect()
		toolbarResizeObserver = null
	}
	document.documentElement.style.removeProperty('--kanso-board-toolbar-height')
})

function goBack() {
	router.push({ name: 'board-list' })
}

function goToStats() {
	router.push({ name: 'board-stats', params: { id: props.id } })
}

function goToArchived() {
	router.push({ name: 'board-archived', params: { id: props.id } })
}

function goToTrash() {
	router.push({ name: 'board-trash', params: { id: props.id } })
}

async function submitNewStack() {
	const title = newStackTitle.value.trim()
	if (!title) return
	stackError.value = ''
	try {
		await createStack.mutateAsync({ boardId: Number(props.id), title })
		newStackTitle.value = ''
	} catch (err) {
		stackError.value =
			err?.response?.data?.error || t('kanso', 'Failed to create stack.')
	}
}

// On-demand column composer revealed from the ⋯ More menu. Kept off the board by
// default (was a persistent trailing input); shown when revealed here or when the
// board is empty (onboarding). Esc / empty-blur collapses it.
const showAddColumn = ref(false)
const addColumnInputRef = ref(null)

function revealAddColumn() {
	stackError.value = ''
	showAddColumn.value = true
	nextTick(() => {
		// Scroll the composer into view (it sits at the far right of the columns row)
		// and focus it so the user can type immediately.
		addColumnInputRef.value?.scrollIntoView?.({ inline: 'end', block: 'nearest' })
		addColumnInputRef.value?.focus()
	})
}

function collapseAddColumn() {
	showAddColumn.value = false
	newStackTitle.value = ''
	stackError.value = ''
}

// Blur collapses the on-demand composer only when it's empty; a non-empty draft
// stays open (mirrors the card composer) so a stray blur doesn't lose typing. The
// empty-board onboarding composer never collapses (there's nothing to collapse to).
function onAddColumnBlur() {
	if (showAddColumn.value && newStackTitle.value.trim() === '') {
		showAddColumn.value = false
	}
}

async function handleCreateCard(stackId, title, duedate = null, allDay = false) {
	// Only carry a due date when a natural-date token resolved to one (#3416);
	// a plain create stays the exact same payload as before (back-compat).
	const payload = { stackId, title }
	if (duedate) {
		payload.duedate = duedate
		payload.allDay = allDay
	}
	await createCard.mutateAsync(payload)
}

// Fetch the board's card templates for the composer picker (#3409). Lazily
// called when a column's "from template" menu opens.
async function handleFetchTemplates() {
	return apiFetchCardTemplates(boardId.value)
}

// Create a new card in stackId pre-filled from a template (#3409), then refetch
// the board so the fresh card appears (database-first).
async function handleCreateFromTemplate(stackId, templateId) {
	await apiCreateCardFromTemplate(templateId, stackId)
	queryClient.invalidateQueries({ queryKey: boardQueryKey(boardId.value) })
}

async function handleDeleteStack(stackId) {
	await deleteStack.mutateAsync(stackId)
}

async function handleRestoreStack(stackId) {
	await restoreStack.mutateAsync(stackId)
}

async function handleRenameStack(stackId, title) {
	await updateStack.mutateAsync({ stackId, data: { title } })
}

async function handleSetRole(stackId, role) {
	await updateStack.mutateAsync({ stackId, data: { role } })
}

async function handleSetWip(stackId, wipLimit) {
	await updateStack.mutateAsync({ stackId, data: { wipLimit } })
}

async function handleSetColor(stackId, color) {
	await updateStack.mutateAsync({ stackId, data: { color } })
}

// ── Multi-select / bulk actions ───────────────────────────────────────────────

/**
 * Called by StackColumn when a card tile emits 'select' in multi-select mode.
 * Shift-click selects the range from the last selection to this card; plain
 * click toggles the card in/out of the selection.
 *
 * @param {{ id: number, shiftKey: boolean }} payload
 */
function handleCardSelect({ id, shiftKey }) {
	if (shiftKey) {
		const orderedIds = allVisibleCards.value.map((c) => c.id)
		bulk.selectRange(orderedIds, id)
	} else {
		bulk.toggle(id)
	}
}

/**
 * Run one bulk action over the current selection. On success, surface a short
 * toast summarizing how many cards were applied vs. skipped (cards the caller
 * can't edit / that vanished are skipped server-side, not fatal); on failure,
 * show the server error in the board's error banner.
 *
 * @param {string} action - one of the fixed bulk actions
 * @param {object} params - action-specific params
 */
async function runBulkAction(action, params) {
	try {
		const result = await bulk.apply(action, params)
		const okCount = result?.ok?.length ?? 0
		const skippedCount = result?.skipped?.length ?? 0
		if (skippedCount > 0) {
			showWarning(t('kanso', '{ok} updated, {skipped} skipped', { ok: okCount, skipped: skippedCount }))
		} else if (okCount > 0) {
			showSuccess(t('kanso', '{ok} cards updated', { ok: okCount }))
		}
	} catch (err) {
		shortcutError.value = err?.response?.data?.error || t('kanso', 'Bulk action failed.')
	}
}

const onBulkMove = (stackId) => runBulkAction('move', { targetStackId: stackId })
const onBulkAddLabel = (labelId) => runBulkAction('add_label', { labelId })
const onBulkRemoveLabel = (labelId) => runBulkAction('remove_label', { labelId })
const onBulkAssign = (userId) => runBulkAction('assign_user', { userId })
const onBulkSetDue = (due) => runBulkAction('set_due_date', { duedate: due })
const onBulkArchive = () => runBulkAction('archive', {})
const onBulkDelete = () => runBulkAction('delete', {})
</script>

<style scoped>
/* Visually hidden but readable by screen readers (aria-live region). */
.board-view__sr-only {
	position: absolute;
	width: 1px;
	height: 1px;
	margin: -1px;
	padding: 0;
	overflow: hidden;
	clip: rect(0 0 0 0);
	clip-path: inset(50%);
	white-space: nowrap;
	border: 0;
}

.board-view {
	display: flex;
	flex-direction: column;
	height: 100%;
	overflow: hidden;
}

/* Board background (#3528): the chosen preset gradient sits BEHIND everything.
   The header is opaque (its own --color-main-background) and columns/cards ride
   on their own opaque surfaces, so the gradient only shows in the gutters while
   text stays readable. --board-background is set inline from the preset CSS. */
.board-view--has-background {
	background: var(--board-background);
}

/* One consolidated toolbar (variant 1a): a single opaque header row holding the
   back button, board title, then the right-aligned control cluster (search, view
   toggle, sort, swimlanes, filters, and the icon actions). A tighter gap groups
   the controls so they read as one bar rather than scattered buttons. */
.board-view__header {
	display: flex;
	align-items: center;
	gap: 8px;
	/* Extra left padding reserves room for the NcAppNavigation toggle, which is
	   pinned to the top-left of the app content area and would otherwise overlap
	   the "All boards" button. */
	padding: 10px 20px 10px 52px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-main-background);
	flex-shrink: 0;
	/* Sit above the right-docked settings panel (z-index 1800) so the toolbar
	   buttons (incl. the gear that toggles the panel) stay clickable and the
	   opaque header visually caps the panel below it. */
	position: relative;
	z-index: 1801;
}

.board-view__back {
	flex-shrink: 0;
}

.board-view__title {
	display: flex;
	align-items: center;
	gap: 10px;
	font-size: 1.2rem;
	font-weight: 700;
	color: var(--color-main-text);
	margin: 0;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.board-view__color-dot {
	flex-shrink: 0;
	width: 14px;
	height: 14px;
	border-radius: 50%;
}

.board-view__title-text {
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.board-view__title-skeleton {
	width: 200px;
	height: 20px;
	border-radius: 4px;
}

/* Search box - pushed to the right edge of the title area via margin-left: auto */
.board-view__search {
	margin-left: auto;
	flex-shrink: 0;
}

/* Compact density toggle (#3415) — an icon button among the view controls. */
.board-view__density-toggle {
	flex-shrink: 0;
}

/* Filter bar - sits after the search box. The dropdown internals (label dots,
   priority dots) now live in BoardFilterBar.vue. */
.board-view__filter-menu {
	flex-shrink: 0;
}

.board-view__filter-error {
	color: var(--color-error);
	font-size: 0.8rem;
	flex-shrink: 0;
}

/* Settings gear button */
.board-view__settings-btn {
	flex-shrink: 0;
	margin-left: 4px;
}

.board-view__error {
	padding: 40px 24px;
	text-align: center;
	color: var(--color-error);
}

.board-view__error-msg {
	margin: 0 0 20px;
	color: var(--color-main-text);
	font-size: 1.05rem;
}

.board-view__error-actions {
	display: flex;
	justify-content: center;
	flex-wrap: wrap;
	gap: 10px;
}

.board-view__move-error {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 24px;
	background: rgba(var(--color-error-rgb, 227, 0, 0), 0.1);
	color: var(--color-error);
	font-size: 0.875rem;
	flex-shrink: 0;
}

.board-view__move-error-dismiss {
	background: none;
	border: none;
	color: var(--color-error);
	cursor: pointer;
	font-size: 1.2rem;
	line-height: 1;
	padding: 0 4px;
}

/* Stacks scrollable row - the "sunken" board canvas (variant 1a). A recessed
   surface so the white/raised columns lift off it. When a custom board
   background gradient is active we stay transparent so the gradient shows
   through instead (see the --has-background override below). */
.board-view__stacks-wrap {
	display: flex;
	flex-direction: row;
	align-items: flex-start;
	gap: 16px;
	padding: 20px 24px;
	overflow-x: auto;
	overflow-y: hidden;
	flex: 1;
	background: var(--color-background-hover);
}

/* With a custom board background, let the gradient be the canvas. */
.board-view--has-background .board-view__stacks-wrap,
.board-view--has-background .board-view__swimlanes-wrap {
	background: transparent;
}

/* Swimlanes (grouped board): vertical scroll region holding stacked lanes.
   Each lane scrolls horizontally on its own; this wrapper scrolls vertically. */
.board-view__swimlanes-wrap {
	display: flex;
	flex-direction: column;
	overflow-y: auto;
	overflow-x: hidden;
	flex: 1;
	min-height: 0;
}

/* Give each lane's stacks row its own horizontal scroll so lanes stay aligned
   to the left edge while long boards scroll sideways independently. */
.board-view__swimlanes-wrap :deep(.swimlane__stacks) {
	overflow-x: auto;
}

.board-view__swimlanes-empty {
	padding: 24px;
	color: var(--color-text-maxcontrast);
}

/* The consolidated "More" overflow menu trigger. */
.board-view__more-menu {
	flex-shrink: 0;
}

/* Skeleton stacks */
.stack-skeleton {
	flex-shrink: 0;
	width: 280px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.stack-skeleton__title {
	height: 18px;
	width: 60%;
	border-radius: 4px;
	margin-bottom: 4px;
}

.skeleton-card {
	height: 52px;
	border-radius: var(--border-radius);
	background: linear-gradient(90deg, var(--color-border) 25%, var(--color-background-hover) 50%, var(--color-border) 75%);
	background-size: 400px 100%;
	animation: shimmer 1.4s infinite linear;
}

@keyframes shimmer {
	0% { background-position: -400px 0; }
	100% { background-position: 400px 0; }
}

.skeleton-text {
	background: linear-gradient(90deg, var(--color-border) 25%, var(--color-background-hover) 50%, var(--color-border) 75%);
	background-size: 400px 100%;
	animation: shimmer 1.4s infinite linear;
}

/* Add stack */
.add-stack {
	flex-shrink: 0;
	width: 240px;
}

/* Empty-board first-column composer: a little breathing room so a fresh board
   reads as an intentional prompt rather than a stray input. */
.add-stack--empty {
	padding-top: 8px;
}

.add-stack__input {
	width: 100%;
	height: 36px;
	padding: 0 12px;
	border: 2px dashed var(--color-border);
	border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.875rem;
	transition: border-color 0.15s ease;
}

.add-stack__input:focus {
	outline: none;
	border-color: var(--color-primary);
	border-style: solid;
}

.add-stack__error {
	color: var(--color-error);
	font-size: 0.8rem;
	margin: 4px 0 0;
}

/* Empty-board onboarding hint (#3413): sits above the "Add stack" composer when
   the board has no columns yet. */
.add-stack__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	margin: 0 0 8px;
}

/* One-time keyboard-shortcut discoverability hint (#3413): a small, unobtrusive
   pill anchored bottom-left, out of the way of the bulk action bar (centered). */
.board-view__shortcuts-hint {
	position: fixed;
	right: 16px;
	bottom: 16px;
	/* Above the app content and the NC left nav so its trigger is clickable. */
	z-index: 2000;
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 4px 4px 4px 12px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 100px);
	box-shadow: var(--shadow-card-hover, 0 1px 4px rgba(0, 0, 0, 0.2));
	font-size: 0.85rem;
}

.board-view__shortcuts-hint-open {
	border: none;
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.85rem;
	cursor: pointer;
	padding: 2px 0;
}

.board-view__shortcuts-hint-open:hover {
	color: var(--color-primary-element);
}

.board-view__shortcuts-hint-dismiss {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 1.1rem;
	line-height: 1;
	cursor: pointer;
}

.board-view__shortcuts-hint-dismiss:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

/* Keyboard shortcuts modal */
.shortcuts-modal {
	padding: 16px 24px 24px;
}

.shortcuts-modal__table {
	width: 100%;
	border-collapse: collapse;
}

.shortcuts-modal__table tr + tr td {
	padding-top: 10px;
}

.shortcuts-modal__key {
	width: 120px;
	padding-right: 16px;
	white-space: nowrap;
	vertical-align: top;
}

.shortcuts-modal__key kbd {
	display: inline-block;
	padding: 2px 7px;
	font-size: 0.8rem;
	font-family: monospace;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: 4px;
	color: var(--color-main-text);
}

/* Archived cards badge button */
.board-view__archived-btn {
	flex-shrink: 0;
}

/* Trash button */
.board-view__trash-btn {
	flex-shrink: 0;
}

/* Quick-look preview click-away backdrop - transparent, sits just under the
   panel (panel z-index 2100) and above the board content. */
.card-preview-backdrop {
	position: fixed;
	inset: 0;
	z-index: 2099;
	background: transparent;
}
</style>
