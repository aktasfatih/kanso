<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\DeckImportService;
use OCA\Kanso\Service\DeckReader;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\SortKeyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DeckImportServiceTest extends TestCase {
	private DeckReader&MockObject $deckReader;
	private BoardService&MockObject $boardService;
	private StackMapper&MockObject $stackMapper;
	private CardMapper&MockObject $cardMapper;
	private LabelMapper&MockObject $labelMapper;
	private CardLabelMapper&MockObject $cardLabelMapper;
	private CardAssigneeMapper&MockObject $cardAssigneeMapper;
	private IUserManager&MockObject $userManager;
	private \OCP\IDBConnection&MockObject $db;
	private DeckImportService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->deckReader = $this->createMock(DeckReader::class);
		$this->boardService = $this->createMock(BoardService::class);
		$this->stackMapper = $this->createMock(StackMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->labelMapper = $this->createMock(LabelMapper::class);
		$this->cardLabelMapper = $this->createMock(CardLabelMapper::class);
		$this->cardAssigneeMapper = $this->createMock(CardAssigneeMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->db = $this->createMock(\OCP\IDBConnection::class);
		$this->service = new DeckImportService(
			$this->deckReader,
			$this->boardService,
			$this->stackMapper,
			$this->cardMapper,
			$this->labelMapper,
			$this->cardLabelMapper,
			$this->cardAssigneeMapper,
			new SortKeyService(),
			$this->userManager,
			$this->db,
		);
	}

	private function autoId(): callable {
		$next = 1;
		return function ($entity) use (&$next) {
			$entity->setId($next++);
			return $entity;
		};
	}

	public function testImportUnavailableThrows(): void {
		$this->deckReader->method('isAvailable')->willReturn(false);
		$this->expectException(InvalidInputException::class);
		$this->service->importBoard(2, 'alice');
	}

	public function testImportWithoutAccessThrows(): void {
		$this->deckReader->method('isAvailable')->willReturn(true);
		$this->deckReader->method('userCanReadBoard')->with('bob', 2)->willReturn(false);
		$this->expectException(NotPermittedException::class);
		$this->service->importBoard(2, 'bob');
	}

	public function testImportMissingBoardThrows(): void {
		$this->deckReader->method('isAvailable')->willReturn(true);
		$this->deckReader->method('userCanReadBoard')->willReturn(true);
		$this->deckReader->method('readBoard')->with(2)->willReturn(null);
		$this->expectException(DoesNotExistException::class);
		$this->service->importBoard(2, 'alice');
	}

	public function testImportMirrorsBoardStacksCardsLabelsAndAssignees(): void {
		$this->deckReader->method('isAvailable')->willReturn(true);
		$this->deckReader->method('userCanReadBoard')->willReturn(true);
		$this->deckReader->method('readBoard')->with(2)
			->willReturn(['id' => 2, 'title' => 'Migrate me', 'color' => '0082c9', 'owner' => 'carol']);

		$board = new Board();
		$board->setId(100);
		$board->setTitle('Migrate me');
		$this->boardService->method('create')->with('Migrate me', '0082c9', 'alice')->willReturn($board);

		$this->deckReader->method('readLabels')->with(2)->willReturn([
			['id' => 6, 'title' => 'Finished', 'color' => '31CC7C'],
		]);
		$this->deckReader->method('readStacks')->with(2)->willReturn([
			['id' => 11, 'title' => 'To do'],
			['id' => 12, 'title' => 'Done'],
		]);
		$this->deckReader->method('readCards')->willReturnMap([
			[11, [
				['id' => 21, 'title' => 'First', 'description' => 'hello', 'archived' => false, 'duedate' => 1000, 'doneAt' => 0, 'createdAt' => 123],
				['id' => 22, 'title' => 'Second', 'description' => '', 'archived' => true, 'duedate' => null, 'doneAt' => 5000, 'createdAt' => 0],
			]],
			[12, []],
		]);
		$this->deckReader->method('readAssignedLabels')->willReturn([21 => [6]]);
		$this->deckReader->method('readAssignedUsers')->willReturn([21 => ['bob', 'ghost']]);

		// Entities come back with ids assigned in insertion order.
		$this->labelMapper->method('insert')->willReturnCallback($this->autoId());
		$this->stackMapper->method('insert')->willReturnCallback($this->autoId());

		$capturedCards = [];
		$cardId = 500;
		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c) use (&$capturedCards, &$cardId): Card {
			$c->setId($cardId++);
			$capturedCards[] = $c;
			return $c;
		});

		// The first card (kanso id 500) gets the imported label; only existing
		// users are assigned.
		$this->userManager->method('userExists')->willReturnCallback(fn (string $u): bool => $u === 'bob');
		$this->cardLabelMapper->expects(self::once())->method('insertAssignment')->with(500, 1);
		$this->cardAssigneeMapper->expects(self::once())->method('insertAssignment')->with(500, 'bob');

		$result = $this->service->importBoard(2, 'alice');

		self::assertSame(['boardId' => 100, 'title' => 'Migrate me', 'stacks' => 2, 'cards' => 2, 'labels' => 1], $result);

		// Card field mapping: archived + done + duedate carried across.
		self::assertSame('First', $capturedCards[0]->getTitle());
		self::assertSame('hello', $capturedCards[0]->getDescription());
		self::assertSame(123, $capturedCards[0]->getCreatedAt());
		self::assertSame(0, $capturedCards[0]->getDoneAt());
		self::assertInstanceOf(\DateTime::class, $capturedCards[0]->getDuedate());
		self::assertTrue($capturedCards[1]->getArchived());
		self::assertSame(5000, $capturedCards[1]->getDoneAt());
		self::assertNull($capturedCards[1]->getDuedate());

		// Cards land on the FIRST kanso stack (id 1) in order; second stack empty.
		self::assertSame(1, $capturedCards[0]->getStackId());
		self::assertSame(1, $capturedCards[1]->getStackId());
		// Sort keys are strictly increasing within the stack.
		self::assertLessThan($capturedCards[1]->getSortKey(), $capturedCards[0]->getSortKey());
	}

	public function testListImportableEmptyWhenDeckUnavailable(): void {
		$this->deckReader->method('isAvailable')->willReturn(false);
		self::assertSame([], $this->service->listImportableBoards('alice'));
	}

	// ---- transaction (#3478) ----------------------------------------------

	private function stubReadableBoard(): void {
		$this->deckReader->method('isAvailable')->willReturn(true);
		$this->deckReader->method('userCanReadBoard')->willReturn(true);
		$this->deckReader->method('readBoard')->with(2)
			->willReturn(['id' => 2, 'title' => 'B', 'color' => null, 'owner' => 'carol']);
		$board = new Board();
		$board->setId(100);
		$board->setTitle('B');
		$this->boardService->method('create')->willReturn($board);
	}

	public function testImportCommitsOnSuccess(): void {
		$this->stubReadableBoard();
		$this->deckReader->method('readLabels')->willReturn([]);
		$this->deckReader->method('readStacks')->willReturn([]);
		$this->deckReader->method('readAssignedLabels')->willReturn([]);
		$this->deckReader->method('readAssignedUsers')->willReturn([]);
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->db->expects(self::never())->method('rollBack');

		$this->service->importBoard(2, 'alice');
	}

	public function testImportRollsBackAndRethrowsOnFailure(): void {
		$this->stubReadableBoard();
		$this->deckReader->method('readLabels')->willReturn([['id' => 6, 'title' => 'X', 'color' => null]]);
		$this->labelMapper->method('insert')->willThrowException(new \RuntimeException('boom'));
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');

		$this->expectException(\RuntimeException::class);
		$this->service->importBoard(2, 'alice');
	}
}
