<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<Teleport to="#content-vue">
		<aside
			class="bs-modal"
			role="dialog"
			aria-modal="true"
			:aria-label="t('kanso', 'Board settings')">

			<!-- Header -->
			<header class="bs-modal__header">
				<div class="bs-modal__heading">
					<span class="bs-modal__title">{{ t('kanso', 'Board settings') }}</span>
					<span v-if="boardSubtitle" class="bs-modal__subtitle">{{ boardSubtitle }}</span>
				</div>
				<button
					class="bs-modal__close"
					:aria-label="t('kanso', 'Close')"
					@click="$emit('close')">
					<CloseIcon :size="20" />
				</button>
			</header>

			<div class="bs-modal__body">
				<!-- Vertical section rail -->
				<nav class="bs-rail" :aria-label="t('kanso', 'Board settings sections')">
					<!-- The tablist holds ONLY tabs; the danger group is a sibling so it
					     does not pollute the tablist's child structure (WAI-ARIA). -->
					<div
						class="bs-rail__tabs"
						role="tablist"
						aria-orientation="vertical"
						@keydown="onRailKeydown">
						<button
							v-for="section in railSections"
							:id="`bs-rail-tab-${section.id}`"
							:key="section.id"
							class="bs-rail__item"
							:class="{ 'bs-rail__item--active': activeTab === section.id }"
							type="button"
							role="tab"
							:aria-selected="activeTab === section.id ? 'true' : 'false'"
							:aria-controls="`bs-pane-${section.id}`"
							:tabindex="activeTab === section.id ? 0 : -1"
							@click="activeTab = section.id">
							<component :is="section.icon" :size="16" class="bs-rail__icon" />
							<span class="bs-rail__label">{{ section.name }}</span>
						</button>
					</div>

				</nav>

				<!-- Pane area -->
				<div class="bs-panes">
					<!-- Delete-board confirm -->
					<div v-if="showDeleteBoardConfirm" class="bs-delete-confirm" role="alertdialog">
						<p class="bs-delete-confirm__title">
							{{ t('kanso', 'Delete this board permanently?') }}
						</p>
						<p class="bs-delete-confirm__hint">
							{{ t('kanso', 'This removes the board and all of its cards for everyone. This cannot be undone.') }}
						</p>
						<div class="bs-delete-confirm__actions">
							<NcButton type="error" :disabled="isDeletingBoard" @click="doDeleteBoard">
								{{ isDeletingBoard ? t('kanso', 'Deleting…') : t('kanso', 'Delete board') }}
							</NcButton>
							<NcButton :disabled="isDeletingBoard" @click="showDeleteBoardConfirm = false">
								{{ t('kanso', 'Cancel') }}
							</NcButton>
						</div>
						<span v-if="deleteBoardError" class="label-settings__error">{{ deleteBoardError }}</span>
					</div>

					<section
						v-show="activeTab === 'general'"
						id="bs-pane-general"
						class="bs-pane"
						role="tabpanel"
						aria-labelledby="bs-rail-tab-general">
					<div class="board-settings__general">
							<template v-if="canManage">
								<label class="board-settings__prefix-label" for="bs-board-name">
									{{ t('kanso', 'Board name') }}
								</label>
								<div class="board-settings__prefix-row">
									<input
										id="bs-board-name"
										v-model="nameDraft"
										class="board-settings__prefix-input board-settings__name-input"
										type="text"
										maxlength="100"
										:disabled="nameSaving"
										:placeholder="t('kanso', 'Board name')"
										@keydown.enter.prevent="saveName">
									<NcButton
										:disabled="nameSaving || !nameDirty"
										@click="saveName">
										{{ t('kanso', 'Save') }}
									</NcButton>
								</div>
								<span v-if="nameError" class="label-settings__error">{{ nameError }}</span>
							</template>

						<NcCheckboxRadioSwitch
							:model-value="isDefaultBoard"
							:disabled="settingsBusy"
							@update:model-value="setDefaultBoard">
							{{ t('kanso', 'Open this board when Kanso starts') }}
						</NcCheckboxRadioSwitch>
						<p class="board-settings__general-hint">
							{{ t('kanso', 'Kanso opens the board list by default. Turn this on to open this board instead.') }}
						</p>
						<NcCheckboxRadioSwitch
							:model-value="inMyCalendar"
							:disabled="calendarSyncBusy"
							@update:model-value="setInMyCalendar">
							{{ t('kanso', 'Show this board in my calendar') }}
						</NcCheckboxRadioSwitch>
						<p class="board-settings__general-hint">
							{{ t('kanso', 'Cards with a due date sync to your calendar and phone (via CalDAV/DAVx5) as tasks. Turn this off to keep this board out of your own calendar. Only affects you.') }}
						</p>
						<NcCheckboxRadioSwitch
							v-if="canManage"
							:model-value="newCardsOnTop"
							:disabled="newCardsOnTopSaving"
							@update:model-value="onNewCardsOnTopChange">
							{{ t('kanso', 'Add new cards to the top of a column') }}
						</NcCheckboxRadioSwitch>
						<p v-if="canManage" class="board-settings__general-hint">
							{{ t('kanso', 'New cards are added to the bottom by default. Turn this on to add them at the top instead.') }}
						</p>
						<span v-if="newCardsOnTopError" class="label-settings__error">{{ newCardsOnTopError }}</span>

						<template v-if="canManage">
							<label class="board-settings__prefix-label" for="bs-card-prefix">
								{{ t('kanso', 'Card ID prefix') }}
							</label>
							<div class="board-settings__prefix-row">
								<input
									id="bs-card-prefix"
									v-model="prefixDraft"
									class="board-settings__prefix-input"
									type="text"
									maxlength="5"
									:disabled="prefixSaving"
									:placeholder="t('kanso', 'e.g. KAN')"
									@keydown.enter.prevent="savePrefix">
								<NcButton
									:disabled="prefixSaving || !prefixDirty"
									@click="savePrefix">
									{{ t('kanso', 'Save') }}
								</NcButton>
							</div>
							<p class="board-settings__general-hint">
								{{ t('kanso', 'Cards get a readable reference like {example}. Changing the prefix only changes how existing cards are displayed; their numbers stay the same.', { example: (boardPrefix || 'KAN') + '-123' }) }}
							</p>
							<span v-if="prefixError" class="label-settings__error">{{ prefixError }}</span>
						</template>

						<!-- Project chat link (#3748): a plain URL (typically a Talk room)
						     surfaced as a toolbar button for every member. MANAGE only;
						     http/https only (validated here and server-side); empty clears
						     the link and hides the button. Deliberately dumb - no Talk API. -->
						<template v-if="canManage">
							<label class="board-settings__prefix-label" for="bs-chat-url">
								{{ t('kanso', 'Project chat link') }}
							</label>
							<div class="board-settings__prefix-row">
								<input
									id="bs-chat-url"
									v-model="chatUrlDraft"
									class="board-settings__chat-url-input"
									type="url"
									:disabled="chatUrlSaving"
									:placeholder="t('kanso', 'https://cloud.example.com/call/abc123')"
									data-test="board-chat-url-input"
									@keydown.enter.prevent="saveChatUrl">
								<NcButton
									:disabled="chatUrlSaving || !chatUrlDirty"
									data-test="board-chat-url-save"
									@click="saveChatUrl">
									{{ t('kanso', 'Save') }}
								</NcButton>
							</div>
							<p class="board-settings__general-hint">
								{{ t('kanso', 'Shown to everyone on this board as a "Project chat" button in the toolbar — typically a Nextcloud Talk room. Leave empty to remove the button.') }}
							</p>
							<span v-if="chatUrlError" class="label-settings__error" data-test="board-chat-url-error">{{ chatUrlError }}</span>
						</template>

						<!-- Board background (#3528): a curated preset gradient rendered
						     behind the board view. MANAGE only; presets only (no free-form
						     CSS / image upload). -->
						<template v-if="canManage">
							<label class="board-settings__bg-label">
								{{ t('kanso', 'Board background') }}
							</label>
							<div
								class="board-settings__bg-grid"
								role="group"
								:aria-label="t('kanso', 'Board background')">
								<button
									type="button"
									class="board-settings__bg-option board-settings__bg-option--none"
									:class="{ 'board-settings__bg-option--active': !boardBackground }"
									:title="t('kanso', 'No background')"
									:aria-pressed="!boardBackground"
									:disabled="backgroundSaving"
									data-test="board-bg-none"
									@click="applyBackground('')">
									<span class="board-settings__bg-none-icon">✕</span>
								</button>
								<button
									v-for="preset in BACKGROUND_PRESETS"
									:key="preset.key"
									type="button"
									class="board-settings__bg-option"
									:style="{ background: preset.css }"
									:class="{ 'board-settings__bg-option--active': boardBackground === preset.key }"
									:title="preset.label"
									:aria-pressed="boardBackground === preset.key"
									:disabled="backgroundSaving"
									:data-test="`board-bg-${preset.key}`"
									@click="applyBackground(preset.key)" />
							</div>
							<span v-if="backgroundError" class="label-settings__error">{{ backgroundError }}</span>
						</template>

						<!-- Board actions: Export / Duplicate are internal-only
						     (#3744) - hidden for external members and 403'd by the
						     server regardless; Archive / Delete are MANAGE-only
						     (canManage). -->
						<div v-if="isInternal || canManage" class="board-actions">
							<h4 class="board-actions__heading">{{ t('kanso', 'Board actions') }}</h4>

							<template v-if="isInternal">
							<!-- Export -->
							<div class="board-actions__row">
								<div class="board-actions__text">
									<span class="board-actions__label">{{ t('kanso', 'Export board') }}</span>
									<span class="board-actions__hint">{{ t('kanso', 'Download a JSON backup of this board.') }}</span>
								</div>
								<NcButton
									:disabled="exporting"
									data-test="board-export"
									@click="exportBoardToFile">
									<template #icon>
										<DownloadIcon :size="20" />
									</template>
									{{ exporting ? t('kanso', 'Exporting…') : t('kanso', 'Export') }}
								</NcButton>
							</div>
							<span v-if="exportError" class="label-settings__error">{{ exportError }}</span>

							<!-- Duplicate -->
							<div class="board-actions__row">
								<div class="board-actions__text">
									<span class="board-actions__label">{{ t('kanso', 'Duplicate board') }}</span>
									<span class="board-actions__hint">{{ t('kanso', 'Create a new board that you own from this one.') }}</span>
									<label class="board-actions__check">
										<input
											type="checkbox"
											v-model="duplicateWithCards"
											:disabled="duplicating"
											data-test="board-duplicate-with-cards">
										{{ t('kanso', 'Copy cards too') }}
									</label>
								</div>
								<NcButton
									:disabled="duplicating"
									data-test="board-duplicate"
									@click="duplicateBoardNow">
									<template #icon>
										<ContentCopyIcon :size="20" />
									</template>
									{{ duplicating ? t('kanso', 'Duplicating…') : t('kanso', 'Duplicate') }}
								</NcButton>
							</div>
							<span v-if="duplicateError" class="label-settings__error">{{ duplicateError }}</span>
							</template>

							<!-- Danger zone: destructive actions, MANAGE only. -->
							<div v-if="canManage" class="board-actions__danger">
								<h4 class="board-actions__danger-heading">{{ t('kanso', 'Danger zone') }}</h4>

								<div class="board-actions__row">
									<div class="board-actions__text">
										<span class="board-actions__label">{{ t('kanso', 'Archive board') }}</span>
										<span class="board-actions__hint">{{ t('kanso', 'Hide this board without deleting it. You can restore it later.') }}</span>
									</div>
									<NcButton :disabled="archiving" @click="archiveBoard">
										<template #icon>
											<ArchiveArrowDownIcon :size="20" />
										</template>
										{{ t('kanso', 'Archive') }}
									</NcButton>
								</div>

								<div class="board-actions__row">
									<div class="board-actions__text">
										<span class="board-actions__label board-actions__label--delete">{{ t('kanso', 'Delete board') }}</span>
										<span class="board-actions__hint">{{ t('kanso', 'Permanently remove this board and all of its cards for everyone. This cannot be undone.') }}</span>
									</div>
									<NcButton type="error" :disabled="isDeletingBoard" @click="onDeleteBoardClick">
										<template #icon>
											<DeleteIcon :size="20" />
										</template>
										{{ t('kanso', 'Delete') }}
									</NcButton>
								</div>
							</div>
						</div>
					</div>
					</section>

				<section
					v-show="activeTab === 'labels'"
					id="bs-pane-labels"
					class="bs-pane"
					role="tabpanel"
					aria-labelledby="bs-rail-tab-labels">
				<ul class="label-settings__list" role="list">
					<li v-if="labels.length === 0" class="label-settings__empty">
						{{ t('kanso', 'No labels yet. Create one below.') }}
					</li>

					<li
						v-for="label in labels"
						:key="label.id"
						class="label-settings__item">
						<!-- Color swatch / picker trigger -->
						<button
							class="label-settings__swatch"
							:style="label.color ? { background: cssColor(label.color) } : {}"
							:class="{ 'label-settings__swatch--no-color': !label.color }"
							:title="t('kanso', 'Change color')"
							:aria-label="t('kanso', 'Change color of label {title}', { title: label.title })"
							:disabled="!canManage"
							@click="openColorPicker(label)"
							@keydown.escape="onColorPickerEscape">
							<span v-if="!label.color" class="label-settings__swatch-icon">?</span>
						</button>

						<!-- Color picker popover for this label -->
						<div
							v-if="colorPickerFor === label.id"
							class="label-settings__color-popover"
							role="dialog"
							:aria-label="t('kanso', 'Pick a color')"
							@keydown.escape="onColorPickerEscape">
							<div class="label-settings__color-grid">
								<button
									v-for="preset in COLOR_PRESETS"
									:key="preset"
									class="label-settings__color-option"
									:style="{ background: cssColor(preset) }"
									:class="{ 'label-settings__color-option--active': label.color === preset }"
									:title="preset"
									:aria-pressed="label.color === preset"
									@click="applyColor(label, preset)" />
								<button
									class="label-settings__color-option label-settings__color-option--clear"
									:title="t('kanso', 'No color')"
									:aria-pressed="!label.color"
									@click="applyColor(label, '')">
									×
								</button>
							</div>
						</div>

						<!-- Inline rename input -->
						<template v-if="editingLabelId === label.id">
							<input
								:ref="(el) => setEditRef(label.id, el)"
								v-model="editingTitle"
								class="label-settings__rename-input"
								type="text"
								:aria-label="t('kanso', 'Rename label')"
								@keydown.enter.prevent="saveRename(label)"
								@keydown.escape="cancelRename"
								@blur="saveRename(label)" />
						</template>
						<template v-else>
							<span
								class="label-settings__name"
								:class="{ 'label-settings__name--readonly': !canManage }"
								@click="canManage && startRename(label)">
								{{ label.title }}
							</span>
						</template>

						<!-- Actions (only when canManage) -->
						<div v-if="canManage" class="label-settings__actions">
							<button
								class="label-settings__action-btn"
								:title="t('kanso', 'Rename')"
								:aria-label="t('kanso', 'Rename label {title}', { title: label.title })"
								@click="startRename(label)">
								<PencilIcon :size="14" />
							</button>
							<button
								class="label-settings__action-btn label-settings__action-btn--danger"
								:title="t('kanso', 'Delete')"
								:aria-label="t('kanso', 'Delete label {title}', { title: label.title })"
								:disabled="confirmDeleteLabelId === label.id && isDeletingLabel"
								@click="confirmDeleteLabel(label)">
								<DeleteIcon :size="14" />
							</button>
						</div>

						<!-- Inline delete confirm -->
						<div v-if="confirmDeleteLabelId === label.id" class="label-settings__confirm">
							<span>{{ t('kanso', 'Delete "{title}"?', { title: label.title }) }}</span>
							<button class="label-settings__confirm-yes" :disabled="isDeletingLabel" @click="doDeleteLabel(label)">
								{{ t('kanso', 'Delete') }}
							</button>
							<button class="label-settings__confirm-no" @click="confirmDeleteLabelId = null">
								{{ t('kanso', 'Cancel') }}
							</button>
							<span v-if="deleteLabelError" class="label-settings__error">{{ deleteLabelError }}</span>
						</div>

						<!-- Rename/color error -->
						<span v-if="labelError[label.id]" class="label-settings__error">
							{{ labelError[label.id] }}
						</span>
					</li>
				</ul>

				<!-- Create new label form (only when canManage) -->
				<form v-if="canManage" class="label-settings__create" @submit.prevent="submitCreate">
					<h4 class="label-settings__create-heading">{{ t('kanso', 'Add label') }}</h4>
					<div class="label-settings__create-row">
						<button
							type="button"
							class="label-settings__swatch"
							:style="newColor ? { background: cssColor(newColor) } : {}"
							:class="{ 'label-settings__swatch--no-color': !newColor }"
							:title="t('kanso', 'Pick color')"
							:aria-label="t('kanso', 'Pick color for new label')"
							@click="showNewColorPicker = !showNewColorPicker"
							@keydown.escape="onColorPickerEscape">
							<span v-if="!newColor" class="label-settings__swatch-icon">+</span>
						</button>

						<div
							v-if="showNewColorPicker"
							class="label-settings__color-popover label-settings__color-popover--create"
							role="dialog"
							:aria-label="t('kanso', 'Pick a color')"
							@keydown.escape="onColorPickerEscape">
							<div class="label-settings__color-grid">
								<button
									v-for="preset in COLOR_PRESETS"
									:key="preset"
									type="button"
									class="label-settings__color-option"
									:style="{ background: cssColor(preset) }"
									:class="{ 'label-settings__color-option--active': newColor === preset }"
									:title="preset"
									:aria-pressed="newColor === preset"
									@click="newColor = preset; showNewColorPicker = false" />
								<button
									type="button"
									class="label-settings__color-option label-settings__color-option--clear"
									:title="t('kanso', 'No color')"
									:aria-pressed="!newColor"
									@click="newColor = ''; showNewColorPicker = false">
									×
								</button>
							</div>
						</div>

						<input
							v-model="newTitle"
							class="label-settings__create-input"
							type="text"
							:placeholder="t('kanso', 'Label name…')"
							:disabled="isCreating"
							:aria-label="t('kanso', 'New label name')"
							@keydown.enter.prevent="submitCreate" />
						<button
							class="label-settings__create-btn"
							type="submit"
							:disabled="!newTitle.trim() || isCreating"
							:aria-label="t('kanso', 'Create label')">
							{{ t('kanso', 'Add') }}
						</button>
					</div>
					<span v-if="createError" class="label-settings__error">{{ createError }}</span>
				</form>
				</section>

				<section
					v-show="activeTab === 'review-types'"
					id="bs-pane-review-types"
					class="bs-pane"
					role="tabpanel"
					aria-labelledby="bs-rail-tab-review-types">
					<div class="rt-settings__intro">
						<p>
							{{ t('kanso', 'Review types label the kind of sign-off a card needs — for example Code, QA, or Security. Request one from the Reviews section of a card.') }}
						</p>
						<p>
							{{ t('kanso', 'Stage turns them into a pipeline: a review is held — its reviewer is not notified and its chip shows greyed with a lock — until every lower-stage review on the card is approved. Types on the same stage run in parallel. Keep every stage at 0 to notify all reviewers at once.') }}
						</p>
					</div>
					<ul class="rt-settings__list" role="list">
					<li v-if="reviewTypes.length === 0" class="label-settings__empty">
						{{ t('kanso', 'No review types yet. Create one below.') }}
					</li>

					<li
						v-for="rt in reviewTypes"
						:key="rt.id"
						class="label-settings__item">
						<!-- Color swatch / picker trigger -->
						<button
							class="label-settings__swatch"
							:style="rt.color ? { background: cssColor(rt.color) } : {}"
							:class="{ 'label-settings__swatch--no-color': !rt.color }"
							:title="t('kanso', 'Change color')"
							:aria-label="t('kanso', 'Change color of review type {title}', { title: rt.title })"
							:disabled="!canManage"
							@click="openRtColorPicker(rt)"
							@keydown.escape="onColorPickerEscape">
							<span v-if="!rt.color" class="label-settings__swatch-icon">?</span>
						</button>

						<!-- Color picker popover for this review type -->
						<div
							v-if="rtColorPickerFor === rt.id"
							class="label-settings__color-popover"
							role="dialog"
							:aria-label="t('kanso', 'Pick a color')"
							@keydown.escape="onColorPickerEscape">
							<div class="label-settings__color-grid">
								<button
									v-for="preset in COLOR_PRESETS"
									:key="preset"
									class="label-settings__color-option"
									:style="{ background: cssColor(preset) }"
									:class="{ 'label-settings__color-option--active': rt.color === preset }"
									:title="preset"
									:aria-pressed="rt.color === preset"
									@click="applyRtColor(rt, preset)" />
								<button
									class="label-settings__color-option label-settings__color-option--clear"
									:title="t('kanso', 'No color')"
									:aria-pressed="!rt.color"
									@click="applyRtColor(rt, '')">
									×
								</button>
							</div>
						</div>

						<!-- Inline rename input -->
						<template v-if="editingRtId === rt.id">
							<input
								:ref="(el) => setRtEditRef(rt.id, el)"
								v-model="editingRtTitle"
								class="label-settings__rename-input"
								type="text"
								:aria-label="t('kanso', 'Rename review type')"
								@keydown.enter.prevent="saveRtRename(rt)"
								@keydown.escape="cancelRtRename"
								@blur="saveRtRename(rt)" />
						</template>
						<template v-else>
							<span
								class="label-settings__name"
								:class="{ 'label-settings__name--readonly': !canManage }"
								@click="canManage && startRtRename(rt)">
								{{ rt.title }}
							</span>
						</template>

						<!-- Stage: lower stages gate higher ones -->
						<label class="review-type-stage" :title="t('kanso', 'Stage — lower stages gate (defer) higher ones')">
							<span class="review-type-stage__label">{{ t('kanso', 'Stage') }}</span>
							<input
								class="review-type-stage__input"
								type="number"
								min="0"
								step="1"
								:value="rt.stage ?? 0"
								:disabled="!canManage"
								:aria-label="t('kanso', 'Stage of review type {title}', { title: rt.title })"
								@change="saveRtStage(rt, $event)" />
						</label>

						<!-- Actions (only when canManage) -->
						<div v-if="canManage" class="label-settings__actions">
							<button
								class="label-settings__action-btn"
								:title="t('kanso', 'Rename')"
								:aria-label="t('kanso', 'Rename review type {title}', { title: rt.title })"
								@click="startRtRename(rt)">
								<PencilIcon :size="14" />
							</button>
							<button
								class="label-settings__action-btn label-settings__action-btn--danger"
								:title="t('kanso', 'Delete')"
								:aria-label="t('kanso', 'Delete review type {title}', { title: rt.title })"
								:disabled="confirmDeleteRtId === rt.id && isDeletingRt"
								@click="confirmDeleteRt(rt)">
								<DeleteIcon :size="14" />
							</button>
						</div>

						<!-- Inline delete confirm -->
						<div v-if="confirmDeleteRtId === rt.id" class="label-settings__confirm">
							<span>{{ t('kanso', 'Delete "{title}"?', { title: rt.title }) }}</span>
							<button class="label-settings__confirm-yes" :disabled="isDeletingRt" @click="doDeleteRt(rt)">
								{{ t('kanso', 'Delete') }}
							</button>
							<button class="label-settings__confirm-no" @click="confirmDeleteRtId = null">
								{{ t('kanso', 'Cancel') }}
							</button>
							<span v-if="deleteRtError" class="label-settings__error">{{ deleteRtError }}</span>
						</div>

						<!-- Rename/color error -->
						<span v-if="rtError[rt.id]" class="label-settings__error">
							{{ rtError[rt.id] }}
						</span>
					</li>
				</ul>

				<!-- Create new review type form (only when canManage) -->
				<form v-if="canManage" class="label-settings__create" @submit.prevent="submitCreateRt">
					<h4 class="label-settings__create-heading">{{ t('kanso', 'Add review type') }}</h4>
					<div class="label-settings__create-row">
						<button
							type="button"
							class="label-settings__swatch"
							:style="newRtColor ? { background: cssColor(newRtColor) } : {}"
							:class="{ 'label-settings__swatch--no-color': !newRtColor }"
							:title="t('kanso', 'Pick color')"
							:aria-label="t('kanso', 'Pick color for new review type')"
							@click="showNewRtColorPicker = !showNewRtColorPicker">
							<span v-if="!newRtColor" class="label-settings__swatch-icon">+</span>
						</button>

						<div
							v-if="showNewRtColorPicker"
							class="label-settings__color-popover label-settings__color-popover--create"
							role="dialog"
							:aria-label="t('kanso', 'Pick a color')">
							<div class="label-settings__color-grid">
								<button
									v-for="preset in COLOR_PRESETS"
									:key="preset"
									type="button"
									class="label-settings__color-option"
									:style="{ background: cssColor(preset) }"
									:class="{ 'label-settings__color-option--active': newRtColor === preset }"
									:title="preset"
									:aria-pressed="newRtColor === preset"
									@click="newRtColor = preset; showNewRtColorPicker = false" />
								<button
									type="button"
									class="label-settings__color-option label-settings__color-option--clear"
									:title="t('kanso', 'No color')"
									:aria-pressed="!newRtColor"
									@click="newRtColor = ''; showNewRtColorPicker = false">
									×
								</button>
							</div>
						</div>

						<input
							v-model="newRtTitle"
							class="label-settings__create-input"
							type="text"
							:placeholder="t('kanso', 'Review type name…')"
							:disabled="isCreatingRt"
							:aria-label="t('kanso', 'New review type name')"
							@keydown.enter.prevent="submitCreateRt" />
						<label class="review-type-stage review-type-stage--create" :title="t('kanso', 'Stage — lower stages gate (defer) higher ones')">
							<span class="review-type-stage__label">{{ t('kanso', 'Stage') }}</span>
							<input
								v-model.number="newRtStage"
								class="review-type-stage__input"
								type="number"
								min="0"
								step="1"
								:disabled="isCreatingRt"
								:aria-label="t('kanso', 'Stage for new review type')" />
						</label>
						<button
							class="label-settings__create-btn"
							type="submit"
							:disabled="!newRtTitle.trim() || isCreatingRt"
							:aria-label="t('kanso', 'Create review type')">
							{{ t('kanso', 'Add') }}
						</button>
					</div>
					<span v-if="createRtError" class="label-settings__error">{{ createRtError }}</span>
				</form>
				</section>

				<section
					v-show="activeTab === 'card-fields'"
					id="bs-pane-card-fields"
					class="bs-pane"
					role="tabpanel"
					aria-labelledby="bs-rail-tab-card-fields">

				<ul class="rt-settings__list" role="list">
					<li v-if="cardFields.length === 0" class="label-settings__empty">
						{{ t('kanso', 'No custom fields yet. Create one below.') }}
					</li>

					<li
						v-for="field in cardFields"
						:key="field.id"
						class="label-settings__item cf-settings__item">

						<!-- Inline rename input -->
						<template v-if="editingCfId === field.id">
							<input
								:ref="(el) => setCfEditRef(field.id, el)"
								v-model="editingCfName"
								class="label-settings__rename-input"
								type="text"
								:aria-label="t('kanso', 'Rename field')"
								@keydown.enter.prevent="saveCfRename(field)"
								@keydown.escape="cancelCfRename"
								@blur="saveCfRename(field)" />
						</template>
						<template v-else>
							<span
								class="label-settings__name"
								:class="{ 'label-settings__name--readonly': !canManage }"
								@click="canManage && startCfRename(field)">
								{{ field.name }}
							</span>
						</template>

						<!-- Type badge -->
						<span class="cf-settings__type-badge">{{ field.type }}</span>

						<!-- Options preview for select fields -->
						<span v-if="field.type === 'select' && field.options && field.options.length > 0" class="cf-settings__options-preview">
							{{ field.options.join(', ') }}
						</span>

						<!-- Options editor for select fields (MANAGE only) -->
						<div v-if="field.type === 'select' && canManage && editingCfOptionsId === field.id" class="cf-settings__options-editor">
							<textarea
								v-model="editingCfOptions"
								class="cf-settings__options-textarea"
								:aria-label="t('kanso', 'Options (one per line or comma-separated)')"
								:placeholder="t('kanso', 'Option 1\nOption 2\nOption 3')"
								rows="4"
								@keydown.escape="editingCfOptionsId = null" />
							<div class="cf-settings__options-actions">
								<button
									class="label-settings__create-btn"
									:disabled="updateCardField.isPending.value"
									@click="saveCfOptions(field)">
									{{ t('kanso', 'Save options') }}
								</button>
								<button class="label-settings__confirm-no" @click="editingCfOptionsId = null">
									{{ t('kanso', 'Cancel') }}
								</button>
							</div>
						</div>

						<!-- Actions (only when canManage) -->
						<div v-if="canManage" class="label-settings__actions">
							<button
								class="label-settings__action-btn"
								:title="t('kanso', 'Rename')"
								:aria-label="t('kanso', 'Rename field {name}', { name: field.name })"
								@click="startCfRename(field)">
								<PencilIcon :size="14" />
							</button>
							<button
								v-if="field.type === 'select'"
								class="label-settings__action-btn"
								:title="t('kanso', 'Edit options')"
								:aria-label="t('kanso', 'Edit options for field {name}', { name: field.name })"
								@click="startCfOptionsEdit(field)">
								<ChevronDownIcon :size="14" />
							</button>
							<button
								class="label-settings__action-btn label-settings__action-btn--danger"
								:title="t('kanso', 'Delete')"
								:aria-label="t('kanso', 'Delete field {name}', { name: field.name })"
								:disabled="confirmDeleteCfId === field.id && isDeletingCf"
								@click="confirmDeleteCf(field)">
								<DeleteIcon :size="14" />
							</button>
						</div>

						<!-- Inline delete confirm -->
						<div v-if="confirmDeleteCfId === field.id" class="label-settings__confirm">
							<span>{{ t('kanso', 'Delete "{name}"?', { name: field.name }) }}</span>
							<button class="label-settings__confirm-yes" :disabled="isDeletingCf" @click="doDeleteCf(field)">
								{{ t('kanso', 'Delete') }}
							</button>
							<button class="label-settings__confirm-no" @click="confirmDeleteCfId = null">
								{{ t('kanso', 'Cancel') }}
							</button>
							<span v-if="deleteCfError" class="label-settings__error">{{ deleteCfError }}</span>
						</div>

						<!-- Rename error -->
						<span v-if="cfError[field.id]" class="label-settings__error">
							{{ cfError[field.id] }}
						</span>
					</li>
				</ul>

				<!-- Create new custom field form (only when canManage) -->
				<form
					v-if="canManage"
					class="label-settings__create cf-settings__create"
					data-test="cf-create-form"
					@submit.prevent="submitCreateCf">
					<h4 class="label-settings__create-heading">{{ t('kanso', 'Add custom field') }}</h4>
					<div class="label-settings__create-row cf-settings__create-row">
						<input
							v-model="newCfName"
							class="label-settings__create-input"
							type="text"
							:placeholder="t('kanso', 'Field name…')"
							:disabled="isCreatingCf"
							:aria-label="t('kanso', 'New custom field name')"
							data-test="cf-name-input"
							@keydown.enter.prevent="submitCreateCf" />
						<select
							v-model="newCfType"
							class="workflow__select cf-settings__type-select"
							:disabled="isCreatingCf"
							:aria-label="t('kanso', 'Field type')"
							data-test="cf-type-select">
							<option value="text">{{ t('kanso', 'Text') }}</option>
							<option value="number">{{ t('kanso', 'Number') }}</option>
							<option value="date">{{ t('kanso', 'Date') }}</option>
							<option value="select">{{ t('kanso', 'Select') }}</option>
						</select>
						<button
							class="label-settings__create-btn"
							type="submit"
							:disabled="!newCfName.trim() || isCreatingCf"
							:aria-label="t('kanso', 'Create custom field')">
							{{ t('kanso', 'Add') }}
						</button>
					</div>
					<!-- Options textarea: only shown for select type -->
					<div v-if="newCfType === 'select'" class="cf-settings__new-options">
						<label class="cf-settings__new-options-label" for="bs-cf-new-options">
							{{ t('kanso', 'Options (one per line or comma-separated)') }}
						</label>
						<textarea
							id="bs-cf-new-options"
							v-model="newCfOptionsRaw"
							class="cf-settings__options-textarea"
							:disabled="isCreatingCf"
							:placeholder="t('kanso', 'Option 1\nOption 2\nOption 3')"
							rows="4"
							data-test="cf-options-textarea" />
					</div>
					<span v-if="createCfError" class="label-settings__error">{{ createCfError }}</span>
				</form>
				</section>

				<section
					v-if="canShare || canManage"
					v-show="activeTab === 'sharing'"
					id="bs-pane-sharing"
					class="bs-pane"
					role="tabpanel"
					aria-labelledby="bs-rail-tab-sharing">
				<!-- Member sharing (ACL) needs the Share right; a manage-only member
				     still reaches this pane for the public link below. -->
				<template v-if="canShare">
				<!-- Sharee search -->
				<div class="sharing__search-wrap">
					<input
						v-model="searchQuery"
						class="sharing__search-input"
						type="search"
						:placeholder="t('kanso', 'Search users or groups…')"
						:aria-label="t('kanso', 'Search users or groups')" />
					<span v-if="isSearching" class="sharing__search-spinner" aria-hidden="true" />
					<span v-if="searchError" class="sharing__error">{{ searchError }}</span>

					<!-- Dropdown results -->
					<ul v-if="searchResults.length > 0" class="sharing__dropdown" role="listbox">
						<li
							v-for="result in searchResults"
							:key="`${result.type}-${result.id}`"
							class="sharing__dropdown-item"
							role="option"
							:aria-label="result.displayName"
							@click="pickSharee(result)"
							@keydown.enter="pickSharee(result)"
							@keydown.space.prevent="pickSharee(result)">
							<AccountGroupIcon v-if="result.type === 'group'" :size="18" class="sharing__type-icon" />
							<AccountIcon v-else :size="18" class="sharing__type-icon" />
							<span class="sharing__dropdown-name">{{ result.displayName }}</span>
							<span class="sharing__dropdown-type">{{ result.type === 'group' ? t('kanso', 'Group') : t('kanso', 'User') }}</span>
						</li>
					</ul>
				</div>
				<span v-if="addAclError" class="sharing__error sharing__error--add">{{ addAclError }}</span>

				<!-- Current ACL entries -->
				<ul class="sharing__list" role="list">
					<li v-if="acl.length === 0" class="sharing__empty">
						{{ t('kanso', 'Not shared with anyone yet.') }}
					</li>

					<li
						v-for="entry in acl"
						:key="entry.id"
						class="sharing__entry">
						<!-- Avatar / icon -->
						<div class="sharing__avatar-wrap">
							<NcAvatar
								v-if="entry.participantType === 'user'"
								:user="entry.participant"
								:display-name="resolveDisplayName(entry)"
								:size="32"
								:disable-tooltip="false" />
							<span v-else class="sharing__group-icon" :title="t('kanso', 'Group')">
								<AccountGroupIcon :size="20" />
							</span>
						</div>

						<!-- Display name -->
						<span class="sharing__entry-name">{{ resolveDisplayName(entry) }}</span>

						<!-- Board side (#3742): internal (provider) vs external (client).
						     Role assignment is MANAGE-gated; others see a passive badge. -->
						<select
							v-if="canManage"
							class="sharing__role-select"
							:value="entry.role || 'internal'"
							:disabled="patchingAclId === entry.id"
							:aria-label="t('kanso', 'Board side for {name}', { name: resolveDisplayName(entry) })"
							:title="t('kanso', 'Internal members and external members each see only their own side\'s internal cards')"
							data-test="acl-role-select"
							@change="changeRole(entry, $event.target.value)">
							<option value="internal">{{ t('kanso', 'Internal') }}</option>
							<option value="external">{{ t('kanso', 'External') }}</option>
						</select>
						<span
							v-else-if="(entry.role || 'internal') === 'external'"
							class="sharing__role-badge"
							data-test="acl-role-badge">
							{{ t('kanso', 'External') }}
						</span>

						<!-- Permission toggles: Edit (bit 2), Share (bit 4), Manage (bit 8) -->
						<div class="sharing__perms">
							<label
								class="sharing__perm-label"
								:class="{ 'sharing__perm-label--disabled': !canToggleBit(entry, PERM_EDIT) }"
								:title="t('kanso', 'Can edit')">
								<input
									type="checkbox"
									:checked="hasBit(entry.permission, PERM_EDIT)"
									:disabled="!canToggleBit(entry, PERM_EDIT) || patchingAclId === entry.id"
									@change="togglePerm(entry, PERM_EDIT, $event.target.checked)" />
								{{ t('kanso', 'Edit') }}
							</label>
							<label
								class="sharing__perm-label"
								:class="{ 'sharing__perm-label--disabled': !canToggleBit(entry, PERM_SHARE) }"
								:title="t('kanso', 'Can share')">
								<input
									type="checkbox"
									:checked="hasBit(entry.permission, PERM_SHARE)"
									:disabled="!canToggleBit(entry, PERM_SHARE) || patchingAclId === entry.id"
									@change="togglePerm(entry, PERM_SHARE, $event.target.checked)" />
								{{ t('kanso', 'Share') }}
							</label>
							<label
								class="sharing__perm-label"
								:class="{ 'sharing__perm-label--disabled': !canToggleBit(entry, PERM_MANAGE) }"
								:title="t('kanso', 'Can manage')">
								<input
									type="checkbox"
									:checked="hasBit(entry.permission, PERM_MANAGE)"
									:disabled="!canToggleBit(entry, PERM_MANAGE) || patchingAclId === entry.id"
									@change="togglePerm(entry, PERM_MANAGE, $event.target.checked)" />
								{{ t('kanso', 'Manage') }}
							</label>
						</div>

						<!-- Per-entry error -->
						<span v-if="patchAclErrors[entry.id]" class="sharing__error">{{ patchAclErrors[entry.id] }}</span>

						<!-- Delete / inline confirm -->
						<button
							class="sharing__remove-btn"
							:title="t('kanso', 'Remove')"
							:aria-label="t('kanso', 'Remove {name} from board', { name: resolveDisplayName(entry) })"
							:disabled="confirmRemoveAclId === entry.id && isRemovingAcl"
							@click="confirmRemoveAcl(entry)">
							<DeleteIcon :size="14" />
						</button>

						<!-- Inline remove confirm -->
						<div v-if="confirmRemoveAclId === entry.id" class="label-settings__confirm">
							<span>{{ t('kanso', 'Remove "{name}" from board?', { name: resolveDisplayName(entry) }) }}</span>
							<button class="label-settings__confirm-yes" :disabled="isRemovingAcl" @click="doRemoveAcl(entry)">
								{{ t('kanso', 'Remove') }}
							</button>
							<button class="label-settings__confirm-no" @click="confirmRemoveAclId = null">
								{{ t('kanso', 'Cancel') }}
							</button>
							<span v-if="removeAclError" class="label-settings__error">{{ removeAclError }}</span>
						</div>
					</li>
				</ul>

				<!-- Leave board: only for user-type entries matching current user -->
				<div v-if="ownEntry" class="sharing__leave">
					<button
						v-if="!confirmLeave"
						class="sharing__leave-btn"
						@click="confirmLeave = true">
						{{ t('kanso', 'Leave board') }}
					</button>
					<div v-else class="label-settings__confirm">
						<span>{{ t('kanso', 'Leave this board?') }}</span>
						<button class="label-settings__confirm-yes" :disabled="isLeaving" @click="doLeave">
							{{ t('kanso', 'Leave') }}
						</button>
						<button class="label-settings__confirm-no" @click="confirmLeave = false">
							{{ t('kanso', 'Cancel') }}
						</button>
						<span v-if="leaveError" class="label-settings__error">{{ leaveError }}</span>
					</div>
				</div>
				</template>

				<!-- Public / read-only link group (#3531) — lives with sharing so
				     public-link creation sits next to member sharing. -->
				<div class="automation__group sharing__public-link">
					<button
						class="automation__group-header"
						type="button"
						:aria-expanded="automationGroups.publicLink ? 'true' : 'false'"
						aria-controls="bs-sharing-public-link"
						@click="toggleAutomationGroup('publicLink')">
						<LinkVariantIcon :size="16" class="automation__group-icon" />
						<span class="automation__group-title">{{ t('kanso', 'Public link') }}</span>
						<span v-if="publicShare.enabled" class="automation__group-badge">{{ t('kanso', 'Link active') }}</span>
						<ChevronUpIcon v-if="automationGroups.publicLink" :size="16" class="automation__group-chevron" />
						<ChevronDownIcon v-else :size="16" class="automation__group-chevron" />
					</button>
					<div v-show="automationGroups.publicLink" id="bs-sharing-public-link" class="automation__group-body">
						<p v-if="!canManage" class="workflow__readonly-notice">
							{{ t('kanso', 'You need manage permission to configure the public link.') }}
						</p>
						<template v-else>
							<p class="github-webhook__hint">
								{{ publicShareNote }}
							</p>

							<div class="github-webhook__actions">
								<NcCheckboxRadioSwitch
									type="switch"
									:model-value="publicShare.enabled"
									:disabled="publicShareBusy"
									@update:model-value="togglePublicShare">
									{{ t('kanso', 'Enable public link') }}
								</NcCheckboxRadioSwitch>
							</div>

							<template v-if="publicShare.enabled && publicShare.url">
								<!-- Opt-in exposure toggles (#3949): deliberately widen the
								     person-free public link. OFF by default. -->
								<div class="github-webhook__actions">
									<NcCheckboxRadioSwitch
										type="switch"
										:model-value="publicShare.commentsEnabled"
										:disabled="publicShareBusy"
										@update:model-value="togglePublicShareComments">
										{{ t('kanso', 'Show comments (read-only)') }}
									</NcCheckboxRadioSwitch>
								</div>

								<label class="github-webhook__label">{{ t('kanso', 'Public link') }}</label>
								<div class="github-webhook__row">
									<input class="github-webhook__input" type="text" readonly :value="publicShare.url">
									<NcButton :disabled="!publicShare.url" @click="copyText(publicShare.url)">
										{{ t('kanso', 'Copy') }}
									</NcButton>
								</div>
								<div class="github-webhook__actions">
									<NcButton :disabled="publicShareBusy" @click="handleRotatePublicShare">
										{{ t('kanso', 'Rotate link') }}
									</NcButton>
									<span class="bs-share__hint">{{ t('kanso', 'Anyone with the link can view this board read-only.') }}</span>
								</div>
							</template>
							<span v-if="publicShareError" class="label-settings__error">{{ publicShareError }}</span>
						</template>
					</div>
				</div>
				</section>

				<section
					v-show="activeTab === 'workflow'"
					id="bs-pane-workflow"
					class="bs-pane"
					role="tabpanel"
					aria-labelledby="bs-rail-tab-workflow">
				<p v-if="!canEdit" class="workflow__readonly-notice">
					{{ t('kanso', 'You need edit permission to configure workflows.') }}
				</p>

				<!-- Estimation scale selector (MANAGE only) -->
				<div class="workflow__item workflow__item--estimation">
					<span class="workflow__stack-name">{{ t('kanso', 'Estimation') }}</span>
					<div class="workflow__field">
						<label
							:for="`estimate-scale-${boardId}`"
							class="workflow__label">
							{{ t('kanso', 'Scale') }}
						</label>
						<select
							:id="`estimate-scale-${boardId}`"
							:key="`estimate-scale-${scaleSelectKey}`"
							class="workflow__select"
							:disabled="!canManage || estimateScaleSaving"
							:value="currentEstimateScale"
							@change="onEstimateScaleChange($event.target.value)">
							<option
								v-for="opt in ESTIMATE_SCALE_OPTIONS"
								:key="opt.value"
								:value="opt.value">
								{{ opt.label }}
							</option>
						</select>
					</div>
					<span v-if="estimateScaleSaving" class="workflow__saving" aria-live="polite">
						{{ t('kanso', 'Saving…') }}
					</span>
					<span v-if="estimateScaleError" class="label-settings__error">
						{{ estimateScaleError }}
					</span>
				</div>

				<ul v-if="sortedStacks.length > 0" class="workflow__list" role="list">
					<li
						v-for="stack in sortedStacks"
						:key="stack.id"
						class="workflow__item">

						<span class="workflow__stack-name">{{ stack.title }}</span>

						<!-- Role selector -->
						<div class="workflow__field">
							<label
								:for="`workflow-role-${stack.id}`"
								class="workflow__label">
								{{ t('kanso', 'Role') }}
							</label>
							<select
								:id="`workflow-role-${stack.id}`"
								class="workflow__select"
								:disabled="!canEdit || workflowSaving[stack.id]"
								:value="stack.role ?? 0"
								@change="onRoleChange(stack, $event.target.value)">
								<option
									v-for="opt in ROLE_OPTIONS"
									:key="opt.value"
									:value="opt.value">
									{{ opt.label }}
								</option>
							</select>
						</div>

						<!-- WIP limit input -->
						<div class="workflow__field">
							<label
								:for="`workflow-wip-${stack.id}`"
								class="workflow__label">
								{{ t('kanso', 'WIP limit') }}
							</label>
							<input
								:id="`workflow-wip-${stack.id}`"
								class="workflow__wip-input"
								type="number"
								min="0"
								step="1"
								:disabled="!canEdit || workflowSaving[stack.id]"
								:value="wipLimitDisplay(stack)"
								:placeholder="t('kanso', 'No limit')"
								@change="onWipLimitChange(stack, $event.target.value)" />
						</div>

						<!-- Per-stack saving indicator -->
						<span v-if="workflowSaving[stack.id]" class="workflow__saving" aria-live="polite">
							{{ t('kanso', 'Saving…') }}
						</span>

						<!-- Per-stack error -->
						<span v-if="workflowErrors[stack.id]" class="label-settings__error">
							{{ workflowErrors[stack.id] }}
						</span>
					</li>
				</ul>

				<p v-else class="label-settings__empty">
					{{ t('kanso', 'No stacks yet.') }}
				</p>
				</section>

				<section
					v-show="activeTab === 'automation'"
					id="bs-pane-automation"
					class="bs-pane"
					role="tabpanel"
					aria-labelledby="bs-rail-tab-automation">

				<p class="automation__intro">
					{{ t('kanso', 'Rules run on Nextcloud cron.') }}
				</p>

				<!-- GitHub integration group -->
				<div class="automation__group">
					<button
						class="automation__group-header"
						type="button"
						:aria-expanded="automationGroups.github ? 'true' : 'false'"
						aria-controls="bs-automation-github"
						@click="toggleAutomationGroup('github')">
						<GithubIcon :size="16" class="automation__group-icon" />
						<span class="automation__group-title">{{ t('kanso', 'GitHub') }}</span>
						<span v-if="webhook.enabled" class="automation__group-badge">{{ t('kanso', 'Webhook active') }}</span>
						<ChevronUpIcon v-if="automationGroups.github" :size="16" class="automation__group-chevron" />
						<ChevronDownIcon v-else :size="16" class="automation__group-chevron" />
					</button>
					<div v-show="automationGroups.github" id="bs-automation-github" class="automation__group-body">
				<p v-if="!canManage" class="workflow__readonly-notice">
					{{ t('kanso', 'You need manage permission to configure the GitHub webhook.') }}
				</p>
				<template v-else>
					<p class="github-webhook__hint">
						{{ t('kanso', 'Add a GitHub webhook sending "pull_request" and "issues" events to the URL below. A PR opened on a kanso-<id> branch moves its card to your Review column; a merged PR moves it to Done. Closing an issue linked on a card moves that card to Done; reopening it moves the card back to In progress. Issue intake below can also turn newly opened issues into linked cards.') }}
					</p>

					<label class="github-webhook__label">{{ t('kanso', 'Payload URL') }}</label>
					<div class="github-webhook__row">
						<input
							class="github-webhook__input"
							type="text"
							readonly
							:value="webhook.payloadUrl">
						<NcButton :disabled="!webhook.payloadUrl" @click="copyText(webhook.payloadUrl)">
							{{ t('kanso', 'Copy') }}
						</NcButton>
					</div>

					<template v-if="revealedSecret">
						<label class="github-webhook__label">{{ t('kanso', 'Secret (copy it now, it is shown only once)') }}</label>
						<div class="github-webhook__row">
							<input class="github-webhook__input" type="text" readonly :value="revealedSecret">
							<NcButton @click="copyText(revealedSecret)">{{ t('kanso', 'Copy') }}</NcButton>
						</div>
					</template>

					<div class="github-webhook__actions">
						<NcButton type="primary" :disabled="webhookBusy" @click="handleRotateSecret">
							{{ webhook.enabled ? t('kanso', 'Regenerate secret') : t('kanso', 'Enable & generate secret') }}
						</NcButton>
						<NcButton v-if="webhook.enabled" :disabled="webhookBusy" @click="handleDisableWebhook">
							{{ t('kanso', 'Disable') }}
						</NcButton>
						<span v-if="webhook.enabled" class="github-webhook__status">{{ t('kanso', 'Enabled') }}</span>
					</div>

					<!-- Issue intake (#3752): opt-in auto-create of linked cards -->
					<template v-if="webhook.enabled">
						<label class="github-webhook__label" for="bs-webhook-intake-stack">{{ t('kanso', 'Issue intake') }}</label>
						<p class="github-webhook__hint">
							{{ t('kanso', 'Pick a column and every newly opened issue becomes a card there, with the issue attached as a link (title only, no body copy).') }}
						</p>
						<div class="github-webhook__row">
							<select
								id="bs-webhook-intake-stack"
								class="workflow__select"
								:disabled="intakeBusy"
								:value="webhook.intakeStackId == null ? '' : String(webhook.intakeStackId)"
								@change="onIntakeStackChange">
								<option value="">{{ t('kanso', 'Off — do not create cards') }}</option>
								<option v-for="s in stacks" :key="s.id" :value="String(s.id)">{{ s.title }}</option>
							</select>
						</div>
						<div v-if="webhook.intakeStackId != null" class="github-webhook__row">
							<select
								class="workflow__select"
								:disabled="intakeBusy"
								:value="intakeFilterMode"
								:aria-label="t('kanso', 'Which issues to take in')"
								@change="onIntakeFilterModeChange">
								<option value="all">{{ t('kanso', 'All issues') }}</option>
								<option value="label">{{ t('kanso', 'Only issues with a label') }}</option>
							</select>
							<template v-if="intakeFilterMode === 'label'">
								<input
									v-model="intakeLabelInput"
									class="github-webhook__input"
									type="text"
									:placeholder="t('kanso', 'GitHub label name')"
									:aria-label="t('kanso', 'GitHub label name')"
									:disabled="intakeBusy"
									@keyup.enter="saveIntakeLabel">
								<NcButton :disabled="intakeBusy || !intakeLabelInput.trim()" @click="saveIntakeLabel">
									{{ t('kanso', 'Save') }}
								</NcButton>
							</template>
						</div>
					</template>
					<span v-if="webhookError" class="label-settings__error">{{ webhookError }}</span>
				</template>
					</div>
				</div>

				<!-- Calendar feed group (read-only ICS of card due dates) (#3541) -->
				<div class="automation__group">
					<button
						class="automation__group-header"
						type="button"
						:aria-expanded="automationGroups.calendarFeed ? 'true' : 'false'"
						aria-controls="bs-automation-calendar-feed"
						@click="toggleAutomationGroup('calendarFeed')">
						<CalendarIcon :size="16" class="automation__group-icon" />
						<span class="automation__group-title">{{ t('kanso', 'Calendar feed') }}</span>
						<span v-if="calendarFeed.enabled" class="automation__group-badge">{{ t('kanso', 'Feed active') }}</span>
						<ChevronUpIcon v-if="automationGroups.calendarFeed" :size="16" class="automation__group-chevron" />
						<ChevronDownIcon v-else :size="16" class="automation__group-chevron" />
					</button>
					<div v-show="automationGroups.calendarFeed" id="bs-automation-calendar-feed" class="automation__group-body">
						<p v-if="!canManage" class="workflow__readonly-notice">
							{{ t('kanso', 'You need manage permission to configure the calendar feed.') }}
						</p>
						<template v-else>
							<p class="github-webhook__hint">
								{{ t('kanso', 'Subscribe to this board\'s card due dates in any calendar app (Nextcloud Calendar, Thunderbird, your phone). The feed is read-only and shows only card titles, due dates and a link back to each card.') }}
							</p>

							<div class="github-webhook__actions">
								<NcCheckboxRadioSwitch
									type="switch"
									:model-value="calendarFeed.enabled"
									:disabled="calendarFeedBusy"
									@update:model-value="toggleCalendarFeed">
									{{ t('kanso', 'Enable calendar feed') }}
								</NcCheckboxRadioSwitch>
							</div>

							<template v-if="calendarFeed.enabled && calendarFeed.url">
								<label class="github-webhook__label">{{ t('kanso', 'Feed URL') }}</label>
								<div class="github-webhook__row">
									<input class="github-webhook__input" type="text" readonly :value="calendarFeed.url">
									<NcButton :disabled="!calendarFeed.url" @click="copyText(calendarFeed.url)">
										{{ t('kanso', 'Copy') }}
									</NcButton>
								</div>
								<div class="github-webhook__actions">
									<NcButton :disabled="calendarFeedBusy" @click="handleRotateCalendarFeed">
										{{ t('kanso', 'Rotate feed URL') }}
									</NcButton>
									<span class="bs-share__hint">{{ t('kanso', 'Anyone with the link can subscribe to this board\'s due dates.') }}</span>
								</div>
							</template>
							<span v-if="calendarFeedError" class="label-settings__error">{{ calendarFeedError }}</span>
						</template>
					</div>
				</div>

				<!-- Auto-archive group -->
				<div class="automation__group">
					<button
						class="automation__group-header"
						type="button"
						:aria-expanded="automationGroups.autoArchive ? 'true' : 'false'"
						aria-controls="bs-automation-auto-archive"
						@click="toggleAutomationGroup('autoArchive')">
						<ArchiveIcon :size="16" class="automation__group-icon" />
						<span class="automation__group-title">{{ t('kanso', 'Auto-archive') }}</span>
						<span v-if="archiveRules.length" class="automation__group-count">{{ archiveRules.length }}</span>
						<ChevronUpIcon v-if="automationGroups.autoArchive" :size="16" class="automation__group-chevron" />
						<ChevronDownIcon v-else :size="16" class="automation__group-chevron" />
					</button>
					<div v-show="automationGroups.autoArchive" id="bs-automation-auto-archive" class="automation__group-body">

				<p v-if="!canManage" class="workflow__readonly-notice">
					{{ t('kanso', 'You need manage permission to configure automation rules.') }}
				</p>

				<!-- Loading / error states -->
				<p v-if="archiveRulesQuery.isLoading.value" class="automation__loading">
					{{ t('kanso', 'Loading…') }}
				</p>
				<p v-else-if="archiveRulesQuery.isError.value" class="label-settings__error">
					{{ t('kanso', 'Failed to load archive rules.') }}
				</p>

				<!-- Rules list -->
				<template v-else>
					<ul class="automation__rules-list" role="list">
						<li v-if="!archiveRules.length" class="label-settings__empty">
							{{ t('kanso', 'No auto-archive rules yet.') }}
						</li>

						<li
							v-for="rule in archiveRules"
							:key="rule.id"
							class="automation__rule-item">

							<!-- Rule description row -->
							<div class="automation__rule-main">
								<!-- Human-readable description -->
								<span class="automation__rule-desc">
									<template v-if="rule.condition === 0">
										{{ t('kanso', 'Archive cards done for more than {n} days', { n: secondsToDays(rule.thresholdSeconds) }) }}
									</template>
									<template v-else>
										{{ t('kanso', 'Archive cards done AND created more than {n} days ago', { n: secondsToDays(rule.thresholdSeconds) }) }}
									</template>
									<span v-if="rule.stackId" class="automation__rule-scope">
										({{ t('kanso', 'stack: {name}', { name: resolveStackName(rule.stackId) }) }})
									</span>
									<span v-else class="automation__rule-scope">
										({{ t('kanso', 'whole board') }})
									</span>
								</span>

								<!-- Enable/disable toggle -->
								<label
									v-if="canManage"
									class="automation__toggle-label"
									:title="rule.enabled ? t('kanso', 'Disable rule') : t('kanso', 'Enable rule')">
									<input
										type="checkbox"
										:checked="rule.enabled"
										:disabled="togglingRuleId === rule.id"
										class="automation__toggle-input"
										@change="toggleRuleEnabled(rule, $event.target.checked)" />
									<span class="automation__toggle-track" aria-hidden="true" />
									<span class="automation__toggle-sr">
										{{ rule.enabled ? t('kanso', 'Enabled') : t('kanso', 'Disabled') }}
									</span>
								</label>
								<span v-else class="automation__rule-status">
									{{ rule.enabled ? t('kanso', 'Enabled') : t('kanso', 'Disabled') }}
								</span>
							</div>

							<!-- Action buttons row (MANAGE only) -->
							<div v-if="canManage" class="automation__rule-actions">
								<!-- Archive now button -->
								<button
									class="automation__archive-now-btn"
									:disabled="archivingRuleId === rule.id"
									@click="doArchiveNow(rule)">
									{{ archivingRuleId === rule.id ? t('kanso', 'Running…') : t('kanso', 'Archive now') }}
								</button>

								<!-- Inline "Archived N cards" feedback -->
								<span
									v-if="archiveNowResults[rule.id] !== undefined"
									class="automation__archive-result">
									{{ t('kanso', 'Archived {n} cards', { n: archiveNowResults[rule.id] }) }}
								</span>

								<!-- Delete button -->
								<button
									class="label-settings__action-btn label-settings__action-btn--danger"
									:title="t('kanso', 'Delete rule')"
									:aria-label="t('kanso', 'Delete rule')"
									:disabled="confirmDeleteRuleId === rule.id && isDeletingRule"
									@click="confirmDeleteRule(rule)">
									<DeleteIcon :size="14" />
								</button>
							</div>

							<!-- Inline delete confirm -->
							<div v-if="confirmDeleteRuleId === rule.id" class="label-settings__confirm">
								<span>{{ t('kanso', 'Delete this rule?') }}</span>
								<button class="label-settings__confirm-yes" :disabled="isDeletingRule" @click="doDeleteRule(rule)">
									{{ t('kanso', 'Delete') }}
								</button>
								<button class="label-settings__confirm-no" @click="confirmDeleteRuleId = null">
									{{ t('kanso', 'Cancel') }}
								</button>
								<span v-if="deleteRuleError" class="label-settings__error">{{ deleteRuleError }}</span>
							</div>

							<!-- Toggle error -->
							<span v-if="toggleRuleErrors[rule.id]" class="label-settings__error">
								{{ toggleRuleErrors[rule.id] }}
							</span>

							<!-- Archive now error -->
							<span v-if="archiveNowErrors[rule.id]" class="label-settings__error">
								{{ archiveNowErrors[rule.id] }}
							</span>
						</li>
					</ul>

					<!-- Add rule form (MANAGE only) -->
					<form v-if="canManage" class="automation__create-form" @submit.prevent="submitCreateRule">
						<h4 class="label-settings__create-heading">{{ t('kanso', 'Add rule') }}</h4>

						<!-- Scope selector -->
						<div class="automation__form-row">
							<label class="automation__form-label" :for="`archive-scope-${boardId}`">
								{{ t('kanso', 'Scope') }}
							</label>
							<select
								:id="`archive-scope-${boardId}`"
								v-model="newRuleStackId"
								class="workflow__select automation__form-select">
								<option :value="null">{{ t('kanso', 'Whole board') }}</option>
								<option
									v-for="stack in activeStacks"
									:key="stack.id"
									:value="stack.id">
									{{ stack.title }}
								</option>
							</select>
						</div>

						<!-- Condition selector -->
						<div class="automation__form-row">
							<label class="automation__form-label" :for="`archive-condition-${boardId}`">
								{{ t('kanso', 'Condition') }}
							</label>
							<select
								:id="`archive-condition-${boardId}`"
								v-model="newRuleCondition"
								class="workflow__select automation__form-select">
								<option :value="0">{{ t('kanso', 'Done for ≥ N days') }}</option>
								<option :value="1">{{ t('kanso', 'Done AND created ≥ N days ago') }}</option>
							</select>
						</div>

						<!-- Threshold (days) -->
						<div class="automation__form-row">
							<label class="automation__form-label" :for="`archive-days-${boardId}`">
								{{ t('kanso', 'Days') }}
							</label>
							<input
								:id="`archive-days-${boardId}`"
								v-model.number="newRuleDays"
								type="number"
								min="0"
								step="1"
								class="workflow__wip-input"
								:placeholder="t('kanso', '0')" />
						</div>

						<button
							class="label-settings__create-btn automation__create-btn"
							type="submit"
							:disabled="isCreatingRule || newRuleDays === '' || newRuleDays === null || newRuleDays < 0">
							{{ isCreatingRule ? t('kanso', 'Adding…') : t('kanso', 'Add rule') }}
						</button>

						<span v-if="createRuleError" class="label-settings__error">{{ createRuleError }}</span>
					</form>
				</template>
					</div>
				</div>

				<!-- Recurring cards group -->
				<div class="automation__group">
					<button
						class="automation__group-header"
						type="button"
						:aria-expanded="automationGroups.recurring ? 'true' : 'false'"
						aria-controls="bs-automation-recurring"
						@click="toggleAutomationGroup('recurring')">
						<RepeatIcon :size="16" class="automation__group-icon" />
						<span class="automation__group-title">{{ t('kanso', 'Recurring cards') }}</span>
						<span v-if="recurRules.length" class="automation__group-count">{{ recurRules.length }}</span>
						<ChevronUpIcon v-if="automationGroups.recurring" :size="16" class="automation__group-chevron" />
						<ChevronDownIcon v-else :size="16" class="automation__group-chevron" />
					</button>
					<div v-show="automationGroups.recurring" id="bs-automation-recurring" class="automation__group-body">

				<!-- Loading / error states -->
				<p v-if="recurRulesQuery.isLoading.value" class="automation__loading">
					{{ t('kanso', 'Loading…') }}
				</p>
				<p v-else-if="recurRulesQuery.isError.value" class="label-settings__error">
					{{ t('kanso', 'Failed to load recur rules.') }}
				</p>

				<template v-else>
					<ul class="automation__rules-list" role="list">
						<li v-if="!recurRules.length" class="label-settings__empty">
							{{ t('kanso', 'No recurring card rules yet.') }}
						</li>

						<li
							v-for="rule in recurRules"
							:key="rule.id"
							class="automation__rule-item">

							<!-- Rule description row -->
							<div class="automation__rule-main">
								<span class="automation__rule-desc">
									<strong class="automation__rule-title">{{ resolveCardTitle(rule.templateCardId) }}</strong>
									<span class="automation__rule-meta">
										<span class="automation__recur-summary">{{ humanRrule(rule.rrule) }}</span>
										<span class="automation__rule-scope">→ {{ resolveStackName(rule.targetStackId) }}</span>
										<span class="automation__rule-mode">{{ rule.mode === 0 ? t('kanso', 'Clone') : t('kanso', 'Reset') }}</span>
										<span v-if="rule.nextOccurrenceAt" class="automation__rule-next">{{ t('kanso', 'next: {date}', { date: formatDate(rule.nextOccurrenceAt) }) }}</span>
									</span>
								</span>

								<!-- Enable/disable toggle -->
								<label
									v-if="canManage"
									class="automation__toggle-label"
									:title="rule.enabled ? t('kanso', 'Disable rule') : t('kanso', 'Enable rule')">
									<input
										type="checkbox"
										:checked="rule.enabled"
										:disabled="togglingRecurRuleId === rule.id"
										class="automation__toggle-input"
										@change="toggleRecurRuleEnabled(rule, $event.target.checked)" />
									<span class="automation__toggle-track" aria-hidden="true" />
									<span class="automation__toggle-sr">
										{{ rule.enabled ? t('kanso', 'Enabled') : t('kanso', 'Disabled') }}
									</span>
								</label>
								<span v-else class="automation__rule-status">
									{{ rule.enabled ? t('kanso', 'Enabled') : t('kanso', 'Disabled') }}
								</span>
							</div>

							<!-- Action buttons row (MANAGE only) -->
							<div v-if="canManage" class="automation__rule-actions">
								<!-- Create now button -->
								<button
									class="automation__archive-now-btn"
									:disabled="creatingNowRuleId === rule.id"
									@click="doCreateNow(rule)">
									{{ creatingNowRuleId === rule.id ? t('kanso', 'Creating…') : t('kanso', 'Create now') }}
								</button>

								<!-- Inline "Created" feedback -->
								<span
									v-if="createNowResults[rule.id]"
									class="automation__archive-result">
									{{ t('kanso', 'Created') }}
								</span>

								<!-- Delete button -->
								<button
									class="label-settings__action-btn label-settings__action-btn--danger"
									:title="t('kanso', 'Delete rule')"
									:aria-label="t('kanso', 'Delete rule')"
									:disabled="confirmDeleteRecurRuleId === rule.id && isDeletingRecurRule"
									@click="confirmDeleteRecurRule(rule)">
									<DeleteIcon :size="14" />
								</button>
							</div>

							<!-- Inline delete confirm -->
							<div v-if="confirmDeleteRecurRuleId === rule.id" class="label-settings__confirm">
								<span>{{ t('kanso', 'Delete this rule?') }}</span>
								<button class="label-settings__confirm-yes" :disabled="isDeletingRecurRule" @click="doDeleteRecurRule(rule)">
									{{ t('kanso', 'Delete') }}
								</button>
								<button class="label-settings__confirm-no" @click="confirmDeleteRecurRuleId = null">
									{{ t('kanso', 'Cancel') }}
								</button>
								<span v-if="deleteRecurRuleError" class="label-settings__error">{{ deleteRecurRuleError }}</span>
							</div>

							<!-- Toggle error -->
							<span v-if="toggleRecurRuleErrors[rule.id]" class="label-settings__error">
								{{ toggleRecurRuleErrors[rule.id] }}
							</span>

							<!-- Create now error -->
							<span v-if="createNowErrors[rule.id]" class="label-settings__error">
								{{ createNowErrors[rule.id] }}
							</span>
						</li>
					</ul>

					<!-- Add recur rule form (MANAGE only) -->
					<form v-if="canManage" class="automation__create-form" @submit.prevent="submitCreateRecurRule">
						<h4 class="label-settings__create-heading">{{ t('kanso', 'Add rule') }}</h4>

						<!-- Template card -->
						<div class="automation__form-row">
							<label class="automation__form-label" :for="`recur-card-${boardId}`">
								{{ t('kanso', 'Template card') }}
							</label>
							<select
								:id="`recur-card-${boardId}`"
								v-model="newRecurTemplateCardId"
								class="workflow__select automation__form-select">
								<option :value="null" disabled>{{ t('kanso', 'Select a card…') }}</option>
								<option
									v-for="card in activeCards"
									:key="card.id"
									:value="card.id">
									{{ card.title }}
								</option>
							</select>
						</div>

						<!-- Target stack -->
						<div class="automation__form-row">
							<label class="automation__form-label" :for="`recur-stack-${boardId}`">
								{{ t('kanso', 'Target stack') }}
							</label>
							<select
								:id="`recur-stack-${boardId}`"
								v-model="newRecurTargetStackId"
								class="workflow__select automation__form-select">
								<option :value="null" disabled>{{ t('kanso', 'Select a stack…') }}</option>
								<option
									v-for="stack in activeStacks"
									:key="stack.id"
									:value="stack.id">
									{{ stack.title }}
								</option>
							</select>
						</div>

						<!-- Mode -->
						<div class="automation__form-row">
							<label class="automation__form-label">{{ t('kanso', 'Mode') }}</label>
							<div class="automation__radio-group">
								<label class="automation__radio-label">
									<input type="radio" :value="0" v-model="newRecurMode" />
									{{ t('kanso', 'Clone') }}
								</label>
								<label class="automation__radio-label">
									<input type="radio" :value="1" v-model="newRecurMode" />
									{{ t('kanso', 'Reset') }}
								</label>
							</div>
						</div>

						<!-- Frequency + interval -->
						<div class="automation__form-row">
							<label class="automation__form-label" :for="`recur-freq-${boardId}`">
								{{ t('kanso', 'Frequency') }}
							</label>
							<div class="automation__freq-row">
								<span class="automation__freq-every">{{ t('kanso', 'Every') }}</span>
								<input
									:id="`recur-interval-${boardId}`"
									v-model.number="newRecurInterval"
									type="number"
									min="1"
									step="1"
									class="workflow__wip-input automation__interval-input" />
								<select
									:id="`recur-freq-${boardId}`"
									v-model="newRecurFreq"
									class="workflow__select">
									<option value="DAILY">{{ t('kanso', 'day(s)') }}</option>
									<option value="WEEKLY">{{ t('kanso', 'week(s)') }}</option>
									<option value="MONTHLY">{{ t('kanso', 'month(s)') }}</option>
									<option value="YEARLY">{{ t('kanso', 'year(s)') }}</option>
								</select>
							</div>
						</div>

						<!-- Weekday multi-select (only when WEEKLY) -->
						<div v-if="newRecurFreq === 'WEEKLY'" class="automation__form-row automation__form-row--top">
							<label class="automation__form-label">{{ t('kanso', 'On days') }}</label>
							<div class="automation__weekday-group">
								<button
									v-for="wd in WEEKDAY_OPTIONS"
									:key="wd.value"
									type="button"
									class="automation__weekday-btn"
									:class="{ 'automation__weekday-btn--active': newRecurWeekdays.includes(wd.value) }"
									:aria-pressed="newRecurWeekdays.includes(wd.value)"
									@click="toggleWeekday(wd.value)">
									{{ wd.label }}
								</button>
							</div>
						</div>

						<!-- End condition -->
						<div class="automation__form-row">
							<label class="automation__form-label" :for="`recur-end-${boardId}`">
								{{ t('kanso', 'Ends') }}
							</label>
							<select
								:id="`recur-end-${boardId}`"
								v-model="newRecurEndType"
								class="workflow__select automation__form-select">
								<option value="forever">{{ t('kanso', 'Forever') }}</option>
								<option value="count">{{ t('kanso', 'After N occurrences') }}</option>
								<option value="until">{{ t('kanso', 'Until date') }}</option>
							</select>
						</div>

						<!-- After N occurrences -->
						<div v-if="newRecurEndType === 'count'" class="automation__form-row">
							<label class="automation__form-label" :for="`recur-count-${boardId}`">
								{{ t('kanso', 'Count') }}
							</label>
							<input
								:id="`recur-count-${boardId}`"
								v-model.number="newRecurCount"
								type="number"
								min="1"
								step="1"
								class="workflow__wip-input" />
						</div>

						<!-- Until date -->
						<div v-if="newRecurEndType === 'until'" class="automation__form-row">
							<label class="automation__form-label" :for="`recur-until-${boardId}`">
								{{ t('kanso', 'Until') }}
							</label>
							<input
								:id="`recur-until-${boardId}`"
								v-model="newRecurUntil"
								type="date"
								class="workflow__select automation__form-select" />
						</div>

						<!-- Due-date policy -->
						<div class="automation__form-row">
							<label class="automation__form-label" :for="`recur-due-${boardId}`">
								{{ t('kanso', 'Due date') }}
							</label>
							<select
								:id="`recur-due-${boardId}`"
								v-model="newRecurDuedatePolicy"
								class="workflow__select automation__form-select">
								<option :value="0">{{ t('kanso', 'At occurrence') }}</option>
								<option :value="1">{{ t('kanso', 'Offset after (days)') }}</option>
								<option :value="2">{{ t('kanso', 'None') }}</option>
							</select>
						</div>

						<!-- Offset days (only when policy = offset after) -->
						<div v-if="newRecurDuedatePolicy === 1" class="automation__form-row">
							<label class="automation__form-label" :for="`recur-due-offset-${boardId}`">
								{{ t('kanso', 'Offset (days)') }}
							</label>
							<input
								:id="`recur-due-offset-${boardId}`"
								v-model.number="newRecurDuedateOffsetDays"
								type="number"
								min="0"
								step="1"
								class="workflow__wip-input" />
						</div>

						<!-- Skip while open (clone mode only) -->
						<div v-if="newRecurMode === 0" class="automation__form-row">
							<label class="automation__form-label">{{ t('kanso', 'Skip while open') }}</label>
							<label class="automation__toggle-label">
								<input
									type="checkbox"
									v-model="newRecurSkipWhileOpen"
									class="automation__toggle-input" />
								<span class="automation__toggle-track" aria-hidden="true" />
								<span class="automation__toggle-sr">
									{{ newRecurSkipWhileOpen ? t('kanso', 'Yes') : t('kanso', 'No') }}
								</span>
							</label>
						</div>

						<button
							class="label-settings__create-btn automation__create-btn"
							type="submit"
							:disabled="isCreatingRecurRule || !newRecurTemplateCardId || !newRecurTargetStackId">
							{{ isCreatingRecurRule ? t('kanso', 'Adding…') : t('kanso', 'Add rule') }}
						</button>

						<span v-if="createRecurRuleError" class="label-settings__error">{{ createRecurRuleError }}</span>
					</form>
				</template>
					</div>
				</div>

				<!-- Column automations (card rules) group -->
				<div class="automation__group">
					<button
						class="automation__group-header"
						type="button"
						:aria-expanded="automationGroups.cardRules ? 'true' : 'false'"
						aria-controls="bs-automation-card-rules"
						@click="toggleAutomationGroup('cardRules')">
						<ViewColumnIcon :size="16" class="automation__group-icon" />
						<span class="automation__group-title">{{ t('kanso', 'Column automations') }}</span>
						<span v-if="autoRules.length" class="automation__group-count">{{ autoRules.length }}</span>
						<ChevronUpIcon v-if="automationGroups.cardRules" :size="16" class="automation__group-chevron" />
						<ChevronDownIcon v-else :size="16" class="automation__group-chevron" />
					</button>
					<div v-show="automationGroups.cardRules" id="bs-automation-card-rules" class="automation__group-body">
				<p class="automation__section-hint">
					{{ t('kanso', 'Run an action when a card enters a stack with a given role.') }}
				</p>

				<p v-if="autoRulesQuery.isLoading.value" class="automation__loading">
					{{ t('kanso', 'Loading…') }}
				</p>
				<p v-else-if="autoRulesQuery.isError.value" class="label-settings__error">
					{{ t('kanso', 'Failed to load card rules.') }}
				</p>

				<template v-else>
					<ul class="automation__rules-list" role="list">
						<li v-if="!autoRules.length" class="label-settings__empty">
							{{ t('kanso', 'No card rules yet.') }}
						</li>

						<li
							v-for="rule in autoRules"
							:key="rule.id"
							class="automation__rule-item">

							<div class="automation__rule-main">
								<span class="automation__rule-desc">{{ describeAutoRule(rule) }}</span>

								<!-- Enable/disable toggle -->
								<label
									v-if="canManage"
									class="automation__toggle-label"
									:title="rule.enabled ? t('kanso', 'Disable rule') : t('kanso', 'Enable rule')">
									<input
										type="checkbox"
										:checked="rule.enabled"
										:disabled="togglingAutoRuleId === rule.id"
										class="automation__toggle-input"
										@change="toggleAutoRuleEnabled(rule, $event.target.checked)" />
									<span class="automation__toggle-track" aria-hidden="true" />
									<span class="automation__toggle-sr">
										{{ rule.enabled ? t('kanso', 'Enabled') : t('kanso', 'Disabled') }}
									</span>
								</label>
								<span v-else class="automation__rule-status">
									{{ rule.enabled ? t('kanso', 'Enabled') : t('kanso', 'Disabled') }}
								</span>
							</div>

							<div v-if="canManage" class="automation__rule-actions">
								<button
									class="label-settings__action-btn label-settings__action-btn--danger"
									:title="t('kanso', 'Delete rule')"
									:aria-label="t('kanso', 'Delete rule')"
									:disabled="confirmDeleteAutoRuleId === rule.id && isDeletingAutoRule"
									@click="confirmDeleteAutoRule(rule)">
									<DeleteIcon :size="14" />
								</button>
							</div>

							<div v-if="confirmDeleteAutoRuleId === rule.id" class="label-settings__confirm">
								<span>{{ t('kanso', 'Delete this rule?') }}</span>
								<button class="label-settings__confirm-yes" :disabled="isDeletingAutoRule" @click="doDeleteAutoRule(rule)">
									{{ t('kanso', 'Delete') }}
								</button>
								<button class="label-settings__confirm-no" @click="confirmDeleteAutoRuleId = null">
									{{ t('kanso', 'Cancel') }}
								</button>
								<span v-if="deleteAutoRuleError" class="label-settings__error">{{ deleteAutoRuleError }}</span>
							</div>

							<span v-if="toggleAutoRuleErrors[rule.id]" class="label-settings__error">
								{{ toggleAutoRuleErrors[rule.id] }}
							</span>
						</li>
					</ul>

					<!-- Add card rule form (MANAGE only) -->
					<form v-if="canManage" class="automation__create-form" @submit.prevent="submitCreateAutoRule">
						<h4 class="label-settings__create-heading">{{ t('kanso', 'Add rule') }}</h4>

						<!-- Trigger role -->
						<div class="automation__form-row">
							<label class="automation__form-label" :for="`auto-role-${boardId}`">
								{{ t('kanso', 'When a card enters role') }}
							</label>
							<select
								:id="`auto-role-${boardId}`"
								v-model.number="newAutoRole"
								class="workflow__select automation__form-select">
								<option
									v-for="opt in AUTO_ROLE_OPTIONS"
									:key="opt.value"
									:value="opt.value">
									{{ opt.label }}
								</option>
							</select>
						</div>

						<!-- Action -->
						<div class="automation__form-row">
							<label class="automation__form-label" :for="`auto-action-${boardId}`">
								{{ t('kanso', 'Do') }}
							</label>
							<select
								:id="`auto-action-${boardId}`"
								v-model="newAutoAction"
								class="workflow__select automation__form-select">
								<option value="request_review">{{ t('kanso', 'Request a review') }}</option>
								<option value="add_label">{{ t('kanso', 'Add a label') }}</option>
							</select>
						</div>

						<!-- Reviewer (request_review) -->
						<div v-if="newAutoAction === 'request_review'" class="automation__form-row">
							<label class="automation__form-label" :for="`auto-reviewer-${boardId}`">
								{{ t('kanso', 'Reviewer') }}
							</label>
							<select
								:id="`auto-reviewer-${boardId}`"
								v-model="newAutoReviewer"
								class="workflow__select automation__form-select">
								<option :value="''" disabled>{{ t('kanso', 'Select a person…') }}</option>
								<option
									v-for="p in reviewerOptions"
									:key="p.uid"
									:value="p.uid">
									{{ p.name }}
								</option>
							</select>
						</div>

						<!-- Label (add_label) -->
						<div v-if="newAutoAction === 'add_label'" class="automation__form-row">
							<label class="automation__form-label" :for="`auto-label-${boardId}`">
								{{ t('kanso', 'Label') }}
							</label>
							<select
								:id="`auto-label-${boardId}`"
								v-model.number="newAutoLabel"
								class="workflow__select automation__form-select">
								<option :value="null" disabled>{{ t('kanso', 'Select a label…') }}</option>
								<option
									v-for="label in labels"
									:key="label.id"
									:value="label.id">
									{{ label.title }}
								</option>
							</select>
						</div>

						<button
							class="label-settings__create-btn automation__create-btn"
							type="submit"
							:disabled="isCreatingAutoRule || !canSubmitAutoRule">
							{{ isCreatingAutoRule ? t('kanso', 'Adding…') : t('kanso', 'Add rule') }}
						</button>

						<span v-if="createAutoRuleError" class="label-settings__error">{{ createAutoRuleError }}</span>
					</form>
				</template>
					</div>
				</div>

				</section>
				</div>
			</div>
		</aside>
	</Teleport>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import { showConfirmation } from '@nextcloud/dialogs'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import ArchiveArrowDownIcon from 'vue-material-design-icons/ArchiveArrowDown.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import TagMultipleIcon from 'vue-material-design-icons/TagMultiple.vue'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import TableColumnIcon from 'vue-material-design-icons/TableColumn.vue'
import ShareVariantIcon from 'vue-material-design-icons/ShareVariant.vue'
import SwapHorizontalIcon from 'vue-material-design-icons/SwapHorizontal.vue'
import RobotIcon from 'vue-material-design-icons/Robot.vue'
import ViewColumnIcon from 'vue-material-design-icons/ViewColumn.vue'
import ArchiveIcon from 'vue-material-design-icons/Archive.vue'
import RepeatIcon from 'vue-material-design-icons/Repeat.vue'
import GithubIcon from 'vue-material-design-icons/Github.vue'
import LinkVariantIcon from 'vue-material-design-icons/LinkVariant.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import ChevronUpIcon from 'vue-material-design-icons/ChevronUp.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import { useLabels } from '../composables/useLabels.js'
import { useReviewTypes } from '../composables/useReviewTypes.js'
import { useCardFields } from '../composables/useCardFields.js'
import { useAcl } from '../composables/useAcl.js'
import { useQueryClient } from '@tanstack/vue-query'
import { useBoard } from '../composables/useBoard.js'
import { useBoardActions } from '../composables/useBoardActions.js'
import { useArchiveRules } from '../composables/useArchiveRules.js'
import { useRecurRules } from '../composables/useRecurRules.js'
import { useAutomationRules } from '../composables/useAutomationRules.js'
import { cssColor, LABEL_COLOR_PRESETS } from '../services/color.js'
import { BACKGROUND_PRESETS } from '../services/backgrounds.js'
import { getScaleOptions, scaleTokens } from '../services/estimateScales.js'
import {
	fetchWebhookConfig,
	rotateWebhookSecret as apiRotateWebhookSecret,
	disableWebhook as apiDisableWebhook,
	updateWebhookIntake as apiUpdateWebhookIntake,
	fetchPublicShareConfig,
	enablePublicShare as apiEnablePublicShare,
	disablePublicShare as apiDisablePublicShare,
	setPublicShareComments as apiSetPublicShareComments,
	fetchCalendarFeedConfig,
	enableCalendarFeed as apiEnableCalendarFeed,
	disableCalendarFeed as apiDisableCalendarFeed,
	fetchCalendarSync,
	setCalendarSync,
	getSettings,
	updateSettings,
} from '../services/api.js'

const props = defineProps({
	boardId: {
		type: [String, Number],
		required: true,
	},
	labels: {
		type: Array,
		default: () => [],
	},
	reviewTypes: {
		type: Array,
		default: () => [],
	},
	/** cardFields definitions from board payload */
	cardFields: {
		type: Array,
		default: () => [],
	},
	/** acl array from board payload */
	acl: {
		type: Array,
		default: () => [],
	},
	/** requester permission mask from board payload */
	permissions: {
		type: Number,
		default: 0,
	},
	/** participants list for display-name resolution */
	participants: {
		type: Array,
		default: () => [],
	},
	/** current Nextcloud user id */
	currentUserId: {
		type: String,
		default: '',
	},
	/** stacks array from board payload - used in Workflow tab */
	stacks: {
		type: Array,
		default: () => [],
	},
	/** cards array from board payload - used in Recurring cards tab */
	cards: {
		type: Array,
		default: () => [],
	},
})

const emit = defineEmits(['close', 'leave'])

// ── Escape key dismissal ──────────────────────────────────────────────────────
function onKeydown(e) {
	if (e.key !== 'Escape') return
	// A destructive confirm takes Escape first — dismiss it instead of tearing
	// down the whole settings modal.
	if (showDeleteBoardConfirm.value) {
		showDeleteBoardConfirm.value = false
		return
	}
	emit('close')
}
onMounted(() => document.addEventListener('keydown', onKeydown))
onUnmounted(() => document.removeEventListener('keydown', onKeydown))

// ── Permission constants ──────────────────────────────────────────────────────
const PERM_READ = 1
const PERM_EDIT = 2
const PERM_SHARE = 4
const PERM_MANAGE = 8

const canManage = computed(() => (props.permissions & PERM_MANAGE) !== 0)
const canShare = computed(() => (props.permissions & PERM_SHARE) !== 0)
const canEdit = computed(() => (props.permissions & PERM_EDIT) !== 0)

// ── GitHub webhook config (MANAGE) ───────────────────────────────────────────
const webhook = ref({ enabled: false, payloadUrl: '', intakeStackId: null, intakeLabel: '' })
const revealedSecret = ref('')
const webhookError = ref('')
const webhookBusy = ref(false)

// Issue intake (#3752): the filter-mode select and label input are local UI
// state, re-derived from the server config after every load/save.
const intakeBusy = ref(false)
const intakeFilterMode = ref('all')
const intakeLabelInput = ref('')

function syncIntakeFromConfig() {
	intakeFilterMode.value = webhook.value.intakeLabel ? 'label' : 'all'
	intakeLabelInput.value = webhook.value.intakeLabel || ''
}

async function loadWebhookConfig() {
	if (!canManage.value) return
	try {
		webhook.value = await fetchWebhookConfig(props.boardId)
		syncIntakeFromConfig()
		// An active integration should be visible without a click.
		if (webhook.value.enabled) {
			automationGroups.value.github = true
		}
	} catch (e) {
		webhookError.value = t('kanso', 'Failed to load the GitHub webhook config.')
	}
}

async function saveIntake(stackId, label) {
	webhookError.value = ''
	intakeBusy.value = true
	try {
		webhook.value = await apiUpdateWebhookIntake(props.boardId, stackId, label)
		syncIntakeFromConfig()
	} catch (e) {
		webhookError.value = e?.response?.data?.error || t('kanso', 'Could not save the issue-intake settings.')
	} finally {
		intakeBusy.value = false
	}
}

function onIntakeStackChange(e) {
	const v = e.target.value
	if (v === '') {
		// Off: the server also drops any label filter.
		saveIntake(null, '')
		return
	}
	const label = intakeFilterMode.value === 'label' ? intakeLabelInput.value.trim() : ''
	saveIntake(Number(v), label)
}

function onIntakeFilterModeChange(e) {
	intakeFilterMode.value = e.target.value
	if (e.target.value === 'all') {
		saveIntake(webhook.value.intakeStackId, '')
	}
	// 'label' persists on Save/Enter, once a name is typed.
}

function saveIntakeLabel() {
	const label = intakeLabelInput.value.trim()
	if (!label) return
	saveIntake(webhook.value.intakeStackId, label)
}

async function handleRotateSecret() {
	webhookError.value = ''
	webhookBusy.value = true
	try {
		const res = await apiRotateWebhookSecret(props.boardId)
		revealedSecret.value = res.secret
		webhook.value = { ...webhook.value, enabled: true, payloadUrl: res.payloadUrl }
	} catch (e) {
		webhookError.value = e?.response?.data?.error || t('kanso', 'Could not generate the secret.')
	} finally {
		webhookBusy.value = false
	}
}

async function handleDisableWebhook() {
	webhookError.value = ''
	webhookBusy.value = true
	try {
		await apiDisableWebhook(props.boardId)
		webhook.value = { ...webhook.value, enabled: false }
		revealedSecret.value = ''
	} catch (e) {
		webhookError.value = e?.response?.data?.error || t('kanso', 'Could not disable the webhook.')
	} finally {
		webhookBusy.value = false
	}
}

async function copyText(text) {
	try {
		await navigator.clipboard.writeText(text)
	} catch (e) {
		webhookError.value = t('kanso', 'Could not copy to clipboard.')
	}
}

// ── Public / read-only share link (MANAGE) ───────────────────────────────────
const publicShare = ref({ enabled: false, url: null, commentsEnabled: false })
const publicShareError = ref('')
const publicShareBusy = ref(false)

// The "what's exposed" note reflects the enabled opt-in toggles (#3949): with
// comments OFF the person-free baseline holds; with comments ON the note says so.
const publicShareNote = computed(() => {
	if (publicShare.value.commentsEnabled) {
		return t('kanso', 'Share a read-only view of this board with anyone via a public link. No sign-in is required to view it. Read-only comments are shown; assignees, activity and members are never shown.')
	}
	return t('kanso', 'Share a read-only view of this board with anyone via a public link. No sign-in is required to view it. Assignees, comments, activity and members are never shown.')
})

async function loadPublicShareConfig() {
	if (!canManage.value) return
	try {
		publicShare.value = await fetchPublicShareConfig(props.boardId)
		// An active public link should be visible without a click.
		if (publicShare.value.enabled) {
			automationGroups.value.publicLink = true
		}
	} catch (e) {
		publicShareError.value = t('kanso', 'Failed to load the public link config.')
	}
}

async function togglePublicShare(checked) {
	if (checked) {
		await enablePublicLink()
	} else {
		await disablePublicLink()
	}
}

async function enablePublicLink() {
	publicShareError.value = ''
	publicShareBusy.value = true
	try {
		publicShare.value = await apiEnablePublicShare(props.boardId)
	} catch (e) {
		publicShareError.value = e?.response?.data?.error || t('kanso', 'Could not enable the public link.')
	} finally {
		publicShareBusy.value = false
	}
}

// Rotate = mint a fresh token; the previously-shared link stops working at once.
async function handleRotatePublicShare() {
	await enablePublicLink()
}

async function disablePublicLink() {
	publicShareError.value = ''
	publicShareBusy.value = true
	try {
		await apiDisablePublicShare(props.boardId)
		publicShare.value = { enabled: false, url: null, commentsEnabled: false }
	} catch (e) {
		publicShareError.value = e?.response?.data?.error || t('kanso', 'Could not disable the public link.')
	} finally {
		publicShareBusy.value = false
	}
}

// Opt in / out of showing read-only comments on the public board (#3949).
async function togglePublicShareComments(checked) {
	publicShareError.value = ''
	publicShareBusy.value = true
	try {
		publicShare.value = await apiSetPublicShareComments(props.boardId, checked)
	} catch (e) {
		publicShareError.value = e?.response?.data?.error || t('kanso', 'Could not update the public link options.')
	} finally {
		publicShareBusy.value = false
	}
}

// ── Calendar feed (read-only ICS of card due dates) (#3541) ───────────────────
const calendarFeed = ref({ enabled: false, url: null })
const calendarFeedError = ref('')
const calendarFeedBusy = ref(false)

async function loadCalendarFeedConfig() {
	if (!canManage.value) return
	try {
		calendarFeed.value = await fetchCalendarFeedConfig(props.boardId)
		// An active feed should be visible without a click.
		if (calendarFeed.value.enabled) {
			automationGroups.value.calendarFeed = true
		}
	} catch (e) {
		calendarFeedError.value = t('kanso', 'Failed to load the calendar feed config.')
	}
}

async function toggleCalendarFeed(checked) {
	if (checked) {
		await enableCalendarFeed()
	} else {
		await disableCalendarFeed()
	}
}

async function enableCalendarFeed() {
	calendarFeedError.value = ''
	calendarFeedBusy.value = true
	try {
		calendarFeed.value = await apiEnableCalendarFeed(props.boardId)
	} catch (e) {
		calendarFeedError.value = e?.response?.data?.error || t('kanso', 'Could not enable the calendar feed.')
	} finally {
		calendarFeedBusy.value = false
	}
}

// Rotate = mint a fresh token; the previously-shared feed URL stops working at once.
async function handleRotateCalendarFeed() {
	await enableCalendarFeed()
}

async function disableCalendarFeed() {
	calendarFeedError.value = ''
	calendarFeedBusy.value = true
	try {
		await apiDisableCalendarFeed(props.boardId)
		calendarFeed.value = { enabled: false, url: null }
	} catch (e) {
		calendarFeedError.value = e?.response?.data?.error || t('kanso', 'Could not disable the calendar feed.')
	} finally {
		calendarFeedBusy.value = false
	}
}

onMounted(loadWebhookConfig)
onMounted(loadPublicShareConfig)
onMounted(loadCalendarFeedConfig)

function hasBit(mask, bit) {
	return (mask & bit) !== 0
}

/**
 * Can the current user toggle a given bit on an entry?
 * Rule mirrors server cap: non-MANAGE holders can only touch bits they themselves hold.
 * MANAGE holders can toggle any bit.
 */
function canToggleBit(entry, bit) {
	if (canManage.value) return true
	return hasBit(props.permissions, bit)
}

// ── Tab / section-rail state ──────────────────────────────────────────────────
const activeTab = ref('labels')

// Section rail items. Sharing shows for anyone who can share (member ACL) OR
// manage (the public link lives here now), mirroring the pane's v-if.
const railSections = computed(() => {
	const sections = [
		{ id: 'general', name: t('kanso', 'General'), icon: CogIcon },
		{ id: 'labels', name: t('kanso', 'Labels'), icon: TagMultipleIcon },
		{ id: 'review-types', name: t('kanso', 'Review types'), icon: CheckDecagramIcon },
		{ id: 'card-fields', name: t('kanso', 'Custom fields'), icon: TableColumnIcon },
	]
	if (canShare.value || canManage.value) {
		sections.push({ id: 'sharing', name: t('kanso', 'Sharing'), icon: ShareVariantIcon })
	}
	sections.push({ id: 'workflow', name: t('kanso', 'Workflow'), icon: SwapHorizontalIcon })
	sections.push({ id: 'automation', name: t('kanso', 'Automation'), icon: RobotIcon })
	return sections
})

// Keyboard navigation for the rail (roving tabindex, WAI-ARIA vertical tablist).
function onRailKeydown(e) {
	const keys = ['ArrowDown', 'ArrowUp', 'Home', 'End']
	if (!keys.includes(e.key)) return
	e.preventDefault()
	const ids = railSections.value.map((s) => s.id)
	const current = ids.indexOf(activeTab.value)
	let next = current
	if (e.key === 'ArrowDown') next = (current + 1) % ids.length
	else if (e.key === 'ArrowUp') next = (current - 1 + ids.length) % ids.length
	else if (e.key === 'Home') next = 0
	else if (e.key === 'End') next = ids.length - 1
	activeTab.value = ids[next]
	nextTick(() => {
		document.getElementById(`bs-rail-tab-${ids[next]}`)?.focus()
	})
}

// Human subtitle under the header title (e.g. "Product Roadmap · you can manage").
const boardSubtitle = computed(() => {
	const title = boardQueryData.value?.board?.title ?? ''
	const role = canManage.value
		? t('kanso', 'you can manage')
		: canEdit.value
			? t('kanso', 'you can edit')
			: t('kanso', 'view only')
	return title ? `${title} · ${role}` : role
})

// ── Automation collapsible groups ─────────────────────────────────────────────
// Column automations (card rules) start expanded; the GitHub integration expands
// when its webhook is active. Everything else defaults collapsed to keep the pane
// from being one long scroll.
const automationGroups = ref({
	cardRules: true,
	autoArchive: false,
	recurring: false,
	github: false,
	publicLink: false,
	calendarFeed: false,
})
function toggleAutomationGroup(key) {
	automationGroups.value[key] = !automationGroups.value[key]
}

// ── Board actions (export / duplicate / delete) ──────────────────────────────
// The heavy lifting lives in the useBoardActions composable, shared with the
// boards-view tile menu (#3750). The modal keeps its own confirm step and
// close/navigation behaviour on top of the shared actions.
const {
	exporting,
	exportError,
	exportBoardToFile: exportBoardAction,
	duplicating,
	duplicateError,
	duplicateBoardNow: duplicateBoardAction,
	isDeletingBoard,
	deleteBoardError,
	deleteBoardNow,
} = useBoardActions()

// ── Delete board (MANAGE, destructive) ────────────────────────────────────────
const showDeleteBoardConfirm = ref(false)
function onDeleteBoardClick() {
	deleteBoardError.value = ''
	showDeleteBoardConfirm.value = true
	// Move focus into the confirmation so keyboard users land on the action and
	// Escape/Tab operate within the confirm rather than the page behind it.
	nextTick(() => {
		document.querySelector('.bs-delete-confirm .bs-delete-confirm__actions button')?.focus()
	})
}
async function doDeleteBoard() {
	if (await deleteBoardNow(props.boardId)) {
		showDeleteBoardConfirm.value = false
		emit('close')
		router.push({ name: 'board-list' })
	}
}

// ── General: default-board-on-start preference ───────────────────────────────
const isDefaultBoard = ref(false)
const settingsBusy = ref(false)
onMounted(async () => {
	try {
		const s = await getSettings()
		isDefaultBoard.value = Number(s.defaultBoardId) === Number(props.boardId)
	} catch {
		// Non-fatal: leave the toggle off.
	}
})
async function setDefaultBoard(checked) {
	settingsBusy.value = true
	try {
		const res = await updateSettings({ defaultBoardId: checked ? Number(props.boardId) : null })
		isDefaultBoard.value = Number(res.defaultBoardId) === Number(props.boardId)
	} catch {
		isDefaultBoard.value = !checked // revert on failure
	} finally {
		settingsBusy.value = false
	}
}

// ── General: per-user "show this board in my calendar" (CalDAV, issue #49) ────
// Personal preference; boards sync to your calendar by default, this hides them.
const inMyCalendar = ref(true)
const calendarSyncBusy = ref(false)
onMounted(async () => {
	try {
		const res = await fetchCalendarSync(props.boardId)
		inMyCalendar.value = res.enabled !== false
	} catch {
		// Non-fatal: default to on (its server-side default).
	}
})
async function setInMyCalendar(checked) {
	calendarSyncBusy.value = true
	try {
		const res = await setCalendarSync(props.boardId, checked)
		inMyCalendar.value = res.enabled !== false
	} catch {
		inMyCalendar.value = !checked // revert on failure
	} finally {
		calendarSyncBusy.value = false
	}
}

// ── Labels composable ─────────────────────────────────────────────────────────
const { createLabel, updateLabel, deleteLabel } = useLabels(() => props.boardId)

// ── Review types composable ───────────────────────────────────────────────────
const { createReviewType, updateReviewType, deleteReviewType } = useReviewTypes(() => props.boardId)

// ── Card fields composable ────────────────────────────────────────────────────
const { createCardField, updateCardField, deleteCardField } = useCardFields(() => props.boardId)

// ── ACL composable ────────────────────────────────────────────────────────────
const {
	addAcl,
	patchAcl,
	removeAcl,
	searchQuery,
	searchResults,
	isSearching,
	searchError,
	clearSearch,
} = useAcl(() => props.boardId)

// ── Workflow composable ───────────────────────────────────────────────────────
const { data: boardQueryData, updateStack, updateBoard } = useBoard(computed(() => props.boardId))

// The viewer's board side from the board payload (#3744): export/duplicate
// are internal-only. Absent role (stale cache) reads as internal - the
// server 403s external egress regardless.
const isInternal = computed(() => (boardQueryData.value?.role ?? 'internal') !== 'external')

const ROLE_OPTIONS = [
	{ value: 0, label: t('kanso', 'None') },
	{ value: 1, label: t('kanso', 'Backlog') },
	{ value: 2, label: t('kanso', 'To do') },
	{ value: 3, label: t('kanso', 'In progress') },
	{ value: 4, label: t('kanso', 'Review') },
	{ value: 5, label: t('kanso', 'Done') },
]

// ── Estimation scale ──────────────────────────────────────────────────────────
const ESTIMATE_SCALE_OPTIONS = getScaleOptions()

const currentEstimateScale = computed(
	() => boardQueryData.value?.board?.estimateScale ?? 'none',
)

const estimateScaleSaving = ref(false)
const estimateScaleError = ref('')
// Bumping this remounts the native <select> so a cancelled change snaps its
// displayed option back to the persisted scale (a one-way :value bind alone
// won't reset the DOM when the reactive value is unchanged).
const scaleSelectKey = ref(0)

/**
 * How many live cards carry an estimate that the target scale would reject
 * (and therefore get cleared). Reads the board's card summaries already in
 * props — no extra request. Switching to 'none' rejects every estimate.
 * @param {string} newScale
 * @return {number}
 */
function countOffScaleEstimates(newScale) {
	const allowed = new Set(scaleTokens(newScale))
	return props.cards.filter(
		(c) => c && !c.archived && c.estimate && !allowed.has(c.estimate),
	).length
}

async function onEstimateScaleChange(newScale) {
	estimateScaleError.value = ''
	if (newScale === currentEstimateScale.value) return

	// Warn before a scale change that would strand existing estimates: the
	// backend clears them, so make the data loss explicit and reversible.
	const affected = countOffScaleEstimates(newScale)
	if (affected > 0) {
		const ok = await showConfirmation({
			name: t('kanso', 'Change estimation scale?'),
			text: t(
				'kanso',
				'{count} card(s) have an estimate that does not fit the new scale and will be cleared. This cannot be undone.',
				{ count: affected },
			),
			labelConfirm: t('kanso', 'Change and clear'),
			labelReject: t('kanso', 'Cancel'),
			severity: 'warning',
		})
		if (!ok) {
			scaleSelectKey.value++ // revert the <select> to the persisted scale
			return
		}
	}

	estimateScaleSaving.value = true
	try {
		await updateBoard.mutateAsync({ estimateScale: newScale })
	} catch (err) {
		estimateScaleError.value = err?.response?.data?.error || t('kanso', 'Failed to update estimation scale.')
		scaleSelectKey.value++ // a failed save shouldn't leave the wrong option shown
	} finally {
		estimateScaleSaving.value = false
	}
}

// ── New-cards-on-top (per-board) ─────────────────────────────────────────────
const newCardsOnTop = computed(() => boardQueryData.value?.board?.newCardsOnTop === true)
const newCardsOnTopSaving = ref(false)
const newCardsOnTopError = ref('')
async function onNewCardsOnTopChange(checked) {
	newCardsOnTopSaving.value = true
	newCardsOnTopError.value = ''
	// The switch is bound to the `newCardsOnTop` cache value (updateBoard does
	// not optimistically patch — it invalidates on settle), so a failed save
	// leaves the cache on its prior value and the toggle visibly reverts once
	// the invalidate refetch lands. Previously the rejection was swallowed
	// (unhandled) and no error surfaced; catch it and show the failure.
	try {
		await updateBoard.mutateAsync({ newCardsOnTop: checked })
	} catch (err) {
		newCardsOnTopError.value = err?.response?.data?.error || t('kanso', 'Failed to update setting.')
	} finally {
		newCardsOnTopSaving.value = false
	}
}

// ── Board background (per-board, #3528) ──────────────────────────────────────
// A curated preset gradient rendered behind the board view. Stored server-side
// as a preset KEY (BackgroundValidator allow-lists it); '' clears it. The cache
// value is the source of truth (updateBoard invalidates on settle).
const boardBackground = computed(() => boardQueryData.value?.board?.background || null)
const backgroundSaving = ref(false)
const backgroundError = ref('')
async function applyBackground(key) {
	// No-op if the current selection is re-picked.
	if ((boardBackground.value || '') === key) {
		return
	}
	backgroundError.value = ''
	backgroundSaving.value = true
	try {
		await updateBoard.mutateAsync({ background: key })
	} catch (err) {
		backgroundError.value = err?.response?.data?.error || t('kanso', 'Failed to update background.')
	} finally {
		backgroundSaving.value = false
	}
}

// ── Board name (rename) ──────────────────────────────────────────────────────
// Renaming lives here (not inline in the board header) so it's always reachable
// regardless of how cramped the header is. The draft mirrors the cache value and
// is re-seeded whenever the board payload changes (a save invalidates + refetches,
// and the ['boards'] list too, so the sidebar/command palette update live).
const boardTitle = computed(() => boardQueryData.value?.board?.title || '')
const nameDraft = ref('')
const nameSaving = ref(false)
const nameError = ref('')
watch(boardTitle, (val) => { nameDraft.value = val }, { immediate: true })
// Dirty when the trimmed draft is non-empty and differs from the stored title
// (an empty title is rejected server-side).
const nameDirty = computed(() => {
	const draft = nameDraft.value.trim()
	return draft !== '' && draft !== boardTitle.value
})
async function saveName() {
	if (!nameDirty.value) return
	nameSaving.value = true
	nameError.value = ''
	try {
		await updateBoard.mutateAsync({ title: nameDraft.value.trim() })
	} catch (err) {
		nameError.value = err?.response?.data?.error || t('kanso', 'Failed to rename board.')
	} finally {
		nameSaving.value = false
	}
}

// ── Card ID prefix (per-board) ───────────────────────────────────────────────
// The prefix is the shared half of every card's human id (PREFIX-<n>). Editing
// it only changes how existing cards are displayed; their assigned numbers are
// immutable. The draft mirrors the cache value and is re-seeded whenever the
// board payload changes (e.g. after a save invalidates + refetches).
const boardPrefix = computed(() => boardQueryData.value?.board?.prefix || '')
const prefixDraft = ref('')
const prefixSaving = ref(false)
const prefixError = ref('')
watch(boardPrefix, (val) => { prefixDraft.value = val }, { immediate: true })
// Dirty when the trimmed, uppercased draft differs from the stored prefix and is
// non-empty (an empty prefix is rejected server-side).
const prefixDirty = computed(() => {
	const draft = prefixDraft.value.trim().toUpperCase()
	return draft !== '' && draft !== boardPrefix.value
})
async function savePrefix() {
	if (!prefixDirty.value) return
	prefixSaving.value = true
	prefixError.value = ''
	try {
		await updateBoard.mutateAsync({ prefix: prefixDraft.value.trim() })
	} catch (err) {
		prefixError.value = err?.response?.data?.error || t('kanso', 'Failed to update prefix.')
	} finally {
		prefixSaving.value = false
	}
}

// ── Project chat link (per-board, #3748) ─────────────────────────────────────
// A plain http(s) URL (typically a Talk room) surfaced as a toolbar button for
// every member. MANAGE-only to set; the server enforces the same http/https
// allow-list. The draft mirrors the cache value and is re-seeded after a save
// invalidates + refetches. An empty save clears the link (button disappears).
const boardChatUrl = computed(() => boardQueryData.value?.board?.chatUrl || '')
const chatUrlDraft = ref('')
const chatUrlSaving = ref(false)
const chatUrlError = ref('')
watch(boardChatUrl, (val) => { chatUrlDraft.value = val }, { immediate: true })
const chatUrlDirty = computed(() => chatUrlDraft.value.trim() !== boardChatUrl.value)
async function saveChatUrl() {
	if (!chatUrlDirty.value) return
	const draft = chatUrlDraft.value.trim()
	// Mirror the server gate client-side for an inline error instead of a
	// round-trip: empty (= clear) or a plain absolute http(s) URL.
	if (draft !== '' && !/^https?:\/\/\S+$/i.test(draft)) {
		chatUrlError.value = t('kanso', 'The chat link must be a http:// or https:// URL.')
		return
	}
	chatUrlSaving.value = true
	chatUrlError.value = ''
	try {
		await updateBoard.mutateAsync({ chatUrl: draft })
	} catch (err) {
		chatUrlError.value = err?.response?.data?.error || t('kanso', 'Failed to update chat link.')
	} finally {
		chatUrlSaving.value = false
	}
}

// Archive the board: hides it from the list + nav (restorable from the boards
// page). Close the panel and return to the board list, since the board the user
// is on is now archived.
const archiveQueryClient = useQueryClient()
const archiving = ref(false)
async function archiveBoard() {
	archiving.value = true
	try {
		await updateBoard.mutateAsync({ archived: true })
		// The board-list uses the ['boards'] query; refresh it so the now-archived
		// board drops out of the active grid (into the Archived section).
		await archiveQueryClient.invalidateQueries({ queryKey: ['boards'] })
		emit('close')
		router.push({ name: 'board-list' })
	} finally {
		archiving.value = false
	}
}

// ── Export board to a downloadable .json file (shared composable) ─────────────
function exportBoardToFile() {
	return exportBoardAction(props.boardId)
}

const duplicateWithCards = ref(true)

/**
 * Server-side duplicate of this board into a fresh one the caller owns. On
 * success the board list is refreshed (by the composable), the settings modal
 * closed, and the router navigates to the new copy.
 */
async function duplicateBoardNow() {
	const res = await duplicateBoardAction(props.boardId, duplicateWithCards.value)
	if (res) {
		emit('close')
		router.push({ name: 'board', params: { id: res.boardId } })
	}
}

/**
 * Sorted active (non-archived) stacks for the Workflow tab.
 */
const sortedStacks = computed(() =>
	[...props.stacks]
		.filter((s) => !s.archived)
		.sort((a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0)),
)

/**
 * Per-stack workflow error state.
 * Key: stackId, value: error string.
 */
const workflowErrors = ref({})

/**
 * Per-stack saving state.
 */
const workflowSaving = ref({})

async function onRoleChange(stack, newRole) {
	workflowErrors.value = { ...workflowErrors.value, [stack.id]: '' }
	workflowSaving.value = { ...workflowSaving.value, [stack.id]: true }
	try {
		await updateStack.mutateAsync({ stackId: stack.id, data: { role: Number(newRole) } })
	} catch (err) {
		workflowErrors.value = {
			...workflowErrors.value,
			[stack.id]: err?.response?.data?.error || t('kanso', 'Failed to update role.'),
		}
	} finally {
		workflowSaving.value = { ...workflowSaving.value, [stack.id]: false }
	}
}

/**
 * WIP limit input: empty string → send null (no limit).
 * "0" also maps to null (backend treats both as unlimited).
 * Positive integer → send that integer.
 */
async function onWipLimitChange(stack, rawValue) {
	workflowErrors.value = { ...workflowErrors.value, [stack.id]: '' }
	const trimmed = String(rawValue).trim()
	// Treat empty or "0" as "no limit" → null
	const wipLimit = trimmed === '' || trimmed === '0' ? null : parseInt(trimmed, 10)
	if (trimmed !== '' && trimmed !== '0' && (isNaN(wipLimit) || wipLimit < 0)) {
		workflowErrors.value = {
			...workflowErrors.value,
			[stack.id]: t('kanso', 'WIP limit must be a positive number or empty.'),
		}
		return
	}
	workflowSaving.value = { ...workflowSaving.value, [stack.id]: true }
	try {
		await updateStack.mutateAsync({ stackId: stack.id, data: { wipLimit } })
	} catch (err) {
		workflowErrors.value = {
			...workflowErrors.value,
			[stack.id]: err?.response?.data?.error || t('kanso', 'Failed to update WIP limit.'),
		}
	} finally {
		workflowSaving.value = { ...workflowSaving.value, [stack.id]: false }
	}
}

/**
 * Convert the stack's current wipLimit to a display string for the input.
 * null and 0 → empty string (no limit).
 */
function wipLimitDisplay(stack) {
	const wl = stack.wipLimit
	if (wl == null || wl === 0) return ''
	return String(wl)
}

// ── Color presets (shared palette so every label creator matches) ──────────────
const COLOR_PRESETS = LABEL_COLOR_PRESETS

// ── Label: create state ───────────────────────────────────────────────────────
const newTitle = ref('')
const newColor = ref('')
const isCreating = ref(false)
const createError = ref('')
const showNewColorPicker = ref(false)

async function submitCreate() {
	const title = newTitle.value.trim()
	if (!title) return
	isCreating.value = true
	createError.value = ''
	showNewColorPicker.value = false
	try {
		await createLabel.mutateAsync({ title, color: newColor.value || null })
		newTitle.value = ''
		newColor.value = ''
	} catch (err) {
		createError.value = err?.response?.data?.error || t('kanso', 'Failed to create label.')
	} finally {
		isCreating.value = false
	}
}

// ── Label: rename state ───────────────────────────────────────────────────────
const editingLabelId = ref(null)
const editingTitle = ref('')
const labelError = ref({})
const editRefs = {}

function setEditRef(id, el) {
	if (el) {
		editRefs[id] = el
	} else {
		delete editRefs[id]
	}
}

async function startRename(label) {
	colorPickerFor.value = null
	editingLabelId.value = label.id
	editingTitle.value = label.title
	labelError.value = { ...labelError.value, [label.id]: '' }
	await nextTick()
	editRefs[label.id]?.focus()
	editRefs[label.id]?.select()
}

function cancelRename() {
	editingLabelId.value = null
}

async function saveRename(label) {
	const title = editingTitle.value.trim()
	editingLabelId.value = null
	if (!title || title === label.title) return
	try {
		await updateLabel.mutateAsync({ labelId: label.id, title })
	} catch (err) {
		labelError.value = {
			...labelError.value,
			[label.id]: err?.response?.data?.error || t('kanso', 'Failed to rename label.'),
		}
	}
}

// ── Label: color picker state ─────────────────────────────────────────────────
const colorPickerFor = ref(null)

function openColorPicker(label) {
	colorPickerFor.value = colorPickerFor.value === label.id ? null : label.id
}

// Escape while a colour popover is open closes just the popover — stop it from
// bubbling to the modal's Escape handler, which would otherwise close the whole
// settings sidebar. When no popover is open, let Escape bubble (default close).
function onColorPickerEscape(event) {
	if (showNewColorPicker.value || colorPickerFor.value !== null || rtColorPickerFor.value !== null) {
		event.stopPropagation()
		showNewColorPicker.value = false
		colorPickerFor.value = null
		rtColorPickerFor.value = null
	}
}

async function applyColor(label, color) {
	colorPickerFor.value = null
	if (color === label.color) return
	try {
		await updateLabel.mutateAsync({ labelId: label.id, color })
	} catch (err) {
		labelError.value = {
			...labelError.value,
			[label.id]: err?.response?.data?.error || t('kanso', 'Failed to update color.'),
		}
	}
}

// ── Label: delete state ───────────────────────────────────────────────────────
const confirmDeleteLabelId = ref(null)
const isDeletingLabel = ref(false)
const deleteLabelError = ref('')

function confirmDeleteLabel(label) {
	confirmDeleteLabelId.value = label.id
	deleteLabelError.value = ''
}

async function doDeleteLabel(label) {
	isDeletingLabel.value = true
	deleteLabelError.value = ''
	try {
		await deleteLabel.mutateAsync({ labelId: label.id })
		confirmDeleteLabelId.value = null
	} catch (err) {
		deleteLabelError.value = err?.response?.data?.error || t('kanso', 'Failed to delete label.')
	} finally {
		isDeletingLabel.value = false
	}
}

// ── Review types: create state ────────────────────────────────────────────────
const newRtTitle = ref('')
const newRtColor = ref('')
const newRtStage = ref(0)
const isCreatingRt = ref(false)
const createRtError = ref('')
const showNewRtColorPicker = ref(false)

async function submitCreateRt() {
	const title = newRtTitle.value.trim()
	if (!title) return
	isCreatingRt.value = true
	createRtError.value = ''
	showNewRtColorPicker.value = false
	try {
		await createReviewType.mutateAsync({
			title,
			color: newRtColor.value || null,
			stage: Math.max(0, Number(newRtStage.value) || 0),
		})
		newRtTitle.value = ''
		newRtColor.value = ''
		newRtStage.value = 0
	} catch (err) {
		createRtError.value = err?.response?.data?.error || t('kanso', 'Failed to create review type.')
	} finally {
		isCreatingRt.value = false
	}
}

// ── Review types: rename state ────────────────────────────────────────────────
const editingRtId = ref(null)
const editingRtTitle = ref('')
const rtError = ref({})
const rtEditRefs = {}

function setRtEditRef(id, el) {
	if (el) {
		rtEditRefs[id] = el
	} else {
		delete rtEditRefs[id]
	}
}

async function startRtRename(rt) {
	rtColorPickerFor.value = null
	editingRtId.value = rt.id
	editingRtTitle.value = rt.title
	rtError.value = { ...rtError.value, [rt.id]: '' }
	await nextTick()
	rtEditRefs[rt.id]?.focus()
	rtEditRefs[rt.id]?.select()
}

function cancelRtRename() {
	editingRtId.value = null
}

async function saveRtRename(rt) {
	const title = editingRtTitle.value.trim()
	editingRtId.value = null
	if (!title || title === rt.title) return
	try {
		await updateReviewType.mutateAsync({ typeId: rt.id, title })
	} catch (err) {
		rtError.value = {
			...rtError.value,
			[rt.id]: err?.response?.data?.error || t('kanso', 'Failed to rename review type.'),
		}
	}
}

// ── Review types: stage edit ──────────────────────────────────────────────────
// Lower stages gate higher ones: a review is held (its reviewer un-notified,
// chip greyed) while the card carries an unapproved review of a lower stage.
async function saveRtStage(rt, event) {
	const next = Math.max(0, Number(event.target.value) || 0)
	// Reflect the clamped value back into the input.
	event.target.value = String(next)
	if (next === (rt.stage ?? 0)) return
	try {
		await updateReviewType.mutateAsync({ typeId: rt.id, stage: next })
	} catch (err) {
		rtError.value = {
			...rtError.value,
			[rt.id]: err?.response?.data?.error || t('kanso', 'Failed to update stage.'),
		}
	}
}

// ── Review types: color picker state ─────────────────────────────────────────
const rtColorPickerFor = ref(null)

function openRtColorPicker(rt) {
	rtColorPickerFor.value = rtColorPickerFor.value === rt.id ? null : rt.id
}

async function applyRtColor(rt, color) {
	rtColorPickerFor.value = null
	if (color === rt.color) return
	try {
		await updateReviewType.mutateAsync({ typeId: rt.id, color })
	} catch (err) {
		rtError.value = {
			...rtError.value,
			[rt.id]: err?.response?.data?.error || t('kanso', 'Failed to update color.'),
		}
	}
}

// ── Review types: delete state ────────────────────────────────────────────────
const confirmDeleteRtId = ref(null)
const isDeletingRt = ref(false)
const deleteRtError = ref('')

function confirmDeleteRt(rt) {
	confirmDeleteRtId.value = rt.id
	deleteRtError.value = ''
}

async function doDeleteRt(rt) {
	isDeletingRt.value = true
	deleteRtError.value = ''
	try {
		await deleteReviewType.mutateAsync({ typeId: rt.id })
		confirmDeleteRtId.value = null
	} catch (err) {
		deleteRtError.value = err?.response?.data?.error || t('kanso', 'Failed to delete review type.')
	} finally {
		isDeletingRt.value = false
	}
}

// ── Card fields: create state ─────────────────────────────────────────────────
const newCfName = ref('')
const newCfType = ref('text')
const newCfOptionsRaw = ref('')
const isCreatingCf = ref(false)
const createCfError = ref('')

/** Parse the raw options textarea into a trimmed, deduplicated string array. */
function parseCfOptions(raw) {
	return raw
		.split(/[\n,]+/)
		.map((s) => s.trim())
		.filter(Boolean)
}

async function submitCreateCf() {
	const name = newCfName.value.trim()
	if (!name) return
	isCreatingCf.value = true
	createCfError.value = ''
	try {
		const options = newCfType.value === 'select' ? parseCfOptions(newCfOptionsRaw.value) : undefined
		await createCardField.mutateAsync({ name, type: newCfType.value, options })
		newCfName.value = ''
		newCfType.value = 'text'
		newCfOptionsRaw.value = ''
	} catch (err) {
		createCfError.value = err?.response?.data?.error || t('kanso', 'Failed to create custom field.')
	} finally {
		isCreatingCf.value = false
	}
}

// ── Card fields: rename state ─────────────────────────────────────────────────
const editingCfId = ref(null)
const editingCfName = ref('')
const cfError = ref({})
const cfEditRefs = {}

function setCfEditRef(id, el) {
	if (el) {
		cfEditRefs[id] = el
	} else {
		delete cfEditRefs[id]
	}
}

async function startCfRename(field) {
	editingCfId.value = field.id
	editingCfName.value = field.name
	cfError.value = { ...cfError.value, [field.id]: '' }
	await nextTick()
	cfEditRefs[field.id]?.focus()
	cfEditRefs[field.id]?.select()
}

function cancelCfRename() {
	editingCfId.value = null
}

async function saveCfRename(field) {
	const name = editingCfName.value.trim()
	editingCfId.value = null
	if (!name || name === field.name) return
	try {
		await updateCardField.mutateAsync({ fieldId: field.id, name })
	} catch (err) {
		cfError.value = {
			...cfError.value,
			[field.id]: err?.response?.data?.error || t('kanso', 'Failed to rename field.'),
		}
	}
}

// ── Card fields: options editor (select fields only) ─────────────────────────
const editingCfOptionsId = ref(null)
const editingCfOptions = ref('')

function startCfOptionsEdit(field) {
	editingCfOptionsId.value = field.id
	editingCfOptions.value = Array.isArray(field.options) ? field.options.join('\n') : ''
}

async function saveCfOptions(field) {
	const options = parseCfOptions(editingCfOptions.value)
	editingCfOptionsId.value = null
	try {
		await updateCardField.mutateAsync({ fieldId: field.id, options })
	} catch (err) {
		cfError.value = {
			...cfError.value,
			[field.id]: err?.response?.data?.error || t('kanso', 'Failed to update options.'),
		}
	}
}

// ── Card fields: delete state ─────────────────────────────────────────────────
const confirmDeleteCfId = ref(null)
const isDeletingCf = ref(false)
const deleteCfError = ref('')

function confirmDeleteCf(field) {
	confirmDeleteCfId.value = field.id
	deleteCfError.value = ''
}

async function doDeleteCf(field) {
	isDeletingCf.value = true
	deleteCfError.value = ''
	try {
		await deleteCardField.mutateAsync({ fieldId: field.id })
		confirmDeleteCfId.value = null
	} catch (err) {
		deleteCfError.value = err?.response?.data?.error || t('kanso', 'Failed to delete field.')
	} finally {
		isDeletingCf.value = false
	}
}

// ── ACL: sharee search + add ──────────────────────────────────────────────────
const addAclError = ref('')

async function pickSharee(result) {
	clearSearch()
	addAclError.value = ''
	try {
		// Default permission: READ | EDIT (3)
		await addAcl.mutateAsync({
			participant: result.id,
			participantType: result.type,
			permission: PERM_READ | PERM_EDIT,
		})
	} catch (err) {
		addAclError.value = err?.response?.data?.error || t('kanso', 'Failed to share board.')
	}
}

// ── ACL: permission toggles ───────────────────────────────────────────────────
const patchingAclId = ref(null)
const patchAclErrors = ref({})

async function togglePerm(entry, bit, checked) {
	patchAclErrors.value = { ...patchAclErrors.value, [entry.id]: '' }
	const newPerm = checked
		? (entry.permission | bit)
		: (entry.permission & ~bit)
	// READ is always forced on by server; keep client consistent
	const finalPerm = newPerm | PERM_READ
	patchingAclId.value = entry.id
	try {
		await patchAcl.mutateAsync({ aclId: entry.id, permission: finalPerm })
	} catch (err) {
		patchAclErrors.value = {
			...patchAclErrors.value,
			[entry.id]: err?.response?.data?.error || t('kanso', 'Failed to update permission.'),
		}
	} finally {
		patchingAclId.value = null
	}
}

// ── ACL: board side (internal/external, #3742) ────────────────────────────────

/**
 * Re-assign a member's board side. MANAGE-gated (the selector only renders
 * for managers); the permission mask is re-submitted unchanged so the
 * escalation cap sees zero flipped bits.
 */
async function changeRole(entry, role) {
	if (role === (entry.role || 'internal')) return
	patchAclErrors.value = { ...patchAclErrors.value, [entry.id]: '' }
	patchingAclId.value = entry.id
	try {
		await patchAcl.mutateAsync({ aclId: entry.id, permission: entry.permission, role })
	} catch (err) {
		patchAclErrors.value = {
			...patchAclErrors.value,
			[entry.id]: err?.response?.data?.error || t('kanso', 'Failed to update role.'),
		}
	} finally {
		patchingAclId.value = null
	}
}

// ── ACL: remove entry ─────────────────────────────────────────────────────────
const confirmRemoveAclId = ref(null)
const isRemovingAcl = ref(false)
const removeAclError = ref('')

function confirmRemoveAcl(entry) {
	confirmRemoveAclId.value = entry.id
	removeAclError.value = ''
}

async function doRemoveAcl(entry) {
	isRemovingAcl.value = true
	removeAclError.value = ''
	try {
		await removeAcl.mutateAsync({ aclId: entry.id })
		confirmRemoveAclId.value = null
	} catch (err) {
		removeAclError.value = err?.response?.data?.error || t('kanso', 'Failed to remove share.')
	} finally {
		isRemovingAcl.value = false
	}
}

// ── ACL: leave board ──────────────────────────────────────────────────────────
const router = useRouter()
const confirmLeave = ref(false)
const isLeaving = ref(false)
const leaveError = ref('')

/**
 * The current user's own ACL entry (user-type, participant === currentUserId).
 * Shown only when such an entry exists - i.e., the user is a sharee, not the owner.
 */
const ownEntry = computed(() =>
	props.acl.find(
		(e) => e.participantType === 'user' && e.participant === props.currentUserId,
	),
)

async function doLeave() {
	if (!ownEntry.value) return
	isLeaving.value = true
	leaveError.value = ''
	try {
		await removeAcl.mutateAsync({ aclId: ownEntry.value.id })
		emit('leave')
		router.push({ name: 'board-list' })
	} catch (err) {
		leaveError.value = err?.response?.data?.error || t('kanso', 'Failed to leave board.')
		isLeaving.value = false
	}
}

// ── Display name resolution ───────────────────────────────────────────────────
/**
 * Try to resolve a human display name for an ACL entry.
 * For users: look up in the participants list first; fall back to uid.
 * For groups: use the participant value (gid) - no richer source available.
 */
function resolveDisplayName(entry) {
	if (entry.participantType === 'user') {
		const found = props.participants.find((p) => p.uid === entry.participant)
		if (found?.displayName) return found.displayName
		return entry.participant
	}
	return entry.participant
}

// ── Automation tab: archive rules ─────────────────────────────────────────────

const {
	data: archiveRulesData,
	isLoading: archiveRulesLoading,
	isError: archiveRulesError,
	createRule,
	updateRule,
	deleteRule,
	archiveNow: archiveNowMutation,
} = useArchiveRules(computed(() => props.boardId))

/**
 * Expose the query object as a reactive handle for the template's
 * isLoading / isError checks (the spread above exposes the raw refs).
 */
const archiveRulesQuery = {
	isLoading: archiveRulesLoading,
	isError: archiveRulesError,
}

const archiveRules = computed(() => archiveRulesData.value ?? [])

/** Active (non-archived) stacks for the scope selector. */
const activeStacks = computed(() =>
	[...props.stacks]
		.filter((s) => !s.archived)
		.sort((a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0)),
)

/** Convert thresholdSeconds → display days (rounded, minimum 0). */
function secondsToDays(secs) {
	return Math.max(0, Math.round(secs / 86400))
}

/** Resolve a stackId to its title; falls back to the raw id string. */
function resolveStackName(stackId) {
	const stack = props.stacks.find((s) => s.id === stackId)
	return stack?.title ?? String(stackId)
}

// ── Create rule form state ────────────────────────────────────────────────────
const newRuleStackId = ref(null)   // null = whole board
const newRuleCondition = ref(0)    // 0 = done for ≥N, 1 = done AND created ≥N
const newRuleDays = ref(0)
const isCreatingRule = ref(false)
const createRuleError = ref('')

async function submitCreateRule() {
	// Reject a blank field ('' * 86400 === 0) so a rule that archives every
	// done card immediately can't be created by accident - 0 must be explicit.
	if (newRuleDays.value === '' || newRuleDays.value === null || newRuleDays.value < 0) return
	isCreatingRule.value = true
	createRuleError.value = ''
	try {
		const data = {
			condition: newRuleCondition.value,
			thresholdSeconds: newRuleDays.value * 86400,
			enabled: true,
		}
		// Only include stackId when a specific stack is selected.
		// Omitting it (not sending the key at all) → whole board.
		if (newRuleStackId.value !== null) {
			data.stackId = newRuleStackId.value
		}
		await createRule.mutateAsync(data)
		// Reset form
		newRuleStackId.value = null
		newRuleCondition.value = 0
		newRuleDays.value = 0
	} catch (err) {
		createRuleError.value = err?.response?.data?.error || t('kanso', 'Failed to create rule.')
	} finally {
		isCreatingRule.value = false
	}
}

// ── Toggle enable/disable ─────────────────────────────────────────────────────
const togglingRuleId = ref(null)
const toggleRuleErrors = ref({})

async function toggleRuleEnabled(rule, enabled) {
	togglingRuleId.value = rule.id
	toggleRuleErrors.value = { ...toggleRuleErrors.value, [rule.id]: '' }
	try {
		// PATCH with only `enabled`; omit stackId entirely to keep existing scope.
		await updateRule.mutateAsync({ id: rule.id, data: { enabled } })
	} catch (err) {
		toggleRuleErrors.value = {
			...toggleRuleErrors.value,
			[rule.id]: err?.response?.data?.error || t('kanso', 'Failed to update rule.'),
		}
	} finally {
		togglingRuleId.value = null
	}
}

// ── Archive now ───────────────────────────────────────────────────────────────
const archivingRuleId = ref(null)
const archiveNowResults = ref({})  // Map<ruleId, archivedCount>
const archiveNowErrors = ref({})

async function doArchiveNow(rule) {
	archivingRuleId.value = rule.id
	archiveNowErrors.value = { ...archiveNowErrors.value, [rule.id]: '' }
	// Clear previous result so the count resets when triggering again
	const { [rule.id]: _prev, ...rest } = archiveNowResults.value
	archiveNowResults.value = rest
	try {
		const result = await archiveNowMutation.mutateAsync(rule.id)
		// result = { archived: N }
		archiveNowResults.value = { ...archiveNowResults.value, [rule.id]: result.archived ?? 0 }
	} catch (err) {
		archiveNowErrors.value = {
			...archiveNowErrors.value,
			[rule.id]: err?.response?.data?.error || t('kanso', 'Failed to run archive.'),
		}
	} finally {
		archivingRuleId.value = null
	}
}

// ── Delete rule ───────────────────────────────────────────────────────────────
const confirmDeleteRuleId = ref(null)
const isDeletingRule = ref(false)
const deleteRuleError = ref('')

function confirmDeleteRule(rule) {
	confirmDeleteRuleId.value = rule.id
	deleteRuleError.value = ''
}

async function doDeleteRule(rule) {
	isDeletingRule.value = true
	deleteRuleError.value = ''
	try {
		await deleteRule.mutateAsync(rule.id)
		confirmDeleteRuleId.value = null
	} catch (err) {
		deleteRuleError.value = err?.response?.data?.error || t('kanso', 'Failed to delete rule.')
	} finally {
		isDeletingRule.value = false
	}
}

// ── Automation tab: recur rules ───────────────────────────────────────────────

const {
	data: recurRulesData,
	isLoading: recurRulesLoading,
	isError: recurRulesError,
	createRule: createRecurRule,
	updateRule: updateRecurRule,
	deleteRule: deleteRecurRule,
	createNow: createNowMutation,
} = useRecurRules(computed(() => props.boardId))

const recurRulesQuery = {
	isLoading: recurRulesLoading,
	isError: recurRulesError,
}

const recurRules = computed(() => recurRulesData.value ?? [])

/** Resolve a templateCardId to its title; falls back to the raw id string. */
function resolveCardTitle(cardId) {
	const card = props.cards.find((c) => c.id === cardId)
	return card?.title ?? String(cardId)
}

/**
 * Format a unix timestamp in SECONDS (the units the recur-rule API emits for
 * nextOccurrenceAt / lastSpawnedAt) to a short locale date. Passing seconds to
 * `new Date()` unmultiplied lands in Jan 1970, which is the bug this fixes.
 */
function formatDate(epochSeconds) {
	const sec = Number(epochSeconds)
	if (!sec) return ''
	try {
		return new Date(sec * 1000).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
	} catch {
		return ''
	}
}

/**
 * Build a human-readable summary of an RFC5545 RRULE string.
 * Handles the FREQ/INTERVAL/BYDAY/COUNT/UNTIL subset we emit.
 */
function humanRrule(rrule) {
	if (!rrule) return ''
	const parts = {}
	for (const seg of rrule.split(';')) {
		const eq = seg.indexOf('=')
		if (eq === -1) continue
		parts[seg.slice(0, eq).toUpperCase()] = seg.slice(eq + 1)
	}

	const freq = parts['FREQ'] ?? ''
	const interval = parseInt(parts['INTERVAL'] ?? '1', 10)
	const byday = parts['BYDAY'] ?? ''
	const count = parts['COUNT'] ? parseInt(parts['COUNT'], 10) : null
	const until = parts['UNTIL'] ?? ''

	// Frequency + interval phrase
	const DAY_MAP = { MO: t('kanso', 'Mon'), TU: t('kanso', 'Tue'), WE: t('kanso', 'Wed'), TH: t('kanso', 'Thu'), FR: t('kanso', 'Fri'), SA: t('kanso', 'Sat'), SU: t('kanso', 'Sun') }
	let freqPhrase = ''
	if (freq === 'DAILY') {
		freqPhrase = interval === 1 ? t('kanso', 'Every day') : t('kanso', 'Every {n} days', { n: interval })
	} else if (freq === 'WEEKLY') {
		const base = interval === 1 ? t('kanso', 'Every week') : t('kanso', 'Every {n} weeks', { n: interval })
		if (byday) {
			const dayNames = byday.split(',').map((d) => DAY_MAP[d.trim()] ?? d).join(', ')
			freqPhrase = t('kanso', '{base} on {days}', { base, days: dayNames })
		} else {
			freqPhrase = base
		}
	} else if (freq === 'MONTHLY') {
		freqPhrase = interval === 1 ? t('kanso', 'Every month') : t('kanso', 'Every {n} months', { n: interval })
	} else if (freq === 'YEARLY') {
		freqPhrase = interval === 1 ? t('kanso', 'Every year') : t('kanso', 'Every {n} years', { n: interval })
	} else {
		freqPhrase = rrule
	}

	// End condition suffix
	let endPhrase = ''
	if (count !== null) {
		endPhrase = t('kanso', '· {n} times', { n: count })
	} else if (until) {
		// UNTIL is YYYYMMDDTHHMMSSZ - parse to a readable date
		const y = until.slice(0, 4)
		const m = until.slice(4, 6)
		const d = until.slice(6, 8)
		try {
			const dt = new Date(`${y}-${m}-${d}`)
			endPhrase = t('kanso', '· until {date}', { date: dt.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) })
		} catch {
			endPhrase = t('kanso', '· until {date}', { date: `${y}-${m}-${d}` })
		}
	}

	return endPhrase ? `${freqPhrase} ${endPhrase}` : freqPhrase
}

// ── Recur rule: enable/disable toggle ────────────────────────────────────────
const togglingRecurRuleId = ref(null)
const toggleRecurRuleErrors = ref({})

async function toggleRecurRuleEnabled(rule, enabled) {
	togglingRecurRuleId.value = rule.id
	toggleRecurRuleErrors.value = { ...toggleRecurRuleErrors.value, [rule.id]: '' }
	try {
		await updateRecurRule.mutateAsync({ id: rule.id, data: { enabled } })
	} catch (err) {
		toggleRecurRuleErrors.value = {
			...toggleRecurRuleErrors.value,
			[rule.id]: err?.response?.data?.error || t('kanso', 'Failed to update rule.'),
		}
	} finally {
		togglingRecurRuleId.value = null
	}
}

// ── Recur rule: create now ────────────────────────────────────────────────────
const creatingNowRuleId = ref(null)
const createNowResults = ref({})   // Map<ruleId, true> - show "Created" flash
const createNowErrors = ref({})

async function doCreateNow(rule) {
	creatingNowRuleId.value = rule.id
	createNowErrors.value = { ...createNowErrors.value, [rule.id]: '' }
	// Clear previous result
	const { [rule.id]: _prev, ...rest } = createNowResults.value
	createNowResults.value = rest
	try {
		await createNowMutation.mutateAsync(rule.id)
		createNowResults.value = { ...createNowResults.value, [rule.id]: true }
		// Clear the "Created" flash after 3 seconds
		setTimeout(() => {
			const { [rule.id]: _r, ...remaining } = createNowResults.value
			createNowResults.value = remaining
		}, 3000)
	} catch (err) {
		createNowErrors.value = {
			...createNowErrors.value,
			[rule.id]: err?.response?.data?.error || t('kanso', 'Failed to create card.'),
		}
	} finally {
		creatingNowRuleId.value = null
	}
}

// ── Recur rule: delete ────────────────────────────────────────────────────────
const confirmDeleteRecurRuleId = ref(null)
const isDeletingRecurRule = ref(false)
const deleteRecurRuleError = ref('')

function confirmDeleteRecurRule(rule) {
	confirmDeleteRecurRuleId.value = rule.id
	deleteRecurRuleError.value = ''
}

async function doDeleteRecurRule(rule) {
	isDeletingRecurRule.value = true
	deleteRecurRuleError.value = ''
	try {
		await deleteRecurRule.mutateAsync(rule.id)
		confirmDeleteRecurRuleId.value = null
	} catch (err) {
		deleteRecurRuleError.value = err?.response?.data?.error || t('kanso', 'Failed to delete rule.')
	} finally {
		isDeletingRecurRule.value = false
	}
}

// ── RRULE builder constants ───────────────────────────────────────────────────
const WEEKDAY_OPTIONS = [
	{ value: 'MO', label: t('kanso', 'Mon') },
	{ value: 'TU', label: t('kanso', 'Tue') },
	{ value: 'WE', label: t('kanso', 'Wed') },
	{ value: 'TH', label: t('kanso', 'Thu') },
	{ value: 'FR', label: t('kanso', 'Fri') },
	{ value: 'SA', label: t('kanso', 'Sat') },
	{ value: 'SU', label: t('kanso', 'Sun') },
]

// ── Recur rule: add-rule form state ──────────────────────────────────────────
const newRecurTemplateCardId = ref(null)
const newRecurTargetStackId = ref(null)
const newRecurMode = ref(0)              // 0=clone, 1=reset
const newRecurFreq = ref('WEEKLY')       // DAILY/WEEKLY/MONTHLY/YEARLY
const newRecurInterval = ref(1)
const newRecurWeekdays = ref([])         // e.g. ['MO', 'WE']
const newRecurEndType = ref('forever')   // 'forever' | 'count' | 'until'
const newRecurCount = ref(10)
const newRecurUntil = ref('')            // YYYY-MM-DD input
const newRecurDuedatePolicy = ref(0)     // 0=at occurrence, 1=offset after, 2=none
const newRecurDuedateOffsetDays = ref(1)
const newRecurSkipWhileOpen = ref(false)
const isCreatingRecurRule = ref(false)
const createRecurRuleError = ref('')

/** Build the RFC5545 RRULE string from the builder controls. */
function buildRrule() {
	const parts = [`FREQ=${newRecurFreq.value}`]
	if (newRecurInterval.value > 1) parts.push(`INTERVAL=${newRecurInterval.value}`)
	if (newRecurFreq.value === 'WEEKLY' && newRecurWeekdays.value.length > 0) {
		parts.push(`BYDAY=${newRecurWeekdays.value.join(',')}`)
	}
	if (newRecurEndType.value === 'count') {
		parts.push(`COUNT=${newRecurCount.value}`)
	} else if (newRecurEndType.value === 'until' && newRecurUntil.value) {
		// Convert YYYY-MM-DD → YYYYMMDDТ000000Z
		const d = newRecurUntil.value.replace(/-/g, '')
		parts.push(`UNTIL=${d}T000000Z`)
	}
	return parts.join(';')
}

/** Non-archived cards for the template selector. */
const activeCards = computed(() =>
	props.cards.filter((c) => !c.archived),
)

async function submitCreateRecurRule() {
	if (!newRecurTemplateCardId.value || !newRecurTargetStackId.value) return
	isCreatingRecurRule.value = true
	createRecurRuleError.value = ''
	try {
		const data = {
			templateCardId: newRecurTemplateCardId.value,
			targetStackId: newRecurTargetStackId.value,
			mode: newRecurMode.value,
			rrule: buildRrule(),
			duedatePolicy: newRecurDuedatePolicy.value,
			enabled: true,
		}
		if (newRecurDuedatePolicy.value === 1) {
			data.duedateOffsetSeconds = newRecurDuedateOffsetDays.value * 86400
		}
		if (newRecurMode.value === 0) {
			data.skipWhileOpen = newRecurSkipWhileOpen.value
		}
		await createRecurRule.mutateAsync(data)
		// Reset form
		newRecurTemplateCardId.value = null
		newRecurTargetStackId.value = null
		newRecurMode.value = 0
		newRecurFreq.value = 'WEEKLY'
		newRecurInterval.value = 1
		newRecurWeekdays.value = []
		newRecurEndType.value = 'forever'
		newRecurCount.value = 10
		newRecurUntil.value = ''
		newRecurDuedatePolicy.value = 0
		newRecurDuedateOffsetDays.value = 1
		newRecurSkipWhileOpen.value = false
	} catch (err) {
		createRecurRuleError.value = err?.response?.data?.error || t('kanso', 'Failed to create rule.')
	} finally {
		isCreatingRecurRule.value = false
	}
}

/** Toggle a weekday in the multi-select. */
function toggleWeekday(day) {
	const idx = newRecurWeekdays.value.indexOf(day)
	if (idx === -1) {
		newRecurWeekdays.value = [...newRecurWeekdays.value, day]
	} else {
		newRecurWeekdays.value = newRecurWeekdays.value.filter((d) => d !== day)
	}
}

// ── Automation tab: card rules (trigger → action) ────────────────────────────

const {
	data: autoRulesData,
	isLoading: autoRulesLoading,
	isError: autoRulesError,
	createRule: createAutoRule,
	setEnabled: setAutoRuleEnabled,
	deleteRule: deleteAutoRule,
} = useAutomationRules(computed(() => props.boardId))

const autoRulesQuery = { isLoading: autoRulesLoading, isError: autoRulesError }
const autoRules = computed(() => autoRulesData.value ?? [])

// Trigger role choices - exclude "None" (a roleless stack never fires a rule).
const AUTO_ROLE_OPTIONS = ROLE_OPTIONS.filter((o) => o.value !== 0)

/** Reviewer candidates: board participants (user ACL entries) plus the current user. */
const reviewerOptions = computed(() => {
	const seen = new Map()
	for (const entry of props.acl) {
		if (entry.participantType === 'user' && !seen.has(entry.participant)) {
			seen.set(entry.participant, resolveDisplayName(entry))
		}
	}
	if (props.currentUserId && !seen.has(props.currentUserId)) {
		seen.set(props.currentUserId, props.currentUserId)
	}
	return [...seen.entries()].map(([uid, name]) => ({ uid, name }))
})

function roleLabelFor(role) {
	return ROLE_OPTIONS.find((o) => o.value === role)?.label ?? String(role)
}

/** Human-readable "When a card enters <role> → <action>" summary. */
function describeAutoRule(rule) {
	const when = t('kanso', 'When a card enters {role}', { role: roleLabelFor(rule.params?.role) })
	if (rule.action === 'request_review') {
		const name = reviewerOptions.value.find((p) => p.uid === rule.params?.reviewer)?.name ?? rule.params?.reviewer
		return t('kanso', '{when} → request a review from {name}', { when, name })
	}
	if (rule.action === 'add_label') {
		const label = props.labels.find((l) => l.id === rule.params?.label)?.title ?? String(rule.params?.label)
		return t('kanso', '{when} → add label "{label}"', { when, label })
	}
	return when
}

// Create form state
const newAutoRole = ref(AUTO_ROLE_OPTIONS[0].value)
const newAutoAction = ref('request_review')
const newAutoReviewer = ref('')
const newAutoLabel = ref(null)
const isCreatingAutoRule = ref(false)
const createAutoRuleError = ref('')

const canSubmitAutoRule = computed(() => {
	if (newAutoAction.value === 'request_review') return !!newAutoReviewer.value
	if (newAutoAction.value === 'add_label') return newAutoLabel.value !== null
	return false
})

async function submitCreateAutoRule() {
	if (!canSubmitAutoRule.value) return
	isCreatingAutoRule.value = true
	createAutoRuleError.value = ''
	try {
		const params = { role: newAutoRole.value }
		if (newAutoAction.value === 'request_review') {
			params.reviewer = newAutoReviewer.value
		} else {
			params.label = newAutoLabel.value
		}
		await createAutoRule.mutateAsync({
			trigger: 'card_entered_role',
			action: newAutoAction.value,
			params,
		})
		// Reset the value-carrying fields; keep role/action selections.
		newAutoReviewer.value = ''
		newAutoLabel.value = null
	} catch (err) {
		createAutoRuleError.value = err?.response?.data?.error || t('kanso', 'Failed to create rule.')
	} finally {
		isCreatingAutoRule.value = false
	}
}

// Enable/disable toggle
const togglingAutoRuleId = ref(null)
const toggleAutoRuleErrors = ref({})

async function toggleAutoRuleEnabled(rule, enabled) {
	togglingAutoRuleId.value = rule.id
	toggleAutoRuleErrors.value = { ...toggleAutoRuleErrors.value, [rule.id]: '' }
	try {
		await setAutoRuleEnabled.mutateAsync({ id: rule.id, enabled })
	} catch (err) {
		toggleAutoRuleErrors.value = {
			...toggleAutoRuleErrors.value,
			[rule.id]: err?.response?.data?.error || t('kanso', 'Failed to update rule.'),
		}
	} finally {
		togglingAutoRuleId.value = null
	}
}

// Delete
const confirmDeleteAutoRuleId = ref(null)
const isDeletingAutoRule = ref(false)
const deleteAutoRuleError = ref('')

function confirmDeleteAutoRule(rule) {
	confirmDeleteAutoRuleId.value = rule.id
	deleteAutoRuleError.value = ''
}

async function doDeleteAutoRule(rule) {
	isDeletingAutoRule.value = true
	deleteAutoRuleError.value = ''
	try {
		await deleteAutoRule.mutateAsync(rule.id)
		confirmDeleteAutoRuleId.value = null
	} catch (err) {
		deleteAutoRuleError.value = err?.response?.data?.error || t('kanso', 'Failed to delete rule.')
	} finally {
		isDeletingAutoRule.value = false
	}
}
</script>

<style scoped>
/* ── Card ID prefix field ─────────────────────────────────────────────────── */
.board-settings__prefix-label {
	display: block;
	margin-top: 12px;
	margin-bottom: 4px;
	font-weight: 600;
}
.board-settings__prefix-row {
	display: flex;
	align-items: center;
	gap: 8px;
}
.board-settings__prefix-input {
	width: 120px;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	font-family: var(--font-face-monospace, monospace);
}

/* ── Project chat link (#3748) ────────────────────────────────────────────── */
.board-settings__chat-url-input {
	flex: 1;
	min-width: 0;
}

/* ── Board background palette (#3528) ─────────────────────────────────────── */
.board-settings__bg-label {
	display: block;
	margin-top: 16px;
	margin-bottom: 6px;
	font-weight: 600;
}
.board-settings__bg-grid {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
.board-settings__bg-option {
	width: 40px;
	height: 28px;
	border-radius: var(--border-radius, 6px);
	border: 2px solid var(--color-border);
	cursor: pointer;
	padding: 0;
}
.board-settings__bg-option:hover:not(:disabled) {
	border-color: var(--color-primary-element);
}
.board-settings__bg-option--active {
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px var(--color-primary-element);
}
.board-settings__bg-option--none {
	display: flex;
	align-items: center;
	justify-content: center;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}
.board-settings__bg-option:disabled {
	opacity: 0.6;
	cursor: default;
}
.board-settings__bg-none-icon {
	font-size: 0.9rem;
	line-height: 1;
}

/* ── Reused label styles (same as original LabelSettingsPanel) ─────────────── */

.label-settings__list {
	list-style: none;
	margin: 0 0 24px;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.label-settings__empty {
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
	padding: 8px 0;
}

.label-settings__item {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	padding: 4px 0;
	position: relative;
}

.label-settings__swatch {
	box-sizing: border-box;
	flex-shrink: 0;
	width: 24px;
	height: 24px;
	/* Defeat the UA <button> box (min-size/padding/line-height) that otherwise
	   inflates the height and turns the round swatch into an oval. Matches the
	   treatment on .label-settings__color-option. */
	min-width: 24px;
	min-height: 24px;
	padding: 0;
	aspect-ratio: 1;
	border-radius: 50%;
	border: 2px solid var(--color-border);
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 0.8rem;
	font-weight: 700;
	line-height: 1;
	transition: transform 0.1s ease, border-color 0.1s ease;
}

.label-settings__swatch:not(:disabled):hover {
	transform: scale(1.15);
	border-color: var(--color-primary);
}

.label-settings__swatch:disabled {
	cursor: default;
	opacity: 0.6;
}

.label-settings__swatch--no-color {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.label-settings__swatch-icon {
	line-height: 1;
	pointer-events: none;
}

.label-settings__color-popover {
	position: absolute;
	left: 0;
	top: 32px;
	z-index: 100;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 8px;
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
}

.label-settings__color-popover--create {
	top: 100%;
	margin-top: 4px;
}

.label-settings__color-grid {
	display: grid;
	grid-template-columns: repeat(5, 24px);
	gap: 6px;
}

.label-settings__color-option {
	box-sizing: border-box;
	width: 24px;
	height: 24px;
	/* Defeat the UA <button> box (line-height/padding/min-size) that otherwise
	   inflates the height and turns the swatch into an ellipse. */
	min-width: 24px;
	min-height: 24px;
	padding: 0;
	line-height: 1;
	flex-shrink: 0;
	aspect-ratio: 1;
	border-radius: 50%;
	border: 2px solid transparent;
	cursor: pointer;
	transition: transform 0.1s ease, border-color 0.1s ease;
}

.label-settings__color-option:hover {
	transform: scale(1.2);
	border-color: var(--color-main-text);
}

.label-settings__color-option--active {
	border-color: var(--color-main-text) !important;
	transform: scale(1.1);
}

.label-settings__color-option--clear {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 1rem;
	display: flex;
	align-items: center;
	justify-content: center;
}

.label-settings__name {
	flex: 1;
	font-size: 0.875rem;
	color: var(--color-main-text);
	cursor: pointer;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.label-settings__name:hover:not(.label-settings__name--readonly) {
	color: var(--color-primary);
}

.label-settings__name--readonly {
	cursor: default;
}

.label-settings__rename-input {
	flex: 1;
	font-size: 0.875rem;
	padding: 3px 8px;
	border: 2px solid var(--color-primary);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	min-width: 0;
}

.label-settings__rename-input:focus {
	outline: none;
}

.label-settings__actions {
	display: flex;
	gap: 4px;
	flex-shrink: 0;
}

.review-type-stage {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.review-type-stage__label {
	white-space: nowrap;
}

.review-type-stage__input {
	width: 52px;
	padding: 2px 4px;
	text-align: center;
}

.review-type-stage--create {
	margin-inline: 4px;
}

.label-settings__action-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 26px;
	height: 26px;
	border: none;
	border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	padding: 0;
	transition: background 0.15s ease, color 0.15s ease;
}

.label-settings__action-btn:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.label-settings__action-btn--danger:hover {
	background: rgba(var(--color-error-rgb, 227, 0, 0), 0.1);
	color: var(--color-error);
}

.label-settings__confirm {
	width: 100%;
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	padding: 6px 0;
	font-size: 0.8rem;
	color: var(--color-main-text);
}

.label-settings__confirm-yes {
	font-size: 0.8rem;
	padding: 3px 10px;
	border-radius: var(--border-radius);
	border: none;
	background: var(--color-error);
	color: #fff;
	cursor: pointer;
}

.label-settings__confirm-yes:disabled {
	opacity: 0.6;
	cursor: default;
}

.label-settings__confirm-no {
	font-size: 0.8rem;
	padding: 3px 10px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
}

.label-settings__error {
	width: 100%;
	color: var(--color-error);
	font-size: 0.8rem;
}

.label-settings__create {
	border-top: 1px solid var(--color-border);
	padding-top: 16px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	position: relative;
}

.label-settings__create-heading {
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
	margin: 0;
}

.label-settings__create-row {
	display: flex;
	align-items: center;
	gap: 8px;
	position: relative;
}

.label-settings__create-input {
	flex: 1;
	height: 36px;
	padding: 0 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.875rem;
	transition: border-color 0.15s ease;
}

.label-settings__create-input:focus {
	outline: none;
	border-color: var(--color-primary);
}

.label-settings__create-btn {
	flex-shrink: 0;
	height: 36px;
	padding: 0 14px;
	border-radius: var(--border-radius);
	border: none;
	background: var(--color-primary);
	color: var(--color-primary-text, #fff);
	font-size: 0.875rem;
	font-weight: 600;
	cursor: pointer;
	transition: background 0.15s ease, opacity 0.15s ease;
}

.label-settings__create-btn:hover:not(:disabled) {
	background: var(--color-primary-hover, var(--color-primary));
	opacity: 0.9;
}

.label-settings__create-btn:disabled {
	opacity: 0.5;
	cursor: default;
}

/* ── Sharing tab styles ───────────────────────────────────────────────────────── */

.sharing__search-wrap {
	position: relative;
	margin-bottom: 8px;
}

.sharing__search-input {
	width: 100%;
	height: 36px;
	padding: 0 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.875rem;
	transition: border-color 0.15s ease;
	box-sizing: border-box;
}

.sharing__search-input:focus {
	outline: none;
	border-color: var(--color-primary);
}

.sharing__search-spinner {
	position: absolute;
	right: 10px;
	top: 50%;
	transform: translateY(-50%);
	width: 14px;
	height: 14px;
	border: 2px solid var(--color-border);
	border-top-color: var(--color-primary);
	border-radius: 50%;
	animation: spin 0.7s linear infinite;
}

@keyframes spin {
	to { transform: translateY(-50%) rotate(360deg); }
}

.sharing__dropdown {
	position: absolute;
	top: 100%;
	left: 0;
	right: 0;
	z-index: 200;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
	list-style: none;
	margin: 2px 0 0;
	padding: 4px 0;
	max-height: 200px;
	overflow-y: auto;
}

.sharing__dropdown-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	cursor: pointer;
	font-size: 0.875rem;
	color: var(--color-main-text);
	transition: background 0.1s ease;
}

.sharing__dropdown-item:hover {
	background: var(--color-background-hover);
}

.sharing__type-icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.sharing__dropdown-name {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.sharing__dropdown-type {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.sharing__error {
	display: block;
	color: var(--color-error);
	font-size: 0.8rem;
	margin-top: 4px;
}

.sharing__error--add {
	margin-bottom: 8px;
}

.sharing__list {
	list-style: none;
	margin: 8px 0 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.sharing__empty {
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
	padding: 8px 0;
}

.sharing__entry {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border-dark, var(--color-border));
}

.sharing__entry:last-child {
	border-bottom: none;
}

.sharing__avatar-wrap {
	flex-shrink: 0;
	width: 32px;
	height: 32px;
	display: flex;
	align-items: center;
	justify-content: center;
}

.sharing__group-icon {
	width: 32px;
	height: 32px;
	flex-shrink: 0;
	aspect-ratio: 1;
	border-radius: 50%;
	background: var(--color-background-dark);
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--color-text-maxcontrast);
}

.sharing__entry-name {
	flex: 1;
	font-size: 0.875rem;
	color: var(--color-main-text);
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.sharing__role-select {
	flex-shrink: 0;
	max-width: 110px;
	height: 28px;
	min-height: 28px;
	padding: 0 4px;
	font-size: 0.8rem;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.sharing__role-badge {
	flex-shrink: 0;
	padding: 1px 8px;
	font-size: 0.75rem;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.sharing__perms {
	display: flex;
	gap: 10px;
	flex-shrink: 0;
}

.sharing__perm-label {
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 0.8rem;
	color: var(--color-main-text);
	cursor: pointer;
	user-select: none;
}

.sharing__perm-label--disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.sharing__perm-label input[type='checkbox'] {
	cursor: inherit;
}

.sharing__remove-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 26px;
	height: 26px;
	border: none;
	border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	padding: 0;
	flex-shrink: 0;
	transition: background 0.15s ease, color 0.15s ease;
}

.sharing__remove-btn:hover:not(:disabled) {
	background: rgba(var(--color-error-rgb, 227, 0, 0), 0.1);
	color: var(--color-error);
}

.sharing__remove-btn:disabled {
	opacity: 0.5;
	cursor: default;
}

.sharing__leave {
	margin-top: 20px;
	border-top: 1px solid var(--color-border);
	padding-top: 16px;
}

.sharing__leave-btn {
	height: 36px;
	padding: 0 14px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-error);
	background: transparent;
	color: var(--color-error);
	font-size: 0.875rem;
	font-weight: 600;
	cursor: pointer;
	transition: background 0.15s ease;
}

.sharing__leave-btn:hover {
	background: rgba(var(--color-error-rgb, 227, 0, 0), 0.08);
}

/* ── Workflow tab styles ──────────────────────────────────────────────────── */

.workflow__readonly-notice {
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
	margin: 0 0 16px;
}

.workflow__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 0;
}

.workflow__item {
	display: grid;
	grid-template-columns: 1fr auto auto;
	grid-template-rows: auto auto;
	align-items: center;
	gap: 6px 10px;
	padding: 10px 0;
	border-bottom: 1px solid var(--color-border);
}

.workflow__item:last-child {
	border-bottom: none;
}

.workflow__stack-name {
	grid-column: 1 / -1;
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-main-text);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.workflow__field {
	display: flex;
	align-items: center;
	gap: 6px;
}

.workflow__label {
	font-size: 0.78rem;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.workflow__select {
	height: 30px;
	padding: 0 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.8rem;
	cursor: pointer;
	transition: border-color 0.15s ease;
}

.workflow__select:focus {
	outline: none;
	border-color: var(--color-primary);
}

.workflow__select:disabled {
	opacity: 0.6;
	cursor: default;
}

.workflow__wip-input {
	width: 80px;
	height: 30px;
	padding: 0 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.8rem;
	transition: border-color 0.15s ease;
}

.workflow__wip-input:focus {
	outline: none;
	border-color: var(--color-primary);
}

.workflow__wip-input:disabled {
	opacity: 0.6;
	cursor: default;
}

/* Remove browser spin buttons - they are tiny and touch-unfriendly */
.workflow__wip-input::-webkit-inner-spin-button,
.workflow__wip-input::-webkit-outer-spin-button {
	opacity: 0.5;
}

.workflow__saving {
	grid-column: 1 / -1;
	font-size: 0.78rem;
	color: var(--color-text-maxcontrast);
}

/* ── Automation tab styles ───────────────────────────────────────────────── */

.automation__section-heading {
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
	margin: 0 0 14px;
}

.automation__loading {
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
}

.automation__rules-list {
	list-style: none;
	margin: 0 0 20px;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.automation__rule-item {
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.automation__rule-item:last-child {
	border-bottom: none;
}

.automation__rule-main {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
}

.automation__rule-desc {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 0.875rem;
	color: var(--color-main-text);
	min-width: 0;
}

.automation__rule-title {
	font-weight: 600;
	overflow-wrap: anywhere;
}

/* Second line: schedule · target · mode · next, muted and dot-separated. */
.automation__rule-meta {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 2px 8px;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.automation__rule-meta > *:not(:first-child)::before {
	content: '·';
	margin-right: 8px;
	color: var(--color-text-maxcontrast);
}

.automation__rule-next {
	color: var(--color-text-maxcontrast);
}

.automation__rule-scope {
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
}

.automation__rule-status {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

/* Toggle switch */
.automation__toggle-label {
	display: flex;
	align-items: center;
	gap: 6px;
	cursor: pointer;
	flex-shrink: 0;
}

.automation__toggle-input {
	position: absolute;
	opacity: 0;
	width: 0;
	height: 0;
}

.automation__toggle-track {
	display: inline-block;
	width: 34px;
	height: 18px;
	border-radius: 9px;
	/* OFF state: a mid-contrast fill so the switch is visible in BOTH light and
	   dark themes (the old --color-border was near-invisible on the dark modal). */
	background: var(--color-border-maxcontrast);
	position: relative;
	transition: background 0.2s ease;
	flex-shrink: 0;
}

.automation__toggle-track::after {
	content: '';
	position: absolute;
	top: 3px;
	left: 3px;
	width: 12px;
	height: 12px;
	border-radius: 50%;
	background: var(--color-main-background);
	transition: transform 0.2s ease;
}

.automation__toggle-input:checked + .automation__toggle-track {
	background: var(--color-primary-element);
}

.automation__toggle-input:checked + .automation__toggle-track::after {
	transform: translateX(16px);
}

.automation__toggle-input:disabled + .automation__toggle-track {
	opacity: 0.5;
}

.automation__toggle-sr {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.automation__rule-actions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.automation__archive-now-btn {
	height: 28px;
	padding: 0 12px;
	border-radius: var(--border-radius);
	/* Use the contrast-adjusted --color-primary-element (not the raw brand
	   --color-primary, which can sit on top of the dark background and vanish). */
	border: 1px solid var(--color-primary-element);
	background: transparent;
	color: var(--color-primary-element);
	font-size: 0.8rem;
	font-weight: 600;
	cursor: pointer;
	transition: background 0.15s ease;
}

.automation__archive-now-btn:hover:not(:disabled) {
	background: color-mix(in srgb, var(--color-primary-element) 10%, transparent);
}

.automation__archive-now-btn:disabled {
	opacity: 0.5;
	cursor: default;
}

.automation__archive-result {
	font-size: 0.8rem;
	color: var(--kanso-success-legible);
	font-weight: 600;
}

/* Create form */
.automation__create-form {
	border-top: 1px solid var(--color-border);
	padding-top: 16px;
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.automation__form-row {
	display: flex;
	align-items: center;
	gap: 10px;
}

.automation__form-label {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	width: 70px;
	flex-shrink: 0;
}

.automation__form-select {
	flex: 1;
	min-width: 0;
}

.automation__create-btn {
	align-self: flex-start;
}

/* ── Recurring cards section extras ────────────────────────────────────────── */

.automation__section-heading--spaced {
	margin-top: 28px;
	padding-top: 20px;
	border-top: 1px solid var(--color-border);
}

.automation__section-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	margin: 0 0 12px;
}

.automation__rule-mode {
	font-size: 0.78rem;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 1px 5px;
	color: var(--color-text-maxcontrast);
}

.automation__recur-summary {
	font-size: 0.85rem;
	color: var(--color-main-text);
	font-weight: 500;
}

.automation__radio-group {
	display: flex;
	gap: 14px;
}

.automation__radio-label {
	display: flex;
	align-items: center;
	gap: 5px;
	font-size: 0.875rem;
	cursor: pointer;
}

.automation__freq-row {
	display: flex;
	align-items: center;
	gap: 6px;
	flex: 1;
}

.automation__freq-every {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.automation__interval-input {
	width: 54px;
}

.automation__weekday-group {
	display: flex;
	gap: 4px;
	flex-wrap: wrap;
}

.automation__weekday-btn {
	height: 28px;
	min-width: 36px;
	padding: 0 8px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 0.78rem;
	font-weight: 600;
	cursor: pointer;
	transition: background 0.12s ease, border-color 0.12s ease, color 0.12s ease;
}

.automation__weekday-btn:hover {
	border-color: var(--color-primary);
	color: var(--color-primary);
}

.automation__weekday-btn--active {
	background: var(--color-primary);
	border-color: var(--color-primary);
	color: var(--color-primary-text, #fff);
}

.automation__form-row--top {
	align-items: flex-start;
}

/* ── GitHub webhook config ─────────────────────────────────────────────────── */
.github-webhook__hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: 12px;
}

.github-webhook__label {
	display: block;
	font-weight: 600;
	margin: 8px 0 4px;
}

.github-webhook__row {
	display: flex;
	gap: 8px;
	align-items: center;
	margin-bottom: 8px;
}

.github-webhook__input {
	flex: 1;
	min-width: 0;
	font-family: var(--font-face-monospace, monospace);
}

.github-webhook__actions {
	display: flex;
	gap: 8px;
	align-items: center;
	margin-top: 8px;
}

.github-webhook__status {
	color: var(--kanso-success-legible);
	font-weight: 600;
}

/* Muted descriptive hint under the share/feed rotate actions — NOT a status
   pill, so it must not reuse the success-green .github-webhook__status. */
.bs-share__hint {
	color: var(--color-text-maxcontrast);
}

.github-webhook__divider {
	border: none;
	border-top: 1px solid var(--color-border);
	margin: 20px 0;
}

/* ── Board settings modal shell (replaces NcAppSidebar) ─────────────────────── */

.bs-modal {
	/* Legible success green for "active"/"enabled" status badges (e.g. "Link
	 * active"): stock --color-success in light, a brighter green (#3fb950) under
	 * dark so the badge text/border stays readable on the dark surface. */
	--kanso-success-legible: var(--color-success, #46ba61);
	--kanso-success-legible-rgb: 70, 186, 97;

	position: absolute;
	/* Dock BELOW the board toolbar so the gear button that toggles this panel
	   stays clickable — a second gear click must be able to close it. BoardView
	   publishes the toolbar height as this CSS var. */
	top: var(--kanso-board-toolbar-height, 0px);
	right: 0;
	bottom: 0;
	width: 500px;
	max-width: 100%;
	z-index: 1800;
	display: flex;
	flex-direction: column;
	background: var(--color-main-background);
	border-left: 1px solid var(--color-border);
	box-shadow: var(--shadow-dropdown, 0 0 12px rgba(0, 0, 0, 0.12));
	box-sizing: border-box;
}

/* Brighten success green under dark themes (explicit picker + auto) so status
 * badges clear WCAG AA on the dark surface. Mirrors the CardTile error token. */
body.theme--dark .bs-modal,
[data-theme-dark] .bs-modal,
[data-themes*='dark'] .bs-modal {
	--kanso-success-legible: #3fb950;
	--kanso-success-legible-rgb: 63, 185, 80;
}

@media (prefers-color-scheme: dark) {
	body.theme--default .bs-modal,
	body:not(.theme--light):not(.theme--dark) .bs-modal {
		--kanso-success-legible: #3fb950;
		--kanso-success-legible-rgb: 63, 185, 80;
	}
}

.bs-modal__header {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	padding: 16px 16px 12px 20px;
	border-bottom: 1px solid var(--color-border);
	flex-shrink: 0;
}

.bs-modal__heading {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	/* Fill the header row so the close button is pushed flush to the top-right
	   corner. We can't rely on `margin-left: auto` on .bs-modal__close alone —
	   a global button margin reset overrides it — so let the heading grow. */
	flex: 1 1 auto;
}

.bs-modal__title {
	font-size: 1.2rem;
	font-weight: 700;
}

.bs-modal__subtitle {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.bs-modal__close {
	margin-left: auto;
	width: 36px;
	height: 36px;
	flex-shrink: 0;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
}

.bs-modal__close:hover,
.bs-modal__close:focus-visible {
	background: var(--color-background-hover);
}

.bs-modal__body {
	flex: 1;
	min-height: 0;
	display: flex;
}

/* ── Section rail ──────────────────────────────────────────────────────────── */

.bs-rail {
	width: 164px;
	flex-shrink: 0;
	border-right: 1px solid var(--color-border);
	background: var(--color-background-hover);
	padding: 8px;
	display: flex;
	flex-direction: column;
	box-sizing: border-box;
}

.bs-rail__tabs {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.bs-rail__item {
	display: flex;
	align-items: center;
	gap: 8px;
	height: 36px;
	padding: 0 10px;
	border: none;
	border-radius: var(--border-radius-large);
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.875rem;
	text-align: left;
	cursor: pointer;
}

.bs-rail__item:hover {
	background: var(--color-background-dark);
}

.bs-rail__item:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

.bs-rail__item--active {
	background: var(--color-main-background);
	color: var(--color-primary-element);
	font-weight: 600;
	box-shadow: var(--shadow-card-hover, 0 1px 3px rgba(0, 0, 0, 0.1));
}

.bs-rail__item--active .bs-rail__icon {
	color: var(--color-primary-element);
}

.bs-rail__icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.bs-rail__label {
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* ── Board actions (Export / Duplicate / Archive / Delete) in the General tab ── */
.board-actions {
	margin-top: 24px;
	padding-top: 20px;
	border-top: 1px solid var(--color-border);
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.board-actions__heading {
	margin: 0 0 8px;
	font-size: 0.9375rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.board-actions__row {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
	padding: 12px 0;
}

.board-actions__row + .board-actions__row {
	border-top: 1px solid var(--color-border-dark);
}

.board-actions__text {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.board-actions__label {
	font-weight: 600;
	color: var(--color-main-text);
}

.board-actions__label--delete {
	color: var(--color-error-text, var(--color-error));
}

.board-actions__hint {
	font-size: 0.8125rem;
	color: var(--color-text-maxcontrast);
}

.board-actions__check {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-top: 6px;
	font-size: 0.8125rem;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}

.board-actions__danger {
	margin-top: 20px;
	padding: 4px 16px 12px;
	border: 1px solid var(--color-error);
	border-radius: var(--border-radius-large);
	background: var(--kanso-tint-error, color-mix(in srgb, var(--color-error) 6%, transparent));
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.board-actions__danger-heading {
	margin: 12px 0 0;
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-error-text, var(--color-error));
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

/* ── Pane area ─────────────────────────────────────────────────────────────── */

.bs-panes {
	flex: 1;
	min-width: 0;
	overflow-y: auto;
}

.bs-pane {
	padding: 20px;
	box-sizing: border-box;
}

/* Delete-board confirmation banner. */
.bs-delete-confirm {
	margin: 16px 16px 0;
	padding: 16px;
	border: 1px solid var(--color-error);
	border-radius: var(--border-radius-large);
	background: var(--kanso-tint-error, color-mix(in srgb, var(--color-error) 8%, transparent));
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.bs-delete-confirm__title {
	margin: 0;
	font-weight: 700;
}

.bs-delete-confirm__hint {
	margin: 0;
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
}

.bs-delete-confirm__actions {
	display: flex;
	gap: 8px;
	margin-top: 4px;
}

/* ── Automation collapsible groups ─────────────────────────────────────────── */

.automation__intro {
	margin: 0 0 16px;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.rt-settings__intro {
	margin: 0 0 16px;
	font-size: 0.8rem;
	line-height: 1.4;
	color: var(--color-text-maxcontrast);
}

.rt-settings__intro p {
	margin: 0 0 8px;
}

.rt-settings__intro p:last-child {
	margin-bottom: 0;
}

.automation__group {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	margin-bottom: 16px;
	overflow: hidden;
}

.automation__group-header {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
	height: 44px;
	padding: 0 12px;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.875rem;
	text-align: left;
	cursor: pointer;
	box-sizing: border-box;
}

.automation__group-header:hover {
	background: var(--color-background-hover);
}

.automation__group-header:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

.automation__group-icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.automation__group-title {
	font-weight: 600;
}

.automation__group-count {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 20px;
	height: 20px;
	padding: 0 6px;
	border-radius: 10px;
	background: var(--color-border);
	color: var(--color-text-maxcontrast);
	font-size: 0.75rem;
	font-weight: 600;
}

.automation__group-badge {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	padding: 1px 7px;
	border: 1px solid var(--kanso-success-legible);
	border-radius: 10px;
	color: var(--kanso-success-legible);
	background: rgba(var(--kanso-success-legible-rgb), 0.12);
	font-size: 0.75rem;
	font-weight: 600;
}

.automation__group-chevron {
	margin-left: auto;
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

/* The badge sits next to the chevron; give the chevron no auto-margin then. */
.automation__group-badge + .automation__group-chevron,
.automation__group-count + .automation__group-chevron {
	margin-left: 8px;
}

.automation__group-body {
	padding: 12px;
	border-top: 1px solid var(--color-border);
}

/* ── Custom fields pane ──────────────────────────────────────────────────────── */

.cf-settings__item {
	align-items: flex-start;
	flex-wrap: wrap;
}

.cf-settings__type-badge {
	display: inline-flex;
	align-items: center;
	padding: 1px 7px;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	font-size: 0.7rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
	flex-shrink: 0;
}

.cf-settings__options-preview {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	flex: 1 1 100%;
	padding-left: 2px;
}

.cf-settings__options-editor {
	flex: 1 1 100%;
	margin-top: 4px;
}

.cf-settings__options-textarea {
	width: 100%;
	min-height: 80px;
	box-sizing: border-box;
	font-size: 0.875rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 6px 8px;
	resize: vertical;
}

.cf-settings__options-actions {
	display: flex;
	gap: 8px;
	margin-top: 6px;
}

.cf-settings__create-row {
	flex-wrap: wrap;
	gap: 6px;
}

.cf-settings__type-select {
	flex-shrink: 0;
}

.cf-settings__new-options {
	margin-top: 8px;
}

.cf-settings__new-options-label {
	display: block;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	margin-bottom: 4px;
}
</style>
