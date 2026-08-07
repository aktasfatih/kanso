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

createApp(App)
	.use(router)
	.use(VueQueryPlugin, { queryClient })
	.mount(document.getElementById('kanso'))

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
