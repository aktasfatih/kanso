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
	{
		// Standalone full-page card view (#3817). Top-level (NOT nested under
		// BoardView) so it renders full-page instead of as a board overlay. Shares
		// the CardDetail component with the nested card-modal route; the board id is
		// resolved from the loaded card, not the URL.
		path: '/card/:cardId',
		name: 'card-page',
		component: () => import('../views/CardPage.vue'),
		props: true,
	},
	{
		path: '/board/:id/stats',
		name: 'board-stats',
		component: () => import('../views/BoardStats.vue'),
		props: true,
	},
	{
		path: '/board/:id/archived',
		name: 'board-archived',
		component: () => import('../views/ArchivedView.vue'),
		props: true,
	},
	{
		path: '/board/:id/trash',
		name: 'board-trash',
		component: () => import('../views/TrashView.vue'),
		props: true,
	},
	{
		path: '/my-work',
		name: 'my-work',
		component: () => import('../views/MyWorkView.vue'),
	},
	{
		path: '/my-tasks',
		name: 'my-cards',
		component: () => import('../views/MyCardsView.vue'),
	},
	{
		path: '/reviews',
		name: 'my-reviews',
		component: () => import('../views/MyReviewsView.vue'),
	},
	{
		path: '/inbox',
		name: 'inbox',
		component: () => import('../views/InboxView.vue'),
	},
	{
		// Cross-board saved "Views" (#3815): a named saved filter over all
		// readable boards, opening a board-like List/Timeline surface.
		path: '/views/:id',
		name: 'view',
		component: () => import('../views/ViewPage.vue'),
		props: true,
	},
	{
		path: '/projects',
		name: 'projects',
		component: () => import('../views/ProjectsView.vue'),
	},
	{
		path: '/projects/:id',
		name: 'project',
		component: () => import('../views/ProjectView.vue'),
		props: true,
	},
	{
		path: '/projects/:id/stats',
		name: 'project-stats',
		component: () => import('../views/ProjectStats.vue'),
		props: true,
	},
]

export const router = createRouter({
	history: createWebHashHistory(),
	routes,
})
