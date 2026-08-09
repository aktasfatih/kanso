<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\AclMapper;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Service\AssigneeService;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotificationService;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\SubscriptionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AssigneeServiceTest extends TestCase {
	private CardAssigneeMapper&MockObject $cardAssigneeMapper;
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private ChangeNotifier&MockObject $changeNotifier;
	private PermissionService&MockObject $permissionService;
	private NotificationService&MockObject $notificationService;
	private SubscriptionService&MockObject $subscriptionService;
	private CardVisibilityGuard&MockObject $visibilityGuard;
	private AssigneeService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->cardAssigneeMapper = $this->createMock(CardAssigneeMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->subscriptionService = $this->createMock(SubscriptionService::class);
		$this->visibilityGuard = $this->createMock(CardVisibilityGuard::class);
		$this->visibilityGuard->method('isVisible')->willReturn(true);
		$this->service = new AssigneeService(
			$this->cardAssigneeMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->changeNotifier,
			$this->permissionService,
			$this->notificationService,
			$this->subscriptionService,
			$this->visibilityGuard,
		);
	}

	private function board(int $id = 1): Board {
		$board = new Board();
		$board->setId($id);
		$board->setOwner('alice');
		$board->setDeletedAt(0);
		return $board;
	}

	private function card(int $id = 9, int $boardId = 1): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId($boardId);
		$card->setStackId(5);
		$card->setTitle('Existing card');
		$card->setSortKey('I');
		$card->setDeletedAt(0);
		return $card;
	}

	// ---- assign -----------------------------------------------------------

	public function testAssignInsertsAssignmentAndWritesCardChangeRow(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_EDIT);
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')
			->willReturn(PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT);
		$this->cardAssigneeMapper->method('exists')->with(9, 'bob')->willReturn(false);
		$this->cardAssigneeMapper->expects(self::once())
			->method('insertAssignment')
			->with(9, 'bob');
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_CARD,
				9,
				Change::ACTION_UPDATE,
				'alice'
			)
			->willReturn(new Change());
		$this->notificationService->expects(self::once())
			->method('notifyCardAssigned')
			->with(9, 'bob', 'alice');
		$this->subscriptionService->expects(self::once())
			->method('autoSubscribe')
			->with(9, 0, 'bob');

		$this->service->assign(9, 'bob', 'alice');
	}

	public function testAssignIsIdempotentAndWritesNoChangeRowOnReassign(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')
			->willReturn(PermissionService::PERMISSION_READ);
		$this->cardAssigneeMapper->method('exists')->with(9, 'bob')->willReturn(true);
		$this->cardAssigneeMapper->expects(self::never())->method('insertAssignment');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->service->assign(9, 'bob', 'alice');
	}

	public function testAssignTreatsLostInsertRaceAsIdempotentSuccess(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')
			->willReturn(PermissionService::PERMISSION_READ);
		$this->cardAssigneeMapper->method('exists')->with(9, 'bob')->willReturn(false);
		$uniqueViolation = $this->createMock(\OCP\DB\Exception::class);
		$uniqueViolation->method('getReason')
			->willReturn(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		$this->cardAssigneeMapper->method('insertAssignment')->willThrowException($uniqueViolation);
		$this->changeNotifier->expects(self::never())->method('notify');

		// A concurrent PUT winning the check-then-insert race must not 500.
		$this->service->assign(9, 'bob', 'alice');
	}

	public function testAssignAssertsActorEditPermission(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->cardAssigneeMapper->expects(self::never())->method('insertAssignment');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(NotPermittedException::class);
		$this->service->assign(9, 'bob', 'mallory');
	}

	public function testAssignRejectsParticipantWithoutBoardAccess(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		// Nonexistent users and non-members alike hold no permissions.
		$this->permissionService->method('getPermissions')
			->with($board, 'stranger')
			->willReturn(0);
		$this->cardAssigneeMapper->expects(self::never())->method('exists');
		$this->cardAssigneeMapper->expects(self::never())->method('insertAssignment');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('User has no access to this board');
		$this->service->assign(9, 'stranger', 'alice');
	}

	public function testAssignAcceptsParticipantWithReadViaGroupAcl(): void {
		// A real PermissionService: carol is neither the owner nor a user ACL
		// target, but her group 'devs' holds READ - that must satisfy the
		// assignee membership check.
		$board = $this->board();

		$groupAcl = new Acl();
		$groupAcl->setBoardId(1);
		$groupAcl->setParticipantType(Acl::TYPE_GROUP);
		$groupAcl->setParticipant('devs');
		$groupAcl->setPermission(PermissionService::PERMISSION_READ);

		$aclMapper = $this->createMock(AclMapper::class);
		$aclMapper->method('findByBoard')->with(1)->willReturn([$groupAcl]);

		$carol = $this->createMock(IUser::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('carol')->willReturn($carol);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->with($carol)->willReturn(['devs']);

		$service = new AssigneeService(
			$this->cardAssigneeMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->changeNotifier,
			new PermissionService($aclMapper, $groupManager, $userManager),
			$this->notificationService,
			$this->subscriptionService,
			$this->visibilityGuard,
		);

		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->cardAssigneeMapper->method('exists')->with(9, 'carol')->willReturn(false);
		$this->cardAssigneeMapper->expects(self::once())
			->method('insertAssignment')
			->with(9, 'carol');
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->willReturn(new Change());

		$service->assign(9, 'carol', 'alice');
	}

	public function testAssignRejectsDeletedCard(): void {
		$card = $this->card();
		$card->setDeletedAt(1234);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->cardAssigneeMapper->expects(self::never())->method('insertAssignment');

		$this->expectException(DoesNotExistException::class);
		$this->service->assign(9, 'bob', 'alice');
	}

	// ---- unassign ---------------------------------------------------------

	public function testUnassignDeletesAssignmentAndWritesCardChangeRow(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_EDIT);
		$this->cardAssigneeMapper->expects(self::once())
			->method('deleteAssignment')
			->with(9, 'bob')
			->willReturn(1);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_CARD,
				9,
				Change::ACTION_UPDATE,
				'alice'
			)
			->willReturn(new Change());
		$this->notificationService->expects(self::once())
			->method('dismissCardAssigned')
			->with(9, 'bob');

		$this->service->unassign(9, 'bob', 'alice');
	}

	public function testUnassignIsIdempotentAndWritesNoChangeRowWhenAbsent(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardAssigneeMapper->method('deleteAssignment')->with(9, 'bob')->willReturn(0);
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->service->unassign(9, 'bob', 'alice');
	}

	public function testUnassignAssertsActorEditPermission(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->cardAssigneeMapper->expects(self::never())->method('deleteAssignment');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(NotPermittedException::class);
		$this->service->unassign(9, 'bob', 'mallory');
	}
}
