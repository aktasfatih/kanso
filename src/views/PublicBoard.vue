<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="public-board">
		<header class="public-board__header">
			<span
				class="public-board__dot"
				:style="{ background: board && board.color ? '#' + board.color : 'var(--color-primary-element, #0082c9)' }" />
			<h1 class="public-board__title">{{ board ? board.title : t('kanso', 'Board') }}</h1>
			<span class="public-board__badge">{{ t('kanso', 'Read-only') }}</span>
		</header>

		<p v-if="loading" class="public-board__state">{{ t('kanso', 'Loading…') }}</p>
		<p v-else-if="error" class="public-board__state public-board__state--error">
			{{ t('kanso', 'This link is invalid or has been disabled.') }}
		</p>

		<div v-else class="public-board__columns">
			<section v-for="stack in stacks" :key="stack.id" class="public-col">
				<h2 class="public-col__title" :style="stack.color ? { borderColor: '#' + stack.color } : null">
					{{ stack.title }}
					<span class="public-col__count">{{ cardsByStack[stack.id] ? cardsByStack[stack.id].length : 0 }}</span>
				</h2>
				<ul class="public-col__cards">
					<li
						v-for="card in (cardsByStack[stack.id] || [])"
						:key="card.id"
						class="public-card"
						:class="{ 'public-card--done': card.status === 'done' }"
						tabindex="0"
						role="button"
						:aria-label="t('kanso', 'Open card details')"
						@click="openCard(card)"
						@keydown.enter.prevent="openCard(card)"
						@keydown.space.prevent="openCard(card)">
						<div v-if="card.labels.length" class="public-card__labels">
							<span
								v-for="(label, i) in card.labels"
								:key="i"
								class="public-card__label"
								:style="{ background: label.color ? '#' + label.color : 'var(--color-background-dark, #ededed)' }">
								{{ label.name }}
							</span>
						</div>
						<div class="public-card__row">
							<span v-if="card.humanId" class="public-card__id">{{ card.humanId }}</span>
							<span class="public-card__title">{{ card.title }}</span>
						</div>
						<p v-if="cardExcerpts[card.id]" class="public-card__desc">{{ cardExcerpts[card.id] }}</p>
						<div class="public-card__meta">
							<span v-if="card.priority >= 4" class="public-card__prio">{{ t('kanso', 'Urgent') }}</span>
							<span v-if="card.duedate" class="public-card__due">{{ formatDate(card.duedate) }}</span>
							<span v-if="checklistEnabled && card.checklist.total > 0" class="public-card__check">
								{{ card.checklist.done }}/{{ card.checklist.total }}
							</span>
						</div>
					</li>
				</ul>
			</section>
		</div>

		<footer class="public-board__footer">
			{{ t('kanso', 'Shared read-only via Kanso') }}
		</footer>

		<!-- Read-only card detail (#3945). All fields are already in the public
		     payload; this only expands them - no fetch, no edit affordances. -->
		<div
			v-if="selectedCard"
			class="public-detail__backdrop"
			@click.self="closeCard"
			@keydown.esc="closeCard">
			<div
				class="public-detail"
				role="dialog"
				aria-modal="true"
				tabindex="-1">
				<!-- Cover-colour band (#3951): a presentational accent, not a person.
				     Hidden when the board switched cover colours off (#5894) - the public
				     link is the same board, so it follows the same switch. -->
				<div
					v-if="selectedCard.coverColor && coverColorEnabled"
					class="public-detail__cover"
					:style="{ background: '#' + selectedCard.coverColor }" />
				<div class="public-detail__top">
					<span v-if="selectedCard.humanId" class="public-detail__id">{{ selectedCard.humanId }}</span>
					<h2 class="public-detail__title">{{ selectedCard.title }}</h2>
					<button
						class="public-detail__close"
						type="button"
						:aria-label="t('kanso', 'Close')"
						@click="closeCard">
						×
					</button>
				</div>
				<div v-if="selectedCard.labels.length" class="public-detail__labels">
					<span
						v-for="(label, i) in selectedCard.labels"
						:key="i"
						class="public-detail__label"
						:style="{ background: label.color ? '#' + label.color : 'var(--color-background-dark, #ededed)' }">
						{{ label.name }}
					</span>
				</div>
				<div v-if="hasMeta" class="public-detail__meta">
					<span v-if="selectedCard.priority >= 4" class="public-detail__prio">{{ t('kanso', 'Urgent') }}</span>
					<span v-if="selectedCard.startDate" class="public-detail__field">
						{{ t('kanso', 'Start') }}: {{ formatDate(selectedCard.startDate) }}
					</span>
					<span v-if="selectedCard.duedate" class="public-detail__field">
						{{ t('kanso', 'Due') }}: {{ formatDate(selectedCard.duedate) }}
					</span>
					<span v-if="selectedCard.estimate" class="public-detail__field">
						{{ t('kanso', 'Estimate') }}: {{ selectedCard.estimate }}
					</span>
					<span v-if="checklistEnabled && selectedCard.checklist.total > 0" class="public-detail__field">
						{{ selectedCard.checklist.done }}/{{ selectedCard.checklist.total }}
					</span>
				</div>
				<!-- eslint-disable-next-line vue/no-v-html -- sanitized by renderMarkdown (DOMPurify) -->
				<div
					v-if="selectedCard.description"
					class="public-detail__desc"
					v-html="renderedDescription" />
				<p v-else class="public-detail__desc public-detail__desc--empty">
					{{ t('kanso', 'No description') }}
				</p>

				<!-- Checklist steps (#135). The payload only ever carried the count,
				     so an anonymous reader saw "1/2" and no way to learn what the two
				     steps were. Gated on the same board switch as the count above, and
				     rendered BELOW the meta row so the count keeps its place. Titles
				     are interpolated as plain text - a step title is not a markdown
				     surface anywhere in the app - and the tick is a styled span, never
				     an <input>, because this detail is strictly read-only. -->
				<section v-if="checklistEnabled && checklistItems.length" class="public-checklist">
					<h3 class="public-checklist__title">{{ t('kanso', 'Checklist') }}</h3>
					<ul class="public-checklist__list">
						<li
							v-for="(item, i) in checklistItems"
							:key="i"
							class="public-checklist__item"
							:class="{ 'public-checklist__item--done': item.done }">
							<span class="public-checklist__box" :class="{ 'public-checklist__box--done': item.done }" aria-hidden="true" />
							<span class="public-checklist__label">{{ item.title }}</span>
						</li>
					</ul>
				</section>

				<!-- Sub-cards (#135). Ids only reach the client; each one is resolved
				     against the cards already loaded, so a reference can never name a
				     card this visitor does not otherwise hold. Clicking opens that
				     card's own read-only detail. -->
				<section v-if="subCards.length" class="public-subcards">
					<h3 class="public-subcards__title">{{ t('kanso', 'Sub-cards') }}</h3>
					<ul class="public-subcards__list">
						<li
							v-for="child in subCards"
							:key="child.id"
							class="public-subcard"
							:class="{ 'public-subcard--done': child.status === 'done' }"
							tabindex="0"
							role="button"
							:aria-label="t('kanso', 'Open card details')"
							@click="openCard(child)"
							@keydown.enter.prevent="openCard(child)"
							@keydown.space.prevent="openCard(child)">
							<span v-if="child.humanId" class="public-subcard__id">{{ child.humanId }}</span>
							<span class="public-subcard__title">{{ child.title }}</span>
						</li>
					</ul>
				</section>

				<!-- Read-only comments (#3949): shown ONLY when the board owner opted
				     in. Author DISPLAY NAME + an initials avatar (no NcAvatar / no uid),
				     markdown body, one-level threads. No reply box, no reactions. -->
				<section v-if="commentsEnabled" class="public-comments">
					<h3 class="public-comments__title">{{ t('kanso', 'Comments') }}</h3>
					<p v-if="!threadedComments.length" class="public-comments__empty">
						{{ t('kanso', 'No comments') }}
					</p>
					<ul v-else class="public-comments__list">
						<li v-for="c in threadedComments" :key="c.id" class="public-comment">
							<div class="public-comment__head">
								<span class="public-comment__avatar" aria-hidden="true">{{ initials(c.author) }}</span>
								<span class="public-comment__author">{{ c.author }}</span>
								<span class="public-comment__date">{{ formatDateTime(c.createdAt) }}</span>
							</div>
							<!-- eslint-disable-next-line vue/no-v-html -- sanitized by renderMarkdown (DOMPurify) -->
							<div class="public-comment__body" v-html="renderComment(c.body)" />
							<ul v-if="c.replies.length" class="public-comment__replies">
								<li v-for="r in c.replies" :key="r.id" class="public-comment">
									<div class="public-comment__head">
										<span class="public-comment__avatar" aria-hidden="true">{{ initials(r.author) }}</span>
										<span class="public-comment__author">{{ r.author }}</span>
										<span class="public-comment__date">{{ formatDateTime(r.createdAt) }}</span>
									</div>
									<!-- eslint-disable-next-line vue/no-v-html -- sanitized by renderMarkdown (DOMPurify) -->
									<div class="public-comment__body" v-html="renderComment(r.body)" />
								</li>
							</ul>
						</li>
					</ul>
				</section>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import { renderMarkdown, markdownToPlainText } from '../services/markdown.js'

export default {
	name: 'PublicBoard',
	props: {
		token: { type: String, required: true },
	},
	data() {
		return {
			loading: true,
			error: false,
			board: null,
			stacks: [],
			cards: [],
			selectedCard: null,
			commentsEnabled: false,
			// Built-in card sections (#5894); default to ON so a payload without the
			// flags (or an older server) looks exactly as it did before.
			coverColorEnabled: true,
			checklistEnabled: true,
		}
	},
	computed: {
		// Cards grouped by their stack id, preserving the server's display order.
		cardsByStack() {
			const map = {}
			for (const card of this.cards) {
				(map[card.stackId] = map[card.stackId] || []).push(card)
			}
			return map
		},
		// Tile description excerpts, keyed by card id (#10605). A tile is a text
		// summary, so the description is flattened to PLAIN TEXT first — markdown
		// source used to be printed verbatim, and an embedded image's URL ate the
		// whole excerpt. Images are dropped entirely (see markdownToPlainText);
		// truncation runs on the stripped text, never the raw source, or a
		// stripped-away URL would still consume the character budget. A card whose
		// description is only an image yields '' and renders no paragraph at all.
		cardExcerpts() {
			const map = {}
			for (const card of this.cards) {
				if (!card.description) continue
				const text = markdownToPlainText(card.description)
				if (text) map[card.id] = this.truncate(text)
			}
			return map
		},
		// The open card's description rendered as sanitized markdown HTML. No refs
		// map is passed: the public payload carries no card cross-reference data, so
		// PREFIX-123 references render as plain text (never a broken link).
		renderedDescription() {
			return this.selectedCard ? renderMarkdown(this.selectedCard.description) : ''
		},
		// Whether the open card has any presentational meta worth a meta row.
		hasMeta() {
			const c = this.selectedCard
			if (!c) return false
			return c.priority >= 4 || !!c.startDate || !!c.duedate || !!c.estimate
				|| (this.checklistEnabled && c.checklist.total > 0)
		},
		// The open card's checklist steps ({title, done} only — the server emits
		// nothing else). Tolerates an older server that ships no such key.
		checklistItems() {
			return (this.selectedCard && this.selectedCard.checklistItems) || []
		},
		// The open card's sub-cards, resolved from `childIds` against the cards
		// already loaded. An id with no match is dropped rather than rendered as a
		// placeholder: the payload is one snapshot, so a miss can only mean an
		// older server or a card filtered out of this view.
		subCards() {
			const ids = (this.selectedCard && this.selectedCard.childIds) || []
			if (!ids.length) return []
			const byId = {}
			for (const card of this.cards) {
				byId[card.id] = card
			}
			return ids.map((id) => byId[id]).filter(Boolean)
		},
		// The open card's flat comment list nested one level by parentCommentId:
		// top-level comments in order, each with its direct replies (also in order).
		// The server already scopes and orders the list; this only groups it.
		threadedComments() {
			const list = (this.selectedCard && this.selectedCard.comments) || []
			const byId = {}
			const roots = []
			for (const c of list) {
				byId[c.id] = { ...c, replies: [] }
			}
			for (const c of list) {
				const node = byId[c.id]
				const parent = c.parentCommentId != null ? byId[c.parentCommentId] : null
				if (parent) {
					parent.replies.push(node)
				} else {
					roots.push(node)
				}
			}
			return roots
		},
	},
	async mounted() {
		try {
			const { data } = await axios.get(generateUrl('/apps/kanso/api/public/' + encodeURIComponent(this.token)))
			this.board = data.board
			this.stacks = data.stacks
			this.cards = data.cards
			this.commentsEnabled = !!(data.board && data.board.commentsEnabled)
			this.coverColorEnabled = data.board?.cardFeatures?.coverColor !== false
			this.checklistEnabled = data.board?.cardFeatures?.checklist !== false
		} catch (e) {
			this.error = true
		} finally {
			this.loading = false
		}
		document.addEventListener('keydown', this.onKeydown)
	},
	beforeUnmount() {
		document.removeEventListener('keydown', this.onKeydown)
	},
	methods: {
		t,
		openCard(card) {
			this.selectedCard = card
		},
		closeCard() {
			this.selectedCard = null
		},
		onKeydown(e) {
			if (e.key === 'Escape' && this.selectedCard) {
				this.closeCard()
			}
		},
		truncate(text) {
			return text.length > 240 ? text.slice(0, 240) + '…' : text
		},
		formatDate(iso) {
			try {
				return new Date(iso).toLocaleDateString()
			} catch (e) {
				return ''
			}
		},
		// A comment's created_at is a unix-seconds int (public payload). Guard a
		// missing/zero timestamp so it renders empty rather than the epoch (1970).
		formatDateTime(ts) {
			if (!ts) return ''
			try {
				return new Date(ts * 1000).toLocaleString()
			} catch (e) {
				return ''
			}
		},
		// Initials avatar (no NcAvatar, no uid): first letters of the display name.
		initials(name) {
			const parts = String(name || '').trim().split(/\s+/).filter(Boolean)
			if (!parts.length) return '?'
			const first = parts[0][0] || ''
			const last = parts.length > 1 ? parts[parts.length - 1][0] : ''
			return (first + last).toUpperCase()
		},
		// Comment body rendered as sanitized markdown (same pipeline as the
		// description; no refs map, so PREFIX-123 stays plain text).
		renderComment(body) {
			return renderMarkdown(body || '')
		},
	},
}
</script>
