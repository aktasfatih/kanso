<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Project;
use OCA\Kanso\Db\ProjectCardMapper;
use OCA\Kanso\Db\ProjectMapper;
use OCA\Kanso\Db\ProjectPinMapper;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\ProjectForecast;
use OCA\Kanso\Service\ProjectService;
use OCA\Kanso\Service\StatsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProjectServiceTest extends TestCase {
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
		);
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
		$this->projectMapper->method('findByOwner')->with('alice')->willReturn([$this->project()]);
		$result = $this->service->findMine('alice');
		self::assertCount(1, $result);
		self::assertSame(5, $result[0]['id']);
		self::assertSame('alice', $result[0]['owner']);
	}

	public function testFindMineMarksPinnedProjects(): void {
		$other = $this->project();
		$other->setId(6);
		$this->projectMapper->method('findByOwner')->with('alice')->willReturn([$this->project(), $other]);
		$this->projectPinMapper->method('pinnedMap')->with('alice')->willReturn([6 => true]);

		self::assertSame([false, true], array_column($this->service->findMine('alice'), 'pinned'));
	}

	public function testPinRequiresOwnership(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->projectPinMapper->expects(self::never())->method('pin');
		$this->expectException(NotPermittedException::class);
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
		$this->expectException(NotPermittedException::class);
		$this->service->update(5, 'mallory', 'Hacked', null, null);
	}

	public function testDeleteCascadesMembershipsForOwner(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->projectCardMapper->expects(self::once())->method('deleteByProject')->with(5);
		$this->projectPinMapper->expects(self::once())->method('deleteByProject')->with(5);
		$this->projectMapper->expects(self::once())->method('delete');
		$this->service->delete(5, 'alice');
	}

	public function testDeleteRejectsNonOwner(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->projectCardMapper->expects(self::never())->method('deleteByProject');
		$this->expectException(NotPermittedException::class);
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
		$this->expectException(NotPermittedException::class);
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

	public function testPlanRejectsNonOwner(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->projectCardMapper->expects(self::never())->method('findCardsInProjectAndBoards');
		$this->expectException(NotPermittedException::class);
		$this->service->plan(5, 'mallory');
	}

	public function testListCardsRejectsNonOwner(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->projectCardMapper->expects(self::never())->method('findCardsInProjectAndBoards');
		$this->expectException(NotPermittedException::class);
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
		$this->expectException(NotPermittedException::class);
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
