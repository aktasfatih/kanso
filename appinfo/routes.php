<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		['name' => 'search#index', 'url' => '/api/search', 'verb' => 'GET'],

		['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
		['name' => 'settings#update', 'url' => '/api/settings', 'verb' => 'PUT'],

		['name' => 'board#index', 'url' => '/api/boards', 'verb' => 'GET'],
		['name' => 'board#create', 'url' => '/api/boards', 'verb' => 'POST'],
		['name' => 'board#show', 'url' => '/api/boards/{id}', 'verb' => 'GET'],
		['name' => 'boardStats#show', 'url' => '/api/boards/{id}/stats', 'verb' => 'GET'],
		['name' => 'board#participants', 'url' => '/api/boards/{id}/participants', 'verb' => 'GET'],
		['name' => 'board#update', 'url' => '/api/boards/{id}', 'verb' => 'PATCH'],
		['name' => 'board#destroy', 'url' => '/api/boards/{id}', 'verb' => 'DELETE'],

		// The literal /search segment must precede the {aclId} routes so the
		// router never captures "search" as an id.
		['name' => 'acl#search', 'url' => '/api/boards/{id}/acl/search', 'verb' => 'GET'],
		['name' => 'acl#create', 'url' => '/api/boards/{id}/acl', 'verb' => 'POST'],
		['name' => 'acl#update', 'url' => '/api/boards/{id}/acl/{aclId}', 'verb' => 'PATCH'],
		['name' => 'acl#destroy', 'url' => '/api/boards/{id}/acl/{aclId}', 'verb' => 'DELETE'],

		['name' => 'stack#create', 'url' => '/api/stacks', 'verb' => 'POST'],
		['name' => 'stack#update', 'url' => '/api/stacks/{id}', 'verb' => 'PATCH'],
		['name' => 'stack#destroy', 'url' => '/api/stacks/{id}', 'verb' => 'DELETE'],
		['name' => 'stack#restore', 'url' => '/api/stacks/{id}/restore', 'verb' => 'POST'],
		['name' => 'stack#move', 'url' => '/api/stacks/{id}/move', 'verb' => 'POST'],

		['name' => 'trash#index', 'url' => '/api/boards/{id}/trash', 'verb' => 'GET'],
		['name' => 'trash#restore', 'url' => '/api/cards/{id}/restore', 'verb' => 'POST'],
		['name' => 'trash#purge', 'url' => '/api/cards/{id}/purge', 'verb' => 'DELETE'],

		['name' => 'card#create', 'url' => '/api/cards', 'verb' => 'POST'],
		['name' => 'card#show', 'url' => '/api/cards/{id}', 'verb' => 'GET'],
		['name' => 'card#update', 'url' => '/api/cards/{id}', 'verb' => 'PATCH'],
		['name' => 'card#destroy', 'url' => '/api/cards/{id}', 'verb' => 'DELETE'],
		['name' => 'card#move', 'url' => '/api/cards/{id}/move', 'verb' => 'POST'],
		['name' => 'card#setParent', 'url' => '/api/cards/{id}/parent', 'verb' => 'PUT'],
		['name' => 'card#assignLabel', 'url' => '/api/cards/{id}/labels/{labelId}', 'verb' => 'PUT'],
		['name' => 'card#unassignLabel', 'url' => '/api/cards/{id}/labels/{labelId}', 'verb' => 'DELETE'],
		['name' => 'card#assignUser', 'url' => '/api/cards/{id}/assignees/{userId}', 'verb' => 'PUT'],
		['name' => 'card#unassignUser', 'url' => '/api/cards/{id}/assignees/{userId}', 'verb' => 'DELETE'],

		['name' => 'inbox#index', 'url' => '/api/inbox', 'verb' => 'GET'],

		['name' => 'myCards#index', 'url' => '/api/my-cards', 'verb' => 'GET'],

		['name' => 'review#mine', 'url' => '/api/reviews/mine', 'verb' => 'GET'],
		['name' => 'review#request', 'url' => '/api/cards/{id}/reviews/{userId}', 'verb' => 'PUT'],
		['name' => 'review#withdraw', 'url' => '/api/cards/{id}/reviews/{reviewId}', 'verb' => 'DELETE'],
		['name' => 'review#setState', 'url' => '/api/cards/{id}/reviews/{reviewId}', 'verb' => 'PATCH'],

		['name' => 'project#index', 'url' => '/api/projects', 'verb' => 'GET'],
		['name' => 'project#create', 'url' => '/api/projects', 'verb' => 'POST'],
		['name' => 'project#update', 'url' => '/api/projects/{id}', 'verb' => 'PATCH'],
		['name' => 'project#destroy', 'url' => '/api/projects/{id}', 'verb' => 'DELETE'],
		['name' => 'project#cards', 'url' => '/api/projects/{id}/cards', 'verb' => 'GET'],
		['name' => 'project#stats', 'url' => '/api/projects/{id}/stats', 'verb' => 'GET'],
		['name' => 'project#addCard', 'url' => '/api/projects/{id}/cards/{cardId}', 'verb' => 'PUT'],
		['name' => 'project#removeCard', 'url' => '/api/projects/{id}/cards/{cardId}', 'verb' => 'DELETE'],

		['name' => 'activity#index', 'url' => '/api/cards/{cardId}/activity', 'verb' => 'GET'],

		['name' => 'subscription#index', 'url' => '/api/cards/{cardId}/subscription', 'verb' => 'GET'],
		['name' => 'subscription#subscribe', 'url' => '/api/cards/{cardId}/subscription', 'verb' => 'PUT'],
		['name' => 'subscription#unsubscribe', 'url' => '/api/cards/{cardId}/subscription', 'verb' => 'DELETE'],
		['name' => 'subscription#subscribeOther', 'url' => '/api/cards/{cardId}/subscription/{userId}', 'verb' => 'PUT'],
		['name' => 'subscription#unsubscribeOther', 'url' => '/api/cards/{cardId}/subscription/{userId}', 'verb' => 'DELETE'],
		['name' => 'deckImport#index', 'url' => '/api/deck-import/boards', 'verb' => 'GET'],
		['name' => 'deckImport#import', 'url' => '/api/deck-import/boards/{deckBoardId}', 'verb' => 'POST'],

		['name' => 'webhook#github', 'url' => '/api/boards/{id}/github-webhook', 'verb' => 'POST'],
		['name' => 'webhook#config', 'url' => '/api/boards/{id}/webhook', 'verb' => 'GET'],
		['name' => 'webhook#rotate', 'url' => '/api/boards/{id}/webhook/rotate', 'verb' => 'POST'],
		['name' => 'webhook#disable', 'url' => '/api/boards/{id}/webhook', 'verb' => 'DELETE'],

		['name' => 'cardLink#index', 'url' => '/api/cards/{cardId}/links', 'verb' => 'GET'],
		['name' => 'cardLink#create', 'url' => '/api/cards/{cardId}/links', 'verb' => 'POST'],
		['name' => 'cardLink#destroy', 'url' => '/api/cards/{cardId}/links/{linkId}', 'verb' => 'DELETE'],

		['name' => 'cardRelation#index', 'url' => '/api/cards/{cardId}/relations', 'verb' => 'GET'],
		['name' => 'cardRelation#create', 'url' => '/api/cards/{cardId}/relations', 'verb' => 'POST'],
		['name' => 'cardRelation#destroy', 'url' => '/api/cards/{cardId}/relations/{relationId}', 'verb' => 'DELETE'],

		['name' => 'subscription#boardIndex', 'url' => '/api/boards/{boardId}/subscription', 'verb' => 'GET'],
		['name' => 'subscription#boardSubscribe', 'url' => '/api/boards/{boardId}/subscription', 'verb' => 'PUT'],
		['name' => 'subscription#boardUnsubscribe', 'url' => '/api/boards/{boardId}/subscription', 'verb' => 'DELETE'],

		['name' => 'comment#index', 'url' => '/api/cards/{cardId}/comments', 'verb' => 'GET'],
		['name' => 'comment#create', 'url' => '/api/cards/{cardId}/comments', 'verb' => 'POST'],
		['name' => 'comment#update', 'url' => '/api/comments/{commentId}', 'verb' => 'PATCH'],
		['name' => 'comment#destroy', 'url' => '/api/comments/{commentId}', 'verb' => 'DELETE'],

		['name' => 'checklist#index', 'url' => '/api/cards/{cardId}/checklist', 'verb' => 'GET'],
		['name' => 'checklist#create', 'url' => '/api/cards/{cardId}/checklist', 'verb' => 'POST'],
		['name' => 'checklist#update', 'url' => '/api/checklist/{itemId}', 'verb' => 'PATCH'],
		['name' => 'checklist#move', 'url' => '/api/checklist/{itemId}/move', 'verb' => 'POST'],
		['name' => 'checklist#destroy', 'url' => '/api/checklist/{itemId}', 'verb' => 'DELETE'],

		['name' => 'label#create', 'url' => '/api/labels', 'verb' => 'POST'],
		['name' => 'label#update', 'url' => '/api/labels/{id}', 'verb' => 'PATCH'],
		['name' => 'label#destroy', 'url' => '/api/labels/{id}', 'verb' => 'DELETE'],

		['name' => 'reviewType#create', 'url' => '/api/review-types', 'verb' => 'POST'],
		['name' => 'reviewType#update', 'url' => '/api/review-types/{id}', 'verb' => 'PATCH'],
		['name' => 'reviewType#destroy', 'url' => '/api/review-types/{id}', 'verb' => 'DELETE'],

		['name' => 'archiveRule#index', 'url' => '/api/boards/{id}/archive-rules', 'verb' => 'GET'],
		['name' => 'archiveRule#create', 'url' => '/api/boards/{id}/archive-rules', 'verb' => 'POST'],
		['name' => 'archiveRule#update', 'url' => '/api/archive-rules/{id}', 'verb' => 'PATCH'],
		['name' => 'archiveRule#destroy', 'url' => '/api/archive-rules/{id}', 'verb' => 'DELETE'],
		['name' => 'archiveRule#archiveNow', 'url' => '/api/archive-rules/{id}/archive-now', 'verb' => 'POST'],

		['name' => 'recurRule#index', 'url' => '/api/boards/{id}/recur-rules', 'verb' => 'GET'],
		['name' => 'recurRule#create', 'url' => '/api/boards/{id}/recur-rules', 'verb' => 'POST'],
		['name' => 'recurRule#update', 'url' => '/api/recur-rules/{id}', 'verb' => 'PATCH'],
		['name' => 'recurRule#destroy', 'url' => '/api/recur-rules/{id}', 'verb' => 'DELETE'],
		['name' => 'recurRule#createNow', 'url' => '/api/recur-rules/{id}/create-now', 'verb' => 'POST'],

		['name' => 'automation#index', 'url' => '/api/boards/{id}/automation-rules', 'verb' => 'GET'],
		['name' => 'automation#create', 'url' => '/api/boards/{id}/automation-rules', 'verb' => 'POST'],
		['name' => 'automation#setEnabled', 'url' => '/api/automation-rules/{ruleId}', 'verb' => 'PATCH'],
		['name' => 'automation#destroy', 'url' => '/api/automation-rules/{ruleId}', 'verb' => 'DELETE'],
	],
];
