<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardReview;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ReviewType;
use OCA\Kanso\Db\ReviewTypeMapper;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\CommentService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotificationService;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\ReviewService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReviewServiceTest extends TestCase {
	private CardReviewMapper&MockObject $cardReviewMapper;
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private ChangeNotifier&MockObject $changeNotifier;
	private PermissionService&MockObject $permissionService;
	private NotificationService&MockObject $notificationService;
	private ReviewTypeMapper&MockObject $reviewTypeMapper;
	private BoardService&MockObject $boardService;
	private CommentService&MockObject $commentService;
	private ReviewService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->cardReviewMapper = $this->createMock(CardReviewMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->reviewTypeMapper = $this->createMock(ReviewTypeMapper::class);
		$this->boardService = $this->createMock(BoardService::class);
		$this->commentService = $this->createMock(CommentService::class);
		$this->service = new ReviewService(
			$this->cardReviewMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->changeNotifier,
			$this->permissionService,
			$this->notificationService,
			$this->reviewTypeMapper,
			$this->boardService,
			$this->commentService,
		);
	}

	private function board(int $id = 1): Board {
		$board = new Board();
		$board->setId($id);
		$board->setOwner('alice');
		$board->setDeletedAt(0);
		return $board;
	}

	private function card(int $id = 9, int $boardId = 1): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId($boardId);
		$card->setStackId(5);
		$card->setTitle('Existing card');
		$card->setSortKey('I');
		$card->setDeletedAt(0);
		return $card;
	}

	private function review(string $reviewer = 'bob', string $state = CardReview::STATE_PENDING, int $id = 1, int $cardId = 9): CardReview {
		$review = new CardReview();
		$review->setId($id);
		$review->setCardId($cardId);
		$review->setReviewer($reviewer);
		$review->setState($state);
		$review->setRequestedBy('alice');
		$review->setCreatedAt(100);
		return $review;
	}

	private function loadCardAndBoard(): Board {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		return $board;
	}

	// ---- requestReview ----------------------------------------------------

	public function testRequestInsertsRowWritesChangeAndNotifies(): void {
		$board = $this->loadCardAndBoard();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_EDIT);
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')
			->willReturn(PermissionService::PERMISSION_READ);
		$this->cardReviewMapper->method('existsForType')->with(9, 'bob', 0)->willReturn(false);
		$this->cardReviewMapper->expects(self::once())
			->method('insertRequest')
			->with(9, 'bob', 'alice', null);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'alice')
			->willReturn(new Change());
		$this->notificationService->expects(self::once())
			->method('notifyReviewRequested')
			->with(9, 'bob', 'alice');

		$this->service->requestReview(9, 'bob', 'alice');
	}

	public function testRequestSameReviewerDifferentTypeIsAllowed(): void {
		$board = $this->loadCardAndBoard();
		$this->permissionService->method('getPermissions')->with($board, 'bob')
			->willReturn(PermissionService::PERMISSION_READ);
		$this->reviewTypeMapper->method('find')->with(5)->willReturn($this->reviewType(5, 1));
		// bob already has an untyped review (type 0) but not a type-5 one.
		$this->cardReviewMapper->method('existsForType')->with(9, 'bob', 5)->willReturn(false);
		$this->cardReviewMapper->expects(self::once())->method('insertRequest')->with(9, 'bob', 'alice', 5);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->requestReview(9, 'bob', 'alice', 5);
	}

	public function testRequestIsIdempotentWhenSameTypeAlreadyRequested(): void {
		$board = $this->loadCardAndBoard();
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')
			->willReturn(PermissionService::PERMISSION_READ);
		$this->cardReviewMapper->method('existsForType')->with(9, 'bob', 0)->willReturn(true);
		$this->cardReviewMapper->expects(self::never())->method('insertRequest');
		$this->changeNotifier->expects(self::never())->method('notify');
		$this->notificationService->expects(self::never())->method('notifyReviewRequested');

		$this->service->requestReview(9, 'bob', 'alice');
	}

	public function testRequestTreatsLostInsertRaceAsIdempotentSuccess(): void {
		$board = $this->loadCardAndBoard();
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')
			->willReturn(PermissionService::PERMISSION_READ);
		$this->cardReviewMapper->method('existsForType')->willReturn(false);
		$uniqueViolation = $this->createMock(\OCP\DB\Exception::class);
		$uniqueViolation->method('getReason')
			->willReturn(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		$this->cardReviewMapper->method('insertRequest')->willThrowException($uniqueViolation);
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->service->requestReview(9, 'bob', 'alice');
	}

	public function testRequestAssertsActorEditPermission(): void {
		$board = $this->loadCardAndBoard();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->cardReviewMapper->expects(self::never())->method('insertRequest');

		$this->expectException(NotPermittedException::class);
		$this->service->requestReview(9, 'bob', 'mallory');
	}

	public function testRequestRejectsReviewerWithoutBoardAccess(): void {
		$board = $this->loadCardAndBoard();
		$this->permissionService->method('getPermissions')
			->with($board, 'stranger')
			->willReturn(0);
		$this->cardReviewMapper->expects(self::never())->method('existsForType');
		$this->cardReviewMapper->expects(self::never())->method('insertRequest');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('User has no access to this board');
		$this->service->requestReview(9, 'stranger', 'alice');
	}

	public function testRequestRejectsDeletedCard(): void {
		$card = $this->card();
		$card->setDeletedAt(1234);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->cardReviewMapper->expects(self::never())->method('insertRequest');

		$this->expectException(DoesNotExistException::class);
		$this->service->requestReview(9, 'bob', 'alice');
	}

	private function reviewType(int $id = 3, int $boardId = 1): ReviewType {
		$type = new ReviewType();
		$type->setId($id);
		$type->setBoardId($boardId);
		$type->setTitle('QA');
		return $type;
	}

	public function testRequestWithValidTypePersistsIt(): void {
		$board = $this->loadCardAndBoard();
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')->willReturn(PermissionService::PERMISSION_READ);
		$this->reviewTypeMapper->method('find')->with(3)->willReturn($this->reviewType(3, 1));
		$this->cardReviewMapper->method('existsForType')->with(9, 'bob', 3)->willReturn(false);
		$this->cardReviewMapper->expects(self::once())
			->method('insertRequest')
			->with(9, 'bob', 'alice', 3);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->requestReview(9, 'bob', 'alice', 3);
	}

	public function testRequestRejectsTypeFromAnotherBoard(): void {
		$board = $this->loadCardAndBoard();
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')->willReturn(PermissionService::PERMISSION_READ);
		$this->reviewTypeMapper->method('find')->with(3)->willReturn($this->reviewType(3, 2));
		$this->cardReviewMapper->expects(self::never())->method('insertRequest');

		$this->expectException(InvalidInputException::class);
		$this->service->requestReview(9, 'bob', 'alice', 3);
	}

	public function testRequestRejectsUnknownType(): void {
		$board = $this->loadCardAndBoard();
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')->willReturn(PermissionService::PERMISSION_READ);
		$this->reviewTypeMapper->method('find')->with(3)
			->willThrowException(new DoesNotExistException('no type'));
		$this->cardReviewMapper->expects(self::never())->method('insertRequest');

		$this->expectException(InvalidInputException::class);
		$this->service->requestReview(9, 'bob', 'alice', 3);
	}

	// ---- withdrawReview (by review id) ------------------------------------

	public function testWithdrawDeletesRowWritesChangeAndDismisses(): void {
		$board = $this->loadCardAndBoard();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_EDIT);
		$review = $this->review('bob');
		$this->cardReviewMapper->method('findById')->with(1)->willReturn($review);
		$this->cardReviewMapper->expects(self::once())->method('delete')->with($review);
		$this->changeNotifier->expects(self::once())->method('notify')->willReturn(new Change());
		$this->notificationService->expects(self::once())
			->method('dismissReviewRequested')
			->with(9, 'bob');

		$this->service->withdrawReview(9, 1, 'alice');
	}

	public function testWithdrawIsIdempotentWhenAbsent(): void {
		$this->loadCardAndBoard();
		$this->cardReviewMapper->method('findById')->with(1)->willReturn(null);
		$this->cardReviewMapper->expects(self::never())->method('delete');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->service->withdrawReview(9, 1, 'alice');
	}

	public function testWithdrawIgnoresReviewFromAnotherCard(): void {
		$this->loadCardAndBoard();
		$this->cardReviewMapper->method('findById')->with(1)->willReturn($this->review('bob', CardReview::STATE_PENDING, 1, 42));
		$this->cardReviewMapper->expects(self::never())->method('delete');

		$this->service->withdrawReview(9, 1, 'alice');
	}

	// ---- setState (by review id) ------------------------------------------

	public function testSetStateApprovesOwnReview(): void {
		$board = $this->loadCardAndBoard();
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')
			->willReturn(PermissionService::PERMISSION_READ);
		$this->cardReviewMapper->method('findById')->with(1)->willReturn($this->review('bob'));
		$this->cardReviewMapper->expects(self::once())
			->method('update')
			->willReturnCallback(static function (CardReview $r): CardReview {
				self::assertSame(CardReview::STATE_APPROVED, $r->getState());
				return $r;
			});
		$this->changeNotifier->expects(self::once())->method('notify')->willReturn(new Change());
		$this->notificationService->expects(self::once())
			->method('dismissReviewRequested')
			->with(9, 'bob');

		$this->service->setState(9, 1, CardReview::STATE_APPROVED, 'bob');
	}

	public function testSetStateRejectsActorWhoIsNotTheReviewer(): void {
		$this->loadCardAndBoard();
		$this->cardReviewMapper->method('findById')->with(1)->willReturn($this->review('bob'));
		$this->cardReviewMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->setState(9, 1, CardReview::STATE_APPROVED, 'mallory');
	}

	public function testSetStateRejectsInvalidState(): void {
		$this->cardReviewMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->setState(9, 1, 'pending', 'bob');
	}

	public function testSetStateThrowsWhenReviewMissing(): void {
		$this->loadCardAndBoard();
		$this->cardReviewMapper->method('findById')->with(1)->willReturn(null);
		$this->cardReviewMapper->expects(self::never())->method('update');

		$this->expectException(DoesNotExistException::class);
		$this->service->setState(9, 1, CardReview::STATE_APPROVED, 'bob');
	}

	public function testSetStateIsNoOpWhenUnchanged(): void {
		$board = $this->loadCardAndBoard();
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')
			->willReturn(PermissionService::PERMISSION_READ);
		$this->cardReviewMapper->method('findById')->with(1)
			->willReturn($this->review('bob', CardReview::STATE_APPROVED));
		$this->cardReviewMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->service->setState(9, 1, CardReview::STATE_APPROVED, 'bob');
	}

	public function testSetStateRejectsReviewerWhoLostBoardAccess(): void {
		$board = $this->loadCardAndBoard();
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')
			->willReturn(0);
		$this->cardReviewMapper->method('findById')->with(1)->willReturn($this->review('bob'));
		$this->cardReviewMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->setState(9, 1, CardReview::STATE_APPROVED, 'bob');
	}

	public function testSetStateChangesRequestedWithReasonPostsComment(): void {
		$board = $this->loadCardAndBoard();
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')
			->willReturn(PermissionService::PERMISSION_READ);
		$this->cardReviewMapper->method('findById')->with(1)->willReturn($this->review('bob'));
		$this->cardReviewMapper->expects(self::once())->method('update');
		$this->commentService->expects(self::once())
			->method('addComment')
			->with(9, '**Requested changes:** please fix the tests', null, 'bob');

		$this->service->setState(9, 1, CardReview::STATE_CHANGES_REQUESTED, 'bob', 'please fix the tests');
	}

	public function testSetStateChangesRequestedWithoutReasonPostsNoComment(): void {
		$board = $this->loadCardAndBoard();
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')
			->willReturn(PermissionService::PERMISSION_READ);
		$this->cardReviewMapper->method('findById')->with(1)->willReturn($this->review('bob'));
		$this->commentService->expects(self::never())->method('addComment');

		$this->service->setState(9, 1, CardReview::STATE_CHANGES_REQUESTED, 'bob', '   ');
	}

	// ---- findMine ---------------------------------------------------------

	public function testFindMineQueriesTheReadableBoardSet(): void {
		$this->boardService->method('findAll')->with('bob')->willReturn([
			$this->board(1),
			$this->board(2),
		]);
		$rows = [[
			'id' => 1, 'cardId' => 9, 'cardTitle' => 'Card', 'boardId' => 1,
			'boardTitle' => 'Board', 'state' => 'pending', 'reviewTypeId' => null,
			'requestedBy' => 'alice', 'createdAt' => 100,
		]];
		$this->cardReviewMapper->expects(self::once())
			->method('findByReviewerInBoards')
			->with('bob', [1, 2])
			->willReturn($rows);

		self::assertSame($rows, $this->service->findMine('bob'));
	}

	public function testFindMineWithNoReadableBoardsReturnsEmpty(): void {
		$this->boardService->method('findAll')->with('bob')->willReturn([]);
		$this->cardReviewMapper->expects(self::once())
			->method('findByReviewerInBoards')
			->with('bob', [])
			->willReturn([]);

		self::assertSame([], $this->service->findMine('bob'));
	}
}
