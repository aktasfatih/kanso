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
	private IRequest&MockObject $request;
	private IUserSession&MockObject $userSession;
	private IConfig&MockObject $config;
	private ViewService&MockObject $viewService;
	private ViewController $controller;

	/** In-memory user-config store keyed by config key, seeded per test. */
	private string $stored = '';

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->config = $this->createMock(IConfig::class);
		$this->viewService = $this->createMock(ViewService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		// Back the user-config mock with an in-memory value so reads see writes.
		$this->config->method('getUserValue')
			->willReturnCallback(fn (string $uid, string $app, string $key, $default = '') => $this->stored !== '' ? $this->stored : $default);
		$this->config->method('setUserValue')
			->willReturnCallback(function (string $uid, string $app, string $key, $value): void {
				$this->stored = (string)$value;
			});

		$this->controller = new ViewController(
			'kanso',
			$this->request,
			$this->userSession,
			$this->config,
			$this->viewService,
		);
	}

	public function testIndexEmptyByDefault(): void {
		$response = $this->controller->index();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData()['views']);
	}

	public function testCreateMintsIdAndPersists(): void {
		$response = $this->controller->create('Overdue everywhere', ['done' => 'open', 'due' => 'overdue'], 'board', 'timeline');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$views = $response->getData()['views'];
		self::assertCount(1, $views);
		self::assertSame('Overdue everywhere', $views[0]['name']);
		self::assertSame('board', $views[0]['groupBy']);
		self::assertSame('timeline', $views[0]['display']);
		self::assertSame(['done' => 'open', 'due' => 'overdue'], $views[0]['filter']);
		self::assertNotEmpty($views[0]['id']);

		// It survives to a fresh index read.
		self::assertCount(1, $this->controller->index()->getData()['views']);
	}

	public function testCreateUpsertsByNameKeepingTheId(): void {
		$id = $this->controller->create('Mine', ['a' => 1])->getData()['views'][0]['id'];
		$views = $this->controller->create('Mine', ['b' => 2], 'priority', 'list')->getData()['views'];
		self::assertCount(1, $views);
		self::assertSame($id, $views[0]['id']);
		self::assertSame(['b' => 2], $views[0]['filter']);
		self::assertSame('priority', $views[0]['groupBy']);
	}

	public function testCreateRejectsBlankName(): void {
		$response = $this->controller->create('   ', ['a' => 1]);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateRejectsMissingFilter(): void {
		$response = $this->controller->create('X', null);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateRejectsUnknownGroupByAndDisplay(): void {
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller->create('X', ['a' => 1], 'nope')->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller->create('X', ['a' => 1], 'status', 'grid')->getStatus());
	}

	public function testCreateAcceptsKanbanDisplay(): void {
		$response = $this->controller->create('Kanban view', ['a' => 1], 'status', 'kanban');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$views = $response->getData()['views'];
		self::assertSame('kanban', $views[0]['display']);
	}

	public function testRenameChangesNameByIdAndRejectsDuplicate(): void {
		$id = $this->controller->create('First', ['a' => 1])->getData()['views'][0]['id'];
		$this->controller->create('Second', ['b' => 2]);

		$ok = $this->controller->rename($id, 'Renamed');
		self::assertSame(Http::STATUS_OK, $ok->getStatus());
		$names = array_column($ok->getData()['views'], 'name');
		self::assertContains('Renamed', $names);
		self::assertNotContains('First', $names);

		// Renaming to an existing OTHER view's name is rejected.
		$dupe = $this->controller->rename($id, 'Second');
		self::assertSame(Http::STATUS_BAD_REQUEST, $dupe->getStatus());
	}

	public function testRenameUnknownIdIs400(): void {
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller->rename('deadbeef', 'X')->getStatus());
	}

	public function testDestroyRemovesByIdIdempotently(): void {
		$id = $this->controller->create('Doomed', ['a' => 1])->getData()['views'][0]['id'];
		$after = $this->controller->destroy($id);
		self::assertSame(Http::STATUS_OK, $after->getStatus());
		self::assertSame([], $after->getData()['views']);
		// Deleting again is a no-op, not an error.
		self::assertSame(Http::STATUS_OK, $this->controller->destroy($id)->getStatus());
	}

	public function testCardsDelegatesToServiceForCurrentUser(): void {
		// The service returns the capped envelope; the controller passes it through.
		$feed = [
			'cards' => [['id' => 1, 'boardId' => 3, 'boardTitle' => 'B']],
			'capped' => false,
			'total' => 1,
			'limit' => 5000,
		];
		$this->viewService->expects(self::once())
			->method('findMine')->with('alice')->willReturn($feed);

		$response = $this->controller->cards();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($feed, $response->getData());
	}
}
