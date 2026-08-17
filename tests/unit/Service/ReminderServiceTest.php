<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\NotAMemberException;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Comment;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\Reminder;
use OCA\Kanso\Db\ReminderMapper;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\CardVisibilityScope;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotificationService;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\ReminderService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReminderServiceTest extends TestCase {
	private const NOW = 1_800_000_000;

	private ReminderMapper&MockObject $reminderMapper;
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private CommentMapper&MockObject $commentMapper;
	private PermissionService&MockObject $permissionService;
	private NotificationService&MockObject $notificationService;
	private BoardAccess&MockObject $boardAccess;
	private ITimeFactory&MockObject $time;
	private LoggerInterface&MockObject $logger;
	private ReminderService $service;

	/**
	 * The audience's resolved roles on board 1, consumed by the REAL
	 * CardVisibilityGuard + CardVisibilityScope pair the service is wired with -
	 * so the fire-time leak test exercises the actual visibility rule.
	 *
	 * @var array<string, string>
	 */
	private array $rolesOnBoard = [];

	protected function setUp(): void {
		parent::setUp();
		$this->reminderMapper = $this->createMock(ReminderMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->commentMapper = $this->createMock(CommentMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->notificationService = $this->createMock(NotificationService::class);

		$board = new Board();
		$board->setId(1);
		$board->setOwner('board-owner');
		$this->boardMapper->method('find')->with(1)->willReturn($board);

		$this->boardAccess = $this->createMock(BoardAccess::class);
		$this->boardAccess->method('rolesOn')->willReturnCallback(
			fn (Board $b, array $uids): array => array_intersect_key($this->rolesOnBoard, array_flip($uids)),
		);
		$this->boardAccess->method('contextFor')->willReturnCallback(
			function (Board $b, string $uid): ViewerContext {
				$role = $this->rolesOnBoard[$uid] ?? null;
				if ($role === null) {
					throw new NotAMemberException('not a member');
				}
				return ViewerContext::forMember($uid, $b->getId(), $role, false);
			},
		);

		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(self::NOW);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new ReminderService(
			$this->reminderMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->commentMapper,
			$this->permissionService,
			$this->notificationService,
			new CardVisibilityGuard($this->boardAccess, new CardVisibilityScope()),
			$this->time,
			$this->logger,
		);
	}

	private function card(int $id = 10): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId(1);
		$card->setStackId(5);
		$card->setDeletedAt(0);
		return $card;
	}

	private function reminder(int $id, string $uid, int $cardId, int $remindAt, ?int $commentId = null, ?int $firedAt = null): Reminder {
		$r = new Reminder();
		$r->setId($id);
		$r->setUserId($uid);
		$r->setCardId($cardId);
		$r->setCommentId($commentId);
		$r->setRemindAt($remindAt);
		$r->setFiredAt($firedAt);
		$r->setCreatedAt(self::NOW - 100);
		return $r;
	}

	// ---- scheduling --------------------------------------------------------

	public function testScheduleInsertsAFutureReminder(): void {
		$this->cardMapper->method('find')->with(10)->willReturn($this->card());
		$this->rolesOnBoard = ['alice' => ViewerContext::ROLE_INTERNAL];

		$this->reminderMapper->expects(self::once())
			->method('insertReminder')
			->with('alice', 10, null, self::NOW + 3600)
			->willReturn($this->reminder(1, 'alice', 10, self::NOW + 3600));

		$out = $this->service->schedule(10, 'alice', self::NOW + 3600, null);
		self::assertSame(1, $out->getId());
	}

	public function testScheduleWithCommentValidatesItBelongsToTheCard(): void {
		$this->cardMapper->method('find')->with(10)->willReturn($this->card());
		$this->rolesOnBoard = ['alice' => ViewerContext::ROLE_INTERNAL];

		$comment = new Comment();
		$comment->setId(77);
		$comment->setCardId(10);
		$this->commentMapper->method('find')->with(77)->willReturn($comment);

		$this->reminderMapper->expects(self::once())
			->method('insertReminder')
			->with('alice', 10, 77, self::NOW + 60)
			->willReturn($this->reminder(1, 'alice', 10, self::NOW + 60, 77));

		$this->service->schedule(10, 'alice', self::NOW + 60, 77);
	}

	public function testScheduleRejectsACommentOnAnotherCard(): void {
		$this->cardMapper->method('find')->with(10)->willReturn($this->card());
		$this->rolesOnBoard = ['alice' => ViewerContext::ROLE_INTERNAL];

		$comment = new Comment();
		$comment->setId(77);
		$comment->setCardId(999);
		$this->commentMapper->method('find')->with(77)->willReturn($comment);

		$this->reminderMapper->expects(self::never())->method('insertReminder');
		$this->expectException(InvalidInputException::class);
		$this->service->schedule(10, 'alice', self::NOW + 60, 77);
	}

	public function testSchedulePastTimeIsRejected(): void {
		$this->cardMapper->method('find')->with(10)->willReturn($this->card());
		$this->rolesOnBoard = ['alice' => ViewerContext::ROLE_INTERNAL];

		$this->reminderMapper->expects(self::never())->method('insertReminder');
		$this->expectException(InvalidInputException::class);
		$this->service->schedule(10, 'alice', self::NOW - 1, null);
	}

	public function testScheduleDeniedWhenBoardPermissionFails(): void {
		$this->cardMapper->method('find')->with(10)->willReturn($this->card());
		$this->permissionService->method('assertPermission')
			->willThrowException(new \OCA\Kanso\Service\NotPermittedException('nope'));

		$this->reminderMapper->expects(self::never())->method('insertReminder');
		$this->expectException(\OCA\Kanso\Service\NotPermittedException::class);
		$this->service->schedule(10, 'alice', self::NOW + 60, null);
	}

	public function testScheduleDeniedForACardHiddenFromTheActor(): void {
		// A private card owned by someone else 404s for the actor - even a
		// board member cannot set a reminder on a card they cannot see.
		$card = $this->card();
		$card->setVisibility(CardVisibilityScope::VISIBILITY_PRIVATE);
		$card->setCreatorRole(ViewerContext::ROLE_INTERNAL);
		$card->setOwner('someone-else');
		$this->cardMapper->method('find')->with(10)->willReturn($card);
		$this->rolesOnBoard = ['alice' => ViewerContext::ROLE_INTERNAL];

		$this->reminderMapper->expects(self::never())->method('insertReminder');
		$this->expectException(DoesNotExistException::class);
		$this->service->schedule(10, 'alice', self::NOW + 60, null);
	}

	// ---- cancel ------------------------------------------------------------

	public function testCancelDeletesOwnReminder(): void {
		$this->cardMapper->method('find')->with(10)->willReturn($this->card());
		$this->rolesOnBoard = ['alice' => ViewerContext::ROLE_INTERNAL];
		$reminder = $this->reminder(5, 'alice', 10, self::NOW + 3600);
		$this->reminderMapper->method('findById')->with(5)->willReturn($reminder);

		$this->reminderMapper->expects(self::once())->method('delete')->with($reminder);
		$this->service->cancel(10, 5, 'alice');
	}

	public function testCancelIsNoOpForAnotherUsersReminder(): void {
		$this->cardMapper->method('find')->with(10)->willReturn($this->card());
		$this->rolesOnBoard = ['alice' => ViewerContext::ROLE_INTERNAL, 'bob' => ViewerContext::ROLE_INTERNAL];
		$reminder = $this->reminder(5, 'bob', 10, self::NOW + 3600);
		$this->reminderMapper->method('findById')->with(5)->willReturn($reminder);

		$this->reminderMapper->expects(self::never())->method('delete');
		$this->service->cancel(10, 5, 'alice');
	}

	public function testCancelIsNoOpForAnUnknownReminder(): void {
		$this->cardMapper->method('find')->with(10)->willReturn($this->card());
		$this->rolesOnBoard = ['alice' => ViewerContext::ROLE_INTERNAL];
		$this->reminderMapper->method('findById')->with(5)->willReturn(null);

		$this->reminderMapper->expects(self::never())->method('delete');
		$this->service->cancel(10, 5, 'alice');
	}

	// ---- firing ------------------------------------------------------------

	public function testFireDueNotifiesTheSetterOnceAndStamps(): void {
		$this->rolesOnBoard = ['alice' => ViewerContext::ROLE_INTERNAL];
		$reminder = $this->reminder(5, 'alice', 10, self::NOW - 60, 77);
		$this->reminderMapper->method('findDue')->willReturn([$reminder]);
		$this->cardMapper->method('find')->with(10)->willReturn($this->card());

		$this->notificationService->expects(self::once())
			->method('notifyCardReminder')->with(10, 'alice', 77);
		$this->reminderMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (Reminder $r): Reminder {
				self::assertSame(self::NOW, $r->getFiredAt());
				return $r;
			});

		self::assertSame(1, $this->service->fireDue());
	}

	public function testFireDueCatchesUpOverdueReminders(): void {
		// A reminder whose time passed hours ago (the cron was late) still fires
		// on the next run - findDue returns it (remind_at <= now, fired_at null).
		$this->rolesOnBoard = ['alice' => ViewerContext::ROLE_INTERNAL];
		$overdue = $this->reminder(5, 'alice', 10, self::NOW - 86400);
		$this->reminderMapper->method('findDue')->willReturn([$overdue]);
		$this->cardMapper->method('find')->with(10)->willReturn($this->card());

		$this->notificationService->expects(self::once())
			->method('notifyCardReminder')->with(10, 'alice', null);
		$this->reminderMapper->expects(self::once())->method('update');

		self::assertSame(1, $this->service->fireDue());
	}

	public function testAlreadyFiredReminderIsNeverReturnedSoNoDoubleFire(): void {
		// findDue filters fired_at IS NULL, so a stamped reminder is absent - the
		// run is a no-op (idempotency lives in the query + the stamp together).
		$this->reminderMapper->method('findDue')->willReturn([]);
		$this->notificationService->expects(self::never())->method('notifyCardReminder');
		self::assertSame(0, $this->service->fireDue());
	}

	public function testFireDueStampsButDoesNotNotifyWhenCardWasPurged(): void {
		$reminder = $this->reminder(5, 'alice', 10, self::NOW - 60);
		$this->reminderMapper->method('findDue')->willReturn([$reminder]);
		$this->cardMapper->method('find')->with(10)
			->willThrowException(new DoesNotExistException('gone'));

		$this->notificationService->expects(self::never())->method('notifyCardReminder');
		// Consumed anyway: stamped so it is not retried forever.
		$this->reminderMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (Reminder $r): Reminder {
				self::assertSame(self::NOW, $r->getFiredAt());
				return $r;
			});

		self::assertSame(0, $this->service->fireDue());
	}

	public function testOneFailingReminderIsLoggedAndDoesNotAbortRun(): void {
		$this->rolesOnBoard = ['alice' => ViewerContext::ROLE_INTERNAL];
		$bad = $this->reminder(5, 'alice', 10, self::NOW - 60);
		$good = $this->reminder(6, 'alice', 11, self::NOW - 60);
		$this->reminderMapper->method('findDue')->willReturn([$bad, $good]);
		$this->cardMapper->method('find')->willReturnCallback(function (int $id): Card {
			if ($id === 10) {
				throw new \RuntimeException('card lookup blew up');
			}
			return $this->card(11);
		});

		$this->logger->expects(self::once())->method('warning');
		$this->notificationService->expects(self::once())
			->method('notifyCardReminder')->with(11, 'alice', null);

		self::assertSame(1, $this->service->fireDue());
	}

	// ---- visibility-at-fire-time denial (#3761 pattern) --------------------

	public function testFireDueSkipsNotifyWhenCardNarrowedPastTheSetter(): void {
		// The setter scheduled the reminder while they could see the card; by
		// fire time the card turned PRIVATE and owned by someone else. Firing
		// must NOT deliver a bell (it would name a card they can no longer see),
		// but the reminder is consumed (stamped) so it is not retried.
		$card = $this->card();
		$card->setVisibility(CardVisibilityScope::VISIBILITY_PRIVATE);
		$card->setCreatorRole(ViewerContext::ROLE_INTERNAL);
		$card->setOwner('someone-else');
		$this->rolesOnBoard = [
			'alice' => ViewerContext::ROLE_INTERNAL,
			'someone-else' => ViewerContext::ROLE_INTERNAL,
		];
		$reminder = $this->reminder(5, 'alice', 10, self::NOW - 60);
		$this->reminderMapper->method('findDue')->willReturn([$reminder]);
		$this->cardMapper->method('find')->with(10)->willReturn($card);

		$this->notificationService->expects(self::never())->method('notifyCardReminder');
		$this->reminderMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (Reminder $r): Reminder {
				self::assertSame(self::NOW, $r->getFiredAt());
				return $r;
			});

		self::assertSame(0, $this->service->fireDue());
	}
}
