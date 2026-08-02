<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\ArchiveRule;
use OCA\Kanso\Db\ArchiveRuleMapper;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\Comment;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\RecurRule;
use OCA\Kanso\Db\RecurRuleMapper;
use OCA\Kanso\Db\ReviewTypeMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\ImportService;
use OCA\Kanso\Service\InvalidInputException;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ImportServiceTest extends TestCase {
	private BoardService&MockObject $boardService;
	private StackMapper&MockObject $stackMapper;
	private CardMapper&MockObject $cardMapper;
	private LabelMapper&MockObject $labelMapper;
	private CardLabelMapper&MockObject $cardLabelMapper;
	private CardAssigneeMapper&MockObject $cardAssigneeMapper;
	private ChecklistItemMapper&MockObject $checklistItemMapper;
	private CommentMapper&MockObject $commentMapper;
	private ReviewTypeMapper&MockObject $reviewTypeMapper;
	private CardReviewMapper&MockObject $cardReviewMapper;
	private ArchiveRuleMapper&MockObject $archiveRuleMapper;
	private RecurRuleMapper&MockObject $recurRuleMapper;
	private IUserManager&MockObject $userManager;
	private \OCP\IDBConnection&MockObject $db;
	private ImportService $service;

	/** @var array<string, int> per-class monotonically-increasing id sequences */
	private array $seq = [];
	/** @var array<int, Card> new card id → inserted Card entity (for parent-remap follow-up) */
	private array $cardsById = [];

	protected function setUp(): void {
		parent::setUp();
		$this->boardService = $this->createMock(BoardService::class);
		$this->stackMapper = $this->createMock(StackMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->labelMapper = $this->createMock(LabelMapper::class);
		$this->cardLabelMapper = $this->createMock(CardLabelMapper::class);
		$this->cardAssigneeMapper = $this->createMock(CardAssigneeMapper::class);
		$this->checklistItemMapper = $this->createMock(ChecklistItemMapper::class);
		$this->commentMapper = $this->createMock(CommentMapper::class);
		$this->reviewTypeMapper = $this->createMock(ReviewTypeMapper::class);
		$this->cardReviewMapper = $this->createMock(CardReviewMapper::class);
		$this->archiveRuleMapper = $this->createMock(ArchiveRuleMapper::class);
		$this->recurRuleMapper = $this->createMock(RecurRuleMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->db = $this->createMock(\OCP\IDBConnection::class);

		$this->service = new ImportService(
			$this->boardService,
			$this->stackMapper,
			$this->cardMapper,
			$this->labelMapper,
			$this->cardLabelMapper,
			$this->cardAssigneeMapper,
			$this->checklistItemMapper,
			$this->commentMapper,
			$this->reviewTypeMapper,
			$this->cardReviewMapper,
			$this->archiveRuleMapper,
			$this->recurRuleMapper,
			$this->userManager,
			$this->db,
		);
	}

	/** Assigns fresh ids in a namespace so remapping is observable. */
	private function autoId(string $ns, int $base): callable {
		$this->seq[$ns] = $base;
		return function ($entity) use ($ns) {
			$entity->setId($this->seq[$ns]++);
			return $entity;
		};
	}

	private function primeDb(): void {
		$this->db->expects(self::once())->method('beginTransaction');
	}

	private function newBoard(string $title = 'Copy'): Board {
		$b = new Board();
		$b->setId(900);
		$b->setTitle($title);
		return $b;
	}

	// ── rejection cases ────────────────────────────────────────────────────────

	public function testRejectsOversizedDocument(): void {
		$this->expectException(InvalidInputException::class);
		$huge = str_repeat('x', ImportService::MAX_DOCUMENT_BYTES + 1);
		$this->service->import($huge, 'alice');
	}

	public function testRejectsMalformedJson(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->import('{not json', 'alice');
	}

	public function testRejectsNonKansoDocument(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->import(json_encode(['foo' => 'bar']), 'alice');
	}

	public function testRejectsFutureVersion(): void {
		$this->expectException(InvalidInputException::class);
		$doc = ['kanso' => ExportService_futureVersion(), 'board' => ['title' => 'x']];
		$this->service->import(json_encode($doc), 'alice');
	}

	public function testRejectsMissingBoard(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->import(json_encode(['kanso' => 1]), 'alice');
	}

	// ── happy path + remapping ─────────────────────────────────────────────────

	public function testImportRemapsGraphAndSetsImporterAsOwner(): void {
		$this->primeDb();
		$this->db->expects(self::once())->method('commit');

		$this->boardService->expects(self::once())
			->method('create')->with('Roadmap', '0082c9', 'importer')
			->willReturn($this->newBoard('Roadmap'));

		$this->labelMapper->method('insert')->willReturnCallback($this->autoId('label', 10));
		$this->reviewTypeMapper->method('insert')->willReturnCallback($this->autoId('rt', 20));
		$this->stackMapper->method('insert')->willReturnCallback($this->autoId('stack', 30));
		$this->checklistItemMapper->method('insert')->willReturnCallback($this->autoId('cl', 60));
		$this->cardReviewMapper->method('insert')->willReturnCallback($this->autoId('rv', 80));

		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c): Card {
			$this->seq['card'] ??= 100;
			$c->setId($this->seq['card']++);
			$this->cardsById[$c->getId()] = $c;
			return $c;
		});
		$this->cardMapper->method('find')->willReturnCallback(fn (int $id): Card => $this->cardsById[$id]);
		$this->cardMapper->method('update')->willReturnCallback(fn (Card $c): Card => $c);

		// Everyone referenced exists here, except "ghost".
		$this->userManager->method('userExists')->willReturnCallback(fn (string $u): bool => $u !== 'ghost');

		$capturedRecur = null;
		$this->recurRuleMapper->method('insert')->willReturnCallback(function (RecurRule $r) use (&$capturedRecur): RecurRule {
			$r->setId(41);
			$capturedRecur = $r;
			return $r;
		});
		$capturedArchive = null;
		$this->archiveRuleMapper->method('insert')->willReturnCallback(function (ArchiveRule $a) use (&$capturedArchive): ArchiveRule {
			$a->setId(51);
			$capturedArchive = $a;
			return $a;
		});

		$labelAssignments = [];
		$this->cardLabelMapper->method('insertAssignment')->willReturnCallback(function (int $cardId, int $labelId) use (&$labelAssignments): \OCA\Kanso\Db\CardLabel {
			$labelAssignments[] = [$cardId, $labelId];
			return new \OCA\Kanso\Db\CardLabel();
		});
		$assignees = [];
		$this->cardAssigneeMapper->method('insertAssignment')->willReturnCallback(function (int $cardId, string $uid) use (&$assignees): \OCA\Kanso\Db\CardAssignee {
			$assignees[] = [$cardId, $uid];
			return new \OCA\Kanso\Db\CardAssignee();
		});

		$capturedComments = [];
		$this->commentMapper->method('insert')->willReturnCallback(function (Comment $c) use (&$capturedComments): Comment {
			$this->seq['cm'] ??= 70;
			$c->setId($this->seq['cm']++);
			$capturedComments[] = $c;
			return $c;
		});

		$doc = [
			'kanso' => 1,
			'exportedAt' => 1234,
			'board' => [
				'title' => 'Roadmap',
				'color' => '0082c9',
				'estimateScale' => 'tshirt',
				'newCardsOnTop' => true,
				'labels' => [['id' => 5, 'title' => 'Bug', 'color' => 'e11']],
				'reviewTypes' => [['id' => 6, 'title' => 'QA', 'color' => '00f']],
				'stacks' => [
					['id' => 1, 'title' => 'Todo', 'sortKey' => 'a', 'role' => 0, 'wipLimit' => null],
					['id' => 2, 'title' => 'Done', 'sortKey' => 'b', 'role' => 5, 'wipLimit' => 2],
				],
				'cards' => [
					[
						'id' => 100, 'stackId' => 1, 'title' => 'Parent', 'sortKey' => 'h',
						'parentCardId' => null, 'priority' => 0,
						'labelIds' => [5], 'assignees' => ['bob', 'ghost'],
						'checklist' => [['title' => 'step', 'done' => false, 'sortKey' => 'a']],
						'comments' => [
							['id' => 200, 'parentCommentId' => null, 'author' => 'carol', 'body' => 'top'],
							['id' => 201, 'parentCommentId' => 200, 'author' => 'ghost', 'body' => 'reply'],
						],
						'reviews' => [['reviewer' => 'dave', 'state' => 'pending', 'requestedBy' => 'bob', 'reviewTypeId' => 6]],
					],
					[
						'id' => 101, 'stackId' => 2, 'title' => 'Child', 'sortKey' => 'h',
						'parentCardId' => 100, 'priority' => 0,
					],
				],
				'archiveRules' => [['id' => 9, 'stackId' => 2, 'condition' => 0, 'thresholdSeconds' => 60, 'enabled' => true]],
				'recurRules' => [[
					'id' => 8, 'templateCardId' => 100, 'targetStackId' => 2, 'mode' => 0,
					'rrule' => 'FREQ=DAILY', 'owner' => 'ghost', 'enabled' => true,
				]],
			],
		];

		// The board update carries the extra settings (estimateScale / newCardsOnTop).
		$this->boardService->expects(self::once())->method('update')
			->with(900, null, null, null, 'importer', 'tshirt', true);

		$result = $this->service->import(json_encode($doc), 'importer');

		self::assertSame(['boardId' => 900, 'title' => 'Roadmap', 'stacks' => 2, 'cards' => 2, 'labels' => 1], $result);

		// Label assignment remapped: old label 5 → new label 10 on the new parent card 100.
		self::assertSame([[100, 10]], $labelAssignments);
		// Unknown assignee "ghost" dropped, "bob" kept.
		self::assertSame([[100, 'bob']], $assignees);

		// Parent remap: the child card's parent became the new parent card id (100).
		self::assertSame(100, $this->cardsById[101]->getParentCardId());

		// Comment author remap: "ghost" reply falls back to importer; threading preserved.
		self::assertSame('carol', $capturedComments[0]->getAuthor());
		self::assertNull($capturedComments[0]->getParentCommentId());
		self::assertSame('importer', $capturedComments[1]->getAuthor());
		self::assertSame($capturedComments[0]->getId(), $capturedComments[1]->getParentCommentId());

		// Recur rule remapped its template card + target stack, and its unknown
		// owner fell back to the importer.
		self::assertSame(100, $capturedRecur->getTemplateCardId());
		self::assertSame(31, $capturedRecur->getTargetStackId());
		self::assertSame('importer', $capturedRecur->getOwner());

		// Archive rule remapped its stack (old 2 → new 31).
		self::assertSame(31, $capturedArchive->getStackId());
	}

	public function testImportRollsBackOnFailure(): void {
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');

		$this->boardService->method('create')->willReturn($this->newBoard());
		$this->labelMapper->method('insert')->willThrowException(new \RuntimeException('boom'));

		$this->expectException(\RuntimeException::class);
		$doc = ['kanso' => 1, 'board' => ['title' => 'x', 'labels' => [['id' => 1, 'title' => 'L']]]];
		$this->service->import(json_encode($doc), 'importer');
	}
}

/** The first version number this build must refuse. */
function ExportService_futureVersion(): int {
	return \OCA\Kanso\Service\ExportService::FORMAT_VERSION + 1;
}
