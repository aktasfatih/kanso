<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\BoardSubscription;
use OCA\Kanso\Db\BoardSubscriptionMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Subscription;
use OCA\Kanso\Db\SubscriptionMapper;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\NotificationService;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\SubscriptionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SubscriptionServiceTest extends TestCase {
	private SubscriptionMapper&MockObject $subscriptionMapper;
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private PermissionService&MockObject $permissionService;
	private NotificationService&MockObject $notificationService;
	private BoardSubscriptionMapper&MockObject $boardSubscriptionMapper;
	private CardVisibilityGuard&MockObject $visibilityGuard;
	private SubscriptionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->subscriptionMapper = $this->createMock(SubscriptionMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->boardSubscriptionMapper = $this->createMock(BoardSubscriptionMapper::class);
		$this->visibilityGuard = $this->createMock(CardVisibilityGuard::class);
		$this->visibilityGuard->method('isVisible')->willReturn(true);
		// Pass-through audience filter by default; the #3760 leak tests below
		// exercise a restrictive filter explicitly.
		$this->visibilityGuard->method('filterVisible')->willReturnCallback(
			static fn (Board $b, Card $c, array $uids): array => array_values(array_unique($uids)),
		);
		$this->service = new SubscriptionService(
			$this->subscriptionMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->permissionService,
			$this->notificationService,
			$this->boardSubscriptionMapper,
			$this->visibilityGuard,
		);
	}

	private function board(int $id = 1): Board {
		$board = new Board();
		$board->setId($id);
		$board->setDeletedAt(0);
		return $board;
	}

	private function card(int $id = 9, int $boardId = 1): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId($boardId);
		$card->setDeletedAt(0);
		return $card;
	}

	private function sub(string $uid, int $cardId, int $threadId, int $state): Subscription {
		$s = new Subscription();
		$s->setSubscriber($uid);
		$s->setCardId($cardId);
		$s->setCommentThreadId($threadId);
		$s->setState($state);
		return $s;
	}

	private function expectCardLoaded(): Board {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		return $board;
	}

	// ---- getCardSubscription ---------------------------------------------

	public function testGetCardSubscriptionReportsStateAndWatchers(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'bob', PermissionService::PERMISSION_READ);
		$this->subscriptionMapper->method('findOne')->with('bob', 9, 0)
			->willReturn($this->sub('bob', 9, 0, Subscription::STATE_SUBSCRIBED));
		$this->subscriptionMapper->method('findCardSubscriberUids')->with(9)->willReturn(['bob', 'carol']);

		$result = $this->service->getCardSubscription(9, 'bob');
		self::assertTrue($result['subscribed']);
		self::assertSame(['bob', 'carol'], $result['subscribers']);
		self::assertSame(2, $result['count']);
	}

	public function testGetCardSubscriptionFalseForTombstonedUser(): void {
		$this->expectCardLoaded();
		$this->subscriptionMapper->method('findOne')->with('bob', 9, 0)
			->willReturn($this->sub('bob', 9, 0, Subscription::STATE_OPTED_OUT));
		$this->subscriptionMapper->method('findCardSubscriberUids')->with(9)->willReturn([]);

		self::assertFalse($this->service->getCardSubscription(9, 'bob')['subscribed']);
	}

	// ---- subscribe / unsubscribe -----------------------------------------

	public function testSubscribeInsertsWhenAbsent(): void {
		$this->expectCardLoaded();
		$this->subscriptionMapper->method('findOne')->willReturn(null);
		$this->subscriptionMapper->method('findCardSubscriberUids')->willReturn(['bob']);
		$this->subscriptionMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(function (Subscription $s): Subscription {
				self::assertSame('bob', $s->getSubscriber());
				self::assertSame(0, $s->getCommentThreadId());
				self::assertSame(Subscription::STATE_SUBSCRIBED, $s->getState());
				return $s;
			});

		$this->service->subscribe(9, 'bob');
	}

	public function testSubscribeFlipsTombstoneBackToSubscribed(): void {
		$this->expectCardLoaded();
		$existing = $this->sub('bob', 9, 0, Subscription::STATE_OPTED_OUT);
		$this->subscriptionMapper->method('findOne')->willReturn($existing);
		$this->subscriptionMapper->method('findCardSubscriberUids')->willReturn(['bob']);
		$this->subscriptionMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (Subscription $s): Subscription {
				self::assertSame(Subscription::STATE_SUBSCRIBED, $s->getState());
				return $s;
			});
		$this->subscriptionMapper->expects(self::never())->method('insert');

		$this->service->subscribe(9, 'bob');
	}

	public function testUnsubscribeWritesTombstone(): void {
		$this->expectCardLoaded();
		$existing = $this->sub('bob', 9, 0, Subscription::STATE_SUBSCRIBED);
		$this->subscriptionMapper->method('findOne')->willReturn($existing);
		$this->subscriptionMapper->method('findCardSubscriberUids')->willReturn([]);
		$this->subscriptionMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (Subscription $s): Subscription {
				self::assertSame(Subscription::STATE_OPTED_OUT, $s->getState());
				return $s;
			});

		self::assertFalse($this->service->unsubscribe(9, 'bob')['subscribed']);
	}

	public function testSubscribeAssertsReadPermission(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'stranger', PermissionService::PERMISSION_READ)
			->willThrowException(new NotPermittedException());
		$this->subscriptionMapper->expects(self::never())->method('insert');

		$this->expectException(NotPermittedException::class);
		$this->service->subscribe(9, 'stranger');
	}

	// ---- subscribeOther / unsubscribeOther --------------------------------

	public function testSubscribeOtherIsEditGatedAndSubscribesTarget(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'boss', PermissionService::PERMISSION_EDIT);
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')
			->willReturn(PermissionService::PERMISSION_READ);
		// findOne is called by setState (target 'bob') and buildCardSubscription (actor 'boss'); both absent.
		$this->subscriptionMapper->method('findOne')->willReturn(null);
		$this->subscriptionMapper->method('findCardSubscriberUids')->with(9)->willReturn(['bob']);
		$this->subscriptionMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(function (Subscription $s): Subscription {
				self::assertSame('bob', $s->getSubscriber());
				self::assertSame(0, $s->getCommentThreadId());
				self::assertSame(Subscription::STATE_SUBSCRIBED, $s->getState());
				return $s;
			});

		$result = $this->service->subscribeOther(9, 'bob', 'boss');
		self::assertSame(['bob'], $result['subscribers']);
		self::assertSame(1, $result['count']);
	}

	public function testSubscribeOtherRejectsActorWithoutEdit(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'peon', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->subscriptionMapper->expects(self::never())->method('insert');

		$this->expectException(NotPermittedException::class);
		$this->service->subscribeOther(9, 'bob', 'peon');
	}

	public function testSubscribeOtherRejectsTargetWithoutRead(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->method('assertPermission')
			->with($board, 'boss', PermissionService::PERMISSION_EDIT);
		$this->permissionService->method('getPermissions')
			->with($board, 'stranger')
			->willReturn(0);
		$this->subscriptionMapper->expects(self::never())->method('insert');
		$this->subscriptionMapper->expects(self::never())->method('update');

		$this->expectException(\OCA\Kanso\Service\InvalidInputException::class);
		$this->service->subscribeOther(9, 'stranger', 'boss');
	}

	public function testSubscribeOtherClearsTargetTombstone(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->method('assertPermission')
			->with($board, 'boss', PermissionService::PERMISSION_EDIT);
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')
			->willReturn(PermissionService::PERMISSION_READ);
		$existing = $this->sub('bob', 9, 0, Subscription::STATE_OPTED_OUT);
		$this->subscriptionMapper->method('findOne')->willReturn($existing);
		$this->subscriptionMapper->method('findCardSubscriberUids')->willReturn(['bob']);
		$this->subscriptionMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (Subscription $s): Subscription {
				self::assertSame(Subscription::STATE_SUBSCRIBED, $s->getState());
				return $s;
			});

		$this->service->subscribeOther(9, 'bob', 'boss');
	}

	public function testUnsubscribeOtherIsEditGatedAndTombstonesTarget(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'boss', PermissionService::PERMISSION_EDIT);
		$existing = $this->sub('bob', 9, 0, Subscription::STATE_SUBSCRIBED);
		// findOne serves both setState (target) and buildCardSubscription (actor).
		$this->subscriptionMapper->method('findOne')->willReturn($existing);
		$this->subscriptionMapper->method('findCardSubscriberUids')->with(9)->willReturn([]);
		$this->subscriptionMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (Subscription $s): Subscription {
				self::assertSame(Subscription::STATE_OPTED_OUT, $s->getState());
				return $s;
			});

		$result = $this->service->unsubscribeOther(9, 'bob', 'boss');
		self::assertSame([], $result['subscribers']);
	}

	public function testUnsubscribeOtherIsNoopForNonWatcher(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->method('assertPermission')
			->with($board, 'boss', PermissionService::PERMISSION_EDIT);
		// Target has no subscription row - do NOT plant a stale opt-out tombstone.
		$this->subscriptionMapper->method('findOne')->willReturn(null);
		$this->subscriptionMapper->method('findCardSubscriberUids')->willReturn([]);
		$this->subscriptionMapper->expects(self::never())->method('update');
		$this->subscriptionMapper->expects(self::never())->method('insert');

		$this->service->unsubscribeOther(9, 'newbie', 'boss');
	}

	public function testUnsubscribeOtherRejectsActorWithoutEdit(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'peon', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->subscriptionMapper->expects(self::never())->method('update');
		$this->subscriptionMapper->expects(self::never())->method('insert');

		$this->expectException(NotPermittedException::class);
		$this->service->unsubscribeOther(9, 'bob', 'peon');
	}

	// ---- autoSubscribe ----------------------------------------------------

	public function testAutoSubscribeInsertsWhenAbsent(): void {
		$this->subscriptionMapper->method('findOne')->with('bob', 9, 0)->willReturn(null);
		$this->subscriptionMapper->expects(self::once())->method('insert');

		$this->service->autoSubscribe(9, 0, 'bob');
	}

	public function testAutoSubscribeRespectsExistingRowIncludingTombstone(): void {
		// A tombstoned (opted-out) user must NOT be auto-resubscribed.
		$this->subscriptionMapper->method('findOne')->with('bob', 9, 0)
			->willReturn($this->sub('bob', 9, 0, Subscription::STATE_OPTED_OUT));
		$this->subscriptionMapper->expects(self::never())->method('insert');
		$this->subscriptionMapper->expects(self::never())->method('update');

		$this->service->autoSubscribe(9, 0, 'bob');
	}

	// ---- handleNewComment -------------------------------------------------

	public function testHandleNewTopLevelCommentAutoSubscribesAndNotifiesOthers(): void {
		$this->expectCardLoaded();
		$this->subscriptionMapper->method('findOne')->willReturn(null);
		// Card-level watchers: alice, bob, and the actor bob's comment shouldn't self-notify.
		$this->subscriptionMapper->method('findNotifyUids')->with(9, 0)->willReturn(['alice', 'bob', 'carol']);

		// Commenter auto-subscribes to the card (thread 0).
		$this->subscriptionMapper->expects(self::once())->method('insert');

		$notified = [];
		$this->notificationService->method('notifyCardComment')
			->willReturnCallback(function (int $cardId, string $target, string $actor) use (&$notified): void {
				$notified[] = $target;
			});

		$this->service->handleNewComment(9, null, 'bob');

		// bob (the actor) is skipped; alice and carol get notified.
		self::assertSame(['alice', 'carol'], $notified);
	}

	public function testHandleReplyAlsoAutoSubscribesToThread(): void {
		$this->subscriptionMapper->method('findOne')->willReturn(null);
		$this->subscriptionMapper->method('findNotifyUids')->with(9, 50)->willReturn(['bob']);

		// Two inserts: card-level (thread 0) and the thread (50).
		$threads = [];
		$this->subscriptionMapper->method('insert')
			->willReturnCallback(function (Subscription $s) use (&$threads): Subscription {
				$threads[] = $s->getCommentThreadId();
				return $s;
			});

		$this->service->handleNewComment(9, 50, 'bob');

		self::assertSame([0, 50], $threads);
	}

	// ---- board subscriptions ---------------------------------------------

	private function boardSub(string $uid, int $boardId): BoardSubscription {
		$s = new BoardSubscription();
		$s->setSubscriber($uid);
		$s->setBoardId($boardId);
		return $s;
	}

	private function expectBoardLoaded(): Board {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		return $board;
	}

	public function testGetBoardSubscriptionReportsStateAndWatchers(): void {
		$board = $this->expectBoardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'bob', PermissionService::PERMISSION_READ);
		$this->boardSubscriptionMapper->method('findBoardSubscriberUids')->with(1)->willReturn(['bob', 'carol']);

		$result = $this->service->getBoardSubscription(1, 'bob');
		self::assertTrue($result['subscribed']);
		self::assertSame(['bob', 'carol'], $result['subscribers']);
		self::assertSame(2, $result['count']);
	}

	public function testGetBoardSubscriptionFalseWhenNotAmongWatchers(): void {
		$this->expectBoardLoaded();
		$this->boardSubscriptionMapper->method('findBoardSubscriberUids')->with(1)->willReturn(['carol']);

		self::assertFalse($this->service->getBoardSubscription(1, 'bob')['subscribed']);
	}

	public function testSubscribeBoardInsertsWhenAbsent(): void {
		$this->expectBoardLoaded();
		$this->boardSubscriptionMapper->method('findOne')->with('bob', 1)->willReturn(null);
		$this->boardSubscriptionMapper->method('findBoardSubscriberUids')->willReturn(['bob']);
		$this->boardSubscriptionMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(function (BoardSubscription $s): BoardSubscription {
				self::assertSame('bob', $s->getSubscriber());
				self::assertSame(1, $s->getBoardId());
				return $s;
			});

		self::assertTrue($this->service->subscribeBoard(1, 'bob')['subscribed']);
	}

	public function testSubscribeBoardIsIdempotentWhenAlreadyWatching(): void {
		$this->expectBoardLoaded();
		$this->boardSubscriptionMapper->method('findOne')->with('bob', 1)->willReturn($this->boardSub('bob', 1));
		$this->boardSubscriptionMapper->method('findBoardSubscriberUids')->willReturn(['bob']);
		$this->boardSubscriptionMapper->expects(self::never())->method('insert');

		self::assertTrue($this->service->subscribeBoard(1, 'bob')['subscribed']);
	}

	public function testUnsubscribeBoardDeletesRow(): void {
		$this->expectBoardLoaded();
		$existing = $this->boardSub('bob', 1);
		$this->boardSubscriptionMapper->method('findOne')->with('bob', 1)->willReturn($existing);
		$this->boardSubscriptionMapper->method('findBoardSubscriberUids')->willReturn([]);
		$this->boardSubscriptionMapper->expects(self::once())->method('delete')->with($existing);

		self::assertFalse($this->service->unsubscribeBoard(1, 'bob')['subscribed']);
	}

	public function testUnsubscribeBoardIsIdempotentWhenAbsent(): void {
		$this->expectBoardLoaded();
		$this->boardSubscriptionMapper->method('findOne')->with('bob', 1)->willReturn(null);
		$this->boardSubscriptionMapper->method('findBoardSubscriberUids')->willReturn([]);
		$this->boardSubscriptionMapper->expects(self::never())->method('delete');

		self::assertFalse($this->service->unsubscribeBoard(1, 'bob')['subscribed']);
	}

	public function testSubscribeBoardAssertsReadPermission(): void {
		$board = $this->expectBoardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'stranger', PermissionService::PERMISSION_READ)
			->willThrowException(new NotPermittedException());
		$this->boardSubscriptionMapper->expects(self::never())->method('insert');

		$this->expectException(NotPermittedException::class);
		$this->service->subscribeBoard(1, 'stranger');
	}

	public function testNotifyBoardCardCreatedFansOutSkippingActorAndPermissionless(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->boardSubscriptionMapper->method('findBoardSubscriberUids')->with(1)
			->willReturn(['alice', 'bob', 'stranger']);

		// bob is the actor (skipped); stranger has lost READ (skipped); alice notified.
		$this->permissionService->method('assertPermission')
			->willReturnCallback(function (Board $b, string $uid): void {
				if ($uid === 'stranger') {
					throw new NotPermittedException();
				}
			});

		$notified = [];
		$this->notificationService->method('notifyBoardActivity')
			->willReturnCallback(function (int $cardId, string $target, string $actor) use (&$notified): void {
				$notified[] = $target;
			});

		$this->service->notifyBoardCardCreated(1, $this->card(42), 'bob');

		self::assertSame(['alice'], $notified);
	}

	public function testNotifyBoardCardCreatedNoWatchersIsNoop(): void {
		$this->boardSubscriptionMapper->method('findBoardSubscriberUids')->with(1)->willReturn([]);
		$this->boardMapper->expects(self::never())->method('find');
		$this->notificationService->expects(self::never())->method('notifyBoardActivity');

		$this->service->notifyBoardCardCreated(1, $this->card(42), 'bob');
	}

	// ---- visibility (#3760): fan-outs restricted to the card's audience -----

	/**
	 * A service wired with a guard that admits only $visible - the fan-outs
	 * must notify exactly the filtered set (the filter rule itself is pinned
	 * by LeakMatrixTest / the CardVisibilityGuard tests).
	 *
	 * @param string[] $visible
	 */
	private function serviceAdmittingOnly(array $visible): SubscriptionService {
		$guard = $this->createMock(CardVisibilityGuard::class);
		$guard->method('isVisible')->willReturn(true);
		$guard->method('filterVisible')->willReturnCallback(
			static fn (Board $b, Card $c, array $uids): array => array_values(array_intersect($uids, $visible)),
		);
		return new SubscriptionService(
			$this->subscriptionMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->permissionService,
			$this->notificationService,
			$this->boardSubscriptionMapper,
			$guard,
		);
	}

	public function testHandleNewCommentSkipsWatchersOutsideTheCardsVisibility(): void {
		// carol subscribed while she could see the card; it has since been
		// narrowed past her - she gets NO comment notification (existence oracle).
		$this->expectCardLoaded();
		$this->subscriptionMapper->method('findOne')->willReturn(null);
		$this->subscriptionMapper->method('findNotifyUids')->with(9, 0)->willReturn(['alice', 'carol']);

		$notified = [];
		$this->notificationService->method('notifyCardComment')
			->willReturnCallback(function (int $cardId, string $target, string $actor) use (&$notified): void {
				$notified[] = $target;
			});

		$this->serviceAdmittingOnly(['alice'])->handleNewComment(9, null, 'bob');

		self::assertSame(['alice'], $notified);
	}

	public function testNotifyBoardCardCreatedSkipsWatchersOutsideTheCardsVisibility(): void {
		// A recurrence-spawned card can inherit a narrow class: board watchers
		// outside it get no "new card" bell even though they hold READ.
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->boardSubscriptionMapper->method('findBoardSubscriberUids')->with(1)
			->willReturn(['alice', 'carol']);

		$notified = [];
		$this->notificationService->method('notifyBoardActivity')
			->willReturnCallback(function (int $cardId, string $target, string $actor) use (&$notified): void {
				$notified[] = $target;
			});

		$this->serviceAdmittingOnly(['alice'])->notifyBoardCardCreated(1, $this->card(42), 'bob');

		self::assertSame(['alice'], $notified);
	}
}
