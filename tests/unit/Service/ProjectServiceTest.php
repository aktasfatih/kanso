<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Project;
use OCA\Kanso\Db\ProjectCardMapper;
use OCA\Kanso\Db\ProjectMapper;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\ProjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProjectServiceTest extends TestCase {
	private ProjectMapper&MockObject $projectMapper;
	private ProjectCardMapper&MockObject $projectCardMapper;
	private BoardService&MockObject $boardService;
	private PermissionService&MockObject $permissionService;
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private ProjectService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->projectMapper = $this->createMock(ProjectMapper::class);
		$this->projectCardMapper = $this->createMock(ProjectCardMapper::class);
		$this->boardService = $this->createMock(BoardService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->service = new ProjectService(
			$this->projectMapper,
			$this->projectCardMapper,
			$this->boardService,
			$this->permissionService,
			$this->cardMapper,
			$this->boardMapper,
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

	public function testUpdateRejectsNonOwner(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->projectMapper->expects(self::never())->method('update');
		$this->expectException(NotPermittedException::class);
		$this->service->update(5, 'mallory', 'Hacked', null, null);
	}

	public function testDeleteCascadesMembershipsForOwner(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->projectCardMapper->expects(self::once())->method('deleteByProject')->with(5);
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
		$this->boardService->method('findAll')->with('alice')->willReturn([$this->board(1), $this->board(7)]);
		$this->projectCardMapper->expects(self::once())
			->method('findCardsInProjectAndBoards')
			->with(5, [1, 7])
			->willReturn([['id' => 9, 'boardId' => 1]]);
		$result = $this->service->listCards(5, 'alice');
		self::assertSame(9, $result[0]['id']);
	}

	public function testListCardsRejectsNonOwner(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->projectCardMapper->expects(self::never())->method('findCardsInProjectAndBoards');
		$this->expectException(NotPermittedException::class);
		$this->service->listCards(5, 'mallory');
	}
}
