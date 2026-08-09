<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssignee;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabel;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\CsvImportService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\SortKeyService;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CsvImportServiceTest extends TestCase {
	private BoardService&MockObject $boardService;
	private StackMapper&MockObject $stackMapper;
	private CardMapper&MockObject $cardMapper;
	private LabelMapper&MockObject $labelMapper;
	private CardLabelMapper&MockObject $cardLabelMapper;
	private CardAssigneeMapper&MockObject $cardAssigneeMapper;
	private ChangeNotifier&MockObject $changeNotifier;
	private PermissionService&MockObject $permissionService;
	private IUserManager&MockObject $userManager;
	private IDBConnection&MockObject $db;
	private CsvImportService $service;

	private const BOARD_ID = 900;
	private const STACK_ID = 30;

	protected function setUp(): void {
		parent::setUp();
		$this->boardService = $this->createMock(BoardService::class);
		$this->stackMapper = $this->createMock(StackMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->labelMapper = $this->createMock(LabelMapper::class);
		$this->cardLabelMapper = $this->createMock(CardLabelMapper::class);
		$this->cardAssigneeMapper = $this->createMock(CardAssigneeMapper::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->db = $this->createMock(IDBConnection::class);

		$this->service = new CsvImportService(
			$this->boardService,
			$this->stackMapper,
			$this->cardMapper,
			$this->labelMapper,
			$this->cardLabelMapper,
			$this->cardAssigneeMapper,
			new SortKeyService(),
			$this->changeNotifier,
			$this->permissionService,
			$this->userManager,
			$this->db,
		);
	}

	private function board(): Board {
		$b = new Board();
		$b->setId(self::BOARD_ID);
		$b->setTitle('Target');
		return $b;
	}

	private function stack(int $role = Stack::ROLE_NONE): Stack {
		$s = new Stack();
		$s->setId(self::STACK_ID);
		$s->setBoardId(self::BOARD_ID);
		$s->setTitle('Todo');
		$s->setRole($role);
		return $s;
	}

	/** Wires up the common happy-path expectations (board/stack found + EDIT ok). */
	private function primeTarget(): void {
		$this->boardService->method('find')->with(self::BOARD_ID, 'alice')->willReturn($this->board());
		$this->stackMapper->method('find')->with(self::STACK_ID)->willReturn($this->stack());
		$this->permissionService->method('assertPermission');
		$this->cardMapper->method('nextBoardSeq')->willReturnCallback(function (): int {
			static $n = 0;
			return ++$n;
		});
		$this->cardMapper->method('findLastInStack')->willReturn(null);
	}

	private function mapping(): array {
		return ['title' => 0, 'description' => 1, 'duedate' => 2, 'labels' => 3, 'assignees' => 4];
	}

	// ── rejection cases ────────────────────────────────────────────────────────

	public function testRejectsOversizedDocument(): void {
		$this->expectException(InvalidInputException::class);
		$huge = str_repeat('x', CsvImportService::MAX_DOCUMENT_BYTES + 1);
		$this->service->import($huge, self::BOARD_ID, self::STACK_ID, ['title' => 0], true, 'alice');
	}

	public function testRejectsMissingTitleMapping(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->import("a,b\n1,2\n", self::BOARD_ID, self::STACK_ID, ['description' => 1], true, 'alice');
	}

	public function testRejectsTooManyRows(): void {
		$this->expectException(InvalidInputException::class);
		$rows = "title\n" . str_repeat("x\n", CsvImportService::MAX_ROWS + 1);
		$this->service->import($rows, self::BOARD_ID, self::STACK_ID, ['title' => 0], true, 'alice');
	}

	public function testDeniesWithoutEdit(): void {
		$this->boardService->method('find')->willReturn($this->board());
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException('nope'));
		$this->expectException(NotPermittedException::class);
		$this->service->import("title\nBuy milk\n", self::BOARD_ID, self::STACK_ID, ['title' => 0], true, 'alice');
	}

	// ── happy path ──────────────────────────────────────────────────────────────

	public function testImportsRowsAsCardsAppendedInOrder(): void {
		$this->primeTarget();
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->changeNotifier->expects(self::once())->method('pushBoardChanged')->with(self::BOARD_ID);
		// One CREATE change row per imported card (2 titled rows).
		$this->changeNotifier->expects(self::exactly(2))->method('recordChange');

		$this->labelMapper->method('findByBoard')->willReturn([]);

		$cards = [];
		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c) use (&$cards): Card {
			$c->setId(100 + count($cards));
			$cards[] = $c;
			return $c;
		});

		$csv = "title,description,due date,labels,assignees\n"
			. "Design login,Wireframe the flow,2026-02-01,,\n"
			. ",no title so skipped,,,\n"
			. "Ship it,,,,\n";

		$result = $this->service->import($csv, self::BOARD_ID, self::STACK_ID, $this->mapping(), true, 'alice');

		self::assertSame(2, $result['cards']);
		self::assertSame(1, $result['skipped']);
		self::assertSame(self::BOARD_ID, $result['boardId']);
		self::assertSame(self::STACK_ID, $result['stackId']);

		self::assertCount(2, $cards);
		self::assertSame('Design login', $cards[0]->getTitle());
		self::assertSame('Wireframe the flow', $cards[0]->getDescription());
		self::assertSame(self::STACK_ID, $cards[0]->getStackId());
		self::assertSame('alice', $cards[0]->getOwner());
		self::assertNotNull($cards[0]->getDuedate());
		// Second titled row keeps file order via a strictly-increasing sort key.
		self::assertSame('Ship it', $cards[1]->getTitle());
		self::assertNull($cards[1]->getDescription());
		self::assertTrue($cards[0]->getSortKey() < $cards[1]->getSortKey());
	}

	public function testImportsFarBeyondOldRowCap(): void {
		// Guards the "import ALL the cards" contract: a spreadsheet with many more
		// rows than the old 2000 hard cap imports every titled row, streamed off the
		// handle rather than capped or held in one big array.
		$this->primeTarget();
		$this->db->method('beginTransaction');
		$this->db->method('commit');
		$this->labelMapper->method('findByBoard')->willReturn([]);

		$count = 0;
		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c) use (&$count): Card {
			$c->setId(1000 + $count);
			$count++;
			return $c;
		});

		$rowCount = 5000;
		$csv = "title\n" . str_repeat("Task\n", $rowCount);
		$result = $this->service->import($csv, self::BOARD_ID, self::STACK_ID, ['title' => 0], true, 'alice');

		self::assertSame($rowCount, $result['cards']);
		self::assertSame($rowCount, $count);
	}

	public function testTruncatesLongTitles(): void {
		$this->primeTarget();
		$this->db->method('beginTransaction');
		$this->db->method('commit');
		$this->labelMapper->method('findByBoard')->willReturn([]);

		$captured = null;
		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c) use (&$captured): Card {
			$c->setId(100);
			$captured = $c;
			return $c;
		});

		$long = str_repeat('x', 250);
		$this->service->import("title\n$long\n", self::BOARD_ID, self::STACK_ID, ['title' => 0], true, 'alice');
		self::assertSame(100, mb_strlen($captured->getTitle()));
	}

	// ── labels: match-or-create ─────────────────────────────────────────────────

	public function testLabelsMatchExistingOrAreCreated(): void {
		$this->primeTarget();
		$this->db->method('beginTransaction');
		$this->db->method('commit');

		// An existing "Bug" label on the board (matched case-insensitively).
		$existing = new Label();
		$existing->setId(7);
		$existing->setTitle('Bug');
		$existing->setColor('eb5a46');
		$this->labelMapper->method('findByBoard')->willReturn([$existing]);

		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c): Card {
			static $id = 100;
			$c->setId($id++);
			return $c;
		});

		$createdLabels = [];
		$this->labelMapper->method('insert')->willReturnCallback(function (Label $l) use (&$createdLabels): Label {
			$l->setId(50 + count($createdLabels));
			$createdLabels[] = $l;
			return $l;
		});

		$assignments = [];
		$this->cardLabelMapper->method('insertAssignment')->willReturnCallback(function (int $cardId, int $labelId) use (&$assignments): CardLabel {
			$assignments[] = [$cardId, $labelId];
			return new CardLabel();
		});

		// Row 1 references the existing "bug" (different case) + a new "urgent";
		// row 2 references "urgent" again → it must be created ONCE and reused.
		$csv = "title,labels\n"
			. "A,\"bug, urgent\"\n"
			. "B,urgent\n";
		$result = $this->service->import($csv, self::BOARD_ID, self::STACK_ID, ['title' => 0, 'labels' => 1], true, 'alice');

		self::assertSame(1, $result['labelsCreated']);
		self::assertCount(1, $createdLabels);
		self::assertSame('urgent', $createdLabels[0]->getTitle());

		// Card A got both the existing (7) and the new label; card B reused the new
		// label id (never a second create).
		$newLabelId = $createdLabels[0]->getId();
		$forA = array_values(array_filter($assignments, fn ($a) => $a[0] === 100));
		$forB = array_values(array_filter($assignments, fn ($a) => $a[0] === 101));
		$aIds = array_map(fn ($a) => $a[1], $forA);
		sort($aIds);
		self::assertSame([7, $newLabelId], $aIds);
		self::assertSame([[101, $newLabelId]], $forB);
	}

	// ── assignees: match-or-drop, READ-filtered ─────────────────────────────────

	public function testAssigneesMatchOrDropByReadPermission(): void {
		$this->primeTarget();
		$this->db->method('beginTransaction');
		$this->db->method('commit');
		$this->labelMapper->method('findByBoard')->willReturn([]);
		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c): Card {
			$c->setId(100);
			return $c;
		});

		// alice exists + can READ; bob does not exist; carol exists but cannot READ.
		$this->userManager->method('userExists')->willReturnCallback(
			fn (string $uid): bool => in_array($uid, ['alice', 'carol'], true),
		);
		$this->permissionService->method('getPermissions')->willReturnCallback(
			fn (Board $b, string $uid): int => $uid === 'alice' ? PermissionService::PERMISSION_READ : 0,
		);

		$assigned = [];
		$this->cardAssigneeMapper->method('insertAssignment')->willReturnCallback(function (int $cardId, string $uid) use (&$assigned): CardAssignee {
			$assigned[] = $uid;
			return new CardAssignee();
		});

		$csv = "title,assignees\nTask,\"alice, bob, carol\"\n";
		$this->service->import($csv, self::BOARD_ID, self::STACK_ID, ['title' => 0, 'assignees' => 1], true, 'alice');

		// Only alice survives (bob missing, carol unreadable) - no leak.
		self::assertSame(['alice'], $assigned);
	}

	public function testRollsBackOnFailure(): void {
		$this->primeTarget();
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');
		$this->changeNotifier->expects(self::never())->method('pushBoardChanged');

		$this->labelMapper->method('findByBoard')->willReturn([]);
		$this->cardMapper->method('insert')->willThrowException(new \RuntimeException('boom'));

		$this->expectException(\RuntimeException::class);
		$this->service->import("title\nX\n", self::BOARD_ID, self::STACK_ID, ['title' => 0], true, 'alice');
	}
}
