// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { createApp } from 'vue'
import { VueQueryPlugin } from '@tanstack/vue-query'
import App from './App.vue'
import { router } from './router/index.js'

createApp(App)
	.use(router)
	.use(VueQueryPlugin, {
		queryClientConfig: {
			defaultOptions: {
				queries: {
					staleTime: 30_000,
					retry: 1,
				},
			},
		},
	})
	.mount(document.getElementById('kanso'))
