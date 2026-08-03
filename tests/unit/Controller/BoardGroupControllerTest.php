<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\BoardGroupController;
use OCA\Kanso\Db\BoardGroup;
use OCA\Kanso\Service\BoardGroupService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BoardGroupControllerTest extends TestCase {
	private BoardGroupService&MockObject $service;
	private BoardGroupController $controller;

	protected function setUp(): void {
		parent::setUp();
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$this->service = $this->createMock(BoardGroupService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession->method('getUser')->willReturn($user);

		$this->controller = new BoardGroupController('kanso', $request, $userSession, $this->service);
	}

	public function testIndexReturnsGroups(): void {
		$this->service->method('listGroups')->with('alice')
			->willReturn([['id' => 1, 'name' => 'Work', 'sort' => 0, 'boardIds' => [5]]]);

		$data = $this->controller->index()->getData();
		self::assertSame([['id' => 1, 'name' => 'Work', 'sort' => 0, 'boardIds' => [5]]], $data);
	}

	public function testCreatePassesNameThrough(): void {
		$group = new BoardGroup();
		$group->setId(9);
		$group->setName('Backlog');
		$group->setSort(0);
		$this->service->expects(self::once())
			->method('createGroup')->with('alice', 'Backlog')->willReturn($group);

		$data = $this->controller->create('Backlog')->getData();
		// The controller returns the BoardGroup entity (JsonSerializable).
		self::assertInstanceOf(BoardGroup::class, $data);
		self::assertSame(['id' => 9, 'name' => 'Backlog', 'sort' => 0], $data->jsonSerialize());
	}

	public function testAssignReturns200OnSuccess(): void {
		$this->service->expects(self::once())->method('assignBoard')->with('alice', 2, 7);

		$response = $this->controller->assign(2, 7);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAssignBoardYouCannotReadIsForbidden(): void {
		// The service enforces board READ; a denial surfaces as HTTP 403.
		$this->service->method('assignBoard')
			->willThrowException(new NotPermittedException('nope'));

		$response = $this->controller->assign(2, 99);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['error' => 'Access denied'], $response->getData());
	}

	public function testRenameOfAnotherUsersFolderIsForbidden(): void {
		$this->service->method('renameGroup')
			->willThrowException(new NotPermittedException('not yours'));

		$response = $this->controller->rename(3, 'Hijack');
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testUnassignReturns200(): void {
		$this->service->expects(self::once())->method('unassignBoard')->with('alice', 7);

		$response = $this->controller->unassign(7);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}
}
