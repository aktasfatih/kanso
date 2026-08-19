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
						<p v-if="card.description" class="public-card__desc">{{ truncate(card.description) }}</p>
						<div class="public-card__meta">
							<span v-if="card.priority >= 4" class="public-card__prio">{{ t('kanso', 'Urgent') }}</span>
							<span v-if="card.duedate" class="public-card__due">{{ formatDue(card.duedate) }}</span>
							<span v-if="card.checklist.total > 0" class="public-card__check">
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
				<div
					v-if="selectedCard.priority >= 4 || selectedCard.duedate || selectedCard.checklist.total > 0"
					class="public-detail__meta">
					<span v-if="selectedCard.priority >= 4" class="public-detail__prio">{{ t('kanso', 'Urgent') }}</span>
					<span v-if="selectedCard.duedate">{{ formatDue(selectedCard.duedate) }}</span>
					<span v-if="selectedCard.checklist.total > 0">
						{{ selectedCard.checklist.done }}/{{ selectedCard.checklist.total }}
					</span>
				</div>
				<p
					class="public-detail__desc"
					:class="{ 'public-detail__desc--empty': !selectedCard.description }">
					{{ selectedCard.description || t('kanso', 'No description') }}
				</p>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'

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
	},
	async mounted() {
		try {
			const { data } = await axios.get(generateUrl('/apps/kanso/api/public/' + encodeURIComponent(this.token)))
			this.board = data.board
			this.stacks = data.stacks
			this.cards = data.cards
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
		formatDue(iso) {
			try {
				return new Date(iso).toLocaleDateString()
			} catch (e) {
				return ''
			}
		},
	},
}
</script>
