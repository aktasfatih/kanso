<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="project-view">
		<!-- Loading state -->
		<div v-if="isLoadingCards && !cards.length" class="project-view__loading" aria-live="polite">
			<span class="project-view__spinner" aria-hidden="true" />
			<span>{{ t('kanso', 'Loading project…') }}</span>
		</div>

		<!-- Error state -->
		<div v-else-if="isError" class="project-view__error">
			{{ t('kanso', 'Failed to load project cards. Please try again.') }}
		</div>

		<template v-else>
			<!-- Header -->
			<div class="project-view__header">
				<div class="project-view__header-left">
					<button class="project-view__back" :title="t('kanso', 'Back to projects')" @click="router.push({ name: 'projects' })">
						<ChevronLeftIcon :size="20" />
					</button>
					<span
						v-if="project?.color"
						class="project-view__dot"
						:style="{ background: '#' + project.color }" />
					<h1 class="project-view__title">{{ project?.title ?? t('kanso', 'Project') }}</h1>
				</div>

				<div class="project-view__header-actions">
					<button
						class="project-view__analytics-btn"
						:title="t('kanso', 'Project analytics')"
						:aria-label="t('kanso', 'Project analytics')"
						@click="goToStats">
						<ChartBarIcon :size="20" />
					</button>

					<NcActions :force-menu="true">
						<NcActionButton
							v-if="project"
							:close-after-click="true"
							data-test="project-pin-toggle"
							@click="togglePin(project)">
							<template #icon>
								<StarIcon v-if="project.pinned" :size="20" />
								<StarOutlineIcon v-else :size="20" />
							</template>
							{{ project.pinned ? t('kanso', 'Unpin project') : t('kanso', 'Pin to sidebar') }}
						</NcActionButton>
						<NcActionButton :close-after-click="true" @click="openEditDialog">
							<template #icon>
								<PencilIcon :size="20" />
							</template>
							{{ t('kanso', 'Edit project') }}
						</NcActionButton>
						<NcActionSeparator />
						<NcActionButton :close-after-click="true" @click="handleDelete">
							<template #icon>
								<TrashCanIcon :size="20" />
							</template>
							{{ t('kanso', 'Delete project') }}
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<span v-if="actionError" class="project-view__action-error">{{ actionError }}</span>

			<!-- Prominent, in-place editable description directly under the title.
			     Reuses the shipped markdown editor (#3562): useMarkdownToolbar +
			     renderMarkdown + the updateProject mutation path — no backend change. -->
			<section class="project-view__description">
				<template v-if="editingDescription">
					<div class="project-view__md-toolbar" role="toolbar" :aria-label="t('kanso', 'Formatting')">
						<button type="button" class="project-view__md-btn" :title="t('kanso', 'Bold')" @mousedown.prevent @click="descToolbar.bold()"><FormatBoldIcon :size="18" /></button>
						<button type="button" class="project-view__md-btn" :title="t('kanso', 'Italic')" @mousedown.prevent @click="descToolbar.italic()"><FormatItalicIcon :size="18" /></button>
						<button type="button" class="project-view__md-btn" :title="t('kanso', 'Heading')" @mousedown.prevent @click="descToolbar.heading()"><FormatHeaderPoundIcon :size="18" /></button>
						<span class="project-view__md-sep" />
						<button type="button" class="project-view__md-btn" :title="t('kanso', 'Bulleted list')" @mousedown.prevent @click="descToolbar.bulletList()"><FormatListBulletedIcon :size="18" /></button>
						<button type="button" class="project-view__md-btn" :title="t('kanso', 'Quote')" @mousedown.prevent @click="descToolbar.quote()"><FormatQuoteCloseIcon :size="18" /></button>
						<span class="project-view__md-sep" />
						<button type="button" class="project-view__md-btn" :title="t('kanso', 'Inline code')" @mousedown.prevent @click="descToolbar.inlineCode()"><CodeTagsIcon :size="18" /></button>
						<button type="button" class="project-view__md-btn" :title="t('kanso', 'Link')" @mousedown.prevent @click="descToolbar.link()"><LinkVariantIcon :size="18" /></button>
						<span class="project-view__md-toolbar-spacer" />
						<button
							type="button"
							class="project-view__md-btn"
							:class="{ 'project-view__md-btn--active': showInlinePreview }"
							:aria-pressed="showInlinePreview"
							:title="t('kanso', 'Toggle preview')"
							@mousedown.prevent
							@click="showInlinePreview = !showInlinePreview"><EyeOutlineIcon :size="18" /></button>
					</div>
					<textarea
						ref="inlineDescRef"
						v-model="draftDescription"
						class="project-view__desc-textarea"
						rows="6"
						:placeholder="t('kanso', 'Add a description — markdown supported')"
						:disabled="updateMutation.isPending.value"
						@keydown="onInlineDescKeydown"
						@blur="saveDescriptionOnBlur" />
					<div v-if="showInlinePreview" class="project-view__desc-preview">
						<span class="project-view__desc-preview-label">{{ t('kanso', 'Preview') }}</span>
						<!-- eslint-disable-next-line vue/no-v-html — renderMarkdown sanitises via DOMPurify -->
						<div class="project-view__desc-rendered kanso-md" v-html="renderMarkdown(draftDescription)" />
					</div>
					<div class="project-view__desc-actions">
						<NcButton type="primary" :disabled="updateMutation.isPending.value" @mousedown.prevent @click="saveDescription">
							{{ t('kanso', 'Save') }}
						</NcButton>
						<NcButton @mousedown.prevent @click="cancelDescriptionEdit">
							{{ t('kanso', 'Cancel') }}
						</NcButton>
						<span class="project-view__desc-hint">{{ t('kanso', 'Esc cancel · Ctrl+Enter save') }}</span>
						<span v-if="descError" class="project-view__desc-error">{{ descError }}</span>
					</div>
				</template>

				<template v-else>
					<template v-if="project?.description">
						<div
							class="project-view__desc-view"
							:class="{ 'project-view__desc-view--collapsed': !descExpanded, 'project-view__desc-view--overflowing': descOverflows && !descExpanded }"
							tabindex="0"
							role="button"
							data-test="project-description"
							:title="t('kanso', 'Click to edit the description')"
							@click="startDescriptionEdit"
							@keydown.enter="startDescriptionEdit">
							<!-- eslint-disable-next-line vue/no-v-html — renderMarkdown sanitises via DOMPurify -->
							<div ref="descContentRef" class="project-view__desc-rendered kanso-md" v-html="renderMarkdown(project.description)" />
						</div>
						<button
							v-if="descOverflows || descExpanded"
							type="button"
							class="project-view__desc-toggle"
							data-test="project-description-toggle"
							:aria-expanded="descExpanded"
							@click="descExpanded = !descExpanded">
							<ChevronDownIcon :size="18" :class="{ 'project-view__desc-toggle-icon--up': descExpanded }" />
							{{ descExpanded ? t('kanso', 'Show less') : t('kanso', 'Show more') }}
						</button>
					</template>
					<button
						v-else
						type="button"
						class="project-view__desc-placeholder"
						@click="startDescriptionEdit">
						{{ t('kanso', 'Add a description…') }}
					</button>
				</template>
			</section>

			<div v-if="plan" class="project-view__plan">
				<ProjectHealth
					:plan="plan"
					:project-id="id"
					:error="datesError"
					@update-dates="saveDates" />
				<ProjectBurnup v-if="plan && plan.total" class="project-view__burnup" :plan="plan" />
			</div>

			<div class="project-view__toolbar">
				<div v-if="cards.length" class="project-view__modes" role="tablist" :aria-label="t('kanso', 'Card layout')">
					<button
						v-for="m in modes"
						:key="m.key"
						role="tab"
						class="project-view__mode"
						:class="{ 'project-view__mode--active': mode === m.key }"
						:aria-selected="mode === m.key"
						:data-test="'project-mode-' + m.key"
						@click="setMode(m.key)">
						<component :is="m.icon" :size="16" />
						{{ m.label }}
					</button>
				</div>
				<!-- Add card control -->
				<div class="project-view__add-row">
					<button
						class="project-view__add-btn"
						:aria-expanded="showPicker"
						@click="togglePicker">
						<PlusIcon :size="16" />
						{{ t('kanso', 'Add card') }}
					</button>

					<CardSearchPicker
						v-if="showPicker"
						class="project-view__picker"
						:disabled-card-ids="cardIdsInProject"
						:error="addError"
						@pick="handlePickCard"
						@close="closePicker" />
				</div>
			</div>

			<!-- Empty state -->
			<NcEmptyContent
				v-if="!cards.length && !isLoadingCards"
				:name="t('kanso', 'No cards in this project')"
				:description="t('kanso', 'Use the Add card button above to collect cards from any board.')">
				<template #icon>
					<FolderMultipleOutlineIcon :size="64" />
				</template>
			</NcEmptyContent>

			<template v-else>

				<BoardTimelineView
					v-if="mode === 'timeline'"
					class="project-view__timeline"
					:cards="cards"
					:groups="boardGroups.map((g) => ({ key: g.boardId, title: g.boardTitle, cards: g.cards }))"
					:can-edit="false"
					:board-id="null"
					@open="openCard" />

				<template v-else>
					<component
						:is="group.collapsible ? 'details' : 'section'"
						v-for="group in listGroups"
						:key="group.key"
						class="project-view__section"
						:data-test="'project-group-' + group.key">
						<component :is="group.collapsible ? 'summary' : 'h2'" class="project-view__section-title">
							{{ group.title }} <span class="project-view__count">{{ group.cards.length }}</span>
						</component>
						<ul class="project-view__list">
							<li
								v-for="card in group.cards"
								:key="card.id"
								class="project-view__row"
								:class="{ 'project-view__row--done': card.doneAt > 0 }"
								tabindex="0"
								role="button"
								@click="openCard(card)"
								@keydown.enter="openCard(card)">
								<div class="project-view__row-main">
									<span class="project-view__card-title">{{ card.title }}</span>
									<span class="project-view__meta">
										<span v-if="cardRef(card)" class="project-view__ref">{{ cardRef(card) }}</span>
										<span v-if="mode === 'status'">{{ card.boardTitle }} · </span>
										<span class="project-view__stack">{{ card.stackTitle }}</span>
									</span>
								</div>
								<span
									v-if="card.blockers > 0 && !card.doneAt"
									class="project-view__badge project-view__badge--warning"
									:title="n('kanso', 'Blocked by {count} open card', 'Blocked by {count} open cards', card.blockers, { count: card.blockers })">
									<BlockHelperIcon :size="14" />
								</span>
								<span v-if="card.estimate" class="project-view__badge">{{ card.estimate }}</span>
								<span
									v-if="card.duedate"
									class="project-view__due"
									:class="{ 'project-view__due--overdue': isOverdue(card) }">
									{{ formatDue(card.duedate) }}
								</span>
								<NcActions :force-menu="false">
									<NcActionButton
										:close-after-click="true"
										@click.stop="handleRemoveCard(card.id)">
										<template #icon>
											<CloseIcon :size="20" />
										</template>
										{{ t('kanso', 'Remove from project') }}
									</NcActionButton>
								</NcActions>
							</li>
						</ul>
					</component>
				</template>
			</template>

			<!-- Discussion log — an owner-only personal thread on the project (#3563).
			     Reuses the shipped markdown editor (useMarkdownToolbar + renderMarkdown,
			     same as the description) and mirrors the card comment thread pattern:
			     one-level replies, edit/delete. No @mention/notify — a project has a
			     single reader (the owner). -->
			<section class="project-view__discussion">
				<h2 class="project-view__section-title">
					{{ t('kanso', 'Discussion') }}<span v-if="commentTree.length"> · {{ commentTotal }}</span>
				</h2>

				<!-- Composer -->
				<div class="project-view__composer">
					<div class="project-view__md-toolbar" role="toolbar" :aria-label="t('kanso', 'Formatting')">
						<button type="button" class="project-view__md-btn" :title="t('kanso', 'Bold')" @mousedown.prevent @click="commentToolbar.bold()"><FormatBoldIcon :size="18" /></button>
						<button type="button" class="project-view__md-btn" :title="t('kanso', 'Italic')" @mousedown.prevent @click="commentToolbar.italic()"><FormatItalicIcon :size="18" /></button>
						<button type="button" class="project-view__md-btn" :title="t('kanso', 'Bulleted list')" @mousedown.prevent @click="commentToolbar.bulletList()"><FormatListBulletedIcon :size="18" /></button>
						<button type="button" class="project-view__md-btn" :title="t('kanso', 'Quote')" @mousedown.prevent @click="commentToolbar.quote()"><FormatQuoteCloseIcon :size="18" /></button>
						<button type="button" class="project-view__md-btn" :title="t('kanso', 'Inline code')" @mousedown.prevent @click="commentToolbar.inlineCode()"><CodeTagsIcon :size="18" /></button>
						<button type="button" class="project-view__md-btn" :title="t('kanso', 'Link')" @mousedown.prevent @click="commentToolbar.link()"><LinkVariantIcon :size="18" /></button>
					</div>
					<textarea
						ref="newCommentRef"
						v-model="newCommentBody"
						class="project-view__comment-textarea"
						rows="3"
						:placeholder="t('kanso', 'Add a note to this project — markdown supported')"
						:disabled="addComment.isPending.value"
						@keydown.ctrl.enter.prevent="submitNewComment"
						@keydown.meta.enter.prevent="submitNewComment" />
					<div class="project-view__comment-actions">
						<NcButton type="primary" :disabled="addComment.isPending.value || !newCommentBody.trim()" @click="submitNewComment">
							{{ t('kanso', 'Post') }}
						</NcButton>
						<span class="project-view__desc-hint">{{ t('kanso', 'Ctrl+Enter to post') }}</span>
						<span v-if="commentError" class="project-view__desc-error">{{ commentError }}</span>
					</div>
				</div>

				<!-- Thread -->
				<div v-if="commentTree.length" class="project-view__thread">
					<div
						v-for="{ comment: topComment, replies } in commentTree"
						:key="topComment.id"
						class="project-view__comment-group">
						<div class="project-view__comment">
							<div class="project-view__comment-main">
								<div class="project-view__comment-meta">
									<span class="project-view__comment-author">{{ topComment.authorDisplayName || topComment.author }}</span>
									<span class="project-view__comment-time">{{ formatCommentTime(topComment.createdAt) }}</span>
									<span v-if="topComment.editedAt > 0" class="project-view__comment-edited">{{ t('kanso', 'edited') }}</span>
								</div>

								<template v-if="editingCommentId === topComment.id">
									<textarea
										v-model="editingCommentBody"
										class="project-view__comment-textarea"
										rows="3"
										@keydown.ctrl.enter.prevent="saveCommentEdit(topComment)"
										@keydown.meta.enter.prevent="saveCommentEdit(topComment)"
										@keydown.escape.stop="cancelCommentEdit" />
									<div class="project-view__comment-edit-actions">
										<NcButton type="primary" :disabled="editComment.isPending.value" @click="saveCommentEdit(topComment)">{{ t('kanso', 'Save') }}</NcButton>
										<NcButton @click="cancelCommentEdit">{{ t('kanso', 'Cancel') }}</NcButton>
									</div>
								</template>
								<!-- eslint-disable-next-line vue/no-v-html — renderMarkdown sanitises via DOMPurify -->
								<div v-else class="project-view__comment-body project-view__desc-rendered kanso-md" v-html="renderMarkdown(topComment.body)" />

								<div class="project-view__comment-controls">
									<button
										v-if="editingCommentId !== topComment.id"
										class="project-view__comment-link-btn"
										@click="openReplyBox(topComment.id)">
										{{ t('kanso', 'Reply') }}
									</button>
									<button class="project-view__comment-icon-btn" :title="t('kanso', 'Edit comment')" @click="startCommentEdit(topComment)">
										<PencilIcon :size="14" />
									</button>
									<button
										class="project-view__comment-icon-btn project-view__comment-icon-btn--danger"
										:title="t('kanso', 'Delete comment')"
										:disabled="deleteComment.isPending.value"
										@click="handleDeleteComment(topComment)">
										<TrashCanIcon :size="14" />
									</button>
								</div>
							</div>
						</div>

						<div v-if="replyingToId === topComment.id" class="project-view__reply-compose">
							<textarea
								v-model="replyBody"
								class="project-view__comment-textarea"
								:placeholder="t('kanso', 'Write a reply…')"
								rows="2"
								@keydown.ctrl.enter.prevent="submitReply(topComment.id)"
								@keydown.meta.enter.prevent="submitReply(topComment.id)"
								@keydown.escape.stop="closeReplyBox" />
							<div class="project-view__comment-edit-actions">
								<NcButton type="primary" :disabled="addComment.isPending.value || !replyBody.trim()" @click="submitReply(topComment.id)">{{ t('kanso', 'Post reply') }}</NcButton>
								<NcButton @click="closeReplyBox">{{ t('kanso', 'Cancel') }}</NcButton>
							</div>
						</div>

						<div v-if="replies.length" class="project-view__replies">
							<div v-for="reply in replies" :key="reply.id" class="project-view__comment project-view__comment--reply">
								<div class="project-view__comment-main">
									<div class="project-view__comment-meta">
										<span class="project-view__comment-author">{{ reply.authorDisplayName || reply.author }}</span>
										<span class="project-view__comment-time">{{ formatCommentTime(reply.createdAt) }}</span>
										<span v-if="reply.editedAt > 0" class="project-view__comment-edited">{{ t('kanso', 'edited') }}</span>
									</div>

									<template v-if="editingCommentId === reply.id">
										<textarea
											v-model="editingCommentBody"
											class="project-view__comment-textarea"
											rows="3"
											@keydown.ctrl.enter.prevent="saveCommentEdit(reply)"
											@keydown.meta.enter.prevent="saveCommentEdit(reply)"
											@keydown.escape.stop="cancelCommentEdit" />
										<div class="project-view__comment-edit-actions">
											<NcButton type="primary" :disabled="editComment.isPending.value" @click="saveCommentEdit(reply)">{{ t('kanso', 'Save') }}</NcButton>
											<NcButton @click="cancelCommentEdit">{{ t('kanso', 'Cancel') }}</NcButton>
										</div>
									</template>
									<!-- eslint-disable-next-line vue/no-v-html — renderMarkdown sanitises via DOMPurify -->
									<div v-else class="project-view__comment-body project-view__desc-rendered kanso-md" v-html="renderMarkdown(reply.body)" />

									<div class="project-view__comment-controls">
										<button class="project-view__comment-icon-btn" :title="t('kanso', 'Edit comment')" @click="startCommentEdit(reply)">
											<PencilIcon :size="14" />
										</button>
										<button
											class="project-view__comment-icon-btn project-view__comment-icon-btn--danger"
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
				</div>

				<div v-else class="project-view__discussion-empty">
					{{ t('kanso', 'No notes yet. Start a discussion log for this project.') }}
				</div>
			</section>
		</template>

		<!-- Edit project dialog -->
		<NcDialog
			v-if="showEditDialog"
			:name="t('kanso', 'Edit project')"
			:open="showEditDialog"
			@closing="closeEditDialog">
			<div class="project-view__form">
				<label class="project-view__form-label" for="edit-project-title">
					{{ t('kanso', 'Title') }}
					<span aria-hidden="true" class="project-view__required">*</span>
				</label>
				<input
					id="edit-project-title"
					ref="editTitleInputRef"
					v-model="editTitle"
					class="project-view__form-input"
					type="text"
					maxlength="255"
					:placeholder="t('kanso', 'Project title')"
					:disabled="updateMutation.isPending.value"
					@keydown.enter.prevent="submitEdit">

				<label class="project-view__form-label" for="edit-project-desc">
					{{ t('kanso', 'Description') }}
				</label>
				<div class="project-view__md-toolbar" role="toolbar" :aria-label="t('kanso', 'Formatting')">
					<button type="button" class="project-view__md-btn" :title="t('kanso', 'Bold')" @mousedown.prevent @click="mdToolbar.bold()"><FormatBoldIcon :size="18" /></button>
					<button type="button" class="project-view__md-btn" :title="t('kanso', 'Italic')" @mousedown.prevent @click="mdToolbar.italic()"><FormatItalicIcon :size="18" /></button>
					<button type="button" class="project-view__md-btn" :title="t('kanso', 'Heading')" @mousedown.prevent @click="mdToolbar.heading()"><FormatHeaderPoundIcon :size="18" /></button>
					<span class="project-view__md-sep" />
					<button type="button" class="project-view__md-btn" :title="t('kanso', 'Bulleted list')" @mousedown.prevent @click="mdToolbar.bulletList()"><FormatListBulletedIcon :size="18" /></button>
					<button type="button" class="project-view__md-btn" :title="t('kanso', 'Quote')" @mousedown.prevent @click="mdToolbar.quote()"><FormatQuoteCloseIcon :size="18" /></button>
					<span class="project-view__md-sep" />
					<button type="button" class="project-view__md-btn" :title="t('kanso', 'Inline code')" @mousedown.prevent @click="mdToolbar.inlineCode()"><CodeTagsIcon :size="18" /></button>
					<button type="button" class="project-view__md-btn" :title="t('kanso', 'Link')" @mousedown.prevent @click="mdToolbar.link()"><LinkVariantIcon :size="18" /></button>
					<span class="project-view__md-toolbar-spacer" />
					<button
						type="button"
						class="project-view__md-btn"
						:class="{ 'project-view__md-btn--active': showDescPreview }"
						:aria-pressed="showDescPreview"
						:title="t('kanso', 'Toggle preview')"
						@mousedown.prevent
						@click="showDescPreview = !showDescPreview"><EyeOutlineIcon :size="18" /></button>
				</div>
				<textarea
					id="edit-project-desc"
					ref="descTextareaRef"
					v-model="editDescription"
					class="project-view__form-input project-view__form-textarea"
					rows="6"
					:placeholder="t('kanso', 'Optional description — markdown supported')"
					:disabled="updateMutation.isPending.value" />
				<div v-if="showDescPreview" class="project-view__desc-preview">
					<span class="project-view__desc-preview-label">{{ t('kanso', 'Preview') }}</span>
					<!-- eslint-disable-next-line vue/no-v-html — renderMarkdown sanitises via DOMPurify -->
					<div class="project-view__desc-rendered kanso-md" v-html="renderMarkdown(editDescription)" />
				</div>

				<label class="project-view__form-label">{{ t('kanso', 'Color') }}</label>
				<div class="project-view__color-grid">
					<button
						v-for="preset in COLOR_PRESETS"
						:key="preset"
						type="button"
						class="project-view__color-swatch"
						:class="{ 'project-view__color-swatch--active': editColor === preset }"
						:style="{ background: '#' + preset }"
						:title="'#' + preset"
						:aria-pressed="editColor === preset"
						@click="editColor = editColor === preset ? '' : preset" />
					<button
						type="button"
						class="project-view__color-swatch project-view__color-swatch--clear"
						:class="{ 'project-view__color-swatch--active': !editColor }"
						:title="t('kanso', 'No color')"
						:aria-pressed="!editColor"
						@click="editColor = ''">
						×
					</button>
				</div>

				<span v-if="editError" class="project-view__form-error">{{ editError }}</span>
			</div>

			<template #actions>
				<NcButton :disabled="updateMutation.isPending.value || !editTitle.trim()" type="primary" @click="submitEdit">
					{{ t('kanso', 'Save') }}
				</NcButton>
				<NcButton @click="closeEditDialog">
					{{ t('kanso', 'Cancel') }}
				</NcButton>
			</template>
		</NcDialog>
		<CardModal
			v-if="selectedCardId"
			:key="selectedCardId"
			:card-id="selectedCardId"
			:board-id="selectedBoardId"
			controlled
			@navigate="navigateCard"
			@close="closeCard" />
	</div>
</template>

<script setup>
import { ref, computed, nextTick, watch, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { useQueryClient } from '@tanstack/vue-query'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import TrashCanIcon from 'vue-material-design-icons/TrashCan.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import ChevronLeftIcon from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChartBarIcon from 'vue-material-design-icons/ChartBar.vue'
import FolderMultipleOutlineIcon from 'vue-material-design-icons/FolderMultipleOutline.vue'
import FormatBoldIcon from 'vue-material-design-icons/FormatBold.vue'
import FormatItalicIcon from 'vue-material-design-icons/FormatItalic.vue'
import FormatHeaderPoundIcon from 'vue-material-design-icons/FormatHeaderPound.vue'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'
import FormatQuoteCloseIcon from 'vue-material-design-icons/FormatQuoteClose.vue'
import CodeTagsIcon from 'vue-material-design-icons/CodeTags.vue'
import LinkVariantIcon from 'vue-material-design-icons/LinkVariant.vue'
import EyeOutlineIcon from 'vue-material-design-icons/EyeOutline.vue'
import BlockHelperIcon from 'vue-material-design-icons/BlockHelper.vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import StarOutlineIcon from 'vue-material-design-icons/StarOutline.vue'
import FormatListGroupIcon from 'vue-material-design-icons/FormatListGroup.vue'
import ViewDashboardOutlineIcon from 'vue-material-design-icons/ViewDashboardOutline.vue'
import ChartGanttIcon from 'vue-material-design-icons/ChartGantt.vue'
import { useProjects } from '../composables/useProjects.js'
import { usePageTitle } from '../composables/usePageTitle.js'
import { useProjectCards, useProjectPlan } from '../composables/useProject.js'
import { useProjectComments } from '../composables/useProjectComments.js'
import { buildCommentTree } from '../composables/useComments.js'
import CardSearchPicker from '../components/CardSearchPicker.vue'
import CardModal from '../components/CardModal.vue'
import ProjectHealth from '../components/ProjectHealth.vue'
import ProjectBurnup from '../components/ProjectBurnup.vue'
import BoardTimelineView from '../components/BoardTimelineView.vue'
import { renderMarkdown } from '../services/markdown.js'
import { humanId } from '../services/humanId.js'
import { useMarkdownToolbar } from '../composables/useMarkdownToolbar.js'

const COLOR_PRESETS = [
	'e53935', 'f4511e', 'f6bf26', '33b679', '0b8043',
	'039be5', '3f51b5', '7986cb', '8e24aa', '616161',
]

const props = defineProps({
	id: {
		type: String,
		required: true,
	},
})

const router = useRouter()
const queryClient = useQueryClient()

// Resolve the project from the list query (already in cache)
const { data: projectsData, update: updateMutation, remove: removeMutation, togglePin } = useProjects()
const project = computed(() => (projectsData.value ?? []).find((p) => String(p.id) === String(props.id)) ?? null)

// Browser tab title (#125). Empty until the (cached) projects list resolves.
usePageTitle(() => project.value?.title ?? '')

const {
	data: cardsData,
	isLoading: isLoadingCards,
	isError,
	addCard,
	removeCard,
} = useProjectCards(computed(() => props.id))

const cards = computed(() => cardsData.value ?? [])

const { data: planData } = useProjectPlan(computed(() => props.id))
const plan = computed(() => planData.value ?? null)

// Group cards by boardTitle, preserving server order within each group
const boardGroups = computed(() => {
	const map = new Map()
	for (const card of cards.value) {
		const key = card.boardId
		if (!map.has(key)) {
			map.set(key, { boardId: card.boardId, boardTitle: card.boardTitle, cards: [] })
		}
		map.get(key).cards.push(card)
	}
	return Array.from(map.values())
})

function isOverdue(card) {
	return !card.doneAt && !!card.duedate && new Date(card.duedate).getTime() < Date.now()
}

// The card's board reference (e.g. KANSO-359): its board's prefix plus the
// card's per-board number, both on the project-card payload. Null when either
// half is missing (a pre-migration card), so the chip hides rather than
// rendering a broken id.
function cardRef(card) {
	return card.boardPrefix ? humanId(card.boardPrefix, card.boardSeq) : null
}

function statusOf(card) {
	if (card.doneAt > 0) return 'done'
	if (card.blockers > 0) return 'blocked'
	if (isOverdue(card)) return 'overdue'
	if (card.startedAt > 0) return 'in-progress'
	return 'not-started'
}

const statusGroups = computed(() => {
	const order = [
		{ key: 'blocked', title: t('kanso', 'Blocked') },
		{ key: 'overdue', title: t('kanso', 'Overdue') },
		{ key: 'in-progress', title: t('kanso', 'In progress') },
		{ key: 'not-started', title: t('kanso', 'Not started') },
		{ key: 'done', title: t('kanso', 'Done'), collapsible: true },
	]
	const byStatus = new Map(order.map((g) => [g.key, []]))
	for (const card of cards.value) {
		byStatus.get(statusOf(card)).push(card)
	}
	return order
		.map((g) => ({ ...g, cards: byStatus.get(g.key) }))
		.filter((g) => g.cards.length)
})

const MODE_KEY = 'kanso.projectView.mode'
const modes = [
	{ key: 'status', label: t('kanso', 'Status'), icon: FormatListGroupIcon },
	{ key: 'board', label: t('kanso', 'Board'), icon: ViewDashboardOutlineIcon },
	{ key: 'timeline', label: t('kanso', 'Timeline'), icon: ChartGanttIcon },
]

function readMode() {
	try {
		const stored = localStorage.getItem(MODE_KEY)
		return modes.some((m) => m.key === stored) ? stored : 'status'
	} catch {
		return 'status'
	}
}

const mode = ref(readMode())

function setMode(next) {
	mode.value = next
	try {
		localStorage.setItem(MODE_KEY, next)
	} catch {
		// Storage unavailable (private mode): the choice just isn't remembered.
	}
}

const listGroups = computed(() => (mode.value === 'board'
	? boardGroups.value.map((g) => ({ key: 'board-' + g.boardId, title: g.boardTitle, cards: g.cards }))
	: statusGroups.value))

const datesError = ref('')

async function saveDates(dates) {
	datesError.value = ''
	try {
		await updateMutation.mutateAsync({ id: Number(props.id), ...dates })
	} catch (err) {
		datesError.value = err?.response?.data?.error || t('kanso', 'Failed to update dates.')
	}
}

function formatDue(iso) {
	try {
		return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
	} catch {
		return iso
	}
}

// Cards open as an overlay on the project, so closing returns here.
const selectedCardId = ref(null)
const selectedBoardId = ref(null)

function openCard(card) {
	selectedCardId.value = String(card.id)
	selectedBoardId.value = card.boardId != null ? String(card.boardId) : null
}

function navigateCard(cardId) {
	selectedBoardId.value = null
	selectedCardId.value = String(cardId)
}

function closeCard() {
	selectedCardId.value = null
	selectedBoardId.value = null
	queryClient.invalidateQueries({ queryKey: ['project', String(props.id)] })
}

function goToStats() {
	router.push({ name: 'project-stats', params: { id: String(props.id) } })
}

// ── Card picker (cross-board search) ────────────────────────────────────────
// The picker UI + global readable search now live in the shared CardSearchPicker
// component (#3645); this view only owns the popover open state and the
// project-add mutation.
const showPicker = ref(false)
const addError = ref('')

const cardIdsInProject = computed(() => new Set((cardsData.value ?? []).map((c) => c.id)))

function togglePicker() {
	showPicker.value = !showPicker.value
	if (showPicker.value) {
		addError.value = ''
	}
}

function closePicker() {
	showPicker.value = false
}

async function handlePickCard(result) {
	if (cardIdsInProject.value.has(result.cardId)) return
	addError.value = ''
	try {
		await addCard.mutateAsync(result.cardId)
	} catch (err) {
		addError.value = err?.response?.data?.error || t('kanso', 'Failed to add card.')
	}
}

async function handleRemoveCard(cardId) {
	actionError.value = ''
	try {
		await removeCard.mutateAsync(cardId)
	} catch (err) {
		actionError.value = err?.response?.data?.error || t('kanso', 'Failed to remove card.')
	}
}

// ── Delete project ───────────────────────────────────────────────────────────
const actionError = ref('')

async function handleDelete() {
	actionError.value = ''
	try {
		await removeMutation.mutateAsync(Number(props.id))
		router.push({ name: 'projects' })
	} catch (err) {
		actionError.value = err?.response?.data?.error || t('kanso', 'Failed to delete project.')
	}
}

// ── In-place description edit (under the title) ──────────────────────────────
// Reuses the shipped markdown editor (#3562): the same useMarkdownToolbar +
// renderMarkdown + updateProject path as the dialog, but inline under the title
// so the description can be edited where it's read. No backend change.
const editingDescription = ref(false)
const descContentRef = ref(null)
const descExpanded = ref(false)
const descOverflows = ref(false)

// Keep in sync with the collapsed max-height in the styles below.
const COLLAPSED_DESC_PX = 240

function measureDescription() {
	descOverflows.value = (descContentRef.value?.scrollHeight ?? 0) > COLLAPSED_DESC_PX
}

let descObserver = null

watch(descContentRef, (el) => {
	descObserver?.disconnect()
	descObserver = null
	if (!el || typeof ResizeObserver === 'undefined') return
	descObserver = new ResizeObserver(() => measureDescription())
	descObserver.observe(el)
})

watch([() => project.value?.description, descExpanded], () => nextTick(measureDescription))

onBeforeUnmount(() => descObserver?.disconnect())
const draftDescription = ref('')
const descError = ref('')
const inlineDescRef = ref(null)
const showInlinePreview = ref(false)
// Set while cancelling so the blur fired by unmounting the textarea can't
// re-trigger a save — Escape must never persist. Decoupled from flush timing.
let suppressBlurSave = false

const descToolbar = useMarkdownToolbar({
	getText: () => draftDescription.value,
	setText: (v) => { draftDescription.value = v },
	textareaRef: inlineDescRef,
})

function startDescriptionEdit() {
	draftDescription.value = project.value?.description ?? ''
	descError.value = ''
	showInlinePreview.value = false
	suppressBlurSave = false
	editingDescription.value = true
	nextTick(() => inlineDescRef.value?.focus())
}

function cancelDescriptionEdit() {
	suppressBlurSave = true
	editingDescription.value = false
	descError.value = ''
}

async function saveDescription() {
	if (updateMutation.isPending.value) return
	const next = draftDescription.value.trim()
	// No change → just close without a round-trip.
	if (next === (project.value?.description ?? '').trim()) {
		editingDescription.value = false
		return
	}
	descError.value = ''
	try {
		await updateMutation.mutateAsync({
			id: Number(props.id),
			// Send the empty string (not undefined) to clear an existing description.
			description: next,
		})
		editingDescription.value = false
	} catch (err) {
		descError.value = err?.response?.data?.error || t('kanso', 'Failed to update description.')
	}
}

// Blur commits the edit, but only when focus actually leaves the editor region
// (not when a toolbar / Save / Cancel button inside it took focus — those also
// use @mousedown.prevent to keep the caret) and never when we're cancelling.
function saveDescriptionOnBlur(event) {
	if (suppressBlurSave || !editingDescription.value) return
	const next = event.relatedTarget
	if (next && next.closest && next.closest('.project-view__description')) return
	saveDescription()
}

function onInlineDescKeydown(event) {
	if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
		event.preventDefault()
		saveDescription()
		return
	}
	if (event.key === 'Escape') {
		event.stopPropagation()
		event.preventDefault()
		cancelDescriptionEdit()
	}
}

// ── Edit project dialog ──────────────────────────────────────────────────────
const showEditDialog = ref(false)
const editTitle = ref('')
const editDescription = ref('')
const editColor = ref('')
const editError = ref('')
const editTitleInputRef = ref(null)
const descTextareaRef = ref(null)
const showDescPreview = ref(false)

// Markdown formatting toolbar over the description textarea (mutates the markdown
// string in place; the preview reuses the same renderMarkdown as the read view).
const mdToolbar = useMarkdownToolbar({
	getText: () => editDescription.value,
	setText: (v) => { editDescription.value = v },
	textareaRef: descTextareaRef,
})

function openEditDialog() {
	editTitle.value = project.value?.title ?? ''
	editDescription.value = project.value?.description ?? ''
	editColor.value = project.value?.color ?? ''
	editError.value = ''
	showDescPreview.value = false
	showEditDialog.value = true
	nextTick(() => editTitleInputRef.value?.focus())
}

function closeEditDialog() {
	showEditDialog.value = false
}

async function submitEdit() {
	const title = editTitle.value.trim()
	if (!title || updateMutation.isPending.value) return
	editError.value = ''
	try {
		await updateMutation.mutateAsync({
			id: Number(props.id),
			title,
			description: editDescription.value.trim() || undefined,
			color: editColor.value || undefined,
		})
		closeEditDialog()
	} catch (err) {
		editError.value = err?.response?.data?.error || t('kanso', 'Failed to update project.')
	}
}

// ── Discussion log (owner-only project comments, #3563) ──────────────────────
// Reuses the shipped markdown editor (useMarkdownToolbar + renderMarkdown) and
// mirrors the card comment thread pattern: one-level threading, edit/delete.
const {
	comments: commentsQuery,
	addComment,
	editComment,
	deleteComment,
} = useProjectComments(computed(() => props.id))

const commentTree = computed(() => buildCommentTree(commentsQuery.data.value ?? []))
const commentTotal = computed(() => (commentsQuery.data.value ?? []).length)

const newCommentBody = ref('')
const newCommentRef = ref(null)
const commentError = ref('')

const commentToolbar = useMarkdownToolbar({
	getText: () => newCommentBody.value,
	setText: (v) => { newCommentBody.value = v },
	textareaRef: newCommentRef,
})

function formatCommentTime(unixSeconds) {
	try {
		return new Date(unixSeconds * 1000).toLocaleString(undefined, {
			month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
		})
	} catch {
		return ''
	}
}

async function submitNewComment() {
	const body = newCommentBody.value.trim()
	if (!body || addComment.isPending.value) return
	commentError.value = ''
	try {
		await addComment.mutateAsync({ body, parentCommentId: null })
		// Only clear after a successful post so a failed post keeps the text (#3510).
		newCommentBody.value = ''
	} catch (err) {
		commentError.value = err?.response?.data?.error || t('kanso', 'Failed to post note.')
	}
}

// ── Reply ────────────────────────────────────────────────────────────────────
const replyingToId = ref(null)
const replyBody = ref('')

function openReplyBox(parentId) {
	replyingToId.value = parentId
	replyBody.value = ''
}

function closeReplyBox() {
	replyingToId.value = null
	replyBody.value = ''
}

async function submitReply(parentId) {
	const body = replyBody.value.trim()
	if (!body || addComment.isPending.value) return
	commentError.value = ''
	try {
		await addComment.mutateAsync({ body, parentCommentId: parentId })
		closeReplyBox()
	} catch (err) {
		commentError.value = err?.response?.data?.error || t('kanso', 'Failed to post reply.')
	}
}

// ── Edit ─────────────────────────────────────────────────────────────────────
const editingCommentId = ref(null)
const editingCommentBody = ref('')

function startCommentEdit(comment) {
	editingCommentId.value = comment.id
	editingCommentBody.value = comment.body
}

function cancelCommentEdit() {
	editingCommentId.value = null
	editingCommentBody.value = ''
}

async function saveCommentEdit(comment) {
	const body = editingCommentBody.value.trim()
	if (!body || editComment.isPending.value || body === comment.body) {
		cancelCommentEdit()
		return
	}
	commentError.value = ''
	try {
		await editComment.mutateAsync({ comment, body })
		cancelCommentEdit()
	} catch (err) {
		commentError.value = err?.response?.data?.error || t('kanso', 'Failed to update note.')
	}
}

// ── Delete ───────────────────────────────────────────────────────────────────
async function handleDeleteComment(comment) {
	commentError.value = ''
	try {
		await deleteComment.mutateAsync({ comment })
	} catch (err) {
		commentError.value = err?.response?.data?.error || t('kanso', 'Failed to delete note.')
	}
}
</script>

<style scoped>
.project-view {
	padding: 20px 32px 32px 52px;
	max-width: 1600px;
	box-sizing: border-box;
}

.project-view__plan {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
	gap: 24px;
	align-items: stretch;
	margin-bottom: 28px;
}

.project-view__plan .health {
	margin-bottom: 0;
}

.project-view__plan .project-view__burnup {
	margin: 0;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

@media (max-width: 1100px) {
	.project-view__plan {
		grid-template-columns: minmax(0, 1fr);
	}
}

/* Prose stays at a readable measure; media and code may use the full width. */
.project-view :deep(.kanso-md) > :not(img, table, pre, figure, p:has(> img)) {
	max-width: 90ch;
}

@media (max-width: 600px) {
	.project-view {
		padding: 16px;
	}
}

.project-view__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 20px;
	gap: 12px;
}

.project-view__header-left {
	display: flex;
	align-items: center;
	gap: 10px;
	min-width: 0;
}

.project-view__back {
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	display: flex;
	align-items: center;
	border-radius: var(--border-radius);
	padding: 4px;
	transition: color 0.15s, background 0.15s;
	flex-shrink: 0;
}

.project-view__back:hover {
	color: var(--color-main-text);
	background: var(--color-background-hover);
}

.project-view__header-actions {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
}

.project-view__analytics-btn {
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: var(--border-radius);
	padding: 6px;
	transition: color 0.15s, background 0.15s;
}

.project-view__analytics-btn:hover {
	color: var(--color-main-text);
	background: var(--color-background-hover);
}

.project-view__dot {
	flex-shrink: 0;
	width: 14px;
	height: 14px;
	border-radius: 50%;
	border: 1px solid var(--color-border-dark);
}

.project-view__title {
	font-size: 1.5rem;
	font-weight: 600;
	margin: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* ── Prominent, in-place editable description ──────────────────────────────── */
.project-view__description {
	margin: -4px 0 24px;
}

/* Read mode: comfortable typography + reading width. Long, multi-paragraph
   markdown scrolls inside a capped region instead of being clipped mid-block. */
.project-view__desc-view {
	position: relative;
	cursor: text;
	border-radius: var(--border-radius-large, 8px);
	padding: 8px 10px;
	margin: 0 -10px;
	transition: background 0.15s;
}

.project-view__desc-view--collapsed {
	max-height: 240px;
	overflow: hidden;
}

.project-view__desc-view--overflowing::after {
	content: '';
	position: absolute;
	inset: auto 0 0;
	height: 64px;
	background: linear-gradient(to bottom, transparent, var(--color-main-background));
	pointer-events: none;
}

.project-view__desc-toggle {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	min-height: 32px;
	margin: 4px 0 0;
	padding: 2px 10px 2px 4px;
	border: none;
	border-radius: var(--border-radius);
	background: none;
	color: var(--color-primary-element);
	font-weight: 600;
	cursor: pointer;
}

.project-view__desc-toggle:hover {
	background: var(--color-background-hover);
}

.project-view__desc-toggle-icon--up {
	transform: rotate(180deg);
}

.project-view__desc-view:hover,
.project-view__desc-view:focus-visible {
	background: var(--color-background-hover);
	outline: none;
}

.project-view__desc-rendered {
	font-size: 0.9375rem;
	line-height: 1.65;
	color: var(--color-main-text);
	/* Long unbroken tokens (URLs) wrap instead of forcing horizontal scroll. */
	overflow-wrap: anywhere;
}

.project-view__desc-rendered :deep(p) { margin: 0 0 0.7em; }
.project-view__desc-rendered :deep(p:last-child) { margin-bottom: 0; }
.project-view__desc-rendered :deep(h1),
.project-view__desc-rendered :deep(h2),
.project-view__desc-rendered :deep(h3) {
	margin: 1em 0 0.4em;
	line-height: 1.3;
}
.project-view__desc-rendered :deep(h1:first-child),
.project-view__desc-rendered :deep(h2:first-child),
.project-view__desc-rendered :deep(h3:first-child) { margin-top: 0; }
/* Indent + markers come from the shared `.kanso-md` rules (src/styles/markdown.css). */
.project-view__desc-rendered :deep(ul),
.project-view__desc-rendered :deep(ol) { margin: 0 0 0.7em; }
.project-view__desc-rendered :deep(li) { margin: 0.15em 0; }
.project-view__desc-rendered :deep(blockquote) {
	margin: 0 0 0.7em;
	padding-left: 12px;
	border-left: 3px solid var(--color-border-dark);
	color: var(--color-text-maxcontrast);
}
.project-view__desc-rendered :deep(a) { color: var(--color-primary-element); }

.project-view__desc-placeholder {
	display: inline-flex;
	align-items: center;
	background: none;
	border: none;
	padding: 4px 0;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	font-size: 0.9375rem;
	font-style: italic;
}

.project-view__desc-placeholder:hover {
	color: var(--color-main-text);
	text-decoration: underline;
}

.project-view__desc-textarea {
	width: 100%;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.9375rem;
	font-family: inherit;
	line-height: 1.6;
	resize: vertical;
	min-height: 140px;
}

.project-view__desc-textarea:focus {
	outline: none;
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.project-view__desc-actions {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 8px;
	flex-wrap: wrap;
}

.project-view__desc-hint {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.project-view__desc-error {
	color: var(--color-error-text);
	font-size: 0.85rem;
}

.project-view__loading {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--color-text-maxcontrast);
	padding: 32px 0;
}

.project-view__spinner {
	display: inline-block;
	width: 20px;
	height: 20px;
	border: 2px solid var(--color-border);
	border-top-color: var(--color-primary-element);
	border-radius: 50%;
	animation: project-spin 0.7s linear infinite;
}

@keyframes project-spin {
	to { transform: rotate(360deg); }
}

.project-view__error {
	color: var(--color-error-text);
	padding: 16px 0;
}

.project-view__action-error {
	display: block;
	color: var(--color-error-text);
	font-size: 0.85rem;
	margin-bottom: 12px;
}

.project-view__add-row {
	position: relative;
	margin-bottom: 20px;
}

.project-view__add-btn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px;
	border: 1px dashed var(--color-border-dark);
	border-radius: var(--border-radius-large, 8px);
	background: none;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	transition: color 0.15s, border-color 0.15s, background 0.15s;
}

.project-view__add-btn:hover {
	color: var(--color-main-text);
	border-color: var(--color-primary-element);
	background: var(--color-background-hover);
}

/* The popover shell around the shared CardSearchPicker (its inner list/input
   styles live in the component). This class is applied to the component root, so
   the scoped rule still reaches it. */
.project-view__picker {
	position: absolute;
	top: calc(100% + 6px);
	left: 0;
	z-index: 100;
	width: 420px;
	max-width: calc(100vw - 48px);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	box-shadow: 0 4px 24px rgba(0, 0, 0, 0.12);
	padding: 8px;
}

.project-view__section {
	margin-bottom: 32px;
}

.project-view__section-title {
	font-size: 0.85rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	color: var(--color-text-maxcontrast);
	margin: 0 0 10px;
}

.project-view__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.project-view__row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 14px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
	cursor: pointer;
	transition: background 0.15s;
}

.project-view__row:hover,
.project-view__row:focus-visible {
	background: var(--color-border-dark);
	outline: none;
}

.project-view__row-main {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1;
	min-width: 0;
}

.project-view__card-title {
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.project-view__meta {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

/* The card's board reference (e.g. KANSO-359) - small, muted, monospace so it reads as an id */
.project-view__ref {
	font-size: 0.68rem;
	font-weight: 600;
	letter-spacing: 0.02em;
	font-family: var(--font-face-monospace, monospace);
}

.project-view__due {
	flex-shrink: 0;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.project-view__due--overdue {
	color: var(--kanso-error-legible);
	font-weight: 600;
}

.project-view__row--done .project-view__card-title {
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
}

.project-view__badge {
	flex-shrink: 0;
	display: inline-flex;
	align-items: center;
	padding: 1px 6px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.project-view__badge--warning {
	color: var(--kanso-warning-legible);
	background: var(--kanso-warning-tint);
}

.project-view__count {
	margin-left: 4px;
	font-weight: 400;
}

details.project-view__section > summary {
	cursor: pointer;
}

.project-view__burnup {
	margin-bottom: 24px;
}

.project-view__toolbar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 16px;
}

.project-view__toolbar .project-view__add-row {
	margin: 0 0 0 auto;
}

.project-view__toolbar .project-view__picker {
	left: auto;
	right: 0;
}

.project-view__modes {
	display: inline-flex;
	gap: 2px;
	padding: 2px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
}

.project-view__mode {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	min-height: 32px;
	margin: 0;
	padding: 4px 12px;
	border: none;
	border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	font-weight: 500;
}

.project-view__mode--active {
	background: var(--color-main-background);
	color: var(--color-main-text);
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
}

.project-view .project-view__timeline {
	margin-bottom: 32px;
	padding: 0;
	overflow: visible;
}

/* Fit the rows: a short project doesn't sit in an empty frame, a long one
   scrolls inside the chart instead of stretching the page. */
.project-view .project-view__timeline :deep(.timeline__body) {
	flex: none;
	min-height: 200px;
	max-height: min(72vh, 960px);
}

.project-view__form {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 4px 0;
}

.project-view__form-label {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}

.project-view__required {
	color: var(--color-error-text);
	margin-left: 2px;
}

.project-view__form-input {
	width: 100%;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.9rem;
}

.project-view__form-input:focus {
	outline: none;
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.project-view__color-grid {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: 2px;
}

.project-view__color-swatch {
	width: 24px;
	height: 24px;
	border-radius: 50%;
	border: 2px solid transparent;
	cursor: pointer;
	transition: border-color 0.1s, transform 0.1s;
}

.project-view__color-swatch:hover {
	transform: scale(1.15);
}

.project-view__color-swatch--active {
	border-color: var(--color-main-text);
}

.project-view__color-swatch--clear {
	background: var(--color-background-hover);
	border-color: var(--color-border);
	font-size: 14px;
	line-height: 1;
	color: var(--color-text-maxcontrast);
	display: flex;
	align-items: center;
	justify-content: center;
}

.project-view__form-error {
	color: var(--color-error-text);
	font-size: 0.85rem;
}

.project-view__form-textarea {
	resize: vertical;
	min-height: 120px;
	line-height: 1.6;
	font-family: inherit;
}

.project-view__md-toolbar {
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

.project-view__md-toolbar-spacer { flex: 1 1 auto; }

.project-view__md-sep {
	width: 1px;
	align-self: stretch;
	margin: 2px 4px;
	background: var(--color-border);
}

.project-view__md-btn {
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

.project-view__md-btn:hover { background: var(--color-background-dark); }

.project-view__md-btn--active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.project-view__desc-preview {
	margin: 8px 0 0;
	padding: 10px 14px;
	border: 1px dashed var(--color-border);
	border-radius: 10px;
	background: var(--color-main-background);
}

.project-view__desc-preview-label {
	display: block;
	margin-bottom: 4px;
	font-size: 0.7rem;
	font-weight: 700;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}
/* .project-view__desc-rendered typography is defined once, up in the
   description block, and shared by the read view + both previews. */

/* ── Discussion log ─────────────────────────────────────────────────────────── */
.project-view__discussion {
	margin-top: 8px;
	padding-top: 20px;
	border-top: 1px solid var(--color-border);
}

.project-view__composer {
	margin-bottom: 20px;
}

.project-view__comment-textarea {
	width: 100%;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.9375rem;
	font-family: inherit;
	line-height: 1.6;
	resize: vertical;
}

.project-view__comment-textarea:focus {
	outline: none;
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.project-view__comment-actions,
.project-view__comment-edit-actions {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 8px;
	flex-wrap: wrap;
}

.project-view__thread {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.project-view__comment-group {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.project-view__comment {
	display: flex;
	gap: 10px;
	padding: 10px 12px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
}

.project-view__comment--reply {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
}

.project-view__replies {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-left: 24px;
}

.project-view__reply-compose {
	margin-left: 24px;
}

.project-view__comment-main {
	flex: 1;
	min-width: 0;
}

.project-view__comment-meta {
	display: flex;
	align-items: baseline;
	gap: 8px;
	margin-bottom: 4px;
	flex-wrap: wrap;
}

.project-view__comment-author {
	font-weight: 600;
	font-size: 0.875rem;
}

.project-view__comment-time,
.project-view__comment-edited {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.project-view__comment-edited {
	font-style: italic;
}

.project-view__comment-body {
	font-size: 0.9375rem;
}

.project-view__comment-controls {
	display: flex;
	align-items: center;
	gap: 4px;
	margin-top: 6px;
}

.project-view__comment-link-btn {
	background: none;
	border: none;
	padding: 2px 4px;
	cursor: pointer;
	color: var(--color-primary-element);
	font-size: 0.8rem;
}

.project-view__comment-link-btn:hover {
	text-decoration: underline;
}

.project-view__comment-icon-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	border: none;
	border-radius: 6px;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}

.project-view__comment-icon-btn:hover {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.project-view__comment-icon-btn--danger:hover {
	color: var(--color-error-text);
}

.project-view__discussion-empty {
	padding: 12px 0;
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
