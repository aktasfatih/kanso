<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\AclMapper;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PermissionServiceTest extends TestCase {
	private AclMapper&MockObject $aclMapper;
	private IGroupManager&MockObject $groupManager;
	private IUserManager&MockObject $userManager;
	private PermissionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->aclMapper = $this->createMock(AclMapper::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->service = new PermissionService(
			$this->aclMapper,
			$this->groupManager,
			$this->userManager
		);
	}

	private function board(string $owner = 'alice', int $id = 1): Board {
		$board = new Board();
		$board->setId($id);
		$board->setOwner($owner);
		return $board;
	}

	private function acl(int $participantType, string $participant, int $permission): Acl {
		$acl = new Acl();
		$acl->setParticipantType($participantType);
		$acl->setParticipant($participant);
		$acl->setPermission($permission);
		return $acl;
	}

	public function testOwnerHasAllPermissions(): void {
		$board = $this->board('alice');
		$this->aclMapper->expects(self::never())->method('findByBoard');

		self::assertSame(
			PermissionService::PERMISSION_ALL,
			$this->service->getPermissions($board, 'alice')
		);
		$this->service->assertPermission($board, 'alice', PermissionService::PERMISSION_MANAGE);
	}

	public function testUserAclRowGrantsRead(): void {
		$board = $this->board('alice');
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([
			$this->acl(Acl::TYPE_USER, 'bob', PermissionService::PERMISSION_READ),
		]);

		$this->service->assertPermission($board, 'bob', PermissionService::PERMISSION_READ);
		self::assertSame(
			PermissionService::PERMISSION_READ,
			$this->service->getPermissions($board, 'bob')
		);
	}

	public function testUserAclRowWithReadDoesNotGrantEdit(): void {
		$board = $this->board('alice');
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([
			$this->acl(Acl::TYPE_USER, 'bob', PermissionService::PERMISSION_READ),
		]);

		$this->expectException(NotPermittedException::class);
		$this->service->assertPermission($board, 'bob', PermissionService::PERMISSION_EDIT);
	}

	public function testGroupAclRowGrantsPermissionToMember(): void {
		$board = $this->board('alice');
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([
			$this->acl(
				Acl::TYPE_GROUP,
				'devs',
				PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT
			),
		]);

		$bob = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('bob')->willReturn($bob);
		$this->groupManager->method('getUserGroupIds')->with($bob)->willReturn(['devs', 'staff']);

		$this->service->assertPermission($board, 'bob', PermissionService::PERMISSION_EDIT);
		self::assertSame(
			PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT,
			$this->service->getPermissions($board, 'bob')
		);
	}

	public function testGroupAclRowDoesNotGrantToNonMember(): void {
		$board = $this->board('alice');
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([
			$this->acl(Acl::TYPE_GROUP, 'devs', PermissionService::PERMISSION_READ),
		]);

		$eve = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('eve')->willReturn($eve);
		$this->groupManager->method('getUserGroupIds')->with($eve)->willReturn(['guests']);

		$this->expectException(NotPermittedException::class);
		$this->service->assertPermission($board, 'eve', PermissionService::PERMISSION_READ);
	}

	public function testNoAclRowsDeniesRead(): void {
		$board = $this->board('alice');
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([]);

		$this->expectException(NotPermittedException::class);
		$this->service->assertPermission($board, 'bob', PermissionService::PERMISSION_READ);
	}

	public function testGetPermissionsForBoardsOwnerGetsAllWithoutAclFetch(): void {
		// Every board is owned by the caller → PERMISSION_ALL each, and the ACL
		// table is never touched.
		$this->aclMapper->expects(self::never())->method('findByBoards');

		$map = $this->service->getPermissionsForBoards(
			[$this->board('alice', 1), $this->board('alice', 2)],
			'alice'
		);

		self::assertSame([
			1 => PermissionService::PERMISSION_ALL,
			2 => PermissionService::PERMISSION_ALL,
		], $map);
	}

	public function testGetPermissionsForBoardsBatchesSharedBoardsIntoOneAclFetch(): void {
		$owned = $this->board('bob', 1);
		$sharedDirect = $this->board('alice', 2);
		$sharedViaGroup = $this->board('alice', 3);
		$noAccess = $this->board('alice', 4);

		// ONE batched fetch over exactly the non-owned board ids — never a
		// per-board findByBoard() loop.
		$readEdit = PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT;
		$directAcl = $this->acl(Acl::TYPE_USER, 'bob', $readEdit);
		$directAcl->setBoardId(2);
		$otherUserAcl = $this->acl(Acl::TYPE_USER, 'carol', PermissionService::PERMISSION_ALL);
		$otherUserAcl->setBoardId(4);
		$groupAcl = $this->acl(Acl::TYPE_GROUP, 'devs', PermissionService::PERMISSION_READ);
		$groupAcl->setBoardId(3);
		$foreignGroupAcl = $this->acl(Acl::TYPE_GROUP, 'guests', PermissionService::PERMISSION_ALL);
		$foreignGroupAcl->setBoardId(4);
		$this->aclMapper->expects(self::once())
			->method('findByBoards')
			->with([2, 3, 4])
			->willReturn([$directAcl, $otherUserAcl, $groupAcl, $foreignGroupAcl]);

		$bob = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('bob')->willReturn($bob);
		$this->groupManager->method('getUserGroupIds')->with($bob)->willReturn(['devs']);

		$map = $this->service->getPermissionsForBoards(
			[$owned, $sharedDirect, $sharedViaGroup, $noAccess],
			'bob'
		);

		self::assertSame([
			// Owned → full bitmask without any ACL row.
			1 => PermissionService::PERMISSION_ALL,
			// Shared directly → exactly the granted (reduced) bits.
			2 => $readEdit,
			// Shared via a group bob belongs to → the group's bits.
			3 => PermissionService::PERMISSION_READ,
			// Rows addressing other users/groups grant nothing.
			4 => 0,
		], $map);
	}

	public function testGetPermissionsForBoardsWithEmptySetSkipsTheAclFetch(): void {
		$this->aclMapper->expects(self::never())->method('findByBoards');

		self::assertSame([], $this->service->getPermissionsForBoards([], 'bob'));
	}

	public function testGetUserGroupIdsForUnknownUserIsEmpty(): void {
		$this->userManager->method('get')->with('ghost')->willReturn(null);
		$this->groupManager->expects(self::never())->method('getUserGroupIds');

		self::assertSame([], $this->service->getUserGroupIds('ghost'));
	}
}
