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
import { boardQueryKey } from './composables/queryKeys.js'

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

// Realtime: a push event means someone changed the board - refetch it,
// unless our own moves are still in flight (the move queue's drain
// invalidate syncs afterwards; refetching now would show pre-move state).
// Board query keys are ['board', <route param>]; boardQueryKey coerces the
// realtime boardId to the same string key the board query is registered under.
// Cancel any in-flight board refetch before invalidating (mirrors
// useBoardSubscription): otherwise a pre-change response already on the wire
// can settle after the push-triggered refetch and overwrite fresher data.
initRealtime(async (boardId) => {
	if (isBoardMovePending(boardId)) {
		return
	}
	const boardKey = boardQueryKey(boardId)
	await queryClient.cancelQueries({ queryKey: boardKey })
	queryClient.invalidateQueries({ queryKey: boardKey })
})
