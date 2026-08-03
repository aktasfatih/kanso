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
						:class="{ 'public-card--done': card.status === 'done' }">
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
	},
	methods: {
		t,
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

<style scoped>
.public-board {
	max-width: 1400px;
	margin: 0 auto;
	padding: 24px 16px 48px;
	box-sizing: border-box;
}
.public-board__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 24px;
}
.public-board__dot {
	width: 16px;
	height: 16px;
	border-radius: 50%;
	flex: 0 0 auto;
}
.public-board__title {
	font-size: 24px;
	font-weight: 700;
	margin: 0;
}
.public-board__badge {
	font-size: 12px;
	padding: 2px 10px;
	border-radius: 12px;
	background: var(--color-background-dark, #ededed);
	color: var(--color-text-maxcontrast, #666);
}
.public-board__state {
	color: var(--color-text-maxcontrast, #666);
	padding: 32px 0;
}
.public-board__state--error {
	color: var(--color-error, #c33);
}
.public-board__columns {
	display: flex;
	gap: 16px;
	align-items: flex-start;
	overflow-x: auto;
	padding-bottom: 8px;
}
.public-col {
	flex: 0 0 300px;
	max-width: 300px;
	background: var(--color-background-hover, #f5f5f5);
	border-radius: 10px;
	padding: 10px;
	box-sizing: border-box;
}
.public-col__title {
	display: flex;
	align-items: center;
	justify-content: space-between;
	font-size: 15px;
	font-weight: 600;
	margin: 4px 4px 12px;
	padding-bottom: 6px;
	border-bottom: 2px solid var(--color-border, #ddd);
}
.public-col__count {
	font-size: 12px;
	color: var(--color-text-maxcontrast, #888);
	font-weight: 500;
}
.public-col__cards {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}
.public-card {
	background: var(--color-main-background, #fff);
	border: 1px solid var(--color-border, #e0e0e0);
	border-radius: 8px;
	padding: 10px;
}
.public-card--done {
	opacity: 0.6;
}
.public-card__labels {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-bottom: 6px;
}
.public-card__label {
	font-size: 11px;
	padding: 1px 8px;
	border-radius: 10px;
	color: #fff;
	text-shadow: 0 0 2px rgba(0, 0, 0, 0.4);
}
.public-card__row {
	display: flex;
	gap: 6px;
	align-items: baseline;
}
.public-card__id {
	font-size: 11px;
	color: var(--color-text-maxcontrast, #888);
	font-weight: 600;
	flex: 0 0 auto;
}
.public-card__title {
	font-weight: 500;
	word-break: break-word;
}
.public-card__desc {
	margin: 6px 0 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #666);
	white-space: pre-wrap;
	word-break: break-word;
}
.public-card__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 8px;
	font-size: 12px;
	color: var(--color-text-maxcontrast, #888);
}
.public-card__prio {
	color: var(--color-error, #c33);
	font-weight: 600;
}
.public-board__footer {
	margin-top: 32px;
	text-align: center;
	font-size: 12px;
	color: var(--color-text-maxcontrast, #999);
}
</style>
