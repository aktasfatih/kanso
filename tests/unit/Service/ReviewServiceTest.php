<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\ViewerContext;
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
use OCA\Kanso\Service\CardVisibilityGuard;
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
	private BoardAccess&MockObject $boardAccess;
	private CardVisibilityGuard&MockObject $visibilityGuard;
	private ReviewService $service;

	/** @var string[] uids the guard reports as unable to see the card */
	private array $hiddenFrom = [];

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
		$this->boardAccess = $this->createMock(BoardAccess::class);
		// Default: everyone sees every card (assertVisible passes as a no-op);
		// a test hides the card from specific uids via $this->hiddenFrom.
		$this->visibilityGuard = $this->createMock(CardVisibilityGuard::class);
		$this->visibilityGuard->method('isVisible')->willReturnCallback(
			fn (Board $board, Card $card, string $uid): bool => !in_array($uid, $this->hiddenFrom, true),
		);
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
			$this->boardAccess,
			$this->visibilityGuard,
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

	/**
	 * @param CardReview[] $siblings the findByCard() result the gating fold sees
	 */
	private function expectInsertReturns(CardReview $inserted, array $siblings): void {
		$this->cardReviewMapper->method('insertRequest')->willReturn($inserted);
		$this->cardReviewMapper->method('findByCard')->with(9)->willReturn($siblings);
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
		$inserted = $this->review('bob');
		$this->cardReviewMapper->expects(self::once())
			->method('insertRequest')
			->with(9, 'bob', 'alice', null)
			->willReturn($inserted);
		// No lower-stage review exists → not gated → the notification fires and
		// notified_at is stamped (one update).
		$this->cardReviewMapper->method('findByCard')->with(9)->willReturn([$inserted]);
		$this->reviewTypeMapper->method('stageMapForBoard')->with(1)->willReturn([]);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'alice')
			->willReturn(new Change());
		$this->notificationService->expects(self::once())
			->method('notifyReviewRequested')
			->with(9, 'bob', 'alice');
		$this->cardReviewMapper->expects(self::once())
			->method('update')
			->willReturnCallback(static function (CardReview $r): CardReview {
				self::assertNotNull($r->getNotifiedAt());
				return $r;
			});

		$this->service->requestReview(9, 'bob', 'alice');
	}

	public function testRequestDefersNotificationWhenGated(): void {
		// A stage-1 QA review requested while a stage-0 Code review sits unapproved
		// on the card: the change row still fires (chip renders) but the reviewer
		// notification is suppressed and notified_at is left null.
		$board = $this->loadCardAndBoard();
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')->willReturn(PermissionService::PERMISSION_READ);
		$this->reviewTypeMapper->method('find')->with(2)->willReturn($this->reviewType(2, 1));
		$this->cardReviewMapper->method('existsForType')->with(9, 'bob', 2)->willReturn(false);

		$code = $this->review('carol', CardReview::STATE_PENDING, 1, 9);
		$code->setReviewTypeId(1); // stage 0
		$qa = $this->review('bob', CardReview::STATE_PENDING, 2, 9);
		$qa->setReviewTypeId(2); // stage 1
		$this->cardReviewMapper->method('insertRequest')->willReturn($qa);
		$this->cardReviewMapper->method('findByCard')->with(9)->willReturn([$code, $qa]);
		$this->reviewTypeMapper->method('stageMapForBoard')->with(1)->willReturn([1 => 0, 2 => 1]);

		$this->changeNotifier->expects(self::once())->method('notify')->willReturn(new Change());
		$this->notificationService->expects(self::never())->method('notifyReviewRequested');
		$this->cardReviewMapper->expects(self::never())->method('update');

		$this->service->requestReview(9, 'bob', 'alice', 2);
	}

	public function testRequestNotifiesWhenPrerequisiteNotYetRequested(): void {
		// A stage-1 review whose stage-0 prerequisite has NOT been requested is
		// NOT gated - a not-yet-requested prerequisite must not block.
		$board = $this->loadCardAndBoard();
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')->willReturn(PermissionService::PERMISSION_READ);
		$this->reviewTypeMapper->method('find')->with(2)->willReturn($this->reviewType(2, 1));
		$this->cardReviewMapper->method('existsForType')->with(9, 'bob', 2)->willReturn(false);

		$qa = $this->review('bob', CardReview::STATE_PENDING, 2, 9);
		$qa->setReviewTypeId(2); // stage 1, alone on the card
		$this->cardReviewMapper->method('insertRequest')->willReturn($qa);
		$this->cardReviewMapper->method('findByCard')->with(9)->willReturn([$qa]);
		$this->reviewTypeMapper->method('stageMapForBoard')->with(1)->willReturn([1 => 0, 2 => 1]);

		$this->changeNotifier->method('notify')->willReturn(new Change());
		$this->notificationService->expects(self::once())
			->method('notifyReviewRequested')->with(9, 'bob', 'alice');
		$this->cardReviewMapper->expects(self::once())->method('update');

		$this->service->requestReview(9, 'bob', 'alice', 2);
	}

	public function testRequestSameReviewerDifferentTypeIsAllowed(): void {
		$board = $this->loadCardAndBoard();
		$this->permissionService->method('getPermissions')->with($board, 'bob')
			->willReturn(PermissionService::PERMISSION_READ);
		$this->reviewTypeMapper->method('find')->with(5)->willReturn($this->reviewType(5, 1));
		// bob already has an untyped review (type 0) but not a type-5 one.
		$this->cardReviewMapper->method('existsForType')->with(9, 'bob', 5)->willReturn(false);
		$inserted = $this->review('bob', CardReview::STATE_PENDING, 7, 9);
		$inserted->setReviewTypeId(5);
		$this->cardReviewMapper->expects(self::once())->method('insertRequest')->with(9, 'bob', 'alice', 5)
			->willReturn($inserted);
		$this->cardReviewMapper->method('findByCard')->with(9)->willReturn([$inserted]);
		$this->reviewTypeMapper->method('stageMapForBoard')->with(1)->willReturn([5 => 0]);
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

	public function testRequestRejectsReviewerWhoCannotSeeTheCard(): void {
		// Visibility (#3743): the actor sees the card, but the REVIEWER does not
		// (e.g. an internal card requested from an external member) - the request
		// must be rejected before any row lands, or the title leaks into their feed.
		$board = $this->loadCardAndBoard();
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')->willReturn(PermissionService::PERMISSION_READ);
		$this->hiddenFrom = ['bob'];
		$this->cardReviewMapper->expects(self::never())->method('insertRequest');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('User has no access to this card');
		$this->service->requestReview(9, 'bob', 'alice');
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
		$inserted = $this->review('bob', CardReview::STATE_PENDING, 7, 9);
		$inserted->setReviewTypeId(3);
		$this->cardReviewMapper->expects(self::once())
			->method('insertRequest')
			->with(9, 'bob', 'alice', 3)
			->willReturn($inserted);
		$this->cardReviewMapper->method('findByCard')->with(9)->willReturn([$inserted]);
		$this->reviewTypeMapper->method('stageMapForBoard')->with(1)->willReturn([3 => 0]);
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
		$review = $this->review('bob');
		$this->cardReviewMapper->method('findById')->with(1)->willReturn($review);
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
		// The approve triggers a deferred-notification sweep; nothing downstream
		// to un-gate here (the approved review is skipped).
		$this->cardReviewMapper->method('findByCard')->with(9)->willReturn([$review]);
		$this->reviewTypeMapper->method('stageMapForBoard')->with(1)->willReturn([]);
		$this->notificationService->expects(self::never())->method('notifyReviewRequested');

		$this->service->setState(9, 1, CardReview::STATE_APPROVED, 'bob');
	}

	public function testApprovingBlockerFiresDeferredNotificationOnce(): void {
		// Card holds a stage-0 Code review (bob, pending) and a deferred stage-1 QA
		// review (carol, pending, notified_at null). When bob approves, the QA
		// review un-gates and its reviewer is notified exactly once, with
		// notified_at stamped so a re-approval never re-fires.
		$board = $this->loadCardAndBoard();
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')->willReturn(PermissionService::PERMISSION_READ);

		$code = $this->review('bob', CardReview::STATE_PENDING, 1, 9);
		$code->setReviewTypeId(1); // stage 0
		$qa = $this->review('carol', CardReview::STATE_PENDING, 2, 9);
		$qa->setReviewTypeId(2); // stage 1
		$qa->setRequestedBy('alice');
		$qa->setNotifiedAt(null); // deferred at request time

		$this->cardReviewMapper->method('findById')->with(1)->willReturn($code);
		// After bob's verdict is written, findByCard returns the now-approved code
		// review plus the still-pending QA one.
		$this->cardReviewMapper->method('findByCard')->with(9)->willReturn([$code, $qa]);
		$this->reviewTypeMapper->method('stageMapForBoard')->with(1)->willReturn([1 => 0, 2 => 1]);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		// QA notified exactly once (targeting carol, from requester alice).
		$this->notificationService->expects(self::once())
			->method('notifyReviewRequested')->with(9, 'carol', 'alice');
		// Two updates: bob's verdict flip + stamping QA's notified_at.
		$stamped = false;
		$this->cardReviewMapper->expects(self::exactly(2))
			->method('update')
			->willReturnCallback(function (CardReview $r) use (&$stamped): CardReview {
				if ($r->getReviewer() === 'carol') {
					self::assertNotNull($r->getNotifiedAt());
					$stamped = true;
				}
				return $r;
			});

		$this->service->setState(9, 1, CardReview::STATE_APPROVED, 'bob');
		self::assertTrue($stamped, 'QA review notified_at must be stamped');
	}

	public function testReApprovingBlockerDoesNotReNotify(): void {
		// Idempotency: the QA review was already notified (notified_at set). A
		// no-op re-approve of the (already-approved) blocker must not re-notify.
		$board = $this->loadCardAndBoard();
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')->willReturn(PermissionService::PERMISSION_READ);

		// The blocker is ALREADY approved, so setState is a no-op (no flip) and the
		// deferred-notification sweep never runs.
		$code = $this->review('bob', CardReview::STATE_APPROVED, 1, 9);
		$this->cardReviewMapper->method('findById')->with(1)->willReturn($code);
		$this->cardReviewMapper->expects(self::never())->method('update');
		$this->notificationService->expects(self::never())->method('notifyReviewRequested');

		$this->service->setState(9, 1, CardReview::STATE_APPROVED, 'bob');
	}

	public function testDeferredFireSkipsReviewerWhoCanNoLongerSeeTheCard(): void {
		// Visibility re-check at fire time (#3761): the card narrowed past carol
		// BETWEEN her (visible) request and the un-gating approval. The sweep must
		// skip her - no notification row - and must NOT stamp notified_at, so a
		// later widening lets the next approval sweep deliver it after all.
		$board = $this->loadCardAndBoard();
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')->willReturn(PermissionService::PERMISSION_READ);
		$this->hiddenFrom = ['carol'];

		$code = $this->review('bob', CardReview::STATE_PENDING, 1, 9);
		$code->setReviewTypeId(1); // stage 0
		$qa = $this->review('carol', CardReview::STATE_PENDING, 2, 9);
		$qa->setReviewTypeId(2); // stage 1
		$qa->setNotifiedAt(null); // deferred at request time

		$this->cardReviewMapper->method('findById')->with(1)->willReturn($code);
		$this->cardReviewMapper->method('findByCard')->with(9)->willReturn([$code, $qa]);
		$this->reviewTypeMapper->method('stageMapForBoard')->with(1)->willReturn([1 => 0, 2 => 1]);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		// No notification for the now-hidden reviewer...
		$this->notificationService->expects(self::never())->method('notifyReviewRequested');
		// ...and exactly ONE update: bob's verdict flip. Carol's row is untouched.
		$this->cardReviewMapper->expects(self::once())
			->method('update')
			->willReturnCallback(static function (CardReview $r): CardReview {
				self::assertSame('bob', $r->getReviewer(), 'only the verdict row may be written');
				return $r;
			});

		$this->service->setState(9, 1, CardReview::STATE_APPROVED, 'bob');
		self::assertNull($qa->getNotifiedAt(), 'a skipped fire must not stamp notified_at');
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

	public function testSetStateVerdictStandsWhenReviewerCannotComment(): void {
		// A READ-only reviewer's verdict must land even though the reason comment
		// (EDIT-gated) fails (#3476) - the comment is best-effort, after the write.
		$board = $this->loadCardAndBoard();
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')
			->willReturn(PermissionService::PERMISSION_READ);
		$this->cardReviewMapper->method('findById')->with(1)->willReturn($this->review('bob'));
		$this->cardReviewMapper->expects(self::once())->method('update');
		$this->commentService->method('addComment')->willThrowException(new NotPermittedException());

		// Must NOT throw - the verdict is recorded regardless of the comment.
		$this->service->setState(9, 1, CardReview::STATE_CHANGES_REQUESTED, 'bob', 'please fix');
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

	// ---- serializeReviewsForCard (derived gating) -------------------------

	public function testSerializeFoldsGatedAndBlockedByForDownstreamReview(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());

		$code = $this->review('carol', CardReview::STATE_PENDING, 1, 9);
		$code->setReviewTypeId(1); // stage 0
		$qa = $this->review('bob', CardReview::STATE_PENDING, 2, 9);
		$qa->setReviewTypeId(2); // stage 1
		$this->cardReviewMapper->method('findByCard')->with(9)->willReturn([$code, $qa]);
		$this->reviewTypeMapper->method('stageMapForBoard')->with(1)->willReturn([1 => 0, 2 => 1]);

		$out = $this->service->serializeReviewsForCard(9);

		// stage-0 Code review: not gated (nothing lower).
		self::assertFalse($out[0]['gated']);
		self::assertSame([], $out[0]['blockedBy']);
		// stage-1 QA review: gated by the unapproved stage-0 Code review (id 1).
		self::assertTrue($out[1]['gated']);
		self::assertSame([1], $out[1]['blockedBy']);
	}

	public function testSerializeUngatesWhenLowerStageApproved(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());

		$code = $this->review('carol', CardReview::STATE_APPROVED, 1, 9);
		$code->setReviewTypeId(1); // stage 0, approved
		$qa = $this->review('bob', CardReview::STATE_PENDING, 2, 9);
		$qa->setReviewTypeId(2); // stage 1
		$this->cardReviewMapper->method('findByCard')->with(9)->willReturn([$code, $qa]);
		$this->reviewTypeMapper->method('stageMapForBoard')->with(1)->willReturn([1 => 0, 2 => 1]);

		$out = $this->service->serializeReviewsForCard(9);

		self::assertFalse($out[1]['gated'], 'QA un-gates once the stage-0 Code review is approved');
		self::assertSame([], $out[1]['blockedBy']);
	}

	public function testSerializeUntypedReviewIsStageZeroAndNeverGated(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());

		// An untyped review (type 0) alongside a stage-1 QA review: the untyped one
		// is stage 0, never gated; the QA one is gated by the untyped (id 1).
		$untyped = $this->review('carol', CardReview::STATE_PENDING, 1, 9);
		$untyped->setReviewTypeId(0);
		$qa = $this->review('bob', CardReview::STATE_PENDING, 2, 9);
		$qa->setReviewTypeId(2);
		$this->cardReviewMapper->method('findByCard')->with(9)->willReturn([$untyped, $qa]);
		$this->reviewTypeMapper->method('stageMapForBoard')->with(1)->willReturn([2 => 1]);

		$out = $this->service->serializeReviewsForCard(9);

		self::assertFalse($out[0]['gated']);
		self::assertTrue($out[1]['gated']);
		self::assertSame([1], $out[1]['blockedBy']);
	}

	public function testSerializeSameStageReviewsDoNotGateEachOther(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());

		// Two stage-0 reviews: neither is strictly lower than the other, so neither
		// gates.
		$a = $this->review('carol', CardReview::STATE_PENDING, 1, 9);
		$a->setReviewTypeId(1);
		$b = $this->review('bob', CardReview::STATE_PENDING, 2, 9);
		$b->setReviewTypeId(2);
		$this->cardReviewMapper->method('findByCard')->with(9)->willReturn([$a, $b]);
		$this->reviewTypeMapper->method('stageMapForBoard')->with(1)->willReturn([1 => 0, 2 => 0]);

		$out = $this->service->serializeReviewsForCard(9);

		self::assertFalse($out[0]['gated']);
		self::assertFalse($out[1]['gated']);
	}

	// ---- findMine ---------------------------------------------------------

	public function testFindMineQueriesTheReadableBoardSet(): void {
		$this->boardService->method('findAllActive')->with('bob')->willReturn([
			$this->board(1),
			$this->board(2),
		]);
		$rows = [[
			'id' => 1, 'cardId' => 9, 'cardTitle' => 'Card', 'boardId' => 1,
			'boardTitle' => 'Board', 'state' => 'pending', 'reviewTypeId' => null,
			'requestedBy' => 'alice', 'createdAt' => 100,
		]];
		// The viewer's per-board roles scope the joined card (#3743).
		$roles = [1 => ViewerContext::ROLE_INTERNAL, 2 => ViewerContext::ROLE_EXTERNAL];
		$this->boardAccess->expects(self::once())->method('rolesFor')->willReturn($roles);
		$this->cardReviewMapper->expects(self::once())
			->method('findByReviewerInBoards')
			->with('bob', [1, 2], $roles)
			->willReturn($rows);

		self::assertSame($rows, $this->service->findMine('bob'));
	}

	public function testFindMineSkipsBoardsTheUserHasArchived(): void {
		// #10126: a review requested on an archived board leaves My reviews. The
		// mock mirrors the real BoardService - findAll() STILL carries the
		// archived board (the boards page's Archived tab is built on it), only
		// findAllActive() drops it - so reverting the service to findAll() puts
		// board 2 back in the queried set and this goes red.
		$active = $this->board(1);
		$archived = $this->board(2);
		$archived->setArchived(true);
		$this->boardService->method('findAll')->with('bob')->willReturn([$active, $archived]);
		$this->boardService->method('findAllActive')->with('bob')->willReturn([$active]);
		$roles = [1 => ViewerContext::ROLE_INTERNAL];
		$this->boardAccess->method('rolesFor')->willReturn($roles);

		$rows = [[
			'id' => 1, 'cardId' => 9, 'cardTitle' => 'Card', 'boardId' => 1,
			'boardTitle' => 'Board', 'state' => 'pending', 'reviewTypeId' => null,
			'requestedBy' => 'alice', 'createdAt' => 100,
		]];
		$this->cardReviewMapper->expects(self::once())
			->method('findByReviewerInBoards')
			->with('bob', [1], $roles)
			->willReturn($rows);

		// The identical review on the ACTIVE board still arrives - both halves
		// matter, or a filter that dropped everything would pass too.
		self::assertSame($rows, $this->service->findMine('bob'));
	}

	public function testFindMineWithNoReadableBoardsReturnsEmpty(): void {
		$this->boardService->method('findAllActive')->with('bob')->willReturn([]);
		$this->boardAccess->method('rolesFor')->willReturn([]);
		$this->cardReviewMapper->expects(self::once())
			->method('findByReviewerInBoards')
			->with('bob', [], [])
			->willReturn([]);

		self::assertSame([], $this->service->findMine('bob'));
	}
}
