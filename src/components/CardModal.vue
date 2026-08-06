<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal
		:show="true"
		:name="cardTitle"
		size="large"
		class="card-modal-modal"
		@close="onModalClose">
		<div
			class="card-modal"
			:class="`card-modal--tab-${viewMode}`"
			@keydown.escape="onModalEscape">
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

			<!-- Error state -->
			<div v-else-if="isError" class="card-modal__error">
				{{ t('kanso', 'Failed to load card details.') }}
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
							<!-- Copyable human-readable reference id (e.g. KAN-123) -->
							<button
								v-if="cardHumanId"
								class="card-modal__ref"
								type="button"
								:title="t('kanso', 'Copy reference {ref}', { ref: cardHumanId })"
								@click="copyCardRef">
								{{ cardHumanId }}
							</button>
							<ChevronRightIcon :size="14" class="card-modal__crumb-chevron" />
							<span class="card-modal__attr card-modal__status-wrap">
								<button
									class="card-modal__status-chip card-modal__status-chip--btn"
									:class="`card-modal__status-chip--${currentStatus}`"
									:disabled="updateCard.isPending.value"
									:aria-expanded="openPicker === 'status'"
									:title="t('kanso', 'Change status')"
									@click="togglePicker('status')">
									{{ statusChipLabel }}
									<ChevronDownIcon :size="12" />
								</button>
								<div v-if="openPicker === 'status'" class="card-modal__popover">
									<button
										v-for="opt in STATUS_OPTIONS"
										:key="opt.key"
										class="card-modal__popover-opt"
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

					<div class="card-modal__header-actions">
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
										:show-user-status="false"
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
											:show-user-status="false"
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
								@click="openCopyDialog">
								<template #icon>
									<ContentDuplicateIcon :size="20" />
								</template>
								{{ t('kanso', 'Copy to…') }}
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
							<NcActionButton :close-after-click="true" @click="handleArchiveToggle">
								<template #icon>
									<ArchiveArrowDownIcon v-if="!cardData.archived" :size="20" />
									<ArchiveArrowUpIcon v-else :size="20" />
								</template>
								{{ cardData.archived ? t('kanso', 'Unarchive') : t('kanso', 'Archive') }}
							</NcActionButton>
							<NcActionButton :close-after-click="true" @click="handleDelete">
								<template #icon>
									<TrashCanIcon :size="20" />
								</template>
								{{ t('kanso', 'Delete') }}
							</NcActionButton>
						</NcActions>
					</div>
				</header>
				<span v-if="actionError" class="card-modal__save-error card-modal__action-error">{{ actionError }}</span>
				<span v-if="subscriptionError" class="card-modal__save-error">{{ subscriptionError }}</span>

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
							{{ currentPriority > 0 ? t('kanso', currentPriorityLevel.label) : t('kanso', 'Priority') }}
						</button>
						<div v-if="openPicker === 'priority'" class="card-modal__popover">
							<button
								v-for="level in PRIORITY_LEVELS"
								:key="level.value"
								class="card-modal__popover-opt"
								:class="{ 'card-modal__popover-opt--active': currentPriority === level.value }"
								:disabled="setPriority.isPending.value"
								@click="handleSetPriority(level.value); openPicker = null">
								{{ level.value === 0 ? t('kanso', 'None') : t('kanso', level.label) }}
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
							{{ currentType ? t('kanso', currentType.label) : t('kanso', 'Type') }}
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
								{{ t('kanso', tp.label) }}
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
							<CalendarIcon :size="14" />
							{{ cardData.duedate ? dueDateLabel : t('kanso', 'Due date') }}
						</button>
						<div v-if="openPicker === 'due'" class="card-modal__popover card-modal__popover--pad">
							<label class="card-modal__field-label">{{ t('kanso', 'Due date') }}</label>
							<div class="card-modal__field-row">
								<input
									class="card-modal__date-input"
									:type="isAllDay ? 'date' : 'datetime-local'"
									:value="dueDateInputValue"
									@change="handleDueDateChange">
								<button v-if="cardData.duedate" class="card-modal__field-clear" :title="t('kanso', 'Clear due date')" @click="clearDueDate">
									<CloseIcon :size="14" />
								</button>
							</div>
							<label class="card-modal__allday">
								<input
									type="checkbox"
									:checked="isAllDay"
									@change="toggleAllDay($event.target.checked)">
								{{ t('kanso', 'All day (no time)') }}
							</label>
							<label class="card-modal__field-label">{{ t('kanso', 'Start date') }}</label>
							<div class="card-modal__field-row">
								<input
									class="card-modal__date-input"
									type="datetime-local"
									:value="startDateInputValue"
									@change="handleStartDateChange">
								<button v-if="cardData.startDate" class="card-modal__field-clear" :title="t('kanso', 'Clear start date')" @click="clearStartDate">
									<CloseIcon :size="14" />
								</button>
							</div>
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

					<!-- Cover colour -->
					<div v-if="canEdit" class="card-modal__attr">
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
							:show-user-status="false"
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
									:show-user-status="false"
									:disable-tooltip="true" />
								<span>{{ p.displayName }}</span>
							</button>
						</div>
					</div>
					<span v-if="assigneeError" class="card-modal__save-error">{{ assigneeError }}</span>

						<!-- Contacts (#3530) - read-only Contacts links, only when the Contacts app is available -->
						<template v-if="contactsAvailable">
							<span
								v-for="c in cardContacts"
								:key="c.contactUri"
								class="card-modal__assignee-pill">
								<NcAvatar
									:display-name="c.displayName"
									:size="22"
									:show-user-status="false"
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
												:show-user-status="false"
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
								:show-user-status="false"
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
										:show-user-status="false"
										:disable-tooltip="true" />
									<span>{{ p.displayName }}</span>
								</button>
							</div>
						</div>
					</div>
				</div>

				<!-- Mobile tab bar - visible only on narrow viewports, sits under the attribute bar -->
				<div class="card-modal__tabbar">
					<button
						class="card-modal__tab"
						:class="{ 'card-modal__tab--active': viewMode === 'card' }"
						@click="viewMode = 'card'">
						{{ t('kanso', 'Card') }}
					</button>
					<button
						class="card-modal__tab"
						:class="{ 'card-modal__tab--active': viewMode === 'discussion' }"
						@click="viewMode = 'discussion'">
						{{ t('kanso', 'Discussion') }}<span v-if="commentCount > 0"> {{ commentCount }}</span>
					</button>
				</div>

				<!-- Body: content (left) | discussion (right) -->
				<div class="card-modal__body">
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
								<div class="card-modal__md-toolbar" role="toolbar" :aria-label="t('kanso', 'Formatting')">
									<button type="button" class="card-modal__md-btn" :title="t('kanso', 'Bold')" @mousedown.prevent @click="mdToolbar.bold()"><FormatBoldIcon :size="18" /></button>
									<button type="button" class="card-modal__md-btn" :title="t('kanso', 'Italic')" @mousedown.prevent @click="mdToolbar.italic()"><FormatItalicIcon :size="18" /></button>
									<button type="button" class="card-modal__md-btn" :title="t('kanso', 'Heading')" @mousedown.prevent @click="mdToolbar.heading()"><FormatHeaderPoundIcon :size="18" /></button>
									<span class="card-modal__md-sep" />
									<button type="button" class="card-modal__md-btn" :title="t('kanso', 'Bulleted list')" @mousedown.prevent @click="mdToolbar.bulletList()"><FormatListBulletedIcon :size="18" /></button>
									<button type="button" class="card-modal__md-btn" :title="t('kanso', 'Checklist')" @mousedown.prevent @click="mdToolbar.checklist()"><FormatListChecksIcon :size="18" /></button>
									<button type="button" class="card-modal__md-btn" :title="t('kanso', 'Quote')" @mousedown.prevent @click="mdToolbar.quote()"><FormatQuoteCloseIcon :size="18" /></button>
									<span class="card-modal__md-sep" />
									<button type="button" class="card-modal__md-btn" :title="t('kanso', 'Inline code')" @mousedown.prevent @click="mdToolbar.inlineCode()"><CodeTagsIcon :size="18" /></button>
									<button type="button" class="card-modal__md-btn" :title="t('kanso', 'Link')" @mousedown.prevent @click="mdToolbar.link()"><LinkVariantIcon :size="18" /></button>
									<span class="card-modal__md-toolbar-spacer" />
									<button
										type="button"
										class="card-modal__md-btn"
										:class="{ 'card-modal__md-btn--active': showDescPreview }"
										:aria-pressed="showDescPreview"
										:title="t('kanso', 'Toggle preview')"
										@mousedown.prevent
										@click="showDescPreview = !showDescPreview"><EyeOutlineIcon :size="18" /></button>
								</div>
								<div class="card-modal__mention-wrap">
									<textarea
										ref="descTextareaRef"
										v-model="draftDescription"
										class="card-modal__desc-textarea"
										:placeholder="t('kanso', 'Add a description…')"
										rows="8"
										@keydown="onDescKeydown"
										@paste="onDescPaste"
										@input="mentionDesc.onInput()" />
									<ul
										v-if="mentionDesc.isOpen.value && mentionDesc.matches.value.length > 0"
										class="card-modal__mention-dropdown">
										<li
											v-for="(p, idx) in mentionDesc.matches.value"
											:key="p.uid"
											class="card-modal__assign-option"
											:class="{ 'card-modal__assign-option--highlighted': idx === mentionDesc.highlightedIndex.value }"
											@mousedown.prevent="mentionDesc.select(p)">
											<NcAvatar
												:user="p.uid"
												:display-name="p.displayName"
												:size="24"
												:show-user-status="false"
												:disable-tooltip="true" />
											<span>{{ p.displayName }}</span>
										</li>
									</ul>
								</div>
								<div v-if="showDescPreview" class="card-modal__desc-preview">
									<span class="card-modal__desc-preview-label">{{ t('kanso', 'Preview') }}</span>
									<div class="card-modal__desc-rendered" v-html="draftPreview" />
								</div>
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
									<button
										class="card-modal__checklist-item-delete"
										:title="t('kanso', 'Delete item')"
										:disabled="deleteItem.isPending.value"
										@click="handleDeleteItem(item)">
										<CloseIcon :size="14" />
									</button>
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

						<!-- GitHub links -->
						<section class="card-modal__section card-modal__section--tight">
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

						<!-- File attachments (#3526) -->
						<section class="card-modal__section card-modal__section--tight">
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

						<!-- Time tracking (#3536): manual entries + per-card total -->
						<section class="card-modal__section card-modal__section--tight">
							<div class="card-modal__section-inline">
								<ClockOutlineIcon :size="16" class="card-modal__eyebrow-icon" />
								<span class="card-modal__eyebrow">{{ t('kanso', 'Time tracking') }}</span>
								<span v-if="timeSpentTotal > 0" class="card-modal__attachment-size">{{ formatDuration(timeSpentTotal) }}</span>
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
											<button
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

					<!-- RIGHT: discussion pane -->
					<aside class="card-modal__discussion">
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
										:show-user-status="false" />
									<span class="card-modal__activity-text">
										<strong>{{ item.actorName || item.actor || t('kanso', 'Someone') }}</strong>
										{{ activityVerbText(item) }}
									</span>
									<span class="card-modal__activity-time">{{ relativeTime(item.timestamp) }}</span>
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
									class="card-modal__comment-group">
									<div class="card-modal__comment">
										<NcAvatar
											:user="topComment.author"
											:display-name="topComment.authorDisplayName || topComment.author"
											:size="28"
											:show-user-status="false"
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
													@click="openReplyBox(topComment.id)">
													{{ t('kanso', 'Reply') }}
												</button>
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
														@click.stop="toggleReactionPicker(topComment.id)">
														<EmoticonHappyOutlineIcon :size="14" />
													</button>
													<div v-if="reactionPickerFor === topComment.id" class="card-modal__reaction-picker">
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

									<div v-if="replyingToId === topComment.id && canEdit" class="card-modal__reply-compose">
										<textarea
											:ref="(el) => setReplyRef(topComment.id, el)"
											v-model="replyBody"
											class="card-modal__comment-edit-textarea"
											:placeholder="t('kanso', 'Write a reply…')"
											rows="2"
											@keydown.ctrl.enter.prevent="submitReply(topComment.id)"
											@keydown.meta.enter.prevent="submitReply(topComment.id)"
											@keydown.escape.stop="closeReplyBox" />
										<div class="card-modal__comment-edit-actions">
											<NcButton type="primary" :disabled="addComment.isPending.value || !replyBody.trim()" @click="submitReply(topComment.id)">
												{{ t('kanso', 'Post reply') }}
											</NcButton>
											<NcButton @click="closeReplyBox">{{ t('kanso', 'Cancel') }}</NcButton>
										</div>
									</div>

									<div v-if="replies.length > 0" class="card-modal__replies">
										<div v-for="reply in replies" :key="reply.id" class="card-modal__comment card-modal__comment--reply">
											<NcAvatar
												:user="reply.author"
												:display-name="reply.authorDisplayName || reply.author"
												:size="24"
												:show-user-status="false"
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
															@click.stop="toggleReactionPicker(reply.id)">
																<EmoticonHappyOutlineIcon :size="14" />
														</button>
														<div v-if="reactionPickerFor === reply.id" class="card-modal__reaction-picker">
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
								:show-user-status="false"
								class="card-modal__composer-avatar" />
							<div class="card-modal__composer-main">
								<div class="card-modal__mention-wrap">
									<textarea
										ref="newCommentTextareaRef"
										v-model="newCommentBody"
										class="card-modal__composer-textarea"
										:placeholder="t('kanso', 'Start a new thread…')"
										rows="2"
										:disabled="addComment.isPending.value"
										@keydown="onCommentKeydown"
										@paste="onCommentPaste"
										@input="mentionComment.onInput()" />
									<ul
										v-if="mentionComment.isOpen.value && mentionComment.matches.value.length > 0"
										class="card-modal__mention-dropdown">
										<li
											v-for="(p, idx) in mentionComment.matches.value"
											:key="p.uid"
											class="card-modal__assign-option"
											:class="{ 'card-modal__assign-option--highlighted': idx === mentionComment.highlightedIndex.value }"
											@mousedown.prevent="mentionComment.select(p)">
											<NcAvatar
												:user="p.uid"
												:display-name="p.displayName"
												:size="24"
												:show-user-status="false"
												:disable-tooltip="true" />
											<span>{{ p.displayName }}</span>
										</li>
									</ul>
								</div>
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
	</NcModal>

	<!-- Copy to… : pick a target board + stack (same or another board the user can edit). -->
	<NcModal
		v-if="showCopyDialog"
		size="small"
		:name="t('kanso', 'Copy card to…')"
		@close="showCopyDialog = false">
		<div class="card-modal__copy-dialog">
			<h2 class="card-modal__copy-title">{{ t('kanso', 'Copy card to…') }}</h2>
			<p class="card-modal__copy-hint">
				{{ t('kanso', 'Duplicates the title, description, labels, checklist, estimate, priority and status. Comments, activity and assignees are not copied.') }}
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
					@click="confirmCopy">
					{{ copyPending ? t('kanso', 'Copying…') : t('kanso', 'Copy') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script setup>
import { ref, computed, nextTick, watch, onMounted, onBeforeUnmount } from 'vue'
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { useRouter, useRoute } from 'vue-router'
import { getCurrentUser } from '@nextcloud/auth'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showUndo, showSuccess, showError } from '@nextcloud/dialogs'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import EmoticonHappyOutlineIcon from 'vue-material-design-icons/EmoticonHappyOutline.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import GithubIcon from 'vue-material-design-icons/Github.vue'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
import ContentDuplicateIcon from 'vue-material-design-icons/ContentDuplicate.vue'
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
import TimerSandIcon from 'vue-material-design-icons/TimerSand.vue'
import PaletteIcon from 'vue-material-design-icons/Palette.vue'
import FolderMultipleOutlineIcon from 'vue-material-design-icons/FolderMultipleOutline.vue'
import PaperclipIcon from 'vue-material-design-icons/Paperclip.vue'
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'
import TableColumnIcon from 'vue-material-design-icons/TableColumn.vue'
import { useMentionAutocomplete } from '../composables/useMentionAutocomplete.js'
import { useMarkdownToolbar } from '../composables/useMarkdownToolbar.js'
import FormatBoldIcon from 'vue-material-design-icons/FormatBold.vue'
import FormatItalicIcon from 'vue-material-design-icons/FormatItalic.vue'
import FormatHeaderPoundIcon from 'vue-material-design-icons/FormatHeaderPound.vue'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'
import FormatListChecksIcon from 'vue-material-design-icons/FormatListChecks.vue'
import FormatQuoteCloseIcon from 'vue-material-design-icons/FormatQuoteClose.vue'
import CodeTagsIcon from 'vue-material-design-icons/CodeTags.vue'
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
import { useCardActions } from '../composables/useCardActions.js'
import { useChecklist } from '../composables/useChecklist.js'
import { useComments, buildCommentTree, REACTION_EMOJI } from '../composables/useComments.js'
import { buildCardPrompt } from '../utils/cardPrompt.js'
import { useCardHierarchy } from '../composables/useCardHierarchy.js'
import { boardQueryKey } from '../composables/queryKeys.js'
import { useSubscription } from '../composables/useSubscription.js'
import { useCardLinks, branchName } from '../composables/useCardLinks.js'
import { useCardAttachments } from '../composables/useCardAttachments.js'
import { useCardTimeEntries } from '../composables/useCardTimeEntries.js'
import { useImagePaste } from '../composables/useImagePaste.js'
import { cardAttachmentUrl, cardAttachmentInlineUrl } from '../services/api.js'
import { addCardRelation as apiAddCardRelation, removeCardRelation as apiRemoveCardRelation, moveCard as apiMoveCard, getCardActivity as apiGetCardActivity, copyCard as apiCopyCard, fetchBoard as apiFetchBoard, resolveCardRef as apiResolveCardRef, setCardTemplate as apiSetCardTemplate } from '../services/api.js'
import { useBoards } from '../composables/useBoards.js'
import { useCardFields } from '../composables/useCardFields.js'
import { cssColor, LABEL_COLOR_PRESETS, readableColor } from '../services/color.js'
import { humanId } from '../services/humanId.js'
import { renderMarkdown, buildCardRefMap } from '../services/markdown.js'

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
})

const router = useRouter()
const route = useRoute()

// Board id from route params - modal is a child route of /boards/:id
const boardId = computed(() => route.params.id)

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

watch([() => props.cardId, boardId], async ([cardId, bId]) => {
	refResolveError.value = false
	if (isNumericCardId.value || !cardId || !bId) return
	try {
		const { cardId: numericId } = await apiResolveCardRef(bId, cardId)
		router.replace({ name: 'card-modal', params: { id: String(bId), cardId: String(numericId) } })
	} catch (err) {
		// Unknown / mismatched / malformed reference: leave the modal in a
		// not-found state rather than fetching a bogus numeric id.
		refResolveError.value = true
	}
}, { immediate: true })

const { data: cardData, isLoading: cardIsLoading, isError: cardIsError, updateCard } = useCard(
	computed(() => props.cardId),
	// Only fetch once the id is numeric (a human ref is redirected first).
	computed(() => isOpen.value && isNumericCardId.value),
)
// While a human reference is being resolved to its numeric id, show the loading
// skeleton (the card query is still disabled at that point).
const isLoading = computed(() => cardIsLoading.value || (!isNumericCardId.value && !refResolveError.value))
// Surface a failed human-ref resolution through the same error path as a failed
// card fetch, so the template's not-found branch covers both.
const isError = computed(() => cardIsError.value || refResolveError.value)

// Read board data from cache (same queryKey as BoardView - no extra request).
const { data: boardData } = useBoard(boardId)
const boardLabels = computed(() => boardData.value?.labels ?? [])
const boardReviewTypes = computed(() => boardData.value?.reviewTypes ?? [])
const boardCardFields = computed(() => boardData.value?.cardFields ?? [])

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
	const seq = ++contactSearchSeq
	contactSearching.value = true
	try {
		const results = await fetchCardContacts(resolveContactBoardId(), query)
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
	if (b !== null && typeof b === 'object' && b.value !== undefined) return b.value
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

// Probe availability on mount so the picker shows for an enabled instance even
// before the user interacts (an empty-query search doubles as the probe).
runContactSearch('')

onBeforeUnmount(() => {
	if (contactSearchTimer) clearTimeout(contactSearchTimer)
})

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
	const pad = (n) => String(n).padStart(2, '0')
	const date = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
	return isAllDay.value ? date : `${date}T${pad(d.getHours())}:${pad(d.getMinutes())}`
})

async function handleDueDateChange(event) {
	const val = event.target.value
	if (!val) return
	// An all-day "YYYY-MM-DD" parses as UTC midnight; a datetime-local as local.
	const iso = new Date(val).toISOString()
	try {
		await updateCard.mutateAsync({ data: { duedate: iso } })
	} catch (err) {
		saveError.value = err?.response?.data?.error || t('kanso', 'Failed to update due date.')
	}
}

async function toggleAllDay(checked) {
	try {
		await updateCard.mutateAsync({ data: { allDay: checked } })
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
	const pad = (n) => String(n).padStart(2, '0')
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
})

async function handleStartDateChange(event) {
	const val = event.target.value
	if (!val) return
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

function startDescriptionEdit() {
	draftDescription.value = cardData.value?.description || ''
	editingDescription.value = true
	saveError.value = ''
	showDescPreview.value = false
}

function cancelDescriptionEdit() {
	editingDescription.value = false
	saveError.value = ''
}

async function saveDescription() {
	isSaving.value = true
	saveError.value = ''
	try {
		await updateCard.mutateAsync({ data: { description: draftDescription.value } })
		editingDescription.value = false
	} catch (err) {
		saveError.value =
			err?.response?.data?.error || t('kanso', 'Failed to save.')
	} finally {
		isSaving.value = false
	}
}

// ── Checklist ────────────────────────────────────────────────────────────────
const {
	items: checklistQuery,
	addItem,
	toggleItem,
	renameItem,
	deleteItem,
	moveItem,
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

// verb → human phrase. Falls back to a generic "updated this card".
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
}
function activityVerbText(item) {
	const fn = ACTIVITY_VERBS[item.verb]
	return fn ? fn() : t('kanso', 'updated this card')
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

function toggleReactionPicker(commentId) {
	reactionPickerFor.value = reactionPickerFor.value === commentId ? null : commentId
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

const flatComments = computed(() => commentsQuery.data.value ?? [])
const commentThread = computed(() => buildCommentTree(flatComments.value))
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

async function openReplyBox(parentId) {
	replyingToId.value = parentId
	replyBody.value = ''
	await nextTick()
	replyRefs[parentId]?.focus()
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

// ── Copy to… (duplicate card into a target board/stack) ──────────────────────
const showCopyDialog = ref(false)
const copyTargetBoardId = ref(null)
const copyTargetStackId = ref('')
const copyStackOptions = ref([])
const copyStacksLoading = ref(false)
const copyPending = ref(false)
const copyError = ref('')

// All boards the user can see - the picker offers every board; a copy into one
// the user cannot EDIT is rejected server-side and surfaced as copyError.
const { data: allBoardsData } = useBoards()
const copyBoardOptions = computed(() =>
	(allBoardsData.value ?? []).filter((b) => !b.archived),
)

const copyIsCrossBoard = computed(() =>
	copyTargetBoardId.value != null && Number(copyTargetBoardId.value) !== Number(boardId.value),
)

async function openCopyDialog() {
	copyError.value = ''
	copyTargetStackId.value = ''
	// Default the target to the card's current board so the common case (copy
	// within the same board) is one click away.
	copyTargetBoardId.value = Number(boardId.value)
	showCopyDialog.value = true
	await loadCopyStacks(Number(boardId.value))
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
	const from = route.query.from
	if (MY_WORK_RETURN_ROUTES.includes(from)) {
		// Preserve the hub tab when returning to the /my-work hub.
		const query = from === 'my-work' && route.query.tab ? { tab: route.query.tab } : undefined
		router.push({ name: from, query })
		return
	}
	router.push({ name: 'board', params: { id: route.params.id } })
}

// Escape at the modal root: an open attribute popover takes precedence — close
// it, not the whole card (which would discard an in-progress edit). Inline edits
// (title/description/comment) stop propagation themselves, so they never reach here.
function onModalEscape() {
	if (openPicker.value !== null) {
		openPicker.value = null
		return
	}
	closeModal()
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
function onDocumentMousedown(event) {
	const target = event.target
	if (!(target instanceof Element) || !target.classList.contains('modal-wrapper')) {
		return
	}
	// Scope to THIS card's modal (guard against other stacked NcModals on the page).
	if (!target.closest('.card-modal-modal')) {
		return
	}
	onModalClose()
}
onMounted(() => {
	document.addEventListener('mousedown', onDocumentMousedown, true)
})
onBeforeUnmount(() => {
	document.removeEventListener('mousedown', onDocumentMousedown, true)
})

// NcModal emits `close` from the X button; the backdrop handler above funnels here
// too. Mirror onModalEscape's precedence: if an attribute popover is open, dismiss
// it first rather than closing the whole card (which would drop the picker context).
function onModalClose() {
	if (openPicker.value !== null) {
		openPicker.value = null
		return
	}
	closeModal()
}

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
 * Navigate to another card modal within the same board.
 * @param {number|string} cardId target card id
 */
function openCard(cardId) {
	router.push({ name: 'card-modal', params: { id: route.params.id, cardId: String(cardId) } })
}

/**
 * Delegated click handler for rendered markdown containers: a click on a
 * `KAN-123` cross-reference anchor (class kanso-cardref) opens the target card
 * in the modal. The anchor carries no href, so this is the only navigation path.
 * @param {MouseEvent} event the click event
 */
function handleRefClick(event) {
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

// ── Move to top / bottom of the current column (⋯ menu) ──────────────────────
// Reuses the existing move endpoint: afterCardId=null → top; afterCardId=last
// card in the stack → bottom. A menu action (not a drag), so we just refetch the
// board rather than run the DnD optimistic machinery.
const moveError = ref('')
async function moveToEdge(toTop) {
	moveError.value = ''
	const stackId = cardData.value?.stackId
	if (stackId == null) return
	const selfId = Number(props.cardId)
	let afterCardId = null
	if (!toTop) {
		// Bottom: land after the last non-archived card in this stack (by sortKey).
		const inStack = (boardData.value?.cards ?? [])
			.filter((c) => c.stackId === stackId && !c.archived && c.id !== selfId)
			.sort((a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0))
		if (inStack.length === 0) return // alone in the stack → already top and bottom
		afterCardId = inStack[inStack.length - 1].id
	}
	try {
		await apiMoveCard(selfId, { targetStackId: stackId, afterCardId })
		queryClient.invalidateQueries({ queryKey: boardQueryKey(boardId.value) })
		queryClient.invalidateQueries({ queryKey: ['card', props.cardId] })
	} catch (err) {
		moveError.value = err?.response?.data?.error || t('kanso', 'Failed to move card.')
	}
}

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

// Breadcrumb board name + uppercase status chip
const boardName = computed(() => boardData.value?.board?.title || t('kanso', 'Board'))

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
	const d = new Date(cardData.value.duedate)
	if (isAllDay.value) {
		return d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })
	}
	return d.toLocaleString(undefined, {
		weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
	})
})
// Labels actually assigned to this card (for the attribute-bar chips)
const assignedLabels = computed(() => boardLabels.value.filter((l) => cardLabelIds.value.has(l.id)))

// ── @-mention autocomplete ────────────────────────────────────────────────────
// Participant getter shared across composers — delegates to the already-loaded
// participants query so no extra fetch is made.
function getParticipants() {
	return Array.isArray(participants.data.value) ? participants.data.value : []
}

// Description composer
const descTextareaRef = ref(null)
const mentionDesc = useMentionAutocomplete({
	getText: () => draftDescription.value,
	setText: (v) => { draftDescription.value = v },
	textareaRef: descTextareaRef,
	getParticipants,
})

// Markdown formatting toolbar over the description textarea (edits the markdown
// string in place; live preview reuses the same renderMarkdown as the read view).
const mdToolbar = useMarkdownToolbar({
	getText: () => draftDescription.value,
	setText: (v) => { draftDescription.value = v },
	textareaRef: descTextareaRef,
})

// Paste-a-clipboard-image → upload as attachment → embed inline (#3525). Reuses
// the attachment upload mutation + the inline-URL builder; the sanitiser only
// renders <img> whose src is that same-origin inline path.
const descPasteError = ref('')
const descImagePaste = useImagePaste({
	getText: () => draftDescription.value,
	setText: (v) => { draftDescription.value = v },
	textareaRef: descTextareaRef,
	upload: (file) => uploadAttachment.mutateAsync(file),
	inlineUrl: (attachmentId) => cardAttachmentInlineUrl(props.cardId, attachmentId),
	onError: (msg) => { descPasteError.value = msg || t('kanso', 'Failed to upload image.') },
})

function onDescPaste(event) {
	descPasteError.value = ''
	descImagePaste.onPaste(event)
}

const showDescPreview = ref(false)
// Debounced source for the live preview. renderMarkdown (markdown-it + DOMPurify)
// is expensive, so we don't re-parse on every keystroke: instead we mirror
// draftDescription into this ref on a short delay, and only while the preview
// pane is actually open. This keeps typing snappy for large descriptions.
const previewSource = ref('')
const PREVIEW_DEBOUNCE_MS = 200
let previewDebounceTimer = null

function flushPreviewSource() {
	if (previewDebounceTimer !== null) {
		clearTimeout(previewDebounceTimer)
		previewDebounceTimer = null
	}
	previewSource.value = draftDescription.value
}

// Debounce keystrokes into previewSource, but only when the preview is visible.
watch(draftDescription, () => {
	if (!showDescPreview.value) {
		return
	}
	if (previewDebounceTimer !== null) {
		clearTimeout(previewDebounceTimer)
	}
	previewDebounceTimer = setTimeout(() => {
		previewDebounceTimer = null
		previewSource.value = draftDescription.value
	}, PREVIEW_DEBOUNCE_MS)
})

// When the preview is toggled on, sync immediately so it shows current text
// (no stale render from a previous edit session). When toggled off, cancel any
// pending debounce so it can't fire against closed/stale state.
watch(showDescPreview, (visible) => {
	if (visible) {
		flushPreviewSource()
	} else if (previewDebounceTimer !== null) {
		clearTimeout(previewDebounceTimer)
		previewDebounceTimer = null
	}
})

onBeforeUnmount(() => {
	if (previewDebounceTimer !== null) {
		clearTimeout(previewDebounceTimer)
		previewDebounceTimer = null
	}
})

// Only render when the preview pane is open; feed it from the debounced source.
const draftPreview = computed(() => (showDescPreview.value ? renderMarkdown(previewSource.value, { refs: cardRefMap.value }) : ''))

// New-comment composer
const newCommentTextareaRef = ref(null)
const mentionComment = useMentionAutocomplete({
	getText: () => newCommentBody.value,
	setText: (v) => { newCommentBody.value = v },
	textareaRef: newCommentTextareaRef,
	getParticipants,
})

// Paste-a-clipboard-image into the comment composer → upload + embed inline
// (#3525), same pipeline as the description composer above.
const commentImagePaste = useImagePaste({
	getText: () => newCommentBody.value,
	setText: (v) => { newCommentBody.value = v },
	textareaRef: newCommentTextareaRef,
	upload: (file) => uploadAttachment.mutateAsync(file),
	inlineUrl: (attachmentId) => cardAttachmentInlineUrl(props.cardId, attachmentId),
	onError: (msg) => { commentError.value = msg || t('kanso', 'Failed to upload image.') },
})

function onCommentPaste(event) {
	commentError.value = ''
	commentImagePaste.onPaste(event)
}

/**
 * Unified keydown handler for the description textarea.
 * Mention autocomplete intercepts Arrow/Enter/Tab/Escape when the dropdown is
 * open; when it is closed the original key bindings are preserved exactly.
 */
function onDescKeydown(event) {
	// The mention composable handles the event once. When the dropdown is open
	// it preventDefaults the keys it consumes; when closed it is a no-op (just
	// schedules @-query detection), so the original bindings still fire.
	mentionDesc.onKeydown(event)
	if (event.defaultPrevented) return
	if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
		event.preventDefault()
		saveDescription()
		return
	}
	if (event.key === 'Escape') {
		event.stopPropagation()
		cancelDescriptionEdit()
	}
}

/**
 * Unified keydown handler for the new-comment textarea.
 * Same pattern as onDescKeydown.
 */
function onCommentKeydown(event) {
	mentionComment.onKeydown(event)
	if (event.defaultPrevented) return
	if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
		event.preventDefault()
		submitNewComment()
	}
}

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

/* ── Modal shell ─────────────────────────────────────────────────────────── */
.card-modal {
	display: flex;
	flex-direction: column;
	min-height: 0;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 15px;
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
	gap: 8px;
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}
.card-modal__crumb {
	white-space: nowrap;
}
/* Copyable human-id reference chip in the breadcrumb */
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
	border-color: var(--color-success);
	color: var(--color-success-text, var(--color-success));
	background: var(--kanso-tint-success, color-mix(in srgb, var(--color-success) 10%, var(--color-main-background)));
}
.card-modal__watch-btn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 36px;
	padding: 0 12px;
	border: 1px solid var(--color-border);
	border-radius: 100px;
	background: var(--color-main-background);
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
	cursor: pointer;
}
.card-modal__watch-btn--active {
	border-color: var(--color-primary-element);
	background: var(--color-primary-light);
	color: var(--color-primary-element);
}
.card-modal__watch-wrap {
	position: relative;
	display: inline-flex;
	align-items: center;
	gap: 2px;
}
.card-modal__watch-caret {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 36px;
	border: 1px solid var(--color-border);
	border-radius: 100px;
	background: var(--color-main-background);
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}
.card-modal__watch-caret:hover {
	border-color: var(--color-primary-element);
	color: var(--color-primary-element);
}
.card-modal__watch-caret--active {
	border-color: var(--color-primary-element);
	background: var(--color-primary-light);
	color: var(--color-primary-element);
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
.card-modal__pill--priority-1 { border-color: #888; color: #888; font-weight: 600; }
.card-modal__pill--priority-2 { border-color: var(--color-primary-element); color: var(--color-primary-element); font-weight: 600; }
.card-modal__pill--priority-3 { border-color: #e07b00; color: #e07b00; font-weight: 600; }
.card-modal__pill--priority-4 { border-color: var(--color-error-text); color: var(--color-error-text); font-weight: 600; }
.card-modal__pill--type-bug { border-color: #e74c3c; color: #e74c3c; font-weight: 600; }
.card-modal__pill--type-feature { border-color: #27ae60; color: #27ae60; font-weight: 600; }
.card-modal__pill--type-task { border-color: var(--color-primary-element); color: var(--color-primary-element); font-weight: 600; }
.card-modal__pill--type-chore { border-color: #7f8c8d; color: #7f8c8d; font-weight: 600; }
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
.card-modal__review-pill--approved { border-color: var(--color-success-text); background: rgba(70, 186, 97, 0.08); }
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
.card-modal__review-state--approved { color: var(--color-success-text); }
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
	grid-template-columns: minmax(0, 1fr) 400px;
	align-items: stretch;
	min-height: 0;
	flex: 1;
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
	gap: 8px;
	padding: 6px 4px;
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

/* ── Shared error text ───────────────────────────────────────────────────── */
.card-modal__save-error {
	font-size: 0.8rem;
	color: var(--color-error);
}
.card-modal__action-error {
	padding: 0 24px 8px;
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

/* ── Responsive: stack panes, switch via tabs ────────────────────────────── */
@media (max-width: 680px) {
	.card-modal__header { padding: 14px 16px 10px; }
	.card-modal__title { font-size: 1.2rem; }
	.card-modal__attrbar { flex-wrap: nowrap; overflow-x: auto; padding: 10px 16px; }
	.card-modal__attr-right { margin-left: 0; }
	.card-modal__body { grid-template-columns: 1fr; }
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
</style>

<!-- Widen the modal container for the two-pane card view (teleported outside
     scoped styles, so this block is intentionally global). -->
<style>
.card-modal-modal .modal-container,
.modal-container.card-modal-modal {
	width: min(1180px, 94vw) !important;
	max-width: min(1180px, 94vw) !important;
}
</style>
