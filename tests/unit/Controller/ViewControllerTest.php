<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\ViewController;
use OCA\Kanso\Service\ViewService;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ViewControllerTest extends TestCase {
	private IConfig&MockObject $config;
	private ViewService&MockObject $viewService;
	private ViewController $controller;

	/** @var array<string, string> in-memory user-config backing store */
	private array $stored = [];

	protected function setUp(): void {
		parent::setUp();
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$this->config = $this->createMock(IConfig::class);
		$this->viewService = $this->createMock(ViewService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession->method('getUser')->willReturn($user);

		// Round-trip user config against an in-memory store so create/delete are
		// observable through index().
		$this->stored = [];
		$this->config->method('getUserValue')
			->willReturnCallback(function (string $uid, string $app, string $key, string $default): string {
				self::assertSame('alice', $uid);
				self::assertSame('kanso', $app);
				return $this->stored[$key] ?? $default;
			});
		$this->config->method('setUserValue')
			->willReturnCallback(function (string $uid, string $app, string $key, string $value): void {
				$this->stored[$key] = $value;
			});

		$this->controller = new ViewController('kanso', $request, $userSession, $this->config, $this->viewService);
	}

	public function testIndexEmptyByDefault(): void {
		self::assertSame(['views' => []], $this->controller->index()->getData());
	}

	public function testCreateThenIndexRoundTrips(): void {
		$filter = ['labels' => [1, 2], 'due' => 'overdue'];
		$res = $this->controller->create('My urgent', $filter)->getData();
		self::assertSame(['views' => [['name' => 'My urgent', 'filter' => $filter]]], $res);

		// Persisted as a FLAT array under saved_views (board-agnostic).
		self::assertSame('[{"name":"My urgent","filter":{"labels":[1,2],"due":"overdue"}}]', $this->stored['saved_views']);
		self::assertSame($res, $this->controller->index()->getData());
	}

	public function testCreateUpsertsByName(): void {
		$this->controller->create('View A', ['due' => 'week']);
		$this->controller->create('View B', ['done' => 'open']);
		// Same name overwrites the filter, does not append.
		$res = $this->controller->create('View A', ['priorities' => [4]])->getData();

		self::assertSame(
			[
				['name' => 'View A', 'filter' => ['priorities' => [4]]],
				['name' => 'View B', 'filter' => ['done' => 'open']],
			],
			$res['views'],
		);
	}

	public function testCreateRejectsBlankName(): void {
		$res = $this->controller->create('   ', ['due' => 'overdue']);
		self::assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
		self::assertArrayNotHasKey('saved_views', $this->stored);
	}

	public function testCreateRejectsMissingFilter(): void {
		$res = $this->controller->create('No filter', null);
		self::assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
	}

	public function testDeleteRemovesByNameAndIsIdempotent(): void {
		$this->controller->create('Keep', ['due' => 'week']);
		$this->controller->create('Drop', ['done' => 'done']);

		$res = $this->controller->destroy('Drop')->getData();
		self::assertSame([['name' => 'Keep', 'filter' => ['due' => 'week']]], $res['views']);

		// Deleting a missing name is a no-op (idempotent).
		$again = $this->controller->destroy('Drop')->getData();
		self::assertSame([['name' => 'Keep', 'filter' => ['due' => 'week']]], $again['views']);
	}

	public function testCardsDelegatesToServiceForCurrentUser(): void {
		$feed = [
			'cards' => [['id' => 1, 'boardId' => 3, 'title' => 'x']],
			'labels' => [['id' => 7, 'title' => 'Urgent', 'color' => 'ff0000']],
			'participants' => [['uid' => 'bob', 'displayName' => 'Bob Baker']],
		];
		$this->viewService->expects(self::once())
			->method('findMine')
			->with('alice')
			->willReturn($feed);

		self::assertSame($feed, $this->controller->cards()->getData());
	}
}
