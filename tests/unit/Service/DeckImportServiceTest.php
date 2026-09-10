<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardAttachment;
use OCA\Kanso\Db\CardAttachmentMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Comment;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\DeckImport;
use OCA\Kanso\Db\DeckImportMapper;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\AlreadyImportedException;
use OCA\Kanso\Service\AttachmentSanitizer;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\DeckImportService;
use OCA\Kanso\Service\DeckReader;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\SortKeyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DeckImportServiceTest extends TestCase {
	private DeckReader&MockObject $deckReader;
	private BoardService&MockObject $boardService;
	private StackMapper&MockObject $stackMapper;
	private CardMapper&MockObject $cardMapper;
	private LabelMapper&MockObject $labelMapper;
	private CardLabelMapper&MockObject $cardLabelMapper;
	private CardAssigneeMapper&MockObject $cardAssigneeMapper;
	private CommentMapper&MockObject $commentMapper;
	private CardAttachmentMapper&MockObject $cardAttachmentMapper;
	private DeckImportMapper&MockObject $deckImportMapper;
	/** Every write the stubbed import makes, in order - see stubImportableBoard(). */
	private array $writeOrder = [];
	private IUserManager&MockObject $userManager;
	private \OCP\IDBConnection&MockObject $db;
	private IAppData&MockObject $appData;
	private IAppDataFactory&MockObject $appDataFactory;
	private ISecureRandom&MockObject $secureRandom;
	private IRootFolder&MockObject $rootFolder;
	private LoggerInterface&MockObject $logger;
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
		$this->commentMapper = $this->createMock(CommentMapper::class);
		$this->cardAttachmentMapper = $this->createMock(CardAttachmentMapper::class);
		$this->deckImportMapper = $this->createMock(DeckImportMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->db = $this->createMock(\OCP\IDBConnection::class);
		$this->appData = $this->createMock(IAppData::class);
		$this->appDataFactory = $this->createMock(IAppDataFactory::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new DeckImportService(
			$this->deckReader,
			$this->boardService,
			$this->stackMapper,
			$this->cardMapper,
			$this->labelMapper,
			$this->cardLabelMapper,
			$this->cardAssigneeMapper,
			$this->commentMapper,
			$this->cardAttachmentMapper,
			$this->deckImportMapper,
			new SortKeyService(),
			$this->userManager,
			$this->db,
			$this->appData,
			$this->appDataFactory,
			$this->secureRandom,
			$this->rootFolder,
			$this->logger,
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
		// The permission check comes FIRST: a user who cannot read the Deck board
		// never reaches the import ledger, so the "already imported" answer can
		// never tell a stranger that somebody else imported a board (#10300).
		$this->deckImportMapper->expects(self::never())->method('findForUser');
		$this->deckImportMapper->expects(self::never())->method('record');
		$this->boardService->expects(self::never())->method('create');
		$this->expectException(NotPermittedException::class);
		$this->service->importBoard(2, 'bob');
	}

	/**
	 * Same denial, but with a prior import on record for that user: the answer
	 * must still be "access denied", never "already imported".
	 */
	public function testImportWithoutAccessIsDeniedEvenWithAPriorImport(): void {
		$this->deckReader->method('isAvailable')->willReturn(true);
		$this->deckReader->method('userCanReadBoard')->with('bob', 2)->willReturn(false);
		$this->deckImportMapper->method('findForUser')->willReturn($this->recordedImport(2, 100, 'bob'));
		$this->expectException(NotPermittedException::class);
		$this->service->importBoard(2, 'bob');
	}

	// ---- re-import guard (#10300) -----------------------------------------

	/** A DB failure carrying the given `OCP\DB\Exception::REASON_*`. */
	private function dbFailure(int $reason): \OCP\DB\Exception&MockObject {
		$e = $this->createMock(\OCP\DB\Exception::class);
		$e->method('getReason')->willReturn($reason);
		return $e;
	}

	/** An existing `kanso_deck_imports` row for the given pair. */
	private function recordedImport(int $deckBoardId, int $kansoBoardId, string $uid, int $at = 1700000000): DeckImport {
		$row = new DeckImport();
		$row->setId(7);
		$row->setDeckBoardId($deckBoardId);
		$row->setKansoBoardId($kansoBoardId);
		$row->setImportedBy($uid);
		$row->setImportedAt($at);
		return $row;
	}

	/**
	 * Stubs a readable, importable one-stack/one-card Deck board so a test can
	 * concentrate on the guard rather than the mapping.
	 *
	 * Every write the import makes appends to {@see self::$writeOrder}, so a test
	 * can assert not just THAT the ledger row was claimed but that it was claimed
	 * FIRST - the property the whole guard rests on.
	 */
	private function stubImportableBoard(): void {
		$this->deckReader->method('isAvailable')->willReturn(true);
		$this->deckReader->method('userCanReadBoard')->willReturn(true);
		$this->deckReader->method('readBoard')->with(2)
			->willReturn(['id' => 2, 'title' => 'Migrate me', 'color' => null, 'owner' => 'carol']);

		$board = new Board();
		$board->setId(100);
		$board->setTitle('Migrate me');
		$this->boardService->method('create')->willReturn($board);

		$this->deckReader->method('readLabels')->willReturn([]);
		$this->deckReader->method('readStacks')->willReturn([['id' => 11, 'title' => 'To do']]);
		$this->deckReader->method('readCards')->willReturn([
			['id' => 21, 'title' => 'First', 'description' => '', 'archived' => false, 'duedate' => null, 'doneAt' => 0, 'createdAt' => 0],
		]);
		$this->deckReader->method('readAssignedLabels')->willReturn([]);
		$this->deckReader->method('readAssignedUsers')->willReturn([]);
		$this->deckReader->method('readComments')->willReturn([]);
		$this->deckReader->method('readAttachments')->willReturn([]);
		$this->deckReader->method('readFileReferenceAttachments')->willReturn([]);

		// Registered FIRST, so these supply the return values; a test's own
		// expects()->with(...) on the same method still verifies its arguments.
		$this->deckImportMapper->method('forget')->willReturnCallback(function (): int {
			$this->writeOrder[] = 'forget';
			return 1;
		});
		$this->deckImportMapper->method('record')->willReturnCallback(function (): DeckImport {
			$this->writeOrder[] = 'record';
			return $this->recordedImport(2, 100, 'alice');
		});
		$this->stackMapper->method('insert')->willReturnCallback(function (Stack $s): Stack {
			$this->writeOrder[] = 'stack';
			$s->setId(1);
			return $s;
		});
		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c): Card {
			$this->writeOrder[] = 'card';
			$c->setId(500);
			return $c;
		});
	}

	/**
	 * The happy path records the (Deck board, user) pair INSIDE the import
	 * transaction, so a later attempt has something to find. This is the row the
	 * whole guard rests on - without it a re-run duplicates the board and every
	 * attachment's bytes.
	 */
	public function testFirstImportRecordsTheMapping(): void {
		$this->stubImportableBoard();
		$this->deckImportMapper->method('findForUser')->with(2, 'alice')->willReturn(null);
		// Recorded against the NEW board id, for the importing user, and never as
		// a re-import (nothing to forget on a first run).
		$this->deckImportMapper->expects(self::never())->method('forget');
		$this->deckImportMapper->expects(self::once())->method('record')
			->with(2, 100, 'alice', self::isType('int'));

		$result = $this->service->importBoard(2, 'alice');
		self::assertSame(100, $result['boardId']);
		// ORDER is the guarantee, not merely the call: the claim has to land
		// before the import writes anything, or a losing double-submit would have
		// copied stacks, cards and attachment bytes before finding out it lost.
		self::assertSame(['record', 'stack', 'card'], $this->writeOrder);
	}

	/**
	 * The defect this card fixes: running the same import twice used to produce a
	 * second complete board plus a second physical copy of every attachment. Now
	 * the second run is refused BEFORE anything is created - no board, no cards,
	 * no attachment bytes.
	 */
	public function testSecondImportOfTheSameBoardIsRefusedWithoutConfirmation(): void {
		$this->stubImportableBoard();
		$this->deckImportMapper->method('findForUser')->with(2, 'alice')
			->willReturn($this->recordedImport(2, 100, 'alice'));

		// Nothing is written: not the board, not the cards, not the ledger row.
		$this->boardService->expects(self::never())->method('create');
		$this->deckImportMapper->expects(self::never())->method('record');
		// Refused before the transaction even opens.
		$this->db->expects(self::never())->method('beginTransaction');

		$this->expectException(AlreadyImportedException::class);
		try {
			$this->service->importBoard(2, 'alice');
		} finally {
			self::assertSame([], $this->writeOrder, 'a refused repeat writes nothing at all');
		}
	}

	/**
	 * Cleaning up a bad first attempt and importing again is a real use case, so
	 * an EXPLICIT confirmation is allowed through - it replaces the recorded
	 * mapping rather than adding a second one.
	 */
	public function testConfirmedReimportIsAllowedAndReplacesTheMapping(): void {
		$this->stubImportableBoard();
		// The confirmed path does not need the pre-check at all.
		$this->deckImportMapper->expects(self::never())->method('findForUser');
		$this->deckImportMapper->expects(self::once())->method('forget')->with(2, 'alice');
		$this->deckImportMapper->expects(self::once())->method('record')
			->with(2, 100, 'alice', self::isType('int'));

		$result = $this->service->importBoard(2, 'alice', true);
		self::assertSame(100, $result['boardId']);
		// The release must precede the re-claim (and both must precede the
		// content), or the re-claim would collide with the row it is replacing.
		self::assertSame(['forget', 'record', 'stack', 'card'], $this->writeOrder);
	}

	/**
	 * The pre-check cannot see a request that is still in flight, so the unique
	 * index on (deck_board_id, imported_by) is the real guard for the classic
	 * double-submit. The loser's whole transaction rolls back - no duplicate
	 * board - and the answer is still the clean "already imported", not a 500.
	 */
	public function testConcurrentDoubleSubmitLosesOnTheUniqueIndexAndRollsBack(): void {
		$this->stubImportableBoard();
		// Pre-check sees nothing (the winner had not committed yet); the ledger
		// read in the failure path then finds the winner's row.
		$this->deckImportMapper->method('findForUser')
			->willReturnOnConsecutiveCalls(null, $this->recordedImport(2, 100, 'alice'));
		$this->deckImportMapper->method('record')->willThrowException($this->dbFailure(
			\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION
		));

		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');

		$this->expectException(AlreadyImportedException::class);
		try {
			$this->service->importBoard(2, 'alice');
		} finally {
			// The claim is the transaction's FIRST write and it is where the
			// losing submit dies, so it never reaches a stack, a card, or an
			// attachment's bytes.
			self::assertSame(['record'], $this->writeOrder);
		}
	}

	/**
	 * The loser does not always come back with a unique-constraint violation: it
	 * blocks on the index for as long as the winner's import runs - minutes, for
	 * a board full of attachments - so MySQL is just as likely to answer with a
	 * lock-wait timeout. That must still read as "already imported", not as a
	 * 500, because this IS the double-submit the guard exists for.
	 */
	public function testLockTimeoutOnTheClaimStillReadsAsAlreadyImported(): void {
		$this->stubImportableBoard();
		$this->deckImportMapper->method('findForUser')
			->willReturnOnConsecutiveCalls(null, $this->recordedImport(2, 100, 'alice'));
		$this->deckImportMapper->method('record')->willThrowException($this->dbFailure(
			\OCP\DB\Exception::REASON_LOCK_WAIT_TIMEOUT
		));

		$this->expectException(AlreadyImportedException::class);
		$this->service->importBoard(2, 'alice');
	}

	/**
	 * ...but a claim that failed for an unrelated reason, with no winning record
	 * to point at, must surface as its own error. Dressing a broken database up
	 * as "already imported" would tell the user their import landed when it did
	 * not - the exact confusion this card is about.
	 */
	public function testAFailedClaimWithNoWinnerKeepsItsOriginalError(): void {
		$this->stubImportableBoard();
		// Nothing in the ledger before or after: not a race.
		$this->deckImportMapper->method('findForUser')->willReturn(null);
		$broken = $this->dbFailure(\OCP\DB\Exception::REASON_CONNECTION_LOST);
		$this->deckImportMapper->method('record')->willThrowException($broken);

		$this->db->expects(self::once())->method('rollBack');
		$this->expectException(\OCP\DB\Exception::class);
		$this->service->importBoard(2, 'alice');
	}

	/**
	 * A CONFIRMED re-import that loses a race must not be told to confirm - it
	 * just did. It keeps the underlying error instead of a nonsensical prompt.
	 */
	public function testConfirmedReimportIsNeverToldToConfirmAgain(): void {
		$this->stubImportableBoard();
		$this->deckImportMapper->method('findForUser')->willReturn($this->recordedImport(2, 100, 'alice'));
		$this->deckImportMapper->method('record')->willThrowException($this->dbFailure(
			\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION
		));

		$this->expectException(\OCP\DB\Exception::class);
		$this->service->importBoard(2, 'alice', true);
	}

	/**
	 * The key is (Deck board, importing USER). A board Alice imported must stay
	 * importable for Bob - refusing it would silently break a shared board
	 * mid-migration, which is worse than the duplicate this card prevents.
	 */
	public function testAnotherUsersImportDoesNotBlockThisUser(): void {
		$this->stubImportableBoard();
		// Scoped lookup: asked for BOB, and Bob has imported nothing.
		$this->deckImportMapper->expects(self::once())->method('findForUser')
			->with(2, 'bob')->willReturn(null);
		$this->deckImportMapper->expects(self::once())->method('record')
			->with(2, 100, 'bob', self::isType('int'));

		$result = $this->service->importBoard(2, 'bob');
		self::assertSame(100, $result['boardId']);
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
		$this->deckReader->method('readComments')->willReturn([]);
		$this->deckReader->method('readAttachments')->willReturn([]);
		$this->deckReader->method('readFileReferenceAttachments')->willReturn([]);

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

		self::assertSame([
			'boardId' => 100,
			'title' => 'Migrate me',
			'stacks' => 2,
			'cards' => 2,
			'labels' => 1,
			'comments' => 0,
			'attachments' => 0,
			'skippedAttachments' => 0,
		], $result);

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
		// ...and every key stays within the varchar(64) sort_key column - the
		// invariant that a large import must not break (#rebalance_required).
		foreach ($capturedCards as $c) {
			self::assertLessThanOrEqual(SortKeyService::MAX_KEY_LENGTH, strlen($c->getSortKey()));
		}
	}

	// ---- long / edge-case titles (#3906) ----------------------------------

	/**
	 * Stubs a readable Deck board with a single stack whose only card carries
	 * the given title + description, and captures every inserted card. Returns
	 * the captured Card so a test can assert on the stored title/description.
	 */
	private function importSingleCard(string $cardTitle, string $cardDescription = ''): Card {
		$this->deckReader->method('isAvailable')->willReturn(true);
		$this->deckReader->method('userCanReadBoard')->willReturn(true);
		$this->deckReader->method('readBoard')->with(2)
			->willReturn(['id' => 2, 'title' => 'B', 'color' => null, 'owner' => 'carol']);
		$board = new Board();
		$board->setId(100);
		$board->setTitle('B');
		$this->boardService->method('create')->willReturn($board);

		$this->deckReader->method('readLabels')->willReturn([]);
		$this->deckReader->method('readStacks')->willReturn([['id' => 11, 'title' => 'To do']]);
		$this->deckReader->method('readCards')->willReturn([
			['id' => 21, 'title' => $cardTitle, 'description' => $cardDescription, 'archived' => false, 'duedate' => null, 'doneAt' => 0, 'createdAt' => 0],
		]);
		$this->deckReader->method('readAssignedLabels')->willReturn([]);
		$this->deckReader->method('readAssignedUsers')->willReturn([]);
		$this->deckReader->method('readComments')->willReturn([]);
		$this->deckReader->method('readAttachments')->willReturn([]);
		$this->deckReader->method('readFileReferenceAttachments')->willReturn([]);

		$this->stackMapper->method('insert')->willReturnCallback($this->autoId());

		$captured = null;
		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c) use (&$captured): Card {
			$c->setId(500);
			$captured = $c;
			return $c;
		});

		$this->service->importBoard(2, 'alice');
		self::assertNotNull($captured);
		return $captured;
	}

	public function testImportTruncatesLongCardTitleAndPreservesFullInDescription(): void {
		$longTitle = str_repeat('a', 101);
		$card = $this->importSingleCard($longTitle, str_repeat('d', 5000));

		// Stored title fits the STRING(100) column.
		self::assertSame(100, mb_strlen($card->getTitle()));
		self::assertSame(str_repeat('a', 100), $card->getTitle());
		// The full original title is preserved into the (unbounded) description.
		self::assertStringStartsWith('Full title: ' . $longTitle . "\n\n", $card->getDescription());
		self::assertStringContainsString(str_repeat('d', 5000), $card->getDescription());
	}

	public function testImportOverLongTitleWithEmptyDescriptionStillPreservesFullTitle(): void {
		$longTitle = str_repeat('x', 250);
		$card = $this->importSingleCard($longTitle, '');

		self::assertSame(100, mb_strlen($card->getTitle()));
		// No trailing blank lines when the original description was empty.
		self::assertSame('Full title: ' . $longTitle, $card->getDescription());
	}

	public function testImportKeepsADescriptionLongerThanTheEditorCap(): void {
		// CardService::MAX_DESCRIPTION_LENGTH caps what a user can TYPE into a card
		// (enforced in CardService::update()). The importer must stay uncapped, or
		// migrating a board whose cards hold long descriptions would silently lose
		// or reject content. Pinned so the cap cannot later be pushed down into a
		// shared writer.
		$huge = str_repeat('d', CardService::MAX_DESCRIPTION_LENGTH + 1000);
		$card = $this->importSingleCard('Imported spec', $huge);

		self::assertSame($huge, $card->getDescription());
	}

	public function testImportEmptyCardTitleBecomesPlaceholder(): void {
		$card = $this->importSingleCard('   ', 'body');

		self::assertSame('Untitled', $card->getTitle());
		// A short title is not over-length, so the description is untouched.
		self::assertSame('body', $card->getDescription());
	}

	public function testImportTruncatesUnicodeTitleOnCharBoundary(): void {
		// 101 emoji: each is a single "character" to mb_strlen but multiple bytes.
		// Truncation must cut on a char boundary - never mid-sequence (no mojibake).
		$emoji = '😀';
		$longTitle = str_repeat($emoji, 101);
		$card = $this->importSingleCard($longTitle);

		self::assertSame(100, mb_strlen($card->getTitle()));
		self::assertSame(str_repeat($emoji, 100), $card->getTitle());
		// Round-trips cleanly as UTF-8 (a byte-split emoji would fail this).
		self::assertSame($card->getTitle(), mb_convert_encoding($card->getTitle(), 'UTF-8', 'UTF-8'));
		self::assertStringContainsString('Full title: ' . $longTitle, $card->getDescription());
	}

	public function testImportLongUnicodeDescriptionImportsUnchanged(): void {
		// A short title (not over-length) with a very long unicode/emoji/markdown
		// description imports verbatim - TEXT is unbounded, description untouched.
		$description = str_repeat('héllo 😀 **bold** ', 1000);
		$card = $this->importSingleCard('Short', $description);

		self::assertSame('Short', $card->getTitle());
		self::assertSame($description, $card->getDescription());
	}

	public function testImportTruncatesLongStackTitle(): void {
		$this->deckReader->method('isAvailable')->willReturn(true);
		$this->deckReader->method('userCanReadBoard')->willReturn(true);
		$this->deckReader->method('readBoard')->with(2)
			->willReturn(['id' => 2, 'title' => 'B', 'color' => null, 'owner' => 'carol']);
		$board = new Board();
		$board->setId(100);
		$board->setTitle('B');
		$this->boardService->method('create')->willReturn($board);

		$this->deckReader->method('readLabels')->willReturn([]);
		$this->deckReader->method('readStacks')->willReturn([['id' => 11, 'title' => str_repeat('s', 150)]]);
		$this->deckReader->method('readCards')->willReturn([]);
		$this->deckReader->method('readAssignedLabels')->willReturn([]);
		$this->deckReader->method('readAssignedUsers')->willReturn([]);
		$this->deckReader->method('readComments')->willReturn([]);
		$this->deckReader->method('readAttachments')->willReturn([]);
		$this->deckReader->method('readFileReferenceAttachments')->willReturn([]);

		$captured = null;
		$this->stackMapper->method('insert')->willReturnCallback(function (Stack $s) use (&$captured): Stack {
			$s->setId(1);
			$captured = $s;
			return $s;
		});

		$this->service->importBoard(2, 'alice');

		self::assertNotNull($captured);
		self::assertSame(100, mb_strlen($captured->getTitle()));
	}

	public function testImportTruncatesLongLabelTitle(): void {
		$this->deckReader->method('isAvailable')->willReturn(true);
		$this->deckReader->method('userCanReadBoard')->willReturn(true);
		$this->deckReader->method('readBoard')->with(2)
			->willReturn(['id' => 2, 'title' => 'B', 'color' => null, 'owner' => 'carol']);
		$board = new Board();
		$board->setId(100);
		$board->setTitle('B');
		$this->boardService->method('create')->willReturn($board);

		$this->deckReader->method('readLabels')->willReturn([
			['id' => 6, 'title' => str_repeat('L', 200), 'color' => '31CC7C'],
		]);
		$this->deckReader->method('readStacks')->willReturn([]);
		$this->deckReader->method('readAssignedLabels')->willReturn([]);
		$this->deckReader->method('readAssignedUsers')->willReturn([]);
		$this->deckReader->method('readComments')->willReturn([]);
		$this->deckReader->method('readAttachments')->willReturn([]);
		$this->deckReader->method('readFileReferenceAttachments')->willReturn([]);

		$captured = null;
		$this->labelMapper->method('insert')->willReturnCallback(function (Label $l) use (&$captured): Label {
			$l->setId(1);
			$captured = $l;
			return $l;
		});

		$this->service->importBoard(2, 'alice');

		self::assertNotNull($captured);
		self::assertSame(100, mb_strlen($captured->getTitle()));
	}

	public function testImportTruncatesLongBoardTitle(): void {
		$this->deckReader->method('isAvailable')->willReturn(true);
		$this->deckReader->method('userCanReadBoard')->willReturn(true);
		$this->deckReader->method('readBoard')->with(2)
			->willReturn(['id' => 2, 'title' => str_repeat('B', 300), 'color' => null, 'owner' => 'carol']);

		$board = new Board();
		$board->setId(100);
		$board->setTitle('B');
		$capturedTitle = null;
		$this->boardService->method('create')
			->willReturnCallback(function (string $title) use (&$capturedTitle, $board): Board {
				$capturedTitle = $title;
				return $board;
			});

		$this->deckReader->method('readLabels')->willReturn([]);
		$this->deckReader->method('readStacks')->willReturn([]);
		$this->deckReader->method('readAssignedLabels')->willReturn([]);
		$this->deckReader->method('readAssignedUsers')->willReturn([]);
		$this->deckReader->method('readComments')->willReturn([]);
		$this->deckReader->method('readAttachments')->willReturn([]);
		$this->deckReader->method('readFileReferenceAttachments')->willReturn([]);

		$this->service->importBoard(2, 'alice');

		// The board title handed to BoardService::create() is pre-truncated to
		// its STRING(100) limit, so create()'s own validateTitle() never throws.
		self::assertNotNull($capturedTitle);
		self::assertSame(100, mb_strlen($capturedTitle));
	}

	public function testListImportableEmptyWhenDeckUnavailable(): void {
		$this->deckReader->method('isAvailable')->willReturn(false);
		self::assertSame([], $this->service->listImportableBoards('alice'));
	}

	// ---- large stacks do not overflow the sort key (#rebalance_required) ----

	/**
	 * A stack far larger than ~1150 cards used to abort the import: chaining
	 * after() once per card grew the fractional sort key past the varchar(64)
	 * column, surfacing as a spurious "rebalance_required" 409. The block is now
	 * laid out with a single bounded appendSequence, so a huge stack imports with
	 * short, strictly-increasing, source-ordered keys.
	 */
	public function testImportLargeStackDoesNotOverflowSortKey(): void {
		$this->deckReader->method('isAvailable')->willReturn(true);
		$this->deckReader->method('userCanReadBoard')->willReturn(true);
		$this->deckReader->method('readBoard')->with(2)
			->willReturn(['id' => 2, 'title' => 'B', 'color' => null, 'owner' => 'carol']);
		$board = new Board();
		$board->setId(100);
		$board->setTitle('B');
		$this->boardService->method('create')->willReturn($board);

		// Well past the old ~1153-card overflow threshold.
		$cardCount = 2000;
		$deckCards = [];
		for ($i = 0; $i < $cardCount; $i++) {
			$deckCards[] = [
				'id' => $i + 1,
				'title' => 'Card ' . $i,
				'description' => '',
				'archived' => false,
				'duedate' => null,
				'doneAt' => 0,
				'createdAt' => 0,
			];
		}

		$this->deckReader->method('readLabels')->willReturn([]);
		$this->deckReader->method('readStacks')->willReturn([['id' => 11, 'title' => 'To do']]);
		$this->deckReader->method('readCards')->willReturn($deckCards);
		$this->deckReader->method('readAssignedLabels')->willReturn([]);
		$this->deckReader->method('readAssignedUsers')->willReturn([]);
		$this->deckReader->method('readComments')->willReturn([]);
		$this->deckReader->method('readAttachments')->willReturn([]);
		$this->deckReader->method('readFileReferenceAttachments')->willReturn([]);

		$this->stackMapper->method('insert')->willReturnCallback($this->autoId());

		$sortKeys = [];
		$cardId = 500;
		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c) use (&$sortKeys, &$cardId): Card {
			$c->setId($cardId++);
			$sortKeys[] = $c->getSortKey();
			return $c;
		});

		$result = $this->service->importBoard(2, 'alice');

		self::assertSame($cardCount, $result['cards']);
		self::assertCount($cardCount, $sortKeys);
		// Every key stays well under the varchar(64) column and preserves order.
		foreach ($sortKeys as $key) {
			self::assertLessThanOrEqual(SortKeyService::MAX_KEY_LENGTH, strlen($key));
		}
		$sorted = $sortKeys;
		sort($sorted, SORT_STRING);
		self::assertSame($sorted, $sortKeys, 'imported sort keys must be strictly increasing in source order');
		self::assertSame(count(array_unique($sortKeys)), count($sortKeys), 'sort keys must be unique');
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
		$this->deckReader->method('readComments')->willReturn([]);
		$this->deckReader->method('readAttachments')->willReturn([]);
		$this->deckReader->method('readFileReferenceAttachments')->willReturn([]);
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

	// ---- comments + attachments (#3644) -----------------------------------

	/**
	 * Stubs a readable Deck board with a single stack holding one card (deck id
	 * 21 → kanso id 500), and empty labels/assignees, so a test can focus on the
	 * comment/attachment paths. Returns nothing; the card mapper assigns id 500.
	 */
	private function stubOneCardBoard(): void {
		$this->deckReader->method('isAvailable')->willReturn(true);
		$this->deckReader->method('userCanReadBoard')->willReturn(true);
		$this->deckReader->method('readBoard')->with(2)
			->willReturn(['id' => 2, 'title' => 'B', 'color' => null, 'owner' => 'carol']);
		$board = new Board();
		$board->setId(100);
		$board->setTitle('B');
		$this->boardService->method('create')->willReturn($board);

		$this->deckReader->method('readLabels')->willReturn([]);
		$this->deckReader->method('readStacks')->willReturn([['id' => 11, 'title' => 'To do']]);
		$this->deckReader->method('readCards')->willReturn([
			['id' => 21, 'title' => 'C', 'description' => '', 'archived' => false, 'duedate' => null, 'doneAt' => 0, 'createdAt' => 0],
		]);
		$this->deckReader->method('readAssignedLabels')->willReturn([]);
		$this->deckReader->method('readAssignedUsers')->willReturn([]);
		// NOTE: readAttachments + readFileReferenceAttachments are intentionally
		// NOT stubbed here - each attachment/comment test sets BOTH explicitly
		// (PHPUnit binds the first ->method() stub, so a default here could not be
		// overridden by a test).

		$this->stackMapper->method('insert')->willReturnCallback($this->autoId());
		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c): Card {
			$c->setId(500);
			return $c;
		});
		// A card lookup during attachment import resolves the board id.
		$this->cardMapper->method('find')->willReturnCallback(function (int $id): Card {
			$c = new Card();
			$c->setId($id);
			$c->setBoardId(100);
			return $c;
		});
	}

	public function testImportInsertsCommentsWithCardRemapAndCreatedAt(): void {
		$this->stubOneCardBoard();
		$this->deckReader->method('readAttachments')->willReturn([]);
		$this->deckReader->method('readFileReferenceAttachments')->willReturn([]);
		$this->deckReader->method('readComments')->willReturn([
			['id' => 71, 'cardId' => 21, 'author' => 'bob', 'message' => 'top', 'createdAt' => 111, 'parentId' => 0],
			['id' => 72, 'cardId' => 21, 'author' => 'bob', 'message' => 'reply', 'createdAt' => 222, 'parentId' => 71],
		]);
		$this->userManager->method('userExists')->willReturn(true);

		$captured = [];
		$next = 900;
		$this->commentMapper->method('insert')->willReturnCallback(function (Comment $c) use (&$captured, &$next): Comment {
			$c->setId($next++);
			$captured[] = $c;
			return $c;
		});

		$result = $this->service->importBoard(2, 'alice');

		self::assertSame(2, $result['comments']);
		self::assertCount(2, $captured);
		// Both remapped onto the new card id 500, created-at preserved.
		self::assertSame(500, $captured[0]->getCardId());
		self::assertSame('top', $captured[0]->getBody());
		self::assertSame(111, $captured[0]->getCreatedAt());
		self::assertSame('bob', $captured[0]->getAuthor());
		self::assertNull($captured[0]->getParentCommentId());
		// The reply's parent is remapped to the new id of the top-level comment.
		self::assertSame(222, $captured[1]->getCreatedAt());
		self::assertSame(900, $captured[1]->getParentCommentId());
	}

	public function testImportCommentAuthorMissingFallsBackToImporter(): void {
		$this->stubOneCardBoard();
		$this->deckReader->method('readAttachments')->willReturn([]);
		$this->deckReader->method('readFileReferenceAttachments')->willReturn([]);
		$this->deckReader->method('readComments')->willReturn([
			['id' => 71, 'cardId' => 21, 'author' => 'ghost', 'message' => 'hi', 'createdAt' => 111, 'parentId' => 0],
		]);
		$this->userManager->method('userExists')->willReturnCallback(fn (string $u): bool => $u !== 'ghost');

		$captured = [];
		$this->commentMapper->method('insert')->willReturnCallback(function (Comment $c) use (&$captured): Comment {
			$c->setId(900);
			$captured[] = $c;
			return $c;
		});

		$this->service->importBoard(2, 'alice');

		self::assertSame('alice', $captured[0]->getAuthor());
	}

	public function testImportCopiesDeckFileAttachment(): void {
		$this->stubOneCardBoard();
		$this->deckReader->method('readComments')->willReturn([]);
		$this->deckReader->method('readFileReferenceAttachments')->willReturn([]);
		$this->deckReader->method('readAttachments')->willReturn([
			['id' => 31, 'cardId' => 21, 'type' => 'deck_file', 'data' => 'report.pdf', 'createdBy' => 'bob', 'createdAt' => 333],
		]);
		$this->userManager->method('userExists')->willReturn(true);
		$this->secureRandom->method('generate')->willReturn('objkey123');

		// Deck app-data source: folder "21" → file "report.pdf" with bytes.
		$sourceFile = $this->createMock(ISimpleFile::class);
		$sourceFile->method('getContent')->willReturn('PDFBYTES');
		$sourceFile->method('getMimeType')->willReturn('application/pdf');
		$sourceFile->method('getSize')->willReturn(8);
		$deckFolder = $this->createMock(ISimpleFolder::class);
		$deckFolder->method('getFile')->with('report.pdf')->willReturn($sourceFile);
		$deckAppData = $this->createMock(IAppData::class);
		$deckAppData->method('getFolder')->with('file-card-21')->willReturn($deckFolder);
		$this->appDataFactory->method('get')->with('deck')->willReturn($deckAppData);

		// Kanso app-data write target: card-500 folder, server-generated object.
		$kansoFolder = $this->createMock(ISimpleFolder::class);
		$kansoFolder->expects(self::once())->method('newFile')->with('objkey123', 'PDFBYTES');
		$this->appData->method('getFolder')->with('card-500')->willReturn($kansoFolder);

		$captured = null;
		$this->cardAttachmentMapper->expects(self::once())->method('insert')
			->willReturnCallback(function (CardAttachment $a) use (&$captured): CardAttachment {
				$captured = $a;
				$a->setId(1);
				return $a;
			});

		$result = $this->service->importBoard(2, 'alice');

		self::assertSame(1, $result['attachments']);
		self::assertNotNull($captured);
		self::assertSame(500, $captured->getCardId());
		self::assertSame(100, $captured->getBoardId());
		self::assertSame('report.pdf', $captured->getFilename());
		self::assertSame('application/pdf', $captured->getMime());
		self::assertSame(8, $captured->getSize());
		self::assertSame('objkey123', $captured->getStorageKey());
		self::assertSame('bob', $captured->getUploadedBy());
		self::assertSame(333, $captured->getCreatedAt());
	}

	public function testImportCoercesScriptableAttachmentMime(): void {
		// A deck_file whose source reports a scriptable MIME (text/html) must be
		// stored as application/octet-stream - the same coercion the upload path
		// applies - so an imported .html can never become stored XSS.
		$this->stubOneCardBoard();
		$this->deckReader->method('readComments')->willReturn([]);
		$this->deckReader->method('readFileReferenceAttachments')->willReturn([]);
		$this->deckReader->method('readAttachments')->willReturn([
			['id' => 31, 'cardId' => 21, 'type' => 'deck_file', 'data' => 'page.html', 'createdBy' => 'bob', 'createdAt' => 333],
		]);
		$this->userManager->method('userExists')->willReturn(true);
		$this->secureRandom->method('generate')->willReturn('objkey123');

		$sourceFile = $this->createMock(ISimpleFile::class);
		$sourceFile->method('getContent')->willReturn('<script>alert(1)</script>');
		$sourceFile->method('getMimeType')->willReturn('text/html');
		$sourceFile->method('getSize')->willReturn(25);
		$deckFolder = $this->createMock(ISimpleFolder::class);
		$deckFolder->method('getFile')->with('page.html')->willReturn($sourceFile);
		$deckAppData = $this->createMock(IAppData::class);
		$deckAppData->method('getFolder')->with('file-card-21')->willReturn($deckFolder);
		$this->appDataFactory->method('get')->with('deck')->willReturn($deckAppData);

		$kansoFolder = $this->createMock(ISimpleFolder::class);
		$this->appData->method('getFolder')->with('card-500')->willReturn($kansoFolder);

		$captured = null;
		$this->cardAttachmentMapper->expects(self::once())->method('insert')
			->willReturnCallback(function (CardAttachment $a) use (&$captured): CardAttachment {
				$captured = $a;
				$a->setId(1);
				return $a;
			});

		$result = $this->service->importBoard(2, 'alice');

		self::assertSame(1, $result['attachments']);
		self::assertNotNull($captured);
		self::assertSame('application/octet-stream', $captured->getMime());
	}

	public function testImportSanitizesAttachmentFilename(): void {
		// A control-char / path-laden source filename is normalized on the stored
		// attachment (defence in depth - it never selects a storage path).
		$this->stubOneCardBoard();
		$this->deckReader->method('readComments')->willReturn([]);
		$this->deckReader->method('readFileReferenceAttachments')->willReturn([]);
		$this->deckReader->method('readAttachments')->willReturn([
			['id' => 31, 'cardId' => 21, 'type' => 'deck_file', 'data' => "../../ev\x00il.txt", 'createdBy' => 'bob', 'createdAt' => 333],
		]);
		$this->userManager->method('userExists')->willReturn(true);
		$this->secureRandom->method('generate')->willReturn('objkey123');

		$sourceFile = $this->createMock(ISimpleFile::class);
		$sourceFile->method('getContent')->willReturn('DATA');
		$sourceFile->method('getMimeType')->willReturn('text/plain');
		$sourceFile->method('getSize')->willReturn(4);
		$deckFolder = $this->createMock(ISimpleFolder::class);
		$deckFolder->method('getFile')->willReturn($sourceFile);
		$deckAppData = $this->createMock(IAppData::class);
		$deckAppData->method('getFolder')->with('file-card-21')->willReturn($deckFolder);
		$this->appDataFactory->method('get')->with('deck')->willReturn($deckAppData);

		$kansoFolder = $this->createMock(ISimpleFolder::class);
		$this->appData->method('getFolder')->with('card-500')->willReturn($kansoFolder);

		$captured = null;
		$this->cardAttachmentMapper->expects(self::once())->method('insert')
			->willReturnCallback(function (CardAttachment $a) use (&$captured): CardAttachment {
				$captured = $a;
				$a->setId(1);
				return $a;
			});

		$this->service->importBoard(2, 'alice');

		self::assertNotNull($captured);
		// basename() strips the path, the NUL byte is collapsed.
		self::assertSame('evil.txt', $captured->getFilename());
	}

	public function testImportResolvesTheSourceObjectByBasenameOnly(): void {
		// Deck's filename is the only non-server-generated name Kanso ever hands
		// to a storage lookup, so it is sanitized BEFORE the lookup: a
		// traversal-shaped name resolves to its basename inside the card's own
		// folder, never to an object above it.
		$this->stubOneCardBoard();
		$this->deckReader->method('readComments')->willReturn([]);
		$this->deckReader->method('readFileReferenceAttachments')->willReturn([]);
		$this->deckReader->method('readAttachments')->willReturn([
			['id' => 31, 'cardId' => 21, 'type' => 'deck_file', 'data' => '../../../secrets/appdata.json', 'createdBy' => 'bob', 'createdAt' => 333],
		]);
		$this->userManager->method('userExists')->willReturn(true);
		$this->secureRandom->method('generate')->willReturn('objkey123');

		$sourceFile = $this->createMock(ISimpleFile::class);
		$sourceFile->method('getContent')->willReturn('DATA');
		$sourceFile->method('getMimeType')->willReturn('application/json');
		$sourceFile->method('getSize')->willReturn(4);
		$deckFolder = $this->createMock(ISimpleFolder::class);
		// The lookup name itself must already be the basename.
		$deckFolder->expects(self::once())->method('getFile')
			->with('appdata.json')
			->willReturn($sourceFile);
		$deckAppData = $this->createMock(IAppData::class);
		$deckAppData->method('getFolder')->with('file-card-21')->willReturn($deckFolder);
		$this->appDataFactory->method('get')->with('deck')->willReturn($deckAppData);

		$kansoFolder = $this->createMock(ISimpleFolder::class);
		$this->appData->method('getFolder')->with('card-500')->willReturn($kansoFolder);

		$captured = null;
		$this->cardAttachmentMapper->expects(self::once())->method('insert')
			->willReturnCallback(function (CardAttachment $a) use (&$captured): CardAttachment {
				$captured = $a;
				$a->setId(1);
				return $a;
			});

		$result = $this->service->importBoard(2, 'alice');

		self::assertSame(1, $result['attachments']);
		self::assertNotNull($captured);
		self::assertSame('appdata.json', $captured->getFilename());
	}

	public function testImportSkipsDeckFileWithMissingSourceObjectButFinishesImport(): void {
		// A deck_file row whose source object is GONE from Deck's app-data (the
		// "row present, object missing" case that object-storage/relocated-appdata
		// instances hit) is logged + skipped, never fatal - and, critically, it is
		// COUNTED in skippedAttachments so the summary cannot claim a clean import.
		$this->stubOneCardBoard();
		$this->deckReader->method('readComments')->willReturn([]);
		$this->deckReader->method('readFileReferenceAttachments')->willReturn([]);
		$this->deckReader->method('readAttachments')->willReturn([
			['id' => 31, 'cardId' => 21, 'type' => 'deck_file', 'data' => 'gone.pdf', 'createdBy' => 'bob', 'createdAt' => 333],
		]);
		$this->userManager->method('userExists')->willReturn(true);

		$deckFolder = $this->createMock(ISimpleFolder::class);
		$deckFolder->method('getFile')->with('gone.pdf')
			->willThrowException(new NotFoundException('no such object'));
		$deckAppData = $this->createMock(IAppData::class);
		$deckAppData->method('getFolder')->with('file-card-21')->willReturn($deckFolder);
		$this->appDataFactory->method('get')->with('deck')->willReturn($deckAppData);

		// Nothing written/linked; the miss is logged, not fatal.
		$this->appData->expects(self::never())->method('getFolder');
		$this->cardAttachmentMapper->expects(self::never())->method('insert');
		$this->logger->expects(self::atLeastOnce())->method('warning');
		$this->db->expects(self::once())->method('commit');
		$this->db->expects(self::never())->method('rollBack');

		$result = $this->service->importBoard(2, 'alice');

		self::assertSame(0, $result['attachments']);
		self::assertSame(1, $result['skippedAttachments']);
		self::assertSame(1, $result['cards']);
	}

	public function testImportSkipsOversizedAttachmentButFinishesImport(): void {
		// An oversized source (getSize() > MAX_SIZE) is skipped-and-not-counted,
		// never read, and never fatal - the rest of the import still succeeds.
		$this->stubOneCardBoard();
		$this->deckReader->method('readComments')->willReturn([]);
		$this->deckReader->method('readFileReferenceAttachments')->willReturn([]);
		$this->deckReader->method('readAttachments')->willReturn([
			['id' => 31, 'cardId' => 21, 'type' => 'deck_file', 'data' => 'huge.bin', 'createdBy' => 'bob', 'createdAt' => 333],
		]);
		$this->userManager->method('userExists')->willReturn(true);

		$sourceFile = $this->createMock(ISimpleFile::class);
		// Oversized: its bytes must never be read.
		$sourceFile->method('getSize')->willReturn(AttachmentSanitizer::MAX_SIZE + 1);
		$sourceFile->expects(self::never())->method('getContent');
		$deckFolder = $this->createMock(ISimpleFolder::class);
		$deckFolder->method('getFile')->with('huge.bin')->willReturn($sourceFile);
		$deckAppData = $this->createMock(IAppData::class);
		$deckAppData->method('getFolder')->with('file-card-21')->willReturn($deckFolder);
		$this->appDataFactory->method('get')->with('deck')->willReturn($deckAppData);

		// Nothing is written and no row is inserted for the skipped attachment.
		$this->appData->expects(self::never())->method('getFolder');
		$this->cardAttachmentMapper->expects(self::never())->method('insert');

		$this->db->expects(self::once())->method('commit');
		$this->db->expects(self::never())->method('rollBack');

		$result = $this->service->importBoard(2, 'alice');

		// The whole import still succeeds; the oversized attachment is not counted
		// as imported - and IS counted as skipped, so the summary reports the loss.
		self::assertSame(0, $result['attachments']);
		self::assertSame(1, $result['skippedAttachments']);
		self::assertSame(1, $result['cards']);
	}

	public function testImportSkipsUnreadableDeckFileButFinishesImport(): void {
		// A deck_file whose object resolves but whose bytes FAIL to read (storage
		// error) is logged + skipped, NOT fatal - the import still commits.
		$this->stubOneCardBoard();
		$this->deckReader->method('readComments')->willReturn([]);
		$this->deckReader->method('readFileReferenceAttachments')->willReturn([]);
		$this->deckReader->method('readAttachments')->willReturn([
			['id' => 31, 'cardId' => 21, 'type' => 'deck_file', 'data' => 'broken.bin', 'createdBy' => 'bob', 'createdAt' => 333],
		]);
		$this->userManager->method('userExists')->willReturn(true);

		$sourceFile = $this->createMock(ISimpleFile::class);
		$sourceFile->method('getSize')->willReturn(10);
		$sourceFile->method('getContent')->willThrowException(new \RuntimeException('storage boom'));
		$deckFolder = $this->createMock(ISimpleFolder::class);
		$deckFolder->method('getFile')->with('broken.bin')->willReturn($sourceFile);
		$deckAppData = $this->createMock(IAppData::class);
		$deckAppData->method('getFolder')->with('file-card-21')->willReturn($deckFolder);
		$this->appDataFactory->method('get')->with('deck')->willReturn($deckAppData);

		// Nothing written/linked; the failed read is logged, not fatal.
		$this->cardAttachmentMapper->expects(self::never())->method('insert');
		$this->logger->expects(self::atLeastOnce())->method('warning');
		$this->db->expects(self::once())->method('commit');
		$this->db->expects(self::never())->method('rollBack');

		$result = $this->service->importBoard(2, 'alice');

		self::assertSame(0, $result['attachments']);
		self::assertSame(1, $result['skippedAttachments']);
		self::assertSame(1, $result['cards']);
	}

	public function testImportSkipsUnresolvableFileReferenceAttachment(): void {
		// Two `file`-kind references (Deck shares) whose source nodes can no longer
		// be resolved (owner has no matching file id) are LOGGED and skipped -
		// counted in skippedAttachments, never fatal, nothing written/linked.
		$this->stubOneCardBoard();
		$this->deckReader->method('readComments')->willReturn([]);
		$this->deckReader->method('readAttachments')->willReturn([]);
		$this->deckReader->method('readFileReferenceAttachments')->willReturn([
			['cardId' => 21, 'fileId' => 900, 'filename' => 'a.pdf', 'owner' => 'carol', 'createdBy' => 'carol', 'createdAt' => 10],
			['cardId' => 21, 'fileId' => 901, 'filename' => 'b.pdf', 'owner' => 'carol', 'createdBy' => 'carol', 'createdAt' => 20],
		]);

		// The owner's userfolder resolves NEITHER file id → no node → skipped.
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([]);
		$this->rootFolder->method('getUserFolder')->with('carol')->willReturn($userFolder);

		$this->cardAttachmentMapper->expects(self::never())->method('insert');
		$this->appData->expects(self::never())->method('getFolder');
		$this->logger->expects(self::atLeastOnce())->method('warning');

		$result = $this->service->importBoard(2, 'alice');

		self::assertSame(0, $result['attachments']);
		self::assertSame(2, $result['skippedAttachments']);
		self::assertSame(1, $result['cards']);
	}

	public function testImportCopiesFileReferenceAttachment(): void {
		// A `file`-kind reference (a Deck share) is resolved from the owner's Files
		// by file id and its bytes are COPIED into Kanso via the same sanitized
		// store path as an upload - so it lands as a normal Kanso attachment and
		// counts toward `attachments`, not `skippedAttachments`.
		$this->stubOneCardBoard();
		$this->deckReader->method('readComments')->willReturn([]);
		$this->deckReader->method('readAttachments')->willReturn([]);
		$this->deckReader->method('readFileReferenceAttachments')->willReturn([
			['cardId' => 21, 'fileId' => 1571, 'filename' => 'View Registration Information.pdf', 'owner' => 'carol', 'createdBy' => 'carol', 'createdAt' => 444],
		]);
		$this->userManager->method('userExists')->willReturn(true);
		$this->secureRandom->method('generate')->willReturn('reffkey1');

		$node = $this->createMock(File::class);
		$node->method('getSize')->willReturn(12);
		$node->method('getContent')->willReturn('PDFCONTENT12');
		$node->method('getMimetype')->willReturn('application/pdf');
		$node->method('getName')->willReturn('View Registration Information.pdf');
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with(1571)->willReturn([$node]);
		$this->rootFolder->method('getUserFolder')->with('carol')->willReturn($userFolder);

		$kansoFolder = $this->createMock(ISimpleFolder::class);
		$kansoFolder->expects(self::once())->method('newFile')->with('reffkey1', 'PDFCONTENT12');
		$this->appData->method('getFolder')->with('card-500')->willReturn($kansoFolder);

		$captured = null;
		$this->cardAttachmentMapper->expects(self::once())->method('insert')
			->willReturnCallback(function (CardAttachment $a) use (&$captured): CardAttachment {
				$captured = $a;
				$a->setId(1);
				return $a;
			});

		$result = $this->service->importBoard(2, 'alice');

		self::assertSame(1, $result['attachments']);
		self::assertSame(0, $result['skippedAttachments']);
		self::assertNotNull($captured);
		self::assertSame(500, $captured->getCardId());
		self::assertSame(100, $captured->getBoardId());
		self::assertSame('View Registration Information.pdf', $captured->getFilename());
		self::assertSame('application/pdf', $captured->getMime());
		self::assertSame(12, $captured->getSize());
		self::assertSame('reffkey1', $captured->getStorageKey());
		self::assertSame('carol', $captured->getUploadedBy());
		self::assertSame(444, $captured->getCreatedAt());
	}

	public function testImportSumsSkippedAttachmentsAcrossBothPaths(): void {
		// The summary reports ONE honest skipped total: a dropped deck_file upload
		// and a dropped file reference both land in the same number, so neither
		// path can hide a loss behind the other's clean count.
		$this->stubOneCardBoard();
		$this->deckReader->method('readComments')->willReturn([]);
		$this->deckReader->method('readAttachments')->willReturn([
			['id' => 31, 'cardId' => 21, 'type' => 'deck_file', 'data' => 'huge.bin', 'createdBy' => 'bob', 'createdAt' => 333],
		]);
		$this->deckReader->method('readFileReferenceAttachments')->willReturn([
			['cardId' => 21, 'fileId' => 900, 'filename' => 'a.pdf', 'owner' => 'carol', 'createdBy' => 'carol', 'createdAt' => 10],
		]);
		$this->userManager->method('userExists')->willReturn(true);

		// deck_file: oversized → skipped.
		$sourceFile = $this->createMock(ISimpleFile::class);
		$sourceFile->method('getSize')->willReturn(AttachmentSanitizer::MAX_SIZE + 1);
		$deckFolder = $this->createMock(ISimpleFolder::class);
		$deckFolder->method('getFile')->with('huge.bin')->willReturn($sourceFile);
		$deckAppData = $this->createMock(IAppData::class);
		$deckAppData->method('getFolder')->with('file-card-21')->willReturn($deckFolder);
		$this->appDataFactory->method('get')->with('deck')->willReturn($deckAppData);

		// file reference: unresolvable → skipped.
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([]);
		$this->rootFolder->method('getUserFolder')->with('carol')->willReturn($userFolder);

		$this->cardAttachmentMapper->expects(self::never())->method('insert');
		$this->db->expects(self::once())->method('commit');

		$result = $this->service->importBoard(2, 'alice');

		self::assertSame(0, $result['attachments']);
		self::assertSame(2, $result['skippedAttachments']);
	}

	public function testImportImportsBothAttachmentKindsTogether(): void {
		// A single card with BOTH a deck_file upload AND a file reference: both are
		// copied into Kanso and the summary counts both under `attachments`.
		$this->stubOneCardBoard();
		$this->deckReader->method('readComments')->willReturn([]);
		$this->deckReader->method('readAttachments')->willReturn([
			['id' => 31, 'cardId' => 21, 'type' => 'deck_file', 'data' => 'upload.pdf', 'createdBy' => 'bob', 'createdAt' => 333],
		]);
		$this->deckReader->method('readFileReferenceAttachments')->willReturn([
			['cardId' => 21, 'fileId' => 1571, 'filename' => 'ref.pdf', 'owner' => 'carol', 'createdBy' => 'carol', 'createdAt' => 444],
		]);
		$this->userManager->method('userExists')->willReturn(true);
		$this->secureRandom->method('generate')->willReturnOnConsecutiveCalls('uploadkey', 'refkey');

		// deck_file source in Deck app-data under file-card-21.
		$sourceFile = $this->createMock(ISimpleFile::class);
		$sourceFile->method('getContent')->willReturn('UPLOADBYTES');
		$sourceFile->method('getMimeType')->willReturn('application/pdf');
		$sourceFile->method('getSize')->willReturn(11);
		$deckFolder = $this->createMock(ISimpleFolder::class);
		$deckFolder->method('getFile')->with('upload.pdf')->willReturn($sourceFile);
		$deckAppData = $this->createMock(IAppData::class);
		$deckAppData->method('getFolder')->with('file-card-21')->willReturn($deckFolder);
		$this->appDataFactory->method('get')->with('deck')->willReturn($deckAppData);

		// file-reference source in the owner's Files.
		$node = $this->createMock(File::class);
		$node->method('getSize')->willReturn(7);
		$node->method('getContent')->willReturn('REFBYTE');
		$node->method('getMimetype')->willReturn('application/pdf');
		$node->method('getName')->willReturn('ref.pdf');
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with(1571)->willReturn([$node]);
		$this->rootFolder->method('getUserFolder')->with('carol')->willReturn($userFolder);

		$kansoFolder = $this->createMock(ISimpleFolder::class);
		$kansoFolder->expects(self::exactly(2))->method('newFile');
		$this->appData->method('getFolder')->with('card-500')->willReturn($kansoFolder);

		$captured = [];
		$this->cardAttachmentMapper->method('insert')
			->willReturnCallback(function (CardAttachment $a) use (&$captured): CardAttachment {
				$captured[] = $a;
				$a->setId(count($captured));
				return $a;
			});

		$result = $this->service->importBoard(2, 'alice');

		self::assertSame(2, $result['attachments']);
		self::assertSame(0, $result['skippedAttachments']);
		self::assertCount(2, $captured);
		$filenames = array_map(static fn (CardAttachment $a): string => $a->getFilename(), $captured);
		self::assertContains('upload.pdf', $filenames);
		self::assertContains('ref.pdf', $filenames);
	}
}
