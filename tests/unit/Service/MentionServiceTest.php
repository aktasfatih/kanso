<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\SubscriptionMapper;
use OCA\Kanso\Service\MentionService;
use OCA\Kanso\Service\NotificationService;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\SubscriptionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MentionServiceTest extends TestCase {
	private PermissionService&MockObject $permissionService;
	private SubscriptionService&MockObject $subscriptionService;
	private NotificationService&MockObject $notificationService;
	private MentionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->subscriptionService = $this->createMock(SubscriptionService::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->service = new MentionService(
			$this->permissionService,
			$this->subscriptionService,
			$this->notificationService,
		);
	}

	private function board(int $id = 1): Board {
		$board = new Board();
		$board->setId($id);
		$board->setDeletedAt(0);
		return $board;
	}

	// ---- extractUsernames (pure) -----------------------------------------

	public function testExtractReturnsUniqueUsernamesInOrder(): void {
		$body = 'ping @alice and @bob, then @alice again';
		self::assertSame(['alice', 'bob'], $this->service->extractUsernames($body));
	}

	public function testExtractIgnoresEmailStyleAtSigns(): void {
		// foo@bar is an email fragment, not a mention (@ preceded by a word char).
		self::assertSame([], $this->service->extractUsernames('mail me at foo@bar.com'));
	}

	public function testExtractMatchesAtStartAndAfterPunctuation(): void {
		self::assertSame(['carol'], $this->service->extractUsernames('@carol hi'));
		self::assertSame(['dave'], $this->service->extractUsernames('(cc: @dave)'));
	}

	public function testExtractAllowsDotDashUnderscore(): void {
		self::assertSame(['a.b-c_d'], $this->service->extractUsernames('hey @a.b-c_d'));
	}

	public function testExtractEmptyWhenNoMentions(): void {
		self::assertSame([], $this->service->extractUsernames('no mentions here'));
	}

	// ---- handleMentions (authz + side effects) ---------------------------

	public function testHandleMentionsSubscribesAndNotifiesReadableParticipant(): void {
		$board = $this->board();
		$this->permissionService->method('getPermissions')
			->with($board, 'bob')
			->willReturn(PermissionService::PERMISSION_READ);

		$this->subscriptionService->expects(self::once())
			->method('autoSubscribe')
			->with(9, SubscriptionMapper::THREAD_CARD, 'bob');
		$this->notificationService->expects(self::once())
			->method('notifyCardMentioned')
			->with(9, 'bob', 'alice');

		$this->service->handleMentions(9, $board, 'hey @bob look', 'alice');
	}

	public function testHandleMentionsIsInertForNonMember(): void {
		$board = $this->board();
		$this->permissionService->method('getPermissions')
			->with($board, 'stranger')
			->willReturn(0);

		$this->subscriptionService->expects(self::never())->method('autoSubscribe');
		$this->notificationService->expects(self::never())->method('notifyCardMentioned');

		$this->service->handleMentions(9, $board, 'hi @stranger', 'alice');
	}

	public function testHandleMentionsSkipsSelfMention(): void {
		$board = $this->board();
		// Self is never resolved/notified - short-circuited before the permission check.
		$this->permissionService->expects(self::never())->method('getPermissions');
		$this->subscriptionService->expects(self::never())->method('autoSubscribe');
		$this->notificationService->expects(self::never())->method('notifyCardMentioned');

		$this->service->handleMentions(9, $board, 'note to @alice self', 'alice');
	}

	public function testHandleMentionsMixedMembershipOnlyActsOnReadable(): void {
		$board = $this->board();
		$this->permissionService->method('getPermissions')
			->willReturnCallback(function (Board $b, string $uid): int {
				return $uid === 'bob' ? PermissionService::PERMISSION_READ : 0;
			});

		$subscribed = [];
		$this->subscriptionService->method('autoSubscribe')
			->willReturnCallback(function (int $cardId, int $thread, string $uid) use (&$subscribed): void {
				$subscribed[] = $uid;
			});
		$notified = [];
		$this->notificationService->method('notifyCardMentioned')
			->willReturnCallback(function (int $cardId, string $target, string $actor) use (&$notified): void {
				$notified[] = $target;
			});

		$this->service->handleMentions(9, $board, '@bob @stranger please look', 'alice');

		self::assertSame(['bob'], $subscribed);
		self::assertSame(['bob'], $notified);
	}
}
