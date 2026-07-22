// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { createRouter, createWebHashHistory } from 'vue-router'

const routes = [
	{
		path: '/',
		name: 'board-list',
		component: () => import('../views/BoardList.vue'),
	},
	{
		path: '/board/:id',
		name: 'board',
		component: () => import('../views/BoardView.vue'),
		props: true,
		children: [
			{
				path: 'card/:cardId',
				name: 'card-modal',
				component: () => import('../components/CardModal.vue'),
				props: true,
			},
		],
	},
]

export const router = createRouter({
	history: createWebHashHistory(),
	routes,
})
