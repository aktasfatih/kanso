<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\ProjectAccess;
use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\Project;
use OCA\Kanso\Db\ProjectMapper;
use OCA\Kanso\Db\ProjectPinMapper;
use OCA\Kanso\Db\ProjectShare;
use OCA\Kanso\Db\ProjectShareMapper;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\ProjectShareService;
use OCA\Kanso\Service\ShareeSearchService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProjectShareServiceTest extends TestCase {
	private const EDIT = PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT;

	private ProjectShareMapper&MockObject $shareMapper;
	private ProjectPinMapper&MockObject $pinMapper;
	private ProjectMapper&MockObject $projectMapper;
	private ShareeSearchService&MockObject $shareeSearch;
	private IUserManager&MockObject $userManager;
	private IGroupManager&MockObject $groupManager;
	private PermissionService&MockObject $permissionService;
	private ProjectShareService $service;
	private ?\Throwable $insertFailure = null;

	protected function setUp(): void {
		parent::setUp();
		$this->shareMapper = $this->createMock(ProjectShareMapper::class);
		$this->projectMapper = $this->createMock(ProjectMapper::class);
		$this->shareeSearch = $this->createMock(ShareeSearchService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->shareeSearch->method('resolveParticipant')->willReturnCallback(
			function (int $type, string $participant): string {
				if ($participant === 'forbidden') {
					throw new InvalidInputException('Cannot share with this participant');
				}
				return strtolower($participant);
			}
		);
		$this->pinMapper = $this->createMock(ProjectPinMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->service = new ProjectShareService(
			$this->shareMapper,
			$this->pinMapper,
			$this->projectMapper,
			new ProjectAccess($this->createMock(IDBConnection::class), $this->shareMapper, $this->permissionService),
			$this->shareeSearch,
			$this->userManager,
			$this->groupManager,
		);

		$project = new Project();
		$project->setId(5);
		$project->setOwner('alice');
		$this->projectMapper->method('find')->with(5)->willReturn($project);
		$this->shareMapper->method('insert')->willReturnCallback(function (ProjectShare $share): ProjectShare {
			if ($this->insertFailure !== null) {
				throw $this->insertFailure;
			}
			return $share;
		});
		$this->shareMapper->method('update')->willReturnArgument(0);
	}

	private function share(int $id, string $participant, int $type = Acl::TYPE_USER, int $projectId = 5): ProjectShare {
		$share = new ProjectShare();
		$share->setId($id);
		$share->setProjectId($projectId);
		$share->setParticipantType($type);
		$share->setParticipant($participant);
		$share->setPermission(PermissionService::PERMISSION_READ);
		return $share;
	}

	public function testOwnerSharesWithAUser(): void {
		$this->userManager->method('getDisplayName')->with('bob')->willReturn('Bob B');

		$share = $this->service->create(5, 'bob', 'user', self::EDIT, 'alice');

		self::assertSame('bob', $share['participant']);
		self::assertSame('user', $share['participantType']);
		self::assertSame(self::EDIT, $share['permission']);
		self::assertSame('Bob B', $share['displayName']);
	}

	public function testOwnerSharesWithAGroup(): void {
		$this->groupManager->method('getDisplayName')->with('devs')->willReturn('Developers');

		$share = $this->service->create(5, 'devs', 'group', 0, 'alice');

		self::assertSame('group', $share['participantType']);
		self::assertSame(PermissionService::PERMISSION_READ, $share['permission']);
		self::assertSame('Developers', $share['displayName']);
	}

	public function testMembersCannotManageShares(): void {
		$this->shareMapper->method('findByProject')->willReturn([$this->share(1, 'bob')]);
		$this->shareMapper->expects(self::never())->method('insert');
		$this->expectException(NotPermittedException::class);
		$this->service->create(5, 'carol', 'user', 1, 'bob');
	}

	public function testMembersCannotChangeShares(): void {
		$this->shareMapper->method('findByProject')->willReturn([$this->share(1, 'bob')]);
		$this->shareMapper->expects(self::never())->method('update');
		$this->expectException(NotPermittedException::class);
		$this->service->update(5, 1, self::EDIT, 'bob');
	}

	public function testMembersCannotListShares(): void {
		$this->shareMapper->method('findByProject')->willReturn([$this->share(1, 'bob')]);
		$this->expectException(NotPermittedException::class);
		$this->service->listForProject(5, 'bob');
	}

	public function testMembersCannotSearchSharees(): void {
		$this->shareMapper->method('findByProject')->willReturn([$this->share(1, 'bob')]);
		$this->shareeSearch->expects(self::never())->method('search');
		$this->expectException(NotPermittedException::class);
		$this->service->search(5, 'ca', 'bob');
	}

	public function testRejectsSharingWithTheOwner(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->create(5, 'alice', 'user', 1, 'alice');
	}

	public function testRejectsAParticipantThePolicyForbids(): void {
		$this->shareMapper->expects(self::never())->method('insert');
		$this->expectException(InvalidInputException::class);
		$this->service->create(5, 'forbidden', 'user', 1, 'alice');
	}

	public function testRejectsAGroupThePolicyForbids(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->create(5, 'forbidden', 'group', 1, 'alice');
	}

	public function testRejectsAnInvalidParticipantType(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->create(5, 'bob', 'circle', 1, 'alice');
	}

	public function testRejectsADuplicate(): void {
		$this->shareMapper->method('findByParticipant')->willReturn($this->share(1, 'bob'));
		$this->expectException(InvalidInputException::class);
		$this->service->create(5, 'bob', 'user', 1, 'alice');
	}

	public function testRejectsBoardOnlyPermissionBits(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->create(5, 'bob', 'user', PermissionService::PERMISSION_SHARE, 'alice');
	}

	public function testUpdateChangesThePermission(): void {
		$this->shareMapper->method('find')->with(1)->willReturn($this->share(1, 'bob'));

		self::assertSame(self::EDIT, $this->service->update(5, 1, self::EDIT, 'alice')['permission']);
	}

	public function testUpdateRejectsBoardOnlyPermissionBits(): void {
		$this->shareMapper->method('find')->with(1)->willReturn($this->share(1, 'bob'));
		$this->shareMapper->expects(self::never())->method('update');
		$this->expectException(InvalidInputException::class);
		$this->service->update(5, 1, PermissionService::PERMISSION_SHARE, 'alice');
	}

	public function testUpdateAlwaysKeepsRead(): void {
		$this->shareMapper->method('find')->with(1)->willReturn($this->share(1, 'bob'));

		self::assertSame(self::EDIT, $this->service->update(5, 1, PermissionService::PERMISSION_EDIT, 'alice')['permission']);
	}

	public function testRaisingAShareRechecksTheSharingPolicy(): void {
		$this->shareMapper->method('find')->with(1)->willReturn($this->share(1, 'forbidden'));
		$this->shareMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->update(5, 1, self::EDIT, 'alice');
	}

	public function testRaisingAGroupShareRechecksItAsAGroup(): void {
		$this->shareMapper->method('find')->with(1)->willReturn($this->share(1, 'devs', Acl::TYPE_GROUP));
		$this->shareeSearch->expects(self::once())->method('resolveParticipant')->with(Acl::TYPE_GROUP, 'devs', 'alice');

		$this->service->update(5, 1, self::EDIT, 'alice');
	}

	public function testLoweringAShareSkipsTheSharingPolicy(): void {
		$share = $this->share(1, 'forbidden');
		$share->setPermission(self::EDIT);
		$this->shareMapper->method('find')->with(1)->willReturn($share);
		$this->shareeSearch->expects(self::never())->method('resolveParticipant');

		self::assertSame(PermissionService::PERMISSION_READ, $this->service->update(5, 1, PermissionService::PERMISSION_READ, 'alice')['permission']);
	}

	public function testResendingAShareSkipsTheSharingPolicy(): void {
		$share = $this->share(1, 'forbidden');
		$share->setPermission(self::EDIT);
		$this->shareMapper->method('find')->with(1)->willReturn($share);
		$this->shareeSearch->expects(self::never())->method('resolveParticipant');

		self::assertSame(self::EDIT, $this->service->update(5, 1, self::EDIT, 'alice')['permission']);
	}

	public function testUpdateRejectsAShareOfAnotherProject(): void {
		$this->shareMapper->method('find')->with(1)->willReturn($this->share(1, 'bob', Acl::TYPE_USER, 6));
		$this->shareMapper->expects(self::never())->method('update');
		$this->expectException(DoesNotExistException::class);
		$this->service->update(5, 1, self::EDIT, 'alice');
	}

	public function testDeleteRemovesTheShare(): void {
		$share = $this->share(1, 'bob');
		$this->shareMapper->method('find')->with(1)->willReturn($share);
		$this->shareMapper->expects(self::once())->method('delete')->with($share);

		$this->service->delete(5, 1, 'alice');
	}

	public function testDeleteRejectsAShareOfAnotherProject(): void {
		$this->shareMapper->method('find')->with(1)->willReturn($this->share(1, 'bob', Acl::TYPE_USER, 6));
		$this->shareMapper->expects(self::never())->method('delete');
		$this->expectException(DoesNotExistException::class);
		$this->service->delete(5, 1, 'alice');
	}

	public function testMembersCannotRevokeShares(): void {
		$this->shareMapper->method('findByProject')->willReturn([$this->share(1, 'bob')]);
		$this->shareMapper->expects(self::never())->method('delete');
		$this->expectException(NotPermittedException::class);
		$this->service->delete(5, 1, 'bob');
	}

	public function testMemberLeavesTheirOwnShare(): void {
		$share = $this->share(1, 'bob');
		$this->shareMapper->method('findByParticipant')->with(5, Acl::TYPE_USER, 'bob')->willReturn($share);
		$this->shareMapper->method('findByProject')->willReturnOnConsecutiveCalls([$share], []);
		$this->shareMapper->expects(self::once())->method('delete')->with($share);
		$this->pinMapper->expects(self::once())->method('unpin')->with('bob', 5);

		$this->service->leave(5, 'bob');
	}

	public function testLeavingKeepsThePinWhileAGroupStillGrantsAccess(): void {
		$share = $this->share(1, 'bob');
		$group = $this->share(2, 'devs', Acl::TYPE_GROUP);
		$this->permissionService->method('getUserGroupIds')->with('bob')->willReturn(['devs']);
		$this->shareMapper->method('findByParticipant')->with(5, Acl::TYPE_USER, 'bob')->willReturn($share);
		$this->shareMapper->method('findByProject')->willReturnOnConsecutiveCalls([$share, $group], [$group]);
		$this->shareMapper->expects(self::once())->method('delete')->with($share);
		$this->pinMapper->expects(self::never())->method('unpin');

		$this->service->leave(5, 'bob');
	}

	public function testRevokingKeepsThePinWhileAGroupStillGrantsAccess(): void {
		$this->permissionService->method('getUserGroupIds')->with('bob')->willReturn(['devs']);
		$this->shareMapper->method('find')->willReturn($this->share(1, 'bob'));
		$this->shareMapper->method('findByProject')->willReturn([$this->share(2, 'devs', Acl::TYPE_GROUP)]);
		$this->shareMapper->expects(self::once())->method('delete');
		$this->pinMapper->expects(self::never())->method('unpin');

		$this->service->delete(5, 1, 'alice');
	}

	public function testAStrangerLeavingSeesNoProject(): void {
		$this->shareMapper->method('findByProject')->willReturn([]);
		$this->shareMapper->expects(self::never())->method('delete');
		$this->expectException(DoesNotExistException::class);
		$this->service->leave(5, 'mallory');
	}

	public function testRevokingAUserShareDropsTheirPin(): void {
		$share = $this->share(1, 'bob');
		$this->shareMapper->method('find')->willReturn($share);
		$this->pinMapper->expects(self::once())->method('unpin')->with('bob', 5);

		$this->service->delete(5, 1, 'alice');
	}

	public function testRevokingAGroupShareLeavesPinsAlone(): void {
		$this->shareMapper->method('find')->willReturn($this->share(1, 'devs', Acl::TYPE_GROUP));
		$this->pinMapper->expects(self::never())->method('unpin');

		$this->service->delete(5, 1, 'alice');
	}

	public function testStoresTheCanonicalUid(): void {
		$share = $this->service->create(5, 'BOB', 'user', self::EDIT, 'alice');

		self::assertSame('bob', $share['participant']);
	}

	public function testOwnerCannotBeSharedThroughACaseVariant(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->create(5, 'ALICE', 'user', self::EDIT, 'alice');
	}

	public function testALostInsertRaceReportsTheDuplicate(): void {
		$violation = $this->createMock(\OCP\DB\Exception::class);
		$violation->method('getReason')->willReturn(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		$this->insertFailure = $violation;

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('Already shared');
		$this->service->create(5, 'bob', 'user', self::EDIT, 'alice');
	}

	public function testLeaveWithoutADirectShareIsRefused(): void {
		$this->shareMapper->method('findByParticipant')->willReturn(null);
		$this->shareMapper->method('findByProject')->willReturn([$this->share(1, 'bob')]);
		$this->shareMapper->expects(self::never())->method('delete');
		$this->expectException(NotPermittedException::class);
		$this->service->leave(5, 'bob');
	}

	public function testSearchExcludesTheOwnerAndExistingShares(): void {
		$this->shareMapper->method('findByProject')->with(5)->willReturn([
			$this->share(1, 'bob'),
			$this->share(2, 'devs', Acl::TYPE_GROUP),
		]);
		$this->shareeSearch->method('isSearchable')->willReturn(true);
		$this->shareeSearch->expects(self::once())->method('search')
			->with('ca', 'alice', ['alice' => true, 'bob' => true], ['devs' => true])
			->willReturn([]);

		self::assertSame([], $this->service->search(5, 'ca', 'alice'));
	}

	public function testSearchBelowTheFloorSkipsTheShareLookup(): void {
		$this->shareeSearch->method('isSearchable')->willReturn(false);
		$this->shareMapper->expects(self::never())->method('findByProject');
		$this->shareeSearch->expects(self::never())->method('search');

		self::assertSame([], $this->service->search(5, 'c', 'alice'));
	}
}
