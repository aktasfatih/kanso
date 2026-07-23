<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		['name' => 'board#index', 'url' => '/api/boards', 'verb' => 'GET'],
		['name' => 'board#create', 'url' => '/api/boards', 'verb' => 'POST'],
		['name' => 'board#show', 'url' => '/api/boards/{id}', 'verb' => 'GET'],
		['name' => 'board#update', 'url' => '/api/boards/{id}', 'verb' => 'PATCH'],
		['name' => 'board#destroy', 'url' => '/api/boards/{id}', 'verb' => 'DELETE'],

		['name' => 'stack#create', 'url' => '/api/stacks', 'verb' => 'POST'],
		['name' => 'stack#update', 'url' => '/api/stacks/{id}', 'verb' => 'PATCH'],
		['name' => 'stack#destroy', 'url' => '/api/stacks/{id}', 'verb' => 'DELETE'],

		['name' => 'card#create', 'url' => '/api/cards', 'verb' => 'POST'],
		['name' => 'card#show', 'url' => '/api/cards/{id}', 'verb' => 'GET'],
		['name' => 'card#update', 'url' => '/api/cards/{id}', 'verb' => 'PATCH'],
		['name' => 'card#destroy', 'url' => '/api/cards/{id}', 'verb' => 'DELETE'],
		['name' => 'card#move', 'url' => '/api/cards/{id}/move', 'verb' => 'POST'],
		['name' => 'card#assignLabel', 'url' => '/api/cards/{id}/labels/{labelId}', 'verb' => 'PUT'],
		['name' => 'card#unassignLabel', 'url' => '/api/cards/{id}/labels/{labelId}', 'verb' => 'DELETE'],

		['name' => 'label#create', 'url' => '/api/labels', 'verb' => 'POST'],
		['name' => 'label#update', 'url' => '/api/labels/{id}', 'verb' => 'PATCH'],
		['name' => 'label#destroy', 'url' => '/api/labels/{id}', 'verb' => 'DELETE'],
	],
];
