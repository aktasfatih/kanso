<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
	Standalone full-page card view (#3817).

	The top-level /card/:cardId route. Renders the SAME <CardDetail> the modal
	shell renders - only the chrome differs: instead of a dialog overlay this is a
	full-page shell with a breadcrumb (which board the card belongs to) and an
	explicit back-to-board affordance. All card behaviour (data fetch, optimistic
	mutations, realtime, permission gating) lives in CardDetail, so the page and
	the modal can never drift.

	This route is NOT nested under BoardView, so it renders as a page rather than
	an overlay. The board id is not in the URL here; CardDetail resolves it from
	the loaded card and reports it back via @board-context, which drives the
	breadcrumb and the back link.
-->
<template>
	<div class="card-page">
		<nav class="card-page__bar" aria-label="breadcrumb">
			<button
				class="card-page__back"
				type="button"
				:disabled="!boardId"
				@click="goToBoard">
				<ArrowLeftIcon :size="18" />
				<span>{{ t('kanso', 'Back to board') }}</span>
			</button>
			<span class="card-page__crumbs">
				<button
					v-if="boardId"
					class="card-page__crumb card-page__crumb--link"
					type="button"
					@click="goToBoard">
					{{ boardName }}
				</button>
				<span v-else class="card-page__crumb">{{ boardName }}</span>
				<ChevronRightIcon :size="14" class="card-page__crumb-sep" />
				<span class="card-page__crumb card-page__crumb--current">{{ cardTitle }}</span>
			</span>
		</nav>

		<CardDetail
			mode="page"
			:card-id="cardId"
			@update:title="cardTitle = $event"
			@board-context="onBoardContext" />
	</div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import CardDetail from '../components/CardDetail.vue'

defineProps({
	cardId: {
		type: String,
		required: true,
	},
})

const router = useRouter()

const cardTitle = ref(t('kanso', 'Card'))
const boardId = ref(null)
const boardName = ref(t('kanso', 'Board'))

function onBoardContext({ boardId: id, boardName: name }) {
	boardId.value = id
	if (name) boardName.value = name
}

// Back to the card's board. The board id is resolved from the loaded card (the
// full-page URL carries no board id), so the button stays disabled until it's known.
function goToBoard() {
	if (!boardId.value) return
	router.push({ name: 'board', params: { id: String(boardId.value) } })
}
</script>

<style scoped>
.card-page {
	display: flex;
	flex-direction: column;
	min-height: 0;
	height: 100%;
	overflow-y: auto;
}

.card-page__bar {
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 10px 16px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-main-background);
	position: sticky;
	top: 0;
	z-index: 2;
}

.card-page__back {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	font-weight: 600;
	cursor: pointer;
	border-radius: var(--border-radius);
	padding: 4px 8px;
}
.card-page__back:hover:not(:disabled) {
	background: var(--color-background-hover);
}
.card-page__back:disabled {
	opacity: 0.5;
	cursor: default;
}

.card-page__crumbs {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	min-width: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}
.card-page__crumb {
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	max-width: 40ch;
}
.card-page__crumb--link {
	border: none;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	padding: 0;
	font-size: inherit;
}
.card-page__crumb--link:hover {
	color: var(--color-main-text);
	text-decoration: underline;
}
.card-page__crumb--current {
	color: var(--color-main-text);
	font-weight: 600;
}
.card-page__crumb-sep {
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
}
</style>
