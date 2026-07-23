<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\AclController;
use OCA\Kanso\Db\Acl;
use OCA\Kanso\Service\AclService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AclControllerTest extends TestCase {
	private AclService&MockObject $aclService;
	private AclController $controller;

	protected function setUp(): void {
		parent::setUp();
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$this->aclService = $this->createMock(AclService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession->method('getUser')->willReturn($user);

		$this->controller = new AclController(
			'kanso',
			$request,
			$userSession,
			$this->aclService
		);
	}

	private function acl(int $id = 40): Acl {
		$acl = new Acl();
		$acl->setId($id);
		$acl->setBoardId(1);
		$acl->setParticipantType(Acl::TYPE_USER);
		$acl->setParticipant('bob');
		$acl->setPermission(PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT);
		return $acl;
	}

	public function testCreateReturnsAcl(): void {
		$acl = $this->acl();
		$this->aclService->method('create')
			->with(1, 'bob', 'user', 3, 'alice')
			->willReturn($acl);

		$response = $this->controller->create(1, 'bob', 'user', 3);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($acl, $response->getData());
	}

	public function testCreateSerializesParticipantTypeAsString(): void {
		$this->aclService->method('create')->willReturn($this->acl());

		$response = $this->controller->create(1, 'bob', 'user', 3);
		$data = $response->getData();
		self::assertInstanceOf(Acl::class, $data);
		self::assertSame('user', $data->jsonSerialize()['participantType']);
	}

	public function testCreateMapsNotPermittedTo403(): void {
		$this->aclService->method('create')->willThrowException(new NotPermittedException());

		$response = $this->controller->create(1, 'bob', 'user', 3);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testCreateMapsInvalidInputTo400(): void {
		$this->aclService->method('create')
			->willThrowException(new InvalidInputException('Already shared with this participant'));

		$response = $this->controller->create(1, 'bob', 'user', 3);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('Already shared with this participant', $response->getData()['error']);
	}

	public function testCreateMapsDoesNotExistTo404(): void {
		$this->aclService->method('create')
			->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->create(1, 'bob', 'user', 3);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testUpdateReturnsAcl(): void {
		$acl = $this->acl();
		$this->aclService->method('update')
			->with(1, 40, 7, 'alice')
			->willReturn($acl);

		$response = $this->controller->update(1, 40, 7);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($acl, $response->getData());
	}

	public function testUpdateMapsNotPermittedTo403(): void {
		$this->aclService->method('update')->willThrowException(new NotPermittedException());

		$response = $this->controller->update(1, 40, 7);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testUpdateMapsInvalidInputTo400(): void {
		$this->aclService->method('update')
			->willThrowException(new InvalidInputException('ACL entry does not belong to this board'));

		$response = $this->controller->update(1, 40, 7);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateMapsDoesNotExistTo404(): void {
		$this->aclService->method('update')
			->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->update(1, 40, 7);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testDestroyReturnsEmptyBody(): void {
		$this->aclService->expects(self::once())->method('delete')->with(1, 40, 'alice');

		$response = $this->controller->destroy(1, 40);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData());
	}

	public function testDestroyMapsNotPermittedTo403(): void {
		$this->aclService->method('delete')->willThrowException(new NotPermittedException());

		$response = $this->controller->destroy(1, 40);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testDestroyMapsDoesNotExistTo404(): void {
		$this->aclService->method('delete')
			->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->destroy(1, 40);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testSearchReturnsResults(): void {
		$results = [
			['id' => 'bob', 'displayName' => 'Bob Baker', 'type' => 'user'],
			['id' => 'devs', 'displayName' => 'Developers', 'type' => 'group'],
		];
		$this->aclService->method('search')
			->with(1, 'bo', 'alice')
			->willReturn($results);

		$response = $this->controller->search(1, 'bo');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($results, $response->getData());
	}

	public function testSearchMapsNotPermittedTo403(): void {
		$this->aclService->method('search')->willThrowException(new NotPermittedException());

		$response = $this->controller->search(1, 'bo');
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}
}
