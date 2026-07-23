// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { createApp } from 'vue'
import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import App from './App.vue'
import { router } from './router/index.js'
import { initRealtime } from './services/realtime.js'
import { isBoardMovePending } from './composables/useCardMove.js'

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

// Realtime: a push event means someone changed the board — refetch it,
// unless our own moves are still in flight (the move queue's drain
// invalidate syncs afterwards; refetching now would show pre-move state).
// Board query keys are ['board', <route param>], hence String(boardId).
initRealtime((boardId) => {
	if (isBoardMovePending(boardId)) {
		return
	}
	queryClient.invalidateQueries({ queryKey: ['board', String(boardId)] })
})
