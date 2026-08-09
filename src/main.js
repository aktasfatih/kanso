// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import '@nextcloud/dialogs/style.css'
import './styles/kanso-page-header.css'
import { createApp } from 'vue'
import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import App from './App.vue'
import { router } from './router/index.js'
import { initRealtime } from './services/realtime.js'
import { isBoardMovePending } from './composables/useCardMove.js'
import { syncBoardDelta } from './composables/useBoardDelta.js'

const queryClient = new QueryClient({
	defaultOptions: {
		queries: {
			staleTime: 30_000,
			retry: 1,
		},
	},
})

// Fragment-free deep link handoff (#3744): the server route /card/{id}
// validated access and left the target in the `openCard` initial state
// (a base64-JSON <input> NC renders into the page). The SPA is hash-routed,
// so we translate the server target into the card-modal hash route before
// the first paint. Hand-rolled reader (the element is trivial) - no extra
// @nextcloud/initial-state dependency for one key.
function readOpenCardState() {
	const el = document.getElementById('initial-state-kanso-openCard')
	if (!el) {
		return null
	}
	try {
		const state = JSON.parse(atob(el.value))
		const boardId = Number(state?.boardId)
		const cardId = Number(state?.cardId)
		// Positive integers only - a malformed blob must not produce a junk
		// route like /board/undefined/card/NaN.
		return Number.isInteger(boardId) && boardId > 0 && Number.isInteger(cardId) && cardId > 0
			? { boardId, cardId }
			: null
	} catch {
		return null
	}
}

createApp(App)
	.use(router)
	.use(VueQueryPlugin, { queryClient })
	.mount(document.getElementById('kanso'))

// The replace must wait for the router's INITIAL navigation (started by the
// mount above): a replace issued before it is clobbered when the initial
// '/' navigation settles.
const openCard = readOpenCardState()
if (openCard) {
	router.isReady().then(() => {
		router.replace(`/board/${openCard.boardId}/card/${openCard.cardId}`)
	})
}

// Realtime: a push event means someone changed the board - delta-sync it (#3675)
// instead of re-downloading the whole board. syncBoardDelta fetches only the
// changes since our cursor and PATCHES the cache (O(delta), not O(boardSize));
// on a resync signal or error it falls back to a full board invalidate, and it
// re-checks the move-pending guard internally so it never clobbers an optimistic
// move (the move queue's drain invalidate reconciles afterwards).
initRealtime((boardId) => {
	if (isBoardMovePending(boardId)) {
		return
	}
	syncBoardDelta(queryClient, boardId)
})
