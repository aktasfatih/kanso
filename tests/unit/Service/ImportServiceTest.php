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
use OCA\Kanso\Db\CardAttachment;
use OCA\Kanso\Db\CardAttachmentMapper;
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
use OCA\Kanso\Service\ImportArchiveReader;
use OCA\Kanso\Service\ImportService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\RecurrenceService;
use OCP\Files\IAppData;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ITempManager;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
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
	private CardAttachmentMapper&MockObject $cardAttachmentMapper;
	private IUserManager&MockObject $userManager;
	private \OCP\IDBConnection&MockObject $db;
	private BoardAccess&MockObject $boardAccess;
	private IAppData&MockObject $appData;
	private ISecureRandom&MockObject $secureRandom;
	private ITempManager&MockObject $tempManager;
	private IMimeTypeDetector&MockObject $mimeTypeDetector;
	private LoggerInterface&MockObject $logger;
	private ImportService $service;

	/** @var array<string, int> per-class monotonically-increasing id sequences */
	private array $seq = [];
	/** @var array<int, Card> new card id → inserted Card entity (for parent-remap follow-up) */
	private array $cardsById = [];
	/**
	 * The fake app-data: "card-<id>/<storage key>" → the bytes newFile() got.
	 *
	 * @var array<string, string>
	 */
	private array $appDataObjects = [];
	/** Temp paths this test created, removed in tearDown. */
	private array $tempPaths = [];

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
		$this->cardAttachmentMapper = $this->createMock(CardAttachmentMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->db = $this->createMock(\OCP\IDBConnection::class);
		$this->boardAccess = $this->createMock(BoardAccess::class);
		// duplicate() resolves the duplicating viewer's context and hands it to
		// the (viewer-scoped) export (#3743).
		$this->boardAccess->method('contextFor')->willReturnCallback(
			static fn (Board $board, string $uid): ViewerContext => ViewerContext::forMember($uid, (int)$board->getId(), ViewerContext::ROLE_INTERNAL, true),
		);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->primeAppData();

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
			$this->cardAttachmentMapper,
			$this->userManager,
			$this->db,
			$this->boardAccess,
			$this->realRecurrenceService(),
			$this->appData,
			$this->secureRandom,
			$this->tempManager,
			$this->mimeTypeDetector,
			$this->logger,
		);
	}

	protected function tearDown(): void {
		foreach ($this->tempPaths as $path) {
			@unlink($path);
		}
		$this->tempPaths = [];
		parent::tearDown();
	}

	/**
	 * An in-memory stand-in for Kanso's app-data plus the temp/random/mime
	 * collaborators the attachment restore needs, so a test can assert on the
	 * bytes that actually landed and on what a rollback removed again.
	 */
	private function primeAppData(): void {
		$this->appData = $this->createMock(IAppData::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->tempManager = $this->createMock(ITempManager::class);
		$this->mimeTypeDetector = $this->createMock(IMimeTypeDetector::class);

		$keySeq = 0;
		$this->secureRandom->method('generate')->willReturnCallback(
			static function () use (&$keySeq): string {
				return 'storagekey' . str_pad((string)(++$keySeq), 22, '0', STR_PAD_LEFT);
			},
		);

		$this->tempManager->method('getTemporaryFile')->willReturnCallback(
			function (string $suffix = ''): string {
				$path = tempnam(sys_get_temp_dir(), 'kanso-import-test-') . $suffix;
				$this->tempPaths[] = $path;
				touch($path);
				return $path;
			},
		);

		// Default: whatever finfo would say is irrelevant here unless a test
		// overrides it; a plain text label keeps the sanitizer happy.
		$this->mimeTypeDetector->method('detectContent')->willReturn('text/plain');
		$this->mimeTypeDetector->method('detectPath')->willReturn('text/plain');

		$folders = [];
		$makeFolder = function (string $name) use (&$folders): ISimpleFolder {
			if (isset($folders[$name])) {
				return $folders[$name];
			}
			$folder = $this->createMock(ISimpleFolder::class);
			$folder->method('newFile')->willReturnCallback(
				function (string $key, $content) use ($name): ISimpleFile {
					$bytes = is_resource($content) ? (string)stream_get_contents($content) : (string)$content;
					$this->appDataObjects[$name . '/' . $key] = $bytes;
					return $this->createMock(ISimpleFile::class);
				},
			);
			$folder->method('getFile')->willReturnCallback(
				function (string $key) use ($name): ISimpleFile {
					if (!isset($this->appDataObjects[$name . '/' . $key])) {
						throw new NotFoundException('no such object');
					}
					$file = $this->createMock(ISimpleFile::class);
					$file->method('delete')->willReturnCallback(function () use ($name, $key): void {
						unset($this->appDataObjects[$name . '/' . $key]);
					});
					return $file;
				},
			);
			$folders[$name] = $folder;
			return $folder;
		};
		$this->appData->method('getFolder')->willReturnCallback($makeFolder);
		$this->appData->method('newFolder')->willReturnCallback($makeFolder);
	}

	/**
	 * Writes a v3 export ARCHIVE - `board.json` plus one entry per attachment
	 * path - and returns its path.
	 *
	 * @param array<string, mixed> $document
	 * @param array<string, string> $attachments archive path → bytes
	 */
	private function makeArchive(array $document, array $attachments = []): string {
		$path = tempnam(sys_get_temp_dir(), 'kanso-import-archive-') . '.zip';
		$this->tempPaths[] = $path;
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
		$zip->addFromString('board.json', (string)json_encode($document));
		foreach ($attachments as $entry => $bytes) {
			$zip->addFromString($entry, $bytes);
		}
		self::assertTrue($zip->close());
		return $path;
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

	public function testStillImportsAV2DocumentAfterTheV3ArchiveBump(): void {
		// #10060 raised FORMAT_VERSION to 3 and moved the delivered artifact from
		// a bare .json document to a .zip. Every export anyone has already
		// downloaded is a v1/v2 JSON document, and it must keep importing
		// unchanged: the gate rejects only documents from a NEWER Kanso.
		self::assertGreaterThan(2, ExportService::FORMAT_VERSION, 'this test is about importing an OLDER document');

		$this->primeDb();
		$this->db->expects(self::once())->method('commit');
		$this->boardService->expects(self::once())
			->method('create')->with('Legacy board', '0082c9', 'importer')
			->willReturn($this->newBoard('Legacy board'));

		$doc = [
			'kanso' => 2,
			'exportedAt' => 1234,
			'board' => [
				'title' => 'Legacy board',
				'color' => '0082c9',
				'stacks' => [['id' => 1, 'title' => 'Todo', 'sortKey' => 'a', 'role' => 0, 'wipLimit' => null]],
				'cards' => [],
			],
		];

		$result = $this->service->import((string)json_encode($doc), 'importer');

		self::assertSame(900, $result['boardId']);
		self::assertSame('Legacy board', $result['title']);
		self::assertSame(1, $result['stacks']);
		self::assertSame(0, $result['cards']);
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

	// ── attachments ride the archive back in (#10071) ─────────────────────────

	/** Wires the card/board mappers a v3 archive import needs. */
	private function primeArchiveImport(): void {
		$this->boardService->method('create')->willReturn($this->newBoard('Restored'));
		$this->stackMapper->method('insert')->willReturnCallback($this->autoId('stack', 30));
		$this->cardMapper->method('nextBoardSeq')->willReturn(1);
		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c): Card {
			$this->seq['card'] ??= 100;
			$c->setId($this->seq['card']++);
			$this->cardsById[$c->getId()] = $c;
			return $c;
		});
		$this->userManager->method('userExists')->willReturn(true);
	}

	/**
	 * A two-card, three-attachment v3 document plus the archive carrying its
	 * bytes.
	 *
	 * @return array{0: string, 1: array<string, string>} [archive path, path → bytes]
	 */
	private function twoCardArchive(): array {
		$bytes = [
			'attachments/1/notes.txt' => 'the first attached bytes',
			'attachments/2/report.txt' => str_repeat('report ', 100),
			'attachments/3/second-card.txt' => 'bytes belonging to the other card',
		];
		$document = [
			'kanso' => 3,
			'board' => [
				'title' => 'Restored',
				'stacks' => [['id' => 1, 'title' => 'Todo', 'sortKey' => 'a']],
				'cards' => [
					[
						'id' => 500, 'stackId' => 1, 'title' => 'Alpha', 'sortKey' => 'h',
						'attachments' => [
							['id' => 1, 'filename' => 'notes.txt', 'mime' => 'text/plain', 'size' => 24, 'uploadedBy' => 'alice', 'createdAt' => 1700000000, 'path' => 'attachments/1/notes.txt'],
							['id' => 2, 'filename' => 'report.txt', 'mime' => 'text/plain', 'size' => 700, 'uploadedBy' => 'alice', 'createdAt' => 1700000001, 'path' => 'attachments/2/report.txt'],
						],
					],
					[
						'id' => 501, 'stackId' => 1, 'title' => 'Beta', 'sortKey' => 'i',
						'attachments' => [
							['id' => 3, 'filename' => 'second-card.txt', 'mime' => 'text/plain', 'size' => 33, 'uploadedBy' => 'alice', 'createdAt' => 1700000002, 'path' => 'attachments/3/second-card.txt'],
						],
					],
				],
			],
		];
		return [$this->makeArchive($document, $bytes), $bytes];
	}

	public function testImportsAttachmentBytesOntoTheRightCards(): void {
		$this->primeDb();
		$this->db->expects(self::once())->method('commit');
		$this->primeArchiveImport();
		/** @var list<CardAttachment> $rows */
		$rows = [];
		$this->cardAttachmentMapper->method('insert')->willReturnCallback(
			function (CardAttachment $a) use (&$rows): CardAttachment {
				$a->setId(700 + count($rows));
				$rows[] = $a;
				return $a;
			},
		);

		[$archivePath, $bytes] = $this->twoCardArchive();
		$result = $this->service->importArchive($archivePath, 'importer');

		self::assertSame(2, $result['cards']);
		self::assertCount(3, $rows, 'every manifested attachment produced a row');

		// Card association: the first two rows hang off the first new card, the
		// third off the second - the manifest keyed each entry to its card.
		self::assertSame(100, $rows[0]->getCardId());
		self::assertSame(100, $rows[1]->getCardId());
		self::assertSame(101, $rows[2]->getCardId());
		// And every row belongs to the freshly created board, never a foreign one.
		foreach ($rows as $row) {
			self::assertSame(900, $row->getBoardId());
		}

		// Filenames and byte-for-byte contents survived.
		self::assertSame(['notes.txt', 'report.txt', 'second-card.txt'], array_map(
			static fn (CardAttachment $a): string => (string)$a->getFilename(),
			$rows,
		));
		$expected = array_values($bytes);
		foreach ($rows as $i => $row) {
			$stored = $this->appDataObjects['card-' . $row->getCardId() . '/' . $row->getStorageKey()] ?? null;
			self::assertNotNull($stored, 'the object was written to app-data');
			self::assertSame($expected[$i], $stored, 'bytes round-tripped unchanged');
			self::assertSame(hash('sha256', $expected[$i]), hash('sha256', $stored));
			// The recorded size is what was actually written, not what the
			// manifest claimed.
			self::assertSame(strlen($expected[$i]), $row->getSize());
		}
	}

	public function testTheManifestPathNeverBecomesTheStoragePath(): void {
		// The manifest advertises `attachments/<id>/<filename>`; the object must
		// land under card-<new id>/<server-generated key> and nothing else. This
		// is what makes zip-slip structurally impossible rather than filtered.
		$this->primeDb();
		$this->primeArchiveImport();
		/** @var list<CardAttachment> $rows */
		$rows = [];
		$this->cardAttachmentMapper->method('insert')->willReturnCallback(
			function (CardAttachment $a) use (&$rows): CardAttachment {
				$rows[] = $a;
				return $a;
			},
		);

		[$archivePath] = $this->twoCardArchive();
		$this->service->importArchive($archivePath, 'importer');

		foreach (array_keys($this->appDataObjects) as $objectPath) {
			self::assertMatchesRegularExpression('~^card-\d+/[a-z0-9]{32}$~', $objectPath);
		}
		foreach ($rows as $row) {
			// The storage key is the random one, never the manifest filename or
			// any part of the entry path.
			self::assertMatchesRegularExpression('~^[a-z0-9]{32}$~', (string)$row->getStorageKey());
			self::assertStringNotContainsString('attachments', (string)$row->getStorageKey());
			self::assertStringNotContainsString((string)$row->getFilename(), (string)$row->getStorageKey());
		}
	}

	public function testStoredMimeIsResniffedFromTheBytesNotTakenFromTheManifest(): void {
		// The manifest claims image/png; the bytes are HTML. The stored type must
		// come from the content sniff and, being scriptable, be coerced to the
		// inert generic type - the denylist is what keeps stored XSS out.
		$this->primeDb();
		$this->primeArchiveImport();
		$this->mimeTypeDetector = $this->createMock(IMimeTypeDetector::class);
		$this->mimeTypeDetector->method('detectContent')->willReturn('text/html');
		$this->mimeTypeDetector->method('detectPath')->willReturn('image/png');
		$this->rebuildServiceWithCurrentMimeDetector();
		$captured = null;
		$this->cardAttachmentMapper->method('insert')->willReturnCallback(
			function (CardAttachment $a) use (&$captured): CardAttachment {
				$captured = $a;
				return $a;
			},
		);

		$archivePath = $this->makeArchive([
			'kanso' => 3,
			'board' => [
				'title' => 'Restored',
				'stacks' => [['id' => 1, 'title' => 'Todo', 'sortKey' => 'a']],
				'cards' => [[
					'id' => 500, 'stackId' => 1, 'title' => 'Alpha', 'sortKey' => 'h',
					'attachments' => [[
						'id' => 1, 'filename' => 'innocent.png', 'mime' => 'image/png',
						'size' => 40, 'path' => 'attachments/1/innocent.png',
					]],
				]],
			],
		], ['attachments/1/innocent.png' => '<html><script>alert(1)</script></html>']);

		$this->service->importArchive($archivePath, 'importer');

		self::assertNotNull($captured);
		self::assertSame('application/octet-stream', $captured->getMime());
		self::assertNotSame('image/png', $captured->getMime());
	}

	public function testStoredMimeIsAlsoRefusedWhenTheDISPLAYNameIsScriptable(): void {
		// The mirror: harmless-looking bytes under an .html label. Either reading
		// tripping the denylist is enough to store the inert type.
		$this->primeDb();
		$this->primeArchiveImport();
		$this->mimeTypeDetector = $this->createMock(IMimeTypeDetector::class);
		$this->mimeTypeDetector->method('detectContent')->willReturn('image/png');
		$this->mimeTypeDetector->method('detectPath')->willReturn('text/html');
		$this->rebuildServiceWithCurrentMimeDetector();
		$captured = null;
		$this->cardAttachmentMapper->method('insert')->willReturnCallback(
			function (CardAttachment $a) use (&$captured): CardAttachment {
				$captured = $a;
				return $a;
			},
		);

		$archivePath = $this->makeArchive([
			'kanso' => 3,
			'board' => [
				'title' => 'Restored',
				'stacks' => [['id' => 1, 'title' => 'Todo', 'sortKey' => 'a']],
				'cards' => [[
					'id' => 500, 'stackId' => 1, 'title' => 'Alpha', 'sortKey' => 'h',
					'attachments' => [[
						'id' => 1, 'filename' => 'evil.html', 'mime' => 'image/png',
						'size' => 4, 'path' => 'attachments/1/evil.html',
					]],
				]],
			],
		], ['attachments/1/evil.html' => 'PNG!']);

		$this->service->importArchive($archivePath, 'importer');

		self::assertNotNull($captured);
		self::assertSame('application/octet-stream', $captured->getMime());
	}

	public function testCleansUpTheObjectsItWroteWhenTheImportFailsMidway(): void {
		// App-data writes are not covered by the DB transaction, so a failure
		// after the first attachment landed must not leave orphaned bytes that
		// no surviving row references.
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');
		$this->primeArchiveImport();

		$inserted = 0;
		$this->cardAttachmentMapper->method('insert')->willReturnCallback(
			function (CardAttachment $a) use (&$inserted): CardAttachment {
				$inserted++;
				// The first two land; the third blows up mid-import.
				if ($inserted === 3) {
					throw new \RuntimeException('boom');
				}
				return $a;
			},
		);

		[$archivePath] = $this->twoCardArchive();
		try {
			$this->service->importArchive($archivePath, 'importer');
			self::fail('the import was expected to fail');
		} catch (\RuntimeException $e) {
			self::assertSame('boom', $e->getMessage());
		}

		// Three objects were written (the third's row never landed); all three
		// must be gone again.
		self::assertSame([], $this->appDataObjects, 'no orphaned objects survive a failed import');
	}

	public function testAManifestEntryWithNoBytesInTheArchiveIsSkippedNotFatal(): void {
		$this->primeDb();
		$this->db->expects(self::once())->method('commit');
		$this->primeArchiveImport();
		$rows = 0;
		$this->cardAttachmentMapper->method('insert')->willReturnCallback(
			function (CardAttachment $a) use (&$rows): CardAttachment {
				$rows++;
				return $a;
			},
		);

		$archivePath = $this->makeArchive([
			'kanso' => 3,
			'board' => [
				'title' => 'Restored',
				'stacks' => [['id' => 1, 'title' => 'Todo', 'sortKey' => 'a']],
				'cards' => [[
					'id' => 500, 'stackId' => 1, 'title' => 'Alpha', 'sortKey' => 'h',
					'attachments' => [[
						'id' => 9, 'filename' => 'gone.txt', 'mime' => 'text/plain',
						'size' => 4, 'path' => 'attachments/9/gone.txt',
					]],
				]],
			],
		]);

		$result = $this->service->importArchive($archivePath, 'importer');

		self::assertSame(1, $result['cards'], 'the board still imported');
		self::assertSame(0, $rows, 'the vanished attachment produced no row');
	}

	public function testImportsAV3ArchiveThatCarriesNoAttachments(): void {
		$this->primeDb();
		$this->db->expects(self::once())->method('commit');
		$this->primeArchiveImport();
		$this->cardAttachmentMapper->expects(self::never())->method('insert');

		$archivePath = $this->makeArchive([
			'kanso' => 3,
			'board' => [
				'title' => 'Restored',
				'stacks' => [['id' => 1, 'title' => 'Todo', 'sortKey' => 'a']],
				'cards' => [['id' => 500, 'stackId' => 1, 'title' => 'Alpha', 'sortKey' => 'h', 'attachments' => []]],
			],
		]);

		$result = $this->service->importArchive($archivePath, 'importer');

		self::assertSame(1, $result['cards']);
		self::assertSame([], $this->appDataObjects);
	}

	// ── the upload entry point picks the shape from the BYTES ─────────────────

	public function testUploadedArchiveIsImportedAsAnArchive(): void {
		$this->primeDb();
		$this->db->expects(self::once())->method('commit');
		$this->primeArchiveImport();
		$rows = 0;
		$this->cardAttachmentMapper->method('insert')->willReturnCallback(
			function (CardAttachment $a) use (&$rows): CardAttachment {
				$rows++;
				return $a;
			},
		);

		[$archivePath] = $this->twoCardArchive();
		// A client claiming ".json" / "application/json" changes nothing: the
		// leading bytes decide.
		$result = $this->service->importUploadedFile([
			'name' => 'export.json',
			'type' => 'application/json',
			'size' => (int)filesize($archivePath),
			'tmp_name' => $archivePath,
			'error' => UPLOAD_ERR_OK,
		], 'importer');

		self::assertSame(2, $result['cards']);
		self::assertSame(3, $rows);
	}

	public function testUploadedBareV2DocumentStillImports(): void {
		// Back-compat through the NEW entry point: every .json export already in
		// someone's downloads folder must keep working.
		$this->primeDb();
		$this->db->expects(self::once())->method('commit');
		$this->boardService->expects(self::once())
			->method('create')->with('Legacy board', '0082c9', 'importer')
			->willReturn($this->newBoard('Legacy board'));
		$this->stackMapper->method('insert')->willReturnCallback($this->autoId('stack', 30));
		$this->cardAttachmentMapper->expects(self::never())->method('insert');

		$path = tempnam(sys_get_temp_dir(), 'kanso-import-v2-');
		self::assertIsString($path);
		$this->tempPaths[] = $path;
		file_put_contents($path, (string)json_encode([
			'kanso' => 2,
			'board' => [
				'title' => 'Legacy board',
				'color' => '0082c9',
				'stacks' => [['id' => 1, 'title' => 'Todo', 'sortKey' => 'a']],
				'cards' => [],
			],
		]));

		$result = $this->service->importUploadedFile([
			'name' => 'kanso-legacy.json',
			'size' => (int)filesize($path),
			'tmp_name' => $path,
			'error' => UPLOAD_ERR_OK,
		], 'importer');

		self::assertSame('Legacy board', $result['title']);
		self::assertSame(1, $result['stacks']);
	}

	public function testUploadedFileOverTheArchiveCeilingIsRefused(): void {
		// The size ceiling: nothing is opened, parsed or written for an upload
		// bigger than one import is ever allowed to be. A sparse file stands in
		// for the 256 MiB so the test stays cheap; it carries the zip magic so
		// it takes the ARCHIVE branch and the ceiling is the only thing between
		// it and ZipArchive.
		$path = tempnam(sys_get_temp_dir(), 'kanso-import-huge-');
		self::assertIsString($path);
		$this->tempPaths[] = $path;
		$handle = fopen($path, 'wb');
		self::assertIsResource($handle);
		fwrite($handle, "PK\x03\x04");
		fseek($handle, ImportArchiveReader::MAX_TOTAL_BYTES);
		fwrite($handle, 'x');
		fclose($handle);
		$this->db->expects(self::never())->method('beginTransaction');

		// The message pins WHICH refusal fired: without the ceiling the file
		// would be opened and refused later for being an unreadable archive.
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The export file is too large to import');
		$this->service->importUploadedFile([
			'name' => 'huge.zip',
			'size' => 1,
			'tmp_name' => $path,
			'error' => UPLOAD_ERR_OK,
		], 'importer');
	}

	public function testUploadedBareDocumentOverTheDocumentCapIsRefused(): void {
		// A NON-archive upload answers to the (much smaller) document cap - it is
		// decoded whole, so it may not be archive-sized.
		$path = tempnam(sys_get_temp_dir(), 'kanso-import-bigdoc-');
		self::assertIsString($path);
		$this->tempPaths[] = $path;
		$handle = fopen($path, 'wb');
		self::assertIsResource($handle);
		fwrite($handle, '{');
		fseek($handle, ImportService::MAX_DOCUMENT_BYTES);
		fwrite($handle, '}');
		fclose($handle);
		$this->db->expects(self::never())->method('beginTransaction');

		// The message pins the SIZE refusal: without the cap the oversized blob
		// would be read and merely fail to parse, which is a different bug.
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The export file is too large to import');
		$this->service->importUploadedFile([
			'name' => 'huge.json',
			'size' => 1,
			'tmp_name' => $path,
			'error' => UPLOAD_ERR_OK,
		], 'importer');
	}

	public function testMissingOrForgedUploadIsRefused(): void {
		$this->db->expects(self::never())->method('beginTransaction');
		try {
			$this->service->importUploadedFile(null, 'importer');
			self::fail('a missing upload must be refused');
		} catch (InvalidInputException) {
			// expected
		}
		// A forged tmp_name pointing at a server path we never received. The
		// message pins that it was refused as "no upload", not stumbled over
		// later while trying to read it.
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('No export file uploaded');
		$this->service->importUploadedFile([
			'name' => 'export.zip',
			'size' => 10,
			'tmp_name' => '/etc/passwd-not-here',
			'error' => UPLOAD_ERR_OK,
		], 'importer');
	}

	/**
	 * Rebuilds the service so a test-specific {@see IMimeTypeDetector} takes
	 * effect (the collaborator is constructor-injected).
	 */
	private function rebuildServiceWithCurrentMimeDetector(): void {
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
			$this->cardAttachmentMapper,
			$this->userManager,
			$this->db,
			$this->boardAccess,
			$this->realRecurrenceService(),
			$this->appData,
			$this->secureRandom,
			$this->tempManager,
			$this->mimeTypeDetector,
			$this->logger,
		);
	}
}

/** The first version number this build must refuse. */
function ExportService_futureVersion(): int {
	return \OCA\Kanso\Service\ExportService::FORMAT_VERSION + 1;
}
