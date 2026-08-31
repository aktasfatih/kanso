<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\ArchiveRule;
use OCA\Kanso\Db\ArchiveRuleMapper;
use OCA\Kanso\Db\AutomationRule;
use OCA\Kanso\Db\AutomationRuleMapper;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\ChecklistItem;
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
use OCA\Kanso\Service\ExportService;
use OCA\Kanso\Service\ImportService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\RecurrenceService;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImportServiceTest extends TestCase {
	private BoardService&MockObject $boardService;
	private ExportService&MockObject $exportService;
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
	private AutomationRuleMapper&MockObject $automationRuleMapper;
	private IUserManager&MockObject $userManager;
	private \OCP\IDBConnection&MockObject $db;
	private BoardAccess&MockObject $boardAccess;
	private LoggerInterface&MockObject $logger;
	private ImportService $service;

	/** @var array<string, int> per-class monotonically-increasing id sequences */
	private array $seq = [];
	/** @var array<int, Card> new card id → inserted Card entity (for parent-remap follow-up) */
	private array $cardsById = [];

	protected function setUp(): void {
		parent::setUp();
		$this->boardService = $this->createMock(BoardService::class);
		$this->exportService = $this->createMock(ExportService::class);
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
		$this->automationRuleMapper = $this->createMock(AutomationRuleMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->db = $this->createMock(\OCP\IDBConnection::class);
		$this->boardAccess = $this->createMock(BoardAccess::class);
		// duplicate() resolves the duplicating viewer's context and hands it to
		// the (viewer-scoped) export (#3743).
		$this->boardAccess->method('contextFor')->willReturnCallback(
			static fn (Board $board, string $uid): ViewerContext => ViewerContext::forMember($uid, (int)$board->getId(), ViewerContext::ROLE_INTERNAL, true),
		);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new ImportService(
			$this->boardService,
			$this->exportService,
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
			$this->automationRuleMapper,
			$this->userManager,
			$this->db,
			$this->boardAccess,
			$this->realRecurrenceService(),
			$this->logger,
		);
	}

	/**
	 * A REAL RecurrenceService (collaborators mocked, none of them reached).
	 * Import only calls computeNextOccurrence(), which is self-contained - so
	 * the RRULE guard is exercised against sabre's actual parser rather than a
	 * stub that would just agree with whatever the test asserts.
	 */
	private function realRecurrenceService(): RecurrenceService {
		return new RecurrenceService(
			$this->createMock(RecurRuleMapper::class),
			$this->createMock(CardMapper::class),
			$this->createMock(StackMapper::class),
			$this->createMock(\OCA\Kanso\Db\BoardMapper::class),
			$this->createMock(CardLabelMapper::class),
			$this->createMock(CardAssigneeMapper::class),
			$this->createMock(\OCA\Kanso\Service\CardService::class),
			$this->createMock(\OCA\Kanso\Service\ChangeNotifier::class),
			$this->createMock(\OCA\Kanso\Service\PermissionService::class),
			$this->createMock(\OCA\Kanso\Service\CardVisibilityGuard::class),
			$this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class),
			$this->createMock(\OCP\IDBConnection::class),
			$this->createMock(\OCP\IConfig::class),
			$this->createMock(LoggerInterface::class),
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
		$capturedItems = [];
		$this->checklistItemMapper->method('insert')->willReturnCallback(function (ChecklistItem $i) use (&$capturedItems): ChecklistItem {
			$i->setId(60 + count($capturedItems));
			$capturedItems[] = $i;
			return $i;
		});
		$this->cardReviewMapper->method('insert')->willReturnCallback($this->autoId('rv', 80));

		// Fresh import target starts its human-id counter at 1.
		$this->cardMapper->method('nextBoardSeq')->willReturn(1);
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

		$capturedRecur = [];
		$this->recurRuleMapper->method('insert')->willReturnCallback(function (RecurRule $r) use (&$capturedRecur): RecurRule {
			$r->setId(41 + count($capturedRecur));
			$capturedRecur[] = $r;
			return $r;
		});
		$capturedArchive = null;
		$this->archiveRuleMapper->method('insert')->willReturnCallback(function (ArchiveRule $a) use (&$capturedArchive): ArchiveRule {
			$a->setId(51);
			$capturedArchive = $a;
			return $a;
		});
		$capturedAutomation = [];
		$this->automationRuleMapper->method('insert')->willReturnCallback(function (AutomationRule $r) use (&$capturedAutomation): AutomationRule {
			$r->setId(200 + count($capturedAutomation));
			$capturedAutomation[] = $r;
			return $r;
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
						// Rich-step fields (#3745): dueDate is KEPT; any assignee /
						// role / done_at in the document is IGNORED (clone policy).
						'checklist' => [[
							'title' => 'step', 'done' => false, 'sortKey' => 'a',
							'dueDate' => 1755194400,
							'assignedUser' => 'ghost', 'assignedRole' => 'external',
							'assignedAt' => 1, 'doneAt' => 2,
						]],
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
				'recurRules' => [
					[
						'id' => 8, 'templateCardId' => 100, 'targetStackId' => 2, 'mode' => 0,
						'rrule' => 'FREQ=DAILY', 'owner' => 'ghost', 'enabled' => true,
					],
					// Unparseable RRULE → dropped, never written (the rest of the
					// board still imports).
					[
						'id' => 81, 'templateCardId' => 100, 'targetStackId' => 2, 'mode' => 0,
						'rrule' => 'FREQ=NONSENSE', 'owner' => 'bob', 'enabled' => true,
					],
					// FREQ-less rules: sabre CONSTRUCTS these happily and only blows up
					// while iterating - as an \Error, not an \Exception. Documents with
					// an empty rrule exist in the wild (older exports defaulted it to ''
					// and nothing ever parsed it), so this must be a drop, not a 500
					// that takes the whole import down with it.
					[
						'id' => 83, 'templateCardId' => 100, 'targetStackId' => 2, 'mode' => 0,
						'rrule' => '', 'owner' => 'bob', 'enabled' => true,
					],
					[
						'id' => 84, 'templateCardId' => 100, 'targetStackId' => 2, 'mode' => 0,
						'rrule' => 'COUNT=3', 'owner' => 'bob', 'enabled' => true,
					],
					// No `rrule` key at all - the importer defaults it to '', which is
					// the same FREQ-less trap.
					[
						'id' => 85, 'templateCardId' => 100, 'targetStackId' => 2, 'mode' => 0,
						'owner' => 'bob', 'enabled' => true,
					],
					// A perfectly good rule right behind it still lands intact.
					[
						'id' => 82, 'templateCardId' => 100, 'targetStackId' => 2, 'mode' => 0,
						'rrule' => 'FREQ=WEEKLY;BYDAY=MO', 'owner' => 'bob', 'enabled' => true,
					],
				],
				'automationRules' => [
					// add_label rule: label id 5 must remap to the new id 10.
					['id' => 70, 'trigger' => 'card_entered_role', 'action' => 'add_label',
						'params' => ['role' => 5, 'label' => 5], 'enabled' => true],
					// request_review rule: reviewer "bob" exists → kept.
					['id' => 71, 'trigger' => 'card_entered_role', 'action' => 'request_review',
						'params' => ['role' => 3, 'reviewer' => 'bob'], 'enabled' => true],
					// request_review rule: reviewer "ghost" is gone here → dropped.
					['id' => 72, 'trigger' => 'card_entered_role', 'action' => 'request_review',
						'params' => ['role' => 3, 'reviewer' => 'ghost'], 'enabled' => true],
					// add_label rule for a label not in the export → dropped.
					['id' => 73, 'trigger' => 'card_entered_role', 'action' => 'add_label',
						'params' => ['role' => 5, 'label' => 999], 'enabled' => false],
				],
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

		// Rich-step clone policy (#3745): the due date round-trips; the
		// document's assignee / frozen role / stamps never reach the row.
		self::assertCount(1, $capturedItems);
		self::assertSame(1755194400, $capturedItems[0]->getDueDate()?->getTimestamp());
		self::assertNull($capturedItems[0]->getAssignedUser());
		self::assertNull($capturedItems[0]->getAssignedRole());
		self::assertNull($capturedItems[0]->getAssignedAt());
		self::assertNull($capturedItems[0]->getDoneAt());

		// Parent remap: the child card's parent became the new parent card id (100).
		self::assertSame(100, $this->cardsById[101]->getParentCardId());

		// Human-id numbers assigned locally in import order, seeded from 1 (no
		// per-card query): the two imported cards get board_seq 1 and 2.
		self::assertSame(1, $this->cardsById[100]->getBoardSeq());
		self::assertSame(2, $this->cardsById[101]->getBoardSeq());

		// Comment author remap: "ghost" reply falls back to importer; threading preserved.
		self::assertSame('carol', $capturedComments[0]->getAuthor());
		self::assertNull($capturedComments[0]->getParentCommentId());
		self::assertSame('importer', $capturedComments[1]->getAuthor());
		self::assertSame($capturedComments[0]->getId(), $capturedComments[1]->getParentCommentId());

		// Recur rule remapped its template card + target stack, and its unknown
		// owner fell back to the importer.
		self::assertSame(100, $capturedRecur[0]->getTemplateCardId());
		self::assertSame(31, $capturedRecur[0]->getTargetStackId());
		self::assertSame('importer', $capturedRecur[0]->getOwner());

		// None of the unparseable RRULEs reached the mapper - the outright-invalid
		// FREQ and the three FREQ-less variants alike - while the valid rule sitting
		// behind all of them imported with its RRULE intact.
		self::assertSame(
			['FREQ=DAILY', 'FREQ=WEEKLY;BYDAY=MO'],
			array_map(static fn (RecurRule $r): string => $r->getRrule(), $capturedRecur),
		);

		// Archive rule remapped its stack (old 2 → new 31).
		self::assertSame(31, $capturedArchive->getStackId());

		// Automation rules: the add_label rule remapped its label (5 → 10) and the
		// request_review rule with a known reviewer survived; the rule referencing
		// the vanished reviewer "ghost" and the one referencing an absent label
		// were both dropped. Two rules survive.
		self::assertCount(2, $capturedAutomation);
		$byAction = [];
		foreach ($capturedAutomation as $r) {
			$byAction[$r->getAction()] = json_decode($r->getParams(), true);
		}
		self::assertSame(10, $byAction['add_label']['label']);
		self::assertSame('bob', $byAction['request_review']['reviewer']);
	}

	// ── duplicate ───────────────────────────────────────────────────────────────

	/**
	 * Builds a minimal export doc a duplicated source board would produce.
	 *
	 * @return array{kanso: int, exportedAt: int, board: array<string, mixed>}
	 */
	private function sourceExport(): array {
		return [
			'kanso' => ExportService::FORMAT_VERSION,
			'exportedAt' => 1234,
			'board' => [
				'title' => 'Roadmap',
				'color' => '0082c9',
				'archived' => false,
				'labels' => [['id' => 5, 'title' => 'Bug', 'color' => 'e11']],
				'stacks' => [['id' => 1, 'title' => 'Todo', 'sortKey' => 'a', 'role' => 0, 'wipLimit' => null]],
				'cards' => [['id' => 100, 'stackId' => 1, 'title' => 'Alpha', 'sortKey' => 'h', 'priority' => 0]],
			],
		];
	}

	public function testDuplicateWithCardsClonesTheGraphIntoACopyOwnedByCaller(): void {
		$this->primeDb();
		$this->db->expects(self::once())->method('commit');

		$source = new Board();
		$source->setId(7);
		$source->setTitle('Roadmap');
		$this->exportService->expects(self::once())->method('export')
			->with($source, self::isInstanceOf(ViewerContext::class))
			->willReturn($this->sourceExport());

		// The copy is created through BoardService, titled "<original> (copy)",
		// owned by the caller.
		$this->boardService->expects(self::once())->method('create')
			->with('Roadmap (copy)', '0082c9', 'alice')
			->willReturn($this->newBoard('Roadmap (copy)'));

		$this->labelMapper->method('insert')->willReturnCallback($this->autoId('label', 10));
		$this->stackMapper->method('insert')->willReturnCallback($this->autoId('stack', 30));
		$this->userManager->method('userExists')->willReturn(true);

		$this->cardMapper->method('nextBoardSeq')->willReturn(1);
		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c): Card {
			$this->seq['card'] ??= 100;
			$c->setId($this->seq['card']++);
			$this->cardsById[$c->getId()] = $c;
			return $c;
		});

		$result = $this->service->duplicate($source, 'alice', true);

		self::assertSame('Roadmap (copy)', $result['title']);
		self::assertSame(900, $result['boardId']);
		self::assertSame(1, $result['stacks']);
		self::assertSame(1, $result['cards']);
		self::assertSame(1, $result['labels']);
	}

	public function testDuplicateWithoutCardsProducesAStructuralClone(): void {
		$this->primeDb();
		$this->db->expects(self::once())->method('commit');

		$source = new Board();
		$source->setId(7);
		$source->setTitle('Roadmap');
		$this->exportService->method('export')->willReturn($this->sourceExport());

		$this->boardService->method('create')->willReturn($this->newBoard('Roadmap (copy)'));
		$this->labelMapper->method('insert')->willReturnCallback($this->autoId('label', 10));
		$this->stackMapper->method('insert')->willReturnCallback($this->autoId('stack', 30));
		$this->userManager->method('userExists')->willReturn(true);

		// Structure-only clone: NOT a single card is inserted.
		$this->cardMapper->expects(self::never())->method('insert');
		$this->cardMapper->method('nextBoardSeq')->willReturn(1);

		$result = $this->service->duplicate($source, 'alice', false);

		self::assertSame(0, $result['cards']);
		self::assertSame(1, $result['stacks']);
		self::assertSame(1, $result['labels']);
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
