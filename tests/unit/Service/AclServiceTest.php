<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\AclMapper;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Service\AclService;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AclServiceTest extends TestCase {
	private AclMapper&MockObject $aclMapper;
	private BoardMapper&MockObject $boardMapper;
	private CardAssigneeMapper&MockObject $cardAssigneeMapper;
	private ChangeNotifier&MockObject $changeNotifier;
	private PermissionService&MockObject $permissionService;
	private IUserManager&MockObject $userManager;
	private IGroupManager&MockObject $groupManager;
	private IShareManager&MockObject $shareManager;
	private AclService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->aclMapper = $this->createMock(AclMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->cardAssigneeMapper = $this->createMock(CardAssigneeMapper::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->shareManager = $this->createMock(IShareManager::class);
		// Default to a fully open instance (enumeration on, no group-only
		// restriction, group sharing allowed) - the pre-hardening behaviour.
		// Individual tests override these to drive the config-flag matrix.
		$this->shareManager->method('allowEnumeration')->willReturn(true);
		$this->shareManager->method('allowEnumerationFullMatch')->willReturn(true);
		$this->shareManager->method('limitEnumerationToGroups')->willReturn(false);
		$this->shareManager->method('shareWithGroupMembersOnly')->willReturn(false);
		$this->shareManager->method('shareWithGroupMembersOnlyExcludeGroupsList')->willReturn([]);
		$this->shareManager->method('allowGroupSharing')->willReturn(true);
		$this->service = new AclService(
			$this->aclMapper,
			$this->boardMapper,
			$this->cardAssigneeMapper,
			$this->changeNotifier,
			$this->permissionService,
			$this->userManager,
			$this->groupManager,
			$this->shareManager
		);
	}

	private function board(int $id = 1): Board {
		$board = new Board();
		$board->setId($id);
		$board->setOwner('alice');
		$board->setDeletedAt(0);
		return $board;
	}

	private function acl(int $id = 40, int $boardId = 1, string $participant = 'bob', int $type = Acl::TYPE_USER, int $permission = PermissionService::PERMISSION_READ): Acl {
		$acl = new Acl();
		$acl->setId($id);
		$acl->setBoardId($boardId);
		$acl->setParticipantType($type);
		$acl->setParticipant($participant);
		$acl->setPermission($permission);
		return $acl;
	}

	// ---- create -----------------------------------------------------------

	public function testCreateInsertsAclAndWritesChangeRow(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_SHARE);
		$this->permissionService->method('getPermissions')
			->with($board, 'alice')
			->willReturn(PermissionService::PERMISSION_ALL);
		$this->userManager->method('userExists')->with('bob')->willReturn(true);
		$this->aclMapper->method('findByParticipant')
			->with(1, Acl::TYPE_USER, 'bob')
			->willReturn(null);
		$this->aclMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (Acl $acl): Acl {
				self::assertSame(1, $acl->getBoardId());
				self::assertSame(Acl::TYPE_USER, $acl->getParticipantType());
				self::assertSame('bob', $acl->getParticipant());
				self::assertSame(
					PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT,
					$acl->getPermission()
				);
				$acl->setId(40);
				return $acl;
			});
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_ACL,
				40,
				Change::ACTION_CREATE,
				'alice'
			)
			->willReturn(new Change());

		$acl = $this->service->create(
			1,
			'bob',
			'user',
			PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT,
			'alice'
		);
		self::assertSame(40, $acl->getId());
	}

	public function testCreateAcceptsGroupParticipant(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->method('getPermissions')->willReturn(PermissionService::PERMISSION_ALL);
		$this->groupManager->method('groupExists')->with('devs')->willReturn(true);
		$this->userManager->expects(self::never())->method('userExists');
		$this->aclMapper->method('findByParticipant')
			->with(1, Acl::TYPE_GROUP, 'devs')
			->willReturn(null);
		$this->aclMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (Acl $acl): Acl {
				self::assertSame(Acl::TYPE_GROUP, $acl->getParticipantType());
				self::assertSame('devs', $acl->getParticipant());
				$acl->setId(41);
				return $acl;
			});
		$this->changeNotifier->expects(self::once())->method('notify')->willReturn(new Change());

		$this->service->create(1, 'devs', 'group', PermissionService::PERMISSION_READ, 'alice');
	}

	public function testCreateForcesReadBitWhenOmitted(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->method('getPermissions')->willReturn(PermissionService::PERMISSION_ALL);
		$this->userManager->method('userExists')->with('bob')->willReturn(true);
		$this->aclMapper->method('findByParticipant')->willReturn(null);
		$this->aclMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (Acl $acl): Acl {
				self::assertSame(
					PermissionService::PERMISSION_READ,
					$acl->getPermission() & PermissionService::PERMISSION_READ
				);
				self::assertSame(
					PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT,
					$acl->getPermission()
				);
				$acl->setId(40);
				return $acl;
			});
		$this->changeNotifier->expects(self::once())->method('notify')->willReturn(new Change());

		// EDIT only - READ must be forced into the stored mask.
		$this->service->create(1, 'bob', 'user', PermissionService::PERMISSION_EDIT, 'alice');
	}

	public function testCreateAssertsActorSharePermission(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_SHARE)
			->willThrowException(new NotPermittedException());
		$this->aclMapper->expects(self::never())->method('insert');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(NotPermittedException::class);
		$this->service->create(1, 'bob', 'user', PermissionService::PERMISSION_READ, 'mallory');
	}

	public function testCreateRejectsEscalationBeyondActorBitsWithoutManage(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		// carol holds SHARE but not MANAGE - granting MANAGE would escalate.
		$this->permissionService->method('getPermissions')
			->with($board, 'carol')
			->willReturn(
				PermissionService::PERMISSION_READ
				| PermissionService::PERMISSION_EDIT
				| PermissionService::PERMISSION_SHARE
			);
		$this->userManager->method('userExists')->with('bob')->willReturn(true);
		$this->aclMapper->expects(self::never())->method('insert');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(NotPermittedException::class);
		$this->service->create(1, 'bob', 'user', PermissionService::PERMISSION_MANAGE, 'carol');
	}

	public function testCreateAllowsGrantWithinActorBitsWithoutManage(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->method('getPermissions')
			->with($board, 'carol')
			->willReturn(
				PermissionService::PERMISSION_READ
				| PermissionService::PERMISSION_EDIT
				| PermissionService::PERMISSION_SHARE
			);
		$this->userManager->method('userExists')->with('bob')->willReturn(true);
		$this->aclMapper->method('findByParticipant')->willReturn(null);
		$this->aclMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (Acl $acl): Acl {
				$acl->setId(40);
				return $acl;
			});
		$this->changeNotifier->expects(self::once())->method('notify')->willReturn(new Change());

		$this->service->create(
			1,
			'bob',
			'user',
			PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT,
			'carol'
		);
	}

	public function testCreateRejectsUnknownPermissionBits(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('Unknown permission bits');
		$this->service->create(1, 'bob', 'user', 16, 'alice');
	}

	public function testCreateRejectsInvalidParticipantType(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->create(1, 'bob', 'circle', PermissionService::PERMISSION_READ, 'alice');
	}

	public function testCreateRejectsBoardOwnerAsParticipant(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('Cannot share a board with its owner');
		$this->service->create(1, 'alice', 'user', PermissionService::PERMISSION_READ, 'alice');
	}

	public function testCreateRejectsNonexistentUser(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->userManager->method('userExists')->with('ghost')->willReturn(false);
		$this->aclMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('User does not exist');
		$this->service->create(1, 'ghost', 'user', PermissionService::PERMISSION_READ, 'alice');
	}

	public function testCreateRejectsNonexistentGroup(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->groupManager->method('groupExists')->with('ghosts')->willReturn(false);
		$this->aclMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('Group does not exist');
		$this->service->create(1, 'ghosts', 'group', PermissionService::PERMISSION_READ, 'alice');
	}

	public function testCreateRejectsDuplicateShare(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->method('getPermissions')->willReturn(PermissionService::PERMISSION_ALL);
		$this->userManager->method('userExists')->with('bob')->willReturn(true);
		$this->aclMapper->method('findByParticipant')
			->with(1, Acl::TYPE_USER, 'bob')
			->willReturn($this->acl());
		$this->aclMapper->expects(self::never())->method('insert');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('Already shared with this participant');
		$this->service->create(1, 'bob', 'user', PermissionService::PERMISSION_READ, 'alice');
	}

	public function testCreateTreatsLostInsertRaceAsDuplicateShare(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->method('getPermissions')->willReturn(PermissionService::PERMISSION_ALL);
		$this->userManager->method('userExists')->with('bob')->willReturn(true);
		$this->aclMapper->method('findByParticipant')->willReturn(null);
		$uniqueViolation = $this->createMock(\OCP\DB\Exception::class);
		$uniqueViolation->method('getReason')
			->willReturn(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		$this->aclMapper->method('insert')->willThrowException($uniqueViolation);
		$this->changeNotifier->expects(self::never())->method('notify');

		// A concurrent POST winning the check-then-insert race must surface
		// as the same 400, not as a 500.
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('Already shared with this participant');
		$this->service->create(1, 'bob', 'user', PermissionService::PERMISSION_READ, 'alice');
	}

	public function testCreateRejectsDeletedBoard(): void {
		$board = $this->board();
		$board->setDeletedAt(1234);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->expects(self::never())->method('insert');

		$this->expectException(DoesNotExistException::class);
		$this->service->create(1, 'bob', 'user', PermissionService::PERMISSION_READ, 'alice');
	}

	// ---- update -----------------------------------------------------------

	public function testUpdateChangesPermissionAndWritesChangeRow(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_SHARE);
		$this->permissionService->method('getPermissions')
			->with($board, 'alice')
			->willReturn(PermissionService::PERMISSION_ALL);
		$this->aclMapper->method('find')->with(40)->willReturn($this->acl());
		$this->aclMapper->expects(self::once())
			->method('update')
			->willReturnCallback(static function (Acl $acl): Acl {
				self::assertSame(
					PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT,
					$acl->getPermission()
				);
				return $acl;
			});
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_ACL,
				40,
				Change::ACTION_UPDATE,
				'alice'
			)
			->willReturn(new Change());

		$this->service->update(
			1,
			40,
			PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT,
			'alice'
		);
	}

	public function testUpdateForcesReadBitWhenOmitted(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->method('getPermissions')->willReturn(PermissionService::PERMISSION_ALL);
		$this->aclMapper->method('find')->with(40)->willReturn($this->acl());
		$this->aclMapper->expects(self::once())
			->method('update')
			->willReturnCallback(static function (Acl $acl): Acl {
				self::assertSame(
					PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT,
					$acl->getPermission()
				);
				return $acl;
			});
		$this->changeNotifier->expects(self::once())->method('notify')->willReturn(new Change());

		// Mask 0 for a rule downgrade still keeps READ; EDIT here for variety.
		$this->service->update(1, 40, PermissionService::PERMISSION_EDIT, 'alice');
	}

	public function testUpdateRejectsUnknownPermissionBits(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->method('find')->with(40)->willReturn($this->acl());
		$this->aclMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->update(1, 40, 32, 'alice');
	}

	public function testUpdateRejectsAclOfAnotherBoard(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->method('find')->with(40)->willReturn($this->acl(40, 2));
		$this->aclMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('ACL entry does not belong to this board');
		$this->service->update(1, 40, PermissionService::PERMISSION_READ, 'alice');
	}

	public function testUpdateAssertsActorSharePermission(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_SHARE)
			->willThrowException(new NotPermittedException());
		$this->aclMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->update(1, 40, PermissionService::PERMISSION_READ, 'mallory');
	}

	public function testUpdateRejectsTogglingManageBitWithoutManage(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		// carol holds SHARE but not MANAGE; the existing rule has READ|EDIT.
		$this->permissionService->method('getPermissions')
			->with($board, 'carol')
			->willReturn(
				PermissionService::PERMISSION_READ
				| PermissionService::PERMISSION_EDIT
				| PermissionService::PERMISSION_SHARE
			);
		$this->aclMapper->method('find')->with(40)->willReturn(
			$this->acl(40, 1, 'bob', Acl::TYPE_USER, PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT)
		);
		$this->aclMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(NotPermittedException::class);
		$this->service->update(
			1,
			40,
			PermissionService::PERMISSION_READ
				| PermissionService::PERMISSION_EDIT
				| PermissionService::PERMISSION_MANAGE,
			'carol'
		);
	}

	public function testUpdateAllowsResendingUnchangedMaskWithBitsBeyondActor(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		// The existing rule already carries MANAGE, which carol lacks - but
		// re-sending the identical mask changes no bits, so the cap passes.
		$existingMask = PermissionService::PERMISSION_READ | PermissionService::PERMISSION_MANAGE;
		$this->permissionService->method('getPermissions')
			->with($board, 'carol')
			->willReturn(
				PermissionService::PERMISSION_READ
				| PermissionService::PERMISSION_EDIT
				| PermissionService::PERMISSION_SHARE
			);
		$this->aclMapper->method('find')->with(40)->willReturn(
			$this->acl(40, 1, 'bob', Acl::TYPE_USER, $existingMask)
		);
		$this->aclMapper->expects(self::once())
			->method('update')
			->willReturnCallback(static fn (Acl $acl): Acl => $acl);
		$this->changeNotifier->expects(self::once())->method('notify')->willReturn(new Change());

		$acl = $this->service->update(1, 40, $existingMask, 'carol');
		self::assertSame($existingMask, $acl->getPermission());
	}

	// ---- delete -----------------------------------------------------------

	public function testDeleteRemovesAclAndWritesChangeRow(): void {
		$board = $this->board();
		$acl = $this->acl();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->method('find')->with(40)->willReturn($acl);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_MANAGE);
		// bob keeps nothing after the delete - his assignments get cleaned up.
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')
			->willReturn(0);
		$this->aclMapper->expects(self::once())->method('delete')->with($acl);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_ACL,
				40,
				Change::ACTION_DELETE,
				'alice'
			)
			->willReturn(new Change());
		$this->cardAssigneeMapper->expects(self::once())
			->method('deleteByBoardAndUser')
			->with(1, 'bob')
			->willReturn(2);

		$this->service->delete(1, 40, 'alice');
	}

	public function testDeleteRejectsAclOfAnotherBoard(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->method('find')->with(40)->willReturn($this->acl(40, 2));
		$this->aclMapper->expects(self::never())->method('delete');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('ACL entry does not belong to this board');
		$this->service->delete(1, 40, 'alice');
	}

	public function testDeleteAssertsManageForForeignEntries(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->method('find')->with(40)->willReturn($this->acl());
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'carol', PermissionService::PERMISSION_MANAGE)
			->willThrowException(new NotPermittedException());
		$this->aclMapper->expects(self::never())->method('delete');
		$this->changeNotifier->expects(self::never())->method('notify');
		$this->cardAssigneeMapper->expects(self::never())->method('deleteByBoardAndUser');

		$this->expectException(NotPermittedException::class);
		$this->service->delete(1, 40, 'carol');
	}

	public function testDeleteAllowsSelfRemovalWithoutManageAndCleansUpAssignments(): void {
		$board = $this->board();
		$acl = $this->acl(40, 1, 'bob');
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->method('find')->with(40)->willReturn($acl);
		// bob removes himself: no permission assertion at all.
		$this->permissionService->expects(self::never())->method('assertPermission');
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')
			->willReturn(0);
		$this->aclMapper->expects(self::once())->method('delete')->with($acl);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_ACL,
				40,
				Change::ACTION_DELETE,
				'bob'
			)
			->willReturn(new Change());
		$this->cardAssigneeMapper->expects(self::once())
			->method('deleteByBoardAndUser')
			->with(1, 'bob')
			->willReturn(1);

		$this->service->delete(1, 40, 'bob');
	}

	public function testDeleteSkipsCleanupWhenUserRetainsReadViaGroupAcl(): void {
		$board = $this->board();
		$acl = $this->acl(40, 1, 'bob');
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->method('find')->with(40)->willReturn($acl);
		// After the user rule is gone, bob still holds READ through a group
		// rule - his assignments stay valid.
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')
			->willReturn(PermissionService::PERMISSION_READ);
		$this->aclMapper->expects(self::once())->method('delete')->with($acl);
		$this->changeNotifier->expects(self::once())->method('notify')->willReturn(new Change());
		$this->cardAssigneeMapper->expects(self::never())->method('deleteByBoardAndUser');

		$this->service->delete(1, 40, 'alice');
	}

	public function testDeleteSkipsCleanupForGroupEntries(): void {
		$board = $this->board();
		$acl = $this->acl(40, 1, 'devs', Acl::TYPE_GROUP);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->method('find')->with(40)->willReturn($acl);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_MANAGE);
		$this->permissionService->expects(self::never())->method('getPermissions');
		$this->aclMapper->expects(self::once())->method('delete')->with($acl);
		$this->changeNotifier->expects(self::once())->method('notify')->willReturn(new Change());
		// Group unshares defer stale-assignee cleanup (Backlog #3393).
		$this->cardAssigneeMapper->expects(self::never())->method('deleteByBoardAndUser');

		$this->service->delete(1, 40, 'alice');
	}

	// ---- search -----------------------------------------------------------

	private function user(string $uid, string $displayName): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($displayName);
		return $user;
	}

	private function group(string $gid, string $displayName): IGroup&MockObject {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($gid);
		$group->method('getDisplayName')->willReturn($displayName);
		return $group;
	}

	public function testSearchReturnsMatchingUsersAndGroups(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_SHARE);
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([]);
		$this->userManager->method('searchDisplayName')
			->with('bo', 25)
			->willReturn([$this->user('bob', 'Bob Baker')]);
		$this->groupManager->method('search')
			->with('bo', 25)
			->willReturn([$this->group('board-fans', 'Board Fans')]);

		$results = $this->service->search(1, 'bo', 'alice');
		self::assertSame([
			['id' => 'bob', 'displayName' => 'Bob Baker', 'type' => 'user'],
			['id' => 'board-fans', 'displayName' => 'Board Fans', 'type' => 'group'],
		], $results);
	}

	public function testSearchExcludesOwnerAndExistingParticipants(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([
			$this->acl(40, 1, 'bob', Acl::TYPE_USER),
			$this->acl(41, 1, 'devs', Acl::TYPE_GROUP),
		]);
		$this->userManager->method('searchDisplayName')->with('al', 25)->willReturn([
			$this->user('alice', 'Alice Adams'), // owner
			$this->user('bob', 'Bob Baker'), // already shared
			$this->user('carol', 'Carol Cook'),
		]);
		$this->groupManager->method('search')->with('al', 25)->willReturn([
			$this->group('devs', 'Developers'), // already shared
			$this->group('qa', 'QA Team'),
		]);

		$results = $this->service->search(1, 'al', 'alice');
		self::assertSame([
			['id' => 'carol', 'displayName' => 'Carol Cook', 'type' => 'user'],
			['id' => 'qa', 'displayName' => 'QA Team', 'type' => 'group'],
		], $results);
	}

	public function testSearchFallsBackToGidForEmptyGroupDisplayName(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([]);
		$this->userManager->method('searchDisplayName')->willReturn([]);
		$this->groupManager->method('search')->willReturn([$this->group('devs', '')]);

		$results = $this->service->search(1, 'devs', 'alice');
		self::assertSame([
			['id' => 'devs', 'displayName' => 'devs', 'type' => 'group'],
		], $results);
	}

	public function testSearchAssertsActorSharePermission(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_SHARE)
			->willThrowException(new NotPermittedException());
		$this->userManager->expects(self::never())->method('searchDisplayName');
		$this->groupManager->expects(self::never())->method('search');

		$this->expectException(NotPermittedException::class);
		$this->service->search(1, 'bo', 'mallory');
	}

	public function testSearchReturnsEmptyBelowMinQueryLength(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		// The SHARE assertion still runs, but no enumeration happens.
		$this->permissionService->expects(self::exactly(2))
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_SHARE);
		$this->aclMapper->expects(self::never())->method('findByBoard');
		$this->userManager->expects(self::never())->method('searchDisplayName');
		$this->groupManager->expects(self::never())->method('search');

		// One char (after trim) and empty both fall under the floor.
		self::assertSame([], $this->service->search(1, ' a ', 'alice'));
		self::assertSame([], $this->service->search(1, '', 'alice'));
	}

	public function testSearchReturnsNothingWhenEnumerationDisabledAndNoFullMatch(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([]);
		// Enumeration off, full-match off, group sharing off: nothing at all.
		$share = $this->createMock(IShareManager::class);
		$share->method('allowEnumeration')->willReturn(false);
		$share->method('allowEnumerationFullMatch')->willReturn(false);
		$share->method('limitEnumerationToGroups')->willReturn(false);
		$share->method('shareWithGroupMembersOnly')->willReturn(false);
		$share->method('shareWithGroupMembersOnlyExcludeGroupsList')->willReturn([]);
		$share->method('allowGroupSharing')->willReturn(false);
		$service = $this->serviceWithShareManager($share);
		$this->userManager->expects(self::never())->method('searchDisplayName');
		$this->groupManager->expects(self::never())->method('search');

		self::assertSame([], $service->search(1, 'bo', 'alice'));
	}

	public function testSearchAllowsExactFullMatchWhenEnumerationDisabled(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([]);
		$share = $this->createMock(IShareManager::class);
		$share->method('allowEnumeration')->willReturn(false);
		$share->method('allowEnumerationFullMatch')->willReturn(true);
		$share->method('limitEnumerationToGroups')->willReturn(false);
		$share->method('shareWithGroupMembersOnly')->willReturn(false);
		$share->method('shareWithGroupMembersOnlyExcludeGroupsList')->willReturn([]);
		$share->method('allowGroupSharing')->willReturn(true);
		$service = $this->serviceWithShareManager($share);
		// 'bob' is an exact uid match; 'bobby' is only a prefix hit and must
		// be dropped because wide enumeration is off.
		$this->userManager->method('searchDisplayName')->with('bob', 25)->willReturn([
			$this->user('bob', 'Bob Baker'),
			$this->user('bobby', 'Bobby Tables'),
		]);
		$this->groupManager->method('search')->with('bob', 25)->willReturn([]);

		self::assertSame([
			['id' => 'bob', 'displayName' => 'Bob Baker', 'type' => 'user'],
		], $service->search(1, 'bob', 'alice'));
	}

	public function testSearchAllowsExactDisplayNameMatchWhenEnumerationDisabled(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([]);
		$share = $this->createMock(IShareManager::class);
		$share->method('allowEnumeration')->willReturn(false);
		$share->method('allowEnumerationFullMatch')->willReturn(true);
		$share->method('limitEnumerationToGroups')->willReturn(false);
		$share->method('shareWithGroupMembersOnly')->willReturn(false);
		$share->method('shareWithGroupMembersOnlyExcludeGroupsList')->willReturn([]);
		$share->method('allowGroupSharing')->willReturn(true);
		$service = $this->serviceWithShareManager($share);
		// Query matches the display name exactly (case-insensitive), not the uid.
		$this->userManager->method('searchDisplayName')->with('bob baker', 25)->willReturn([
			$this->user('bob', 'Bob Baker'),
		]);
		$this->groupManager->method('search')->willReturn([]);

		self::assertSame([
			['id' => 'bob', 'displayName' => 'Bob Baker', 'type' => 'user'],
		], $service->search(1, 'bob baker', 'alice'));
	}

	public function testSearchRestrictsUsersToActorGroupsWhenShareWithGroupMembersOnly(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([]);
		$share = $this->createMock(IShareManager::class);
		$share->method('allowEnumeration')->willReturn(true);
		$share->method('allowEnumerationFullMatch')->willReturn(true);
		$share->method('limitEnumerationToGroups')->willReturn(false);
		$share->method('shareWithGroupMembersOnly')->willReturn(true);
		$share->method('shareWithGroupMembersOnlyExcludeGroupsList')->willReturn([]);
		$share->method('allowGroupSharing')->willReturn(true);
		$service = $this->serviceWithShareManager($share);

		$actor = $this->user('alice', 'Alice Adams');
		$bob = $this->user('bob', 'Bob Baker'); // shares 'devs'
		$carol = $this->user('carol', 'Carol Cook'); // no overlap
		$this->userManager->method('get')->with('alice')->willReturn($actor);
		$this->groupManager->method('getUserGroupIds')->willReturnMap([
			[$actor, ['devs', 'qa']],
			[$bob, ['devs']],
			[$carol, ['ops']],
		]);
		$this->userManager->method('searchDisplayName')->with('co', 25)->willReturn([$bob, $carol]);
		$this->groupManager->method('search')->with('co', 25)->willReturn([]);

		self::assertSame([
			['id' => 'bob', 'displayName' => 'Bob Baker', 'type' => 'user'],
		], $service->search(1, 'co', 'alice'));
	}

	public function testSearchLimitEnumerationToGroupsExemptsExactMatch(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([]);
		$share = $this->createMock(IShareManager::class);
		$share->method('allowEnumeration')->willReturn(true);
		$share->method('allowEnumerationFullMatch')->willReturn(true);
		// Enumeration is narrowed to the actor's groups, but an EXACT full
		// match is exempt from that narrowing (mirrors core UserPlugin).
		$share->method('limitEnumerationToGroups')->willReturn(true);
		$share->method('shareWithGroupMembersOnly')->willReturn(false);
		$share->method('shareWithGroupMembersOnlyExcludeGroupsList')->willReturn([]);
		$share->method('allowGroupSharing')->willReturn(true);
		$service = $this->serviceWithShareManager($share);

		$actor = $this->user('alice', 'Alice Adams');
		// 'carol' is an exact uid match but shares no group with alice - still
		// returned. 'carla' is only a wide hit outside alice's groups - dropped.
		$carol = $this->user('carol', 'Carol Cook');
		$carla = $this->user('carla', 'Carla King');
		$this->userManager->method('get')->with('alice')->willReturn($actor);
		$this->groupManager->method('getUserGroupIds')->willReturnMap([
			[$actor, ['devs']],
			[$carol, ['ops']],
			[$carla, ['ops']],
		]);
		$this->userManager->method('searchDisplayName')->with('carol', 25)->willReturn([$carol, $carla]);
		$this->groupManager->method('search')->with('carol', 25)->willReturn([]);

		self::assertSame([
			['id' => 'carol', 'displayName' => 'Carol Cook', 'type' => 'user'],
		], $service->search(1, 'carol', 'alice'));
	}

	public function testSearchExcludesActorGroupFromRestrictionExcludeList(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([]);
		$share = $this->createMock(IShareManager::class);
		$share->method('allowEnumeration')->willReturn(true);
		$share->method('allowEnumerationFullMatch')->willReturn(true);
		$share->method('limitEnumerationToGroups')->willReturn(false);
		$share->method('shareWithGroupMembersOnly')->willReturn(true);
		// 'devs' is excluded from the group-members-only restriction, so it no
		// longer counts as a shared group - bob (only in devs) drops out.
		$share->method('shareWithGroupMembersOnlyExcludeGroupsList')->willReturn(['devs']);
		$share->method('allowGroupSharing')->willReturn(true);
		$service = $this->serviceWithShareManager($share);

		$actor = $this->user('alice', 'Alice Adams');
		$bob = $this->user('bob', 'Bob Baker');
		$dave = $this->user('dave', 'Dave Doe');
		$this->userManager->method('get')->with('alice')->willReturn($actor);
		$this->groupManager->method('getUserGroupIds')->willReturnMap([
			[$actor, ['devs', 'qa']],
			[$bob, ['devs']],
			[$dave, ['qa']],
		]);
		$this->userManager->method('searchDisplayName')->with('da', 25)->willReturn([$bob, $dave]);
		$this->groupManager->method('search')->with('da', 25)->willReturn([]);

		self::assertSame([
			['id' => 'dave', 'displayName' => 'Dave Doe', 'type' => 'user'],
		], $service->search(1, 'da', 'alice'));
	}

	public function testSearchReturnsNoGroupsWhenGroupSharingDisabled(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([]);
		$share = $this->createMock(IShareManager::class);
		$share->method('allowEnumeration')->willReturn(true);
		$share->method('allowEnumerationFullMatch')->willReturn(true);
		$share->method('limitEnumerationToGroups')->willReturn(false);
		$share->method('shareWithGroupMembersOnly')->willReturn(false);
		$share->method('shareWithGroupMembersOnlyExcludeGroupsList')->willReturn([]);
		$share->method('allowGroupSharing')->willReturn(false);
		$service = $this->serviceWithShareManager($share);
		$this->userManager->method('searchDisplayName')->with('bo', 25)->willReturn([
			$this->user('bob', 'Bob Baker'),
		]);
		// Group sharing disabled: groupManager->search must not even be called.
		$this->groupManager->expects(self::never())->method('search');

		self::assertSame([
			['id' => 'bob', 'displayName' => 'Bob Baker', 'type' => 'user'],
		], $service->search(1, 'bo', 'alice'));
	}

	public function testSearchRestrictsGroupsToActorGroupsWhenGroupMembersOnly(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([]);
		$share = $this->createMock(IShareManager::class);
		$share->method('allowEnumeration')->willReturn(true);
		$share->method('allowEnumerationFullMatch')->willReturn(true);
		$share->method('limitEnumerationToGroups')->willReturn(false);
		$share->method('shareWithGroupMembersOnly')->willReturn(true);
		$share->method('shareWithGroupMembersOnlyExcludeGroupsList')->willReturn([]);
		$share->method('allowGroupSharing')->willReturn(true);
		$service = $this->serviceWithShareManager($share);

		$actor = $this->user('alice', 'Alice Adams');
		$this->userManager->method('get')->with('alice')->willReturn($actor);
		$this->groupManager->method('getUserGroupIds')->with($actor)->willReturn(['devs']);
		$this->userManager->method('searchDisplayName')->with('de', 25)->willReturn([]);
		// 'devs' is the actor's group and passes; 'design' is not and is dropped.
		$this->groupManager->method('search')->with('de', 25)->willReturn([
			$this->group('devs', 'Developers'),
			$this->group('design', 'Design Team'),
		]);

		self::assertSame([
			['id' => 'devs', 'displayName' => 'Developers', 'type' => 'group'],
		], $service->search(1, 'de', 'alice'));
	}

	private function serviceWithShareManager(IShareManager $share): AclService {
		return new AclService(
			$this->aclMapper,
			$this->boardMapper,
			$this->cardAssigneeMapper,
			$this->changeNotifier,
			$this->permissionService,
			$this->userManager,
			$this->groupManager,
			$share
		);
	}
}
