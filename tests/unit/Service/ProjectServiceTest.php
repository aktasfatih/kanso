<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\ProjectAccess;
use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Project;
use OCA\Kanso\Db\ProjectCardMapper;
use OCA\Kanso\Db\ProjectCommentMapper;
use OCA\Kanso\Db\ProjectMapper;
use OCA\Kanso\Db\ProjectPinMapper;
use OCA\Kanso\Db\ProjectShare;
use OCA\Kanso\Db\ProjectShareMapper;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\ProjectForecast;
use OCA\Kanso\Service\ProjectService;
use OCA\Kanso\Service\ShareeSearchService;
use OCA\Kanso\Service\StatsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProjectServiceTest extends TestCase {
	private const EDIT = PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT;

	private ProjectMapper&MockObject $projectMapper;
	private ProjectCardMapper&MockObject $projectCardMapper;
	private BoardService&MockObject $boardService;
	private PermissionService&MockObject $permissionService;
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private StatsService&MockObject $statsService;
	private BoardAccess&MockObject $boardAccess;
	private CardVisibilityGuard&MockObject $visibilityGuard;
	private CardAssigneeMapper&MockObject $cardAssigneeMapper;
	private ProjectPinMapper&MockObject $projectPinMapper;
	private ProjectShareMapper&MockObject $shareMapper;
	private ProjectCommentMapper&MockObject $commentMapper;
	private IDBConnection&MockObject $db;
	private IGroupManager&MockObject $groupManager;
	private IUserManager&MockObject $userManager;
	private ShareeSearchService&MockObject $shareeSearch;
	private ProjectService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->projectMapper = $this->createMock(ProjectMapper::class);
		$this->projectCardMapper = $this->createMock(ProjectCardMapper::class);
		$this->boardService = $this->createMock(BoardService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->statsService = $this->createMock(StatsService::class);
		$this->boardAccess = $this->createMock(BoardAccess::class);
		// Default: every card is visible to the actor (assertVisible passes).
		$this->visibilityGuard = $this->createMock(CardVisibilityGuard::class);
		$this->cardAssigneeMapper = $this->createMock(CardAssigneeMapper::class);
		$this->projectPinMapper = $this->createMock(ProjectPinMapper::class);
		$this->shareMapper = $this->createMock(ProjectShareMapper::class);
		$this->commentMapper = $this->createMock(ProjectCommentMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->shareeSearch = $this->createMock(ShareeSearchService::class);
		$this->service = new ProjectService(
			$this->projectMapper,
			$this->projectCardMapper,
			$this->boardService,
			$this->permissionService,
			$this->cardMapper,
			$this->boardMapper,
			$this->statsService,
			$this->boardAccess,
			$this->visibilityGuard,
			$this->cardAssigneeMapper,
			new ProjectForecast(),
			$this->projectPinMapper,
			new ProjectAccess($this->createMock(IDBConnection::class), $this->shareMapper, $this->permissionService),
			$this->shareMapper,
			$this->commentMapper,
			$this->userManager,
			$this->db,
			$this->groupManager,
			$this->shareeSearch,
		);
	}

	private function share(string $participant, int $permission, int $type = Acl::TYPE_USER): ProjectShare {
		$share = new ProjectShare();
		$share->setProjectId(5);
		$share->setParticipantType($type);
		$share->setParticipant($participant);
		$share->setPermission($permission);
		return $share;
	}

	private function sharedWithBob(int $permission): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->shareMapper->method('findByProject')->with(5)->willReturn([$this->share('bob', $permission)]);
	}

	private function project(string $owner = 'alice'): Project {
		$p = new Project();
		$p->setId(5);
		$p->setTitle('Q3 initiative');
		$p->setOwner($owner);
		$p->setCreatedAt(100);
		return $p;
	}

	private function card(int $boardId = 1, int $deletedAt = 0): Card {
		$c = new Card();
		$c->setId(9);
		$c->setBoardId($boardId);
		$c->setDeletedAt($deletedAt);
		return $c;
	}

	private function board(int $id = 1, int $deletedAt = 0): Board {
		$b = new Board();
		$b->setId($id);
		$b->setDeletedAt($deletedAt);
		return $b;
	}

	public function testFindMineMapsToSerializedProjects(): void {
		$this->projectMapper->method('findAccessible')->with('alice')->willReturn([$this->project()]);
		$result = $this->service->findMine('alice');
		self::assertCount(1, $result);
		self::assertSame(5, $result[0]['id']);
		self::assertSame('alice', $result[0]['owner']);
	}

	public function testFindMineMarksPinnedProjects(): void {
		$other = $this->project();
		$other->setId(6);
		$this->projectMapper->method('findAccessible')->with('alice')->willReturn([$this->project(), $other]);
		$this->projectPinMapper->method('pinnedMap')->with('alice')->willReturn([6 => true]);

		self::assertSame([false, true], array_column($this->service->findMine('alice'), 'pinned'));
	}

	public function testFindMineReportsAViewersAccess(): void {
		$this->projectMapper->method('findAccessible')->with('bob')->willReturn([$this->project('alice')]);
		$this->shareMapper->method('findByProjects')->with([5])->willReturn([$this->share('bob', PermissionService::PERMISSION_READ)]);
		$this->userManager->method('getDisplayName')->with('alice')->willReturn('Alice A');

		$project = $this->service->findMine('bob')[0];

		self::assertFalse($project['isOwner']);
		self::assertFalse($project['canEdit']);
		self::assertTrue($project['canLeave']);
		self::assertTrue($project['shared']);
		self::assertSame('Alice A', $project['ownerDisplayName']);
	}

	public function testFindMineGrantsEditThroughAGroupWithoutAllowingLeave(): void {
		$this->projectMapper->method('findAccessible')->with('bob')->willReturn([$this->project('alice')]);
		$this->shareMapper->method('findByProjects')->willReturn([
			$this->share('devs', PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT, Acl::TYPE_GROUP),
		]);
		$this->permissionService->method('getUserGroupIds')->with('bob')->willReturn(['devs']);

		$project = $this->service->findMine('bob')[0];

		self::assertTrue($project['canEdit']);
		self::assertFalse($project['canLeave']);
	}

	public function testAGroupNamedLikeTheViewerDoesNotOfferLeave(): void {
		$this->projectMapper->method('findAccessible')->with('bob')->willReturn([$this->project('alice')]);
		$this->shareMapper->method('findByProjects')->willReturn([$this->share('bob', self::EDIT, Acl::TYPE_GROUP)]);
		$this->permissionService->method('getUserGroupIds')->with('bob')->willReturn(['bob']);

		$project = $this->service->findMine('bob')[0];

		self::assertTrue($project['canEdit']);
		self::assertFalse($project['canLeave']);
	}

	public function testFindMineTellsTheOwnerTheyCanEdit(): void {
		$this->projectMapper->method('findAccessible')->with('alice')->willReturn([$this->project('alice')]);

		$project = $this->service->findMine('alice')[0];

		self::assertTrue($project['isOwner']);
		self::assertTrue($project['canEdit']);
		self::assertFalse($project['shared']);
	}

	public function testViewerCanPinAndRead(): void {
		$this->sharedWithBob(PermissionService::PERMISSION_READ);
		$this->projectPinMapper->expects(self::once())->method('pin')->with('bob', 5);
		$this->boardService->expects(self::once())->method('findAllActive')->with('bob')->willReturn([]);

		$this->service->pin(5, 'bob');
		self::assertSame([], $this->service->listCards(5, 'bob'));
	}

	public function testViewerCannotUpdate(): void {
		$this->sharedWithBob(PermissionService::PERMISSION_READ);
		$this->projectMapper->expects(self::never())->method('update');
		$this->expectException(NotPermittedException::class);
		$this->service->update(5, 'bob', 'Renamed', null, null);
	}

	public function testViewerCannotAddCards(): void {
		$this->sharedWithBob(PermissionService::PERMISSION_READ);
		$this->projectCardMapper->expects(self::never())->method('add');
		$this->expectException(NotPermittedException::class);
		$this->service->addCard(5, 9, 'bob');
	}

	public function testEditorCanUpdateButNotDelete(): void {
		$this->sharedWithBob(PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT);
		$this->projectMapper->expects(self::once())->method('update')->willReturnArgument(0);
		$this->projectMapper->expects(self::never())->method('delete');

		self::assertSame('Renamed', $this->service->update(5, 'bob', 'Renamed', null, null)->getTitle());
		$this->expectException(NotPermittedException::class);
		$this->service->delete(5, 'bob');
	}

	public function testEditorCannotAddACardFromAnUnreadableBoard(): void {
		$this->sharedWithBob(PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card(1));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board(1));
		$this->permissionService->method('getPermissions')->willReturn(0);
		$this->projectCardMapper->expects(self::never())->method('add');
		$this->expectException(NotPermittedException::class);
		$this->service->addCard(5, 9, 'bob');
	}

	public function testEditorCannotRemoveACardTheyCannotSee(): void {
		$this->sharedWithBob(PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card(1));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board(1));
		$this->permissionService->method('getPermissions')->willReturn(0);
		$this->projectCardMapper->expects(self::never())->method('remove');
		$this->expectException(NotPermittedException::class);
		$this->service->removeCard(5, 9, 'bob');
	}

	private function visibleBoardWithHiddenCard(): void {
		$this->sharedWithBob(self::EDIT);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card(1));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board(1));
		$this->permissionService->method('getPermissions')->willReturn(PermissionService::PERMISSION_READ);
		$this->visibilityGuard->method('assertVisible')->willThrowException(new NotPermittedException('hidden'));
	}

	public function testEditorCannotAddACardHiddenFromThem(): void {
		$this->visibleBoardWithHiddenCard();
		$this->projectCardMapper->expects(self::never())->method('add');
		$this->expectException(NotPermittedException::class);
		$this->service->addCard(5, 9, 'bob');
	}

	public function testEditorCannotRemoveACardHiddenFromThem(): void {
		$this->visibleBoardWithHiddenCard();
		$this->projectCardMapper->expects(self::never())->method('remove');
		$this->expectException(NotPermittedException::class);
		$this->service->removeCard(5, 9, 'bob');
	}

	public function testEditorCanRemoveAVisibleCard(): void {
		$this->sharedWithBob(PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card(1));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board(1));
		$this->permissionService->method('getPermissions')->willReturn(PermissionService::PERMISSION_READ);
		$this->projectCardMapper->expects(self::once())->method('remove')->with(5, 9);
		$this->service->removeCard(5, 9, 'bob');
	}

	public function testOwnerCanRemoveACardTheyLostAccessTo(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->cardMapper->expects(self::never())->method('find');
		$this->projectCardMapper->expects(self::once())->method('remove')->with(5, 9);
		$this->service->removeCard(5, 9, 'alice');
	}

	public function testPinRequiresAccess(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->projectPinMapper->expects(self::never())->method('pin');
		$this->expectException(DoesNotExistException::class);
		$this->service->pin(5, 'mallory');
	}

	public function testPinPinsForTheOwner(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->projectPinMapper->expects(self::once())->method('pin')->with('alice', 5);
		$this->service->pin(5, 'alice');
	}

	public function testCreateRejectsEmptyTitle(): void {
		$this->projectMapper->expects(self::never())->method('insert');
		$this->expectException(InvalidInputException::class);
		$this->service->create('alice', '   ', null, null);
	}

	public function testCreateRejectsInvalidColor(): void {
		$this->projectMapper->expects(self::never())->method('insert');
		$this->expectException(InvalidInputException::class);
		$this->service->create('alice', 'Valid title', null, 'not-a-hex');
	}

	public function testCreateInsertsOwnedProject(): void {
		$this->projectMapper->expects(self::once())
			->method('insert')
			->with(self::callback(fn (Project $p): bool => $p->getOwner() === 'alice' && $p->getTitle() === 'New'))
			->willReturnArgument(0);
		$this->service->create('alice', 'New', null, 'ff0000');
	}

	public function testCreateStoresStartAndTargetDates(): void {
		$this->projectMapper->expects(self::once())
			->method('insert')
			->with(self::callback(fn (Project $p): bool => $p->getStartDate() === '2026-10-01' && $p->getTargetDate() === '2026-11-15'))
			->willReturnArgument(0);
		$this->service->create('alice', 'Dated', null, null, '2026-10-01', '2026-11-15');
	}

	public function testCreateDefaultsToPlannedWithoutALeadOrSharing(): void {
		$this->projectMapper->method('insert')->willReturnArgument(0);
		$this->shareMapper->expects(self::never())->method('insert');
		$this->shareeSearch->expects(self::never())->method('resolveParticipant');

		$project = $this->service->create('alice', 'New', null, null)->jsonSerialize();

		self::assertNull($project['lead']);
		self::assertSame('planned', $project['lifecycleStatus']);
	}

	public function testCreateStoresACanonicalLeadWithoutGrantingAccess(): void {
		$this->shareeSearch->expects(self::once())->method('resolveParticipant')->with(Acl::TYPE_USER, 'Bob', 'alice')->willReturn('bob');
		$this->shareMapper->expects(self::never())->method('insert');
		$this->projectMapper->method('insert')->willReturnArgument(0);

		$project = $this->service->create('alice', 'New', null, null, lead: 'Bob', lifecycleStatus: 'in_progress')->jsonSerialize();

		self::assertSame('bob', $project['lead']);
		self::assertSame('in_progress', $project['lifecycleStatus']);
		self::assertSame('alice', $project['owner']);
	}

	public function testCreateCanSelectSelfWithoutSharingEnabled(): void {
		$this->shareeSearch->expects(self::never())->method('resolveParticipant');
		$this->projectMapper->method('insert')->willReturnArgument(0);

		self::assertSame('alice', $this->service->create('alice', 'New', null, null, lead: 'alice')->getLead());
	}

	public function testCreateRejectsAForbiddenOrUnknownLead(): void {
		$this->shareeSearch->method('resolveParticipant')->willThrowException(new InvalidInputException('Cannot share with this participant'));
		$this->projectMapper->expects(self::never())->method('insert');
		$this->expectException(InvalidInputException::class);

		$this->service->create('alice', 'New', null, null, lead: 'unknown');
	}

	public function testCreateRejectsForecastHealthAsLifecycle(): void {
		$this->projectMapper->expects(self::never())->method('insert');
		$this->expectException(InvalidInputException::class);

		$this->service->create('alice', 'New', null, null, lifecycleStatus: 'on_track');
	}

	public function testEditorCanChangeLeadAndLifecycle(): void {
		$this->sharedWithBob(self::EDIT);
		$this->shareeSearch->expects(self::once())->method('resolveParticipant')->with(Acl::TYPE_USER, 'carol', 'bob')->willReturn('carol');
		$this->projectMapper->method('update')->willReturnArgument(0);
		$this->shareMapper->expects(self::never())->method('insert');

		$project = $this->service->update(5, 'bob', null, null, null, lead: 'carol', lifecycleStatus: 'done');

		self::assertSame('carol', $project->getLead());
		self::assertSame('done', $project->getLifecycleStatus());
		self::assertSame('alice', $project->getOwner());
	}

	public function testUpdateKeepsOmittedMetadataWithoutResolvingTheLead(): void {
		$project = $this->project();
		$project->setLead('former-user');
		$project->setLifecycleStatus('in_progress');
		$this->projectMapper->method('find')->willReturn($project);
		$this->projectMapper->method('update')->willReturnArgument(0);
		$this->shareeSearch->expects(self::never())->method('resolveParticipant');

		$updated = $this->service->update(5, 'alice', 'Renamed', null, null);

		self::assertSame('former-user', $updated->getLead());
		self::assertSame('in_progress', $updated->getLifecycleStatus());
	}

	public function testUpdateClearsLeadWithAnEmptyString(): void {
		$project = $this->project();
		$project->setLead('bob');
		$this->projectMapper->method('find')->willReturn($project);
		$this->projectMapper->method('update')->willReturnArgument(0);
		$this->shareeSearch->expects(self::never())->method('resolveParticipant');

		self::assertNull($this->service->update(5, 'alice', null, null, null, lead: '')->getLead());
	}

	public function testUpdateRejectsAnEmptyLifecycle(): void {
		$this->projectMapper->method('find')->willReturn($this->project());
		$this->projectMapper->expects(self::never())->method('update');
		$this->expectException(InvalidInputException::class);

		$this->service->update(5, 'alice', null, null, null, lifecycleStatus: '');
	}

	public function testViewerCannotChangeMetadataOrProbeALead(): void {
		$this->sharedWithBob(PermissionService::PERMISSION_READ);
		$this->projectMapper->expects(self::never())->method('update');
		$this->shareeSearch->expects(self::never())->method('resolveParticipant');
		$this->expectException(NotPermittedException::class);

		$this->service->update(5, 'bob', null, null, null, lead: 'unknown', lifecycleStatus: 'invalid');
	}

	public function testStrangerCannotChangeMetadataOrProbeALead(): void {
		$this->projectMapper->method('find')->willReturn($this->project());
		$this->projectMapper->expects(self::never())->method('update');
		$this->shareeSearch->expects(self::never())->method('resolveParticipant');
		$this->expectException(DoesNotExistException::class);

		$this->service->update(5, 'mallory', null, null, null, lead: 'unknown', lifecycleStatus: 'invalid');
	}

	public function testLeadCandidatesUseSharingPolicyAndExcludeGroups(): void {
		$this->shareeSearch->expects(self::once())->method('search')->with('bob', 'alice', [], [])->willReturn([
			['id' => 'bob', 'displayName' => 'Bob', 'type' => 'user'],
			['id' => 'devs', 'displayName' => 'Developers', 'type' => 'group'],
		]);
		$this->userManager->method('getDisplayName')->with('alice')->willReturn('Alice');

		self::assertSame([
			['id' => 'alice', 'displayName' => 'Alice', 'type' => 'user'],
			['id' => 'bob', 'displayName' => 'Bob', 'type' => 'user'],
		], $this->service->leadCandidates('bob', 'alice'));
	}

	public function testLeadCandidatesKeepSelfWhenSharingIsDisabled(): void {
		$this->shareeSearch->method('search')->willReturn([]);

		self::assertSame([
			['id' => 'alice', 'displayName' => 'alice', 'type' => 'user'],
		], $this->service->leadCandidates('', 'alice'));
	}

	public function testFindMineIncludesLeadDisplayNameAndLifecycle(): void {
		$project = $this->project();
		$project->setLead('bob');
		$project->setLifecycleStatus('done');
		$this->projectMapper->method('findAccessible')->willReturn([$project]);
		$this->userManager->method('getDisplayName')->willReturnMap([['alice', 'Alice'], ['bob', 'Bob']]);

		$record = $this->service->findMine('alice')[0];

		self::assertSame('bob', $record['lead']);
		self::assertSame('Bob', $record['leadDisplayName']);
		self::assertSame('done', $record['lifecycleStatus']);
	}

	public function testCreateRejectsImpossibleDate(): void {
		$this->projectMapper->expects(self::never())->method('insert');
		$this->expectException(InvalidInputException::class);
		$this->service->create('alice', 'Dated', null, null, null, '2026-02-30');
	}

	public function testUpdateRejectsTargetBeforeStart(): void {
		$project = $this->project('alice');
		$project->setStartDate('2026-10-10');
		$this->projectMapper->method('find')->with(5)->willReturn($project);
		$this->projectMapper->expects(self::never())->method('update');
		$this->expectException(InvalidInputException::class);
		$this->service->update(5, 'alice', null, null, null, null, '2026-10-01');
	}

	public function testUpdateClearsTargetDateWithEmptyString(): void {
		$project = $this->project('alice');
		$project->setTargetDate('2026-11-15');
		$this->projectMapper->method('find')->with(5)->willReturn($project);
		$this->projectMapper->expects(self::once())
			->method('update')
			->with(self::callback(fn (Project $p): bool => $p->getTargetDate() === null))
			->willReturnArgument(0);
		$this->service->update(5, 'alice', null, null, null, null, '');
	}

	public function testUpdateRejectsNonOwner(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->projectMapper->expects(self::never())->method('update');
		$this->expectException(DoesNotExistException::class);
		$this->service->update(5, 'mallory', 'Hacked', null, null);
	}

	public function testDeleteCascadesMembershipsForOwner(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->projectCardMapper->expects(self::once())->method('deleteByProject')->with(5);
		$this->projectPinMapper->expects(self::once())->method('deleteByProject')->with(5);
		$this->shareMapper->expects(self::once())->method('deleteByProject')->with(5);
		$this->commentMapper->expects(self::once())->method('deleteByProject')->with(5);
		$this->projectMapper->expects(self::once())->method('delete');
		$this->db->expects(self::once())->method('commit');
		$this->service->delete(5, 'alice');
	}

	private function member(string $uid, bool $enabled = true): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($enabled);
		return $user;
	}

	private function assertHandedTo(string $successor, string $formerOwner = 'gone'): void {
		$project = $this->project($formerOwner);
		$this->projectMapper->expects(self::once())->method('update')->with(self::callback(
			static fn (Project $updated): bool => $updated->getOwner() === $successor
		));
		$this->projectMapper->expects(self::never())->method('delete');
		$this->db->expects(self::once())->method('commit');

		$this->service->handOver($project, $formerOwner);
	}

	/** @param list<IUser> $members */
	private function groupOf(array $members): IGroup&MockObject {
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($members);
		return $group;
	}

	private function assertPurged(): void {
		$project = $this->project('gone');
		$this->projectMapper->expects(self::never())->method('update');
		$this->projectMapper->expects(self::once())->method('delete')->with($project);

		$this->service->handOver($project, 'gone');
	}

	public function testHandOverPicksTheLongestStandingEditor(): void {
		$own = $this->share('bob', self::EDIT);
		$this->shareMapper->method('findByProject')->willReturn([
			$this->share('viewer', PermissionService::PERMISSION_READ),
			$own,
			$this->share('carol', self::EDIT),
		]);
		$this->userManager->method('get')->willReturnCallback(fn (string $uid): IUser => $this->member($uid));
		$this->shareMapper->method('findByParticipant')->with(5, Acl::TYPE_USER, 'bob')->willReturn($own);
		$this->shareMapper->expects(self::once())->method('delete')->with($own);

		$this->assertHandedTo('bob');
	}

	public function testHandOverSkipsDisabledEditors(): void {
		$this->shareMapper->method('findByProject')->willReturn([$this->share('bob', self::EDIT), $this->share('carol', self::EDIT)]);
		$this->userManager->method('get')->willReturnCallback(fn (string $uid): IUser => $this->member($uid, $uid !== 'bob'));

		$this->assertHandedTo('carol');
	}

	public function testHandOverPrefersADirectEditorOverAnEarlierGroup(): void {
		$this->shareMapper->method('findByProject')->willReturn([$this->share('devs', self::EDIT, Acl::TYPE_GROUP), $this->share('carol', self::EDIT)]);
		$this->userManager->method('get')->willReturnCallback(fn (string $uid): IUser => $this->member($uid));
		$this->groupManager->expects(self::never())->method('get');

		$this->assertHandedTo('carol');
	}

	public function testHandOverFallsBackToAGroupEditor(): void {
		$group = $this->groupOf([$this->member('zed'), $this->member('aaa'), $this->member('amy', false), $this->member('bea')]);
		$this->groupManager->method('get')->with('devs')->willReturn($group);
		$this->shareMapper->method('findByProject')->willReturn([$this->share('devs', self::EDIT, Acl::TYPE_GROUP)]);

		$this->assertHandedTo('bea', 'aaa');
	}

	public function testHandOverNeverPicksTheFormerOwnerFromADirectShare(): void {
		$this->shareMapper->method('findByProject')->willReturn([$this->share('gone', self::EDIT), $this->share('carol', self::EDIT)]);
		$this->userManager->method('get')->willReturnCallback(fn (string $uid): IUser => $this->member($uid));

		$this->assertHandedTo('carol');
	}

	public function testHandOverRollsBackAFailedUpdate(): void {
		$this->shareMapper->method('findByProject')->willReturn([$this->share('bob', self::EDIT)]);
		$this->userManager->method('get')->willReturn($this->member('bob'));
		$this->projectMapper->method('update')->willThrowException(new \RuntimeException('boom'));
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');

		$this->expectException(\RuntimeException::class);
		$this->service->handOver($this->project('gone'), 'gone');
	}

	public function testHandOverPurgesDespiteAViewOnlyGroupWithEnabledMembers(): void {
		$this->shareMapper->method('findByProject')->willReturn([$this->share('devs', PermissionService::PERMISSION_READ, Acl::TYPE_GROUP)]);
		$this->groupManager->method('get')->willReturn($this->groupOf([$this->member('bob')]));

		$this->assertPurged();
	}

	public function testHandOverSkipsAnEmptyOlderGroupForANewerOne(): void {
		$this->shareMapper->method('findByProject')->willReturn([
			$this->share('empty', self::EDIT, Acl::TYPE_GROUP),
			$this->share('devs', self::EDIT, Acl::TYPE_GROUP),
		]);
		$this->groupManager->method('get')->willReturnCallback(
			fn (string $gid): IGroup => $this->groupOf($gid === 'devs' ? [$this->member('zed')] : [])
		);

		$this->assertHandedTo('zed');
	}

	public function testHandOverPrefersTheOlderOfTwoEditGroups(): void {
		$this->shareMapper->method('findByProject')->willReturn([
			$this->share('older', self::EDIT, Acl::TYPE_GROUP),
			$this->share('newer', self::EDIT, Acl::TYPE_GROUP),
		]);
		$this->groupManager->method('get')->willReturnCallback(
			fn (string $gid): IGroup => $this->groupOf([$this->member($gid === 'older' ? 'zed' : 'amy')])
		);

		$this->assertHandedTo('zed');
	}

	public function testHandOverKeepsAProjectWhoseOnlyEditGroupLooksEmpty(): void {
		$project = $this->project('gone');
		$this->shareMapper->method('findByProject')->willReturn([$this->share('devs', self::EDIT, Acl::TYPE_GROUP)]);
		$this->groupManager->method('get')->willReturn($this->groupOf([]));
		$this->projectMapper->expects(self::never())->method('delete');

		$this->expectException(\RuntimeException::class);
		$this->service->handOver($project, 'gone');
	}

	public function testHandOverPurgesAnEmptyGroupOnceItIsTrusted(): void {
		$project = $this->project('gone');
		$this->shareMapper->method('findByProject')->willReturn([$this->share('devs', self::EDIT, Acl::TYPE_GROUP)]);
		$this->groupManager->method('get')->willReturn($this->groupOf([]));
		$this->projectMapper->expects(self::once())->method('delete')->with($project);

		$this->service->handOver($project, 'gone', true);
	}

	public function testHandOverStillKeepsAMissingGroupWhenEmptyGroupsAreTrusted(): void {
		$project = $this->project('gone');
		$this->shareMapper->method('findByProject')->willReturn([$this->share('devs', self::EDIT, Acl::TYPE_GROUP)]);
		$this->groupManager->method('get')->willReturn(null);
		$this->projectMapper->expects(self::never())->method('delete');

		$this->expectException(\RuntimeException::class);
		$this->service->handOver($project, 'gone', true);
	}

	public function testHandOverPurgesWhenAGroupHasNoEnabledMember(): void {
		$this->shareMapper->method('findByProject')->willReturn([$this->share('devs', self::EDIT, Acl::TYPE_GROUP)]);
		$this->groupManager->method('get')->willReturn($this->groupOf([$this->member('bob', false), $this->member('gone')]));

		$this->assertPurged();
	}

	public function testHandOverDeletesAProjectNobodyCanEdit(): void {
		$project = $this->project('gone');
		$this->shareMapper->method('findByProject')->willReturn([$this->share('viewer', PermissionService::PERMISSION_READ)]);
		$this->projectMapper->expects(self::never())->method('update');
		$this->commentMapper->expects(self::once())->method('deleteByProject')->with(5);
		$this->projectMapper->expects(self::once())->method('delete')->with($project);

		$this->service->handOver($project, 'gone');
	}

	private function assertNothingIsPurged(): void {
		$this->projectMapper->expects(self::never())->method('delete');
		$this->commentMapper->expects(self::never())->method('deleteByProject');
		$this->projectCardMapper->expects(self::never())->method('deleteByProject');
		$this->shareMapper->expects(self::never())->method('deleteByProject');
	}

	public function testHandOverKeepsAProjectWhoseEditorCannotBeResolved(): void {
		$this->shareMapper->method('findByProject')->willReturn([$this->share('bob', self::EDIT)]);
		$this->userManager->method('get')->with('bob')->willReturn(null);
		$this->assertNothingIsPurged();
		$this->projectMapper->expects(self::never())->method('update');

		$this->expectException(\RuntimeException::class);
		$this->service->handOver($this->project('gone'), 'gone');
	}

	public function testHandOverKeepsAProjectWhoseEditGroupCannotBeResolved(): void {
		$this->shareMapper->method('findByProject')->willReturn([$this->share('devs', self::EDIT, Acl::TYPE_GROUP)]);
		$this->groupManager->method('get')->with('devs')->willReturn(null);
		$this->assertNothingIsPurged();
		$this->projectMapper->expects(self::never())->method('update');

		$this->expectException(\RuntimeException::class);
		$this->service->handOver($this->project('gone'), 'gone');
	}

	public function testHandOverKeepsAProjectWhenADisabledEditorSitsBesideAMissingGroup(): void {
		$this->shareMapper->method('findByProject')->willReturn([
			$this->share('bob', self::EDIT),
			$this->share('devs', self::EDIT, Acl::TYPE_GROUP),
		]);
		$this->userManager->method('get')->willReturn($this->member('bob', false));
		$this->groupManager->method('get')->willReturn(null);
		$this->assertNothingIsPurged();

		$this->expectException(\RuntimeException::class);
		$this->service->handOver($this->project('gone'), 'gone');
	}

	public function testHandOverReachesAnEditorPastAnUnresolvableOne(): void {
		$this->shareMapper->method('findByProject')->willReturn([$this->share('bob', self::EDIT), $this->share('carol', self::EDIT)]);
		$this->userManager->method('get')->willReturnCallback(fn (string $uid): ?IUser => $uid === 'carol' ? $this->member($uid) : null);

		$this->assertHandedTo('carol');
	}

	public function testALeftoverRowOfTheFormerOwnerDoesNotBlockThePurge(): void {
		$project = $this->project('gone');
		$this->shareMapper->method('findByProject')->willReturn([$this->share('gone', self::EDIT)]);
		$this->userManager->method('get')->willReturn(null);
		$this->projectMapper->expects(self::once())->method('delete')->with($project);

		$this->service->handOver($project, 'gone');
	}

	public function testAnUnresolvableViewerDoesNotBlockThePurge(): void {
		$project = $this->project('gone');
		$this->shareMapper->method('findByProject')->willReturn([$this->share('bob', PermissionService::PERMISSION_READ)]);
		$this->userManager->method('get')->willReturn(null);
		$this->projectMapper->expects(self::once())->method('delete')->with($project);

		$this->service->handOver($project, 'gone');
	}

	public function testCreateRejectsAnOverLongDescription(): void {
		$this->projectMapper->expects(self::never())->method('insert');
		$this->expectException(InvalidInputException::class);
		$this->service->create('alice', 'Title', str_repeat('x', 65537), null);
	}

	public function testCreateAcceptsADescriptionAtTheCap(): void {
		$this->projectMapper->expects(self::once())->method('insert')->willReturnArgument(0);
		$this->service->create('alice', 'Title', str_repeat('é', 65536), null);
	}

	public function testUpdateRejectsAnOverLongDescription(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->projectMapper->expects(self::never())->method('update');
		$this->expectException(InvalidInputException::class);
		$this->service->update(5, 'alice', null, str_repeat('x', 65537), null);
	}

	public function testUpdateKeepsAnExistingOverLongDescriptionSaveable(): void {
		$long = str_repeat('x', 70000);
		$project = $this->project('alice');
		$project->setDescription($long);
		$this->projectMapper->method('find')->with(5)->willReturn($project);
		$this->projectMapper->expects(self::once())->method('update')->willReturnArgument(0);

		self::assertSame('Renamed', $this->service->update(5, 'alice', 'Renamed', $long, null)->getTitle());
	}

	public function testUpdateRefusesToGrowAnOverLongDescription(): void {
		$project = $this->project('alice');
		$project->setDescription(str_repeat('x', 70000));
		$this->projectMapper->method('find')->with(5)->willReturn($project);
		$this->projectMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->update(5, 'alice', null, str_repeat('x', 70001), null);
	}

	public function testAFailedDeleteRollsBack(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->shareMapper->method('deleteByProject')->willThrowException(new \RuntimeException('boom'));
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');

		$this->expectException(\RuntimeException::class);
		$this->service->delete(5, 'alice');
	}

	public function testSomeoneElsesDirectShareDoesNotOfferLeave(): void {
		$this->projectMapper->method('findAccessible')->willReturn([$this->project('alice')]);
		$this->shareMapper->method('findByProjects')->willReturn([
			$this->share('carol', PermissionService::PERMISSION_READ),
			$this->share('devs', PermissionService::PERMISSION_READ, Acl::TYPE_GROUP),
		]);
		$this->permissionService->method('getUserGroupIds')->willReturn(['devs']);

		self::assertFalse($this->service->findMine('bob')[0]['canLeave']);
	}

	public function testDeleteRejectsNonOwner(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->projectCardMapper->expects(self::never())->method('deleteByProject');
		$this->expectException(DoesNotExistException::class);
		$this->service->delete(5, 'mallory');
	}

	public function testAddCardRequiresReadOnCardsBoard(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->cardMapper->method('find')->with(9)->willReturn($this->card(1));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board(1));
		// Owner of the project, but no READ on the card's board.
		$this->permissionService->method('getPermissions')->willReturn(0);
		$this->projectCardMapper->expects(self::never())->method('add');
		$this->expectException(NotPermittedException::class);
		$this->service->addCard(5, 9, 'alice');
	}

	public function testAddCardAddsWhenOwnerCanReadBoard(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->cardMapper->method('find')->with(9)->willReturn($this->card(1));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board(1));
		$this->permissionService->method('getPermissions')->willReturn(PermissionService::PERMISSION_READ);
		$this->projectCardMapper->expects(self::once())->method('add')->with(5, 9);
		$this->service->addCard(5, 9, 'alice');
	}

	public function testAddCardRejectsNonOwnerBeforeTouchingCard(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->cardMapper->expects(self::never())->method('find');
		$this->projectCardMapper->expects(self::never())->method('add');
		$this->expectException(DoesNotExistException::class);
		$this->service->addCard(5, 9, 'mallory');
	}

	public function testListCardsFiltersToReadableBoardSet(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->boardService->method('findAllActive')->with('alice')->willReturn([$this->board(1), $this->board(7)]);
		$this->projectCardMapper->expects(self::once())
			->method('findCardsInProjectAndBoards')
			->with(5, [1, 7])
			->willReturn([['id' => 9, 'boardId' => 1]]);
		$result = $this->service->listCards(5, 'alice');
		self::assertSame(9, $result[0]['id']);
	}

	public function testListCardsAndStatsSkipBoardsTheOwnerHasArchived(): void {
		// #10126: an archived board's cards drop out of the project - both from
		// its card list and from the metrics computed over the same set. The mock
		// mirrors the real BoardService: findAll() STILL carries the archived
		// board (the boards page's Archived tab is built on it), only
		// findAllActive() drops it - so reverting the service to findAll() puts
		// board 7 back in the frame and this goes red.
		$active = $this->board(1);
		$archived = $this->board(7);
		$archived->setArchived(true);
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->boardService->method('findAll')->with('alice')->willReturn([$active, $archived]);
		$this->boardService->method('findAllActive')->with('alice')->willReturn([$active]);

		$this->projectCardMapper->expects(self::exactly(2))
			->method('findCardsInProjectAndBoards')
			->with(5, [1])
			->willReturn([['id' => 9, 'boardId' => 1]]);
		$this->statsService->method('projectStats')->with([9])->willReturn(['cardCount' => 1]);

		// The card on the ACTIVE board is still listed and still counted - both
		// halves matter, or a filter that dropped everything would pass too.
		$result = $this->service->listCards(5, 'alice');
		self::assertSame([9], array_column($result, 'id'));
		self::assertSame(1, $this->service->stats(5, 'alice')['cardCount']);
	}

	public function testListCardsCarriesBlockersAndAssignees(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->boardService->method('findAllActive')->with('alice')->willReturn([$this->board(1)]);
		$this->projectCardMapper->method('findCardsInProjectAndBoards')->willReturn([['id' => 9, 'boardId' => 1], ['id' => 10, 'boardId' => 1]]);
		$this->projectCardMapper->expects(self::once())
			->method('openBlockerCounts')
			->with([9, 10], [1], 'alice')
			->willReturn([10 => 2]);
		$this->cardAssigneeMapper->method('findUserIdsByCards')->with([9, 10])->willReturn([9 => ['bob']]);

		$result = $this->service->listCards(5, 'alice');

		self::assertSame([0, 2], array_column($result, 'blockers'));
		self::assertSame([['bob'], []], array_column($result, 'assigneeIds'));
	}

	public function testPlanForecastsOverTheResolvedCards(): void {
		$project = $this->project('alice');
		$project->setTargetDate('2099-01-01');
		$this->projectMapper->method('find')->with(5)->willReturn($project);
		$this->boardService->method('findAllActive')->with('alice')->willReturn([$this->board(1)]);
		$card = ['id' => 9, 'boardId' => 1, 'doneAt' => 0, 'startedAt' => 0, 'duedate' => null, 'estimate' => null, 'estimateScale' => 'none', 'createdAt' => 100, 'addedAt' => 0];
		$this->projectCardMapper->method('findCardsInProjectAndBoards')->willReturn([$card]);
		$this->projectCardMapper->method('openBlockerCounts')->willReturn([9 => 1]);

		$plan = $this->service->plan(5, 'alice');

		self::assertSame(1, $plan['open']);
		self::assertSame(1, $plan['blocked']);
		self::assertSame('2099-01-01', $plan['targetDate']);
		self::assertSame(ProjectForecast::STATUS_NOT_ENOUGH_DATA, $plan['status']);
	}

	/** @return array<int, string> the roles bob holds on his boards */
	private function bobReadsHisOwnBoards(): array {
		$this->sharedWithBob(PermissionService::PERMISSION_READ);
		$boards = [$this->board(1), $this->board(7)];
		$roles = [1 => 'internal', 7 => 'external'];
		$this->boardService->expects(self::once())->method('findAllActive')->with('bob')->willReturn($boards);
		$this->boardAccess->expects(self::once())->method('rolesFor')->with($boards, 'bob')->willReturn($roles);
		return $roles;
	}

	public function testListCardsResolvesEverythingAsTheViewer(): void {
		$roles = $this->bobReadsHisOwnBoards();
		$this->projectCardMapper->expects(self::once())->method('findCardsInProjectAndBoards')
			->with(5, [1, 7], 'bob', $roles)
			->willReturn([['id' => 9, 'boardId' => 1]]);
		$this->projectCardMapper->expects(self::once())->method('openBlockerCounts')
			->with([9], [1, 7], 'bob', $roles)
			->willReturn([]);

		self::assertSame([9], array_column($this->service->listCards(5, 'bob'), 'id'));
	}

	public function testAViewerReadsThePlanAsThemselves(): void {
		$roles = $this->bobReadsHisOwnBoards();
		$card = ['id' => 9, 'boardId' => 1, 'doneAt' => 0, 'startedAt' => 0, 'duedate' => null, 'estimate' => null, 'estimateScale' => 'none', 'createdAt' => 100, 'addedAt' => 0];
		$this->projectCardMapper->expects(self::once())->method('findCardsInProjectAndBoards')
			->with(5, [1, 7], 'bob', $roles)
			->willReturn([$card]);
		$this->projectCardMapper->expects(self::once())->method('openBlockerCounts')
			->with([9], [1, 7], 'bob', $roles)
			->willReturn([]);

		self::assertSame(1, $this->service->plan(5, 'bob')['open']);
	}

	public function testAViewerReadsTheStatsAsThemselves(): void {
		$roles = $this->bobReadsHisOwnBoards();
		$this->projectCardMapper->expects(self::once())->method('findCardsInProjectAndBoards')
			->with(5, [1, 7], 'bob', $roles)
			->willReturn([['id' => 9, 'boardId' => 1]]);
		$this->statsService->expects(self::once())->method('projectStats')->with([9])->willReturn(['cardCount' => 1]);

		self::assertSame(1, $this->service->stats(5, 'bob')['cardCount']);
	}

	public function testPlanRejectsNonOwner(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->projectCardMapper->expects(self::never())->method('findCardsInProjectAndBoards');
		$this->expectException(DoesNotExistException::class);
		$this->service->plan(5, 'mallory');
	}

	public function testListCardsRejectsNonOwner(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->projectCardMapper->expects(self::never())->method('findCardsInProjectAndBoards');
		$this->expectException(DoesNotExistException::class);
		$this->service->listCards(5, 'mallory');
	}

	public function testStatsResolvesReadableCardIdsAndDelegatesToStatsService(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->boardService->method('findAllActive')->with('alice')->willReturn([$this->board(1), $this->board(7)]);
		// The ACL-filtered card set (already restricted to boards 1 & 7).
		$this->projectCardMapper->expects(self::once())
			->method('findCardsInProjectAndBoards')
			->with(5, [1, 7])
			->willReturn([
				['id' => 9, 'boardId' => 1],
				['id' => 11, 'boardId' => 7],
			]);
		// Exactly those two card ids are handed to the stats engine - never a
		// board id, never a per-row leak.
		$this->statsService->expects(self::once())
			->method('projectStats')
			->with([9, 11])
			->willReturn(['cardCount' => 2, 'overdue' => 0]);

		$result = $this->service->stats(5, 'alice');
		self::assertSame(2, $result['cardCount']);
	}

	public function testStatsRejectsNonOwner(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->projectCardMapper->expects(self::never())->method('findCardsInProjectAndBoards');
		$this->statsService->expects(self::never())->method('projectStats');
		$this->expectException(DoesNotExistException::class);
		$this->service->stats(5, 'mallory');
	}

	public function testStatsNeverIncludesCardsFromUnreadableBoards(): void {
		// The owner can read only board 1. A card living on board 99 (which the
		// owner cannot READ) must NOT reach the stats engine: the readable-board
		// frame passed to the mapper excludes it, and the mapper's board filter
		// drops it - so the id set fed to projectStats holds only the readable
		// card. This is the cross-board-leak guard for project analytics.
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->boardService->method('findAllActive')->with('alice')->willReturn([$this->board(1)]);

		$this->projectCardMapper->expects(self::once())
			->method('findCardsInProjectAndBoards')
			// Only the readable board id (1) frames the query - board 99 is absent.
			->with(5, [1])
			// The mapper, given that frame, returns only the readable card.
			->willReturn([['id' => 9, 'boardId' => 1]]);

		$this->statsService->expects(self::once())
			->method('projectStats')
			->with([9])
			->willReturn(['cardCount' => 1]);

		$result = $this->service->stats(5, 'alice');
		self::assertSame(1, $result['cardCount']);
	}
}
