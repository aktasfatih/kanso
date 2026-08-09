<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\ViewerContext;
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

	// ── external-role permission cap (#3744) ──────────────────────────────────

	private function externalAcl(int $participantType, string $participant, int $permission): Acl {
		$acl = $this->acl($participantType, $participant, $permission);
		$acl->setRole(ViewerContext::ROLE_EXTERNAL);
		return $acl;
	}

	public function testExternalRowNeverYieldsManageOrShareEffectively(): void {
		// The backdoor this closes: an external ACL row storing MANAGE|SHARE
		// bits must not pass the MANAGE/SHARE asserts - otherwise an external
		// could edit the ACL / board settings, or SHARE new *internal* members
		// in (a visibility escalation past the role model).
		$board = $this->board('alice');
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([
			$this->externalAcl(Acl::TYPE_USER, 'client', PermissionService::PERMISSION_ALL),
		]);

		self::assertSame(
			PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT,
			$this->service->getPermissions($board, 'client')
		);
		$this->expectException(NotPermittedException::class);
		$this->service->assertPermission($board, 'client', PermissionService::PERMISSION_MANAGE);
	}

	public function testExternalRowStillGrantsReadAndEdit(): void {
		// The cap only strips the internal-only bits: an external with EDIT
		// keeps acting on the cards they can see.
		$board = $this->board('alice');
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([
			$this->externalAcl(
				Acl::TYPE_USER,
				'client',
				PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT
			),
		]);

		$this->service->assertPermission($board, 'client', PermissionService::PERMISSION_EDIT);
		self::assertSame(
			PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT,
			$this->service->getPermissions($board, 'client')
		);
	}

	public function testMixedRolesFoldInternalWinsAndKeepManage(): void {
		// A user matching an external user-row AND an internal group-row is
		// effectively internal (same internal-wins fold as BoardAccess) - the
		// cap must not strip their MANAGE.
		$board = $this->board('alice');
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([
			$this->externalAcl(Acl::TYPE_USER, 'bob', PermissionService::PERMISSION_READ),
			$this->acl(Acl::TYPE_GROUP, 'devs', PermissionService::PERMISSION_ALL),
		]);

		$bob = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('bob')->willReturn($bob);
		$this->groupManager->method('getUserGroupIds')->with($bob)->willReturn(['devs']);

		self::assertSame(PermissionService::PERMISSION_ALL, $this->service->getPermissions($board, 'bob'));
	}

	public function testNullRoleRowsReadAsInternalAndKeepManage(): void {
		// Rows predating the role column hydrate role=null → 'internal' (the
		// migration backfill); the cap must not regress pre-role boards.
		$board = $this->board('alice');
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([
			$this->acl(Acl::TYPE_USER, 'bob', PermissionService::PERMISSION_ALL),
		]);

		self::assertSame(PermissionService::PERMISSION_ALL, $this->service->getPermissions($board, 'bob'));
		$this->service->assertPermission($board, 'bob', PermissionService::PERMISSION_MANAGE);
	}

	public function testGetPermissionsForBoardsAppliesTheExternalCapPerBoard(): void {
		// The batch fold applies the same cap independently per board: bob is
		// external-with-ALL on board 2 (capped) and internal-with-ALL on 3 (not).
		$externalAcl = $this->externalAcl(Acl::TYPE_USER, 'bob', PermissionService::PERMISSION_ALL);
		$externalAcl->setBoardId(2);
		$internalAcl = $this->acl(Acl::TYPE_USER, 'bob', PermissionService::PERMISSION_ALL);
		$internalAcl->setBoardId(3);
		$this->aclMapper->expects(self::once())
			->method('findByBoards')
			->with([2, 3])
			->willReturn([$externalAcl, $internalAcl]);

		$map = $this->service->getPermissionsForBoards(
			[$this->board('alice', 2), $this->board('alice', 3)],
			'bob'
		);

		self::assertSame([
			2 => PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT,
			3 => PermissionService::PERMISSION_ALL,
		], $map);
	}
}
