<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Subscription;
use OCA\Kanso\Db\SubscriptionMapper;
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
	private SubscriptionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->subscriptionMapper = $this->createMock(SubscriptionMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->service = new SubscriptionService(
			$this->subscriptionMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->permissionService,
			$this->notificationService,
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
}
