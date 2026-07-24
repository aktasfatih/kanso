<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal
		:show="true"
		:name="cardTitle"
		size="large"
		@close="closeModal">
		<div class="card-modal" @keydown.escape="closeModal">
			<!-- Loading state -->
			<div v-if="isLoading" class="card-modal__loading">
				<div class="skeleton-text card-modal__title-skeleton" />
				<div class="skeleton-text card-modal__desc-skeleton" />
			</div>

			<!-- Error state -->
			<div v-else-if="isError" class="card-modal__error">
				{{ t('kanso', 'Failed to load card details.') }}
			</div>

			<!-- Card content -->
			<template v-else-if="cardData">
				<!-- Title row — title + edit pencil + actions (⋯) menu -->
				<div class="card-modal__title-row">
					<template v-if="editingTitle">
						<input
							ref="titleInputRef"
							v-model="draftTitle"
							class="card-modal__title-input"
							type="text"
							@keydown.enter.prevent="saveTitle"
							@keydown.escape.stop="cancelTitleEdit"
							@blur="saveTitle" />
					</template>
					<template v-else>
						<h2 class="card-modal__title" @click="startTitleEdit">
							{{ cardData.title }}
						</h2>
						<button class="card-modal__edit-btn" :title="t('kanso', 'Edit title')" @click="startTitleEdit">
							<PencilIcon :size="16" />
						</button>
					</template>

					<!-- Watch toggle -->
					<div class="card-modal__watch-wrap">
						<button
							class="card-modal__watch-btn"
							:class="{ 'card-modal__watch-btn--active': isWatching }"
							:aria-pressed="isWatching"
							:disabled="toggleSubscription.isPending.value"
							:title="isWatching ? t('kanso', 'Stop watching this card') : t('kanso', 'Watch this card')"
							@click="handleWatchToggle">
							<EyeOffOutlineIcon v-if="isWatching" :size="16" />
							<EyeOutlineIcon v-else :size="16" />
							<span class="card-modal__watch-label">
								{{ isWatching ? t('kanso', 'Watching') : t('kanso', 'Watch') }}
							</span>
							<span v-if="watcherCount > 0" class="card-modal__watch-count">
								{{ watcherCount }}
							</span>
						</button>
						<!-- Avatar stack (visible when there are watchers) -->
						<div v-if="visibleWatchers.length > 0" class="card-modal__watch-avatars" aria-hidden="true">
							<NcAvatar
								v-for="uid in visibleWatchers"
								:key="uid"
								:user="uid"
								:size="20"
								class="card-modal__watch-avatar" />
							<span v-if="extraWatchers > 0" class="card-modal__watch-avatar-extra">
								+{{ extraWatchers }}
							</span>
						</div>
						<span v-if="subscriptionError" class="card-modal__save-error card-modal__watch-error">
							{{ subscriptionError }}
						</span>
					</div>

					<!-- ⋯ Actions menu -->
					<NcActions class="card-modal__actions-menu" :force-menu="true">
						<!-- Archive / Unarchive -->
						<NcActionButton
							:close-after-click="true"
							@click="handleArchiveToggle">
							<template #icon>
								<ArchiveArrowDownIcon v-if="!cardData.archived" :size="20" />
								<ArchiveArrowUpIcon v-else :size="20" />
							</template>
							{{ cardData.archived ? t('kanso', 'Unarchive') : t('kanso', 'Archive') }}
						</NcActionButton>

						<!-- Delete -->
						<NcActionButton
							:close-after-click="true"
							@click="showDeleteConfirm = true">
							<template #icon>
								<TrashCanIcon :size="20" />
							</template>
							{{ t('kanso', 'Delete') }}
						</NcActionButton>

						<!-- Future: Duplicate, Move-to (add items here) -->
					</NcActions>
				</div>

				<!-- Inline delete confirmation banner -->
				<div v-if="showDeleteConfirm" class="card-modal__delete-confirm">
					<span>{{ t('kanso', 'Delete this card permanently?') }}</span>
					<div class="card-modal__delete-confirm-actions">
						<NcButton
							type="error"
							:disabled="deleteCard.isPending.value"
							@click="handleDelete">
							{{ t('kanso', 'Delete') }}
						</NcButton>
						<NcButton @click="showDeleteConfirm = false">
							{{ t('kanso', 'Cancel') }}
						</NcButton>
					</div>
				</div>

				<!-- Action error (archive / delete) -->
				<span v-if="actionError" class="card-modal__save-error card-modal__action-error">
					{{ actionError }}
				</span>

				<!-- Two-column layout: main (description + discussion) | sidebar (attributes) -->
				<div class="card-modal__columns">
					<!-- LEFT column: description + discussion -->
					<div class="card-modal__main">
						<!-- Description -->
						<div class="card-modal__description-section">
							<label class="card-modal__label">{{ t('kanso', 'Description') }}</label>

							<template v-if="editingDescription">
								<textarea
									v-model="draftDescription"
									class="card-modal__desc-textarea"
									:placeholder="t('kanso', 'Add a description…')"
									rows="8" />
								<div class="card-modal__desc-actions">
									<NcButton type="primary" :disabled="isSaving" @click="saveDescription">
										{{ t('kanso', 'Save') }}
									</NcButton>
									<NcButton @click="cancelDescriptionEdit">
										{{ t('kanso', 'Cancel') }}
									</NcButton>
									<span v-if="saveError" class="card-modal__save-error">{{ saveError }}</span>
								</div>
							</template>

							<template v-else>
								<div
									v-if="cardData.description"
									class="card-modal__desc-view"
									@click="startDescriptionEdit">
									<div class="card-modal__desc-rendered" v-html="renderedDescription" />
								</div>
								<button
									v-else
									class="card-modal__desc-placeholder"
									@click="startDescriptionEdit">
									{{ t('kanso', 'Add a description…') }}
								</button>
							</template>
						</div>

						<!-- Discussion section -->
						<div class="card-modal__discussion-section">
					<div class="card-modal__discussion-header">
						<CommentMultipleOutlineIcon :size="16" class="card-modal__discussion-header-icon" />
						<label class="card-modal__label">{{ t('kanso', 'Discussion') }}</label>
						<span v-if="commentCount > 0" class="card-modal__discussion-count">
							{{ commentCount }}
						</span>
					</div>

					<!-- Thread list -->
					<div v-if="commentThread.length > 0" class="card-modal__discussion-thread">
						<div
							v-for="{ comment: topComment, replies } in commentThread"
							:key="topComment.id"
							class="card-modal__comment-group">
							<!-- Top-level comment -->
							<div class="card-modal__comment card-modal__comment--top">
								<div class="card-modal__comment-meta">
									<span class="card-modal__comment-author">{{ topComment.authorDisplayName || topComment.author }}</span>
									<span class="card-modal__comment-time">{{ formatCommentTime(topComment.createdAt) }}</span>
									<span v-if="topComment.editedAt > 0" class="card-modal__comment-edited">
										{{ t('kanso', 'edited') }}
									</span>
								</div>

								<!-- Body: edit mode -->
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
										<NcButton
											type="primary"
											:disabled="editComment.isPending.value"
											@click="saveCommentEdit(topComment)">
											{{ t('kanso', 'Save') }}
										</NcButton>
										<NcButton @click="cancelCommentEdit">
											{{ t('kanso', 'Cancel') }}
										</NcButton>
									</div>
								</template>

								<!-- Body: display mode (sanitized markdown via v-html) -->
								<div
									v-else
									class="card-modal__comment-body"
									v-html="renderMarkdown(topComment.body)" />

								<!-- Author controls (edit + delete) — gated on canEdit AND being the author -->
								<div
									v-if="canEdit && currentUserId === topComment.author"
									class="card-modal__comment-controls">
									<button
										class="card-modal__comment-control-btn"
										:title="t('kanso', 'Edit comment')"
										@click="startCommentEdit(topComment)">
										<PencilIcon :size="14" />
									</button>
									<button
										class="card-modal__comment-control-btn card-modal__comment-control-btn--danger"
										:title="t('kanso', 'Delete comment')"
										:disabled="deleteComment.isPending.value"
										@click="handleDeleteComment(topComment)">
										<TrashCanIcon :size="14" />
									</button>
								</div>

								<!-- Reply affordance — gated on canEdit -->
								<button
									v-if="canEdit && editingCommentId !== topComment.id"
									class="card-modal__comment-reply-btn"
									@click="openReplyBox(topComment.id)">
									{{ t('kanso', 'Reply') }}
								</button>
							</div>

							<!-- Inline reply box for this top-level comment -->
							<div
								v-if="replyingToId === topComment.id && canEdit"
								class="card-modal__reply-compose card-modal__reply-compose--indent">
								<textarea
									:ref="(el) => setReplyRef(topComment.id, el)"
									v-model="replyBody"
									class="card-modal__comment-compose-textarea"
									:placeholder="t('kanso', 'Write a reply…')"
									rows="2"
									@keydown.ctrl.enter.prevent="submitReply(topComment.id)"
									@keydown.meta.enter.prevent="submitReply(topComment.id)"
									@keydown.escape.stop="closeReplyBox" />
								<div class="card-modal__comment-compose-actions">
									<NcButton
										type="primary"
										:disabled="addComment.isPending.value || !replyBody.trim()"
										@click="submitReply(topComment.id)">
										{{ t('kanso', 'Post reply') }}
									</NcButton>
									<NcButton @click="closeReplyBox">
										{{ t('kanso', 'Cancel') }}
									</NcButton>
								</div>
							</div>

							<!-- Replies (indented) -->
							<div
								v-if="replies.length > 0"
								class="card-modal__replies">
								<div
									v-for="reply in replies"
									:key="reply.id"
									class="card-modal__comment card-modal__comment--reply">
									<div class="card-modal__comment-meta">
										<span class="card-modal__comment-author">{{ reply.authorDisplayName || reply.author }}</span>
										<span class="card-modal__comment-time">{{ formatCommentTime(reply.createdAt) }}</span>
										<span v-if="reply.editedAt > 0" class="card-modal__comment-edited">
											{{ t('kanso', 'edited') }}
										</span>
									</div>

									<!-- Reply body: edit mode -->
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
											<NcButton
												type="primary"
												:disabled="editComment.isPending.value"
												@click="saveCommentEdit(reply)">
												{{ t('kanso', 'Save') }}
											</NcButton>
											<NcButton @click="cancelCommentEdit">
												{{ t('kanso', 'Cancel') }}
											</NcButton>
										</div>
									</template>

									<!-- Reply body: display mode -->
									<div
										v-else
										class="card-modal__comment-body"
										v-html="renderMarkdown(reply.body)" />

									<!-- Author controls -->
									<div
										v-if="canEdit && currentUserId === reply.author"
										class="card-modal__comment-controls">
										<button
											class="card-modal__comment-control-btn"
											:title="t('kanso', 'Edit comment')"
											@click="startCommentEdit(reply)">
											<PencilIcon :size="14" />
										</button>
										<button
											class="card-modal__comment-control-btn card-modal__comment-control-btn--danger"
											:title="t('kanso', 'Delete comment')"
											:disabled="deleteComment.isPending.value"
											@click="handleDeleteComment(reply)">
											<TrashCanIcon :size="14" />
										</button>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Top-level compose box — only when EDIT permission -->
					<div v-if="canEdit" class="card-modal__comment-compose">
						<textarea
							v-model="newCommentBody"
							class="card-modal__comment-compose-textarea"
							:placeholder="t('kanso', 'Write a comment… (Ctrl+Enter to post)')"
							rows="3"
							:disabled="addComment.isPending.value"
							@keydown.ctrl.enter.prevent="submitNewComment"
							@keydown.meta.enter.prevent="submitNewComment" />
						<div class="card-modal__comment-compose-actions">
							<NcButton
								type="primary"
								:disabled="addComment.isPending.value || !newCommentBody.trim()"
								@click="submitNewComment">
								{{ t('kanso', 'Post') }}
							</NcButton>
						</div>
						<span v-if="commentError" class="card-modal__save-error">{{ commentError }}</span>
					</div>
				</div>
				<!-- END .card-modal__main -->
				</div>

				<!-- RIGHT column: attributes sidebar -->
				<div class="card-modal__sidebar">
					<!-- Done toggle -->
					<div class="card-modal__meta card-modal__meta--done">
						<NcCheckboxRadioSwitch
							:model-value="isDone"
							type="checkbox"
							@update:model-value="handleDoneToggle">
							{{ t('kanso', 'Done') }}
						</NcCheckboxRadioSwitch>
						<span v-if="isDone && cardData.doneAt" class="card-modal__done-at">
							{{ formatDoneAt(cardData.doneAt) }}
						</span>
					</div>

					<!-- Due date — editable via datetime-local input -->
					<div class="card-modal__meta card-modal__meta--due">
						<CalendarIcon :size="16" />
						<span class="card-modal__meta-label">{{ t('kanso', 'Due date') }}</span>
						<input
							class="card-modal__due-input"
							:class="dueDateClass"
							type="datetime-local"
							:value="dueDateInputValue"
							@change="handleDueDateChange" />
						<button
							v-if="cardData.duedate"
							class="card-modal__due-clear"
							:title="t('kanso', 'Clear due date')"
							@click="clearDueDate">
							<CloseIcon :size="14" />
						</button>
					</div>

					<!-- Priority selector -->
					<div class="card-modal__meta card-modal__meta--priority">
						<FlagIcon :size="16" />
						<span class="card-modal__meta-label">{{ t('kanso', 'Priority') }}</span>
						<div class="card-modal__priority-buttons" role="group" :aria-label="t('kanso', 'Select priority')">
							<button
								v-for="level in PRIORITY_LEVELS"
								:key="level.value"
								class="card-modal__priority-btn"
								:class="[
									`card-modal__priority-btn--${level.value}`,
									{ 'card-modal__priority-btn--active': currentPriority === level.value },
								]"
								:title="t('kanso', level.label)"
								:aria-pressed="currentPriority === level.value"
								:disabled="setPriority.isPending.value"
								@click="handleSetPriority(level.value)">
								{{ level.value === 0 ? t('kanso', 'None') : t('kanso', level.label) }}
							</button>
						</div>
						<span v-if="priorityError" class="card-modal__save-error">{{ priorityError }}</span>
					</div>

					<!-- Labels section -->
					<div class="card-modal__labels-section">
						<label class="card-modal__label">{{ t('kanso', 'Labels') }}</label>
						<div
							v-if="boardLabels.length === 0"
							class="card-modal__labels-empty">
							{{ t('kanso', 'No labels on this board yet.') }}
						</div>
						<div v-else class="card-modal__label-chips" role="group" :aria-label="t('kanso', 'Toggle labels')">
							<button
								v-for="label in boardLabels"
								:key="label.id"
								class="card-modal__label-chip"
								:class="{
									'card-modal__label-chip--assigned': cardLabelIds.has(label.id),
									'card-modal__label-chip--no-color': !label.color,
								}"
								:style="label.color ? { '--label-color': cssColor(label.color) } : {}"
								:aria-pressed="cardLabelIds.has(label.id)"
								:disabled="toggleLabel.isPending.value"
								@click="handleToggleLabel(label)">
								{{ label.title }}
							</button>
						</div>
						<span v-if="labelToggleError" class="card-modal__save-error">{{ labelToggleError }}</span>
					</div>

					<!-- Assignees section -->
					<div class="card-modal__assignees-section">
						<label class="card-modal__label">{{ t('kanso', 'Assignees') }}</label>

						<!-- Current assignees as chips -->
						<div class="card-modal__assignee-chips">
							<span
								v-for="uid in cardAssigneeIds"
								:key="uid"
								class="card-modal__assignee-chip">
								<NcAvatar
									:user="uid"
									:display-name="participantName(uid)"
									:size="24"
									:show-user-status="false"
									:disable-tooltip="false" />
								<span class="card-modal__assignee-name">{{ participantName(uid) }}</span>
								<button
									class="card-modal__assignee-remove"
									:title="t('kanso', 'Remove assignee')"
									:disabled="toggleAssignee.isPending.value"
									@click="handleToggleAssignee(uid, false)">
									<CloseIcon :size="12" />
								</button>
							</span>
							<span v-if="cardAssigneeIds.length === 0" class="card-modal__assignees-empty">
								{{ t('kanso', 'No assignees yet.') }}
							</span>
						</div>

						<!-- Assign dropdown: participants not yet assigned -->
						<div v-if="unassignedParticipants.length > 0" class="card-modal__assign-wrap">
							<button
								class="card-modal__assign-toggle"
								:aria-expanded="assignPickerOpen"
								@click="assignPickerOpen = !assignPickerOpen">
								<AccountPlusIcon :size="16" />
								{{ t('kanso', 'Assign…') }}
							</button>
							<div v-if="assignPickerOpen" class="card-modal__assign-popover">
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
					</div>

					<!-- Reviews section -->
					<div class="card-modal__reviews-section">
						<label class="card-modal__label">{{ t('kanso', 'Reviews') }}</label>

						<!-- Current reviewers as chips with state -->
						<div class="card-modal__review-chips">
							<span
								v-for="review in cardReviews"
								:key="review.reviewer"
								class="card-modal__review-chip"
								:class="`card-modal__review-chip--${review.state}`">
								<NcAvatar
									:user="review.reviewer"
									:display-name="participantName(review.reviewer)"
									:size="24"
									:show-user-status="false"
									:disable-tooltip="false" />
								<span class="card-modal__review-name">{{ participantName(review.reviewer) }}</span>
								<span class="card-modal__review-state-badge" :class="`card-modal__review-state-badge--${review.state}`">
									<CheckDecagramIcon v-if="review.state === 'approved'" :size="12" />
									<AlertDecagramIcon v-else-if="review.state === 'changes_requested'" :size="12" />
									<CheckDecagramOutlineIcon v-else :size="12" />
									{{ reviewStateLabel(review.state) }}
								</span>
								<button
									v-if="canEdit"
									class="card-modal__review-remove"
									:title="t('kanso', 'Withdraw review request')"
									:disabled="withdrawReview.isPending.value"
									@click="handleWithdrawReview(review.reviewer)">
									<CloseIcon :size="12" />
								</button>
							</span>
							<span v-if="cardReviews.length === 0" class="card-modal__reviews-empty">
								{{ t('kanso', 'No reviews requested.') }}
							</span>
						</div>

						<!-- Request review from a participant -->
						<div v-if="canEdit && unrequestedParticipants.length > 0" class="card-modal__assign-wrap">
							<button
								class="card-modal__assign-toggle"
								:aria-expanded="reviewPickerOpen"
								@click="reviewPickerOpen = !reviewPickerOpen">
								<AccountPlusIcon :size="16" />
								{{ t('kanso', 'Request review…') }}
							</button>
							<div v-if="reviewPickerOpen" class="card-modal__assign-popover">
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

						<!-- Verdict buttons for the current user when they have an actionable review -->
						<div v-if="myReviewNeedsVerdict" class="card-modal__review-verdict">
							<span class="card-modal__review-verdict-label">{{ t('kanso', 'Your verdict:') }}</span>
							<NcButton
								type="success"
								:disabled="setReviewState.isPending.value"
								@click="handleSetReviewState('approved')">
								<template #icon>
									<CheckDecagramIcon :size="16" />
								</template>
								{{ t('kanso', 'Approve') }}
							</NcButton>
							<NcButton
								type="error"
								:disabled="setReviewState.isPending.value"
								@click="handleSetReviewState('changes_requested')">
								<template #icon>
									<AlertDecagramIcon :size="16" />
								</template>
								{{ t('kanso', 'Request changes') }}
							</NcButton>
						</div>

						<span v-if="reviewError" class="card-modal__save-error">{{ reviewError }}</span>
					</div>

					<!-- Hierarchy section: parent OR children (mutually exclusive per one-level rule) -->
					<!-- Case 1: this card HAS a parent — show parent link + detach button -->
					<div v-if="cardData.parentCardId" class="card-modal__hierarchy-section">
						<div class="card-modal__hierarchy-header">
							<SitemapIcon :size="16" class="card-modal__hierarchy-icon" />
							<label class="card-modal__label">{{ t('kanso', 'Parent card') }}</label>
						</div>
						<div class="card-modal__parent-row">
							<button
								class="card-modal__parent-link"
								@click="openCard(cardData.parentCardId)">
								{{ parentTitle }}
							</button>
							<button
								class="card-modal__hierarchy-detach"
								:title="t('kanso', 'Detach from parent')"
								:disabled="setParentMutation.isPending.value"
								@click="handleClearParent">
								<LinkOffIcon :size="14" />
								{{ t('kanso', 'Detach') }}
							</button>
						</div>
						<span v-if="hierarchyError" class="card-modal__save-error">{{ hierarchyError }}</span>
					</div>

					<!-- Case 2: this card has NO parent — show sub-cards section -->
					<div v-else class="card-modal__hierarchy-section">
						<div class="card-modal__hierarchy-header">
							<SitemapIcon :size="16" class="card-modal__hierarchy-icon" />
							<label class="card-modal__label">{{ t('kanso', 'Sub-cards') }}</label>
							<span v-if="children.length > 0" class="card-modal__hierarchy-progress-text">
								{{ childrenDone }}/{{ children.length }}
							</span>
						</div>

						<!-- Children list -->
						<ul v-if="children.length > 0" class="card-modal__children-list">
							<li
								v-for="child in children"
								:key="child.id"
								class="card-modal__child-item"
								:class="{ 'card-modal__child-item--done': Number(child.doneAt) > 0 }">
								<!-- Done indicator -->
								<span
									class="card-modal__child-done-dot"
									:class="{ 'card-modal__child-done-dot--done': Number(child.doneAt) > 0 }"
									:title="Number(child.doneAt) > 0 ? t('kanso', 'Done') : t('kanso', 'Not done')" />
								<!-- Title link -->
								<button
									class="card-modal__child-link"
									:class="{ 'card-modal__child-link--done': Number(child.doneAt) > 0 }"
									@click="openCard(child.id)">
									{{ child.title }}
								</button>
								<!-- Detach (remove) button -->
								<button
									class="card-modal__child-remove"
									:title="t('kanso', 'Detach sub-card')"
									:disabled="setParentMutation.isPending.value"
									@click="handleDetachChild(child)">
									<CloseIcon :size="12" />
								</button>
							</li>
						</ul>

						<!-- Add sub-card input -->
						<div class="card-modal__add-child-row">
							<input
								ref="addChildInputRef"
								v-model="newChildTitle"
								class="card-modal__add-child-input"
								type="text"
								:placeholder="t('kanso', 'Add a sub-card…')"
								:disabled="addChildMutation.isPending.value"
								@keydown.enter.prevent="handleAddChild" />
						</div>
						<span v-if="hierarchyError" class="card-modal__save-error">{{ hierarchyError }}</span>
					</div>

					<!-- Checklist section -->
					<div class="card-modal__checklist-section">
						<div class="card-modal__checklist-header">
							<CheckboxMarkedOutlineIcon :size="16" class="card-modal__checklist-header-icon" />
							<label class="card-modal__label">{{ t('kanso', 'Checklist') }}</label>
							<span v-if="checklistTotal > 0" class="card-modal__checklist-progress-text">
								{{ checklistDone }}/{{ checklistTotal }}
							</span>
						</div>

						<!-- Progress bar -->
						<div v-if="checklistTotal > 0" class="card-modal__checklist-bar-wrap">
							<div
								class="card-modal__checklist-bar-fill"
								:class="{ 'card-modal__checklist-bar-fill--complete': checklistDone === checklistTotal }"
								:style="{ width: checklistProgressPct + '%' }" />
						</div>

						<!-- Items list -->
						<ul class="card-modal__checklist-list">
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
								<!-- Drag handle -->
								<span
									class="card-modal__checklist-drag-handle"
									:draggable="true"
									:title="t('kanso', 'Drag to reorder')"
									@dragstart="onItemDragStart($event, item)"
									@dragend="onItemDragEnd">
									<DragIcon :size="14" />
								</span>

								<!-- Checkbox -->
								<input
									type="checkbox"
									class="card-modal__checklist-checkbox"
									:checked="item.done"
									:disabled="toggleItem.isPending.value"
									:aria-label="t('kanso', 'Toggle item done')"
									@change="handleToggleItem(item)" />

								<!-- Inline-editable title -->
								<input
									v-if="editingItemId === item.id"
									:ref="(el) => setItemInputRef(item.id, el)"
									v-model="editingItemTitle"
									class="card-modal__checklist-item-input"
									type="text"
									@keydown.enter.prevent="saveItemTitle(item)"
									@keydown.escape.stop="cancelItemEdit"
									@blur="saveItemTitle(item)" />
								<span
									v-else
									class="card-modal__checklist-item-title"
									:class="{ 'card-modal__checklist-item-title--done': item.done }"
									@click="startItemEdit(item)">
									{{ item.title }}
								</span>

								<!-- Delete button -->
								<button
									class="card-modal__checklist-item-delete"
									:title="t('kanso', 'Delete item')"
									:disabled="deleteItem.isPending.value"
									@click="handleDeleteItem(item)">
									<CloseIcon :size="14" />
								</button>
							</li>
						</ul>

						<!-- Drag-over indicator between items is handled by item highlight;
						     drop line shown via CSS on dragover target -->

						<!-- Add item input -->
						<div class="card-modal__checklist-add">
							<CheckboxBlankOutlineIcon :size="16" class="card-modal__checklist-add-icon" />
							<input
								ref="addItemInputRef"
								v-model="newItemTitle"
								class="card-modal__checklist-add-input"
								type="text"
								:placeholder="t('kanso', 'Add an item…')"
								:disabled="addItem.isPending.value"
								@keydown.enter.prevent="handleAddItem" />
						</div>
						<span v-if="checklistError" class="card-modal__save-error">{{ checklistError }}</span>
					</div>
					<!-- END .card-modal__sidebar -->
				</div>
				<!-- END .card-modal__columns -->
				</div>
			</template>
		</div>
	</NcModal>
</template>

<script setup>
import { ref, computed, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { getCurrentUser } from '@nextcloud/auth'
import { translate as t } from '@nextcloud/l10n'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import AccountPlusIcon from 'vue-material-design-icons/AccountPlus.vue'
import ArchiveArrowDownIcon from 'vue-material-design-icons/ArchiveArrowDown.vue'
import ArchiveArrowUpIcon from 'vue-material-design-icons/ArchiveArrowUp.vue'
import CommentMultipleOutlineIcon from 'vue-material-design-icons/CommentMultipleOutline.vue'
import TrashCanIcon from 'vue-material-design-icons/TrashCan.vue'
import CheckboxMarkedOutlineIcon from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import CheckboxBlankOutlineIcon from 'vue-material-design-icons/CheckboxBlankOutline.vue'
import DragIcon from 'vue-material-design-icons/Drag.vue'
import FlagIcon from 'vue-material-design-icons/Flag.vue'
import LinkOffIcon from 'vue-material-design-icons/LinkOff.vue'
import SitemapIcon from 'vue-material-design-icons/Sitemap.vue'
import EyeOutlineIcon from 'vue-material-design-icons/EyeOutline.vue'
import EyeOffOutlineIcon from 'vue-material-design-icons/EyeOffOutline.vue'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import CheckDecagramOutlineIcon from 'vue-material-design-icons/CheckDecagramOutline.vue'
import AlertDecagramIcon from 'vue-material-design-icons/AlertDecagram.vue'
import { useCard } from '../composables/useCard.js'
import { usePriority, PRIORITY_LEVELS } from '../composables/usePriority.js'
import { useBoard } from '../composables/useBoard.js'
import { useLabels } from '../composables/useLabels.js'
import { useAssignees } from '../composables/useAssignees.js'
import { useReviews } from '../composables/useReviews.js'
import { useCardActions } from '../composables/useCardActions.js'
import { useChecklist } from '../composables/useChecklist.js'
import { useComments, buildCommentTree } from '../composables/useComments.js'
import { useCardHierarchy } from '../composables/useCardHierarchy.js'
import { useSubscription } from '../composables/useSubscription.js'
import { cssColor } from '../services/color.js'
import { renderMarkdown } from '../services/markdown.js'

const props = defineProps({
	cardId: {
		type: String,
		required: true,
	},
})

const router = useRouter()
const route = useRoute()

// Board id from route params — modal is a child route of /boards/:id
const boardId = computed(() => route.params.id)

// Modal is open when this component is mounted — enabled is always true here
const isOpen = ref(true)
const { data: cardData, isLoading, isError, updateCard } = useCard(
	computed(() => props.cardId),
	isOpen,
)

// Read board data from cache (same queryKey as BoardView — no extra request).
const { data: boardData } = useBoard(boardId)
const boardLabels = computed(() => boardData.value?.labels ?? [])

// ── Card lifecycle actions (archive / delete) ────────────────────────────────
const { setArchived, deleteCard } = useCardActions(boardId, computed(() => props.cardId))
const actionError = ref('')
const showDeleteConfirm = ref(false)

async function handleArchiveToggle() {
	actionError.value = ''
	const archived = !cardData.value?.archived
	try {
		await setArchived.mutateAsync({ archived })
		// On archive, close the modal so the card visually leaves the board columns.
		// On unarchive, the modal can stay open; the card returns to its column.
		if (archived) {
			closeModal()
		}
	} catch (err) {
		actionError.value = err?.response?.data?.error || t('kanso', 'Failed to update card.')
	}
}

async function handleDelete() {
	actionError.value = ''
	try {
		await deleteCard.mutateAsync()
		showDeleteConfirm.value = false
		closeModal()
	} catch (err) {
		showDeleteConfirm.value = false
		actionError.value = err?.response?.data?.error || t('kanso', 'Failed to delete card.')
	}
}

// Current card's assigned label ids as a Set for O(1) .has() in the template
const cardLabelIds = computed(() => {
	const ids = Array.isArray(cardData.value?.labelIds) ? cardData.value.labelIds : []
	return new Set(ids)
})

// Label toggle mutation
const { toggleLabel } = useLabels(boardId)
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

// ── Assignees ────────────────────────────────────────────────────────────────
const { participants, toggleAssignee } = useAssignees(boardId)
const assigneeError = ref('')
const assignPickerOpen = ref(false)

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
	assignPickerOpen.value = false
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

// ── Reviews ──────────────────────────────────────────────────────────────────
const { requestReview, withdrawReview, setReviewState } = useReviews(boardId)
const reviewError = ref('')
const reviewPickerOpen = ref(false)

const cardReviews = computed(() =>
	Array.isArray(cardData.value?.reviews) ? cardData.value.reviews : [],
)

// Participants not yet requested for review on this card
const unrequestedParticipants = computed(() => {
	const list = Array.isArray(participants.data.value) ? participants.data.value : []
	const requested = new Set(cardReviews.value.map((r) => r.reviewer))
	return list.filter((p) => !requested.has(p.uid))
})

// Whether the current user has a pending or changes_requested review on this card
const myReview = computed(() =>
	cardReviews.value.find((r) => r.reviewer === currentUserId) ?? null,
)

const myReviewNeedsVerdict = computed(() =>
	myReview.value !== null
	&& (myReview.value.state === 'pending' || myReview.value.state === 'changes_requested'),
)

async function handleRequestReview(uid) {
	reviewError.value = ''
	reviewPickerOpen.value = false
	try {
		await requestReview.mutateAsync({
			cardId: Number(props.cardId),
			userId: uid,
		})
	} catch (err) {
		reviewError.value = err?.response?.data?.error || t('kanso', 'Failed to request review.')
	}
}

async function handleWithdrawReview(uid) {
	reviewError.value = ''
	try {
		await withdrawReview.mutateAsync({
			cardId: Number(props.cardId),
			userId: uid,
		})
	} catch (err) {
		reviewError.value = err?.response?.data?.error || t('kanso', 'Failed to withdraw review request.')
	}
}

async function handleSetReviewState(state) {
	reviewError.value = ''
	try {
		await setReviewState.mutateAsync({
			cardId: Number(props.cardId),
			userId: currentUserId,
			state,
		})
	} catch (err) {
		reviewError.value = err?.response?.data?.error || t('kanso', 'Failed to submit review.')
	}
}

function reviewStateLabel(state) {
	if (state === 'approved') return t('kanso', 'Approved')
	if (state === 'changes_requested') return t('kanso', 'Changes requested')
	return t('kanso', 'Pending')
}

// ── Done toggle ──────────────────────────────────────────────────────────────
const isDone = computed(() => Number(cardData.value?.doneAt) > 0)

async function handleDoneToggle(checked) {
	try {
		await updateCard.mutateAsync({ data: { done: checked } })
	} catch (err) {
		saveError.value = err?.response?.data?.error || t('kanso', 'Failed to update done status.')
	}
}

function formatDoneAt(unixTs) {
	return new Date(unixTs * 1000).toLocaleString(undefined, {
		weekday: 'short',
		year: 'numeric',
		month: 'short',
		day: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
	})
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

// ── Due date (editable) ──────────────────────────────────────────────────────
// datetime-local value needs "YYYY-MM-DDTHH:mm" format
const dueDateInputValue = computed(() => {
	if (!cardData.value?.duedate) return ''
	const d = new Date(cardData.value.duedate)
	const pad = (n) => String(n).padStart(2, '0')
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
})

async function handleDueDateChange(event) {
	const val = event.target.value
	if (!val) return
	const iso = new Date(val).toISOString()
	try {
		await updateCard.mutateAsync({ data: { duedate: iso } })
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

// ── Due date color class (respects done state) ───────────────────────────────
const dueDateClass = computed(() => {
	if (!cardData.value?.duedate) return ''
	// When done, suppress overdue/soon coloring
	if (isDone.value) return ''
	const due = new Date(cardData.value.duedate)
	const now = new Date()
	if (due < now) return 'card-modal__due--overdue'
	const diff = due - now
	if (diff / (1000 * 60 * 60) <= 24) return 'card-modal__due--soon'
	return ''
})

const cardTitle = computed(() => cardData.value?.title || t('kanso', 'Card'))
const renderedDescription = computed(() => renderMarkdown(cardData.value?.description || ''))

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
	newItemTitle.value = ''
	try {
		await addItem.mutateAsync({ title })
	} catch (err) {
		checklistError.value = err?.response?.data?.error || t('kanso', 'Failed to add item.')
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
	// reachable — a top-half drop on the first row resolves to afterItemId=null.
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

// ── Comments / Discussion ────────────────────────────────────────────────────
const {
	comments: commentsQuery,
	addComment,
	editComment,
	deleteComment,
} = useComments(computed(() => props.cardId), boardId)

// Current user uid — used to gate edit/delete controls to the comment author
const currentUserId = getCurrentUser()?.uid ?? ''

// EDIT permission bit (bit 1, value 2) from board payload
const canEdit = computed(() => {
	const perms = boardData.value?.permissions ?? 0
	return (perms & 2) !== 0
})

const flatComments = computed(() => commentsQuery.data.value ?? [])
const commentThread = computed(() => buildCommentTree(flatComments.value))
const commentCount = computed(() => flatComments.value.length)

const commentError = ref('')
const newCommentBody = ref('')

async function submitNewComment() {
	const body = newCommentBody.value.trim()
	if (!body) return
	commentError.value = ''
	newCommentBody.value = ''
	try {
		await addComment.mutateAsync({ body, parentCommentId: null })
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
	replyBody.value = ''
	replyingToId.value = null
	try {
		await addComment.mutateAsync({ body, parentCommentId })
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

/**
 * Format a unix timestamp as a relative time string (e.g. "2 hours ago").
 * Falls back to a locale date string for older timestamps.
 * @param {number} unixTs
 * @returns {string}
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

function closeModal() {
	isOpen.value = false
	router.push({ name: 'board', params: { id: route.params.id } })
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
 */
function openCard(cardId) {
	router.push({ name: 'card-modal', params: { id: route.params.id, cardId: String(cardId) } })
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
	newChildTitle.value = ''
	try {
		await addChild(
			{ id: Number(props.cardId), stackId: cardData.value?.stackId },
			title,
		)
	} catch (err) {
		hierarchyError.value = err?.response?.data?.error || t('kanso', 'Failed to add sub-card.')
	}
	// Keep focus for rapid entry
	await nextTick()
	addChildInputRef.value?.focus()
}

// ── Subscription (Watch / Unwatch) ───────────────────────────────────────────
const { toggle: toggleSubscription } = useSubscription(computed(() => props.cardId))

const subscription = computed(() => cardData.value?.subscription ?? { subscribed: false, subscribers: [], count: 0 })
const isWatching = computed(() => subscription.value.subscribed === true)
const watcherCount = computed(() => Number(subscription.value.count) || 0)
const watcherSubscribers = computed(() => {
	const subs = subscription.value.subscribers
	return Array.isArray(subs) ? subs : []
})
// Cap avatars at 3; the rest show as "+N"
const visibleWatchers = computed(() => watcherSubscribers.value.slice(0, 3))
const extraWatchers = computed(() => Math.max(0, watcherSubscribers.value.length - 3))

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
</script>

<style scoped>
.card-modal {
	padding: 24px;
	min-height: 200px;
}

/* Loading skeletons */
.card-modal__loading {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.card-modal__title-skeleton {
	height: 28px;
	width: 70%;
	border-radius: 4px;
}

.card-modal__desc-skeleton {
	height: 80px;
	border-radius: 4px;
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

/* Error */
.card-modal__error {
	color: var(--color-error);
}

/* Title row */
.card-modal__title-row {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 12px;
}

.card-modal__title {
	flex: 1;
	font-size: 1.25rem;
	font-weight: 700;
	color: var(--color-main-text);
	margin: 0;
	cursor: pointer;
	word-break: break-word;
}

.card-modal__title:hover {
	color: var(--color-primary);
}

.card-modal__edit-btn {
	flex-shrink: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	border: none;
	border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	padding: 0;
	transition: background 0.15s ease, color 0.15s ease;
}

.card-modal__edit-btn:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.card-modal__title-input {
	flex: 1;
	font-size: 1.25rem;
	font-weight: 700;
	color: var(--color-main-text);
	border: 2px solid var(--color-primary);
	border-radius: var(--border-radius);
	padding: 4px 8px;
	background: var(--color-main-background);
}

.card-modal__title-input:focus {
	outline: none;
}

/* Meta rows (due date, done) */
.card-modal__meta {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-bottom: 16px;
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
}

.card-modal__meta-label {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

/* Due date input */
.card-modal__due-input {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 3px 8px;
	font-size: 0.875rem;
	font-family: inherit;
	cursor: pointer;
}

.card-modal__due-input:focus {
	outline: 2px solid var(--color-primary);
	outline-offset: 1px;
}

.card-modal__due-input.card-modal__due--overdue {
	color: var(--color-error);
	border-color: var(--color-error);
}

.card-modal__due-input.card-modal__due--soon {
	color: var(--color-warning, #f0a844);
	border-color: var(--color-warning, #f0a844);
}

.card-modal__due-clear {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 22px;
	height: 22px;
	flex-shrink: 0;
	aspect-ratio: 1;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	padding: 0;
	transition: background 0.15s ease, color 0.15s ease;
}

.card-modal__due-clear:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

/* Done section */
.card-modal__meta--done {
	flex-wrap: wrap;
	gap: 8px;
}

.card-modal__done-at {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

/* Labels */
.card-modal__labels-section {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 20px;
}

.card-modal__labels-empty {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.card-modal__label-chips {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.card-modal__label-chip {
	display: inline-flex;
	align-items: center;
	height: 28px;
	padding: 0 12px;
	border-radius: 14px;
	border: 2px solid var(--label-color, var(--color-border));
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.8rem;
	font-weight: 600;
	cursor: pointer;
	transition: background 0.15s ease, color 0.15s ease, opacity 0.1s ease;
	white-space: nowrap;
}

.card-modal__label-chip:hover:not(:disabled) {
	background: color-mix(in srgb, var(--label-color, var(--color-primary)) 15%, transparent);
}

.card-modal__label-chip--assigned {
	background: var(--label-color, var(--color-primary));
	border-color: var(--label-color, var(--color-primary));
	color: #fff;
}

.card-modal__label-chip--no-color {
	border-color: var(--color-border);
	color: var(--color-main-text);
}

.card-modal__label-chip--no-color.card-modal__label-chip--assigned {
	background: var(--color-background-dark);
	border-color: var(--color-border);
	color: var(--color-main-text);
}

.card-modal__label-chip:disabled {
	opacity: 0.6;
	cursor: default;
}

/* Assignees */
.card-modal__assignees-section {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 20px;
}

.card-modal__assignee-chips {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: center;
}

.card-modal__assignee-chip {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 32px;
	padding: 0 8px 0 4px;
	border-radius: 16px;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	font-size: 0.8rem;
	color: var(--color-main-text);
}

.card-modal__assignee-name {
	font-size: 0.8rem;
	max-width: 120px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.card-modal__assignee-remove {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 18px;
	height: 18px;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	padding: 0;
	transition: background 0.15s ease, color 0.15s ease;
	flex-shrink: 0;
}

.card-modal__assignee-remove:hover:not(:disabled) {
	background: var(--color-error);
	color: #fff;
}

.card-modal__assignee-remove:disabled {
	opacity: 0.5;
	cursor: default;
}

.card-modal__assignees-empty {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

/* Reviews */
.card-modal__reviews-section {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 20px;
}

.card-modal__review-chips {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: center;
}

.card-modal__review-chip {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 32px;
	padding: 0 8px 0 4px;
	border-radius: 16px;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	font-size: 0.8rem;
	color: var(--color-main-text);
}

.card-modal__review-chip--approved {
	border-color: var(--color-success, #46ba61);
	background: rgba(70, 186, 97, 0.08);
}

.card-modal__review-chip--changes_requested {
	border-color: var(--color-error, #e30000);
	background: rgba(var(--color-error-rgb, 227, 0, 0), 0.06);
}

.card-modal__review-name {
	font-size: 0.8rem;
	max-width: 100px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.card-modal__review-state-badge {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	font-size: 0.7rem;
	font-weight: 600;
	padding: 2px 6px;
	border-radius: 8px;
	border: 1px solid currentColor;
	white-space: nowrap;
}

.card-modal__review-state-badge--pending {
	color: var(--color-warning, #f0a844);
	border-color: var(--color-warning, #f0a844);
	background: rgba(240, 168, 68, 0.08);
}

.card-modal__review-state-badge--approved {
	color: var(--color-success, #46ba61);
	border-color: var(--color-success, #46ba61);
	background: rgba(70, 186, 97, 0.1);
}

.card-modal__review-state-badge--changes_requested {
	color: var(--color-error, #e30000);
	border-color: var(--color-error, #e30000);
	background: rgba(var(--color-error-rgb, 227, 0, 0), 0.08);
}

.card-modal__review-remove {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 18px;
	height: 18px;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	padding: 0;
	transition: background 0.15s ease, color 0.15s ease;
	flex-shrink: 0;
}

.card-modal__review-remove:hover:not(:disabled) {
	background: var(--color-error);
	color: #fff;
}

.card-modal__review-remove:disabled {
	opacity: 0.5;
	cursor: default;
}

.card-modal__reviews-empty {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.card-modal__review-verdict {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	margin-top: 4px;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.card-modal__review-verdict-label {
	font-size: 0.8rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.card-modal__sidebar .card-modal__reviews-section {
	margin-bottom: 12px;
}

/* Assign picker */
.card-modal__assign-wrap {
	position: relative;
}

.card-modal__assign-toggle {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 30px;
	padding: 0 12px;
	border: 1px dashed var(--color-border);
	border-radius: 15px;
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
	cursor: pointer;
	transition: border-color 0.15s ease, color 0.15s ease;
}

.card-modal__assign-toggle:hover {
	border-color: var(--color-primary);
	color: var(--color-primary);
}

.card-modal__assign-popover {
	position: absolute;
	top: calc(100% + 4px);
	left: 0;
	z-index: 100;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
	min-width: 200px;
	max-height: 240px;
	overflow-y: auto;
	padding: 4px 0;
}

.card-modal__assign-option {
	display: flex;
	align-items: center;
	gap: 10px;
	width: 100%;
	padding: 8px 14px;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.875rem;
	cursor: pointer;
	text-align: left;
	transition: background 0.1s ease;
}

.card-modal__assign-option:hover:not(:disabled) {
	background: var(--color-background-hover);
}

.card-modal__assign-option:disabled {
	opacity: 0.5;
	cursor: default;
}

/* Description */
.card-modal__description-section {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.card-modal__label {
	font-weight: 600;
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.card-modal__desc-view {
	white-space: pre-wrap;
	word-break: break-word;
	color: var(--color-main-text);
	font-size: 0.9rem;
	line-height: 1.6;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	background: var(--color-main-background);
	transition: border-color 0.15s ease;
	min-height: 60px;
}

.card-modal__desc-view:hover {
	border-color: var(--color-primary);
}

.card-modal__desc-placeholder {
	padding: 10px 12px;
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
	cursor: pointer;
	text-align: left;
	width: 100%;
	transition: border-color 0.15s ease, color 0.15s ease;
}

.card-modal__desc-placeholder:hover {
	border-color: var(--color-primary);
	color: var(--color-main-text);
}

.card-modal__desc-textarea {
	width: 100%;
	padding: 10px 12px;
	border: 2px solid var(--color-primary);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.9rem;
	line-height: 1.6;
	resize: vertical;
	font-family: inherit;
}

.card-modal__desc-textarea:focus {
	outline: none;
}

.card-modal__desc-actions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.card-modal__save-error {
	color: var(--color-error);
	font-size: 0.8rem;
}

/* Rendered markdown description */
.card-modal__desc-rendered {
	max-width: 100%;
	word-break: break-word;
}

.card-modal__desc-rendered :deep(code) {
	background: var(--color-background-dark);
	border-radius: 3px;
	padding: 2px 5px;
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.875em;
}

.card-modal__desc-rendered :deep(pre) {
	background: var(--color-background-dark);
	border-radius: 3px;
	padding: 10px 14px;
	overflow-x: auto;
}

.card-modal__desc-rendered :deep(pre code) {
	background: transparent;
	padding: 0;
	border-radius: 0;
}

.card-modal__desc-rendered :deep(a) {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.card-modal__desc-rendered :deep(ul),
.card-modal__desc-rendered :deep(ol) {
	padding-left: 1.5em;
	margin: 0.5em 0;
}

.card-modal__desc-rendered :deep(blockquote) {
	border-left: 3px solid var(--color-border);
	margin-left: 0;
	padding-left: 1em;
	color: var(--color-text-lighter);
}

.card-modal__desc-rendered :deep(p) {
	margin: 0.5em 0;
}

.card-modal__desc-rendered :deep(p:first-child) {
	margin-top: 0;
}

.card-modal__desc-rendered :deep(p:last-child) {
	margin-bottom: 0;
}

.card-modal__desc-rendered :deep(h1),
.card-modal__desc-rendered :deep(h2),
.card-modal__desc-rendered :deep(h3),
.card-modal__desc-rendered :deep(h4),
.card-modal__desc-rendered :deep(h5),
.card-modal__desc-rendered :deep(h6) {
	font-weight: 700;
	margin: 0.75em 0 0.25em;
}

/* Actions menu (⋯) in the title row */
.card-modal__actions-menu {
	flex-shrink: 0;
	margin-left: auto;
}

/* Delete confirmation banner */
.card-modal__delete-confirm {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
	padding: 10px 14px;
	margin-bottom: 16px;
	background: rgba(var(--color-error-rgb, 227, 0, 0), 0.08);
	border: 1px solid var(--color-error);
	border-radius: var(--border-radius);
	font-size: 0.875rem;
	color: var(--color-main-text);
}

.card-modal__delete-confirm-actions {
	display: flex;
	gap: 8px;
}

/* Standalone action error line (archive / delete errors) */
.card-modal__action-error {
	display: block;
	margin-bottom: 12px;
}

/* ── Checklist ─────────────────────────────────────────────────────────────── */
.card-modal__checklist-section {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 24px;
}

.card-modal__checklist-header {
	display: flex;
	align-items: center;
	gap: 6px;
}

.card-modal__checklist-header-icon {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.card-modal__checklist-progress-text {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	margin-left: auto;
}

/* Progress bar */
.card-modal__checklist-bar-wrap {
	height: 4px;
	background: var(--color-border);
	border-radius: 2px;
	overflow: hidden;
}

.card-modal__checklist-bar-fill {
	height: 100%;
	background: var(--color-primary-element);
	border-radius: 2px;
	transition: width 0.25s ease, background 0.2s ease;
}

.card-modal__checklist-bar-fill--complete {
	background: var(--color-success, #46ba61);
}

/* Items list */
.card-modal__checklist-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.card-modal__checklist-item {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 4px 6px;
	border-radius: var(--border-radius);
	background: transparent;
	transition: background 0.1s ease;
	border: 2px solid transparent;
	cursor: default;
}

.card-modal__checklist-item:hover {
	background: var(--color-background-hover);
}

.card-modal__checklist-item--done .card-modal__checklist-item-title {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

/* Drag-over indicator */
.card-modal__checklist-item[data-drag-over='true'] {
	border-bottom-color: var(--color-primary-element);
}

/* Drag handle */
.card-modal__checklist-drag-handle {
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
	cursor: grab;
	opacity: 0;
	transition: opacity 0.15s ease;
	padding: 2px;
}

.card-modal__checklist-item:hover .card-modal__checklist-drag-handle {
	opacity: 1;
}

.card-modal__checklist-drag-handle:active {
	cursor: grabbing;
}

/* Checkbox */
.card-modal__checklist-checkbox {
	flex-shrink: 0;
	width: 16px;
	height: 16px;
	accent-color: var(--color-primary-element);
	cursor: pointer;
}

.card-modal__checklist-checkbox:disabled {
	opacity: 0.5;
	cursor: default;
}

/* Item title (display) */
.card-modal__checklist-item-title {
	flex: 1;
	font-size: 0.875rem;
	color: var(--color-main-text);
	line-height: 1.4;
	word-break: break-word;
	cursor: text;
	border-radius: var(--border-radius);
	padding: 2px 4px;
}

.card-modal__checklist-item-title:hover {
	background: var(--color-background-dark);
}

.card-modal__checklist-item-title--done {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

/* Item title (editing) */
.card-modal__checklist-item-input {
	flex: 1;
	font-size: 0.875rem;
	color: var(--color-main-text);
	border: 2px solid var(--color-primary);
	border-radius: var(--border-radius);
	padding: 2px 6px;
	background: var(--color-main-background);
	font-family: inherit;
}

.card-modal__checklist-item-input:focus {
	outline: none;
}

/* Delete button */
.card-modal__checklist-item-delete {
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	width: 22px;
	height: 22px;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	padding: 0;
	opacity: 0;
	transition: background 0.15s ease, color 0.15s ease, opacity 0.15s ease;
}

.card-modal__checklist-item:hover .card-modal__checklist-item-delete {
	opacity: 1;
}

.card-modal__checklist-item-delete:hover:not(:disabled) {
	background: var(--color-error);
	color: #fff;
}

.card-modal__checklist-item-delete:disabled {
	opacity: 0.4;
	cursor: default;
}

/* Add item row */
.card-modal__checklist-add {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 4px;
	padding: 4px 6px;
}

.card-modal__checklist-add-icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.card-modal__checklist-add-input {
	flex: 1;
	font-size: 0.875rem;
	color: var(--color-main-text);
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
	padding: 5px 10px;
	background: transparent;
	font-family: inherit;
	transition: border-color 0.15s ease, background 0.15s ease;
}

.card-modal__checklist-add-input::placeholder {
	color: var(--color-text-maxcontrast);
}

.card-modal__checklist-add-input:focus {
	outline: none;
	border-color: var(--color-primary);
	background: var(--color-main-background);
}

.card-modal__checklist-add-input:disabled {
	opacity: 0.5;
}

/* ── Priority selector ────────────────────────────────────────────────────── */
.card-modal__meta--priority {
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.card-modal__priority-buttons {
	display: flex;
	gap: 4px;
	flex-wrap: wrap;
}

.card-modal__priority-btn {
	display: inline-flex;
	align-items: center;
	height: 26px;
	padding: 0 10px;
	border-radius: 13px;
	border: 1px solid var(--color-border);
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 0.75rem;
	font-weight: 500;
	cursor: pointer;
	transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
	white-space: nowrap;
}

.card-modal__priority-btn:hover:not(:disabled) {
	background: var(--color-background-hover);
}

.card-modal__priority-btn:disabled {
	opacity: 0.6;
	cursor: default;
}

/* None: active = dark background */
.card-modal__priority-btn--0.card-modal__priority-btn--active {
	background: var(--color-background-dark);
	border-color: var(--color-text-maxcontrast);
	color: var(--color-main-text);
}

/* Low: grey */
.card-modal__priority-btn--1 {
	color: var(--color-text-maxcontrast);
	border-color: var(--color-border);
}
.card-modal__priority-btn--1.card-modal__priority-btn--active {
	background: #888;
	border-color: #888;
	color: #fff;
}

/* Medium: blue */
.card-modal__priority-btn--2 {
	color: var(--color-primary-element, #0082c9);
	border-color: var(--color-primary-element, #0082c9);
}
.card-modal__priority-btn--2.card-modal__priority-btn--active {
	background: var(--color-primary-element, #0082c9);
	border-color: var(--color-primary-element, #0082c9);
	color: #fff;
}

/* High: orange */
.card-modal__priority-btn--3 {
	color: #e07b00;
	border-color: #e07b00;
}
.card-modal__priority-btn--3.card-modal__priority-btn--active {
	background: #e07b00;
	border-color: #e07b00;
	color: #fff;
}

/* Urgent: red */
.card-modal__priority-btn--4 {
	color: var(--color-error, #e30000);
	border-color: var(--color-error, #e30000);
}
.card-modal__priority-btn--4.card-modal__priority-btn--active {
	background: var(--color-error, #e30000);
	border-color: var(--color-error, #e30000);
	color: #fff;
}

/* ── Hierarchy (parent / sub-cards) ───────────────────────────────────────── */
.card-modal__hierarchy-section {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 16px;
	margin-bottom: 8px;
}

.card-modal__hierarchy-header {
	display: flex;
	align-items: center;
	gap: 6px;
}

.card-modal__hierarchy-icon {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.card-modal__hierarchy-progress-text {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	margin-left: auto;
}

/* Parent row */
.card-modal__parent-row {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.card-modal__parent-link {
	flex: 1;
	min-width: 0;
	text-align: left;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 5px 10px;
	font-size: 0.875rem;
	color: var(--color-primary-element);
	cursor: pointer;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	transition: border-color 0.15s ease, color 0.15s ease;
}

.card-modal__parent-link:hover {
	border-color: var(--color-primary);
	color: var(--color-primary);
}

.card-modal__hierarchy-detach {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
	height: 30px;
	padding: 0 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
	cursor: pointer;
	transition: border-color 0.15s ease, color 0.15s ease, background 0.15s ease;
}

.card-modal__hierarchy-detach:hover:not(:disabled) {
	border-color: var(--color-error);
	color: var(--color-error);
	background: rgba(var(--color-error-rgb, 227, 0, 0), 0.06);
}

.card-modal__hierarchy-detach:disabled {
	opacity: 0.5;
	cursor: default;
}

/* Children list */
.card-modal__children-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.card-modal__child-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 6px;
	border-radius: var(--border-radius);
	transition: background 0.1s ease;
}

.card-modal__child-item:hover {
	background: var(--color-background-hover);
}

/* Done dot indicator */
.card-modal__child-done-dot {
	flex-shrink: 0;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	border: 2px solid var(--color-border);
	background: transparent;
	transition: background 0.15s ease, border-color 0.15s ease;
}

.card-modal__child-done-dot--done {
	background: var(--color-success, #46ba61);
	border-color: var(--color-success, #46ba61);
}

.card-modal__child-link {
	flex: 1;
	min-width: 0;
	text-align: left;
	background: transparent;
	border: none;
	padding: 0;
	font-size: 0.875rem;
	color: var(--color-primary-element);
	cursor: pointer;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	transition: color 0.15s ease;
}

.card-modal__child-link:hover {
	color: var(--color-primary);
	text-decoration: underline;
}

.card-modal__child-link--done {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

.card-modal__child-link--done:hover {
	color: var(--color-text-maxcontrast);
}

.card-modal__child-remove {
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	width: 20px;
	height: 20px;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	padding: 0;
	opacity: 0;
	transition: background 0.15s ease, color 0.15s ease, opacity 0.15s ease;
}

.card-modal__child-item:hover .card-modal__child-remove {
	opacity: 1;
}

.card-modal__child-remove:hover:not(:disabled) {
	background: var(--color-error);
	color: #fff;
}

.card-modal__child-remove:disabled {
	opacity: 0.4;
	cursor: default;
}

/* Add sub-card input row */
.card-modal__add-child-row {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 2px;
	padding: 2px 6px;
}

.card-modal__add-child-input {
	flex: 1;
	font-size: 0.875rem;
	color: var(--color-main-text);
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
	padding: 5px 10px;
	background: transparent;
	font-family: inherit;
	transition: border-color 0.15s ease, background 0.15s ease;
}

.card-modal__add-child-input::placeholder {
	color: var(--color-text-maxcontrast);
}

.card-modal__add-child-input:focus {
	outline: none;
	border-color: var(--color-primary);
	background: var(--color-main-background);
}

.card-modal__add-child-input:disabled {
	opacity: 0.5;
}

/* ── Discussion / Comments ─────────────────────────────────────────────────── */
.card-modal__discussion-section {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-top: 28px;
}

.card-modal__discussion-header {
	display: flex;
	align-items: center;
	gap: 6px;
}

.card-modal__discussion-header-icon {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.card-modal__discussion-count {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 20px;
	height: 18px;
	padding: 0 5px;
	border-radius: 9px;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	font-size: 0.7rem;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
	margin-left: auto;
}

/* Thread container */
.card-modal__discussion-thread {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.card-modal__comment-group {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

/* Individual comment bubble */
.card-modal__comment {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.card-modal__comment--top {
	border-left: 3px solid var(--color-primary-element, #0082c9);
}

/* Replies: indented */
.card-modal__replies {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding-left: 20px;
}

.card-modal__comment--reply {
	border-left: 3px solid var(--color-border);
}

/* Comment meta row */
.card-modal__comment-meta {
	display: flex;
	align-items: baseline;
	gap: 6px;
	flex-wrap: wrap;
}

.card-modal__comment-author {
	font-weight: 600;
	font-size: 0.8rem;
	color: var(--color-main-text);
}

.card-modal__comment-time {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.card-modal__comment-edited {
	font-size: 0.7rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

/* Comment body — sanitized markdown */
.card-modal__comment-body {
	font-size: 0.875rem;
	color: var(--color-main-text);
	line-height: 1.5;
	word-break: break-word;
}

/* Reuse description rendered styles for comment body */
.card-modal__comment-body :deep(code) {
	background: var(--color-background-dark);
	border-radius: 3px;
	padding: 2px 5px;
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.875em;
}

.card-modal__comment-body :deep(pre) {
	background: var(--color-background-dark);
	border-radius: 3px;
	padding: 10px 14px;
	overflow-x: auto;
}

.card-modal__comment-body :deep(pre code) {
	background: transparent;
	padding: 0;
	border-radius: 0;
}

.card-modal__comment-body :deep(a) {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.card-modal__comment-body :deep(ul),
.card-modal__comment-body :deep(ol) {
	padding-left: 1.5em;
	margin: 0.25em 0;
}

.card-modal__comment-body :deep(blockquote) {
	border-left: 3px solid var(--color-border);
	margin-left: 0;
	padding-left: 1em;
	color: var(--color-text-maxcontrast);
}

.card-modal__comment-body :deep(p) {
	margin: 0.25em 0;
}

.card-modal__comment-body :deep(p:first-child) {
	margin-top: 0;
}

.card-modal__comment-body :deep(p:last-child) {
	margin-bottom: 0;
}

.card-modal__comment-body :deep(strong) {
	font-weight: 700;
}

/* Comment author controls (edit + delete) */
.card-modal__comment-controls {
	display: flex;
	gap: 4px;
	align-items: center;
}

.card-modal__comment-control-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	border: none;
	border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	padding: 0;
	transition: background 0.15s ease, color 0.15s ease;
}

.card-modal__comment-control-btn:hover:not(:disabled) {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.card-modal__comment-control-btn--danger:hover:not(:disabled) {
	background: var(--color-error);
	color: #fff;
}

.card-modal__comment-control-btn:disabled {
	opacity: 0.4;
	cursor: default;
}

/* Reply button */
.card-modal__comment-reply-btn {
	align-self: flex-start;
	display: inline-flex;
	align-items: center;
	height: 24px;
	padding: 0 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 0.75rem;
	cursor: pointer;
	transition: border-color 0.15s ease, color 0.15s ease;
}

.card-modal__comment-reply-btn:hover {
	border-color: var(--color-primary);
	color: var(--color-primary);
}

/* Inline edit textarea for an existing comment */
.card-modal__comment-edit-textarea {
	width: 100%;
	padding: 8px 10px;
	border: 2px solid var(--color-primary);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.875rem;
	line-height: 1.5;
	resize: vertical;
	font-family: inherit;
}

.card-modal__comment-edit-textarea:focus {
	outline: none;
}

.card-modal__comment-edit-actions {
	display: flex;
	align-items: center;
	gap: 8px;
}

/* Reply compose box */
.card-modal__reply-compose {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.card-modal__reply-compose--indent {
	padding-left: 20px;
}

/* Top-level compose box */
.card-modal__comment-compose {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 4px;
}

.card-modal__comment-compose-textarea {
	width: 100%;
	padding: 10px 12px;
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.875rem;
	line-height: 1.5;
	resize: vertical;
	font-family: inherit;
	transition: border-color 0.15s ease, background 0.15s ease;
}

.card-modal__comment-compose-textarea::placeholder {
	color: var(--color-text-maxcontrast);
}

.card-modal__comment-compose-textarea:focus {
	outline: none;
	border-color: var(--color-primary);
	border-style: solid;
	background: var(--color-main-background);
}

.card-modal__comment-compose-textarea:disabled {
	opacity: 0.5;
}

.card-modal__comment-compose-actions {
	display: flex;
	align-items: center;
	gap: 8px;
}

/* Watch / Unwatch toggle */
.card-modal__watch-wrap {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-shrink: 0;
}

.card-modal__watch-btn {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	height: 28px;
	padding: 0 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
	font-family: inherit;
	cursor: pointer;
	transition: border-color 0.15s ease, color 0.15s ease, background 0.15s ease;
	white-space: nowrap;
}

.card-modal__watch-btn:hover:not(:disabled) {
	border-color: var(--color-primary);
	color: var(--color-primary);
}

.card-modal__watch-btn--active {
	border-color: var(--color-primary);
	color: var(--color-primary);
	background: var(--color-primary-light);
}

.card-modal__watch-btn:disabled {
	opacity: 0.5;
	cursor: default;
}

.card-modal__watch-label {
	line-height: 1;
}

.card-modal__watch-count {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 18px;
	height: 18px;
	padding: 0 4px;
	border-radius: 9px;
	background: var(--color-primary);
	color: var(--color-primary-text);
	font-size: 0.7rem;
	font-weight: 600;
	line-height: 1;
}

.card-modal__watch-avatars {
	display: flex;
	align-items: center;
	gap: 2px;
}

.card-modal__watch-avatar {
	border: 2px solid var(--color-main-background);
	border-radius: 50%;
	margin-left: -6px;
}

.card-modal__watch-avatar:first-child {
	margin-left: 0;
}

.card-modal__watch-avatar-extra {
	margin-left: 4px;
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.card-modal__watch-error {
	margin-left: 4px;
}

/* ── Two-column layout ─────────────────────────────────────────────────────── */
.card-modal__columns {
	display: grid;
	grid-template-columns: 1fr 300px;
	gap: 24px;
	align-items: start;
	margin-top: 4px;
}

/* Left column: description + discussion */
.card-modal__main {
	display: flex;
	flex-direction: column;
	gap: 0;
	min-width: 0;
}

.card-modal__main .card-modal__description-section {
	margin-bottom: 0;
}

.card-modal__main .card-modal__discussion-section {
	margin-top: 28px;
}

/* Right column: attributes sidebar */
.card-modal__sidebar {
	display: flex;
	flex-direction: column;
	gap: 0;
	min-width: 0;
	border-left: 1px solid var(--color-border);
	padding-left: 20px;
}

/* Tighten spacing between sidebar attribute rows */
.card-modal__sidebar .card-modal__meta {
	margin-bottom: 12px;
}

.card-modal__sidebar .card-modal__labels-section {
	margin-bottom: 12px;
}

.card-modal__sidebar .card-modal__assignees-section {
	margin-bottom: 12px;
}

.card-modal__sidebar .card-modal__hierarchy-section {
	margin-top: 0;
	margin-bottom: 12px;
}

.card-modal__sidebar .card-modal__checklist-section {
	margin-top: 0;
}

/* Collapse to single column on narrow viewports */
@media (max-width: 700px) {
	.card-modal__columns {
		grid-template-columns: 1fr;
	}

	.card-modal__sidebar {
		border-left: none;
		padding-left: 0;
		border-top: 1px solid var(--color-border);
		padding-top: 20px;
	}

	/* On narrow screens show sidebar above main (attributes first) */
	.card-modal__sidebar {
		order: -1;
	}
}
</style>
