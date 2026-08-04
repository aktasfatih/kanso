<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="inbox-view">
		<div v-if="!embedded" class="inbox-view__header kanso-page-header">
			<h1 class="inbox-view__title">{{ t('kanso', 'Inbox') }}</h1>
		</div>

		<!-- Loading state -->
		<div v-if="isLoading" class="inbox-view__loading" aria-live="polite">
			<span class="inbox-view__spinner" aria-hidden="true" />
			<span>{{ t('kanso', 'Loading inbox…') }}</span>
		</div>

		<!-- Error state -->
		<div v-else-if="isError" class="inbox-view__error">
			{{ t('kanso', 'Failed to load inbox. Please try again.') }}
		</div>

		<!-- Empty state -->
		<NcEmptyContent
			v-else-if="filteredItems.length === 0"
			:name="t('kanso', 'You\'re all caught up')"
			:description="t('kanso', 'Comments on cards you watch will appear here. Subscribe to a card via its Watch button to follow activity.')">
			<template #icon>
				<BellIcon :size="64" />
			</template>
		</NcEmptyContent>

		<!-- Feed -->
		<ul v-else class="inbox-view__list">
			<li
				v-for="item in filteredItems"
				:key="item.type + '-' + item.id"
				class="inbox-view__item"
				role="button"
				tabindex="0"
				@click="openCard(item)"
				@keydown.enter.prevent="openCard(item)"
				@keydown.space.prevent="openCard(item)">
				<!-- Main content -->
				<div class="inbox-view__item-content">
					<!-- Summary line: "{author} {action} {cardTitle}" -->
					<p class="inbox-view__item-summary">
						<strong class="inbox-view__item-author">{{ displayName(item) }}</strong>
						{{ actionPhrase(item) }}
						<strong class="inbox-view__item-card">{{ item.cardTitle }}</strong>
					</p>

					<!-- Secondary line: board context + timestamp -->
					<p class="inbox-view__item-meta">
						<span class="inbox-view__item-board">{{ item.boardTitle }}</span>
						<span class="inbox-view__item-sep" aria-hidden="true">·</span>
						<span class="inbox-view__item-time" :title="fullDate(item.createdAt)">
							{{ relativeTime(item.createdAt) }}
						</span>
					</p>

					<!-- Comment snippet (1–2 lines); status changes carry no body -->
					<p v-if="item.type === 'comment'" class="inbox-view__item-body">{{ item.body }}</p>
				</div>

				<!-- Chevron hint -->
				<ChevronRightIcon class="inbox-view__item-chevron" :size="20" aria-hidden="true" />
			</li>
		</ul>
	</div>
</template>

<script setup>
import { computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import BellIcon from 'vue-material-design-icons/Bell.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import { useInbox } from '../composables/useInbox.js'
import { markInboxSeen } from '../composables/useMyWorkBadges.js'

const props = defineProps({
	embedded: { type: Boolean, default: false },
	boardFilter: { type: Number, default: null },
})

const router = useRouter()
const { data, isLoading, isError } = useInbox()

const items = computed(() => data.value ?? [])

// Opening the Inbox clears its nav "new" badge: mark everything up to the newest
// currently-known item as seen. Runs whenever the feed loads/refreshes while the
// view is mounted so freshly-fetched items also count as read on this visit.
watch(items, (list) => {
	if (!list.length) return
	const newest = list.reduce((max, it) => Math.max(max, Number(it.createdAt) || 0), 0)
	markInboxSeen(newest)
}, { immediate: true })

/** Items after applying the optional board filter from the hub. */
const filteredItems = computed(() =>
	props.boardFilter === null
		? items.value
		: items.value.filter((item) => item.boardId === props.boardFilter),
)

/**
 * Resolve the author display name.
 * The backend may include an `authorDisplayName` field (same pattern used in
 * CardModal for comment authors: `topComment.authorDisplayName || topComment.author`).
 * Fall back to the raw uid when the field is absent or empty.
 */
function displayName(item) {
	return item.authorDisplayName || item.author
}

// kanso_changes verb constants surfaced in the feed (mirrors Change::VERB_*).
const VERB_ASSIGNED = 8
const VERB_REVIEW_REQUESTED = 10

/**
 * The verb phrase between the actor and the card title. Comments read
 * "commented on"; status changes map their verb to a phrase.
 */
function actionPhrase(item) {
	if (item.type === 'change') {
		if (item.verb === VERB_REVIEW_REQUESTED) return t('kanso', 'requested a review on')
		if (item.verb === VERB_ASSIGNED) return t('kanso', 'updated assignees on')
	}
	return t('kanso', 'commented on')
}

function openCard(item) {
	// Thread the origin so CardModal returns to My Work on close (see MyCardsView).
	const from = props.embedded ? 'my-work' : 'inbox'
	const query = props.embedded ? { from, tab: 'inbox' } : { from }
	router.push({ name: 'card-modal', params: { id: item.boardId, cardId: item.cardId }, query })
}

/**
 * Full locale date string shown in the title tooltip.
 * @param {number} createdAt - Unix timestamp in seconds
 */
function fullDate(createdAt) {
	if (!createdAt) return ''
	return new Date(createdAt * 1000).toLocaleString(undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
	})
}

/**
 * Human-readable relative time - mirrors the logic in ReviewRow.vue.
 * @param {number} createdAt - Unix timestamp in seconds
 */
function relativeTime(createdAt) {
	if (!createdAt) return ''
	const now = Date.now()
	const ms = createdAt * 1000
	const diffSec = Math.floor((now - ms) / 1000)
	if (diffSec < 60) return t('kanso', 'just now')
	if (diffSec < 3600) return t('kanso', '{n} min ago', { n: Math.floor(diffSec / 60) })
	if (diffSec < 86400) return t('kanso', '{n} hr ago', { n: Math.floor(diffSec / 3600) })
	if (diffSec < 86400 * 7) return t('kanso', '{n} days ago', { n: Math.floor(diffSec / 86400) })
	return new Date(ms).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>

<style scoped>
.inbox-view {
	padding: 24px 32px;
	max-width: 860px;
}

.inbox-view__header {
	margin-bottom: 24px;
}

.inbox-view__title {
	font-size: 1.5rem;
	font-weight: 600;
	margin: 0;
}

.inbox-view__loading {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--color-text-maxcontrast);
	padding: 32px 0;
}

.inbox-view__spinner {
	display: inline-block;
	width: 20px;
	height: 20px;
	border: 2px solid var(--color-border);
	border-top-color: var(--color-primary-element);
	border-radius: 50%;
	animation: inbox-spin 0.7s linear infinite;
}

@keyframes inbox-spin {
	to { transform: rotate(360deg); }
}

.inbox-view__error {
	color: var(--color-error);
	padding: 16px 0;
}

.inbox-view__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.inbox-view__item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 14px 16px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
	cursor: pointer;
	transition: background 0.15s;
}

.inbox-view__item:hover,
.inbox-view__item:focus-visible {
	background: var(--color-border-dark);
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

.inbox-view__item-content {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 3px;
}

.inbox-view__item-summary,
.inbox-view__item-meta,
.inbox-view__item-body {
	margin: 0;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.inbox-view__item-summary {
	font-size: 0.9rem;
}

.inbox-view__item-author {
	font-weight: 600;
}

.inbox-view__item-card {
	font-weight: 500;
}

.inbox-view__item-meta {
	display: flex;
	align-items: center;
	gap: 5px;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.inbox-view__item-sep {
	opacity: 0.5;
}

.inbox-view__item-body {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	/* Allow up to 2 lines before truncating */
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
	white-space: normal;
	overflow: hidden;
}

.inbox-view__item-chevron {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
	opacity: 0.5;
}
</style>
