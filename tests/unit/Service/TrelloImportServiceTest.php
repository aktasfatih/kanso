<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardLabel;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\ChecklistItem;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\SortKeyService;
use OCA\Kanso\Service\TrelloImportService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TrelloImportServiceTest extends TestCase {
	private BoardService&MockObject $boardService;
	private StackMapper&MockObject $stackMapper;
	private CardMapper&MockObject $cardMapper;
	private LabelMapper&MockObject $labelMapper;
	private CardLabelMapper&MockObject $cardLabelMapper;
	private ChecklistItemMapper&MockObject $checklistItemMapper;
	private IDBConnection&MockObject $db;
	private TrelloImportService $service;

	/** @var array<string, int> per-class monotonically-increasing id sequences */
	private array $seq = [];

	protected function setUp(): void {
		parent::setUp();
		$this->boardService = $this->createMock(BoardService::class);
		$this->stackMapper = $this->createMock(StackMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->labelMapper = $this->createMock(LabelMapper::class);
		$this->cardLabelMapper = $this->createMock(CardLabelMapper::class);
		$this->checklistItemMapper = $this->createMock(ChecklistItemMapper::class);
		$this->db = $this->createMock(IDBConnection::class);

		$this->service = new TrelloImportService(
			$this->boardService,
			$this->stackMapper,
			$this->cardMapper,
			$this->labelMapper,
			$this->cardLabelMapper,
			$this->checklistItemMapper,
			new SortKeyService(),
			$this->db,
		);
	}

	/** Assigns fresh ids in a namespace so mapping is observable. */
	private function autoId(string $ns, int $base): callable {
		$this->seq[$ns] = $base;
		return function ($entity) use ($ns) {
			$entity->setId($this->seq[$ns]++);
			return $entity;
		};
	}

	private function newBoard(string $title): Board {
		$b = new Board();
		$b->setId(900);
		$b->setTitle($title);
		return $b;
	}

	// ── rejection cases ────────────────────────────────────────────────────────

	public function testRejectsOversizedDocument(): void {
		$this->expectException(InvalidInputException::class);
		$huge = str_repeat('x', TrelloImportService::MAX_DOCUMENT_BYTES + 1);
		$this->service->import($huge, 'alice');
	}

	public function testRejectsMalformedJson(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->import('{not json', 'alice');
	}

	public function testRejectsNonTrelloDocument(): void {
		// Valid JSON but missing the `lists` / `cards` arrays that identify a
		// Trello board export.
		$this->expectException(InvalidInputException::class);
		$this->service->import(json_encode(['name' => 'x', 'foo' => 'bar']), 'alice');
	}

	public function testRejectsListsWithoutCardsArray(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->import(json_encode(['name' => 'x', 'lists' => []]), 'alice');
	}

	// ── happy path ──────────────────────────────────────────────────────────────

	public function testImportMapsStructureCardsLabelsChecklists(): void {
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');

		// Importer becomes the board owner.
		$this->boardService->expects(self::once())
			->method('create')->with('My Trello Board', null, 'importer')
			->willReturn($this->newBoard('My Trello Board'));

		$this->labelMapper->method('insert')->willReturnCallback($this->autoId('label', 10));
		$this->stackMapper->method('insert')->willReturnCallback($this->autoId('stack', 30));

		$cards = [];
		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c) use (&$cards): Card {
			$this->seq['card'] ??= 100;
			$c->setId($this->seq['card']++);
			$cards[] = $c;
			return $c;
		});

		$labelAssignments = [];
		$this->cardLabelMapper->method('insertAssignment')->willReturnCallback(function (int $cardId, int $labelId) use (&$labelAssignments): CardLabel {
			$labelAssignments[] = [$cardId, $labelId];
			return new CardLabel();
		});

		$checklistItems = [];
		$this->checklistItemMapper->method('insert')->willReturnCallback(function (ChecklistItem $i) use (&$checklistItems): ChecklistItem {
			$i->setId(count($checklistItems) + 500);
			$checklistItems[] = $i;
			return $i;
		});

		$stacks = [];
		$this->stackMapper->method('insert')->willReturnCallback(function (Stack $s) use (&$stacks): Stack {
			$this->seq['stack'] ??= 30;
			$s->setId($this->seq['stack']++);
			$stacks[] = $s;
			return $s;
		});

		$doc = [
			'name' => 'My Trello Board',
			'labels' => [
				['id' => 'L1', 'name' => 'Bug', 'color' => 'red'],
				['id' => 'L2', 'name' => '', 'color' => 'green'],
			],
			// Intentionally out of order + one closed list to prove pos-ordering
			// and the archived-list rule.
			'lists' => [
				['id' => 'listB', 'name' => 'Doing', 'pos' => 200, 'closed' => false],
				['id' => 'listA', 'name' => 'Todo', 'pos' => 100, 'closed' => false],
				['id' => 'listC', 'name' => 'Old', 'pos' => 300, 'closed' => true],
			],
			'cards' => [
				[
					'id' => 'cardA2', 'idList' => 'listA', 'name' => 'Second', 'pos' => 200,
					'desc' => '', 'closed' => false,
				],
				[
					'id' => 'cardA1', 'idList' => 'listA', 'name' => 'First', 'pos' => 100,
					'desc' => 'the first card', 'due' => '2026-01-15T10:00:00.000Z',
					'dueComplete' => true, 'closed' => false, 'idLabels' => ['L1', 'L2'],
				],
				[
					'id' => 'cardB1', 'idList' => 'listB', 'name' => 'Archived one', 'pos' => 100,
					'closed' => true,
				],
				// A card whose list is not in the export is dropped, not orphaned.
				['id' => 'orphan', 'idList' => 'ghostList', 'name' => 'Orphan', 'pos' => 1],
			],
			'checklists' => [
				[
					'id' => 'cl1', 'idCard' => 'cardA1', 'pos' => 100,
					'checkItems' => [
						// Rich-step fields (#3745): `due` is KEPT as the step due
						// date; `idMember` is a Trello member id with no NC uid
						// mapping and must be DROPPED (clone-path policy).
						['name' => 'step two', 'state' => 'incomplete', 'pos' => 200,
							'due' => '2026-02-01T09:00:00.000Z', 'idMember' => 'tr-member-1'],
						['name' => 'step one', 'state' => 'complete', 'pos' => 100],
					],
				],
			],
		];

		$result = $this->service->import(json_encode($doc), 'importer');

		self::assertSame(
			['boardId' => 900, 'title' => 'My Trello Board', 'stacks' => 3, 'cards' => 3, 'labels' => 2],
			$result
		);

		// Lists became stacks in pos order (Todo, Doing, Old); the closed list is
		// imported ARCHIVED rather than dropped.
		self::assertSame(['Todo', 'Doing', 'Old'], array_map(fn (Stack $s) => $s->getTitle(), $stacks));
		self::assertFalse($stacks[0]->getArchived());
		self::assertTrue($stacks[2]->getArchived());

		// Cards land on the right stack in pos order; the orphan is dropped.
		self::assertCount(3, $cards);
		$byTitle = [];
		foreach ($cards as $c) {
			$byTitle[$c->getTitle()] = $c;
		}
		self::assertArrayHasKey('First', $byTitle);
		self::assertArrayHasKey('Second', $byTitle);
		self::assertArrayHasKey('Archived one', $byTitle);
		self::assertArrayNotHasKey('Orphan', $byTitle);

		// name→title, desc→description, due→duedate, dueComplete→done.
		self::assertSame('the first card', $byTitle['First']->getDescription());
		self::assertSame(strtotime('2026-01-15T10:00:00.000Z'), $byTitle['First']->getDuedate()->getTimestamp());
		self::assertGreaterThan(0, $byTitle['First']->getDoneAt());
		self::assertSame(0, $byTitle['Second']->getDoneAt());
		// closed→archived.
		self::assertTrue($byTitle['Archived one']->getArchived());
		self::assertFalse($byTitle['First']->getArchived());
		// Blank desc → null.
		self::assertNull($byTitle['Second']->getDescription());

		// "First" was ordered before "Second" within Todo (pos 100 < 200).
		self::assertTrue($byTitle['First']->getSortKey() < $byTitle['Second']->getSortKey());

		// Both of First's Trello labels mapped to the new Kanso label ids (10, 11).
		$firstCardId = $byTitle['First']->getId();
		$assignedLabels = array_map(fn ($a) => $a[1], array_filter($labelAssignments, fn ($a) => $a[0] === $firstCardId));
		sort($assignedLabels);
		self::assertSame([10, 11], $assignedLabels);

		// Checklist items flattened onto First, pos-ordered, state:complete→done.
		self::assertCount(2, $checklistItems);
		self::assertSame($firstCardId, $checklistItems[0]->getCardId());
		self::assertSame('step one', $checklistItems[0]->getTitle());
		self::assertTrue($checklistItems[0]->getDone());
		self::assertSame('step two', $checklistItems[1]->getTitle());
		self::assertFalse($checklistItems[1]->getDone());
		self::assertTrue($checklistItems[0]->getSortKey() < $checklistItems[1]->getSortKey());
		// Rich-step clone policy (#3745): checkItem `due` becomes the step due
		// date; the Trello member never becomes an assignee.
		self::assertSame(strtotime('2026-02-01T09:00:00.000Z'), $checklistItems[1]->getDueDate()?->getTimestamp());
		self::assertNull($checklistItems[1]->getAssignedUser());
		self::assertNull($checklistItems[0]->getDueDate());
	}

	public function testTruncatesLongTitlesToFitColumns(): void {
		// A Trello name far longer than the VARCHAR(100) title columns must be
		// truncated so it does not fail the whole (transactional) import.
		$this->db->method('beginTransaction');
		$this->db->method('commit');
		$this->boardService->method('create')->willReturn($this->newBoard('board'));

		$capturedStack = null;
		$this->stackMapper->method('insert')->willReturnCallback(function (Stack $s) use (&$capturedStack): Stack {
			$s->setId(30);
			$capturedStack = $s;
			return $s;
		});
		$capturedCard = null;
		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c) use (&$capturedCard): Card {
			$c->setId(100);
			$capturedCard = $c;
			return $c;
		});

		$long = str_repeat('x', 250);
		$doc = [
			'name' => 'x',
			'lists' => [['id' => 'l1', 'name' => $long, 'pos' => 1]],
			'cards' => [['id' => 'c1', 'idList' => 'l1', 'name' => $long, 'pos' => 1]],
		];
		$this->service->import(json_encode($doc), 'importer');

		self::assertSame(100, mb_strlen($capturedStack->getTitle()));
		self::assertSame(100, mb_strlen($capturedCard->getTitle()));
	}

	/**
	 * A list of far more than ~1150 cards - and a card with that many checklist
	 * items - used to abort the import: chaining after() once per item grew the
	 * fractional sort key past the varchar(64) column ("rebalance_required").
	 * Both are now laid out with a single bounded appendSequence, so a huge board
	 * imports with short, strictly-increasing, source-ordered keys.
	 */
	public function testImportLargeListAndChecklistDoNotOverflowSortKey(): void {
		$this->db->expects(self::once())->method('commit');
		$this->boardService->method('create')->willReturn($this->newBoard('Big'));
		$this->stackMapper->method('insert')->willReturnCallback($this->autoId('stack', 30));

		$cardKeys = [];
		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c) use (&$cardKeys): Card {
			$this->seq['card'] ??= 100;
			$c->setId($this->seq['card']++);
			$cardKeys[] = $c->getSortKey();
			return $c;
		});

		$itemKeys = [];
		$this->checklistItemMapper->method('insert')->willReturnCallback(function (ChecklistItem $i) use (&$itemKeys): ChecklistItem {
			$i->setId(count($itemKeys) + 5000);
			$itemKeys[] = $i->getSortKey();
			return $i;
		});

		// Well past the old ~1153-item overflow threshold, on ONE list.
		$count = 2000;
		$cards = [];
		$checkItems = [];
		for ($i = 0; $i < $count; $i++) {
			$cards[] = ['id' => 'C' . $i, 'idList' => 'L1', 'name' => 'Card ' . $i, 'pos' => $i + 1];
			$checkItems[] = ['name' => 'Item ' . $i, 'pos' => $i + 1, 'state' => 'incomplete'];
		}
		// One card also carries a huge single checklist (its items share one key run).
		$checklists = [['id' => 'CL1', 'idCard' => 'C0', 'pos' => 1, 'checkItems' => $checkItems]];

		$doc = [
			'name' => 'Big',
			'lists' => [['id' => 'L1', 'name' => 'To do', 'pos' => 1]],
			'cards' => $cards,
			'checklists' => $checklists,
		];

		$result = $this->service->import(json_encode($doc), 'importer');

		self::assertSame($count, $result['cards']);
		self::assertCount($count, $cardKeys);
		self::assertCount($count, $itemKeys);
		foreach ([$cardKeys, $itemKeys] as $keys) {
			foreach ($keys as $key) {
				self::assertLessThanOrEqual(SortKeyService::MAX_KEY_LENGTH, strlen($key));
			}
			$sorted = $keys;
			sort($sorted, SORT_STRING);
			self::assertSame($sorted, $keys, 'keys must be strictly increasing in source order');
			self::assertSame(count(array_unique($keys)), count($keys), 'keys must be unique');
		}
	}

	public function testImportRollsBackOnFailure(): void {
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');

		$this->boardService->method('create')->willReturn($this->newBoard('x'));
		$this->labelMapper->method('insert')->willThrowException(new \RuntimeException('boom'));

		$this->expectException(\RuntimeException::class);
		$doc = [
			'name' => 'x',
			'lists' => [],
			'cards' => [],
			'labels' => [['id' => 'L1', 'name' => 'Bug', 'color' => 'red']],
		];
		$this->service->import(json_encode($doc), 'importer');
	}
}
