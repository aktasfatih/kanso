<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\AclMapper;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\ParticipantService;
use OCA\Kanso\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ParticipantServiceTest extends TestCase {
	private BoardMapper&MockObject $boardMapper;
	private AclMapper&MockObject $aclMapper;
	private PermissionService&MockObject $permissionService;
	private IUserManager&MockObject $userManager;
	private IGroupManager&MockObject $groupManager;
	private ParticipantService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->aclMapper = $this->createMock(AclMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->service = new ParticipantService(
			$this->boardMapper,
			$this->aclMapper,
			$this->permissionService,
			$this->userManager,
			$this->groupManager
		);
	}

	private function board(int $id = 1): Board {
		$board = new Board();
		$board->setId($id);
		$board->setOwner('alice');
		$board->setDeletedAt(0);
		return $board;
	}

	private function userAcl(string $uid): Acl {
		$acl = new Acl();
		$acl->setBoardId(1);
		$acl->setParticipantType(Acl::TYPE_USER);
		$acl->setParticipant($uid);
		$acl->setPermission(PermissionService::PERMISSION_READ);
		return $acl;
	}

	private function groupAcl(string $gid): Acl {
		$acl = new Acl();
		$acl->setBoardId(1);
		$acl->setParticipantType(Acl::TYPE_GROUP);
		$acl->setParticipant($gid);
		$acl->setPermission(PermissionService::PERMISSION_READ);
		return $acl;
	}

	private function user(string $uid, string $displayName): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($displayName);
		return $user;
	}

	public function testReturnsOwnerUserAclsAndExpandedGroupsSortedAndDeduped(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		// bob is shared with directly AND a member of 'devs' - must appear once.
		$this->aclMapper->method('findByBoard')->with(1)
			->willReturn([$this->userAcl('bob'), $this->groupAcl('devs')]);

		$this->userManager->method('get')->willReturnMap([
			['alice', $this->user('alice', 'Alice Adams')],
			['bob', $this->user('bob', 'Zed Bobson')],
		]);

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([
			$this->user('bob', 'Zed Bobson'),
			$this->user('carol', 'Carol Chen'),
		]);
		$this->groupManager->method('get')->with('devs')->willReturn($group);

		$participants = $this->service->getParticipants(1, 'alice');
		self::assertSame([
			['uid' => 'alice', 'displayName' => 'Alice Adams'],
			['uid' => 'carol', 'displayName' => 'Carol Chen'],
			['uid' => 'bob', 'displayName' => 'Zed Bobson'],
		], $participants);
	}

	public function testFallsBackToUidWhenUserIsUnresolvable(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		// An ACL row can outlive its user - the ghost stays listed by uid.
		$this->aclMapper->method('findByBoard')->with(1)
			->willReturn([$this->userAcl('deleted-user')]);
		$this->userManager->method('get')->willReturnMap([
			['alice', $this->user('alice', 'Alice Adams')],
			['deleted-user', null],
		]);

		$participants = $this->service->getParticipants(1, 'alice');
		self::assertSame([
			['uid' => 'alice', 'displayName' => 'Alice Adams'],
			['uid' => 'deleted-user', 'displayName' => 'deleted-user'],
		], $participants);
	}

	public function testSkipsUnresolvableGroups(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->aclMapper->method('findByBoard')->with(1)
			->willReturn([$this->groupAcl('vanished-group')]);
		$this->userManager->method('get')
			->with('alice')
			->willReturn($this->user('alice', 'Alice Adams'));
		$this->groupManager->method('get')->with('vanished-group')->willReturn(null);

		$participants = $this->service->getParticipants(1, 'alice');
		self::assertSame([
			['uid' => 'alice', 'displayName' => 'Alice Adams'],
		], $participants);
	}

	public function testAssertsReadPermission(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_READ)
			->willThrowException(new NotPermittedException());
		$this->aclMapper->expects(self::never())->method('findByBoard');

		$this->expectException(NotPermittedException::class);
		$this->service->getParticipants(1, 'mallory');
	}

	public function testRejectsDeletedBoard(): void {
		$board = $this->board();
		$board->setDeletedAt(1234);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->expects(self::never())->method('findByBoard');

		$this->expectException(DoesNotExistException::class);
		$this->service->getParticipants(1, 'alice');
	}
}
