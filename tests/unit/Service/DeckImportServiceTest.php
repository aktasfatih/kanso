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
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
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
	private CommentMapper&MockObject $commentMapper;
	private CardAttachmentMapper&MockObject $cardAttachmentMapper;
	private IUserManager&MockObject $userManager;
	private \OCP\IDBConnection&MockObject $db;
	private IAppData&MockObject $appData;
	private IAppDataFactory&MockObject $appDataFactory;
	private ISecureRandom&MockObject $secureRandom;
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
		$this->userManager = $this->createMock(IUserManager::class);
		$this->db = $this->createMock(\OCP\IDBConnection::class);
		$this->appData = $this->createMock(IAppData::class);
		$this->appDataFactory = $this->createMock(IAppDataFactory::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);
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
			new SortKeyService(),
			$this->userManager,
			$this->db,
			$this->appData,
			$this->appDataFactory,
			$this->secureRandom,
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
		$this->deckReader->method('readComments')->willReturn([]);
		$this->deckReader->method('readAttachments')->willReturn([]);
		$this->deckReader->method('countFileReferenceAttachments')->willReturn(0);

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
			'skippedFileAttachments' => 0,
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
		$this->deckReader->method('readComments')->willReturn([]);
		$this->deckReader->method('readAttachments')->willReturn([]);
		$this->deckReader->method('countFileReferenceAttachments')->willReturn(0);
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
		$this->deckReader->method('countFileReferenceAttachments')->willReturn(0);

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
		$deckAppData->method('getFolder')->with('21')->willReturn($deckFolder);
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

	public function testImportSkipsAndCountsFileReferenceAttachment(): void {
		// readAttachments returns only deck_file rows; a `file`-kind row never
		// reaches import - it is surfaced via countFileReferenceAttachments and
		// the attachment mapper is never touched (asserted in the helper).
		$result = $this->importWithSkipCount(2);

		self::assertSame(0, $result['attachments']);
		self::assertSame(2, $result['skippedFileAttachments']);
	}

	/**
	 * Runs a one-card import where the only Deck attachments are user-Files
	 * references (the `file` kind), so none are copied and the count surfaces.
	 *
	 * @return array{boardId: int, title: string, stacks: int, cards: int, labels: int, comments: int, attachments: int, skippedFileAttachments: int}
	 */
	private function importWithSkipCount(int $skip): array {
		$reader = $this->createMock(DeckReader::class);
		$reader->method('isAvailable')->willReturn(true);
		$reader->method('userCanReadBoard')->willReturn(true);
		$reader->method('readBoard')->with(2)
			->willReturn(['id' => 2, 'title' => 'B', 'color' => null, 'owner' => 'carol']);
		$reader->method('readLabels')->willReturn([]);
		$reader->method('readStacks')->willReturn([['id' => 11, 'title' => 'To do']]);
		$reader->method('readCards')->willReturn([
			['id' => 21, 'title' => 'C', 'description' => '', 'archived' => false, 'duedate' => null, 'doneAt' => 0, 'createdAt' => 0],
		]);
		$reader->method('readAssignedLabels')->willReturn([]);
		$reader->method('readAssignedUsers')->willReturn([]);
		$reader->method('readComments')->willReturn([]);
		$reader->method('readAttachments')->willReturn([]);
		$reader->method('countFileReferenceAttachments')->willReturn($skip);

		$board = new Board();
		$board->setId(100);
		$board->setTitle('B');
		$boardService = $this->createMock(BoardService::class);
		$boardService->method('create')->willReturn($board);

		$stackMapper = $this->createMock(StackMapper::class);
		$stackMapper->method('insert')->willReturnCallback($this->autoId());
		$cardMapper = $this->createMock(CardMapper::class);
		$cardMapper->method('insert')->willReturnCallback(function (Card $c): Card {
			$c->setId(500);
			return $c;
		});

		$attachmentMapper = $this->createMock(CardAttachmentMapper::class);
		$attachmentMapper->expects(self::never())->method('insert');

		$service = new DeckImportService(
			$reader,
			$boardService,
			$stackMapper,
			$cardMapper,
			$this->createMock(LabelMapper::class),
			$this->createMock(CardLabelMapper::class),
			$this->createMock(CardAssigneeMapper::class),
			$this->createMock(CommentMapper::class),
			$attachmentMapper,
			new SortKeyService(),
			$this->createMock(IUserManager::class),
			$this->createMock(\OCP\IDBConnection::class),
			$this->createMock(IAppData::class),
			$this->createMock(IAppDataFactory::class),
			$this->createMock(ISecureRandom::class),
		);
		return $service->importBoard(2, 'alice');
	}
}
