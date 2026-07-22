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
	],
];
