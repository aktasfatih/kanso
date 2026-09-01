<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		class="card-modal"
		:class="[
			`card-modal--tab-${viewMode}`,
			`card-modal--mode-${mode}`,
			{ 'card-modal--discussion-collapsed': discussionCollapsed },
		]"
		@keydown.escape="onRootEscape">
		<!-- Loading state - real layout, shimmer, never a spinner -->
			<div v-if="isLoading" class="card-modal__skeleton">
				<div class="card-modal__sk-header">
					<div class="card-modal__sk-col">
						<div class="kskel" style="height:12px;width:180px" />
						<div class="kskel" style="height:26px;width:52%" />
					</div>
					<div class="kskel" style="height:36px;width:120px;border-radius:100px" />
					<div class="kskel" style="height:36px;width:64px;border-radius:100px" />
				</div>
				<div class="card-modal__sk-bar">
					<div class="kskel" style="height:32px;width:92px;border-radius:10px" />
					<div class="kskel" style="height:32px;width:150px;border-radius:10px" />
					<div class="kskel" style="height:32px;width:130px;border-radius:10px" />
					<div class="kskel" style="height:32px;width:90px;border-radius:10px" />
				</div>
				<div class="card-modal__sk-body">
					<div class="card-modal__sk-main">
						<div class="kskel" style="height:10px;width:90px" />
						<div class="kskel" style="height:14px;width:100%" />
						<div class="kskel" style="height:14px;width:92%" />
						<div class="kskel" style="height:14px;width:64%" />
						<div class="kskel" style="height:150px;width:100%;border-radius:10px;margin-top:14px" />
					</div>
					<div class="card-modal__sk-side">
						<div class="kskel" style="height:78px;border-radius:10px" />
						<div class="kskel" style="height:60px;border-radius:10px" />
					</div>
				</div>
			</div>

			<!-- Error state - status-aware, with a way out (#3662). A 404/403 from a
			     dead deep-link/inbox/notification reads as "gone"/"no access" and is a
			     dead end; a transient failure offers a retry. -->
			<div v-else-if="isError" class="card-modal__error">
				<p class="card-modal__error-msg">{{ cardErrorMessage }}</p>
				<div class="card-modal__error-actions">
					<NcButton v-if="cardErrorRetryable" type="primary" @click="retryCardLoad">
						{{ t('kanso', 'Retry') }}
					</NcButton>
					<NcButton v-if="boardData" @click="closeModal">
						{{ t('kanso', 'Back to board') }}
					</NcButton>
					<NcButton :type="boardData ? 'tertiary' : 'primary'" @click="goToBoards">
						{{ t('kanso', 'Go to boards') }}
					</NcButton>
				</div>
			</div>

			<!-- Card content -->
			<template v-else-if="cardData">
				<!-- Verdict banner - shown when the current user owes a verdict on this card -->
				<div
					v-for="review in myPendingReviews"
					:key="`verdict-${review.id}`"
					class="card-modal__verdict">
					<CheckDecagramOutlineIcon :size="20" class="card-modal__verdict-icon" />
					<div class="card-modal__verdict-copy">
						<span class="card-modal__verdict-title">
							{{ reviewTypeById(review.reviewTypeId)
								? t('kanso', 'Your {type} review is requested', { type: reviewTypeById(review.reviewTypeId).title })
								: t('kanso', 'Your review is requested on this card') }}
						</span>
						<span class="card-modal__verdict-sub">
							{{ t('kanso', 'Done-gated columns block Done until every review is approved.') }}
						</span>
					</div>
					<div class="card-modal__verdict-actions">
						<template v-if="changesReasonFor === review.id">
							<textarea
								v-model="changesReasonText"
								class="card-modal__verdict-reason"
								:placeholder="t('kanso', 'What changes are needed? (posted as a comment)')"
								rows="2" />
							<NcButton
								type="error"
								:disabled="setReviewState.isPending.value || !changesReasonText.trim()"
								@click="submitChangesRequested(review.id)">
								{{ t('kanso', 'Submit') }}
							</NcButton>
							<NcButton :disabled="setReviewState.isPending.value" @click="cancelChangesRequested">
								{{ t('kanso', 'Cancel') }}
							</NcButton>
						</template>
						<template v-else>
							<NcButton
								type="success"
								:disabled="setReviewState.isPending.value"
								@click="handleReviewVerdict(review, 'approved')">
								<template #icon>
									<CheckDecagramIcon :size="16" />
								</template>
								{{ t('kanso', 'Approve') }}
							</NcButton>
							<NcButton
								type="error"
								:disabled="setReviewState.isPending.value"
								@click="handleReviewVerdict(review, 'changes_requested')">
								<template #icon>
									<AlertDecagramIcon :size="16" />
								</template>
								{{ t('kanso', 'Request changes') }}
							</NcButton>
						</template>
					</div>
				</div>
				<span v-if="reviewError" class="card-modal__save-error">{{ reviewError }}</span>

				<!-- Header band: breadcrumb + title + action cluster -->
				<header class="card-modal__header">
					<div class="card-modal__header-main">
						<div class="card-modal__breadcrumb">
							<span class="card-modal__crumb">{{ boardName }}</span>
							<!-- The card's column, always readable without opening anything - on a
							     board with no workflow roles this is the only place it shows. -->
							<template v-if="currentColumnName">
								<ChevronRightIcon :size="14" class="card-modal__crumb-chevron" />
								<span class="card-modal__crumb card-modal__crumb--column" :title="currentColumnName">{{ currentColumnName }}</span>
							</template>
							<ChevronRightIcon :size="14" class="card-modal__crumb-chevron" />
							<span class="card-modal__attr card-modal__status-wrap">
								<button
									class="card-modal__status-chip card-modal__status-chip--btn"
									:class="`card-modal__status-chip--${currentStatus}`"
									:disabled="updateCard.isPending.value || stageMoving"
									:aria-expanded="openPicker === 'status'"
									:title="t('kanso', 'Change status')"
									@click="togglePicker('status')">
									{{ statusChipLabel }}
									<ChevronDownIcon :size="12" />
								</button>
								<div v-if="openPicker === 'status'" class="card-modal__popover">
									<!-- Every live column is an option (#54); pick the exact one. Offered
									     on every board, so a card can change column without changing
									     status - and change status without leaving its column. -->
									<span class="card-modal__popover-head">{{ t('kanso', 'Column') }}</span>
									<button
										v-for="col in boardColumns"
										:key="`stage-col-${col.id}`"
										class="card-modal__popover-opt card-modal__popover-opt--column"
										:class="{ 'card-modal__popover-opt--active': Number(col.id) === Number(cardData.stackId) }"
										:disabled="updateCard.isPending.value || stageMoving"
										@click="setStage(col); openPicker = null">
										{{ stageLabel(col) }}
									</button>
									<span class="card-modal__popover-head">{{ t('kanso', 'Status') }}</span>
									<button
										v-for="opt in STATUS_OPTIONS"
										:key="opt.key"
										class="card-modal__popover-opt card-modal__popover-opt--status"
										:class="{ 'card-modal__popover-opt--active': currentStatus === opt.key }"
										:disabled="updateCard.isPending.value"
										@click="setStatus(opt.key); openPicker = null">
										{{ opt.label }}
									</button>
								</div>
							</span>
							<span class="card-modal__crumb-dot">·</span>
							<span class="card-modal__crumb">#{{ cardData.id }}</span>
						</div>
						<div class="card-modal__title-row">
							<!-- Copyable human-readable reference id (e.g. KAN-123), alongside the
							     title now that the breadcrumb carries the column. -->
							<button
								v-if="cardHumanId"
								class="card-modal__ref"
								type="button"
								:title="t('kanso', 'Copy reference {ref}', { ref: cardHumanId })"
								@click="copyCardRef">
								{{ cardHumanId }}
							</button>
							<input
								v-if="editingTitle"
								ref="titleInputRef"
								v-model="draftTitle"
								class="card-modal__title-input"
								type="text"
								@keydown.enter.prevent="saveTitle"
								@keydown.escape.stop="cancelTitleEdit"
								@blur="saveTitle">
							<h2
								v-else
								class="card-modal__title"
								role="button"
								tabindex="0"
								:aria-label="t('kanso', 'Edit title')"
								@click="startTitleEdit"
								@keydown.enter.prevent="startTitleEdit"
								@keydown.space.prevent="startTitleEdit">
								{{ cardData.title }}
							</h2>
						</div>
					</div>

					<div class="card-modal__header-actions">
						<!-- Collapse/expand the discussion pane (#9854). Lives in the header
						     so the affordance costs zero horizontal space - collapsing gives
						     the main pane the entire body width, with no residual rail.
						     Hidden below 680px, where the panes are tabbed instead. -->
						<button
							class="card-modal__discussion-toggle"
							:class="{ 'card-modal__discussion-toggle--collapsed': discussionCollapsed }"
							:aria-expanded="!discussionCollapsed"
							:aria-controls="discussionPaneId"
							:title="discussionToggleLabel"
							:aria-label="discussionToggleLabel"
							@click="toggleDiscussionCollapsed">
							<DockRightIcon :size="18" />
							<!-- Collapsed, the pane's comment count is the only signal left
							     that the card has a conversation - keep it visible. -->
							<span
								v-if="discussionCollapsed && commentCount > 0"
								class="card-modal__discussion-toggle-count">{{ commentCount }}</span>
						</button>
						<!-- Expand the modal into the standalone full-page card view.
						     Only shown in modal mode - the page has nothing to expand to. -->
						<button
							v-if="mode === 'modal'"
							class="card-modal__expand-btn"
							:title="t('kanso', 'Open as full page')"
							:aria-label="t('kanso', 'Open as full page')"
							@click="expandToPage">
							<OpenInNewIcon :size="18" />
						</button>
						<button
							class="card-modal__done-btn"
							:class="{ 'card-modal__done-btn--done': isDone }"
							:disabled="updateCard.isPending.value"
							@click="setStatus(isDone ? 'in_progress' : 'done')">
							<CheckCircleOutlineIcon :size="18" />
							<span class="card-modal__done-label">{{ isDone ? t('kanso', 'Done') : t('kanso', 'Mark done') }}</span>
						</button>

						<span class="card-modal__watch-wrap">
							<button
								class="card-modal__watch-btn"
								:class="{ 'card-modal__watch-btn--active': isWatching }"
								:aria-pressed="isWatching"
								:disabled="toggleSubscription.isPending.value"
								:title="isWatching ? t('kanso', 'Stop watching this card') : t('kanso', 'Watch this card')"
								@click="handleWatchToggle">
								<EyeOffOutlineIcon v-if="isWatching" :size="18" />
								<EyeOutlineIcon v-else :size="18" />
								<span v-if="watcherCount > 0" class="card-modal__watch-count">{{ watcherCount }}</span>
								<span v-else class="card-modal__watch-label">{{ t('kanso', 'Watch') }}</span>
							</button>
							<button
								class="card-modal__watch-caret"
								:class="{ 'card-modal__watch-caret--active': isWatching }"
								:aria-expanded="openPicker === 'watchers'"
								:aria-label="t('kanso', 'Show watchers')"
								:title="t('kanso', 'Show watchers')"
								@click="togglePicker('watchers')">
								<ChevronDownIcon :size="16" />
							</button>
							<div
								v-if="openPicker === 'watchers'"
								class="card-modal__popover card-modal__popover--right card-modal__watch-panel"
								role="dialog"
								:aria-label="t('kanso', 'Watchers')">
								<span class="card-modal__watch-panel-title">
									{{ n('kanso', '%n watcher', '%n watchers', watcherCount) }}
								</span>
								<span
									v-for="uid in displayedWatcherIds"
									:key="'watch-panel-' + uid"
									class="card-modal__watch-row">
									<NcAvatar
										:user="uid"
										:display-name="participantName(uid)"
										:size="24"
										:hide-status="true"
										:disable-tooltip="true" />
									<span class="card-modal__watch-row-name">{{ participantName(uid) }}</span>
									<button
										v-if="canEdit"
										class="card-modal__pill-x"
										:title="t('kanso', 'Remove watcher')"
										:disabled="toggleOtherSubscription.isPending.value"
										@click="handleToggleWatcher(uid, false)">
										<CloseIcon :size="12" />
									</button>
								</span>
								<span
									v-if="displayedWatcherIds.length === 0 && !isWatching"
									class="card-modal__watch-panel-empty">
									{{ t('kanso', 'No one is watching this card yet.') }}
								</span>
								<template v-if="canEdit && unwatchedParticipants.length > 0">
									<span class="card-modal__watch-panel-divider" />
									<span class="card-modal__watch-panel-subtitle">{{ t('kanso', 'Add watcher') }}</span>
									<button
										v-for="p in unwatchedParticipants"
										:key="'watch-add-' + p.uid"
										class="card-modal__assign-option"
										:disabled="toggleOtherSubscription.isPending.value"
										@click="handleToggleWatcher(p.uid, true)">
										<NcAvatar
											:user="p.uid"
											:display-name="p.displayName"
											:size="24"
											:hide-status="true"
											:disable-tooltip="true" />
										<span>{{ p.displayName }}</span>
									</button>
								</template>
								<span v-if="watcherError" class="card-modal__save-error">{{ watcherError }}</span>
							</div>
						</span>

						<NcActions class="card-modal__actions-menu" :force-menu="true">
							<NcActionButton
								v-if="canEdit"
								:close-after-click="true"
								@click="openRelationEditor">
								<template #icon>
									<VectorLinkIcon :size="20" />
								</template>
								{{ t('kanso', 'Add relation') }}
							</NcActionButton>
							<NcActionButton
								v-if="canEdit && !cardData.parentCardId && availableChildCards.length > 0"
								:close-after-click="true"
								@click="openLinkChildEditor">
								<template #icon>
									<LinkVariantIcon :size="20" />
								</template>
								{{ t('kanso', 'Link a sub-card') }}
							</NcActionButton>
							<NcActionButton
								v-if="canEdit && !cardData.parentCardId && children.length === 0 && availableParentCards.length > 0"
								:close-after-click="true"
								@click="openSetParentEditor">
								<template #icon>
									<SitemapIcon :size="20" />
								</template>
								{{ t('kanso', 'Make this a sub-card of…') }}
							</NcActionButton>
							<NcActionSeparator v-if="canEdit" />
							<NcActionButton
								v-if="canEdit"
								:close-after-click="true"
								@click="moveToEdge(true)">
								<template #icon>
									<ChevronDoubleUpIcon :size="20" />
								</template>
								{{ t('kanso', 'Move to top') }}
							</NcActionButton>
							<NcActionButton
								v-if="canEdit"
								:close-after-click="true"
								@click="moveToEdge(false)">
								<template #icon>
									<ChevronDoubleDownIcon :size="20" />
								</template>
								{{ t('kanso', 'Move to bottom') }}
							</NcActionButton>
							<NcActionButton
								v-if="canEdit"
								:close-after-click="true"
								@click="openMovePicker">
								<template #icon>
									<DragIcon :size="20" />
								</template>
								{{ t('kanso', 'Move card…') }}
							</NcActionButton>
							<NcActionButton
								v-if="canEdit"
								:close-after-click="true"
								@click="openCopyDialog">
								<template #icon>
									<ContentDuplicateIcon :size="20" />
								</template>
								{{ t('kanso', 'Copy to…') }}
							</NcActionButton>
							<NcActionButton
								v-if="canEdit"
								:close-after-click="true"
								@click="openMoveToBoardDialog">
								<template #icon>
									<TransferIcon :size="20" />
								</template>
								{{ t('kanso', 'Move to board…') }}
							</NcActionButton>
							<NcActionButton
								v-if="canEdit"
								:close-after-click="true"
								:disabled="templatePending"
								@click="handleTemplateToggle">
								<template #icon>
									<FileDocumentOutlineIcon :size="20" />
								</template>
								{{ cardData.isTemplate ? t('kanso', 'Unmark as template') : t('kanso', 'Mark as template') }}
							</NcActionButton>
							<NcActionSeparator v-if="canEdit" />
							<NcActionButton
								:close-after-click="true"
								:disabled="copyingPrompt"
								@click="copyAsPrompt">
								<template #icon>
									<ContentCopyIcon :size="20" />
								</template>
								{{ t('kanso', 'Copy as prompt') }}
							</NcActionButton>
							<NcActionSeparator />
							<NcActionButton
								:close-after-click="true"
								@click="scheduleReminder(reminderPresets()[0].at)">
								<template #icon>
									<BellOutlineIcon :size="20" />
								</template>
								{{ t('kanso', 'Remind me later today') }}
							</NcActionButton>
							<NcActionButton
								:close-after-click="true"
								@click="scheduleReminder(reminderPresets()[1].at)">
								<template #icon>
									<BellOutlineIcon :size="20" />
								</template>
								{{ t('kanso', 'Remind me tomorrow') }}
							</NcActionButton>
							<NcActionButton
								:close-after-click="true"
								@click="scheduleReminder(reminderPresets()[2].at)">
								<template #icon>
									<BellOutlineIcon :size="20" />
								</template>
								{{ t('kanso', 'Remind me next week') }}
							</NcActionButton>
							<NcActionButton
								:close-after-click="true"
								@click="openCustomReminder(null)">
								<template #icon>
									<BellPlusOutlineIcon :size="20" />
								</template>
								{{ t('kanso', 'Remind me at a custom time…') }}
							</NcActionButton>
							<NcActionSeparator />
							<NcActionButton :close-after-click="true" @click="handleArchiveToggle">
								<template #icon>
									<ArchiveArrowDownIcon v-if="!cardData.archived" :size="20" />
									<ArchiveArrowUpIcon v-else :size="20" />
								</template>
								{{ cardData.archived ? t('kanso', 'Unarchive') : t('kanso', 'Archive') }}
							</NcActionButton>
							<NcActionButton :close-after-click="true" @click="confirmDeleteCard">
								<template #icon>
									<TrashCanIcon :size="20" />
								</template>
								{{ t('kanso', 'Delete') }}
							</NcActionButton>
						</NcActions>
					</div>
				</header>
				<span v-if="actionError" class="card-modal__save-error card-modal__action-error">{{ actionError }}</span>
				<!-- Recurring-source delete guard: shown when a manager tries to delete a card that is a recurrence template -->
				<NcDialog
					:open="deleteWithRuleConfirm"
					:name="t('kanso', 'Delete recurring card')"
					size="normal"
					:buttons="deleteGuardButtons"
					@update:open="(v) => { if (!v) deleteWithRuleConfirm = false }">
					<p class="card-modal__delete-guard-msg">
						{{ t('kanso', 'This card powers a recurring series. Delete just this card, or also stop the recurrence?') }}
					</p>
				</NcDialog>
				<span v-if="subscriptionError" class="card-modal__save-error">{{ subscriptionError }}</span>
				<span v-if="reminderError" class="card-modal__save-error">{{ reminderError }}</span>

				<!-- Personal reminders (#3816): the viewer's OWN pending reminders on
				     this card, each cancellable. Private per-user. -->
				<div v-if="myReminders.length > 0" class="card-modal__reminders">
					<span
						v-for="reminder in myReminders"
						:key="reminder.id"
						class="card-modal__reminder-chip"
						:title="reminder.commentId ? t('kanso', 'Reminder about a comment') : t('kanso', 'Reminder')">
						<BellOutlineIcon :size="14" />
						<span class="card-modal__reminder-time">{{ formatReminderTime(reminder.remindAt) }}</span>
						<button
							class="card-modal__reminder-cancel"
							:title="t('kanso', 'Cancel reminder')"
							:disabled="cancelReminder.isPending.value"
							@click="handleCancelReminder(reminder)">
							<CloseIcon :size="12" />
						</button>
					</span>
				</div>

				<!-- Inline custom date-time picker, shared by the card menu and the
				     comment "Remind me" action. -->
				<div v-if="customReminderFor !== undefined" class="card-modal__reminder-custom">
					<label class="card-modal__reminder-custom-label">
						{{ customReminderFor ? t('kanso', 'Remind me about this comment at') : t('kanso', 'Remind me at') }}
					</label>
					<input
						v-model="customReminderValue"
						type="datetime-local"
						class="card-modal__reminder-custom-input"
						@keydown.enter.prevent="submitCustomReminder"
						@keydown.escape.stop="cancelCustomReminder">
					<NcButton type="primary" :disabled="createReminder.isPending.value || !customReminderValue" @click="submitCustomReminder">
						{{ t('kanso', 'Set reminder') }}
					</NcButton>
					<NcButton @click="cancelCustomReminder">{{ t('kanso', 'Cancel') }}</NcButton>
				</div>

				<!-- Attribute bar: every card attribute on one scannable row -->
				<div class="card-modal__attrbar">
					<!-- Priority -->
					<div class="card-modal__attr">
						<button
							class="card-modal__pill"
							data-pill="priority"
							:class="currentPriority > 0 ? `card-modal__pill--priority-${currentPriority}` : 'card-modal__pill--dashed'"
							:aria-expanded="openPicker === 'priority'"
							@click="togglePicker('priority')">
							<FlagIcon v-if="currentPriority > 0" :size="14" />
							<FlagOutlineIcon v-else :size="14" />
							{{ currentPriority > 0 ? currentPriorityLevel.label : t('kanso', 'Priority') }}
						</button>
						<div v-if="openPicker === 'priority'" class="card-modal__popover">
							<button
								v-for="level in PRIORITY_LEVELS"
								:key="level.value"
								class="card-modal__popover-opt"
								:class="{ 'card-modal__popover-opt--active': currentPriority === level.value }"
								:disabled="setPriority.isPending.value"
								@click="handleSetPriority(level.value); openPicker = null">
								{{ level.value === 0 ? t('kanso', 'None') : level.label }}
							</button>
							<span v-if="priorityError" class="card-modal__save-error">{{ priorityError }}</span>
						</div>
					</div>

					<!-- Type (#3402): exactly one built-in issue type, icon-first -->
					<div class="card-modal__attr">
						<button
							class="card-modal__pill"
							data-pill="type"
							:class="currentType ? `card-modal__pill--type-${currentType.value}` : 'card-modal__pill--dashed'"
							:aria-expanded="openPicker === 'type'"
							@click="togglePicker('type')">
							<component :is="typeIcon(currentType?.value)" :size="14" />
							{{ currentType ? currentType.label : t('kanso', 'Type') }}
						</button>
						<div v-if="openPicker === 'type'" class="card-modal__popover">
							<button
								class="card-modal__popover-opt"
								:class="{ 'card-modal__popover-opt--active': !currentType }"
								:disabled="setType.isPending.value"
								@click="handleSetType(''); openPicker = null">
								{{ t('kanso', 'None') }}
							</button>
							<button
								v-for="tp in CARD_TYPES"
								:key="tp.value"
								class="card-modal__popover-opt"
								:class="{ 'card-modal__popover-opt--active': currentTypeValue === tp.value }"
								:disabled="setType.isPending.value"
								@click="handleSetType(tp.value); openPicker = null">
								<component :is="typeIcon(tp.value)" :size="14" />
								{{ tp.label }}
							</button>
							<span v-if="typeError" class="card-modal__save-error">{{ typeError }}</span>
						</div>
					</div>

					<!-- Dates (due + start) -->
					<div class="card-modal__attr">
						<button
							class="card-modal__pill"
							data-pill="due"
							:class="cardData.duedate ? dueDateClass : 'card-modal__pill--dashed'"
							:aria-expanded="openPicker === 'due'"
							@click="togglePicker('due')">
							<!-- A recurring card swaps the calendar glyph for a repeat
							     icon (#61 follow-up), matching the board tile cue for
							     all viewers. Same footprint, so no layout shift. -->
							<RepeatIcon v-if="cardIsRecurring" :size="14" :title="t('kanso', 'Repeats')" />
							<CalendarIcon v-else :size="14" />
							{{ cardData.duedate ? dueDateLabel : t('kanso', 'Due date') }}
						</button>
						<div v-if="openPicker === 'due'" class="card-modal__popover card-modal__popover--pad card-modal__popover--date">
							<!-- A timed card is a Start → End window; an all-day card is a
							     single day, so it collapses to one date field (no time). -->
							<template v-if="!isAllDay">
								<label class="card-modal__field-label">{{ t('kanso', 'Start date') }}<span class="card-modal__field-hint" tabindex="0" role="img" :title="startDateHint" :aria-label="startDateHint"><InformationOutlineIcon :size="13" /></span></label>
								<div class="card-modal__field-row">
									<input
										class="card-modal__date-input"
										data-date="start"
										type="datetime-local"
										:value="startDateInputValue"
										@blur="handleStartDateChange"
										@keyup.enter="handleStartDateChange">
									<button v-if="cardData.startDate" class="card-modal__field-clear" :title="t('kanso', 'Clear start date')" @click="clearStartDate">
										<CloseIcon :size="14" />
									</button>
								</div>
								<label class="card-modal__field-label">{{ t('kanso', 'Due date') }}<span class="card-modal__field-hint" tabindex="0" role="img" :title="dueDateHint" :aria-label="dueDateHint"><InformationOutlineIcon :size="13" /></span></label>
								<div class="card-modal__field-row">
									<input
										class="card-modal__date-input"
										data-date="end"
										type="datetime-local"
										:value="dueDateInputValue"
										@blur="handleDueDateChange"
										@keyup.enter="handleDueDateChange">
									<button v-if="cardData.duedate" class="card-modal__field-clear" :title="t('kanso', 'Clear due date')" @click="clearDueDate">
										<CloseIcon :size="14" />
									</button>
								</div>
							</template>
							<template v-else>
								<label class="card-modal__field-label">{{ t('kanso', 'Due date') }}<span class="card-modal__field-hint" tabindex="0" role="img" :title="dueDateHint" :aria-label="dueDateHint"><InformationOutlineIcon :size="13" /></span></label>
								<div class="card-modal__field-row">
									<input
										class="card-modal__date-input"
										data-date="end"
										type="date"
										:value="dueDateInputValue"
										@blur="handleDueDateChange"
										@keyup.enter="handleDueDateChange">
									<button v-if="cardData.duedate" class="card-modal__field-clear" :title="t('kanso', 'Clear due date')" @click="clearDueDate">
										<CloseIcon :size="14" />
									</button>
								</div>
							</template>
							<label class="card-modal__allday">
								<input
									type="checkbox"
									:checked="isAllDay"
									@change="toggleAllDay($event.target.checked)">
								{{ t('kanso', 'All day (no time)') }}
							</label>
							<!-- Surface date-save failures here (e.g. the end-before-start
							     guard's 400) - otherwise a rejected date edit is silent. -->
							<span v-if="saveError" class="card-modal__save-error">{{ saveError }}</span>
							<!-- Repeat / recurrence (#55) - managers only; reuses the
							     recurring-card engine with this card as the source. -->
							<template v-if="canManage">
								<label class="card-modal__field-label card-modal__recur-label">{{ t('kanso', 'Repeat') }}<span class="card-modal__field-hint" tabindex="0" role="img" :title="repeatHint" :aria-label="repeatHint"><InformationOutlineIcon :size="13" /></span></label>
								<p v-if="recurIsCustom" class="card-modal__recur-note">
									{{ t('kanso', 'Custom schedule — edit it in Board settings → Automation.') }}
								</p>
								<template v-else>
									<div class="card-modal__field-row">
										<select
											class="card-modal__date-input"
											data-recur="freq"
											:value="recurFreq"
											:disabled="recurBusy"
											@change="onRecurFreqChange($event.target.value)">
											<option value="OFF">{{ t('kanso', 'Does not repeat') }}</option>
											<option value="DAILY">{{ t('kanso', 'Daily') }}</option>
											<option value="WEEKLY">{{ t('kanso', 'Weekly') }}</option>
											<option value="MONTHLY">{{ t('kanso', 'Monthly') }}</option>
											<option value="YEARLY">{{ t('kanso', 'Yearly') }}</option>
										</select>
									</div>
									<div v-if="recurFreq !== 'OFF'" class="card-modal__field-row card-modal__recur-interval">
										<span class="card-modal__recur-every">{{ t('kanso', 'every') }}</span>
										<input
											class="card-modal__date-input card-modal__recur-interval-input"
											data-recur="interval"
											type="number"
											min="1"
											:value="recurInterval"
											:disabled="recurBusy"
											@change="onRecurIntervalChange($event.target.value)">
										<span class="card-modal__recur-unit">{{ recurUnitLabel }}</span>
									</div>
									<div v-if="recurFreq !== 'OFF'" class="card-modal__field-row card-modal__recur-mode">
										<label class="card-modal__recur-mode-opt" :class="{ 'card-modal__recur-mode-opt--active': recurMode === 1 }">
											<input type="radio" :value="1" v-model="recurMode" :disabled="recurBusy" @change="applyRecurrence" />
											{{ t('kanso', 'Bring this card back') }}
										</label>
										<label class="card-modal__recur-mode-opt" :class="{ 'card-modal__recur-mode-opt--active': recurMode === 0 }">
											<input type="radio" :value="0" v-model="recurMode" :disabled="recurBusy" @change="applyRecurrence" />
											{{ t('kanso', 'Create a new card each time') }}
										</label>
									</div>
								</template>
								<span v-if="recurError" class="card-modal__save-error">{{ recurError }}</span>
							</template>
						</div>
					</div>

					<!-- Estimate -->
					<div v-if="boardEstimateScale !== 'none'" class="card-modal__attr">
						<button
							class="card-modal__pill"
							:class="currentEstimate ? '' : 'card-modal__pill--dashed'"
							:aria-expanded="openPicker === 'estimate'"
							@click="togglePicker('estimate')">
							<TimerSandIcon :size="14" />
							{{ currentEstimate ? t('kanso', 'Estimate: {value}', { value: currentEstimate }) : t('kanso', 'Estimate') }}
						</button>
						<div v-if="openPicker === 'estimate'" class="card-modal__popover">
							<div class="card-modal__popover-tokens">
								<button
									v-for="token in estimateTokens"
									:key="token"
									class="card-modal__popover-opt"
									:class="{ 'card-modal__popover-opt--active': currentEstimate === token }"
									:disabled="updateCard.isPending.value"
									@click="handleSetEstimate(token); openPicker = null">
									{{ token }}
								</button>
								<button
									v-if="currentEstimate"
									class="card-modal__popover-opt"
									:disabled="updateCard.isPending.value"
									@click="handleSetEstimate(''); openPicker = null">
									{{ t('kanso', 'None') }}
								</button>
							</div>
							<span v-if="estimateError" class="card-modal__save-error">{{ estimateError }}</span>
						</div>
					</div>

					<!-- Cover colour. Hidden when the board switched cover colours off
					     (#5894); any colour already set stays stored and comes back. -->
					<div v-if="canEdit && cardFeatures.coverColor" class="card-modal__attr">
						<button
							class="card-modal__pill"
							:class="currentCoverColor ? '' : 'card-modal__pill--dashed'"
							:style="currentCoverColor ? { borderColor: cssColor(currentCoverColor), color: cssColor(currentCoverColor) } : {}"
							:aria-expanded="openPicker === 'cover'"
							@click="togglePicker('cover')">
							<PaletteIcon :size="14" />
							{{ t('kanso', 'Cover') }}
						</button>
						<div v-if="openPicker === 'cover'" class="card-modal__popover card-modal__popover--pad">
							<div class="card-modal__cover-swatches">
								<button
									v-for="opt in COVER_COLOR_OPTIONS"
									:key="opt.hex"
									class="card-modal__cover-swatch"
									:class="{ 'card-modal__cover-swatch--active': currentCoverColor === opt.hex }"
									:style="{ background: cssColor(opt.hex) }"
									:title="opt.name"
									:aria-label="opt.name"
									:disabled="updateCard.isPending.value"
									@click="handleSetCoverColor(opt.hex)" />
							</div>
							<button
								v-if="currentCoverColor"
								class="card-modal__popover-opt"
								:disabled="updateCard.isPending.value"
								@click="handleSetCoverColor('')">
								{{ t('kanso', 'No cover') }}
							</button>
							<span v-if="coverColorError" class="card-modal__save-error">{{ coverColorError }}</span>
						</div>
					</div>

					<!-- Assignees -->
					<span
						v-for="uid in cardAssigneeIds"
						:key="uid"
						class="card-modal__assignee-pill">
						<NcAvatar
							:user="uid"
							:display-name="participantName(uid)"
							:size="22"
							:hide-status="true"
							:disable-tooltip="false" />
						<span class="card-modal__assignee-name">{{ participantName(uid) }}</span>
						<button
							class="card-modal__pill-x"
							:title="t('kanso', 'Remove assignee')"
							:disabled="toggleAssignee.isPending.value"
							@click="handleToggleAssignee(uid, false)">
							<CloseIcon :size="12" />
						</button>
					</span>
					<div v-if="unassignedParticipants.length > 0" class="card-modal__attr">
						<button
							class="card-modal__pill card-modal__pill--dashed"
							:aria-expanded="openPicker === 'assign'"
							@click="togglePicker('assign')">
							<AccountPlusIcon :size="14" />
							{{ t('kanso', 'Assign') }}
						</button>
						<div v-if="openPicker === 'assign'" class="card-modal__popover">
							<button
								v-for="p in unassignedParticipants"
								:key="p.uid"
								class="card-modal__assign-option"
								:disabled="toggleAssignee.isPending.value"
								@click="handleToggleAssignee(p.uid, true)">
								<NcAvatar
									:user="p.uid"
									:display-name="p.displayName"
									:size="24"
									:hide-status="true"
									:disable-tooltip="true" />
								<span>{{ p.displayName }}</span>
							</button>
						</div>
					</div>
					<span v-if="assigneeError" class="card-modal__save-error">{{ assigneeError }}</span>

						<!-- Contacts (#3530) - read-only Contacts links, only when the Contacts app is
						     available AND the board still shows contacts (#5894). Existing links stay
						     in the database while hidden. -->
						<template v-if="contactsAvailable && cardFeatures.contacts">
							<span
								v-for="c in cardContacts"
								:key="c.contactUri"
								class="card-modal__assignee-pill">
								<NcAvatar
									:display-name="c.displayName"
									:size="22"
									:hide-status="true"
									:disable-tooltip="false" />
								<span class="card-modal__assignee-name">{{ c.displayName }}</span>
								<button
									class="card-modal__pill-x"
									:title="t('kanso', 'Unlink contact')"
									:disabled="toggleContact.isPending.value"
									@click="handleToggleContact(c, false)">
									<CloseIcon :size="12" />
								</button>
							</span>
							<div class="card-modal__attr">
								<button
									class="card-modal__pill card-modal__pill--dashed"
									:aria-expanded="openPicker === 'contact'"
									@click="toggleContactPicker()">
									<AccountBoxIcon :size="14" />
									{{ t('kanso', 'Link contact') }}
								</button>
								<div v-if="openPicker === 'contact'" class="card-modal__popover">
									<input
										ref="contactSearchInput"
										v-model="contactQuery"
										type="text"
										class="card-modal__contact-search"
										:placeholder="t('kanso', 'Search contacts…')"
										@input="onContactSearch">
									<div
										v-for="c in contactResults"
										:key="c.contactUri"
										class="card-modal__assign-option-wrap">
										<button
											class="card-modal__assign-option"
											:disabled="toggleContact.isPending.value || cardContactUris.has(c.contactUri)"
											@click="handleToggleContact(c, true)">
											<NcAvatar
												:display-name="c.displayName"
												:size="24"
												:hide-status="true"
												:disable-tooltip="true" />
											<span class="card-modal__contact-option-text">
												<span>{{ c.displayName }}</span>
												<span v-if="c.email" class="card-modal__contact-email">{{ c.email }}</span>
											</span>
										</button>
									</div>
									<span
										v-if="!contactSearching && contactResults.length === 0"
										class="card-modal__contact-empty">
										{{ contactQuery ? t('kanso', 'No contacts found.') : t('kanso', 'Type to search your contacts.') }}
									</span>
								</div>
							</div>
							<span v-if="contactError" class="card-modal__save-error">{{ contactError }}</span>
						</template>

					<span class="card-modal__attr-divider" />

					<!-- Labels -->
					<span
						v-for="label in assignedLabels"
						:key="label.id"
						class="card-modal__label-chip"
						:class="{ 'card-modal__label-chip--no-color': !label.color }"
						:style="label.color ? { background: cssColor(label.color), color: readableColor(label.color) } : {}">
						{{ label.title }}
					</span>
					<div class="card-modal__attr">
						<button
							class="card-modal__pill card-modal__pill--dashed card-modal__pill--sm"
							:aria-expanded="openPicker === 'label'"
							@click="togglePicker('label')">
							<PlusIcon :size="12" />
							{{ t('kanso', 'Label') }}
						</button>
						<div v-if="openPicker === 'label'" class="card-modal__popover">
							<div v-if="boardLabels.length === 0" class="card-modal__popover-empty">
								{{ t('kanso', 'No labels on this board yet.') }}
							</div>
							<button
								v-for="label in boardLabels"
								:key="label.id"
								class="card-modal__label-toggle"
								:class="{
									'card-modal__label-toggle--active': cardLabelIds.has(label.id),
									'card-modal__label-toggle--no-color': !label.color,
								}"
								:style="label.color ? { '--label-color': cssColor(label.color) } : {}"
								:aria-pressed="cardLabelIds.has(label.id)"
								:disabled="toggleLabel.isPending.value"
								@click="handleToggleLabel(label)">
								{{ label.title }}
							</button>
							<span v-if="labelToggleError" class="card-modal__save-error">{{ labelToggleError }}</span>

							<!-- Inline create (label creation is board management → MANAGE-gated) -->
							<div v-if="canManage" class="card-modal__label-create">
								<button
									type="button"
									class="card-modal__label-swatch"
									:style="newLabelColor ? { background: cssColor(newLabelColor) } : {}"
									:class="{ 'card-modal__label-swatch--no-color': !newLabelColor }"
									:title="t('kanso', 'Pick color')"
									:aria-label="t('kanso', 'Pick color for new label')"
									@click="showNewLabelColor = !showNewLabelColor">
									<span v-if="!newLabelColor">+</span>
								</button>
								<div v-if="showNewLabelColor" class="card-modal__label-color-grid">
									<button
										v-for="preset in LABEL_COLOR_PRESETS"
										:key="preset"
										type="button"
										class="card-modal__label-color-option"
										:style="{ background: cssColor(preset) }"
										:class="{ 'card-modal__label-color-option--active': newLabelColor === preset }"
										:title="preset"
										:aria-pressed="newLabelColor === preset"
										@click="newLabelColor = preset; showNewLabelColor = false" />
									<button
										type="button"
										class="card-modal__label-color-option card-modal__label-color-option--clear"
										:title="t('kanso', 'No color')"
										:aria-pressed="!newLabelColor"
										@click="newLabelColor = ''; showNewLabelColor = false">×</button>
								</div>
								<input
									v-model="newLabelTitle"
									class="card-modal__label-create-input"
									type="text"
									:placeholder="t('kanso', 'New label…')"
									:disabled="isCreatingLabel"
									:aria-label="t('kanso', 'New label name')"
									@keydown.enter.prevent="submitCreateLabel" />
								<button
									class="card-modal__label-create-btn"
									type="button"
									:disabled="isCreatingLabel || !newLabelTitle.trim()"
									@click="submitCreateLabel">
									{{ t('kanso', 'Create') }}
								</button>
							</div>
							<span v-if="createLabelError" class="card-modal__save-error">{{ createLabelError }}</span>
						</div>
					</div>

					<!-- Projects membership -->
					<span class="card-modal__attr-divider" />

					<div class="card-modal__attr">
						<button
							class="card-modal__pill card-modal__pill--dashed card-modal__pill--sm"
							:aria-expanded="openPicker === 'project'"
							@click="togglePicker('project')">
							<FolderMultipleOutlineIcon :size="12" />
							{{ cardProjectIds.size > 0
								? t('kanso', '{n} project', { n: cardProjectIds.size })
								: t('kanso', 'Project') }}
						</button>
						<div v-if="openPicker === 'project'" class="card-modal__popover">
							<div v-if="allProjects.length === 0" class="card-modal__popover-empty">
								{{ t('kanso', 'No projects yet.') }}
							</div>
							<button
								v-for="project in allProjects"
								:key="project.id"
								class="card-modal__label-toggle"
								:class="{ 'card-modal__label-toggle--active': cardProjectIds.has(project.id) }"
								:aria-pressed="cardProjectIds.has(project.id)"
								:disabled="projectTogglePending"
								@click="handleToggleProject(project.id)">
								<span
									class="card-modal__project-dot"
									:style="project.color ? { background: '#' + project.color } : {}" />
								{{ project.title }}
							</button>
							<span v-if="projectToggleError" class="card-modal__save-error">{{ projectToggleError }}</span>
						</div>
					</div>

					<!-- Reviews (pushed right) -->
					<div class="card-modal__attr-right">
						<span class="card-modal__attr-eyebrow">{{ t('kanso', 'Review') }}</span>
						<span
							v-for="review in cardReviews"
							:key="review.id"
							class="card-modal__review-pill"
							:class="[`card-modal__review-pill--${review.state}`, {
								'card-modal__review-pill--compact': reviewsCompact,
								'card-modal__review-pill--gated': review.gated,
							}]"
							:title="review.gated ? reviewGateTooltip(review) : null">
							<NcAvatar
								:user="review.reviewer"
								:display-name="participantName(review.reviewer)"
								:size="22"
								:hide-status="true"
								:disable-tooltip="false" />
							<span class="card-modal__review-name">{{ participantName(review.reviewer) }}</span>
							<span
								v-if="reviewTypeById(review.reviewTypeId)"
								class="card-modal__review-type-badge"
								:style="reviewTypeById(review.reviewTypeId).color
									? { background: cssColor(reviewTypeById(review.reviewTypeId).color), color: readableColor(reviewTypeById(review.reviewTypeId).color) }
									: {}">
								{{ reviewTypeById(review.reviewTypeId).title }}
							</span>
							<span
								class="card-modal__review-state"
								:class="review.gated ? 'card-modal__review-state--gated' : `card-modal__review-state--${review.state}`">
								<LockOutlineIcon v-if="review.gated" :size="12" />
								<CheckDecagramIcon v-else-if="review.state === 'approved'" :size="12" />
								<AlertDecagramIcon v-else-if="review.state === 'changes_requested'" :size="12" />
								<CheckDecagramOutlineIcon v-else :size="12" />
								<span class="card-modal__review-state-label">{{ review.gated ? t('kanso', 'Waiting') : reviewStateLabel(review.state) }}</span>
							</span>
							<button
								v-if="canEdit"
								class="card-modal__pill-x"
								:title="t('kanso', 'Withdraw review request')"
								:disabled="withdrawReview.isPending.value"
								@click="handleWithdrawReview(review.id)">
								<CloseIcon :size="12" />
							</button>
						</span>
						<div v-if="canEdit && unrequestedParticipants.length > 0" class="card-modal__attr">
							<button
								class="card-modal__pill card-modal__pill--dashed card-modal__pill--sm"
								:aria-expanded="openPicker === 'review'"
								@click="togglePicker('review')">
								<PlusIcon :size="12" />
								{{ t('kanso', 'Request') }}
							</button>
							<div v-if="openPicker === 'review'" class="card-modal__popover card-modal__popover--right">
								<div v-if="boardReviewTypes.length > 0" class="card-modal__review-type-selector">
									<span class="card-modal__field-label">{{ t('kanso', 'Type') }}</span>
									<button
										class="card-modal__review-type-option"
										:class="{ 'card-modal__review-type-option--active': selectedReviewTypeId === null }"
										@click.stop="selectedReviewTypeId = null">
										{{ t('kanso', 'Review') }}
									</button>
									<button
										v-for="rt in boardReviewTypes"
										:key="rt.id"
										class="card-modal__review-type-option"
										:class="{ 'card-modal__review-type-option--active': selectedReviewTypeId === rt.id }"
										:style="rt.color && selectedReviewTypeId === rt.id
											? { background: cssColor(rt.color), color: readableColor(rt.color), borderColor: cssColor(rt.color) }
											: rt.color
												? { borderColor: cssColor(rt.color), color: cssColor(rt.color) }
												: {}"
										@click.stop="selectedReviewTypeId = rt.id">
										{{ rt.title }}
									</button>
								</div>
								<button
									v-for="p in unrequestedParticipants"
									:key="p.uid"
									class="card-modal__assign-option"
									:disabled="requestReview.isPending.value"
									@click="handleRequestReview(p.uid)">
									<NcAvatar
										:user="p.uid"
										:display-name="p.displayName"
										:size="24"
										:hide-status="true"
										:disable-tooltip="true" />
									<span>{{ p.displayName }}</span>
								</button>
							</div>
						</div>
					</div>
				</div>

				<!-- Mobile tab bar - visible only on narrow viewports, sits under the attribute bar -->
				<div class="card-modal__tabbar" role="tablist">
					<button
						class="card-modal__tab"
						:class="{ 'card-modal__tab--active': viewMode === 'card' }"
						role="tab"
						:aria-selected="viewMode === 'card'"
						@click="viewMode = 'card'">
						{{ t('kanso', 'Card') }}
					</button>
					<button
						class="card-modal__tab"
						:class="{ 'card-modal__tab--active': viewMode === 'discussion' }"
						role="tab"
						:aria-selected="viewMode === 'discussion'"
						@click="viewMode = 'discussion'">
						{{ t('kanso', 'Discussion') }}<span v-if="commentCount > 0" class="card-modal__tab-count">{{ commentCount }}</span>
					</button>
				</div>

				<!-- Body: content (left) | discussion (right) -->
				<div ref="bodyRef" class="card-modal__body" :style="discussionWidthStyle">
					<!-- LEFT: description · checklist · sub-cards · github · relations -->
					<div class="card-modal__content">
						<!-- Description -->
						<section class="card-modal__section">
							<div class="card-modal__section-head">
								<span class="card-modal__eyebrow">{{ t('kanso', 'Description') }}</span>
								<button
									v-if="!editingDescription && cardData.description"
									class="card-modal__ghost-btn"
									@click="startDescriptionEdit">
									<PencilIcon :size="14" />
									{{ t('kanso', 'Edit') }}
								</button>
							</div>

							<template v-if="editingDescription">
								<Suspense>
									<MarkdownEditor
										v-model="draftDescription"
										:placeholder="t('kanso', 'Add a description…')"
										:disabled="isSaving"
										:autofocus="true"
										min-height="160px"
										:participants="participants.data.value ?? []"
										:upload-image="(file) => uploadAttachment.mutateAsync(file)"
										:inline-url="(id) => cardAttachmentInlineUrl(props.cardId, id)"
										:show-toolbar="!editorToolbarHidden"
										@submit="saveDescription"
										@escape="cancelDescriptionEdit"
										@image-error="(msg) => { descPasteError = msg || t('kanso', 'Failed to upload image.') }" />
									<template #fallback>
										<div class="card-modal__desc-textarea card-modal__editor-loading" />
									</template>
								</Suspense>
								<div class="card-modal__desc-actions">
									<NcButton type="primary" :disabled="isSaving" @click="saveDescription">
										{{ t('kanso', 'Save') }}
									</NcButton>
									<NcButton @click="cancelDescriptionEdit">
										{{ t('kanso', 'Cancel') }}
									</NcButton>
									<span class="card-modal__hint">{{ t('kanso', 'Esc cancel · Ctrl+Enter save') }}</span>
									<span v-if="uploadAttachment.isPending.value" class="card-modal__hint">{{ t('kanso', 'Uploading image…') }}</span>
									<span v-if="saveError" class="card-modal__save-error">{{ saveError }}</span>
									<span v-if="descPasteError" class="card-modal__save-error">{{ descPasteError }}</span>
								</div>

								<!-- Save conflict (#9845): somebody else changed the description
								     while this editor was open. NOTHING is thrown away - the
								     draft stays in the editor above, their text is shown here in
								     full, and the user picks which one wins. -->
								<div v-if="descriptionConflict" class="card-modal__desc-conflict">
									<p class="card-modal__desc-conflict-msg">
										{{ t('kanso', 'Someone else changed this description while you were editing. Your text is kept in the editor above — their version is shown below.') }}
									</p>
									<pre class="card-modal__desc-conflict-theirs">{{ descriptionConflict.description || t('kanso', '(empty)') }}</pre>
									<div class="card-modal__desc-conflict-actions">
										<NcButton type="primary" :disabled="isSaving" @click="overwriteDescription">
											{{ t('kanso', 'Keep my version') }}
										</NcButton>
										<NcButton :disabled="isSaving" @click="useTheirDescription">
											{{ t('kanso', 'Discard mine, use theirs') }}
										</NcButton>
									</div>
								</div>
							</template>

							<template v-else>
								<div
									v-if="cardData.description"
									class="card-modal__desc-view"
									@click="startDescriptionEdit">
									<!-- eslint-disable-next-line vue/no-v-html — renderMarkdown sanitises via DOMPurify -->
									<div class="card-modal__desc-rendered" v-html="renderedDescription" @click="handleRefClick" />
								</div>
								<button
									v-else
									class="card-modal__desc-placeholder"
									@click="startDescriptionEdit">
									{{ t('kanso', 'Add a description…') }}
								</button>
							</template>
						</section>

						<!-- Checklist - promoted next to the description -->
						<section v-if="checklistTotal > 0 || canEdit" class="card-modal__checklist">
							<div class="card-modal__checklist-head">
								<CheckboxMarkedOutlineIcon :size="16" class="card-modal__checklist-head-icon" />
								<span class="card-modal__checklist-title">{{ t('kanso', 'Checklist') }}</span>
								<span v-if="checklistTotal > 0" class="card-modal__checklist-count">{{ checklistDone }} / {{ checklistTotal }}</span>
								<div v-if="checklistTotal > 0" class="card-modal__checklist-bar">
									<div
										class="card-modal__checklist-bar-fill"
										:class="{ 'card-modal__checklist-bar-fill--complete': checklistDone === checklistTotal }"
										:style="{ width: checklistProgressPct + '%' }" />
								</div>
							</div>

							<ul v-if="checklistTotal > 0" class="card-modal__checklist-list">
								<li
									v-for="item in checklistItems"
									:key="item.id"
									class="card-modal__checklist-item"
									:class="{ 'card-modal__checklist-item--done': item.done }"
									:data-item-id="item.id"
									:data-drag-over="dragOverItemId === item.id ? 'true' : 'false'"
									@dragover.prevent="onItemDragOver($event, item)"
									@dragleave="onItemDragLeave($event, item)"
									@drop.prevent="onItemDrop($event, item)">
									<span
										class="card-modal__checklist-drag"
										:draggable="true"
										:title="t('kanso', 'Drag to reorder')"
										@dragstart="onItemDragStart($event, item)"
										@dragend="onItemDragEnd">
										<DragIcon :size="16" />
									</span>
									<input
										type="checkbox"
										class="card-modal__checklist-checkbox"
										:checked="item.done"
										:disabled="toggleItem.isPending.value"
										:aria-label="t('kanso', 'Toggle item done')"
										@change="handleToggleItem(item)">
									<input
										v-if="editingItemId === item.id"
										:ref="(el) => setItemInputRef(item.id, el)"
										v-model="editingItemTitle"
										class="card-modal__checklist-item-input"
										type="text"
										@keydown.enter.prevent="saveItemTitle(item)"
										@keydown.escape.stop="cancelItemEdit"
										@blur="saveItemTitle(item)">
									<span
										v-else
										class="card-modal__checklist-item-title"
										:class="{ 'card-modal__checklist-item-title--done': item.done }"
										role="button"
										tabindex="0"
										:aria-label="t('kanso', 'Edit item')"
										@click="startItemEdit(item)"
										@keydown.enter.prevent="startItemEdit(item)"
										@keydown.space.prevent="startItemEdit(item)">
										{{ item.title }}
									</span>
									<!-- Rich step meta (#3745): due chip + assignee avatar + pickers -->
									<span
										v-if="item.dueDate"
										class="card-modal__step-due"
										:class="stepDueClass(item)"
										:data-step-due="item.id"
										:role="canEdit ? 'button' : undefined"
										:tabindex="canEdit ? 0 : undefined"
										:title="t('kanso', 'Step due date')"
										@click="canEdit && toggleStepMenu(item, 'due')"
										@keydown.enter.prevent="canEdit && toggleStepMenu(item, 'due')">
										<CalendarIcon :size="12" />
										{{ formatStepDue(item.dueDate) }}
									</span>
									<span
										v-if="item.assignedUser"
										class="card-modal__step-assignee"
										:data-step-assignee="item.assignedUser"
										:role="canEdit ? 'button' : undefined"
										:tabindex="canEdit ? 0 : undefined"
										:title="participantName(item.assignedUser)"
										@click="canEdit && toggleStepMenu(item, 'assign')"
										@keydown.enter.prevent="canEdit && toggleStepMenu(item, 'assign')">
										<NcAvatar
											:user="item.assignedUser"
											:display-name="participantName(item.assignedUser)"
											:size="20"
											:hide-status="true"
											:disable-tooltip="true" />
									</span>
									<span v-if="canEdit" class="card-modal__step-actions">
										<button
											v-if="!item.assignedUser"
											class="card-modal__step-btn"
											:title="t('kanso', 'Assign step')"
											:aria-expanded="isStepMenuOpen(item, 'assign')"
											@click="toggleStepMenu(item, 'assign')">
											<AccountPlusIcon :size="14" />
										</button>
										<button
											v-if="!item.dueDate"
											class="card-modal__step-btn"
											:title="t('kanso', 'Set step due date')"
											:aria-expanded="isStepMenuOpen(item, 'due')"
											@click="toggleStepMenu(item, 'due')">
											<CalendarIcon :size="14" />
										</button>
									</span>
									<button
										class="card-modal__checklist-item-delete"
										:title="t('kanso', 'Delete item')"
										:disabled="deleteItem.isPending.value"
										@click="handleDeleteItem(item)">
										<CloseIcon :size="14" />
									</button>
									<div v-if="isStepMenuOpen(item, 'assign')" class="card-modal__popover card-modal__step-popover">
										<button
											v-if="item.assignedUser"
											class="card-modal__assign-option"
											:disabled="unassignItem.isPending.value"
											@click="handleUnassignStep(item)">
											<CloseIcon :size="16" />
											<span>{{ t('kanso', 'Remove assignee') }}</span>
										</button>
										<button
											v-for="p in stepAssignCandidates(item)"
											:key="p.uid"
											class="card-modal__assign-option"
											:disabled="assignItem.isPending.value"
											@click="handleAssignStep(item, p.uid)">
											<NcAvatar
												:user="p.uid"
												:display-name="p.displayName"
												:size="24"
												:hide-status="true"
												:disable-tooltip="true" />
											<span>{{ p.displayName }}</span>
										</button>
									</div>
									<div v-if="isStepMenuOpen(item, 'due')" class="card-modal__popover card-modal__popover--pad card-modal__step-popover">
										<label class="card-modal__field-label">{{ t('kanso', 'Step due date') }}</label>
										<div class="card-modal__field-row">
											<input
												class="card-modal__date-input"
												type="datetime-local"
												:value="stepDueInputValue(item)"
												@change="handleStepDueChange(item, $event)">
											<button
												v-if="item.dueDate"
												class="card-modal__field-clear"
												:title="t('kanso', 'Clear step due date')"
												@click="clearStepDue(item)">
												<CloseIcon :size="14" />
											</button>
										</div>
									</div>
								</li>
							</ul>

							<div v-if="canEdit" class="card-modal__checklist-add">
								<CheckboxBlankOutlineIcon :size="16" class="card-modal__checklist-add-icon" />
								<input
									ref="addItemInputRef"
									v-model="newItemTitle"
									class="card-modal__checklist-add-input"
									type="text"
									:placeholder="t('kanso', 'Add an item…')"
									:disabled="addItem.isPending.value"
									@keydown.enter.prevent="handleAddItem">
							</div>
							<span v-if="checklistError" class="card-modal__save-error">{{ checklistError }}</span>
						</section>

						<!-- Hierarchy: parent link OR sub-cards -->
						<section v-if="cardData.parentCardId" class="card-modal__section">
							<div class="card-modal__section-head">
								<SitemapIcon :size="16" class="card-modal__eyebrow-icon" />
								<span class="card-modal__eyebrow">{{ t('kanso', 'Parent card') }}</span>
							</div>
							<div class="card-modal__parent-row">
								<button class="card-modal__parent-link" @click="openCard(cardData.parentCardId)">
									{{ parentTitle }}
								</button>
								<button
									class="card-modal__ghost-btn"
									:title="t('kanso', 'Detach from parent')"
									:disabled="setParentMutation.isPending.value"
									@click="handleClearParent">
									<LinkOffIcon :size="14" />
									{{ t('kanso', 'Detach') }}
								</button>
							</div>
							<span v-if="hierarchyError" class="card-modal__save-error">{{ hierarchyError }}</span>
						</section>

						<section v-else class="card-modal__section card-modal__section--tight">
							<div class="card-modal__section-inline">
								<SitemapIcon :size="16" class="card-modal__eyebrow-icon" />
								<span class="card-modal__eyebrow">{{ t('kanso', 'Sub-cards') }}</span>
								<span v-if="children.length > 0" class="card-modal__section-count">{{ childrenDone }} / {{ children.length }}</span>
								<input
									v-if="canEdit"
									ref="addChildInputRef"
									v-model="newChildTitle"
									class="card-modal__dashed-input"
									type="text"
									:placeholder="t('kanso', 'Add a sub-card…')"
									:disabled="addChildMutation.isPending.value"
									@keydown.enter.prevent="handleAddChild">
							</div>
							<div v-if="children.length > 0" class="card-modal__children-grid">
								<div
									v-for="child in children"
									:key="child.id"
									class="card-modal__child"
									:class="{ 'card-modal__child--done': Number(child.doneAt) > 0 }">
									<span
										class="card-modal__child-dot"
										:class="{ 'card-modal__child-dot--done': Number(child.doneAt) > 0 }" />
									<button
										class="card-modal__child-link"
										:class="{ 'card-modal__child-link--done': Number(child.doneAt) > 0 }"
										@click="openCard(child.id)">
										{{ child.title }}
									</button>
									<button
										class="card-modal__child-remove"
										:title="t('kanso', 'Detach sub-card')"
										:disabled="setParentMutation.isPending.value"
										@click="handleDetachChild(child)">
										<CloseIcon :size="12" />
									</button>
								</div>
							</div>

							<!-- Inline editors, revealed from the ⋯ menu -->
							<div v-if="showLinkChild && canEdit" class="card-modal__relation-add">
								<select v-model="linkChildTargetId" class="card-modal__relation-target">
									<option value="">{{ t('kanso', 'Pick a card to link…') }}</option>
									<option v-for="c in availableChildCards" :key="c.id" :value="c.id">{{ c.title }}</option>
								</select>
								<button
									class="card-modal__relation-add-btn"
									:disabled="!linkChildTargetId || setParentMutation.isPending.value"
									@click="confirmLinkChild">
									{{ t('kanso', 'Link') }}
								</button>
								<button class="card-modal__relation-add-btn" @click="showLinkChild = false">
									{{ t('kanso', 'Cancel') }}
								</button>
							</div>
							<div v-if="showSetParent && canEdit && children.length === 0" class="card-modal__relation-add">
								<select v-model="setParentTargetId" class="card-modal__relation-target">
									<option value="">{{ t('kanso', 'Pick a parent card…') }}</option>
									<option v-for="c in availableParentCards" :key="c.id" :value="c.id">{{ c.title }}</option>
								</select>
								<button
									class="card-modal__relation-add-btn"
									:disabled="!setParentTargetId || setParentMutation.isPending.value"
									@click="confirmSetParent">
									{{ t('kanso', 'Set parent') }}
								</button>
								<button class="card-modal__relation-add-btn" @click="showSetParent = false">
									{{ t('kanso', 'Cancel') }}
								</button>
							</div>
							<span v-if="hierarchyError" class="card-modal__save-error">{{ hierarchyError }}</span>
						</section>

						<!-- GitHub links. Hidden when the board switched GitHub off (#5894);
						     linked issues/PRs are kept and reappear when it is switched on. -->
						<section v-if="cardFeatures.github" class="card-modal__section card-modal__section--tight">
							<div class="card-modal__section-inline">
								<GithubIcon :size="16" class="card-modal__eyebrow-icon" />
								<span class="card-modal__eyebrow">{{ t('kanso', 'GitHub') }}</span>
								<form v-if="canEdit" class="card-modal__inline-form" @submit.prevent="handleAddLink">
									<input
										v-model="newLinkUrl"
										type="url"
										class="card-modal__dashed-input"
										:placeholder="t('kanso', 'Paste a GitHub PR or issue URL…')">
									<NcButton
										type="secondary"
										native-type="submit"
										:disabled="!newLinkUrl || addLink.isPending.value">
										{{ t('kanso', 'Attach') }}
									</NcButton>
								</form>
								<button
									class="card-modal__ghost-btn"
									:title="t('kanso', 'Copy the git branch name for this card')"
									@click="copyBranchName">
									<ContentCopyIcon :size="14" />
									{{ branchCopied ? t('kanso', 'Copied!') : t('kanso', 'Branch') }}
								</button>
							</div>
							<ul v-if="cardLinks.length > 0" class="card-modal__links-list">
								<li v-for="link in cardLinks" :key="link.id" class="card-modal__link-row">
									<a :href="link.url" target="_blank" rel="noopener noreferrer" class="card-modal__link">
										<span class="card-modal__link-badge" :class="`card-modal__link-badge--${link.state}`">
											{{ linkStateLabel(link.state) }}
										</span>
										<span class="card-modal__link-text">{{ link.title || link.url }}</span>
										<OpenInNewIcon :size="14" class="card-modal__link-ext" />
									</a>
									<button
										v-if="canEdit"
										class="card-modal__child-remove"
										:title="t('kanso', 'Remove link')"
										@click="handleRemoveLink(link.id)">
										<CloseIcon :size="14" />
									</button>
								</li>
							</ul>
							<span v-if="linkError" class="card-modal__save-error">{{ linkError }}</span>
						</section>

						<!-- File attachments (#3526). Hidden when the board switched attachments
						     off (#5894); nothing is deleted - every file is still listed here the
						     moment it is switched back on. -->
						<section v-if="cardFeatures.attachments" class="card-modal__section card-modal__section--tight">
							<div class="card-modal__section-inline">
								<PaperclipIcon :size="16" class="card-modal__eyebrow-icon" />
								<span class="card-modal__eyebrow">{{ t('kanso', 'Attachments') }}</span>
								<template v-if="canEdit">
									<input
										ref="attachmentInput"
										type="file"
										class="card-modal__file-input"
										@change="handleAttachmentPick">
									<NcButton
										type="secondary"
										:disabled="uploadAttachment.isPending.value"
										@click="triggerAttachmentPick">
										{{ uploadAttachment.isPending.value ? t('kanso', 'Uploading…') : t('kanso', 'Upload') }}
									</NcButton>
								</template>
							</div>
							<ul v-if="cardAttachments.length > 0" class="card-modal__links-list">
								<li v-for="att in cardAttachments" :key="att.id" class="card-modal__link-row">
									<a :href="attachmentHref(att.id)" class="card-modal__link" download>
										<PaperclipIcon :size="14" class="card-modal__eyebrow-icon" />
										<span class="card-modal__link-text">{{ att.filename }}</span>
										<span class="card-modal__attachment-size">{{ formatBytes(att.size) }}</span>
									</a>
									<button
										v-if="canEdit"
										class="card-modal__child-remove"
										:title="t('kanso', 'Remove attachment')"
										@click="handleRemoveAttachment(att.id)">
										<CloseIcon :size="14" />
									</button>
								</li>
							</ul>
							<span v-if="attachmentError" class="card-modal__save-error">{{ attachmentError }}</span>
						</section>

						<!-- Time tracking (#3536): manual entries + per-card total. Hidden when
						     the board switched time tracking off (#5894). Entries are kept, and a
						     timer that is already running keeps running - it is just not shown. -->
						<section v-if="cardFeatures.timeTracking" class="card-modal__section card-modal__section--tight">
							<div class="card-modal__section-inline">
								<ClockOutlineIcon :size="16" class="card-modal__eyebrow-icon" />
								<span class="card-modal__eyebrow">{{ t('kanso', 'Time tracking') }}</span>
								<span v-if="timeSpentTotal > 0" class="card-modal__attachment-size">{{ formatDuration(timeSpentTotal) }}</span>
							</div>

							<!-- Live running timer row (#73): shown only while cardData.runningTimer
							     is set. The elapsed counter ticks every second via the timerNow ref. -->
							<div v-if="cardData?.runningTimer" class="card-modal__timer-running-row">
								<TimerOutlineIcon :size="14" class="card-modal__timer-running-icon" />
								<span class="card-modal__timer-running-label">{{ t('kanso', 'Timer running') }}</span>
								<span class="card-modal__timer-running-elapsed">{{ formatDuration(timerElapsed) }}</span>
							</div>

							<form v-if="canEdit" class="card-modal__time-add" @submit.prevent="handleAddTimeEntry">
								<input
									v-model="timeDurationInput"
									type="text"
									class="card-modal__time-duration"
									:placeholder="t('kanso', 'e.g. 1h 30m')"
									:disabled="addEntry.isPending.value">
								<input
									v-model="timeNoteInput"
									type="text"
									class="card-modal__time-note"
									:placeholder="t('kanso', 'Note (optional)')"
									:disabled="addEntry.isPending.value">
								<NcButton
									type="secondary"
									native-type="submit"
									:disabled="addEntry.isPending.value">
									{{ addEntry.isPending.value ? t('kanso', 'Adding…') : t('kanso', 'Add time') }}
								</NcButton>
							</form>

							<ul v-if="cardTimeEntries.length > 0" class="card-modal__links-list">
								<li v-for="entry in cardTimeEntries" :key="entry.id" class="card-modal__link-row">
									<div class="card-modal__time-entry">
										<span class="card-modal__time-entry-duration">{{ formatDuration(entry.seconds) }}</span>
										<span v-if="entry.note" class="card-modal__time-entry-note">{{ entry.note }}</span>
										<span class="card-modal__time-entry-meta">
											<NcAvatar
												:user="entry.createdBy || ''"
												:display-name="entry.createdBy || ''"
												:size="16"
												:disable-menu="true" />
											<span class="card-modal__activity-time">{{ relativeTime(entry.createdAt) }}</span>
										</span>
									</div>
									<button
										v-if="canEdit"
										class="card-modal__child-remove"
										:title="t('kanso', 'Remove time entry')"
										@click="handleRemoveTimeEntry(entry.id)">
										<CloseIcon :size="14" />
									</button>
								</li>
							</ul>
							<span v-if="timeEntryError" class="card-modal__save-error">{{ timeEntryError }}</span>
						</section>

						<!-- Custom fields (#3537): shown only when the board has fields -->
						<section
							v-if="boardCardFields.length > 0"
							class="card-modal__section card-modal__section--tight"
							data-test="card-custom-fields">
							<div class="card-modal__section-inline">
								<TableColumnIcon :size="16" class="card-modal__eyebrow-icon" />
								<span class="card-modal__eyebrow">{{ t('kanso', 'Custom fields') }}</span>
							</div>
							<ul class="card-modal__cf-list">
								<li
									v-for="field in boardCardFields"
									:key="field.id"
									class="card-modal__cf-row">
									<label
										:for="`card-cf-${field.id}`"
										class="card-modal__cf-label">
										{{ field.name }}
									</label>
									<!-- text -->
									<input
										v-if="field.type === 'text'"
										:id="`card-cf-${field.id}`"
										class="card-modal__cf-input"
										type="text"
										:value="(cardData.fieldValues ?? []).find(fv => fv.fieldId === field.id)?.value ?? ''"
										:disabled="!canEdit"
										:aria-label="field.name"
										:data-test="`cf-input-${field.id}`"
										@change="handleFieldChange(field, $event.target.value)"
										@blur="handleFieldChange(field, $event.target.value)">
									<!-- number -->
									<input
										v-else-if="field.type === 'number'"
										:id="`card-cf-${field.id}`"
										class="card-modal__cf-input"
										type="number"
										:value="(cardData.fieldValues ?? []).find(fv => fv.fieldId === field.id)?.value ?? ''"
										:disabled="!canEdit"
										:aria-label="field.name"
										:data-test="`cf-input-${field.id}`"
										@change="handleFieldChange(field, $event.target.value)"
										@blur="handleFieldChange(field, $event.target.value)">
									<!-- date -->
									<input
										v-else-if="field.type === 'date'"
										:id="`card-cf-${field.id}`"
										class="card-modal__cf-input"
										type="date"
										:value="(cardData.fieldValues ?? []).find(fv => fv.fieldId === field.id)?.value ?? ''"
										:disabled="!canEdit"
										:aria-label="field.name"
										:data-test="`cf-input-${field.id}`"
										@change="handleFieldChange(field, $event.target.value)">
									<!-- select -->
									<select
										v-else-if="field.type === 'select'"
										:id="`card-cf-${field.id}`"
										class="card-modal__cf-select"
										:value="(cardData.fieldValues ?? []).find(fv => fv.fieldId === field.id)?.value ?? ''"
										:disabled="!canEdit"
										:aria-label="field.name"
										:data-test="`cf-select-${field.id}`"
										@change="handleFieldChange(field, $event.target.value)">
										<option value="">{{ t('kanso', '— none —') }}</option>
										<option
											v-for="opt in (field.options ?? [])"
											:key="opt"
											:value="opt">
											{{ opt }}
										</option>
									</select>
									<span v-if="cardFieldErrors[field.id]" class="card-modal__save-error">
										{{ cardFieldErrors[field.id] }}
									</span>
								</li>
							</ul>
						</section>

						<!-- Card visibility (#3743): who on the board may see this card.
						     Editable by the card creator or a board manager only; everyone
						     else sees the current level read-only. -->
						<section
							v-if="canEdit"
							class="card-modal__section card-modal__section--tight"
							data-test="card-visibility">
							<div class="card-modal__section-inline">
								<LockOutlineIcon :size="16" class="card-modal__eyebrow-icon" />
								<span class="card-modal__eyebrow">{{ t('kanso', 'Visibility') }}</span>
								<select
									class="card-modal__visibility-select"
									:value="cardData.visibility ?? 'public'"
									:disabled="!canSetVisibility"
									data-test="card-visibility-select"
									:aria-label="t('kanso', 'Card visibility')"
									@change="handleVisibilityChange($event.target.value)">
									<option value="public">{{ t('kanso', 'Public — everyone on the board') }}</option>
									<option value="internal">{{ t('kanso', 'Internal — only your side of the board') }}</option>
									<option value="private">{{ t('kanso', 'Private — only you') }}</option>
								</select>
							</div>
							<span v-if="visibilityError" class="card-modal__save-error">{{ visibilityError }}</span>
						</section>

						<!-- Relations - shown only when the card has relations, or the
						     editor was opened from the ⋯ menu -->
						<section
							v-if="hasAnyRelation || showRelationEditor"
							class="card-modal__section">
							<div class="card-modal__section-head">
								<span class="card-modal__eyebrow">{{ t('kanso', 'Relations') }}</span>
							</div>

							<template v-for="group in relationGroups" :key="group.key">
								<template v-if="group.items.length > 0">
									<span class="card-modal__relation-label">{{ group.label }}</span>
									<ul class="card-modal__relation-group">
										<li v-for="rel in group.items" :key="rel.id" class="card-modal__relation-row">
											<!-- A counterpart hidden from this viewer (#3743): keep the
											     row (the relation is real and removable) but never its
											     title - render a non-clickable placeholder instead. -->
											<span
												v-if="rel.hidden"
												class="card-modal__relation-title card-modal__relation-title--hidden"
												:title="t('kanso', 'This card is not visible to you')">
												<LockOutlineIcon :size="12" />
												{{ t('kanso', 'Hidden card') }}
											</span>
											<button
												v-else
												type="button"
												class="card-modal__relation-title"
												:class="{ 'card-modal__relation-title--done': rel.done }"
												@click="openCard(rel.cardId)">
												{{ rel.title }}
											</button>
											<button
												v-if="canEdit"
												class="card-modal__child-remove"
												:title="t('kanso', 'Remove relation')"
												:disabled="removeRelation.isPending.value"
												@click="handleRemoveRelation(rel.id)">
												<CloseIcon :size="12" />
											</button>
										</li>
									</ul>
								</template>
							</template>

							<div v-if="canEdit && showRelationEditor" class="card-modal__relation-add">
								<select v-model="newRelationKind" class="card-modal__relation-kind">
									<option value="blocks">{{ t('kanso', 'Blocks') }}</option>
									<option value="blocked_by">{{ t('kanso', 'Blocked by') }}</option>
									<option value="duplicates">{{ t('kanso', 'Duplicates') }}</option>
									<option value="relates">{{ t('kanso', 'Relates to') }}</option>
								</select>
								<select v-model="newRelationTargetId" class="card-modal__relation-target">
									<option value="">{{ t('kanso', 'Pick a card…') }}</option>
									<option v-for="c in boardCardsForRelation" :key="c.id" :value="c.id">{{ c.title }}</option>
								</select>
								<button
									class="card-modal__relation-add-btn"
									:disabled="!newRelationTargetId || addRelation.isPending.value"
									@click="handleAddRelation">
									{{ t('kanso', 'Add') }}
								</button>
								<button class="card-modal__relation-add-btn" @click="showRelationEditor = false">
									{{ t('kanso', 'Done') }}
								</button>
							</div>
							<span v-if="relationError" class="card-modal__save-error">{{ relationError }}</span>
						</section>
					</div>

					<!-- Drag handle: resizes the split between the main pane and the
					     discussion pane. Inert below the 680px breakpoint (panes stack). -->
					<div
						class="card-modal__resizer"
						role="separator"
						aria-orientation="vertical"
						:aria-label="t('kanso', 'Resize discussion panel')"
						:aria-valuenow="discussionWidth"
						:aria-valuemin="DISCUSSION_MIN_WIDTH"
						:aria-valuemax="DISCUSSION_MAX_WIDTH"
						tabindex="0"
						@pointerdown="onResizePointerDown"
						@keydown="onResizeKeydown">
						<span class="card-modal__resizer-grip" />
					</div>

					<!-- RIGHT: discussion pane. Collapsed via CSS (never v-if) so the
					     comment query keeps feeding the header badge and the mobile
					     Discussion tab stays intact. -->
					<aside :id="discussionPaneId" class="card-modal__discussion">
						<div class="card-modal__discussion-head card-modal__discussion-tabs" role="tablist">
							<button
								class="card-modal__discussion-tab"
								:class="{ 'card-modal__discussion-tab--active': discussionTab === 'discussion' }"
								role="tab"
								:aria-selected="discussionTab === 'discussion'"
								@click="discussionTab = 'discussion'">
								<CommentMultipleOutlineIcon :size="16" />
								{{ t('kanso', 'Discussion') }}
								<span v-if="commentCount > 0" class="card-modal__discussion-count">{{ commentCount }}</span>
							</button>
							<button
								class="card-modal__discussion-tab"
								:class="{ 'card-modal__discussion-tab--active': discussionTab === 'activity' }"
								role="tab"
								:aria-selected="discussionTab === 'activity'"
								@click="discussionTab = 'activity'">
								<HistoryIcon :size="16" />
								{{ t('kanso', 'Activity') }}
							</button>
						</div>

						<!-- Activity feed (read-only view over the change log) -->
						<div v-if="discussionTab === 'activity'" class="card-modal__activity">
							<ul v-if="activityItems.length > 0" class="card-modal__activity-list">
								<li v-for="item in activityItems" :key="item.id ?? `${item.action}-${item.verb}-${item.timestamp}-${item.actor || ''}`" class="card-modal__activity-row">
									<NcAvatar
										:user="item.actor || ''"
										:display-name="item.actorName || item.actor || t('kanso', 'System')"
										:size="24"
										:hide-status="true" />
									<span class="card-modal__activity-text">
										<strong>{{ item.actorName || item.actor || t('kanso', 'Someone') }}</strong>{{ ' ' }}
										<template v-for="(seg, si) in activitySegments(item)" :key="si"><strong v-if="seg.strong">{{ seg.text }}</strong><template v-else>{{ seg.text }}</template></template>
										<button
											v-if="hasDescriptionDiff(item)"
											type="button"
											class="card-modal__activity-diff-toggle"
											:aria-expanded="expandedDiffs.has(item.id)"
											@click="toggleDiff(item.id)">
											{{ expandedDiffs.has(item.id) ? t('kanso', 'Hide changes') : t('kanso', 'Show changes') }}
										</button>
									</span>
									<span class="card-modal__activity-time">{{ relativeTime(item.timestamp) }}</span>
									<div v-if="hasDescriptionDiff(item) && expandedDiffs.has(item.id)" class="card-modal__activity-diff">
										<div
											v-for="(line, i) in diffLines(item.detail.from, item.detail.to)"
											:key="i"
											class="card-modal__activity-diff-line"
											:class="`card-modal__activity-diff-line--${line.kind}`">
											<span class="card-modal__activity-diff-sign" aria-hidden="true">{{ line.sign }}</span>
											<span class="card-modal__activity-diff-body">{{ line.text }}</span>
										</div>
									</div>
								</li>
							</ul>
							<div v-else class="card-modal__discussion-empty">
								<HistoryIcon :size="64" class="card-modal__discussion-empty-icon" />
								<p>{{ t('kanso', 'No activity yet.') }}</p>
							</div>
						</div>

						<div v-if="discussionTab === 'discussion'" class="card-modal__thread-scroll">
							<div v-if="commentThread.length > 0" class="card-modal__thread">
								<div
									v-for="{ comment: topComment, replies } in commentThread"
									:key="topComment.id"
									:id="`comment-${topComment.id}`"
									class="card-modal__comment-group"
									:class="{ 'card-modal__comment-group--highlight': highlightedCommentId === topComment.id }">
									<div class="card-modal__comment">
										<NcAvatar
											:user="topComment.author"
											:display-name="topComment.authorDisplayName || topComment.author"
											:size="28"
											:hide-status="true"
											class="card-modal__comment-avatar" />
										<div class="card-modal__comment-main">
											<div class="card-modal__comment-meta">
												<span class="card-modal__comment-author">{{ topComment.authorDisplayName || topComment.author }}</span>
												<span class="card-modal__comment-time">{{ formatCommentTime(topComment.createdAt) }}</span>
												<span v-if="topComment.editedAt > 0" class="card-modal__comment-edited">{{ t('kanso', 'edited') }}</span>
											</div>

											<template v-if="editingCommentId === topComment.id">
												<textarea
													:ref="(el) => setCommentEditRef(topComment.id, el)"
													v-model="editingCommentBody"
													class="card-modal__comment-edit-textarea"
													rows="3"
													@keydown.ctrl.enter.prevent="saveCommentEdit(topComment)"
													@keydown.meta.enter.prevent="saveCommentEdit(topComment)"
													@keydown.escape.stop="cancelCommentEdit" />
												<div class="card-modal__comment-edit-actions">
													<NcButton type="primary" :disabled="editComment.isPending.value" @click="saveCommentEdit(topComment)">
														{{ t('kanso', 'Save') }}
													</NcButton>
													<NcButton @click="cancelCommentEdit">{{ t('kanso', 'Cancel') }}</NcButton>
												</div>
											</template>
											<!-- eslint-disable-next-line vue/no-v-html — renderMarkdown sanitises via DOMPurify -->
											<div v-else class="card-modal__comment-body" v-html="renderedComments.get(topComment.id)" @click="handleRefClick" />

											<div class="card-modal__comment-controls">
												<button
													v-if="canEdit && editingCommentId !== topComment.id"
													class="card-modal__comment-link-btn"
													@click="openReplyBox(topComment, topComment)">
													{{ t('kanso', 'Reply') }}
												</button>
												<!-- Personal "remind me" about this comment (#3816). Any
												     reader can set a private reminder - not gated by canEdit. -->
												<NcActions v-if="editingCommentId !== topComment.id" :force-menu="true" type="tertiary">
													<template #icon>
														<BellOutlineIcon :size="14" />
													</template>
													<NcActionButton :close-after-click="true" @click="scheduleReminder(reminderPresets()[0].at, topComment.id)">
														<template #icon><BellOutlineIcon :size="20" /></template>
														{{ t('kanso', 'Remind me later today') }}
													</NcActionButton>
													<NcActionButton :close-after-click="true" @click="scheduleReminder(reminderPresets()[1].at, topComment.id)">
														<template #icon><BellOutlineIcon :size="20" /></template>
														{{ t('kanso', 'Remind me tomorrow') }}
													</NcActionButton>
													<NcActionButton :close-after-click="true" @click="scheduleReminder(reminderPresets()[2].at, topComment.id)">
														<template #icon><BellOutlineIcon :size="20" /></template>
														{{ t('kanso', 'Remind me next week') }}
													</NcActionButton>
													<NcActionButton :close-after-click="true" @click="openCustomReminder(topComment.id)">
														<template #icon><BellPlusOutlineIcon :size="20" /></template>
														{{ t('kanso', 'Remind me at a custom time…') }}
													</NcActionButton>
												</NcActions>
												<template v-if="canEdit && currentUserId === topComment.author">
													<button class="card-modal__comment-icon-btn" :title="t('kanso', 'Edit comment')" @click="startCommentEdit(topComment)">
														<PencilIcon :size="14" />
													</button>
													<button
														class="card-modal__comment-icon-btn card-modal__comment-icon-btn--danger"
														:title="t('kanso', 'Delete comment')"
														:disabled="deleteComment.isPending.value"
														@click="handleDeleteComment(topComment)">
														<TrashCanIcon :size="14" />
													</button>
												</template>
												<button
													v-for="summary in topComment.reactions"
													:key="summary.emoji"
													class="card-modal__reaction-chip"
													:class="{ 'card-modal__reaction-chip--mine': summary.mine }"
													:title="reactorTooltip(summary)"
													:disabled="!canEdit || toggleReaction.isPending.value"
													@click="onReactionClick(topComment, summary.emoji)">
													<span class="card-modal__reaction-emoji">{{ summary.emoji }}</span>
													<span class="card-modal__reaction-count">{{ summary.count }}</span>
												</button>
												<div v-if="canEdit" class="card-modal__reaction-add-wrap">
													<button
														class="card-modal__reaction-add"
														:title="t('kanso', 'Add reaction')"
														@click.stop="toggleReactionPicker(topComment.id, $event)">
														<EmoticonHappyOutlineIcon :size="14" />
													</button>
													<div v-if="reactionPickerFor === topComment.id" :style="reactionPickerStyle" class="card-modal__reaction-picker">
														<button
															v-for="emoji in reactionEmoji"
															:key="emoji"
															class="card-modal__reaction-picker-btn"
															:title="emoji"
															@click="onReactionClick(topComment, emoji)">
															{{ emoji }}
														</button>
													</div>
												</div>
											</div>
										</div>
									</div>

									<div v-if="replies.length > 0" class="card-modal__replies">
										<div
											v-for="reply in replies"
											:key="reply.id"
											:id="`comment-${reply.id}`"
											class="card-modal__comment card-modal__comment--reply"
											:class="{ 'card-modal__comment--highlight': highlightedCommentId === reply.id }">
											<NcAvatar
												:user="reply.author"
												:display-name="reply.authorDisplayName || reply.author"
												:size="24"
												:hide-status="true"
												class="card-modal__comment-avatar" />
											<div class="card-modal__comment-main">
												<div class="card-modal__comment-meta">
													<span class="card-modal__comment-author">{{ reply.authorDisplayName || reply.author }}</span>
													<span class="card-modal__comment-time">{{ formatCommentTime(reply.createdAt) }}</span>
													<span v-if="reply.editedAt > 0" class="card-modal__comment-edited">{{ t('kanso', 'edited') }}</span>
												</div>

												<template v-if="editingCommentId === reply.id">
													<textarea
														:ref="(el) => setCommentEditRef(reply.id, el)"
														v-model="editingCommentBody"
														class="card-modal__comment-edit-textarea"
														rows="3"
														@keydown.ctrl.enter.prevent="saveCommentEdit(reply)"
														@keydown.meta.enter.prevent="saveCommentEdit(reply)"
														@keydown.escape.stop="cancelCommentEdit" />
													<div class="card-modal__comment-edit-actions">
														<NcButton type="primary" :disabled="editComment.isPending.value" @click="saveCommentEdit(reply)">
															{{ t('kanso', 'Save') }}
														</NcButton>
														<NcButton @click="cancelCommentEdit">{{ t('kanso', 'Cancel') }}</NcButton>
													</div>
												</template>
												<!-- eslint-disable-next-line vue/no-v-html — renderMarkdown sanitises via DOMPurify -->
												<div v-else class="card-modal__comment-body" v-html="renderedComments.get(reply.id)" @click="handleRefClick" />

												<div v-if="canEdit" class="card-modal__comment-controls">
													<button
														v-if="editingCommentId !== reply.id"
														class="card-modal__comment-link-btn"
														@click="openReplyBox(reply, topComment)">
														{{ t('kanso', 'Reply') }}
													</button>
													<template v-if="currentUserId === reply.author">
														<button class="card-modal__comment-icon-btn" :title="t('kanso', 'Edit comment')" @click="startCommentEdit(reply)">
															<PencilIcon :size="14" />
														</button>
														<button
															class="card-modal__comment-icon-btn card-modal__comment-icon-btn--danger"
															:title="t('kanso', 'Delete comment')"
															:disabled="deleteComment.isPending.value"
															@click="handleDeleteComment(reply)">
															<TrashCanIcon :size="14" />
														</button>
													</template>
													<button
														v-for="summary in reply.reactions"
														:key="summary.emoji"
														class="card-modal__reaction-chip"
														:class="{ 'card-modal__reaction-chip--mine': summary.mine }"
														:title="reactorTooltip(summary)"
														:disabled="!canEdit || toggleReaction.isPending.value"
														@click="onReactionClick(reply, summary.emoji)">
															<span class="card-modal__reaction-emoji">{{ summary.emoji }}</span>
															<span class="card-modal__reaction-count">{{ summary.count }}</span>
													</button>
													<div v-if="canEdit" class="card-modal__reaction-add-wrap">
														<button
															class="card-modal__reaction-add"
															:title="t('kanso', 'Add reaction')"
															@click.stop="toggleReactionPicker(reply.id, $event)">
																<EmoticonHappyOutlineIcon :size="14" />
														</button>
														<div v-if="reactionPickerFor === reply.id" :style="reactionPickerStyle" class="card-modal__reaction-picker">
															<button
																v-for="emoji in reactionEmoji"
																:key="emoji"
																class="card-modal__reaction-picker-btn"
																:title="emoji"
																@click="onReactionClick(reply, emoji)">
																	{{ emoji }}
															</button>
														</div>
													</div>
												</div>
											</div>
										</div>
									</div>
									<div v-if="replyingToId === topComment.id && canEdit" class="card-modal__reply-compose">
										<Suspense>
											<MarkdownEditor
												:ref="(el) => setReplyRef(topComment.id, el)"
												v-model="replyBody"
												:placeholder="t('kanso', 'Write a reply…')"
												:disabled="addComment.isPending.value"
												:autofocus="true"
												min-height="60px"
												:participants="participants.data.value ?? []"
												:upload-image="(file) => uploadAttachment.mutateAsync(file)"
												:inline-url="(id) => cardAttachmentInlineUrl(props.cardId, id)"
												:show-toolbar="!editorToolbarHidden"
												@submit="submitReply(topComment.id)"
												@escape="closeReplyBox"
												@image-error="(msg) => { commentError = msg || t('kanso', 'Failed to upload image.') }" />
											<template #fallback>
												<div class="card-modal__comment-edit-textarea card-modal__editor-loading" />
											</template>
										</Suspense>
										<div class="card-modal__comment-edit-actions">
											<NcButton type="primary" :disabled="addComment.isPending.value || !replyBody.trim()" @click="submitReply(topComment.id)">
												{{ t('kanso', 'Post reply') }}
											</NcButton>
											<NcButton @click="closeReplyBox">{{ t('kanso', 'Cancel') }}</NcButton>
										</div>
									</div>
								</div>
							</div>

							<div v-else class="card-modal__discussion-empty">
								<CommentOutlineIcon :size="64" class="card-modal__discussion-empty-icon" />
								<span>{{ t('kanso', 'No threads yet. Everyone watching this card is notified when you start one.') }}</span>
							</div>
						</div>

						<div v-if="canEdit && discussionTab === 'discussion'" class="card-modal__composer">
							<NcAvatar
								:user="currentUserId"
								:size="28"
								:hide-status="true"
								class="card-modal__composer-avatar" />
							<div class="card-modal__composer-main">
								<Suspense>
									<MarkdownEditor
										v-model="newCommentBody"
										:placeholder="t('kanso', 'Start a new thread…')"
										:disabled="addComment.isPending.value"
										min-height="60px"
										:participants="participants.data.value ?? []"
										:upload-image="(file) => uploadAttachment.mutateAsync(file)"
										:inline-url="(id) => cardAttachmentInlineUrl(props.cardId, id)"
										:show-toolbar="!editorToolbarHidden"
										@submit="submitNewComment"
										@image-error="(msg) => { commentError = msg || t('kanso', 'Failed to upload image.') }" />
									<template #fallback>
										<div class="card-modal__composer-textarea card-modal__editor-loading" />
									</template>
								</Suspense>
								<div class="card-modal__composer-actions">
									<NcButton type="primary" :disabled="addComment.isPending.value || !newCommentBody.trim()" @click="submitNewComment">
										{{ t('kanso', 'Post') }}
									</NcButton>
									<span class="card-modal__hint">{{ t('kanso', 'Ctrl+Enter to post') }}</span>
									<span v-if="uploadAttachment.isPending.value" class="card-modal__hint">{{ t('kanso', 'Uploading image…') }}</span>
								</div>
								<span v-if="commentError" class="card-modal__save-error">{{ commentError }}</span>
							</div>
						</div>
					</aside>
				</div>
			</template>
		</div>

	<!-- Copy to… / Move to board… : pick a target board + stack (a board the user can edit). -->
	<NcModal
		v-if="showCopyDialog"
		size="small"
		:name="copyDialogIsMove ? t('kanso', 'Move card to board…') : t('kanso', 'Copy card to…')"
		@close="showCopyDialog = false">
		<div class="card-modal__copy-dialog">
			<h2 class="card-modal__copy-title">{{ copyDialogIsMove ? t('kanso', 'Move card to board…') : t('kanso', 'Copy card to…') }}</h2>
			<p class="card-modal__copy-hint">
				{{ copyDialogIsMove
					? t('kanso', 'Moves the card to another board and removes it from here. Assignees and watchers are kept only if they can access the target board.')
					: t('kanso', 'Duplicates the title, description, labels, checklist, estimate, priority and status. Comments, activity and assignees are not copied.') }}
			</p>
			<label class="card-modal__copy-field">
				<span class="card-modal__copy-label">{{ t('kanso', 'Board') }}</span>
				<select v-model="copyTargetBoardId" class="card-modal__relation-target" @change="onCopyBoardChange">
					<option v-for="b in copyBoardOptions" :key="b.id" :value="b.id">{{ b.title }}</option>
				</select>
			</label>
			<label class="card-modal__copy-field">
				<span class="card-modal__copy-label">{{ t('kanso', 'Column') }}</span>
				<select v-model="copyTargetStackId" class="card-modal__relation-target" :disabled="copyStacksLoading || copyStackOptions.length === 0">
					<option v-if="copyStacksLoading" value="">{{ t('kanso', 'Loading…') }}</option>
					<option v-for="s in copyStackOptions" :key="s.id" :value="s.id">{{ s.title }}</option>
				</select>
			</label>
			<p v-if="copyIsCrossBoard" class="card-modal__copy-note">
				{{ t('kanso', 'Labels are board-specific: only labels that also exist (same name and color) on the target board are kept.') }}
			</p>
			<span v-if="copyError" class="card-modal__save-error">{{ copyError }}</span>
			<div class="card-modal__copy-actions">
				<NcButton :disabled="copyPending" @click="showCopyDialog = false">
					{{ t('kanso', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="copyPending || !copyTargetStackId"
					@click="copyDialogIsMove ? confirmMoveToBoard() : confirmCopy()">
					{{ copyDialogIsMove
						? (copyPending ? t('kanso', 'Moving…') : t('kanso', 'Move'))
						: (copyPending ? t('kanso', 'Copying…') : t('kanso', 'Copy')) }}
				</NcButton>
			</div>
		</div>
	</NcModal>

	<!-- Keyboard / screen-reader "Move card…" picker: the non-pointer
	     alternative to drag-and-drop. Pick a column + position; confirm funnels
	     through the shared optimistic move path (useCardMove). -->
	<NcModal
		v-if="showMovePicker"
		size="small"
		:name="t('kanso', 'Move card')"
		@close="showMovePicker = false">
		<div class="card-modal__copy-dialog">
			<h2 class="card-modal__copy-title">{{ t('kanso', 'Move card') }}</h2>
			<label class="card-modal__copy-field">
				<span class="card-modal__copy-label">{{ t('kanso', 'Column') }}</span>
				<select v-model="movePickerStackId" class="card-modal__relation-target">
					<option v-for="s in moveTargetStacks" :key="s.id" :value="s.id">{{ s.title }}</option>
				</select>
			</label>
			<fieldset class="card-modal__move-position">
				<legend class="card-modal__copy-label">{{ t('kanso', 'Position') }}</legend>
				<label class="card-modal__move-radio">
					<input v-model="movePickerPosition" type="radio" value="top">
					<span>{{ t('kanso', 'Top of the column') }}</span>
				</label>
				<label class="card-modal__move-radio">
					<input v-model="movePickerPosition" type="radio" value="bottom">
					<span>{{ t('kanso', 'Bottom of the column') }}</span>
				</label>
				<label class="card-modal__move-radio" :class="{ 'card-modal__move-radio--disabled': movePickerAfterOptions.length === 0 }">
					<input
						v-model="movePickerPosition"
						type="radio"
						value="after"
						:disabled="movePickerAfterOptions.length === 0">
					<span>{{ t('kanso', 'After a specific card') }}</span>
				</label>
				<select
					v-if="movePickerPosition === 'after'"
					v-model="movePickerAfterCardId"
					class="card-modal__relation-target"
					:disabled="movePickerAfterOptions.length === 0">
					<option v-for="c in movePickerAfterOptions" :key="c.id" :value="c.id">{{ c.title }}</option>
				</select>
			</fieldset>
			<span v-if="moveError" class="card-modal__save-error">{{ moveError }}</span>
			<div class="card-modal__copy-actions">
				<NcButton @click="showMovePicker = false">{{ t('kanso', 'Cancel') }}</NcButton>
				<NcButton
					type="primary"
					:disabled="movePickerStackId == null"
					@click="confirmMovePicker">
					{{ t('kanso', 'Move') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script setup>
import { ref, computed, nextTick, watch, onMounted, onBeforeUnmount, defineAsyncComponent } from 'vue'
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { useRouter, useRoute } from 'vue-router'
import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showUndo, showSuccess, showError } from '@nextcloud/dialogs'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import EmoticonHappyOutlineIcon from 'vue-material-design-icons/EmoticonHappyOutline.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import RepeatIcon from 'vue-material-design-icons/Repeat.vue'
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import GithubIcon from 'vue-material-design-icons/Github.vue'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
import ContentDuplicateIcon from 'vue-material-design-icons/ContentDuplicate.vue'
import TransferIcon from 'vue-material-design-icons/Transfer.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import AccountBoxIcon from 'vue-material-design-icons/AccountBox.vue'
import AccountPlusIcon from 'vue-material-design-icons/AccountPlus.vue'
import ArchiveArrowDownIcon from 'vue-material-design-icons/ArchiveArrowDown.vue'
import ArchiveArrowUpIcon from 'vue-material-design-icons/ArchiveArrowUp.vue'
import CommentMultipleOutlineIcon from 'vue-material-design-icons/CommentMultipleOutline.vue'
import HistoryIcon from 'vue-material-design-icons/History.vue'
import CommentOutlineIcon from 'vue-material-design-icons/CommentOutline.vue'
import TrashCanIcon from 'vue-material-design-icons/TrashCan.vue'
import CheckboxMarkedOutlineIcon from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import CheckboxBlankOutlineIcon from 'vue-material-design-icons/CheckboxBlankOutline.vue'
import DragIcon from 'vue-material-design-icons/Drag.vue'
import FlagIcon from 'vue-material-design-icons/Flag.vue'
import FlagOutlineIcon from 'vue-material-design-icons/FlagOutline.vue'
import BugIcon from 'vue-material-design-icons/Bug.vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import CheckboxMarkedCircleOutlineIcon from 'vue-material-design-icons/CheckboxMarkedCircleOutline.vue'
import BroomIcon from 'vue-material-design-icons/Broom.vue'
import TagOutlineIcon from 'vue-material-design-icons/TagOutline.vue'
import LinkOffIcon from 'vue-material-design-icons/LinkOff.vue'
import SitemapIcon from 'vue-material-design-icons/Sitemap.vue'
import LockOutlineIcon from 'vue-material-design-icons/LockOutline.vue'
import EyeOutlineIcon from 'vue-material-design-icons/EyeOutline.vue'
import EyeOffOutlineIcon from 'vue-material-design-icons/EyeOffOutline.vue'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import CheckDecagramOutlineIcon from 'vue-material-design-icons/CheckDecagramOutline.vue'
import AlertDecagramIcon from 'vue-material-design-icons/AlertDecagram.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChevronDoubleUpIcon from 'vue-material-design-icons/ChevronDoubleUp.vue'
import ChevronDoubleDownIcon from 'vue-material-design-icons/ChevronDoubleDown.vue'
import LinkVariantIcon from 'vue-material-design-icons/LinkVariant.vue'
import VectorLinkIcon from 'vue-material-design-icons/VectorLink.vue'
import CheckCircleOutlineIcon from 'vue-material-design-icons/CheckCircleOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import DockRightIcon from 'vue-material-design-icons/DockRight.vue'
import TimerSandIcon from 'vue-material-design-icons/TimerSand.vue'
import PaletteIcon from 'vue-material-design-icons/Palette.vue'
import FolderMultipleOutlineIcon from 'vue-material-design-icons/FolderMultipleOutline.vue'
import PaperclipIcon from 'vue-material-design-icons/Paperclip.vue'
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'
import TimerOutlineIcon from 'vue-material-design-icons/TimerOutline.vue'
import BellOutlineIcon from 'vue-material-design-icons/BellOutline.vue'
import BellPlusOutlineIcon from 'vue-material-design-icons/BellPlusOutline.vue'
import TableColumnIcon from 'vue-material-design-icons/TableColumn.vue'
// Lazy-load the WYSIWYG editor so the heavy Tiptap/ProseMirror bundle only loads
// when a card modal is actually opened, not on the main board bundle.
const MarkdownEditor = defineAsyncComponent(() => import('./MarkdownEditor.vue'))
import { useCard } from '../composables/useCard.js'
import { useProjects } from '../composables/useProjects.js'
import { addCardToProject as apiAddCardToProject, removeCardFromProject as apiRemoveCardFromProject } from '../services/api.js'
import { usePriority, PRIORITY_LEVELS } from '../composables/usePriority.js'
import { useCardType, CARD_TYPES } from '../composables/useCardType.js'
import { useBoard } from '../composables/useBoard.js'
import { scaleTokens } from '../services/estimateScales.js'
import { useLabels } from '../composables/useLabels.js'
import { useAssignees } from '../composables/useAssignees.js'
import { useContacts } from '../composables/useContacts.js'
import { fetchCardContacts } from '../services/api.js'
import { useReviews } from '../composables/useReviews.js'
import { useRecurRules } from '../composables/useRecurRules.js'
import { useReminders, reminderPresets } from '../composables/useReminders.js'
import { useCardActions } from '../composables/useCardActions.js'
import { useChecklist } from '../composables/useChecklist.js'
import { useComments, buildCommentTree, REACTION_EMOJI } from '../composables/useComments.js'
import { buildCardPrompt } from '../utils/cardPrompt.js'
import { allDayInputValue, timedInputValue, formatCardDate } from '../utils/dateDisplay.js'
import { useCardHierarchy } from '../composables/useCardHierarchy.js'
import { boardQueryKey, invalidateCrossBoardFeeds } from '../composables/queryKeys.js'
import { useCardMove } from '../composables/useCardMove.js'
import { useAnnouncer } from '../composables/useAnnouncer.js'
import { initial, between, after, before } from '../services/sortKey.js'
import { useSubscription } from '../composables/useSubscription.js'
import { useCardLinks, branchName } from '../composables/useCardLinks.js'
import { useCardAttachments } from '../composables/useCardAttachments.js'
import { useCardTimeEntries } from '../composables/useCardTimeEntries.js'
import { cardAttachmentUrl, cardAttachmentInlineUrl } from '../services/api.js'
import { addCardRelation as apiAddCardRelation, removeCardRelation as apiRemoveCardRelation, getCardActivity as apiGetCardActivity, copyCard as apiCopyCard, moveCardToBoard as apiMoveCardToBoard, moveCard as apiMoveCard, fetchBoard as apiFetchBoard, resolveCardRef as apiResolveCardRef, setCardTemplate as apiSetCardTemplate } from '../services/api.js'
import { useBoards } from '../composables/useBoards.js'
import { useCardFields } from '../composables/useCardFields.js'
import { cssColor, LABEL_COLOR_PRESETS, readableColor } from '../services/color.js'
import { humanId } from '../services/humanId.js'
import { normalizeCardFeatures } from '../services/cardFeatures.js'
import { renderMarkdown, buildCardRefMap } from '../services/markdown.js'
import { useEditorPrefs } from '../composables/useEditorPrefs.js'

/**
 * Given a hex background color return '#000' or '#fff' for readable contrast.
 * Uses the W3C relative luminance formula (sRGB).
 * @param {string} hex background color
 * @return {string} readable foreground color
 */

const props = defineProps({
	cardId: {
		type: String,
		required: true,
	},
	// Which shell is rendering this content: the dialog overlay ('modal', the
	// board-scoped /board/:id/card/:cardId child route) or the standalone
	// full-page view ('page', the top-level /card/:cardId route). The two shells
	// share this one component; `mode` only gates the handful of behaviours that
	// legitimately differ (backdrop-close, the expand button, where in-card
	// navigation lands). Everything else - data fetch, optimistic mutations,
	// realtime patching, permission gating - is identical.
	mode: {
		type: String,
		default: 'modal',
		validator: (v) => v === 'modal' || v === 'page',
	},
	// Optional explicit board id. The modal route carries the board id in the URL
	// (route.params.id); the full-page route does not, so the page shell has no
	// board id up front - we fall back to the loaded card's own boardId once it
	// arrives (the card summary/detail payload always carries boardId).
	boardId: {
		type: [String, Number],
		default: null,
	},
	// Controlled overlay mode (#3950): the card is opened as an in-place overlay by
	// a parent that owns the open/close state (e.g. a cross-board View), NOT via the
	// nested card-modal route. In this mode close is purely an `emit('close')` — the
	// component performs NO router navigation on close, so the parent surface (the
	// View's URL) is preserved instead of being swapped for the card's own board.
	// Everything else (data fetch, mutations, realtime, expand-to-page) is unchanged.
	controlled: {
		type: Boolean,
		default: false,
	},
})

// The shell owns closing: the modal navigates back to its board overlay origin,
// the page has an explicit back-to-board affordance. CardDetail emits `close` and
// lets each shell decide, so no navigation policy is duplicated across shells.
// `navigate` is emitted (controlled mode only, #3950) when an in-card link (a
// parent/sub-card/relation chip or a KAN-123 cross-reference) targets a DIFFERENT
// card: the parent that owns the overlay swaps to it, since there is no card-modal
// route to push to on a View surface.
const emit = defineEmits(['close', 'update:title', 'board-context', 'navigate'])

const router = useRouter()
const route = useRoute()

// Editor toolbar visibility — shared module-level pref seeded by App.vue from settings
const { editorToolbarHidden } = useEditorPrefs()

// Modal is open when this component is mounted - enabled is always true here
const isOpen = ref(true)

// A card can be addressed in the URL by its human id (e.g. .../card/KAN-123) as
// well as its numeric id (#3611). A non-numeric cardId is a human reference: we
// resolve it board-scoped to the numeric id and REPLACE the route so the modal
// (and useCard, which fetches /api/cards/{numeric id}) only ever sees a number.
// The numeric route keeps working unchanged. An unresolvable ref surfaces the
// existing not-found modal state.
const isNumericCardId = computed(() => /^\d+$/.test(String(props.cardId)))
const refResolveError = ref(false)

// The card query. Declared BEFORE `boardId` and the {immediate:true} resolve watch
// below, on purpose: the full-page route derives boardId from the loaded card
// (`cardData.value?.boardId`), and the immediate watch reads `boardId` synchronously
// during setup. If `cardData` were declared after them, that synchronous read would
// hit it in the temporal dead zone and throw ("Cannot access before initialization"),
// blanking the page. The modal route dodged it by short-circuiting boardId on
// route.params.id and never touching cardData - the page route has no id param, so it
// falls through to cardData. Keep this ordering. (#3817)
const { data: cardData, isLoading: cardIsLoading, isError: cardIsError, error: cardError, refetch: cardRefetch, updateCard } = useCard(
	computed(() => props.cardId),
	// Only fetch once the id is numeric (a human ref is redirected first).
	computed(() => isOpen.value && isNumericCardId.value),
)

// Effective board id. Prefer an explicit prop, then the route param (modal route),
// then the loaded card's boardId (full-page route, once the card resolves). Kept a
// computed so the page shell reacts when cardData arrives. For the modal route this
// is byte-identical to the old `route.params.id` (the prop/card fallbacks are null
// there until the same value shows up), so modal behaviour is unchanged.
const boardId = computed(() => {
	if (props.boardId != null) return String(props.boardId)
	if (route.params.id) return route.params.id
	const fromCard = cardData.value?.boardId
	return fromCard != null ? String(fromCard) : undefined
})

watch([() => props.cardId, boardId], async ([cardId, bId]) => {
	refResolveError.value = false
	if (isNumericCardId.value || !cardId || !bId) return
	try {
		const { cardId: numericId } = await apiResolveCardRef(bId, cardId)
		// Redirect the human ref to its numeric id on the SAME shell's route so we
		// don't bounce the user between the modal overlay and the full page.
		if (props.mode === 'page') {
			router.replace({ name: 'card-page', params: { cardId: String(numericId) } })
		} else {
			router.replace({ name: 'card-modal', params: { id: String(bId), cardId: String(numericId) } })
		}
	} catch (err) {
		// Unknown / mismatched / malformed reference: leave the modal in a
		// not-found state rather than fetching a bogus numeric id.
		refResolveError.value = true
	}
}, { immediate: true })
// While a human reference is being resolved to its numeric id, show the loading
// skeleton (the card query is still disabled at that point).
const isLoading = computed(() => cardIsLoading.value || (!isNumericCardId.value && !refResolveError.value))
// Surface a failed human-ref resolution through the same error path as a failed
// card fetch, so the template's not-found branch covers both.
const isError = computed(() => cardIsError.value || refResolveError.value)

// Distinguish "the card is gone / forbidden" from a transient load failure so the
// error slot can show friendly, actionable copy instead of a raw failure (#3662).
// A failed human-ref resolution (refResolveError) means the reference didn't
// resolve to a live card - treat it as a 404 (gone). Otherwise read the real HTTP
// status off the axios rejection: 404 = deleted, 403 = access revoked, anything
// else (incl. a network error with no response) = transient/retryable.
const cardErrorStatus = computed(() => {
	if (refResolveError.value) return 404
	return cardError.value?.response?.status ?? null
})
const cardIsGone = computed(() => cardErrorStatus.value === 404)
const cardIsForbidden = computed(() => cardErrorStatus.value === 403)
const cardErrorMessage = computed(() => {
	if (cardIsGone.value) {
		return t('kanso', 'This card no longer exists — it may have been deleted.')
	}
	if (cardIsForbidden.value) {
		return t('kanso', 'You no longer have access to this card.')
	}
	return t('kanso', 'Couldn\'t load this card. Please try again.')
})
// A gone/forbidden card is a dead end - there is nothing to retry. A transient
// failure can be retried by refetching the query. (refResolveError always maps to
// 404, so a retryable error is always a real numeric-id fetch failure.)
const cardErrorRetryable = computed(() => !cardIsGone.value && !cardIsForbidden.value)

function retryCardLoad() {
	cardRefetch()
}

// Read board data from cache (same queryKey as BoardView - no extra request).
const { data: boardData } = useBoard(boardId)
const boardLabels = computed(() => boardData.value?.labels ?? [])
const boardReviewTypes = computed(() => boardData.value?.reviewTypes ?? [])
const boardCardFields = computed(() => boardData.value?.cardFields ?? [])
// Built-in card sections this board's manager left switched on (#5894). Read
// straight off the board payload, so a change propagates through the normal
// delta/realtime path. A board that predates the feature reads as all-enabled.
const cardFeatures = computed(() => normalizeCardFeatures(boardData.value?.board?.cardFeatures))

// ── Card fields: value mutations ──────────────────────────────────────────────
const { setCardFieldValue, clearCardFieldValue } = useCardFields(boardId)
const cardFieldErrors = ref({})

async function handleFieldChange(field, value) {
	cardFieldErrors.value = { ...cardFieldErrors.value, [field.id]: '' }
	try {
		if (value === '' || value === null || value === undefined) {
			await clearCardFieldValue.mutateAsync({ cardId: Number(props.cardId), fieldId: field.id })
		} else {
			await setCardFieldValue.mutateAsync({ cardId: Number(props.cardId), fieldId: field.id, value: String(value) })
		}
	} catch (err) {
		cardFieldErrors.value = {
			...cardFieldErrors.value,
			[field.id]: err?.response?.data?.error || t('kanso', 'Failed to save field value.'),
		}
	}
}

// ── Card lifecycle actions (archive / delete) ────────────────────────────────
const { setArchived, deleteCard, restoreCard } = useCardActions(boardId, computed(() => props.cardId))
const actionError = ref('')
const deleteWithRuleConfirm = ref(false)  // true = show the recurring-source delete guard

// Buttons for the recurring-source delete guard dialog (NcDialog renders them
// right-aligned in array order; the two destructive choices carry the error type).
const deleteGuardButtons = [
	{ label: t('kanso', 'Cancel'), callback: () => { deleteWithRuleConfirm.value = false } },
	{ label: t('kanso', 'Delete card only'), type: 'error', callback: () => { handleDeleteCardOnly() } },
	{ label: t('kanso', 'Delete and stop recurrence'), type: 'error', callback: () => { handleDeleteCardAndRule() } },
]

async function handleArchiveToggle() {
	actionError.value = ''
	const archived = !cardData.value?.archived
	try {
		await setArchived.mutateAsync({ archived })
		// On archive, close the modal so the card visually leaves the board columns.
		// On unarchive, the modal can stay open; the card returns to its column.
		if (archived) {
			closeModal()
			showUndo(t('kanso', 'Card archived'), () => {
				setArchived.mutate({ archived: false })
			})
		}
	} catch (err) {
		actionError.value = err?.response?.data?.error || t('kanso', 'Failed to update card.')
	}
}

// Per-board card templates (#3409). Flag / unflag this card as a template. A
// template is excluded from the live board render, so on marking we close the
// modal (the card leaves the columns) and invalidate the board so the list drops
// it; on unmarking it returns to its column. Database-first: the server flips the
// flag + writes a change row, then the board/card caches refetch.
const templatePending = ref(false)
async function handleTemplateToggle() {
	if (templatePending.value) return
	actionError.value = ''
	const isTemplate = !cardData.value?.isTemplate
	templatePending.value = true
	try {
		await apiSetCardTemplate(props.cardId, isTemplate)
		queryClient.invalidateQueries({ queryKey: ['card', props.cardId] })
		queryClient.invalidateQueries({ queryKey: boardQueryKey(boardId.value) })
		if (isTemplate) {
			// The card leaves the board (templates aren't live cards), so tell the
			// user where it went and how to reuse it — otherwise it just "vanishes".
			showSuccess(t('kanso', 'Saved as a template. Add a card from it with the "+ From template" button on any column.'))
			closeModal()
		} else {
			showSuccess(t('kanso', 'Template turned back into a normal card.'))
		}
	} catch (err) {
		actionError.value = err?.response?.data?.error || t('kanso', 'Failed to update card.')
	} finally {
		templatePending.value = false
	}
}

async function handleDelete() {
	actionError.value = ''
	try {
		await deleteCard.mutateAsync()
		closeModal()
		// Note: restore does NOT re-attach sub-cards that were detached on delete -
		// this is documented self-healing behaviour acceptable for this MVP.
		showUndo(t('kanso', 'Card deleted'), () => {
			restoreCard.mutate()
		})
	} catch (err) {
		actionError.value = err?.response?.data?.error || t('kanso', 'Failed to delete card.')
	}
}

// Recurring-source delete guard: if the card has a recur rule, ask first.
async function confirmDeleteCard() {
	if (cardRecurRule.value) {
		deleteWithRuleConfirm.value = true
	} else {
		await handleDelete()
	}
}

async function handleDeleteCardOnly() {
	deleteWithRuleConfirm.value = false
	await handleDelete()
}

async function handleDeleteCardAndRule() {
	deleteWithRuleConfirm.value = false
	actionError.value = ''
	try {
		if (cardRecurRule.value) {
			await deleteRecurRule.mutateAsync(cardRecurRule.value.id)
		}
		await deleteCard.mutateAsync()
		closeModal()
		showUndo(t('kanso', 'Card deleted'), () => {
			restoreCard.mutate()
		})
	} catch (err) {
		actionError.value = err?.response?.data?.error || t('kanso', 'Failed to delete card.')
	}
}

// Current card's assigned label ids as a Set for O(1) .has() in the template
const cardLabelIds = computed(() => {
	const ids = Array.isArray(cardData.value?.labelIds) ? cardData.value.labelIds : []
	return new Set(ids)
})

// Label toggle + create mutations (create is board-management → MANAGE-gated server-side)
const { toggleLabel, createLabel } = useLabels(boardId)
const labelToggleError = ref('')

async function handleToggleLabel(label) {
	const assign = !cardLabelIds.value.has(label.id)
	labelToggleError.value = ''
	try {
		await toggleLabel.mutateAsync({
			cardId: Number(props.cardId),
			labelId: label.id,
			assign,
		})
		announceMove(assign
			? t('kanso', 'Label {label} added', { label: label.title })
			: t('kanso', 'Label {label} removed', { label: label.title }))
	} catch (err) {
		labelToggleError.value = err?.response?.data?.error || t('kanso', 'Failed to update label.')
	}
}

// ── Inline label creation (from the card's label popover) ────────────────────
// Reuses the shared colour presets — no new colour engine.
const newLabelTitle = ref('')
const newLabelColor = ref('')
const showNewLabelColor = ref(false)
const isCreatingLabel = ref(false)
const createLabelError = ref('')

async function submitCreateLabel() {
	const title = newLabelTitle.value.trim()
	if (!title || isCreatingLabel.value) return
	createLabelError.value = ''
	isCreatingLabel.value = true
	showNewLabelColor.value = false

	// Step 1: create the board label. On failure, keep the inputs so the user
	// can retry without re-typing.
	let label
	try {
		label = await createLabel.mutateAsync({ title, color: newLabelColor.value || null })
	} catch (err) {
		createLabelError.value = err?.response?.data?.error || t('kanso', 'Failed to create label.')
		isCreatingLabel.value = false
		return
	}

	// The label now exists on the board — clear the inputs so a retry of the
	// assign step below never creates a duplicate label.
	newLabelTitle.value = ''
	newLabelColor.value = ''

	// Step 2: assign the freshly-created label to this card.
	try {
		if (label?.id != null) {
			await toggleLabel.mutateAsync({ cardId: Number(props.cardId), labelId: label.id, assign: true })
		}
	} catch (err) {
		createLabelError.value = err?.response?.data?.error
			|| t('kanso', 'Label created, but could not assign it to this card.')
	} finally {
		isCreatingLabel.value = false
	}
}

// ── Assignees ────────────────────────────────────────────────────────────────
const { participants, toggleAssignee } = useAssignees(boardId)
const assigneeError = ref('')

const cardAssigneeIds = computed(() =>
	Array.isArray(cardData.value?.assigneeIds) ? cardData.value.assigneeIds : [],
)

const participantMap = computed(() => {
	const list = Array.isArray(participants.data.value) ? participants.data.value : []
	return new Map(list.map((p) => [p.uid, p]))
})

function participantName(uid) {
	return participantMap.value.get(uid)?.displayName ?? uid
}

const unassignedParticipants = computed(() => {
	const list = Array.isArray(participants.data.value) ? participants.data.value : []
	const assigned = new Set(cardAssigneeIds.value)
	return list.filter((p) => !assigned.has(p.uid))
})

async function handleToggleAssignee(uid, assign) {
	assigneeError.value = ''
	openPicker.value = null
	try {
		await toggleAssignee.mutateAsync({
			cardId: Number(props.cardId),
			userId: uid,
			assign,
		})
		const who = participantName(uid)
		announceMove(assign
			? t('kanso', '{user} assigned', { user: who })
			: t('kanso', '{user} unassigned', { user: who }))
	} catch (err) {
		assigneeError.value = err?.response?.data?.error || t('kanso', 'Failed to update assignee.')
	}
}

// ── Contacts (#3530) ─────────────────────────────────────────────────────────
// Read-only Contacts links. The board summary/card detail carry a `contacts`
// array of {contactUri, displayName}. The picker searches the address book live
// (fetchCardContacts, capped + [] when the Contacts app is disabled), so the
// whole feature self-hides when Contacts is unavailable.
const { toggleContact } = useContacts(boardId)
const contactError = ref('')
const contactQuery = ref('')
const contactResults = ref([])
const contactSearching = ref(false)
const contactsAvailable = ref(false)
const contactSearchInput = ref(null)
let contactSearchTimer = null
let contactSearchSeq = 0

const cardContacts = computed(() =>
	Array.isArray(cardData.value?.contacts) ? cardData.value.contacts : [],
)
const cardContactUris = computed(() => new Set(cardContacts.value.map((c) => c.contactUri)))

// Probe Contacts availability once (a search returns [] when disabled, but an
// empty-query search on an enabled instance also returns [] - so we treat a
// successful call as "available" and only hide on a hard failure). We surface
// the picker whenever the card already has contacts, or the probe succeeded.
async function runContactSearch(query) {
	const bId = resolveContactBoardId()
	// Board id not known yet (full-page route, card still loading). Skip the probe;
	// the boardId watch below re-runs it once the id resolves. Avoids a bogus
	// GET /boards/undefined/contacts.
	if (bId === null || bId === undefined || bId === 'undefined') return
	const seq = ++contactSearchSeq
	contactSearching.value = true
	try {
		const results = await fetchCardContacts(bId, query)
		if (seq !== contactSearchSeq) return
		contactResults.value = Array.isArray(results) ? results : []
		contactsAvailable.value = true
	} catch (err) {
		if (seq !== contactSearchSeq) return
		contactResults.value = []
		// A 4xx/5xx here means Contacts is effectively unavailable - hide it,
		// unless the card already carries links (then keep it visible read-path).
		if (cardContacts.value.length === 0) {
			contactsAvailable.value = false
		}
		contactError.value = err?.response?.data?.error || ''
	} finally {
		if (seq === contactSearchSeq) contactSearching.value = false
	}
}

function resolveContactBoardId() {
	const b = boardId
	if (typeof b === 'function') return b()
	// Return `.value` even when it's undefined (ref not resolved yet) rather than
	// the ref object itself — the latter stringifies to "[object Object]" in the
	// URL. On the full-page route boardId resolves only after the card loads.
	if (b !== null && typeof b === 'object' && 'value' in b) return b.value
	return b
}

function onContactSearch() {
	if (contactSearchTimer) clearTimeout(contactSearchTimer)
	contactSearchTimer = setTimeout(() => {
		runContactSearch(contactQuery.value)
	}, 200)
}

async function toggleContactPicker() {
	if (openPicker.value === 'contact') {
		openPicker.value = null
		return
	}
	openPicker.value = 'contact'
	contactError.value = ''
	contactResults.value = []
	contactQuery.value = ''
	await nextTick()
	contactSearchInput.value?.focus?.()
	runContactSearch('')
}

async function handleToggleContact(contact, link) {
	contactError.value = ''
	openPicker.value = null
	try {
		await toggleContact.mutateAsync({
			cardId: Number(props.cardId),
			contact,
			link,
		})
	} catch (err) {
		contactError.value = err?.response?.data?.error || t('kanso', 'Failed to update contact.')
	}
}

// A card that already carries contact links keeps the section visible (so an
// existing link can always be unlinked) regardless of the probe outcome.
watch(cardContacts, (list) => {
	if (list.length > 0) contactsAvailable.value = true
}, { immediate: true })

// Probe availability once the board id is known so the picker shows for an enabled
// instance even before the user interacts (an empty-query search doubles as the
// probe). On the modal route boardId is set immediately (fires at once); on the
// full-page route it resolves after the card loads, so this re-probes then instead
// of firing a GET /boards/undefined/contacts at setup.
watch(boardId, (id) => {
	if (id !== null && id !== undefined && id !== 'undefined') runContactSearch('')
}, { immediate: true })

onBeforeUnmount(() => {
	if (contactSearchTimer) clearTimeout(contactSearchTimer)
})

// ── Personal reminders (#3816) ────────────────────────────────────────────────
// One-shot "remind me" on this card / a specific comment. Personal per-user: the
// pending list comes from cardData.myReminders (the viewer's own rows only).
const { createReminder, cancelReminder } = useReminders()
const reminderError = ref('')
// The comment the "Custom date-time…" picker is being opened for (null = the
// card overflow menu, i.e. a card-level reminder). Undefined = picker closed.
const customReminderFor = ref(undefined)
const customReminderValue = ref('')

const myReminders = computed(() => {
	const list = cardData.value?.myReminders
	return Array.isArray(list) ? list : []
})

// datetime-local needs "YYYY-MM-DDTHH:mm" in local time (same shaping as the
// card due date + step due inputs). Default the custom picker to +1 hour.
function defaultReminderInputValue() {
	const d = new Date(Date.now() + 60 * 60 * 1000)
	const pad = (n) => String(n).padStart(2, '0')
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

function formatReminderTime(tsSeconds) {
	return new Date(Number(tsSeconds) * 1000).toLocaleString(undefined, {
		day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
	})
}

// Schedule a preset reminder (at a unix ts). commentId null = card-level.
async function scheduleReminder(at, commentId = null) {
	reminderError.value = ''
	try {
		await createReminder.mutateAsync({ cardId: props.cardId, remindAt: at, commentId })
		showSuccess(t('kanso', 'Reminder set'))
	} catch (err) {
		reminderError.value = err?.response?.data?.error || t('kanso', 'Failed to set reminder.')
		showError(reminderError.value)
	}
}

// Open the custom date-time picker for a card-level (commentId null) or
// comment-scoped reminder.
function openCustomReminder(commentId = null) {
	customReminderFor.value = commentId
	customReminderValue.value = defaultReminderInputValue()
}

function cancelCustomReminder() {
	customReminderFor.value = undefined
	customReminderValue.value = ''
}

async function submitCustomReminder() {
	if (!customReminderValue.value) return
	const at = Math.floor(new Date(customReminderValue.value).getTime() / 1000)
	if (!Number.isFinite(at) || at <= Math.floor(Date.now() / 1000)) {
		reminderError.value = t('kanso', 'Pick a time in the future.')
		showError(reminderError.value)
		return
	}
	const commentId = customReminderFor.value ?? null
	cancelCustomReminder()
	await scheduleReminder(at, commentId)
}

async function handleCancelReminder(reminder) {
	reminderError.value = ''
	try {
		await cancelReminder.mutateAsync({ cardId: props.cardId, reminderId: reminder.id })
	} catch (err) {
		reminderError.value = err?.response?.data?.error || t('kanso', 'Failed to cancel reminder.')
		showError(reminderError.value)
	}
}

// ── Reviews ──────────────────────────────────────────────────────────────────
const { requestReview, withdrawReview, setReviewState } = useReviews(boardId)
const reviewError = ref('')

// Selected review type id for the next request-review action (null = no type)
const selectedReviewTypeId = ref(null)

// Resolve a reviewTypeId to its type object (for name + color display).
// 0 (or null) = untyped → no badge.
function reviewTypeById(typeId) {
	if (typeId == null || typeId === 0) return null
	return boardReviewTypes.value.find((rt) => rt.id === typeId) ?? null
}

const cardReviews = computed(() =>
	Array.isArray(cardData.value?.reviews) ? cardData.value.reviews : [],
)

// With 3+ reviews the full pills (avatar + name + type + state label) wrap and
// inflate the header. Collapse to condensed chips (avatar + type + state icon;
// name/state-text move to the avatar tooltip) so they stay on one row. One or
// two reviews keep the legible full pill.
const reviewsCompact = computed(() => cardReviews.value.length >= 3)

// Participants offerable for the CURRENTLY-SELECTED review type. A card may hold
// several reviews per person (one per type), so we only exclude a participant
// who already holds a review of the selected type - switching the type re-opens
// them, which is how you add multiple reviews to one card.
const unrequestedParticipants = computed(() => {
	const list = Array.isArray(participants.data.value) ? participants.data.value : []
	const type = selectedReviewTypeId.value ?? 0
	const requested = new Set(
		cardReviews.value.filter((r) => (r.reviewTypeId ?? 0) === type).map((r) => r.reviewer),
	)
	return list.filter((p) => !requested.has(p.uid))
})

// Every review of the current user that still needs their verdict - a person may
// have more than one (different types), each gets its own verdict controls.
const myPendingReviews = computed(() =>
	cardReviews.value.filter((r) =>
		r.reviewer === currentUserId
		&& (r.state === 'pending' || r.state === 'changes_requested'),
	),
)

// Reject-reason prompt state (#3469): the review id being rejected + its text.
const changesReasonFor = ref(null)
const changesReasonText = ref('')

async function handleRequestReview(uid) {
	reviewError.value = ''
	openPicker.value = null
	const typeId = selectedReviewTypeId.value
	try {
		await requestReview.mutateAsync({
			cardId: Number(props.cardId),
			userId: uid,
			reviewTypeId: typeId ?? null,
		})
	} catch (err) {
		reviewError.value = err?.response?.data?.error || t('kanso', 'Failed to request review.')
	}
}

async function handleWithdrawReview(reviewId) {
	reviewError.value = ''
	try {
		await withdrawReview.mutateAsync({ cardId: Number(props.cardId), reviewId })
	} catch (err) {
		reviewError.value = err?.response?.data?.error || t('kanso', 'Failed to withdraw review request.')
	}
}

// Approve applies immediately; requesting changes opens the reason prompt first.
async function handleReviewVerdict(review, state) {
	if (state === 'changes_requested') {
		changesReasonFor.value = review.id
		changesReasonText.value = ''
		return
	}
	await submitReviewState(review.id, state, null)
}

async function submitChangesRequested(reviewId) {
	await submitReviewState(reviewId, 'changes_requested', changesReasonText.value)
	changesReasonFor.value = null
	changesReasonText.value = ''
}

function cancelChangesRequested() {
	changesReasonFor.value = null
	changesReasonText.value = ''
}

async function submitReviewState(reviewId, state, reason) {
	reviewError.value = ''
	try {
		await setReviewState.mutateAsync({ cardId: Number(props.cardId), reviewId, state, reason })
	} catch (err) {
		reviewError.value = err?.response?.data?.error || t('kanso', 'Failed to submit review.')
	}
}

function reviewStateLabel(state) {
	if (state === 'approved') return t('kanso', 'Approved')
	if (state === 'changes_requested') return t('kanso', 'Changes requested')
	return t('kanso', 'Pending')
}

// A gated review is pending but blocked by a lower-stage review that hasn't
// been approved yet; its reviewer isn't notified until the blocker clears.
function reviewGateTooltip(review) {
	if (!review.gated) return ''
	const blockers = Array.isArray(review.blockedBy) ? review.blockedBy : []
	// Name the blocking lower-stage review type(s) when we can resolve them.
	const names = blockers
		.map((id) => cardReviews.value.find((r) => r.id === id))
		.filter(Boolean)
		.map((r) => (reviewTypeById(r.reviewTypeId)?.title) || t('kanso', 'Review'))
	const unique = [...new Set(names)]
	if (unique.length === 0) return t('kanso', 'Waiting on an earlier review')
	return t('kanso', 'Waiting on {type} review', { type: unique.join(', ') })
}

// ── Done toggle ──────────────────────────────────────────────────────────────
const isDone = computed(() => Number(cardData.value?.doneAt) > 0)

// Derived status (done_at / started_at) - the card-view control + the board's
// stack-role automation both drive it.
const STATUS_OPTIONS = [
	{ key: 'not_started', label: t('kanso', 'Not started') },
	{ key: 'in_progress', label: t('kanso', 'In progress') },
	{ key: 'done', label: t('kanso', 'Done') },
]
const currentStatus = computed(() => {
	if (Number(cardData.value?.doneAt) > 0) return 'done'
	if (Number(cardData.value?.startedAt) > 0) return 'in_progress'
	return 'not_started'
})

async function setStatus(status) {
	if (status === currentStatus.value) return
	try {
		await updateCard.mutateAsync({ data: { status } })
	} catch (err) {
		saveError.value = err?.response?.data?.error || t('kanso', 'Failed to update status.')
	}
}

// ── Workflow stages (#54) ────────────────────────────────────────────────────
// The breadcrumb chip is one control on every board: it shows the card's status
// and its picker offers BOTH the columns and the three statuses. EVERY live
// column is an option, so two "In progress" columns both appear (disambiguated
// by name) and you pick the exact one rather than always landing in the first.
// Picking a column moves the card there; the server's move automation stamps
// started_at / done_at from the column's role, and a role-less column leaves the
// timestamps alone - moving between columns need not change the status. The
// status options stay because on a role-less board they are the only way to
// reach "not started" / "in progress" / "done" at all.
const WORKFLOW_ROLE_LABELS = {
	1: t('kanso', 'Backlog'),
	2: t('kanso', 'To do'),
	3: t('kanso', 'In progress'),
	4: t('kanso', 'Review'),
	5: t('kanso', 'Done'),
}
// Every live column, in board order - the options the stage picker offers.
const boardColumns = computed(() =>
	(boardData.value?.stacks ?? [])
		.filter((s) => !s.archived)
		.slice()
		.sort((a, b) => String(a.sortKey).localeCompare(String(b.sortKey))),
)
const currentColumn = computed(() =>
	boardColumns.value.find((s) => Number(s.id) === Number(cardData.value?.stackId)) ?? null,
)
// The column crumb: the plain column name, shown on every board (roles or not),
// so the card view always says where the card sits without opening the picker.
const currentColumnName = computed(() => currentColumn.value?.title || '')
// A column's stage label: its role (Backlog / … / Done) qualified by the column
// name when they differ, so duplicate-role columns stay distinct; a role-less
// column is shown by its bare name.
function stageLabel(col) {
	if (!col) return ''
	const roleLabel = WORKFLOW_ROLE_LABELS[Number(col.role)]
	const title = col.title ?? ''
	if (!roleLabel) return title
	if (!title || title === roleLabel) return roleLabel
	return `${roleLabel} (${title})`
}
const stageMoving = ref(false)
async function setStage(col) {
	// Already in this column - nothing to do.
	if (!col || Number(col.id) === Number(cardData.value?.stackId)) return
	stageMoving.value = true
	saveError.value = ''
	try {
		await apiMoveCard(props.cardId, { targetStackId: col.id, afterCardId: null })
		queryClient.invalidateQueries({ queryKey: ['card', props.cardId] })
		queryClient.invalidateQueries({ queryKey: boardQueryKey(boardId.value) })
		invalidateCrossBoardFeeds(queryClient)
	} catch (err) {
		saveError.value = err?.response?.data?.error || t('kanso', 'Failed to update status.')
	} finally {
		stageMoving.value = false
	}
}

// ── Priority ─────────────────────────────────────────────────────────────────
const { setPriority } = usePriority(boardId, computed(() => props.cardId))
const priorityError = ref('')

const currentPriority = computed(() => Number(cardData.value?.priority ?? 0))

async function handleSetPriority(priority) {
	if (priority === currentPriority.value) return
	priorityError.value = ''
	try {
		await setPriority.mutateAsync({ priority })
	} catch (err) {
		priorityError.value = err?.response?.data?.error || t('kanso', 'Failed to update priority.')
	}
}

// ── Type (#3402) ─────────────────────────────────────────────────────────────
const { setType } = useCardType(boardId, computed(() => props.cardId))
const typeError = ref('')

const currentTypeValue = computed(() => cardData.value?.type ?? '')
const currentType = computed(() => CARD_TYPES.find((tp) => tp.value === currentTypeValue.value) ?? null)

/** Icon component for a built-in type value ('' → the "no type" outline icon). */
function typeIcon(value) {
	switch (value) {
	case 'bug': return BugIcon
	case 'feature': return StarIcon
	case 'task': return CheckboxMarkedCircleOutlineIcon
	case 'chore': return BroomIcon
	default: return TagOutlineIcon
	}
}

async function handleSetType(type) {
	if (type === currentTypeValue.value) return
	typeError.value = ''
	try {
		await setType.mutateAsync({ type })
	} catch (err) {
		typeError.value = err?.response?.data?.error || t('kanso', 'Failed to update type.')
	}
}

// ── Estimate ─────────────────────────────────────────────────────────────────
const boardEstimateScale = computed(() => boardData.value?.board?.estimateScale ?? 'none')
const estimateTokens = computed(() => scaleTokens(boardEstimateScale.value))
const currentEstimate = computed(() => cardData.value?.estimate ?? '')
const estimateError = ref('')

async function handleSetEstimate(token) {
	// Toggle: clicking the active token clears it; clicking a new one sets it.
	const newToken = token !== '' && token === currentEstimate.value ? '' : token
	estimateError.value = ''
	try {
		await updateCard.mutateAsync({ data: { estimate: newToken } })
	} catch (err) {
		estimateError.value = err?.response?.data?.error || t('kanso', 'Failed to update estimate.')
	}
}

// ── Cover colour (#3549) ─────────────────────────────────────────────────────
// Named palette for the cover swatches (shares the label preset hexes so the
// swatches stay consistent with labels/columns everywhere).
const COVER_COLOR_OPTIONS = [
	{ hex: 'e74c3c', name: t('kanso', 'Red') },
	{ hex: 'e67e22', name: t('kanso', 'Orange') },
	{ hex: 'f1c40f', name: t('kanso', 'Yellow') },
	{ hex: '2ecc71', name: t('kanso', 'Green') },
	{ hex: '1abc9c', name: t('kanso', 'Teal') },
	{ hex: '3498db', name: t('kanso', 'Blue') },
	{ hex: '9b59b6', name: t('kanso', 'Purple') },
	{ hex: '34495e', name: t('kanso', 'Slate') },
]
const currentCoverColor = computed(() => cardData.value?.coverColor ?? '')
const coverColorError = ref('')

async function handleSetCoverColor(hex) {
	// Toggle: clicking the active swatch clears it; '' also clears.
	const next = hex !== '' && hex === currentCoverColor.value ? '' : hex
	coverColorError.value = ''
	try {
		await updateCard.mutateAsync({ data: { coverColor: next } })
		openPicker.value = null
	} catch (err) {
		coverColorError.value = err?.response?.data?.error || t('kanso', 'Failed to update cover.')
	}
}

// ── Due date (editable) ──────────────────────────────────────────────────────
// All-day due dates hide the time-of-day (input becomes a plain date; the pill
// and value drop "HH:MM"). The stored duedate is unchanged (at 00:00).
const isAllDay = computed(() => cardData.value?.allDay === true)

// datetime-local needs "YYYY-MM-DDTHH:mm"; an all-day input is just "YYYY-MM-DD".
const dueDateInputValue = computed(() => {
	if (!cardData.value?.duedate) return ''
	const d = new Date(cardData.value.duedate)
	// All-day dates are stored at UTC midnight and the input is a plain date
	// picker: read the day back in UTC so a date typed as the 22nd shows the 22nd
	// west of UTC (not the 21st). Timed dates keep local time (real time-of-day).
	return isAllDay.value ? allDayInputValue(d) : timedInputValue(d)
})

// Native segmented date inputs fire `change` per segment, so committing on
// `change` kicks off updateCard → refetch mid-edit and clobbers the field (#64).
// Commit on blur/Enter instead: the value is bound straight to the computed and
// only saved once the user leaves the field.
async function handleDueDateChange(event) {
	const val = event.target.value
	if (!val) return
	// Leaving the field without editing it shouldn't fire a redundant PATCH.
	if (val === dueDateInputValue.value) return
	// An all-day "YYYY-MM-DD" parses as UTC midnight; a datetime-local as local.
	const iso = new Date(val).toISOString()
	try {
		await updateCard.mutateAsync({ data: { duedate: iso } })
	} catch (err) {
		saveError.value = err?.response?.data?.error || t('kanso', 'Failed to update due date.')
	}
}

async function toggleAllDay(checked) {
	// An all-day card is a single day (a due date only) — the Start field is hidden.
	// Clear any start date when switching to all-day so it can't linger invisibly,
	// which would otherwise trip the end-before-start guard or silently anchor a
	// repeat on a day the user can no longer see.
	const data = checked ? { allDay: true, startDate: '' } : { allDay: false }
	try {
		await updateCard.mutateAsync({ data })
	} catch (err) {
		saveError.value = err?.response?.data?.error || t('kanso', 'Failed to update due date.')
	}
}

async function clearDueDate() {
	try {
		await updateCard.mutateAsync({ data: { duedate: '' } })
	} catch (err) {
		saveError.value = err?.response?.data?.error || t('kanso', 'Failed to clear due date.')
	}
}

// ── Start date (editable) ────────────────────────────────────────────────────
const startDateInputValue = computed(() => {
	if (!cardData.value?.startDate) return ''
	const d = new Date(cardData.value.startDate)
	// The start input is always a datetime-local field. For an all-day card the
	// start date is stored at UTC midnight, so read the day back in UTC (matching
	// the due date) and pin the time to 00:00; timed dates stay in local time.
	return isAllDay.value ? `${allDayInputValue(d)}T00:00` : timedInputValue(d)
})

async function handleStartDateChange(event) {
	const val = event.target.value
	if (!val) return
	// Leaving the field without editing it shouldn't fire a redundant PATCH.
	if (val === startDateInputValue.value) return
	const iso = new Date(val).toISOString()
	try {
		await updateCard.mutateAsync({ data: { startDate: iso } })
	} catch (err) {
		saveError.value = err?.response?.data?.error || t('kanso', 'Failed to update start date.')
	}
}

async function clearStartDate() {
	try {
		await updateCard.mutateAsync({ data: { startDate: '' } })
	} catch (err) {
		saveError.value = err?.response?.data?.error || t('kanso', 'Failed to clear start date.')
	}
}

// Short plain-language tips shown behind the ⓘ next to each date/repeat field,
// so people don't have to guess. Wording matches the window model: a repeat
// slides the start and due dates forward together. The repeat hint also spells
// out the anchor (start date, else due date - the rule's own creation time is a
// last-resort fallback not worth a tooltip), because the first repeat lands on
// that date itself even when the schedule says otherwise (e.g. "monthly on the
// 15th" on a card starting the 4th). See RecurrenceService::anchorFor().
const startDateHint = t('kanso', 'Optional. When work can begin. It moves with the card when it repeats.')
const dueDateHint = t('kanso', 'Optional. When the card is due. It moves with the card when it repeats, and sends a reminder.')
const repeatHint = t('kanso', 'Brings the card back on a schedule, counting from the card\'s start date, or its due date. A card dated in the future comes back on that date first, then follows the schedule. Its start and due dates slide forward together each time.')

// ── Due date color class (respects done state) ───────────────────────────────
const dueDateClass = computed(() => {
	if (!cardData.value?.duedate) return ''
	// When done, suppress overdue/soon coloring
	if (isDone.value) return ''
	const due = new Date(cardData.value.duedate)
	const now = new Date()
	if (due < now) return 'card-modal__pill--overdue'
	const diff = due - now
	if (diff / (1000 * 60 * 60) <= 24) return 'card-modal__pill--soon'
	return ''
})

const cardTitle = computed(() => cardData.value?.title || t('kanso', 'Card'))

// Surface the resolved title to the shell (the modal uses it for its accessible
// name; the page uses it for the breadcrumb / document title). Emitted rather than
// exposed so binding stays declarative in both shells.
watch(cardTitle, (title) => emit('update:title', title), { immediate: true })
// Board-scoped PREFIX-<seq> → {cardId, title} map for cross-references, built
// from the already-cached board cards + prefix (no extra request). Drives the
// title-link rendering of `KAN-123` in the description and comments.
const cardRefMap = computed(() =>
	buildCardRefMap(boardData.value?.cards ?? [], boardData.value?.board?.prefix),
)
const renderedDescription = computed(() =>
	renderMarkdown(cardData.value?.description || '', { refs: cardRefMap.value }))

// ── Title editing ─────────────────────────────────────────────────────────────
const editingTitle = ref(false)
const draftTitle = ref('')
const titleInputRef = ref(null)

async function startTitleEdit() {
	draftTitle.value = cardData.value?.title || ''
	editingTitle.value = true
	await nextTick()
	titleInputRef.value?.focus()
	titleInputRef.value?.select()
}

function cancelTitleEdit() {
	editingTitle.value = false
}

async function saveTitle() {
	const title = draftTitle.value.trim()
	if (!title || title === cardData.value?.title) {
		editingTitle.value = false
		return
	}
	try {
		await updateCard.mutateAsync({ data: { title } })
	} catch (err) {
		saveError.value = err?.response?.data?.error || t('kanso', 'Could not save the title')
	} finally {
		editingTitle.value = false
	}
}

// ── Description editing ───────────────────────────────────────────────────────
const editingDescription = ref(false)
const draftDescription = ref('')
const isSaving = ref(false)
const saveError = ref('')

// Optimistic-concurrency state for the description (#9848). Both are captured
// ONCE, when the editor opens, and deliberately not recomputed from cardData:
// a realtime delta may refresh the card underneath an open editor, and the base
// must keep pointing at the version this draft was actually derived from.
// `descriptionBaseVersion` is the card's `descriptionRevision` — a per-card
// counter that moves ONLY when the description itself changes, so an unrelated
// title or due-date save can never look like a conflict.
// `descriptionConflict` holds the server's current text after a rejected save.
const descriptionBaseVersion = ref(null)
const descriptionBaseText = ref('')
const descriptionConflict = ref(null)

// The board shell renders this component through an UNKEYED router-view, so
// navigating card→card REUSES it. Every piece of description-editing state is
// per-card and must be dropped on the switch - carrying the optimistic base
// version across would compare the new card's version against the old card's
// and silently skip the conflict guard, and a stale conflict panel would show
// the wrong card's text. Not `immediate`: there is nothing to clear on mount.
watch(() => props.cardId, () => {
	editingDescription.value = false
	draftDescription.value = ''
	descriptionBaseVersion.value = null
	descriptionBaseText.value = ''
	descriptionConflict.value = null
	saveError.value = ''
})

function startDescriptionEdit() {
	draftDescription.value = cardData.value?.description || ''
	descriptionBaseText.value = draftDescription.value
	// `descriptionRevision` rides on the DETAIL payload only (it is deliberately
	// absent from board/stack summaries, next to the description it guards), and
	// this cache is filled solely by the single-card fetch, so it is always a
	// number here — 0 included, which `??` correctly keeps. A card object from
	// anywhere else would leave it undefined and silently drop the guard back to
	// last-writer-wins, so keep this reading the detail query.
	descriptionBaseVersion.value = cardData.value?.descriptionRevision ?? null
	descriptionConflict.value = null
	editingDescription.value = true
	saveError.value = ''
}

function cancelDescriptionEdit() {
	// Cancelling on top of an UNRESOLVED conflict would throw away exactly the
	// text the server just refused to overwrite - and Escape makes that one
	// reflex away. Confirm before losing it; an ordinary cancel is untouched.
	if (descriptionConflict.value
		&& !window.confirm(t('kanso', 'Discard your unsaved description? The other version will be kept.'))) {
		return
	}
	editingDescription.value = false
	descriptionConflict.value = null
	saveError.value = ''
}

/**
 * One guarded description save. `baseVersion` is the card version this draft
 * was derived from; the server refuses the write with 409 when the card has
 * moved on AND the stored text differs, so a second author's work is never
 * silently overwritten.
 *
 * The base is the card's `descriptionRevision`, and the server enforces it with
 * a conditional UPDATE, so exactly one of two simultaneous saves can win. The
 * rejection carries the server's current text: when that is byte-identical to
 * what this editor started from, the other write cost us nothing and the save is
 * retried once, transparently. Anything else is a genuine two-author conflict
 * and is surfaced with BOTH versions intact.
 *
 * @param {number|null} baseVersion card `descriptionRevision` this draft is based on
 * @param {boolean} allowRetry whether a provably-spurious 409 may be retried
 */
async function pushDescription(baseVersion, allowRetry) {
	try {
		const saved = await updateCard.mutateAsync({
			data: { description: draftDescription.value, baseDescriptionRevision: baseVersion },
		})
		descriptionBaseText.value = draftDescription.value
		descriptionBaseVersion.value = saved?.descriptionRevision ?? null
		descriptionConflict.value = null
		editingDescription.value = false
	} catch (err) {
		const data = err?.response?.data
		if (err?.response?.status === 409 && data?.error === 'description_conflict') {
			const theirs = data.description ?? ''
			if (allowRetry && theirs === descriptionBaseText.value) {
				await pushDescription(data.revision ?? null, false)
				return
			}
			// Keep the editor open with the draft untouched; the panel renders
			// their version alongside it.
			descriptionConflict.value = { description: theirs, revision: data.revision ?? null }
			return
		}
		saveError.value = data?.error || t('kanso', 'Failed to save.')
	}
}

async function saveDescription() {
	isSaving.value = true
	saveError.value = ''
	descriptionConflict.value = null
	try {
		await pushDescription(descriptionBaseVersion.value, true)
	} finally {
		isSaving.value = false
	}
}

/** Conflict resolution: the user's draft wins over the version shown to them. */
async function overwriteDescription() {
	const base = descriptionConflict.value?.revision ?? null
	isSaving.value = true
	saveError.value = ''
	descriptionConflict.value = null
	try {
		await pushDescription(base, false)
	} finally {
		isSaving.value = false
	}
}

/**
 * Conflict resolution: adopt their version into the editor. This REPLACES the
 * user's draft and there is no undo - which is why it is a deliberate click on
 * a button that says so, next to a panel showing both texts, and never the
 * default. "Keep my version" is the primary action.
 */
function useTheirDescription() {
	const conflict = descriptionConflict.value
	if (!conflict) return
	draftDescription.value = conflict.description
	descriptionBaseText.value = conflict.description
	descriptionBaseVersion.value = conflict.revision
	descriptionConflict.value = null
	saveError.value = ''
}

// ── Checklist ────────────────────────────────────────────────────────────────
const {
	items: checklistQuery,
	addItem,
	toggleItem,
	renameItem,
	deleteItem,
	moveItem,
	assignItem,
	unassignItem,
	setItemDue,
} = useChecklist(computed(() => props.cardId), boardId)

const checklistItems = computed(() => checklistQuery.data.value ?? [])

const checklistTotal = computed(() => checklistItems.value.length)
const checklistDone = computed(() => checklistItems.value.filter((i) => i.done).length)
const checklistProgressPct = computed(() =>
	checklistTotal.value === 0 ? 0 : Math.round((checklistDone.value / checklistTotal.value) * 100),
)

const checklistError = ref('')
const newItemTitle = ref('')
const addItemInputRef = ref(null)

async function handleAddItem() {
	const title = newItemTitle.value.trim()
	if (!title) return
	checklistError.value = ''
	// Clear immediately so rapid entry works; on failure restore the text, but
	// only if the user hasn't already started typing the next item.
	newItemTitle.value = ''
	try {
		await addItem.mutateAsync({ title })
	} catch (err) {
		checklistError.value = err?.response?.data?.error || t('kanso', 'Failed to add item.')
		if (newItemTitle.value === '') newItemTitle.value = title
	}
	// Keep focus for rapid entry
	await nextTick()
	addItemInputRef.value?.focus()
}

async function handleToggleItem(item) {
	checklistError.value = ''
	try {
		await toggleItem.mutateAsync({ item })
	} catch (err) {
		checklistError.value = err?.response?.data?.error || t('kanso', 'Failed to update item.')
	}
}

async function handleDeleteItem(item) {
	checklistError.value = ''
	try {
		await deleteItem.mutateAsync({ item })
	} catch (err) {
		checklistError.value = err?.response?.data?.error || t('kanso', 'Failed to delete item.')
	}
}

// Inline item title editing
const editingItemId = ref(null)
const editingItemTitle = ref('')
const itemInputRefs = {}

function setItemInputRef(id, el) {
	if (el) {
		itemInputRefs[id] = el
	} else {
		delete itemInputRefs[id]
	}
}

async function startItemEdit(item) {
	editingItemId.value = item.id
	editingItemTitle.value = item.title
	await nextTick()
	itemInputRefs[item.id]?.focus()
	itemInputRefs[item.id]?.select()
}

function cancelItemEdit() {
	editingItemId.value = null
	editingItemTitle.value = ''
}

async function saveItemTitle(item) {
	const title = editingItemTitle.value.trim()
	if (!title || title === item.title) {
		cancelItemEdit()
		return
	}
	checklistError.value = ''
	try {
		await renameItem.mutateAsync({ item, title })
	} catch (err) {
		checklistError.value = err?.response?.data?.error || t('kanso', 'Failed to rename item.')
	} finally {
		cancelItemEdit()
	}
}

// ── Rich checklist steps (#3745): per-item assignee + due date ──────────────
// One step popover open at a time, keyed `${type}:${itemId}` ('assign'|'due').
const openStepMenu = ref(null)
function isStepMenuOpen(item, type) {
	return openStepMenu.value === `${type}:${item.id}`
}
function toggleStepMenu(item, type) {
	const key = `${type}:${item.id}`
	openStepMenu.value = openStepMenu.value === key ? null : key
}

// All board participants (external members included - assigning a step to the
// client side is the point of #3745) minus the current assignee.
function stepAssignCandidates(item) {
	const list = Array.isArray(participants.data.value) ? participants.data.value : []
	return list.filter((p) => p.uid !== item.assignedUser)
}

async function handleAssignStep(item, uid) {
	checklistError.value = ''
	openStepMenu.value = null
	try {
		await assignItem.mutateAsync({ item, participant: uid })
	} catch (err) {
		checklistError.value = err?.response?.data?.error || t('kanso', 'Failed to assign step.')
	}
}

async function handleUnassignStep(item) {
	checklistError.value = ''
	openStepMenu.value = null
	try {
		await unassignItem.mutateAsync({ item })
	} catch (err) {
		checklistError.value = err?.response?.data?.error || t('kanso', 'Failed to unassign step.')
	}
}

// datetime-local needs "YYYY-MM-DDTHH:mm" in local time (same shaping as the
// card due date input above).
function stepDueInputValue(item) {
	if (!item.dueDate) return ''
	const d = new Date(item.dueDate)
	const pad = (n) => String(n).padStart(2, '0')
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

async function handleStepDueChange(item, event) {
	const val = event.target.value
	if (!val) return
	checklistError.value = ''
	openStepMenu.value = null
	try {
		await setItemDue.mutateAsync({ item, due: new Date(val).toISOString() })
	} catch (err) {
		checklistError.value = err?.response?.data?.error || t('kanso', 'Failed to update step due date.')
	}
}

async function clearStepDue(item) {
	checklistError.value = ''
	openStepMenu.value = null
	try {
		await setItemDue.mutateAsync({ item, due: null })
	} catch (err) {
		checklistError.value = err?.response?.data?.error || t('kanso', 'Failed to clear step due date.')
	}
}

// Overdue/soon styling for the step due chip - suppressed once the step is
// done (mirrors the card-tile due chip).
function stepDueClass(item) {
	if (!item.dueDate || item.done) return ''
	const due = new Date(item.dueDate)
	const now = new Date()
	if (due < now) return 'card-modal__step-due--overdue'
	if ((due - now) / (1000 * 60 * 60) <= 24) return 'card-modal__step-due--soon'
	return ''
}

function formatStepDue(iso) {
	return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

// ── Checklist drag-and-drop (native HTML5 DnD) ─────────────────────────────
// Using HTML5 DnD (draggable=true) on the drag handle for intra-list reordering.
// The board uses @atlaskit/pragmatic-drag-and-drop for cross-column card DnD;
// wiring Pragmatic DnD here would require data type namespacing to avoid conflicts
// with the card DnD, which is out of scope for this composable. HTML5 DnD is
// sufficient and self-contained for checklist reorder.
const draggingItem = ref(null)
const dragOverItemId = ref(null)

function onItemDragStart(event, item) {
	draggingItem.value = item
	event.dataTransfer.effectAllowed = 'move'
	event.dataTransfer.setData('text/plain', String(item.id))
}

function onItemDragEnd() {
	draggingItem.value = null
	dragOverItemId.value = null
}

function onItemDragOver(event, item) {
	if (!draggingItem.value || draggingItem.value.id === item.id) return
	event.dataTransfer.dropEffect = 'move'
	dragOverItemId.value = item.id
}

function onItemDragLeave(_event, item) {
	if (dragOverItemId.value === item.id) {
		dragOverItemId.value = null
	}
}

async function onItemDrop(event, targetItem) {
	if (!draggingItem.value || draggingItem.value.id === targetItem.id) {
		dragOverItemId.value = null
		return
	}
	checklistError.value = ''
	const itemToMove = draggingItem.value
	dragOverItemId.value = null
	draggingItem.value = null

	// Closest-edge: dropping on the TOP half of the target inserts the item
	// before it, on the bottom half after it. This makes "move to the very top"
	// reachable - a top-half drop on the first row resolves to afterItemId=null.
	const rect = event.currentTarget.getBoundingClientRect()
	const dropBefore = event.clientY - rect.top < rect.height / 2

	let afterItemId
	if (dropBefore) {
		// Insert before the target → sit after the target's predecessor,
		// skipping the item being moved (so dragging item #2 onto item #1's top
		// half lands it at the top, not back where it was).
		const ordered = checklistItems.value
		const targetIdx = ordered.findIndex((i) => i.id === targetItem.id)
		let predecessor = null
		for (let k = targetIdx - 1; k >= 0; k--) {
			if (ordered[k].id !== itemToMove.id) {
				predecessor = ordered[k]
				break
			}
		}
		afterItemId = predecessor ? predecessor.id : null
	} else {
		afterItemId = targetItem.id
	}

	try {
		await moveItem.mutateAsync({ item: itemToMove, afterItemId })
	} catch (err) {
		checklistError.value = err?.response?.data?.error || t('kanso', 'Failed to reorder item.')
	}
}

// ── Discussion | Activity tabs ───────────────────────────────────────────────
const discussionTab = ref('discussion')

// Per-card activity feed — a read-only view over the change log. Fetched lazily
// when the Activity tab is open, and refetched each time it's opened (staleTime 0).
const activityQuery = useQuery({
	queryKey: computed(() => ['card-activity', String(props.cardId)]),
	queryFn: () => apiGetCardActivity(Number(props.cardId)),
	enabled: computed(() => discussionTab.value === 'activity'),
	staleTime: 0,
})
const activityItems = computed(() => (Array.isArray(activityQuery.data.value) ? activityQuery.data.value : []))

// verb → generic human phrase (the fallback when an item carries no detail, e.g.
// legacy rows recorded before the values were captured). Falls back to a generic
// "updated this card".
const ACTIVITY_VERBS = {
	1: () => t('kanso', 'created this card'),
	2: () => t('kanso', 'updated this card'),
	3: () => t('kanso', 'moved this card'),
	4: () => t('kanso', 'deleted this card'),
	5: () => t('kanso', 'commented'),
	6: () => t('kanso', 'added a label'),
	7: () => t('kanso', 'removed a label'),
	8: () => t('kanso', 'assigned a member'),
	9: () => t('kanso', 'removed an assignee'),
	10: () => t('kanso', 'requested a review'),
	11: () => t('kanso', 'gave a review verdict'),
	12: () => t('kanso', 'updated the checklist'),
	13: () => t('kanso', 'linked a contact'),
	14: () => t('kanso', 'unlinked a contact'),
	15: () => t('kanso', 'renamed this card'),
	16: () => t('kanso', 'updated the description'),
	17: () => t('kanso', 'changed the due date'),
	18: () => t('kanso', 'changed the start date'),
	19: () => t('kanso', 'changed the priority'),
	20: () => t('kanso', 'changed the status'),
	21: () => t('kanso', 'changed the estimate'),
	22: () => t('kanso', 'changed the card type'),
}
function activityVerbText(item) {
	const fn = ACTIVITY_VERBS[item.verb]
	return fn ? fn() : t('kanso', 'updated this card')
}

// Render the activity phrase as an ordered list of { text, strong } segments so
// the specific values (column names, label titles, assignee names, field values)
// render as escaped, bold-highlighted USER CONTENT. Every value rides in a
// segment and is bound via {{ }} in the template — never v-html — so it is
// auto-escaped. When an item has no detail (legacy rows, or verbs that store
// none) we fall back to the flat ACTIVITY_VERBS phrase as a single plain segment.
function plain(text) {
	return [{ text, strong: false }]
}
function activitySegments(item) {
	const d = item.detail
	// The 't' calls below use {value} placeholders so the phrase stays
	// translatable; we split each around the placeholder(s) and slot the escaped
	// value in as its own bold segment, rather than interpolating into markup.
	const withOne = (phrase, value) => {
		const parts = phrase.split('{value}')
		return [
			{ text: parts[0], strong: false },
			{ text: value, strong: true },
			{ text: parts[1] ?? '', strong: false },
		]
	}
	const withFromTo = (phrase, from, to) => {
		// phrase carries {from} then {to}; split on both, keep order.
		const afterFrom = phrase.split('{from}')
		const head = afterFrom[0]
		const tail = (afterFrom[1] ?? '').split('{to}')
		return [
			{ text: head, strong: false },
			{ text: from, strong: true },
			{ text: tail[0] ?? '', strong: false },
			{ text: to, strong: true },
			{ text: tail[1] ?? '', strong: false },
		]
	}

	switch (item.verb) {
	case 3: // moved
		if (d && d.from != null && d.to != null && d.from !== '' && d.to !== '') {
			return withFromTo(t('kanso', 'moved this card from {from} to {to}'), d.from, d.to)
		}
		break
	case 6: // labeled (added)
		if (d && d.to) return withOne(t('kanso', 'added the label {value}'), d.to)
		break
	case 7: // unlabeled (removed)
		if (d && d.from) return withOne(t('kanso', 'removed the label {value}'), d.from)
		break
	case 8: // assigned
		if (d && d.to) return withOne(t('kanso', 'assigned {value}'), d.to)
		break
	case 9: // unassigned
		if (d && d.from) return withOne(t('kanso', 'removed {value}'), d.from)
		break
	case 15: // renamed
		if (d && d.to != null && d.to !== '') return withOne(t('kanso', 'renamed this card to {value}'), d.to)
		break
	case 17: // due date
		if (d) {
			return d.to ? withOne(t('kanso', 'changed the due date to {value}'), d.to) : plain(t('kanso', 'cleared the due date'))
		}
		break
	case 18: // start date
		if (d) {
			return d.to ? withOne(t('kanso', 'changed the start date to {value}'), d.to) : plain(t('kanso', 'cleared the start date'))
		}
		break
	case 19: // priority
		if (d && d.to != null && d.to !== '') {
			return (d.from != null && d.from !== '')
				? withFromTo(t('kanso', 'changed the priority from {from} to {to}'), d.from, d.to)
				: withOne(t('kanso', 'changed the priority to {value}'), d.to)
		}
		break
	case 20: // status
		if (d && d.to != null && d.to !== '') {
			return (d.from != null && d.from !== '')
				? withFromTo(t('kanso', 'changed the status from {from} to {to}'), d.from, d.to)
				: withOne(t('kanso', 'changed the status to {value}'), d.to)
		}
		break
	case 21: // estimate
		if (d) {
			return d.to ? withOne(t('kanso', 'changed the estimate to {value}'), d.to) : plain(t('kanso', 'cleared the estimate'))
		}
		break
	case 22: // card type
		if (d) {
			return d.to ? withOne(t('kanso', 'changed the card type to {value}'), d.to) : plain(t('kanso', 'cleared the card type'))
		}
		break
	}
	// Description (16) keeps the collapsible diff below; every other case falls
	// back to the flat verb phrase.
	return plain(activityVerbText(item))
}

// ── Description diff (Activity feed) ──────────────────────────────────────────
// A description-update item may carry item.detail = { from, to }. Under its
// "updated the description" line we offer a collapsible, dependency-free
// line-level diff. Which item ids are currently expanded (collapsed by default).
const expandedDiffs = ref(new Set())
function toggleDiff(id) {
	// Reassign the Set so the reactive template re-renders on mutate.
	const next = new Set(expandedDiffs.value)
	if (next.has(id)) {
		next.delete(id)
	} else {
		next.add(id)
	}
	expandedDiffs.value = next
}

// Only offer the toggle when there's a real before/after to show: both a detail
// payload present AND the two texts actually differ (equal or both-empty → nothing).
function hasDescriptionDiff(item) {
	if (item.verb !== 16 || !item.detail) return false
	const from = item.detail.from ?? ''
	const to = item.detail.to ?? ''
	return from !== to && (from !== '' || to !== '')
}

// A minimal LCS-based line diff. Splits both texts on \n, computes the longest
// common subsequence of lines, then walks it to emit unchanged / removed / added
// lines in order. Text is bound via {{ }} (auto-escaped) — it is NEVER v-html'd,
// so user content renders as plain text, not markup.
function diffLines(fromText, toText) {
	const a = String(fromText ?? '').split('\n')
	const b = String(toText ?? '').split('\n')
	const n = a.length
	const m = b.length

	// LCS length table (rows n+1, cols m+1).
	const lcs = Array.from({ length: n + 1 }, () => new Array(m + 1).fill(0))
	for (let i = n - 1; i >= 0; i--) {
		for (let j = m - 1; j >= 0; j--) {
			lcs[i][j] = a[i] === b[j]
				? lcs[i + 1][j + 1] + 1
				: Math.max(lcs[i + 1][j], lcs[i][j + 1])
		}
	}

	const out = []
	let i = 0
	let j = 0
	while (i < n && j < m) {
		if (a[i] === b[j]) {
			out.push({ kind: 'same', sign: ' ', text: a[i] })
			i++
			j++
		} else if (lcs[i + 1][j] >= lcs[i][j + 1]) {
			out.push({ kind: 'removed', sign: '−', text: a[i] })
			i++
		} else {
			out.push({ kind: 'added', sign: '+', text: b[j] })
			j++
		}
	}
	while (i < n) {
		out.push({ kind: 'removed', sign: '−', text: a[i] })
		i++
	}
	while (j < m) {
		out.push({ kind: 'added', sign: '+', text: b[j] })
		j++
	}
	return out
}

// Compact relative time (falls back to a localized date for older entries).
function relativeTime(tsSeconds) {
	if (!tsSeconds) return ''
	const secs = Math.max(0, Math.floor(Date.now() / 1000) - Number(tsSeconds))
	if (secs < 60) return t('kanso', 'just now')
	const mins = Math.floor(secs / 60)
	if (mins < 60) return n('kanso', '%n minute ago', '%n minutes ago', mins)
	const hours = Math.floor(mins / 60)
	if (hours < 24) return n('kanso', '%n hour ago', '%n hours ago', hours)
	const days = Math.floor(hours / 24)
	if (days < 7) return n('kanso', '%n day ago', '%n days ago', days)
	return new Date(Number(tsSeconds) * 1000).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
}

// ── Comments / Discussion ────────────────────────────────────────────────────
const {
	comments: commentsQuery,
	addComment,
	editComment,
	deleteComment,
	toggleReaction,
	toggleCommentReaction,
} = useComments(computed(() => props.cardId), boardId)

// Emoji reactions on comments (#3550). The fixed picker set + which comment has
// its "add reaction" popover open. Toggling reads the comment's own reaction
// summary (mine flag) to decide react vs. unreact.
const reactionEmoji = REACTION_EMOJI
const reactionPickerFor = ref(null)
// The emoji picker is positioned `fixed` (coords computed from the trigger button on
// open) so it escapes the comment thread's `overflow` clipping and the content pane's
// stacking — a comment lives inside .card-modal__thread-scroll (overflow:auto), which
// would otherwise clip the popover when it opens sideways. See toggleReactionPicker.
const reactionPickerStyle = ref({})

function toggleReactionPicker(commentId, ev) {
	if (reactionPickerFor.value === commentId) {
		reactionPickerFor.value = null
		return
	}
	reactionPickerFor.value = commentId
	const btn = ev && ev.currentTarget
	if (btn && typeof btn.getBoundingClientRect === 'function') {
		const r = btn.getBoundingClientRect()
		// Open the picker just above the button. Align it to the button's side that
		// leaves the most room (right-align when the button sits in the right half of
		// the viewport, so it opens leftward and stays on-screen; left-align otherwise).
		const style = {
			position: 'fixed',
			bottom: (window.innerHeight - r.top + 4) + 'px',
			top: 'auto',
		}
		if (r.left < window.innerWidth / 2) {
			style.left = Math.round(r.left) + 'px'
			style.right = 'auto'
		} else {
			style.right = Math.round(window.innerWidth - r.right) + 'px'
			style.left = 'auto'
		}
		reactionPickerStyle.value = style
	} else {
		reactionPickerStyle.value = {}
	}
}

async function onReactionClick(comment, emoji) {
	reactionPickerFor.value = null
	try {
		await toggleCommentReaction(comment, emoji)
	} catch (err) {
		commentError.value = err?.response?.data?.error || t('kanso', 'Failed to update reaction.')
	}
}

function reactorTooltip(summary) {
	const names = Array.isArray(summary.reactors) ? summary.reactors : []
	if (names.length === 0) return ''
	return names.join(', ')
}

// Current user uid - used to gate edit/delete controls to the comment author
const currentUserId = getCurrentUser()?.uid ?? ''

// EDIT permission bit (bit 1, value 2) from board payload
const canEdit = computed(() => {
	const perms = boardData.value?.permissions ?? 0
	return (perms & 2) !== 0
})

// MANAGE permission bit (bit 3, value 8) - board management, e.g. creating labels
const canManage = computed(() => {
	const perms = boardData.value?.permissions ?? 0
	return (perms & 8) !== 0
})

// Card visibility (#3743): only the card's creator or a board manager may
// change it (the server enforces the same rule).
const canSetVisibility = computed(() => canManage.value || (cardData.value?.owner === currentUserId))

// ── Repeat / recurrence (#55) ────────────────────────────────────────────────
// A compact "Repeat" control in the due-date popover, backed by the SAME
// recurring-card engine as Board Settings → Automation: it just creates/edits a
// rule whose source ("template") card is THIS card and whose target is this
// card's own column. Manager-gated to match the rule endpoints. Only the simple
// presets (frequency + interval, clone mode, due-at-occurrence) are exposed
// here; anything richer stays in the Automation tab, and a rule that already
// uses those richer options is shown read-only so this control never clobbers it.
const {
	data: recurRulesData,
	createRule: createRecurRule,
	updateRule: updateRecurRule,
	deleteRule: deleteRecurRule,
} = useRecurRules(boardId, {
	// Hold the fetch until the board id has resolved (the full-page card route
	// learns it only after the card loads - firing early 404s, #3817) and only
	// for managers, the only ones who ever see the Repeat control.
	enabled: computed(() => !!boardId.value && canManage.value),
})

// The (first) recurrence rule anchored on this card, if any.
const cardRecurRule = computed(() =>
	(recurRulesData.value ?? []).find((r) => Number(r.templateCardId) === Number(props.cardId)) ?? null,
)

// Does this card recur? (#61 follow-up) Drives the repeat-icon swap on the Due
// Date pill. Prefer the detail payload's `recurring` boolean so ALL viewers see
// the cue (matching the board tile); OR-in the manager-only rule so the icon
// flips instantly when a manager toggles recurrence in the popover, before any
// refetch.
const cardIsRecurring = computed(() => !!cardData.value?.recurring || !!cardRecurRule.value)

// Split an RRULE into FREQ + INTERVAL, flagging any token this simple control
// can't represent (BYDAY / COUNT / UNTIL / …).
function parseSimpleRrule(rrule) {
	let freq = null
	let interval = 1
	let extra = false
	for (const token of String(rrule || '').split(';')) {
		const [k, v] = token.split('=')
		if (k === 'FREQ') freq = v
		else if (k === 'INTERVAL') interval = Math.max(1, parseInt(v, 10) || 1)
		else if (token.trim()) extra = true
	}
	return { freq, interval, extra }
}

const RECUR_FREQS = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY']

// A card rule the presets can't round-trip (extra RRULE parts or a non-default
// due-date policy) is surfaced read-only, pointing at Automation. Mode is now
// supported directly in the simple control so it no longer triggers custom.
const recurIsCustom = computed(() => {
	const rule = cardRecurRule.value
	if (!rule) return false
	const parsed = parseSimpleRrule(rule.rrule)
	return parsed.extra
		|| !RECUR_FREQS.includes(parsed.freq)
		|| Number(rule.duedatePolicy) !== 0
})

const recurFreq = ref('OFF')
const recurInterval = ref(1)
const recurMode = ref(1)
const recurError = ref('')

// Mirror the card's current rule into the control (and reset when it goes away).
watch(cardRecurRule, (rule) => {
	if (rule && !recurIsCustom.value) {
		const parsed = parseSimpleRrule(rule.rrule)
		recurFreq.value = parsed.freq
		recurInterval.value = parsed.interval
		recurMode.value = Number(rule.mode) === 1 ? 1 : 0
	} else if (!rule) {
		recurFreq.value = 'OFF'
		recurInterval.value = 1
		recurMode.value = 1
	}
}, { immediate: true })

const recurBusy = computed(() =>
	createRecurRule.isPending.value || updateRecurRule.isPending.value || deleteRecurRule.isPending.value,
)

const recurUnitLabel = computed(() => {
	const i = Math.max(1, Number(recurInterval.value) || 1)
	switch (recurFreq.value) {
	case 'DAILY': return n('kanso', 'day', 'days', i)
	case 'WEEKLY': return n('kanso', 'week', 'weeks', i)
	case 'MONTHLY': return n('kanso', 'month', 'months', i)
	case 'YEARLY': return n('kanso', 'year', 'years', i)
	default: return ''
	}
})

function buildCardRrule(freq, interval) {
	const parts = [`FREQ=${freq}`]
	const i = Math.max(1, Number(interval) || 1)
	if (i > 1) parts.push(`INTERVAL=${i}`)
	return parts.join(';')
}

// Create / update / delete the card's rule to match the control. Optimism is left
// to the composable's cache invalidation; errors surface inline.
async function applyRecurrence() {
	recurError.value = ''
	const rule = cardRecurRule.value
	try {
		if (recurFreq.value === 'OFF') {
			if (rule) await deleteRecurRule.mutateAsync(rule.id)
			return
		}
		const rrule = buildCardRrule(recurFreq.value, recurInterval.value)
		if (rule) {
			const updates = { rrule }
			if (Number(rule.mode) !== recurMode.value) updates.mode = recurMode.value
			await updateRecurRule.mutateAsync({ id: rule.id, data: updates })
		} else {
			// For RESET mode (1): target is the card's own column — it resets in place.
			// For CLONE mode (0): target is the first board column (traditional clone landing).
			const cardStack = Number(cardData.value?.stackId)
			const firstStack = boardColumns.value[0]
			const targetStackId = recurMode.value === 1
				? cardStack
				: (firstStack?.id ?? cardStack)
			if (!targetStackId) {
				recurError.value = t('kanso', 'This card needs a column before it can repeat.')
				return
			}
			await createRecurRule.mutateAsync({
				templateCardId: Number(props.cardId),
				targetStackId,
				mode: recurMode.value,
				rrule,
				duedatePolicy: 0,
			})
		}
	} catch (err) {
		recurError.value = err?.response?.data?.error || t('kanso', 'Failed to update recurrence.')
	}
}

function onRecurFreqChange(value) {
	recurFreq.value = value
	if (value !== 'OFF' && !(recurInterval.value >= 1)) recurInterval.value = 1
	applyRecurrence()
}

function onRecurIntervalChange(value) {
	recurInterval.value = Math.max(1, parseInt(value, 10) || 1)
	if (recurFreq.value !== 'OFF') applyRecurrence()
}

const visibilityError = ref('')
async function handleVisibilityChange(visibility) {
	visibilityError.value = ''
	try {
		await updateCard.mutateAsync({ data: { visibility } })
	} catch (e) {
		visibilityError.value = t('kanso', 'Could not change the card visibility')
	}
}

const flatComments = computed(() => commentsQuery.data.value ?? [])
const commentThread = computed(() => buildCommentTree(flatComments.value))

// ── Scroll-to-comment deep links (#3870) ─────────────────────────────────────
// A reminder notification links to the card with a `#comment-<id>` fragment (see
// Notifier::cardLink). The fragment-free deep-link boot (main.js) stashes that id
// into the route query (`?comment=<id>`) before it hash-routes into the SPA, since
// the router.replace would otherwise clobber the raw fragment. We ALSO read
// window.location.hash directly as a fallback (in-app navigation that keeps the
// fragment). Once the thread has rendered we scroll the target into view and give
// it a brief highlight that fades. No-op when the fragment is absent or the comment
// isn't in the loaded thread (deleted / not yet loaded) - never throws.
const highlightedCommentId = ref(null)
let highlightTimer = null

function parseTargetCommentId() {
	// Prefer the query the boot handoff set; fall back to a raw location hash so
	// an in-app link that carries `#comment-<id>` still works.
	const fromQuery = route.query.comment
	const raw = Array.isArray(fromQuery) ? fromQuery[0] : fromQuery
	if (raw != null && /^\d+$/.test(String(raw))) return Number(raw)
	const m = /(?:^|#)comment-(\d+)\s*$/.exec(window.location.hash || '')
	return m ? Number(m[1]) : null
}

let scrollHandled = false

async function scrollToTargetComment() {
	if (scrollHandled) return
	const id = parseTargetCommentId()
	if (!id) return
	// The comment must actually be in the loaded thread; otherwise (deleted, or a
	// stale link) do nothing rather than scroll to a phantom element.
	if (!flatComments.value.some((c) => Number(c.id) === id)) return
	// Ensure the discussion tab (not activity) is showing so the node is rendered.
	discussionTab.value = 'discussion'
	// The v-for nodes are patched asynchronously after the query data lands, so a
	// single nextTick can run before the DOM node exists; poll a few frames for it
	// before giving up rather than silently no-op'ing on a slow first paint.
	let el = null
	for (let attempt = 0; attempt < 20 && !el; attempt++) {
		await nextTick()
		el = document.getElementById(`comment-${id}`)
		if (!el) await new Promise((r) => requestAnimationFrame(r))
	}
	if (!el) return
	scrollHandled = true
	// A collapsed discussion pane (#9854) still renders the node, so the scroll and
	// highlight below would "succeed" into a hidden element and a user arriving from
	// a mention or notification would land on a card showing nothing. Reveal the
	// pane. Deliberately NOT persisted: a one-off reveal for this deep link, not a
	// change to the saved preference. (Set here, after the first await, so it can
	// never run before the collapse state below is initialised.)
	discussionCollapsed.value = false
	// Defer one extra frame to let any async-loaded components (e.g. the Tiptap
	// MarkdownEditor chunk that loads on first open) finish painting and settle the
	// layout before we scroll. Without this wait the scroll runs before the editor
	// expands, the layout shifts, and the element ends up off-screen.
	await new Promise((r) => requestAnimationFrame(r))
	await new Promise((r) => requestAnimationFrame(r))
	el.scrollIntoView({ behavior: 'smooth', block: 'center' })
	highlightedCommentId.value = id
	if (highlightTimer) clearTimeout(highlightTimer)
	highlightTimer = setTimeout(() => {
		highlightedCommentId.value = null
		highlightTimer = null
	}, 4000)
}

// The thread loads async, so wait until comments actually arrive, then scroll.
// `immediate` covers the case where the query resolved before this watcher ran.
watch(
	() => flatComments.value.length,
	(len) => {
		if (len > 0) scrollToTargetComment()
	},
	{ immediate: true },
)
// Memoized per-comment rendered markdown, keyed by comment id. renderMarkdown is
// expensive; rendering inline in-template re-ran it on any modal re-render. This
// map only recomputes when the comments data actually changes.
const renderedComments = computed(() => {
	const map = new Map()
	const refs = cardRefMap.value
	for (const c of flatComments.value) {
		map.set(c.id, renderMarkdown(c.body, { refs }))
	}
	return map
})
const commentCount = computed(() => flatComments.value.length)

const commentError = ref('')
const newCommentBody = ref('')

async function submitNewComment() {
	const body = newCommentBody.value.trim()
	if (!body) return
	commentError.value = ''
	try {
		await addComment.mutateAsync({ body, parentCommentId: null })
		// Clear only after success so a failed post keeps the user's text.
		newCommentBody.value = ''
	} catch (err) {
		commentError.value = err?.response?.data?.error || t('kanso', 'Failed to post comment.')
	}
}

// Reply state
const replyingToId = ref(null)
const replyBody = ref('')
const replyRefs = {}

function setReplyRef(id, el) {
	if (el) replyRefs[id] = el
	else delete replyRefs[id]
}

/**
 * Build a markdown blockquote of `comment` for pre-filling a reply. Used when
 * replying to a comment that ISN'T the last one in the thread, so the reply
 * carries the context of what it answers. The body is truncated to keep the
 * quote compact. Display name is plain text (no @mention) to avoid an
 * unintended re-notification of the quoted author.
 * @param {object} comment the comment being quoted
 * @returns {string} markdown ending in a blank line, ready to type after
 */
function buildQuote(comment) {
	const author = comment.authorDisplayName || comment.author || t('kanso', 'Unknown')
	const raw = String(comment.body || '').trim()
	const MAX = 280
	const excerpt = raw.length > MAX ? `${raw.slice(0, MAX).trimEnd()}…` : raw
	const quotedBody = excerpt
		? excerpt.split('\n').map((line) => `> ${line}`).join('\n')
		: '>'
	return `> **${author}** ${t('kanso', 'wrote')}:\n${quotedBody}\n\n`
}

/**
 * Open the reply composer for a thread. The composer always lives at the END of
 * the thread (keyed on the thread root), no matter which comment you clicked
 * Reply on — and the reply is posted against the thread root so it lands last
 * (the server model is one level deep: replies point at the top-level comment).
 * Replying to a comment that isn't the last one pre-fills a quote of it.
 * @param {object} target the comment the user clicked Reply on
 * @param {object} root the thread's top-level comment
 */
async function openReplyBox(target, root) {
	const rootId = root?.id ?? target.id
	replyingToId.value = rootId
	const thread = commentThread.value.find((th) => th.comment.id === rootId)
	const last = thread && thread.replies.length ? thread.replies[thread.replies.length - 1] : thread?.comment
	const quote = last && target.id !== last.id ? buildQuote(target) : ''
	replyBody.value = quote
	await nextTick()
	// The reply ref is now a MarkdownEditor component (exposes focus/setContent).
	// setContent pre-fills the editor with the blockquote and moves caret to end.
	const editorRef = replyRefs[rootId]
	if (editorRef) {
		if (quote && typeof editorRef.setContent === 'function') {
			editorRef.setContent(quote)
		} else if (typeof editorRef.focus === 'function') {
			editorRef.focus('end')
		}
	}
}

function closeReplyBox() {
	replyingToId.value = null
	replyBody.value = ''
}

async function submitReply(parentCommentId) {
	const body = replyBody.value.trim()
	if (!body) return
	commentError.value = ''
	try {
		await addComment.mutateAsync({ body, parentCommentId })
		// Clear + close the reply box only after success (keep text on failure).
		replyBody.value = ''
		replyingToId.value = null
	} catch (err) {
		commentError.value = err?.response?.data?.error || t('kanso', 'Failed to post reply.')
	}
}

// Inline comment edit state
const editingCommentId = ref(null)
const editingCommentBody = ref('')
const commentEditRefs = {}

function setCommentEditRef(id, el) {
	if (el) commentEditRefs[id] = el
	else delete commentEditRefs[id]
}

async function startCommentEdit(comment) {
	editingCommentId.value = comment.id
	editingCommentBody.value = comment.body
	await nextTick()
	commentEditRefs[comment.id]?.focus()
}

function cancelCommentEdit() {
	editingCommentId.value = null
	editingCommentBody.value = ''
}

async function saveCommentEdit(comment) {
	const body = editingCommentBody.value.trim()
	if (!body || body === comment.body) {
		cancelCommentEdit()
		return
	}
	commentError.value = ''
	try {
		await editComment.mutateAsync({ comment, body })
	} catch (err) {
		commentError.value = err?.response?.data?.error || t('kanso', 'Failed to edit comment.')
	} finally {
		cancelCommentEdit()
	}
}

async function handleDeleteComment(comment) {
	commentError.value = ''
	try {
		await deleteComment.mutateAsync({ comment })
	} catch (err) {
		commentError.value = err?.response?.data?.error || t('kanso', 'Failed to delete comment.')
	}
}

// ── Copy as prompt ───────────────────────────────────────────────────────────
// Copies the card title + description + full comment thread as a single
// markdown block, ready to paste into an LLM. Comments are lazily loaded, so we
// ensure the query has resolved before assembling the text.
const copyingPrompt = ref(false)

async function copyAsPrompt() {
	if (copyingPrompt.value) return
	copyingPrompt.value = true
	try {
		// Make sure the (lazy) comments query has data before assembling.
		let comments = commentsQuery.data.value
		if (!Array.isArray(comments)) {
			const result = await commentsQuery.refetch()
			comments = result?.data ?? commentsQuery.data.value ?? []
		}

		const prompt = buildCardPrompt(cardData.value ?? {}, comments)

		if (!navigator.clipboard?.writeText) {
			showError(t('kanso', 'Clipboard is not available in this context.'))
			return
		}
		await navigator.clipboard.writeText(prompt)
		showSuccess(t('kanso', 'Card copied as prompt.'))
	} catch (err) {
		showError(t('kanso', 'Could not copy to clipboard.'))
	} finally {
		copyingPrompt.value = false
	}
}

// ── Copy to… / Move to board… (relocate card into a target board/stack) ──────
// The same picker UI serves both: 'copy' duplicates the card, 'move' relocates
// it (server-side single-card cross-board move, #3679).
const showCopyDialog = ref(false)
const copyDialogMode = ref('copy')
const copyDialogIsMove = computed(() => copyDialogMode.value === 'move')
const copyTargetBoardId = ref(null)
const copyTargetStackId = ref('')
const copyStackOptions = ref([])
const copyStacksLoading = ref(false)
const copyPending = ref(false)
const copyError = ref('')

// All boards the user can see - the picker offers every board; a copy/move into
// one the user cannot EDIT is rejected server-side and surfaced as copyError.
// Move mode excludes the card's current board (a same-board "move to board" is
// meaningless - the server rejects it too).
const { data: allBoardsData } = useBoards()
const copyBoardOptions = computed(() =>
	(allBoardsData.value ?? [])
		.filter((b) => !b.archived)
		.filter((b) => !copyDialogIsMove.value || Number(b.id) !== Number(boardId.value)),
)

const copyIsCrossBoard = computed(() =>
	copyTargetBoardId.value != null && Number(copyTargetBoardId.value) !== Number(boardId.value),
)

async function openCopyDialog() {
	copyDialogMode.value = 'copy'
	copyError.value = ''
	copyTargetStackId.value = ''
	// Default the target to the card's current board so the common case (copy
	// within the same board) is one click away.
	copyTargetBoardId.value = Number(boardId.value)
	showCopyDialog.value = true
	await loadCopyStacks(Number(boardId.value))
}

async function openMoveToBoardDialog() {
	copyDialogMode.value = 'move'
	copyError.value = ''
	copyTargetStackId.value = ''
	// Move targets another board; default to the first eligible one (the current
	// board is excluded from the options). No default when none exist.
	const first = copyBoardOptions.value[0]
	copyTargetBoardId.value = first ? Number(first.id) : null
	showCopyDialog.value = true
	if (copyTargetBoardId.value != null) {
		await loadCopyStacks(copyTargetBoardId.value)
	} else {
		copyStackOptions.value = []
		copyError.value = t('kanso', 'There is no other board you can move this card to.')
	}
}

function onCopyBoardChange() {
	copyTargetStackId.value = ''
	loadCopyStacks(Number(copyTargetBoardId.value))
}

// Load the target board's stacks for the column picker. The current board's
// stacks come from cache; another board is fetched on demand.
async function loadCopyStacks(targetBoardId) {
	copyError.value = ''
	copyStacksLoading.value = true
	copyStackOptions.value = []
	try {
		let stacks
		if (Number(targetBoardId) === Number(boardId.value) && Array.isArray(boardData.value?.stacks)) {
			stacks = boardData.value.stacks
		} else {
			const board = await apiFetchBoard(targetBoardId)
			stacks = board?.stacks ?? []
		}
		copyStackOptions.value = stacks
			.slice()
			.sort((a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0))
		// Preselect the card's current column when copying within the same board.
		if (Number(targetBoardId) === Number(boardId.value) && cardData.value?.stackId != null) {
			copyTargetStackId.value = cardData.value.stackId
		} else if (copyStackOptions.value.length > 0) {
			copyTargetStackId.value = copyStackOptions.value[0].id
		}
	} catch (err) {
		copyError.value = err?.response?.data?.error || t('kanso', 'You cannot copy to that board.')
	} finally {
		copyStacksLoading.value = false
	}
}

async function confirmCopy() {
	const targetStackId = Number(copyTargetStackId.value)
	if (!targetStackId || copyPending.value) return
	copyError.value = ''
	copyPending.value = true
	try {
		const targetBoard = Number(copyTargetBoardId.value)
		const newCard = await apiCopyCard(Number(props.cardId), targetStackId)
		// Refresh the target board so the duplicate appears; also refresh the
		// source board (unchanged, but keeps caches consistent) and the boards
		// list (its per-board card counts now include the copy).
		queryClient.invalidateQueries({ queryKey: boardQueryKey(targetBoard) })
		queryClient.invalidateQueries({ queryKey: boardQueryKey(boardId.value) })
		queryClient.invalidateQueries({ queryKey: ['boards'] })
		showCopyDialog.value = false
		showSuccess(t('kanso', 'Card copied.'))
		// Same-board copy: jump straight to the new card. Cross-board: leave the
		// user where they are (a full board switch would be jarring mid-flow).
		if (targetBoard === Number(boardId.value) && newCard?.id != null) {
			openCard(newCard.id)
		}
	} catch (err) {
		copyError.value = err?.response?.data?.error || t('kanso', 'Failed to copy card.')
	} finally {
		copyPending.value = false
	}
}

async function confirmMoveToBoard() {
	const targetStackId = Number(copyTargetStackId.value)
	if (!targetStackId || copyPending.value) return
	copyError.value = ''
	copyPending.value = true
	try {
		const targetBoard = Number(copyTargetBoardId.value)
		await apiMoveCardToBoard(Number(props.cardId), targetStackId)
		// The card left this board and landed on the target: refresh BOTH boards
		// (source loses it, target gains it) and the boards list (per-board counts).
		queryClient.invalidateQueries({ queryKey: boardQueryKey(targetBoard) })
		queryClient.invalidateQueries({ queryKey: boardQueryKey(boardId.value) })
		queryClient.invalidateQueries({ queryKey: ['boards'] })
		// A cross-board move changes the board context the My Work feeds render,
		// and can drop the card from a feed entirely (#3766).
		invalidateCrossBoardFeeds(queryClient)
		showCopyDialog.value = false
		showSuccess(t('kanso', 'Card moved.'))
		// The card no longer exists on this board (its id changed on the target),
		// so close the modal rather than leave a stale/404 detail open.
		closeModal()
	} catch (err) {
		copyError.value = err?.response?.data?.error || t('kanso', 'Failed to move card.')
	} finally {
		copyPending.value = false
	}
}

/**
 * Format a unix timestamp as a relative time string (e.g. "2 hours ago").
 * Falls back to a locale date string for older timestamps.
 * @param {number} unixTs seconds since epoch
 * @return {string} relative time label
 */
function formatCommentTime(unixTs) {
	if (!unixTs) return ''
	const now = Date.now()
	const ms = unixTs * 1000
	const diffSec = Math.floor((now - ms) / 1000)
	if (diffSec < 60) return t('kanso', 'just now')
	if (diffSec < 3600) return t('kanso', '{n} min ago', { n: Math.floor(diffSec / 60) })
	if (diffSec < 86400) return t('kanso', '{n} hr ago', { n: Math.floor(diffSec / 3600) })
	if (diffSec < 86400 * 7) return t('kanso', '{n} days ago', { n: Math.floor(diffSec / 86400) })
	return new Date(ms).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

// My Work surfaces open a card with `?from=<routeName>` so we can return there on
// close instead of dumping the user on the board. Board-opened / deep-linked cards
// have no `from` and still close to the board (do not regress that flow).
const MY_WORK_RETURN_ROUTES = ['my-work', 'my-cards', 'my-reviews', 'inbox']

function closeModal() {
	isOpen.value = false
	// Controlled overlay (#3950): the parent owns open/close and the surrounding
	// URL (e.g. a View at /views/:id). Do NOT navigate — just tell the parent to
	// tear the overlay down, leaving the user exactly where they were.
	if (props.controlled) {
		emit('close')
		return
	}
	const from = route.query.from
	if (MY_WORK_RETURN_ROUTES.includes(from)) {
		// Preserve the hub tab when returning to the /my-work hub.
		const query = from === 'my-work' && route.query.tab ? { tab: route.query.tab } : undefined
		router.push({ name: from, query })
		emit('close')
		return
	}
	// Back to the card's board. On the modal route this is route.params.id; on the
	// full-page route (no board id in the URL) it comes from the loaded card's
	// boardId (the "back to board" affordance). Only navigate once we know it.
	const bId = boardId.value
	if (bId != null) {
		router.push({ name: 'board', params: { id: String(bId) } })
	}
	emit('close')
}

// Escape hatch from a dead card link (#3662): leave the (possibly gone) board
// entirely. Return to the My Work hub if that's where the user came from,
// otherwise fall back to the boards list - never route back into a board that
// may itself no longer exist.
function goToBoards() {
	isOpen.value = false
	// Controlled overlay (#3950): no board to route to — the parent surface owns
	// navigation. Just close the overlay in place.
	if (props.controlled) {
		emit('close')
		return
	}
	const from = route.query.from
	if (MY_WORK_RETURN_ROUTES.includes(from)) {
		const query = from === 'my-work' && route.query.tab ? { tab: route.query.tab } : undefined
		router.push({ name: from, query })
		return
	}
	router.push({ name: 'board-list' })
}

// Escape at the card root: an open attribute popover takes precedence — close
// it, not the whole card (which would discard an in-progress edit). Inline edits
// (title/description/comment) stop propagation themselves, so they never reach here.
// In modal mode Escape closes the card; on the full page there is nothing to close
// to on Escape (the browser back / the explicit back-to-board button own that), so
// Escape only ever dismisses an open popover.
function onRootEscape() {
	if (openPicker.value !== null) {
		openPicker.value = null
		return
	}
	if (props.mode === 'modal') {
		closeModal()
	}
}

// Close the card when the dark backdrop is clicked (not just the X). NcModal's own
// close-on-click-outside prop is unreliable in @nextcloud/vue 9, and its teleported
// wrapper mounts a tick later than this component, so a direct listener attach races
// the transition. Instead we listen on `document` (capture) and act only when the
// mousedown TARGET is our modal's own .modal-wrapper (the dark backdrop). The
// target-is-wrapper check is the drag guard: a text-selection drag that STARTS on
// inner content has target inside .modal-container, so releasing on the backdrop
// never closes the card. The handler funnels through onModalClose so it obeys the
// same picker-first precedence as Escape.
//
// It also carries the click-outside dismiss for the attribute popovers (#3665):
// when ANY picker is open, a mousedown that lands outside the popover AND outside the
// picker's own (open) trigger clears openPicker — one shared path keyed on openPicker,
// no per-picker wiring. This runs BEFORE the backdrop-close check so it holds the same
// picker-first precedence as Escape: an outside click on the backdrop closes the PICKER,
// not the whole card. The active trigger is excluded via aria-expanded="true" so the
// same click that would toggle it shut doesn't get double-handled (mousedown closes,
// then the trigger's click re-opens); the popover interior (options, date input,
// mention textareas) is excluded so selecting/typing never closes it prematurely.
function onDocumentMousedown(event) {
	const target = event.target
	if (!(target instanceof Element)) {
		return
	}
	if (openPicker.value !== null) {
		if (!target.closest('.card-modal__popover') && !target.closest('[aria-expanded="true"]')) {
			openPicker.value = null
			return
		}
		// A picker is open and the click is on its trigger or inside the popover —
		// leave it to the element's own handler; never fall through to backdrop-close.
		return
	}
	// Backdrop-close only applies to the modal shell. The full page has no dark
	// backdrop to click, so the popover-dismiss above is all this handler does there.
	if (props.mode !== 'modal') {
		return
	}
	if (!target.classList.contains('modal-wrapper')) {
		return
	}
	// Scope to THIS card's modal (guard against other stacked NcModals on the page).
	if (!target.closest('.card-modal-modal')) {
		return
	}
	requestClose()
}
onMounted(() => {
	document.addEventListener('mousedown', onDocumentMousedown, true)
})
onBeforeUnmount(() => {
	document.removeEventListener('mousedown', onDocumentMousedown, true)
	if (highlightTimer) clearTimeout(highlightTimer)
})

// The modal shell funnels its X button here, and the backdrop handler above does
// too. Mirror onRootEscape's precedence: if an attribute popover is open, dismiss
// it first rather than closing the whole card (which would drop the picker context).
// Exposed to the shell as requestClose() so the modal's close button obeys the same
// popover-first precedence as Escape / the backdrop.
function requestClose() {
	if (openPicker.value !== null) {
		openPicker.value = null
		return
	}
	closeModal()
}

defineExpose({ requestClose })

// ── Card hierarchy (parent / sub-cards) ─────────────────────────────────────
const { setParent, clearParent, addChild, setParentMutation, addChildMutation } =
	useCardHierarchy(boardId)

const hierarchyError = ref('')
const newChildTitle = ref('')
const addChildInputRef = ref(null)

// Children list from card detail (summary objects with id, title, doneAt, stackId, archived)
const children = computed(() =>
	Array.isArray(cardData.value?.children) ? cardData.value.children : [],
)

const childrenDone = computed(() =>
	children.value.filter((c) => Number(c.doneAt) > 0).length,
)

// Parent title: look up the parent's title from the board cache (fast path),
// falling back to a generic label so we never render an undefined value.
const parentTitle = computed(() => {
	const parentId = cardData.value?.parentCardId
	if (!parentId) return ''
	const boardCards = boardData.value?.cards ?? []
	const parentCard = boardCards.find((c) => c.id === Number(parentId))
	return parentCard?.title || t('kanso', 'Parent card')
})

/**
 * Navigate to another card within the same shell: a sub-card / cross-reference
 * click keeps you in the modal overlay when opened from the modal, and on the
 * full page when opened from the page (so the shell never flips underneath you).
 * @param {number|string} cardId target card id
 */
function openCard(cardId) {
	// Controlled overlay (#3950): there is no card-modal route to push to (the View
	// URL carries no board id — route.params.id is undefined here). Hand the target
	// card up so the parent re-opens the overlay on it, staying in the View.
	if (props.controlled) {
		emit('navigate', String(cardId))
		return
	}
	if (props.mode === 'page') {
		router.push({ name: 'card-page', params: { cardId: String(cardId) } })
		return
	}
	router.push({ name: 'card-modal', params: { id: route.params.id, cardId: String(cardId) } })
}

/**
 * Expand the modal into the standalone full-page card view (#3817). Only wired
 * from the modal header. Preserves any `?from` origin so the page's back-to-board
 * / My Work return stays correct after the switch.
 */
function expandToPage() {
	const query = route.query.from ? { from: route.query.from, ...(route.query.tab ? { tab: route.query.tab } : {}) } : undefined
	router.push({ name: 'card-page', params: { cardId: String(props.cardId) }, query })
}

/**
 * Delegated click handler for rendered markdown containers: a click on a
 * `KAN-123` cross-reference anchor (class kanso-cardref) opens the target card
 * in the modal. The anchor carries no href, so this is the only navigation path.
 * @param {MouseEvent} event the click event
 */
function handleRefClick(event) {
	// A clicked @mention chip opens that user's Nextcloud profile in a new tab
	// (keeps the card modal / in-progress edit intact). The chip carries no
	// href; the uid comes from data-kanso-mention (set + sanitized in markdown.js).
	const mention = event.target?.closest?.('.kanso-mention[data-kanso-mention]')
	if (mention) {
		const uid = mention.getAttribute('data-kanso-mention')
		if (uid) {
			event.preventDefault()
			event.stopPropagation()
			window.open(generateUrl('/u/{uid}', { uid }), '_blank', 'noopener,noreferrer')
		}
		return
	}

	const anchor = event.target?.closest?.('a.kanso-cardref')
	if (!anchor) return
	const cardId = anchor.getAttribute('data-kanso-card-id')
	if (!cardId) return
	event.preventDefault()
	event.stopPropagation()
	openCard(cardId)
}

async function handleClearParent() {
	hierarchyError.value = ''
	const currentParentId = cardData.value?.parentCardId ?? null
	try {
		await clearParent(Number(props.cardId), currentParentId ? Number(currentParentId) : null)
	} catch (err) {
		hierarchyError.value = err?.response?.data?.error || t('kanso', 'Failed to detach from parent.')
	}
}

async function handleDetachChild(child) {
	hierarchyError.value = ''
	try {
		await clearParent(Number(child.id), Number(props.cardId))
	} catch (err) {
		hierarchyError.value = err?.response?.data?.error || t('kanso', 'Failed to detach sub-card.')
	}
}

async function handleAddChild() {
	const title = newChildTitle.value.trim()
	if (!title) return
	hierarchyError.value = ''
	// Clear immediately for rapid entry; restore on failure only if the field
	// is still empty (the user hasn't started typing the next sub-card).
	newChildTitle.value = ''
	try {
		await addChild(
			{ id: Number(props.cardId), stackId: cardData.value?.stackId },
			title,
		)
	} catch (err) {
		hierarchyError.value = err?.response?.data?.error || t('kanso', 'Failed to add sub-card.')
		if (newChildTitle.value === '') newChildTitle.value = title
	}
	// Keep focus for rapid entry
	await nextTick()
	addChildInputRef.value?.focus()
}

// Cards eligible to link as a child of THIS card. One-level rule: a candidate
// must be a top-level card (no parent) that is not itself a parent, not this
// card, not archived, and not already one of its children.
const availableChildCards = computed(() => {
	const cards = boardData.value?.cards ?? []
	const selfId = Number(props.cardId)
	const existingChildIds = new Set(children.value.map((c) => Number(c.id)))
	return cards.filter((c) =>
		c.id !== selfId
		&& !c.archived
		&& !existingChildIds.has(Number(c.id))
		&& c.parentCardId == null
		&& !(c.childProgress && c.childProgress.total > 0),
	)
})

// Cards eligible to be THIS card's parent - any top-level card (no parent) other
// than itself. Only offered when this card has no children of its own.
const availableParentCards = computed(() => {
	const cards = boardData.value?.cards ?? []
	const selfId = Number(props.cardId)
	return cards.filter((c) =>
		c.id !== selfId
		&& !c.archived
		&& c.parentCardId == null,
	)
})

// Hierarchy + relation editors are revealed on demand from the ⋯ menu, not
// shown inline by default (an empty "No eligible cards" box is noise).
const showRelationEditor = ref(false)
const showLinkChild = ref(false)
const showSetParent = ref(false)
const linkChildTargetId = ref('')
const setParentTargetId = ref('')

function openRelationEditor() {
	showRelationEditor.value = true
}
function openLinkChildEditor() {
	linkChildTargetId.value = ''
	showLinkChild.value = true
}
function openSetParentEditor() {
	setParentTargetId.value = ''
	showSetParent.value = true
}

async function confirmLinkChild() {
	const id = Number(linkChildTargetId.value)
	if (!id) return
	const card = availableChildCards.value.find((c) => Number(c.id) === id)
	hierarchyError.value = ''
	try {
		await setParent(id, Number(props.cardId), card?.parentCardId != null ? Number(card.parentCardId) : null)
		linkChildTargetId.value = ''
		showLinkChild.value = false
	} catch (err) {
		hierarchyError.value = err?.response?.data?.error || t('kanso', 'Failed to link card.')
	}
}

async function confirmSetParent() {
	const id = Number(setParentTargetId.value)
	if (!id) return
	hierarchyError.value = ''
	try {
		await setParent(
			Number(props.cardId),
			id,
			cardData.value?.parentCardId != null ? Number(cardData.value.parentCardId) : null,
		)
		setParentTargetId.value = ''
		showSetParent.value = false
	} catch (err) {
		hierarchyError.value = err?.response?.data?.error || t('kanso', 'Failed to set parent.')
	}
}

// ── GitHub links ─────────────────────────────────────────────────────────────
const { links: cardLinksData, addLink, removeLink } = useCardLinks(computed(() => props.cardId))
const cardLinks = computed(() => cardLinksData.value ?? [])
const newLinkUrl = ref('')
const linkError = ref('')
const branchCopied = ref(false)

function linkStateLabel(state) {
	switch (state) {
	case 'open': return t('kanso', 'Open')
	case 'merged': return t('kanso', 'Merged')
	case 'closed': return t('kanso', 'Closed')
	default: return t('kanso', 'Link')
	}
}

async function handleAddLink() {
	const value = newLinkUrl.value.trim()
	if (!value) return
	linkError.value = ''
	try {
		await addLink.mutateAsync(value)
		newLinkUrl.value = ''
	} catch (e) {
		linkError.value = e?.response?.data?.error || t('kanso', 'Only https://github.com links are supported')
	}
}

async function handleRemoveLink(linkId) {
	linkError.value = ''
	try {
		await removeLink.mutateAsync(linkId)
	} catch (e) {
		linkError.value = e?.response?.data?.error || t('kanso', 'Failed to remove link.')
	}
}

async function copyBranchName() {
	const name = branchName(props.cardId, cardData.value?.title || '')
	try {
		await navigator.clipboard.writeText(name)
		branchCopied.value = true
		setTimeout(() => { branchCopied.value = false }, 1500)
	} catch (e) {
		linkError.value = t('kanso', 'Could not copy to clipboard')
	}
}

// ── File attachments (#3526) ─────────────────────────────────────────────────
const { attachments: cardAttachmentsData, uploadAttachment, removeAttachment } = useCardAttachments(computed(() => props.cardId))
const cardAttachments = computed(() => cardAttachmentsData.value ?? [])
const attachmentInput = ref(null)
const attachmentError = ref('')

function attachmentHref(attachmentId) {
	return cardAttachmentUrl(props.cardId, attachmentId)
}

function formatBytes(bytes) {
	const n = Number(bytes) || 0
	if (n < 1024) return `${n} B`
	const units = ['KB', 'MB', 'GB', 'TB']
	let value = n / 1024
	let i = 0
	while (value >= 1024 && i < units.length - 1) {
		value /= 1024
		i++
	}
	return `${value.toFixed(value < 10 ? 1 : 0)} ${units[i]}`
}

function triggerAttachmentPick() {
	attachmentError.value = ''
	attachmentInput.value?.click()
}

async function handleAttachmentPick(event) {
	const file = event.target.files?.[0]
	// Reset so picking the same file again re-fires change.
	event.target.value = ''
	if (!file) return
	attachmentError.value = ''
	try {
		await uploadAttachment.mutateAsync(file)
	} catch (e) {
		attachmentError.value = e?.response?.data?.error || t('kanso', 'Failed to upload attachment.')
	}
}

async function handleRemoveAttachment(attachmentId) {
	attachmentError.value = ''
	try {
		await removeAttachment.mutateAsync(attachmentId)
	} catch (e) {
		attachmentError.value = e?.response?.data?.error || t('kanso', 'Failed to remove attachment.')
	}
}

// ── Time tracking (#3536) ────────────────────────────────────────────────────
const { timeEntries: cardTimeEntriesData, addEntry, removeEntry } = useCardTimeEntries(computed(() => props.cardId))
const cardTimeEntries = computed(() => cardTimeEntriesData.value ?? [])
const timeDurationInput = ref('')
const timeNoteInput = ref('')
const timeEntryError = ref('')

// The per-card total comes from the card DETAIL payload (kept off the board
// summaries); the entries list only backs the breakdown and the delete buttons.
const timeSpentTotal = computed(() => Number(cardData.value?.timeSpent) || 0)

// Live running-timer counter (#73): ticks every second while a running timer
// is present on the card. The ref is created once and the interval is kept in
// sync with the timer's presence (card changes without unmounting the detail).
const timerNow = ref(Math.floor(Date.now() / 1000))
const timerElapsed = computed(() => {
	const startedAt = cardData.value?.runningTimer?.startedAt
	if (!startedAt) return 0
	return Math.max(0, timerNow.value - startedAt)
})

let timerInterval = null

function syncTimerInterval() {
	// Only the visible counter needs the tick. With time tracking switched off
	// (#5894) the row is not rendered, so skip the interval entirely - the timer
	// itself is untouched and keeps running server-side.
	const hasTimer = !!(cardData.value?.runningTimer) && cardFeatures.value.timeTracking
	if (hasTimer && timerInterval === null) {
		timerInterval = setInterval(() => {
			timerNow.value = Math.floor(Date.now() / 1000)
		}, 1000)
	} else if (!hasTimer && timerInterval !== null) {
		clearInterval(timerInterval)
		timerInterval = null
	}
}

// Start/stop the interval whenever the running timer appears or disappears.
watch([() => cardData.value?.runningTimer, () => cardFeatures.value.timeTracking], syncTimerInterval, { immediate: true })

// Human-readable duration: 5400 → "1h 30m", 45 → "45s", 0 → "0m".
function formatDuration(totalSeconds) {
	const secs = Math.max(0, Math.floor(Number(totalSeconds) || 0))
	if (secs === 0) return '0m'
	const h = Math.floor(secs / 3600)
	const m = Math.floor((secs % 3600) / 60)
	const s = secs % 60
	const parts = []
	if (h > 0) parts.push(`${h}h`)
	if (m > 0) parts.push(`${m}m`)
	// Only surface bare seconds when there is no hour/minute component.
	if (s > 0 && h === 0 && m === 0) parts.push(`${s}s`)
	return parts.join(' ')
}

// Parses "1h 30m", "90m", "1.5h", "45s", or a bare number (minutes) into
// seconds. Returns 0 for anything unparseable.
function parseDuration(raw) {
	const input = String(raw ?? '').trim().toLowerCase()
	if (input === '') return 0
	// A bare number is interpreted as minutes.
	if (/^\d+(\.\d+)?$/.test(input)) {
		return Math.round(parseFloat(input) * 60)
	}
	const re = /(\d+(?:\.\d+)?)\s*(h|m|s)/g
	let match
	let seconds = 0
	let matched = false
	while ((match = re.exec(input)) !== null) {
		matched = true
		const value = parseFloat(match[1])
		if (match[2] === 'h') seconds += value * 3600
		else if (match[2] === 'm') seconds += value * 60
		else seconds += value
	}
	return matched ? Math.round(seconds) : 0
}

async function handleAddTimeEntry() {
	timeEntryError.value = ''
	const seconds = parseDuration(timeDurationInput.value)
	if (seconds <= 0) {
		timeEntryError.value = t('kanso', 'Enter a duration, e.g. 1h 30m.')
		return
	}
	try {
		await addEntry.mutateAsync({ seconds, note: timeNoteInput.value.trim() || null })
		timeDurationInput.value = ''
		timeNoteInput.value = ''
		// Refresh the card detail so the total (timeSpent) reflects the new entry.
		queryClient.invalidateQueries({ queryKey: ['card', props.cardId] })
	} catch (e) {
		timeEntryError.value = e?.response?.data?.error || t('kanso', 'Failed to add time entry.')
	}
}

async function handleRemoveTimeEntry(entryId) {
	timeEntryError.value = ''
	try {
		await removeEntry.mutateAsync(entryId)
		queryClient.invalidateQueries({ queryKey: ['card', props.cardId] })
	} catch (e) {
		timeEntryError.value = e?.response?.data?.error || t('kanso', 'Failed to remove time entry.')
	}
}

// ── Subscription (Watch / Unwatch) ───────────────────────────────────────────
const { toggle: toggleSubscription, toggleOther: toggleOtherSubscription } = useSubscription(computed(() => props.cardId))

const subscription = computed(() => cardData.value?.subscription ?? { subscribed: false, subscribers: [], count: 0 })
const isWatching = computed(() => subscription.value.subscribed === true)
const watcherCount = computed(() => Number(subscription.value.count) || 0)

const subscriptionError = ref('')

async function handleWatchToggle() {
	subscriptionError.value = ''
	const next = !isWatching.value
	try {
		await toggleSubscription.mutateAsync({ subscribed: next })
	} catch (err) {
		subscriptionError.value = err?.response?.data?.error || t('kanso', 'Failed to update watch status.')
	}
}

// Watchers managed by an EDIT user: add/remove OTHER board participants.
const watcherError = ref('')
const watcherIds = computed(() =>
	Array.isArray(subscription.value.subscribers) ? subscription.value.subscribers : [],
)
// Pills shown in the attribute bar exclude the current user — self-watch is
// managed exclusively by the header Watch toggle (avoids a duplicate pill and a
// header/pill state desync).
const displayedWatcherIds = computed(() => watcherIds.value.filter((uid) => uid !== currentUserId))
const unwatchedParticipants = computed(() => {
	const list = Array.isArray(participants.data.value) ? participants.data.value : []
	const watching = new Set(watcherIds.value)
	// The actor manages themselves via the header Watch toggle.
	return list.filter((p) => !watching.has(p.uid) && p.uid !== currentUserId)
})

async function handleToggleWatcher(uid, subscribe) {
	watcherError.value = ''
	openPicker.value = null
	try {
		await toggleOtherSubscription.mutateAsync({ userId: uid, subscribed: subscribe })
	} catch (err) {
		watcherError.value = err?.response?.data?.error || t('kanso', 'Failed to update watchers.')
	}
}

// ── Card relations (blocks / blocked-by / duplicates / relates) ──────────────
const queryClient = useQueryClient()

// ── Keep the Activity feed live while its tab is open ────────────────────────
// The activity query (['card-activity', String(cardId)], staleTime 0) only
// refetches on tab (re)mount. Every change-log-appending mutation already
// invalidates the card query (['card', id]) and/or the board query, and the
// realtime path (main.js) invalidates the board — so instead of wiring a
// card-activity invalidation into all ~15 mutation composables, we listen to
// the query cache here and refresh the feed whenever the card or its board is
// updated. This covers both local edits and incoming realtime changes with no
// extra poll (comments already refresh via useComments; this scopes to the
// other verbs). The board branch is deliberately coarse: it refetches the feed
// on ANY board update (the realtime case only invalidates the board, never the
// card), but this is bounded — it fires only while the Activity tab is open,
// for a single card.
//
// The card query key is NOT uniformly typed: useCard registers ['card', <raw>]
// (numeric) while other composables use ['card', String(id)]. A hard-coded key
// match would silently drop half the mutations, so we coerce both sides of the
// id comparison (cf. #3576 key-type trap). The subscription only lives while
// the Activity tab is open, and is torn down on close/unmount.
let stopActivityCacheSync = null
function startActivityCacheSync() {
	if (stopActivityCacheSync) return
	stopActivityCacheSync = queryClient.getQueryCache().subscribe((event) => {
		// Only react to a query actually settling with new/invalidated data;
		// ignore observer add/remove churn and the activity query's own events.
		if (event?.type !== 'updated') return
		const key = event.query?.queryKey
		if (!Array.isArray(key) || key.length < 2) return
		const [kind, id] = key
		const matchesCard = kind === 'card' && String(id) === String(props.cardId)
		const matchesBoard = kind === 'board' && String(id) === String(boardId.value)
		if (!matchesCard && !matchesBoard) return
		// Rebuild the key at fire time — cardId can change while the modal stays
		// mounted (navigating parent/child), and the tab may still be open.
		queryClient.invalidateQueries({ queryKey: ['card-activity', String(props.cardId)] })
	})
}
function stopActivityCacheSyncFn() {
	if (stopActivityCacheSync) {
		stopActivityCacheSync()
		stopActivityCacheSync = null
	}
}
watch(discussionTab, (tab) => {
	if (tab === 'activity') {
		startActivityCacheSync()
	} else {
		stopActivityCacheSyncFn()
	}
}, { immediate: true })
onBeforeUnmount(stopActivityCacheSyncFn)

// ── Same-board move (⋯ menu "Move to top/bottom" + keyboard "Move card…") ─────
// Both the edge shortcuts and the keyboard/SR position picker funnel through the
// ONE optimistic move path (useCardMove.enqueueMove) — the same code DnD uses —
// so there is never a second move implementation to keep in sync.
const moveError = ref('')
const { enqueueMove, lastError: enqueueMoveError } = useCardMove(boardId)
const { announce: announceMove } = useAnnouncer()

/** Non-archived cards in a stack, self excluded, sorted by fractional key. */
function stackCardsSorted(stackId) {
	const selfId = Number(props.cardId)
	return (boardData.value?.cards ?? [])
		.filter((c) => c.stackId === stackId && !c.archived && c.id !== selfId)
		.sort((a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0))
}

/**
 * Move this card within the board via the shared optimistic queue. afterCardId
 * null → top of the target stack; otherwise land right after that card. Computes
 * the same optimistic fractional key BoardView derives for a drop, so the card
 * appears in the right spot before the server responds. Announces to SR.
 *
 * @param {number} targetStackId
 * @param {?number} afterCardId  card to land after, or null for top
 */
function moveWithinBoard(targetStackId, afterCardId) {
	moveError.value = ''
	const selfId = Number(props.cardId)
	const inStack = stackCardsSorted(targetStackId)

	// Derive optimisticKey mirroring BoardView's drop math.
	let optimisticKey
	try {
		if (afterCardId == null) {
			// Top: before the first card, or the only card in an empty stack.
			const first = inStack[0]
			optimisticKey = first ? before(first.sortKey) : initial()
		} else {
			const idx = inStack.findIndex((c) => c.id === afterCardId)
			const anchor = inStack[idx]
			const next = idx >= 0 ? inStack[idx + 1] : null
			optimisticKey = next ? between(anchor.sortKey, next.sortKey) : after(anchor.sortKey)
		}
	} catch {
		// Keys too close/overflow → let the server truth win on reconcile.
		optimisticKey = afterCardId == null
			? (inStack[0]?.sortKey ?? initial())
			: (inStack.find((c) => c.id === afterCardId)?.sortKey ?? initial())
	}

	enqueueMove({ cardId: selfId, targetStackId, afterCardId: afterCardId ?? null, optimisticKey })
	queryClient.invalidateQueries({ queryKey: ['card', props.cardId] })

	const stackTitle = (boardData.value?.stacks ?? []).find((s) => s.id === targetStackId)?.title ?? ''
	announceMove(t('kanso', '{card} moved to {stack}', {
		card: cardData.value?.title || t('kanso', 'Card'),
		stack: stackTitle,
	}))
}

// afterCardId=null → top; last card in the stack → bottom.
function moveToEdge(toTop) {
	const stackId = cardData.value?.stackId
	if (stackId == null) return
	const inStack = stackCardsSorted(stackId)
	if (toTop) {
		if (inStack.length === 0) return // already the only card → top & bottom
		moveWithinBoard(stackId, null)
	} else {
		if (inStack.length === 0) return
		moveWithinBoard(stackId, inStack[inStack.length - 1].id)
	}
}

// ── Keyboard / SR "Move card…" picker (a11y alternative to drag-and-drop) ─────
// Reviewers require a non-pointer way to move a card. Pick a target stack and a
// position (top, bottom, or after a specific card); confirm funnels through
// moveWithinBoard → enqueueMove (same path as DnD).
const showMovePicker = ref(false)
const movePickerStackId = ref(null)
// 'top' | 'bottom' | 'after'
const movePickerPosition = ref('bottom')
const movePickerAfterCardId = ref(null)

/** Editable, non-archived stacks on this board (the move targets). */
const moveTargetStacks = computed(() =>
	(boardData.value?.stacks ?? [])
		.filter((s) => !s.archived)
		.slice()
		.sort((a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0)),
)

/** Cards in the chosen target stack the user can position after (self excluded). */
const movePickerAfterOptions = computed(() =>
	movePickerStackId.value == null ? [] : stackCardsSorted(movePickerStackId.value),
)

function openMovePicker() {
	moveError.value = ''
	movePickerStackId.value = cardData.value?.stackId ?? moveTargetStacks.value[0]?.id ?? null
	movePickerPosition.value = 'bottom'
	movePickerAfterCardId.value = null
	showMovePicker.value = true
}

// Reset the "after" selection whenever the target stack changes so a stale card
// id from the previous stack can't leak into the move contract.
watch(movePickerStackId, () => {
	movePickerAfterCardId.value = movePickerAfterOptions.value[0]?.id ?? null
})

function confirmMovePicker() {
	const stackId = movePickerStackId.value
	if (stackId == null) return
	let afterCardId = null
	if (movePickerPosition.value === 'top') {
		afterCardId = null
	} else if (movePickerPosition.value === 'bottom') {
		const cards = stackCardsSorted(stackId)
		afterCardId = cards.length ? cards[cards.length - 1].id : null
	} else {
		// 'after' a specific card. Guard against a stale/empty selection: an empty
		// target stack or a missing pick degrades to top — never an invalid move.
		afterCardId = movePickerAfterCardId.value ?? null
		if (afterCardId != null && !stackCardsSorted(stackId).some((c) => c.id === afterCardId)) {
			afterCardId = null
		}
	}
	moveWithinBoard(stackId, afterCardId)
	showMovePicker.value = false
}

// Surface a queued-move failure (403 review gate / 409 rebalance / generic) in
// the modal's move-error slot, same as the edge shortcuts.
watch(enqueueMoveError, (msg) => {
	if (msg) moveError.value = msg
})

// The four relation groups from card detail
const relations = computed(() => cardData.value?.relations ?? { blocks: [], blockedBy: [], duplicates: [], relates: [] })

// Relation groups shaped for the template loop
const relationGroups = computed(() => [
	{ key: 'blocks', label: t('kanso', 'Blocks'), items: relations.value.blocks ?? [] },
	{ key: 'blockedBy', label: t('kanso', 'Blocked by'), items: relations.value.blockedBy ?? [] },
	{ key: 'duplicates', label: t('kanso', 'Duplicates'), items: relations.value.duplicates ?? [] },
	{ key: 'relates', label: t('kanso', 'Relates to'), items: relations.value.relates ?? [] },
])
const hasAnyRelation = computed(() => relationGroups.value.some((g) => g.items.length > 0))

// Add-relation form state
const newRelationKind = ref('blocks')
const newRelationTargetId = ref('')
const relationError = ref('')

// Cards on this board available for selection (excluding the current card)
const boardCardsForRelation = computed(() => {
	const cards = boardData.value?.cards ?? []
	return cards.filter((c) => c.id !== Number(props.cardId) && !c.archived)
})

const addRelation = useMutation({
	mutationFn: ({ otherCardId, kind }) =>
		apiAddCardRelation(Number(props.cardId), otherCardId, kind),
	onSettled: () => {
		queryClient.invalidateQueries({ queryKey: ['card', props.cardId] })
		queryClient.invalidateQueries({ queryKey: boardQueryKey(boardId.value) })
	},
})

const removeRelation = useMutation({
	mutationFn: ({ relationId }) =>
		apiRemoveCardRelation(Number(props.cardId), relationId),
	onSettled: () => {
		queryClient.invalidateQueries({ queryKey: ['card', props.cardId] })
		queryClient.invalidateQueries({ queryKey: boardQueryKey(boardId.value) })
	},
})

async function handleAddRelation() {
	const targetId = Number(newRelationTargetId.value)
	if (!targetId) return
	relationError.value = ''
	try {
		await addRelation.mutateAsync({ otherCardId: targetId, kind: newRelationKind.value })
		newRelationTargetId.value = ''
	} catch (err) {
		relationError.value = err?.response?.data?.error || t('kanso', 'Failed to add relation.')
	}
}

async function handleRemoveRelation(relationId) {
	relationError.value = ''
	try {
		await removeRelation.mutateAsync({ relationId })
	} catch (err) {
		relationError.value = err?.response?.data?.error || t('kanso', 'Failed to remove relation.')
	}
}

// ── Redesign view state: header breadcrumb + attribute bar + responsive panes ─
// Mobile splits the card and discussion into tabs; desktop shows both panes.
const viewMode = ref('card')

// Resizable discussion/activity pane (#3661). The body is a two-column grid whose
// right (discussion) track width is driven by a CSS var; a drag handle updates it
// live and persists the chosen width per user in localStorage. Below the 680px
// breakpoint the panes stack and the var/handle are ignored (see <style>).
const DISCUSSION_WIDTH_KEY = 'kanso.cardDiscussionWidth'
const DISCUSSION_MIN_WIDTH = 280
const DISCUSSION_MAX_WIDTH = 720
const DISCUSSION_DEFAULT_WIDTH = 400
const DISCUSSION_KEY_STEP = 24
function clampDiscussionWidth(px) {
	return Math.min(DISCUSSION_MAX_WIDTH, Math.max(DISCUSSION_MIN_WIDTH, Math.round(px)))
}
const discussionWidth = ref(DISCUSSION_DEFAULT_WIDTH)
try {
	const saved = parseInt(localStorage.getItem(DISCUSSION_WIDTH_KEY), 10)
	if (Number.isFinite(saved)) discussionWidth.value = clampDiscussionWidth(saved)
} catch (e) { /* localStorage unavailable - default width */ }
const discussionWidthStyle = computed(() => ({ '--kanso-discussion-width': discussionWidth.value + 'px' }))
function persistDiscussionWidth() {
	try {
		localStorage.setItem(DISCUSSION_WIDTH_KEY, String(discussionWidth.value))
	} catch (e) { /* ignore persistence failure */ }
}

// Collapsing the discussion pane entirely (#9854). A state class on the root
// collapses the body to a single grid track and hides the pane + its handle, so
// the main pane gets the full body width with no leftover rail. The pane is only
// hidden, never unmounted - the comment query keeps the header badge live and the
// mobile Discussion tab keeps working. Persisted per user, like the width above.
// The collapse rules live in a media block that is the exact complement of the
// 680px stacking query, so this persisted flag can never leak into the
// stacked/tabbed layout and blank the Discussion tab.
const DISCUSSION_COLLAPSED_KEY = 'kanso.cardDiscussionCollapsed'
const discussionCollapsed = ref(false)
try {
	discussionCollapsed.value = localStorage.getItem(DISCUSSION_COLLAPSED_KEY) === '1'
} catch (e) { /* localStorage unavailable - default to expanded */ }
// Unique per card so `aria-controls` still resolves if two card views coexist.
const discussionPaneId = computed(() => `card-modal-discussion-${props.cardId}`)
// An aria-label replaces the whole accessible name, so the count badge would be
// silent for screen readers unless it is folded into the label itself.
const discussionToggleLabel = computed(() => {
	if (!discussionCollapsed.value) return t('kanso', 'Hide the discussion panel')
	if (commentCount.value > 0) {
		return n('kanso', 'Show the discussion panel (%n comment)', 'Show the discussion panel (%n comments)', commentCount.value)
	}
	return t('kanso', 'Show the discussion panel')
})
function toggleDiscussionCollapsed() {
	discussionCollapsed.value = !discussionCollapsed.value
	try {
		if (discussionCollapsed.value) {
			localStorage.setItem(DISCUSSION_COLLAPSED_KEY, '1')
		} else {
			localStorage.removeItem(DISCUSSION_COLLAPSED_KEY)
		}
	} catch (e) { /* ignore persistence failure */ }
}

const bodyRef = ref(null)
let resizePointerId = null
function onResizePointerMove(e) {
	if (!bodyRef.value) return
	// The handle sits on the left edge of the discussion pane; width grows as the
	// pointer moves left, so measure from the body's right edge.
	const rect = bodyRef.value.getBoundingClientRect()
	discussionWidth.value = clampDiscussionWidth(rect.right - e.clientX)
}
function onResizePointerUp(e) {
	if (resizePointerId !== null && e.pointerId !== resizePointerId) return
	window.removeEventListener('pointermove', onResizePointerMove)
	window.removeEventListener('pointerup', onResizePointerUp)
	window.removeEventListener('pointercancel', onResizePointerUp)
	document.body.style.userSelect = ''
	document.body.style.cursor = ''
	resizePointerId = null
	persistDiscussionWidth()
}
function onResizePointerDown(e) {
	// Only resize on desktop layout; below 680px the panes stack.
	if (window.matchMedia('(max-width: 680px)').matches) return
	e.preventDefault()
	resizePointerId = e.pointerId
	window.addEventListener('pointermove', onResizePointerMove)
	window.addEventListener('pointerup', onResizePointerUp)
	window.addEventListener('pointercancel', onResizePointerUp)
	// Suppress text selection / show a resize cursor for the whole drag.
	document.body.style.userSelect = 'none'
	document.body.style.cursor = 'col-resize'
}
function onResizeKeydown(e) {
	if (window.matchMedia('(max-width: 680px)').matches) return
	// Left arrow shrinks the main pane (grows discussion); Right grows the main pane.
	if (e.key === 'ArrowLeft') {
		discussionWidth.value = clampDiscussionWidth(discussionWidth.value + DISCUSSION_KEY_STEP)
	} else if (e.key === 'ArrowRight') {
		discussionWidth.value = clampDiscussionWidth(discussionWidth.value - DISCUSSION_KEY_STEP)
	} else if (e.key === 'Home') {
		discussionWidth.value = DISCUSSION_MAX_WIDTH
	} else if (e.key === 'End') {
		discussionWidth.value = DISCUSSION_MIN_WIDTH
	} else {
		return
	}
	e.preventDefault()
	persistDiscussionWidth()
}
onBeforeUnmount(() => {
	if (resizePointerId !== null) {
		window.removeEventListener('pointermove', onResizePointerMove)
		window.removeEventListener('pointerup', onResizePointerUp)
		window.removeEventListener('pointercancel', onResizePointerUp)
		document.body.style.userSelect = ''
		document.body.style.cursor = ''
	}
	// Clear the running-timer tick interval so it doesn't leak after unmount.
	if (timerInterval !== null) {
		clearInterval(timerInterval)
		timerInterval = null
	}
})

// Breadcrumb board name + uppercase status chip
const boardName = computed(() => boardData.value?.board?.title || t('kanso', 'Board'))

// Surface the resolved board context to the shell so the full-page breadcrumb /
// back-to-board affordance can render the real board name + link. Emitted (not
// exposed) so CardPage stays declarative. Fires whenever the id or name resolves,
// which for the page shell is after the card (and then the board) loads.
watch([boardId, boardName], ([id, name]) => {
	emit('board-context', { boardId: id != null ? String(id) : null, boardName: name })
}, { immediate: true })

// Human-readable reference id (prefix + '-' + boardSeq), e.g. "KAN-123".
// Null for a not-yet-numbered card (pre-migration row) so we hide the chip.
const cardHumanId = computed(() => humanId(boardData.value?.board?.prefix, cardData.value?.boardSeq))

// Copy the human id to the clipboard - the card's shareable reference.
async function copyCardRef() {
	if (!cardHumanId.value) return
	try {
		if (!navigator.clipboard?.writeText) {
			showError(t('kanso', 'Clipboard is not available in this context.'))
			return
		}
		await navigator.clipboard.writeText(cardHumanId.value)
		showSuccess(t('kanso', 'Reference {ref} copied.', { ref: cardHumanId.value }))
	} catch (err) {
		showError(t('kanso', 'Could not copy to clipboard.'))
	}
}
// The chip is the status, on every board - the column reads from the breadcrumb.
const statusChipLabel = computed(() =>
	(STATUS_OPTIONS.find((o) => o.key === currentStatus.value)?.label || '').toUpperCase(),
)

// One attribute-bar popover open at a time:
// 'priority' | 'due' | 'estimate' | 'assign' | 'label' | 'review' | null
const openPicker = ref(null)
function togglePicker(name) {
	openPicker.value = openPicker.value === name ? null : name
}

// Attribute-bar display helpers
const currentPriorityLevel = computed(() =>
	PRIORITY_LEVELS.find((l) => l.value === currentPriority.value) || null,
)
const dueDateLabel = computed(() => {
	if (!cardData.value?.duedate) return ''
	// All-day → format in UTC (the stored calendar day) so the pill matches the
	// picked day regardless of the viewer's timezone; timed → local with time.
	return formatCardDate(
		cardData.value.duedate,
		isAllDay.value,
		isAllDay.value
			? { weekday: 'short', day: 'numeric', month: 'short' }
			: { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' },
	)
})
// Labels actually assigned to this card (for the attribute-bar chips)
const assignedLabels = computed(() => boardLabels.value.filter((l) => cardLabelIds.value.has(l.id)))

// ── Description / comment editor state ───────────────────────────────────────
// descPasteError is surfaced in the template when image upload fails
const descPasteError = ref('')

// ── Projects membership ──────────────────────────────────────────────────────
const { data: projectsData } = useProjects()
const allProjects = computed(() => projectsData.value ?? [])

// The card detail now returns projectIds: number[]; fall back to [] if absent.
const cardProjectIds = computed(() => {
	const ids = Array.isArray(cardData.value?.projectIds) ? cardData.value.projectIds : []
	return new Set(ids)
})

const projectTogglePending = ref(false)
const projectToggleError = ref('')

async function handleToggleProject(projectId) {
	projectToggleError.value = ''
	projectTogglePending.value = true
	const isMember = cardProjectIds.value.has(projectId)
	try {
		if (isMember) {
			await apiRemoveCardFromProject(projectId, Number(props.cardId))
		} else {
			await apiAddCardToProject(projectId, Number(props.cardId))
		}
		// Invalidate card (so projectIds refreshes) + the project's card list
		queryClient.invalidateQueries({ queryKey: ['card', props.cardId] })
		queryClient.invalidateQueries({ queryKey: ['project', String(projectId), 'cards'] })
		queryClient.invalidateQueries({ queryKey: ['projects'] })
	} catch (err) {
		projectToggleError.value = err?.response?.data?.error || t('kanso', 'Failed to update project membership.')
	} finally {
		projectTogglePending.value = false
	}
}
</script>

<style scoped>
/* ── Copy-to dialog ──────────────────────────────────────────────────────── */
.card-modal__copy-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.card-modal__copy-title {
	margin: 0;
	font-size: 1.1rem;
	font-weight: 600;
}

.card-modal__copy-hint,
.card-modal__copy-note {
	margin: 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.card-modal__copy-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.card-modal__copy-label {
	font-size: 0.85rem;
	font-weight: 500;
}

.card-modal__copy-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 4px;
}

.card-modal__move-position {
	display: flex;
	flex-direction: column;
	gap: 6px;
	border: none;
	margin: 0;
	padding: 0;
}

.card-modal__move-radio {
	display: flex;
	align-items: center;
	gap: 8px;
	cursor: pointer;
}

.card-modal__move-radio--disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

/* ── Modal shell ─────────────────────────────────────────────────────────── */
.card-modal {
	/* Legible status colours for small chip text/border. NC's stock error red,
	 * warning amber, neutral grey, and success green are too dim on the dark
	 * modal surface (#3905/#4054); brighten them under dark themes while keeping
	 * the stock values in light mode. Scoped to the modal so they can't leak. */
	--kanso-error-legible: var(--color-error, #e30000);
	--kanso-warning-legible: var(--color-warning, #e07b00);
	--kanso-neutral-legible: var(--color-text-maxcontrast, #767676);
	--kanso-success-legible: var(--color-success, #46ba61);
	--kanso-success-legible-rgb: 70, 186, 97;


	display: flex;
	flex-direction: column;
	min-height: 0;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 15px;
}

/* Explicit dark themes (theme picker) + auto (prefers-color-scheme) dark.
 * These brighter values read clearly on the dark surface and still pass AA
 * as chip text/border. */
body.theme--dark .card-modal,
[data-theme-dark] .card-modal,
[data-themes*='dark'] .card-modal {
	--kanso-error-legible: #ff6b6b;
	--kanso-warning-legible: #d29922;
	--kanso-neutral-legible: #8b949e;
	--kanso-success-legible: #3fb950;
	--kanso-success-legible-rgb: 63, 185, 80;
}

@media (prefers-color-scheme: dark) {
	body.theme--default .card-modal,
	body:not(.theme--light):not(.theme--dark) .card-modal {
		--kanso-error-legible: #ff6b6b;
		--kanso-warning-legible: #d29922;
		--kanso-neutral-legible: #8b949e;
		--kanso-success-legible: #3fb950;
		--kanso-success-legible-rgb: 63, 185, 80;
	}
}


/* ── Loading skeleton (shimmer, real layout) ─────────────────────────────── */
@keyframes kshim {
	0% { background-position: -400px 0; }
	100% { background-position: 400px 0; }
}
.kskel {
	background: linear-gradient(90deg, var(--color-background-dark) 25%, var(--color-background-hover) 50%, var(--color-background-dark) 75%);
	background-size: 400px 100%;
	animation: kshim 1.4s infinite linear;
	border-radius: 4px;
}
.card-modal__sk-header {
	display: flex;
	align-items: flex-start;
	gap: 16px;
	padding: 18px 24px 14px;
	border-bottom: 1px solid var(--color-border);
}
.card-modal__sk-col {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 10px;
}
.card-modal__sk-bar {
	display: flex;
	gap: 6px;
	padding: 10px 24px;
	background: var(--color-background-hover);
	border-bottom: 1px solid var(--color-border);
}
.card-modal__sk-body {
	display: grid;
	grid-template-columns: 1fr 400px;
	min-height: 340px;
}
.card-modal__sk-main {
	padding: 24px 28px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}
.card-modal__sk-side {
	border-left: 1px solid var(--color-border);
	background: var(--color-background-hover);
	padding: 18px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.card-modal__error {
	padding: 40px 24px;
	text-align: center;
	color: var(--color-error);
}

.card-modal__error-msg {
	margin: 0 0 20px;
	color: var(--color-main-text);
	font-size: 1.05rem;
}

.card-modal__error-actions {
	display: flex;
	justify-content: center;
	flex-wrap: wrap;
	gap: 10px;
}

/* ── Verdict banner (review requested) ───────────────────────────────────── */
.card-modal__verdict {
	display: flex;
	align-items: center;
	gap: 14px;
	padding: 12px 20px;
	background: var(--color-primary-light);
	border-bottom: 1px solid var(--color-primary-element);
}
.card-modal__verdict-icon {
	color: var(--color-primary-element);
	flex-shrink: 0;
}
.card-modal__verdict-copy {
	display: flex;
	flex-direction: column;
	min-width: 0;
}
.card-modal__verdict-title {
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-main-text);
}
.card-modal__verdict-sub {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}
.card-modal__verdict-actions {
	margin-left: auto;
	display: flex;
	align-items: flex-start;
	gap: 8px;
	flex-wrap: wrap;
	justify-content: flex-end;
}
.card-modal__verdict-reason {
	min-width: 240px;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
	resize: vertical;
}

/* ── Header band ─────────────────────────────────────────────────────────── */
.card-modal__header {
	display: flex;
	align-items: flex-start;
	gap: 16px;
	padding: 18px 20px 14px 24px;
	border-bottom: 1px solid var(--color-border);
}
.card-modal__header-main {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}
.card-modal__breadcrumb {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px;
	row-gap: 4px;
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}
.card-modal__crumb {
	white-space: nowrap;
}
/* The column crumb can be long - keep it on one line and clip it rather than
   letting a wordy column name push the status chip off the row. */
.card-modal__crumb--column {
	max-width: 220px;
	overflow: hidden;
	text-overflow: ellipsis;
}
/* Copyable human-id reference chip, next to the card title */
.card-modal__ref {
	display: inline-flex;
	align-items: center;
	height: 20px;
	padding: 0 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.72rem;
	font-weight: 600;
	letter-spacing: 0.02em;
	cursor: pointer;
	white-space: nowrap;
}
.card-modal__ref:hover {
	color: var(--color-main-text);
	border-color: var(--color-primary-element);
}
.card-modal__crumb-chevron,
.card-modal__crumb-dot {
	color: var(--color-border-dark);
}
.card-modal__status-chip {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	height: 20px;
	padding: 0 6px 0 8px;
	border-radius: 10px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	font-size: 0.68rem;
	font-weight: 700;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}
.card-modal__status-chip--btn {
	cursor: pointer;
}
.card-modal__status-chip--btn:hover {
	border-color: var(--color-primary-element);
}
.card-modal__status-chip--in_progress {
	background: var(--color-primary-light);
	border-color: var(--color-primary-element);
	color: var(--color-primary-light-text);
}
.card-modal__status-chip--done {
	background: var(--color-success);
	border-color: var(--color-success);
	color: var(--color-success-text);
}
.card-modal__status-wrap {
	align-items: center;
}
/* Title row: the reference chip sits ahead of the title, which takes the rest. */
.card-modal__title-row {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	min-width: 0;
}
.card-modal__title-row .card-modal__ref {
	flex: 0 0 auto;
	/* Optically centre the 20px chip on the title's first line (1.5rem × 1.25). */
	margin-top: 6px;
}
.card-modal__title-row .card-modal__title,
.card-modal__title-row .card-modal__title-input {
	flex: 1 1 auto;
	min-width: 0;
}
.card-modal__title {
	margin: 0 0 0 -4px;
	font-size: 1.5rem;
	line-height: 1.25;
	font-weight: 700;
	color: var(--color-main-text);
	cursor: text;
	border-radius: 3px;
	padding: 1px 4px;
	word-break: break-word;
}
.card-modal__title:hover {
	background: var(--color-background-hover);
}
.card-modal__title-input {
	margin: 0 0 0 -4px;
	font-size: 1.5rem;
	line-height: 1.25;
	font-weight: 700;
	color: var(--color-main-text);
	border: 1px solid var(--color-primary-element);
	border-radius: 3px;
	padding: 1px 4px;
	width: 100%;
	box-sizing: border-box;
}
.card-modal__title-input:focus {
	outline: none;
}
.card-modal__header-actions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-shrink: 0;
}
.card-modal__done-btn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 36px;
	padding: 0 14px;
	border: 1px solid var(--color-border);
	border-radius: 100px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.875rem;
	font-weight: 600;
	cursor: pointer;
}
.card-modal__done-btn:hover {
	border-color: var(--color-primary-element);
	color: var(--color-primary-element);
}
.card-modal__done-btn--done {
	border-color: var(--kanso-success-legible);
	color: var(--kanso-success-legible);
	background: rgba(var(--kanso-success-legible-rgb), 0.1);
}
/* Watch control: a single split-button pill — the watch toggle (left half) and
   the watchers caret (right half) sit flush, sharing one border with a thin 1px
   divider between them. Only the outer corners are rounded. */
.card-modal__watch-wrap {
	position: relative;
	display: inline-flex;
	align-items: center;
	gap: 0;
}
.card-modal__watch-btn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 36px;
	/* !important — NC core sets `button { margin: 3px 3px 3px 0 }`, which otherwise
	   pushes a 3px gap between the two halves of the split button. */
	margin: 0 !important;
	padding: 0 12px;
	border: 1px solid var(--color-border);
	border-radius: 100px 0 0 100px;
	background: var(--color-main-background);
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
	cursor: pointer;
}
.card-modal__watch-caret {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 36px;
	margin: 0 !important;
	/* The left edge is the shared divider; drop the caret's own left border so the
	   two halves share a single 1px line, and sit flush against the left half
	   (no margin) so there is no gap between them. */
	border: 1px solid var(--color-border);
	border-left: 0;
	border-radius: 0 100px 100px 0;
	background: var(--color-main-background);
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}
.card-modal__watch-btn:hover,
.card-modal__watch-caret:hover {
	border-color: var(--color-primary-element);
	color: var(--color-primary-element);
}
/* Keep focus rings on top and the hovered/focused half's border above its
   neighbour so the shared divider reads correctly. */
.card-modal__watch-btn:hover,
.card-modal__watch-btn:focus-visible,
.card-modal__watch-caret:hover,
.card-modal__watch-caret:focus-visible {
	position: relative;
	z-index: 1;
}
/* Active (watching) state styles the whole pill as one control. */
.card-modal__watch-btn--active,
.card-modal__watch-caret--active {
	border-color: var(--color-primary-element);
	background: var(--color-primary-light);
	color: var(--color-primary-element);
}
.card-modal__watch-caret--active {
	/* Re-add a left border so the divider stays visible in the active state,
	   tinted to match the active pill. */
	border-left: 1px solid var(--color-primary-element);
}
.card-modal__watch-panel {
	min-width: 240px;
	gap: 4px;
}
.card-modal__watch-panel-title {
	padding: 4px 8px 2px;
	font-size: 0.75rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}
.card-modal__watch-panel-subtitle {
	padding: 2px 8px;
	font-size: 0.6875rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.03em;
	color: var(--color-text-maxcontrast);
}
.card-modal__watch-panel-empty {
	padding: 4px 8px 6px;
	font-size: 0.8125rem;
	color: var(--color-text-maxcontrast);
}
.card-modal__watch-panel-divider {
	height: 1px;
	margin: 4px 0;
	background: var(--color-border);
}
.card-modal__watch-row {
	display: flex;
	align-items: center;
	gap: 8px;
	min-height: 32px;
	padding: 2px 8px;
}
.card-modal__watch-row-name {
	flex: 1 1 auto;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 0.8125rem;
}
.card-modal__icon-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 36px;
	height: 36px;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
}
.card-modal__icon-btn:hover {
	background: var(--color-background-hover);
}
.card-modal__actions-menu {
	flex-shrink: 0;
}

/* ── Attribute bar ───────────────────────────────────────────────────────── */
.card-modal__attrbar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
	padding: 10px 24px;
	background: var(--color-background-hover);
	border-bottom: 1px solid var(--color-border);
}
.card-modal__attr {
	position: relative;
	display: inline-flex;
}
.card-modal__attr-right {
	position: relative;
	margin-left: auto;
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	justify-content: flex-end;
}
.card-modal__attr-eyebrow {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}
.card-modal__attr-divider {
	width: 1px;
	height: 20px;
	background: var(--color-border-dark);
	margin: 0 4px;
}
.card-modal__pill {
	display: inline-flex;
	align-items: center;
	gap: 7px;
	height: 32px;
	padding: 0 12px;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.8125rem;
	cursor: pointer;
	white-space: nowrap;
}
.card-modal__pill:hover {
	border-color: var(--color-primary-element);
}
.card-modal__pill--sm {
	height: 24px;
	padding: 0 10px;
	font-size: 0.7rem;
	gap: 5px;
}
.card-modal__pill--dashed {
	border-style: dashed;
	border-color: var(--color-border-dark);
	background: transparent;
	color: var(--color-text-maxcontrast);
}
.card-modal__pill--dashed:hover {
	border-color: var(--color-primary-element);
	color: var(--color-primary-element);
}
/* Priority pill colours (only colours Kanso invents) */
.card-modal__pill--priority-1 { border-color: var(--kanso-neutral-legible); color: var(--kanso-neutral-legible); font-weight: 600; }
.card-modal__pill--priority-2 { border-color: var(--color-primary-element); color: var(--color-primary-element); font-weight: 600; }
.card-modal__pill--priority-3 { border-color: var(--kanso-warning-legible); color: var(--kanso-warning-legible); font-weight: 600; }
.card-modal__pill--priority-4 { border-color: var(--color-error-text); color: var(--color-error-text); font-weight: 600; }
.card-modal__pill--type-bug { border-color: var(--kanso-error-legible); color: var(--kanso-error-legible); font-weight: 600; }
.card-modal__pill--type-feature { border-color: var(--kanso-success-legible); color: var(--kanso-success-legible); font-weight: 600; }
.card-modal__pill--type-task { border-color: var(--color-primary-element); color: var(--color-primary-element); font-weight: 600; }
.card-modal__pill--type-chore { border-color: var(--kanso-neutral-legible); color: var(--kanso-neutral-legible); font-weight: 600; }
.card-modal__pill--overdue { border-color: var(--color-error-text); color: var(--color-error-text); font-weight: 600; }
.card-modal__pill--soon { border-color: var(--color-warning-text); color: var(--color-warning-text); font-weight: 600; }

.card-modal__assignee-pill {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 32px;
	padding: 0 8px 0 4px;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	background: var(--color-main-background);
	font-size: 0.8125rem;
}
.card-modal__assignee-name {
	max-width: 130px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.card-modal__pill-x {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 18px;
	height: 18px;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}
.card-modal__pill-x:hover {
	background: var(--color-error);
	color: #fff;
}
.card-modal__label-chip {
	display: inline-flex;
	align-items: center;
	height: 20px;
	padding: 0 10px;
	border-radius: 10px;
	font-size: 0.7rem;
	font-weight: 600;
	max-width: 160px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.card-modal__label-chip--no-color {
	background: var(--color-background-dark);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
}
.card-modal__review-pill {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 28px;
	padding: 0 8px 0 3px;
	border-radius: 10px;
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	font-size: 0.75rem;
}
.card-modal__review-pill--pending { border-color: var(--color-warning-text); background: rgba(236, 167, 0, 0.08); }
.card-modal__review-pill--approved { border-color: var(--kanso-success-legible); background: rgba(var(--kanso-success-legible-rgb), 0.08); }
.card-modal__review-pill--changes_requested { border-color: var(--color-error-text); background: rgba(233, 50, 45, 0.08); }
/* A gated (deferred) review reads as inert: greyed out, dashed border, muted
   colours. The lock icon + hover tooltip explain it's waiting on an earlier
   review. Wins over the state colour because the class comes later. */
.card-modal__review-pill--gated {
	border-color: var(--color-border-dark);
	border-style: dashed;
	background: var(--color-background-dark);
	opacity: 0.7;
}
.card-modal__review-name {
	max-width: 120px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.card-modal__review-type-badge {
	display: inline-flex;
	align-items: center;
	height: 16px;
	padding: 0 6px;
	border-radius: 8px;
	font-size: 0.65rem;
	font-weight: 600;
	background: var(--color-background-dark);
}
.card-modal__review-state {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	font-weight: 600;
}
.card-modal__review-state--pending { color: var(--color-warning-text); }
.card-modal__review-state--approved { color: var(--kanso-success-legible); }
.card-modal__review-state--changes_requested { color: var(--color-error-text); }
.card-modal__review-state--gated { color: var(--color-text-maxcontrast); }
/* Compact mode (3+ reviews): drop the reviewer name and the state text so the
   chip shrinks to avatar + type badge + state icon and N reviews stay on one
   row. The reviewer name is still available via the avatar's hover tooltip and
   the state via its coloured icon + border. */
.card-modal__review-pill--compact { gap: 4px; padding: 0 6px 0 3px; }
.card-modal__review-pill--compact .card-modal__review-name,
.card-modal__review-pill--compact .card-modal__review-state-label { display: none; }

/* ── Popovers ────────────────────────────────────────────────────────────── */
.card-modal__popover {
	position: absolute;
	top: calc(100% + 6px);
	left: 0;
	z-index: 30;
	min-width: 200px;
	max-height: 320px;
	overflow: auto;
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 6px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 10px;
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
}
.card-modal__popover--right {
	left: auto;
	right: 0;
}
.card-modal__popover--pad {
	padding: 10px 12px;
	gap: 6px;
}
/* The date popover packs the Due date + Start date + the whole Repeat control, so
   the shared 200px min-width left it cramped and the 320px cap made it scroll.
   Give it room to lay the fields out comfortably (the mobile inset-sheet rule
   below still wins on small screens via its higher specificity). */
.card-modal__popover--date {
	width: 300px;
	max-width: calc(100vw - 32px);
	max-height: min(70vh, 540px);
}
.card-modal__popover-tokens {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}
.card-modal__popover-empty {
	padding: 8px;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}
/* Section heading inside a popover that groups options (Column / Status). */
.card-modal__popover-head {
	padding: 6px 10px 2px;
	font-size: 0.7rem;
	font-weight: 700;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}
.card-modal__popover-head:not(:first-child) {
	margin-top: 4px;
	border-top: 1px solid var(--color-border);
	padding-top: 8px;
}
.card-modal__popover-opt {
	display: flex;
	align-items: center;
	min-height: 32px;
	padding: 6px 10px;
	border: none;
	border-radius: 6px;
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.8125rem;
	text-align: left;
	cursor: pointer;
}
.card-modal__popover-opt:hover {
	background: var(--color-background-hover);
}
.card-modal__popover-opt--active {
	background: var(--color-primary-light);
	color: var(--color-primary-element);
	font-weight: 600;
}
.card-modal__assign-option {
	display: flex;
	align-items: center;
	gap: 8px;
	min-height: 36px;
	padding: 4px 8px;
	border: none;
	border-radius: 6px;
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.8125rem;
	text-align: left;
	cursor: pointer;
}
.card-modal__assign-option:hover {
	background: var(--color-background-hover);
}
.card-modal__assign-option:disabled {
	opacity: 0.5;
	cursor: default;
}
/* Contacts picker (#3530) */
.card-modal__contact-search {
	width: 100%;
	margin-bottom: 4px;
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.8125rem;
}
.card-modal__contact-option-text {
	display: flex;
	flex-direction: column;
	min-width: 0;
}
.card-modal__contact-email {
	color: var(--color-text-maxcontrast);
	font-size: 0.6875rem;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.card-modal__contact-empty {
	display: block;
	padding: 6px 8px;
	color: var(--color-text-maxcontrast);
	font-size: 0.75rem;
}
.card-modal__label-toggle {
	display: inline-flex;
	align-items: center;
	height: 26px;
	padding: 0 10px;
	margin: 2px;
	border: 1px solid var(--label-color, var(--color-border));
	border-radius: 10px;
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.75rem;
	font-weight: 600;
	cursor: pointer;
}
.card-modal__label-toggle--active {
	background: var(--label-color, var(--color-primary-element));
	color: #fff;
	border-color: var(--label-color, var(--color-primary-element));
}
.card-modal__label-toggle--no-color.card-modal__label-toggle--active {
	background: var(--color-primary-element);
	border-color: var(--color-primary-element);
}
/* Projects dropdown uses label-toggle styling; dot provides color identity */
.card-modal__project-dot {
	display: inline-block;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: var(--color-primary-element);
	border: 1px solid rgba(0, 0, 0, 0.1);
	flex-shrink: 0;
}
.card-modal__label-create {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: 8px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}
.card-modal__label-swatch {
	flex: 0 0 auto;
	width: 26px;
	height: 26px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}
.card-modal__label-swatch--no-color {
	background: var(--color-background-hover);
}
/* Cover-colour swatches (#3549) - a small grid of preset colours in the picker */
.card-modal__cover-swatches {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-bottom: 6px;
}
.card-modal__cover-swatch {
	width: 24px;
	height: 24px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	cursor: pointer;
	padding: 0;
}
.card-modal__cover-swatch--active {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 1px;
}
.card-modal__label-color-grid {
	flex: 1 1 100%;
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	order: 3;
}
.card-modal__label-color-option {
	width: 22px;
	height: 22px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	cursor: pointer;
}
.card-modal__label-color-option--active {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 1px;
}
.card-modal__label-color-option--clear {
	background: var(--color-main-background);
	color: var(--color-text-maxcontrast);
	line-height: 1;
}
.card-modal__label-create-input {
	flex: 1 1 auto;
	min-width: 80px;
	height: 26px;
	padding: 0 8px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.8rem;
}
.card-modal__label-create-btn {
	flex: 0 0 auto;
	height: 26px;
	padding: 0 10px;
	border: none;
	border-radius: 6px;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-size: 0.75rem;
	font-weight: 600;
	cursor: pointer;
}
.card-modal__label-create-btn:disabled {
	opacity: 0.5;
	cursor: default;
}
.card-modal__field-label {
	font-size: 0.7rem;
	font-weight: 700;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}
/* The ⓘ hint that sits next to a field label (start/due/repeat tips). */
.card-modal__field-hint {
	display: inline-flex;
	vertical-align: middle;
	margin-inline-start: 4px;
	opacity: 0.55;
	cursor: help;
}
.card-modal__field-hint:hover {
	opacity: 0.9;
}
.card-modal__field-row {
	display: flex;
	align-items: center;
	gap: 6px;
}
.card-modal__date-input {
	flex: 1;
	height: 34px;
	padding: 0 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
}
.card-modal__allday {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-top: 6px;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}
.card-modal__recur-label {
	display: block;
	margin-top: 10px;
	padding-top: 10px;
	border-top: 1px solid var(--color-border);
}
.card-modal__recur-interval {
	margin-top: 6px;
}
.card-modal__recur-interval-input {
	flex: 0 0 64px;
	width: 64px;
}
.card-modal__recur-every,
.card-modal__recur-unit {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}
.card-modal__recur-note {
	margin-top: 6px;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}
/* The two "what happens when it repeats" options carry long labels, so stack
   them as full-width cards instead of cramming them side by side in a row. */
.card-modal__recur-mode {
	flex-direction: column;
	align-items: stretch;
	gap: 4px;
	margin-top: 8px;
}
.card-modal__recur-mode-opt {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 7px 10px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	font-size: 0.85rem;
	color: var(--color-main-text);
	cursor: pointer;
}
.card-modal__recur-mode-opt--active {
	border-color: var(--color-primary-element);
	background: var(--color-primary-light);
	color: var(--color-primary-element);
	font-weight: 600;
}
.card-modal__field-clear {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}
.card-modal__field-clear:hover {
	background: var(--color-background-dark);
}
.card-modal__review-type-selector {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px;
	padding: 2px 4px 6px;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 4px;
}
.card-modal__review-type-option {
	height: 24px;
	padding: 0 8px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.7rem;
	cursor: pointer;
}
.card-modal__review-type-option--active {
	background: var(--color-primary-element);
	color: #fff;
	border-color: var(--color-primary-element);
}

/* ── Body grid ───────────────────────────────────────────────────────────── */
.card-modal__body {
	display: grid;
	/* main pane | drag handle | discussion pane (width from a persisted CSS var, #3661) */
	grid-template-columns: minmax(0, 1fr) 0 var(--kanso-discussion-width, 400px);
	align-items: stretch;
	min-height: 0;
	flex: 1;
}
/* Drag handle straddling the border between the two panes. Zero-width in the grid
   track; widened visually via padding so it's easy to grab without shifting layout. */
.card-modal__resizer {
	position: relative;
	z-index: 5;
	width: 0;
	margin: 0 -5px;
	padding: 0 5px;
	cursor: col-resize;
	touch-action: none;
	display: flex;
	align-items: stretch;
	justify-content: center;
}
.card-modal__resizer-grip {
	width: 1px;
	background: var(--color-border);
	transition: background 0.12s ease, width 0.12s ease;
}
.card-modal__resizer:hover .card-modal__resizer-grip,
.card-modal__resizer:focus-visible .card-modal__resizer-grip {
	width: 3px;
	background: var(--color-primary-element);
}
.card-modal__resizer:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -1px;
}
.card-modal__content {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding: 24px 28px 32px;
	min-width: 0;
	overflow: auto;
	max-height: 64vh;
}

.card-modal__section {
	display: flex;
	flex-direction: column;
	gap: 10px;
}
.card-modal__section-head {
	display: flex;
	align-items: center;
	gap: 8px;
}
/* Compact one-row section header: eyebrow label + primary input inline */
.card-modal__section--tight {
	gap: 8px;
}
.card-modal__section-inline {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
}
.card-modal__section-inline .card-modal__eyebrow,
.card-modal__section-inline .card-modal__eyebrow-icon {
	flex-shrink: 0;
}
.card-modal__section-inline .card-modal__dashed-input {
	flex: 1;
	width: auto;
	min-width: 160px;
}
.card-modal__inline-form {
	flex: 1;
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 200px;
}
.card-modal__inline-form .card-modal__dashed-input {
	flex: 1;
	width: auto;
}
.card-modal__eyebrow {
	font-size: 0.7rem;
	font-weight: 700;
	letter-spacing: 0.06em;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
}
.card-modal__eyebrow-icon {
	color: var(--color-text-maxcontrast);
}
.card-modal__section-count {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}
.card-modal__ghost-btn {
	margin-left: auto;
	display: inline-flex;
	align-items: center;
	gap: 5px;
	height: 26px;
	padding: 0 10px;
	border: none;
	border-radius: 100px;
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 0.75rem;
	cursor: pointer;
}
.card-modal__ghost-btn:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}
.card-modal__hint {
	font-size: 0.7rem;
	color: var(--color-text-maxcontrast);
}

/* Description */
.card-modal__desc-view {
	font-size: 0.9375rem;
	line-height: 1.65;
	color: var(--color-main-text);
	cursor: text;
	border-radius: 3px;
	padding: 2px 4px;
	margin-left: -4px;
}
.card-modal__desc-view:hover {
	background: var(--color-background-hover);
}
.card-modal__desc-rendered :deep(p) { margin: 0 0 0.7em; }
.card-modal__desc-rendered :deep(p:last-child) { margin-bottom: 0; }
.card-modal__desc-rendered :deep(code) {
	background: var(--color-border);
	border-radius: 3px;
	padding: 2px 5px;
	font-size: 0.875em;
}
.card-modal__desc-rendered :deep(pre) {
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 10px 12px;
	overflow: auto;
}
.card-modal__desc-placeholder {
	text-align: left;
	padding: 16px;
	border: 1px dashed var(--color-border-dark);
	border-radius: 10px;
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 0.9375rem;
	cursor: pointer;
	width: 100%;
}
.card-modal__desc-placeholder:hover {
	border-color: var(--color-primary-element);
	color: var(--color-main-text);
}
.card-modal__md-toolbar {
	display: flex;
	align-items: center;
	gap: 2px;
	margin-bottom: 6px;
	padding: 3px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-background-hover);
	flex-wrap: wrap;
}
.card-modal__md-toolbar-spacer { flex: 1 1 auto; }
.card-modal__md-sep {
	width: 1px;
	align-self: stretch;
	margin: 2px 4px;
	background: var(--color-border);
}
.card-modal__md-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	border: none;
	border-radius: 6px;
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
}
.card-modal__md-btn:hover { background: var(--color-background-dark); }
.card-modal__md-btn--active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}
.card-modal__desc-preview {
	margin: 8px 0 0;
	padding: 10px 14px;
	border: 1px dashed var(--color-border);
	border-radius: 10px;
	background: var(--color-main-background);
}
.card-modal__desc-preview-label {
	display: block;
	margin-bottom: 4px;
	font-size: 0.7rem;
	font-weight: 700;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}
.card-modal__desc-textarea {
	width: 100%;
	box-sizing: border-box;
	padding: 12px 14px;
	border: 1px solid var(--color-primary-element);
	border-radius: 10px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.9375rem;
	line-height: 1.65;
	resize: vertical;
}
.card-modal__desc-textarea:focus { outline: none; }

/* Loading placeholder shown while the async MarkdownEditor chunk loads */
.card-modal__editor-loading {
	min-height: 60px;
	border-radius: 10px;
	background: var(--color-background-hover);
	animation: kanso-editor-pulse 1.4s ease-in-out infinite;
}
@keyframes kanso-editor-pulse {
	0%, 100% { opacity: 0.6; }
	50% { opacity: 1; }
}

.card-modal__desc-actions {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
}

/* Checklist */
.card-modal__checklist {
	border: 1px solid var(--color-border);
	border-radius: 10px;
	overflow: hidden;
	/* overflow:hidden zeroes min-height:auto for a flex item, so the column
	   scroll container would otherwise shrink this box to nothing - pin it. */
	flex-shrink: 0;
}
.card-modal__checklist-head {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 12px 14px;
	background: var(--color-background-hover);
	border-bottom: 1px solid var(--color-border);
}
.card-modal__checklist-head-icon { color: var(--color-text-maxcontrast); }
.card-modal__checklist-title {
	font-size: 0.8125rem;
	font-weight: 600;
}
.card-modal__checklist-count {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}
.card-modal__checklist-bar {
	flex: 1;
	height: 4px;
	max-width: 220px;
	margin-left: 8px;
	background: var(--color-border-dark);
	border-radius: 2px;
	overflow: hidden;
}
.card-modal__checklist-bar-fill {
	height: 100%;
	background: var(--color-primary-element);
	border-radius: 2px;
	transition: width 0.15s ease;
}
.card-modal__checklist-bar-fill--complete {
	background: #46ba61;
}
.card-modal__checklist-list {
	list-style: none;
	margin: 0;
	padding: 6px;
}
.card-modal__checklist-item {
	position: relative; /* anchors the per-step assign/due popovers (#3745) */
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px;
	border-radius: 3px;
}
.card-modal__checklist-item:hover {
	background: var(--color-background-hover);
}
.card-modal__checklist-item[data-drag-over='true'] {
	box-shadow: inset 0 2px 0 var(--color-primary-element);
}
.card-modal__checklist-drag {
	display: inline-flex;
	color: var(--color-border-dark);
	cursor: grab;
}
.card-modal__checklist-checkbox {
	width: 16px;
	height: 16px;
	accent-color: var(--color-primary-element);
	cursor: pointer;
	flex-shrink: 0;
}
.card-modal__checklist-item-title {
	flex: 1;
	font-size: 0.875rem;
	color: var(--color-main-text);
	cursor: text;
	word-break: break-word;
}
.card-modal__checklist-item-title--done {
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
}
.card-modal__checklist-item-input {
	flex: 1;
	height: 30px;
	font-size: 0.875rem;
	border: 1px solid var(--color-primary-element);
	border-radius: 3px;
	padding: 0 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}
.card-modal__checklist-item-input:focus { outline: none; }
/* ── Rich step meta (#3745): due chip + assignee avatar + row actions ────── */
.card-modal__step-due {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	flex-shrink: 0;
	padding: 1px 7px;
	border-radius: 10px;
	font-size: 12px;
	white-space: nowrap;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}
.card-modal__step-due[role='button'] {
	cursor: pointer;
}
.card-modal__step-due--overdue {
	background: var(--color-error);
	color: #fff;
}
.card-modal__step-due--soon {
	background: var(--color-warning, #d89b00);
	color: #fff;
}
.card-modal__step-assignee {
	display: inline-flex;
	flex-shrink: 0;
	line-height: 0;
}
.card-modal__step-assignee[role='button'] {
	cursor: pointer;
}
.card-modal__step-actions {
	display: inline-flex;
	gap: 2px;
	flex-shrink: 0;
	opacity: 0;
}
.card-modal__checklist-item:hover .card-modal__step-actions,
.card-modal__checklist-item:focus-within .card-modal__step-actions {
	opacity: 1;
}
.card-modal__step-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}
.card-modal__step-btn:hover {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}
.card-modal__step-popover {
	top: calc(100% - 4px);
	right: 0;
	left: auto;
}

.card-modal__checklist-item-delete {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}
.card-modal__checklist-item-delete:hover {
	background: var(--color-error);
	color: #fff;
}
.card-modal__checklist-add {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 6px 8px 12px 8px;
}
.card-modal__checklist-add-icon { color: var(--color-text-maxcontrast); }
.card-modal__checklist-add-input {
	flex: 1;
	height: 34px;
	font-size: 0.875rem;
	color: var(--color-main-text);
	border: 1px solid transparent;
	border-radius: 3px;
	padding: 0 8px;
	background: transparent;
}
.card-modal__checklist-add-input:focus {
	outline: none;
	border-color: var(--color-primary-element);
	background: var(--color-main-background);
}

/* Sub-cards / parent */
.card-modal__parent-row {
	display: flex;
	align-items: center;
	gap: 10px;
}
.card-modal__parent-link {
	flex: 1;
	text-align: left;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: 3px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.875rem;
	cursor: pointer;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.card-modal__parent-link:hover {
	border-color: var(--color-primary-element);
}
.card-modal__children-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
}
.card-modal__child {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: 3px;
	background: var(--color-main-background);
	min-width: 0;
}
.card-modal__child:hover {
	border-color: var(--color-primary-element);
	box-shadow: 0 1px 4px rgba(0, 0, 0, 0.06);
}
.card-modal__child-dot {
	flex-shrink: 0;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	border: 2px solid var(--color-border-dark);
}
.card-modal__child-dot--done {
	border: none;
	background: #46ba61;
}
.card-modal__child-link {
	flex: 1;
	text-align: left;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.875rem;
	cursor: pointer;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.card-modal__child-link--done {
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
}
.card-modal__child-remove {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 22px;
	height: 22px;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	flex-shrink: 0;
}
.card-modal__child-remove:hover {
	background: var(--color-error);
	color: #fff;
}
.card-modal__dashed-input {
	height: 34px;
	font-size: 0.875rem;
	color: var(--color-main-text);
	border: 1px dashed var(--color-border-dark);
	border-radius: 3px;
	padding: 0 12px;
	background: transparent;
	width: 100%;
	box-sizing: border-box;
}
.card-modal__dashed-input:focus {
	outline: none;
	border-color: var(--color-primary-element);
	border-style: solid;
	background: var(--color-main-background);
}
.card-modal__hierarchy-actions {
	display: flex;
	align-items: center;
	gap: 8px;
}
.card-modal__hierarchy-actions .card-modal__dashed-input {
	flex: 1;
	width: auto;
}
.card-modal__set-parent {
	margin-top: -2px;
}
.card-modal__ghost-btn--start {
	margin-left: 0;
	padding-left: 0;
}
.card-modal__ghost-btn--start:hover {
	background: transparent;
	color: var(--color-primary-element);
}

/* GitHub links */
.card-modal__links-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}
.card-modal__link-row {
	display: flex;
	align-items: center;
	gap: 6px;
}
.card-modal__link {
	flex: 1;
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px 10px;
	border-radius: 3px;
	color: var(--color-main-text);
	min-width: 0;
}
.card-modal__link:hover {
	background: var(--color-background-hover);
	text-decoration: none;
}
.card-modal__link-badge {
	flex: 0 0 auto;
	font-size: 11px;
	font-weight: 600;
	line-height: 1;
	padding: 3px 7px;
	border-radius: 10px;
	color: #fff;
	background: var(--color-text-maxcontrast);
	text-transform: uppercase;
}
.card-modal__link-badge--open { background: #46ba61; }
.card-modal__link-badge--merged { background: #8e44ad; }
.card-modal__link-badge--closed { background: #e9322d; }
.card-modal__link-text {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 0.875rem;
}
.card-modal__link-ext {
	margin-left: auto;
	color: var(--color-text-maxcontrast);
}
.card-modal__file-input {
	display: none;
}
.card-modal__attachment-size {
	margin-left: auto;
	padding-left: 8px;
	color: var(--color-text-maxcontrast);
	font-size: 0.8125rem;
	white-space: nowrap;
}
.card-modal__link-add {
	display: flex;
	gap: 8px;
	align-items: center;
}
/* Timer running indicator (#73): a prominent "Timer running · elapsed" row at
 * the top of the Time tracking section. The icon pulses to draw the eye; the
 * elapsed counter ticks every second via the timerNow ref. */
.card-modal__timer-running-row {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-top: 6px;
	padding: 4px 8px;
	border-radius: var(--border-radius);
	background: rgba(var(--kanso-success-legible-rgb, 70, 186, 97), 0.08);
	border: 1px solid rgba(var(--kanso-success-legible-rgb, 70, 186, 97), 0.25);
	font-size: 0.8rem;
}
.card-modal__timer-running-icon {
	color: var(--kanso-success-legible);
	animation: kanso-detail-timer-pulse 2s ease-in-out infinite;
	flex: 0 0 auto;
}
@keyframes kanso-detail-timer-pulse {
	0%, 100% { opacity: 1; }
	50% { opacity: 0.45; }
}
.card-modal__timer-running-label {
	color: var(--kanso-success-legible);
	font-weight: 600;
}
.card-modal__timer-running-elapsed {
	margin-inline-start: auto;
	color: var(--kanso-success-legible);
	font-weight: 700;
	font-variant-numeric: tabular-nums;
}
/* Time tracking (#3536) */
.card-modal__time-add {
	display: flex;
	gap: 8px;
	align-items: center;
	flex-wrap: wrap;
	margin-top: 4px;
}
.card-modal__time-duration {
	width: 110px;
	flex: 0 0 auto;
}
.card-modal__time-note {
	flex: 1 1 120px;
	min-width: 120px;
}
.card-modal__time-entry {
	display: flex;
	gap: 8px;
	align-items: center;
	flex: 1;
	min-width: 0;
}
.card-modal__time-entry-duration {
	font-weight: 600;
	white-space: nowrap;
}
.card-modal__time-entry-note {
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.card-modal__time-entry-meta {
	margin-left: auto;
	display: flex;
	gap: 6px;
	align-items: center;
	white-space: nowrap;
}
.card-modal__link-add .card-modal__dashed-input {
	flex: 1;
	width: auto;
}

/* Relations */
.card-modal__relation-label {
	font-size: 0.72rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}
.card-modal__relation-group {
	list-style: none;
	margin: 0 0 6px;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}
.card-modal__relation-row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 8px;
	border-radius: 3px;
}
.card-modal__relation-row:hover {
	background: var(--color-background-hover);
}
.card-modal__relation-title {
	flex: 1;
	min-width: 0;
	text-align: left;
	border: none;
	background: transparent;
	padding: 0;
	color: var(--color-main-text);
	font-size: 0.875rem;
	cursor: pointer;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.card-modal__relation-title:hover {
	text-decoration: underline;
	color: var(--color-primary-element);
}
.card-modal__relation-title:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
	border-radius: 2px;
}
.card-modal__relation-title--hidden {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
	cursor: default;
}

.card-modal__visibility-select {
	margin-inline-start: auto;
	max-width: 320px;
}

.card-modal__relation-title--done {
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
}
.card-modal__relation-add {
	display: flex;
	gap: 8px;
	align-items: center;
	flex-wrap: wrap;
}
.card-modal__relation-kind,
.card-modal__relation-target {
	height: 34px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
	padding: 0 6px;
}
.card-modal__relation-target { flex: 1; min-width: 140px; }
.card-modal__relation-add-btn {
	height: 34px;
	padding: 0 14px;
	border: 1px solid var(--color-border);
	border-radius: 100px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.8125rem;
	cursor: pointer;
}
.card-modal__relation-add-btn:disabled {
	opacity: 0.5;
	cursor: default;
}

/* ── Discussion pane ─────────────────────────────────────────────────────── */
.card-modal__discussion {
	display: flex;
	flex-direction: column;
	border-left: 1px solid var(--color-border);
	background: var(--color-background-hover);
	min-width: 0;
	max-height: 64vh;
}
.card-modal__discussion-head {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 14px 18px;
	border-bottom: 1px solid var(--color-border);
}
.card-modal__discussion-head-icon { color: var(--color-text-maxcontrast); }
.card-modal__discussion-title {
	font-size: 0.8125rem;
	font-weight: 600;
}
.card-modal__discussion-count {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}
/* Discussion | Activity tab bar */
.card-modal__discussion-tabs {
	gap: 4px;
	padding: 8px 12px;
}
.card-modal__discussion-tab {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 10px;
	border: none;
	border-radius: 6px;
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 0.8125rem;
	font-weight: 600;
	cursor: pointer;
}
.card-modal__discussion-tab:hover { background: var(--color-background-hover); }
.card-modal__discussion-tab--active {
	background: var(--color-primary-element-light);
	color: var(--color-main-text);
}
/* Activity feed */
.card-modal__activity {
	flex: 1;
	overflow: auto;
	padding: 8px 12px;
}
.card-modal__activity-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}
.card-modal__activity-row {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px;
	padding: 6px 4px;
}
/* The diff panel is a full-width child that drops onto its own line below the
   avatar/text/time header (the row wraps). */
.card-modal__activity-diff-toggle {
	background: transparent;
	border: none;
	padding: 0 0 0 4px;
	margin: 0;
	font: inherit;
	font-size: 0.75rem;
	color: var(--color-primary-element);
	cursor: pointer;
	text-decoration: underline;
}
.card-modal__activity-diff-toggle:hover,
.card-modal__activity-diff-toggle:focus-visible {
	color: var(--color-primary-element-hover, var(--color-primary-element));
}
.card-modal__activity-diff {
	flex: 1 0 100%;
	margin: 2px 0 4px 32px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 6px);
	overflow: hidden;
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.75rem;
	line-height: 1.5;
}
.card-modal__activity-diff-line {
	display: flex;
	gap: 6px;
	padding: 0 6px;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
	color: var(--color-main-text);
}
.card-modal__activity-diff-sign {
	flex: 0 0 auto;
	width: 1ch;
	text-align: center;
	color: var(--color-text-maxcontrast);
	user-select: none;
}
.card-modal__activity-diff-body {
	flex: 1 1 auto;
	min-width: 0;
}
.card-modal__activity-diff-line--removed {
	background: color-mix(in srgb, var(--color-error) 15%, transparent);
}
.card-modal__activity-diff-line--removed .card-modal__activity-diff-sign {
	color: var(--color-error);
}
.card-modal__activity-diff-line--added {
	background: color-mix(in srgb, var(--color-success) 15%, transparent);
}
.card-modal__activity-diff-line--added .card-modal__activity-diff-sign {
	color: var(--color-success);
}
.card-modal__activity-text {
	flex: 1;
	font-size: 0.8125rem;
	color: var(--color-main-text);
	min-width: 0;
}
.card-modal__activity-time {
	flex: 0 0 auto;
	font-size: 0.72rem;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}
.card-modal__thread-scroll {
	flex: 1;
	overflow: auto;
	padding: 14px 16px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-height: 0;
}
.card-modal__discussion-empty {
	flex: 1;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 10px;
	padding: 32px;
	text-align: center;
}
.card-modal__discussion-empty-icon { color: var(--color-border-dark); }
.card-modal__discussion-empty span {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	max-width: 220px;
}
.card-modal__thread {
	display: flex;
	flex-direction: column;
	gap: 12px;
}
.card-modal__comment-group {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 10px;
	overflow: hidden;
	flex-shrink: 0;
}
/* Transient highlight when a deep link scrolls to a comment (#3870): a brief
   background/outline flash that fades out so the target is easy to spot without
   permanently altering the comment's chrome. */
.card-modal__comment-group--highlight {
	animation: kanso-comment-flash 3.6s ease-out 1;
}
.card-modal__comment--highlight {
	animation: kanso-comment-flash 3.6s ease-out 1;
	border-radius: 8px;
}
@keyframes kanso-comment-flash {
	0% {
		background: var(--color-primary-element-light, var(--color-primary-light));
		box-shadow: 0 0 0 2px var(--color-primary-element, var(--color-primary));
	}
	70% {
		background: var(--color-primary-element-light, var(--color-primary-light));
		box-shadow: 0 0 0 2px var(--color-primary-element, var(--color-primary));
	}
	100% {
		background: var(--color-main-background);
		box-shadow: 0 0 0 2px transparent;
	}
}
.card-modal__comment {
	display: flex;
	gap: 10px;
	padding: 12px 14px;
}
.card-modal__comment-avatar { flex-shrink: 0; }
.card-modal__comment-main {
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
	flex: 1;
}
.card-modal__comment-meta {
	display: flex;
	align-items: baseline;
	gap: 6px;
	flex-wrap: wrap;
}
.card-modal__comment-author {
	font-size: 0.8125rem;
	font-weight: 600;
}
.card-modal__comment-time,
.card-modal__comment-edited {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}
.card-modal__comment-edited { font-style: italic; }
.card-modal__comment-body {
	font-size: 0.875rem;
	line-height: 1.55;
	color: var(--color-main-text);
	word-break: break-word;
}
.card-modal__comment-body :deep(p) { margin: 0 0 0.5em; }
.card-modal__comment-body :deep(p:last-child) { margin-bottom: 0; }
.card-modal__comment-body :deep(code) {
	background: var(--color-border);
	border-radius: 3px;
	padding: 1px 4px;
	font-size: 0.85em;
}
.card-modal__comment-controls {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px;
	margin-top: 2px;
}
.card-modal__comment-link-btn {
	height: 24px;
	padding: 0 8px;
	margin-left: -8px;
	border: none;
	border-radius: 3px;
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 0.75rem;
	cursor: pointer;
}
.card-modal__comment-link-btn:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}
.card-modal__comment-icon-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}
.card-modal__comment-icon-btn:hover { background: var(--color-background-hover); }
.card-modal__comment-icon-btn--danger:hover { background: var(--color-error); color: #fff; }

/* Emoji reactions on comments (#3550) */
.card-modal__reactions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px;
	margin-top: 4px;
}
.card-modal__reaction-chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	height: 24px;
	padding: 0 8px;
	border: 1px solid var(--color-border);
	border-radius: 12px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.8rem;
	line-height: 1;
	cursor: pointer;
}
.card-modal__reaction-chip:hover:not(:disabled) { background: var(--color-background-hover); }
.card-modal__reaction-chip:disabled { cursor: default; opacity: 0.7; }
.card-modal__reaction-chip--mine {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light);
	color: var(--color-main-text);
}
.card-modal__reaction-count { font-variant-numeric: tabular-nums; }
.card-modal__reaction-add-wrap { position: relative; display: inline-flex; }
.card-modal__reaction-add {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}
.card-modal__reaction-add:hover { background: var(--color-background-hover); color: var(--color-main-text); }
.card-modal__reaction-picker {
	position: absolute;
	bottom: calc(100% + 4px);
	/* Anchor to the right: the add button sits at the right end of the comment
	   controls row, so open leftward into the comment to avoid the popover being
	   clipped by the scroll container's / comment group's overflow. */
	right: 0;
	z-index: 10;
	display: flex;
	gap: 2px;
	padding: 4px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	box-shadow: 0 2px 8px var(--color-box-shadow, rgba(0, 0, 0, 0.2));
}
.card-modal__reaction-picker-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	border: none;
	border-radius: 4px;
	background: transparent;
	font-size: 1.1rem;
	line-height: 1;
	cursor: pointer;
}
.card-modal__reaction-picker-btn:hover { background: var(--color-background-hover); }
.card-modal__comment--reply {
	border-top: 1px solid var(--color-border);
	background: var(--color-main-background);
	padding-left: 44px;
}
.card-modal__replies {
	border-top: 1px solid var(--color-border);
	background: var(--color-background-hover);
}
.card-modal__reply-compose {
	padding: 6px 14px 12px 44px;
	border-top: 1px solid var(--color-border);
	background: var(--color-background-hover);
	display: flex;
	flex-direction: column;
	gap: 8px;
}
.card-modal__comment-edit-textarea {
	width: 100%;
	box-sizing: border-box;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
	font-size: 0.875rem;
	resize: vertical;
}
.card-modal__comment-edit-textarea:focus {
	outline: none;
	border-color: var(--color-primary-element);
}
.card-modal__comment-edit-actions {
	display: flex;
	gap: 8px;
}

/* Composer (pinned) */
.card-modal__composer {
	border-top: 1px solid var(--color-border);
	background: var(--color-main-background);
	padding: 12px 16px;
	display: flex;
	gap: 10px;
	align-items: flex-start;
}
.card-modal__composer-avatar { flex-shrink: 0; }
.card-modal__composer-main {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-width: 0;
}
.card-modal__composer-textarea {
	width: 100%;
	box-sizing: border-box;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
	font-size: 0.875rem;
	line-height: 1.5;
	resize: vertical;
}
.card-modal__composer-textarea:focus {
	outline: none;
	border-color: var(--color-primary-element);
}
.card-modal__composer-actions {
	display: flex;
	align-items: center;
	gap: 10px;
}

/* ── Description save conflict (#9845) ───────────────────────────────────── */
.card-modal__desc-conflict {
	margin-top: 10px;
	padding: 12px;
	border: 1px solid var(--color-warning, var(--color-border-dark));
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
}
.card-modal__desc-conflict-msg {
	margin: 0 0 8px;
	font-size: 0.85rem;
}
.card-modal__desc-conflict-theirs {
	margin: 0;
	max-height: 240px;
	overflow: auto;
	padding: 8px;
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.8rem;
	white-space: pre-wrap;
	word-break: break-word;
	user-select: text;
}
.card-modal__desc-conflict-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 10px;
}

/* ── Shared error text ───────────────────────────────────────────────────── */
.card-modal__save-error {
	font-size: 0.8rem;
	color: var(--color-error);
}
.card-modal__action-error {
	padding: 0 24px 8px;
}

/* ── Personal reminders (#3816) ──────────────────────────────────────────── */
.card-modal__reminders {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	padding: 0 24px 8px;
}
.card-modal__reminder-chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 4px 2px 8px;
	border-radius: var(--border-radius-pill, 16px);
	background: var(--color-background-hover);
	font-size: 0.85em;
}
.card-modal__reminder-time { color: var(--color-main-text); }
.card-modal__reminder-cancel {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	border: none;
	background: transparent;
	cursor: pointer;
	padding: 2px;
	border-radius: 50%;
	color: var(--color-text-maxcontrast);
}
.card-modal__reminder-cancel:hover:not(:disabled) {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}
.card-modal__reminder-custom {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	padding: 0 24px 8px;
}
.card-modal__reminder-custom-label { font-size: 0.9em; color: var(--color-text-maxcontrast); }
.card-modal__reminder-custom-input {
	padding: 4px 8px;
}

/* ── Mobile tab bar (hidden on desktop) ──────────────────────────────────── */
.card-modal__tabbar { display: none; }
.card-modal__tab {
	flex: 1;
	height: 44px;
	border: none;
	border-bottom: 2px solid transparent;
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
	cursor: pointer;
}
.card-modal__tab--active {
	border-bottom-color: var(--color-primary-element);
	color: var(--color-primary-element);
	font-weight: 600;
}
/* Comment-count badge on the Discussion tab — its own spacing so the number
   never renders glued to the label (Vue condenses a leading text space away). */
.card-modal__tab-count {
	margin-inline-start: 6px;
	font-variant-numeric: tabular-nums;
	color: var(--color-text-maxcontrast);
}

/* Keep every round button/dot a perfect circle (#3492). Two forces turn them
   into ovals: a flex row can shrink the width, and Nextcloud's global
   `button { min-height: 34px }` stretches the height past the set size. Pin the
   width (flex-shrink) and clear the inherited min-height/min-width so the equal
   width/height wins. */
.card-modal__pill-x,
.card-modal__field-clear,
.card-modal__checklist-item-delete,
.card-modal__comment-icon-btn,
.card-modal__reaction-add,
.card-modal__child-remove,
.card-modal__icon-btn,
.card-modal__child-dot,
.card-modal__avatar-overflow {
	flex-shrink: 0;
	min-width: 0;
	min-height: 0;
	aspect-ratio: 1;
}

/* Discussion collapse toggle (#9854) - same ghost idiom as the expand button,
   but it can grow to fit the comment-count badge shown while collapsed. Declared
   ABOVE the responsive block below, which switches it off in the tabbed layout. */
.card-modal__discussion-toggle {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 4px;
	min-width: 34px;
	height: 34px;
	padding: 0 6px;
	border: none;
	border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}
.card-modal__discussion-toggle:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}
/* Collapsed reads as "pressed" - keep that on hover too, so the affordance does
   not vanish exactly when the pointer is on it. */
.card-modal__discussion-toggle--collapsed,
.card-modal__discussion-toggle--collapsed:hover {
	color: var(--color-main-text);
	background: var(--color-background-dark);
}
.card-modal__discussion-toggle-count {
	font-size: 0.75rem;
	font-weight: 600;
	font-variant-numeric: tabular-nums;
}

/* ── Collapsed discussion pane (#9854) ───────────────────────────────────────
   Scoped to the desktop split layout on purpose. Below 680px the panes stack and
   tab-switch, and a persisted collapse flag from a desktop session must never
   apply there - it would `display: none` the pane and render a blank Discussion
   tab. Keeping these rules inside a media block makes that impossible regardless
   of specificity or source order - and the block is the exact complement of the
   stacked-layout query, so a fractional viewport width (zoom, display scaling)
   can never fall between the two and leave the toggle inert. */
@media not all and (max-width: 680px) {
	.card-modal--discussion-collapsed .card-modal__body {
		grid-template-columns: minmax(0, 1fr);
	}
	.card-modal--discussion-collapsed .card-modal__discussion,
	.card-modal--discussion-collapsed .card-modal__resizer {
		display: none;
	}
}

/* ── Responsive: stack panes, switch via tabs ────────────────────────────── */
@media (max-width: 680px) {
	/* Reflow the header so the action cluster wraps below the title instead of
	   squeezing the title column toward 0 width (which made the title render one
	   letter per line). The title row and the actions row each take the full
	   width; the title column can no longer be crushed by flex-shrink:0 actions. */
	.card-modal__header {
		flex-wrap: wrap;
		padding: 14px 16px 10px;
	}
	/* In modal mode NcModal teleports its own close (X) button to the top-right of
	   the modal container, outside this component's scoped tree. Reserve room on
	   the right so the breadcrumb/title never slide under it. */
	.card-modal--mode-modal .card-modal__header { padding-right: 48px; }
	.card-modal__header-main {
		/* Force the title column onto its own full-width row. */
		flex: 1 0 100%;
		min-width: 0;
	}
	.card-modal__header-actions {
		/* Actions drop to their own row below the title and may wrap among
		   themselves on very narrow screens rather than overflowing. */
		flex-basis: 100%;
		flex-wrap: wrap;
	}
	.card-modal__title {
		font-size: 1.2rem;
		/* Wrap on word boundaries and only break inside a word as a last resort,
		   so a long title never renders one character per line. */
		word-break: normal;
		overflow-wrap: anywhere;
	}
	/* Attributes stay at the top and wrap cleanly instead of scrolling as a
	   cramped one-line strip. */
	.card-modal__attrbar { flex-wrap: wrap; gap: 8px; padding: 12px 16px; }
	.card-modal__attr-right { margin-left: 0; }
	/* Attribute-bar popovers (review request, type, due, label, assignee…) are
	   absolutely positioned off a small pill, so a right-anchored one can spill
	   past the screen edge once the pill wraps mid-row (#4058). Pin them to the
	   viewport as an inset sheet so they always render fully on-screen. */
	.card-modal__attrbar .card-modal__popover {
		position: fixed;
		left: 12px;
		right: 12px;
		top: auto;
		bottom: 12px;
		width: auto;
		min-width: 0;
		max-width: none;
		max-height: 60vh;
	}
	.card-modal__body { grid-template-columns: 1fr; }
	/* Panes stack: the persisted split width and its drag handle are ignored. */
	.card-modal__resizer { display: none; }
	/* The tab bar below switches panes here, so the desktop collapse toggle has
	   no job. Its persisted state is inert too - the collapse rules are confined
	   to the complement of this query (see #9854). */
	.card-modal__discussion-toggle { display: none; }
	.card-modal__content,
	.card-modal__discussion { max-height: none; }
	.card-modal__discussion { border-left: none; border-top: 1px solid var(--color-border); }
	.card-modal__children-grid { grid-template-columns: 1fr; }
	.card-modal__tabbar {
		display: flex;
		position: sticky;
		top: 0;
		z-index: 20;
		background: var(--color-main-background);
		border-bottom: 1px solid var(--color-border);
	}
	/* Tab switching: show only the active pane */
	.card-modal--tab-card .card-modal__discussion { display: none; }
	.card-modal--tab-discussion .card-modal__content { display: none; }
	/* Larger touch targets on mobile */
	.card-modal__checklist-item { min-height: 44px; }
	.card-modal__checklist-checkbox { width: 18px; height: 18px; }
}

/* ── @-mention inline chip (rendered in markdown output) ─────────────────── */
:deep(.kanso-mention) {
	display: inline;
	padding: 1px 5px;
	border-radius: 4px;
	background: var(--color-primary-element-light, rgba(0, 130, 201, 0.1));
	color: var(--color-primary-element, #0082c9);
	font-weight: 500;
	white-space: nowrap;
	cursor: pointer;
}
:deep(.kanso-mention:hover) {
	text-decoration: underline;
}

/* Card cross-reference link (KAN-123 → the target card's title). Carries no
 * href; the delegated click handler navigates. */
:deep(.kanso-cardref) {
	color: var(--color-primary-element, #0082c9);
	font-weight: 500;
	cursor: pointer;
	text-decoration: none;
}
:deep(.kanso-cardref:hover) {
	text-decoration: underline;
}

/* ── @-mention autocomplete dropdown ────────────────────────────────────── */
.card-modal__mention-wrap {
	position: relative;
}
.card-modal__mention-dropdown {
	position: absolute;
	bottom: calc(100% + 4px);
	left: 0;
	z-index: 40;
	min-width: 220px;
	max-height: 260px;
	overflow: auto;
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 6px;
	margin: 0;
	list-style: none;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 10px;
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
}
.card-modal__assign-option--highlighted {
	background: var(--color-background-hover);
}

/* ── Custom fields section (#3537) ──────────────────────────────────────────── */

.card-modal__cf-list {
	list-style: none;
	margin: 8px 0 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.card-modal__cf-row {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.card-modal__cf-label {
	flex: 0 0 120px;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.card-modal__cf-input,
.card-modal__cf-select {
	flex: 1 1 120px;
	min-width: 80px;
	max-width: 260px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 4px 8px;
	font-size: 0.875rem;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.card-modal__cf-input:disabled,
.card-modal__cf-select:disabled {
	opacity: 0.6;
	cursor: default;
}

/* Expand-to-full-page button (modal header, #3817) - matches the ghost icon
   buttons in the header cluster. */
.card-modal__expand-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 34px;
	height: 34px;
	border: none;
	border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}
.card-modal__expand-btn:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

/* Full-page shell: the card content is no longer inside a fixed-width modal
   container, so give it a centred, comfortable max width. */
.card-modal--mode-page {
	/* Full-page view fills the available width (the content panes keep their own
	   inner padding, so text isn't flush to the edges). */
	width: 100%;
}
</style>
