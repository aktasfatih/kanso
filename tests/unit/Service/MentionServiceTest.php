<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\NotAMemberException;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\SubscriptionMapper;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\CardVisibilityScope;
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
	private BoardAccess&MockObject $boardAccess;
	private MentionService $service;

	/**
	 * The mentioned users' resolved roles, consumed by the REAL
	 * CardVisibilityGuard + CardVisibilityScope pair (#3760 leak tests
	 * exercise the actual visibility rule, not a stub).
	 *
	 * @var array<string, string>
	 */
	private array $rolesOnBoard = [];

	protected function setUp(): void {
		parent::setUp();
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->subscriptionService = $this->createMock(SubscriptionService::class);
		$this->notificationService = $this->createMock(NotificationService::class);
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
		$this->service = new MentionService(
			$this->permissionService,
			$this->subscriptionService,
			$this->notificationService,
			new CardVisibilityGuard($this->boardAccess, new CardVisibilityScope()),
		);
	}

	private function board(int $id = 1): Board {
		$board = new Board();
		$board->setId($id);
		$board->setDeletedAt(0);
		return $board;
	}

	private function card(int $id = 9, ?string $visibility = null, ?string $creatorRole = null, string $owner = 'alice'): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId(1);
		if ($visibility !== null) {
			$card->setVisibility($visibility);
		}
		if ($creatorRole !== null) {
			$card->setCreatorRole($creatorRole);
		}
		$card->setOwner($owner);
		return $card;
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

	// ---- mention bound ----------------------------------------------------
	//
	// Each distinct mention costs one uncached ACL resolution, so without a cap
	// the mention count in a body - not the board's size - decides how many
	// queries a single write runs. The bound lives in extractUsernames() so BOTH
	// mention surfaces get it: a card description (separately length-capped) and
	// a comment body, which at its own 10,000-char limit still holds ~3,300
	// distinct `@u1` tokens.

	public function testExtractCapsTheNumberOfDistinctMentions(): void {
		$body = implode(' ', array_map(static fn (int $i): string => '@u' . $i, range(1, 5000)));
		$extracted = $this->service->extractUsernames($body);

		self::assertCount(MentionService::MAX_MENTIONS, $extracted);
		// First-N, in document order - the ones a human actually typed first.
		self::assertSame('u1', $extracted[0]);
		self::assertSame('u' . MentionService::MAX_MENTIONS, $extracted[MentionService::MAX_MENTIONS - 1]);
	}

	public function testExtractCapCountsDistinctUsersNotRepeats(): void {
		// Dedup happens before the cap, so repeating one name does not consume
		// the budget and push a real second mention out.
		$body = str_repeat('@alice ', 500) . '@bob';
		self::assertSame(['alice', 'bob'], $this->service->extractUsernames($body));
	}

	public function testHandleMentionsIssuesABoundedNumberOfPermissionLookups(): void {
		$board = $this->board();
		$lookups = 0;
		$this->permissionService->method('getPermissions')
			->willReturnCallback(function (Board $b, string $uid) use (&$lookups): int {
				$lookups++;
				return 0; // nobody is a member: isolate the query count itself
			});

		$body = implode(' ', array_map(static fn (int $i): string => '@u' . $i, range(1, 5000)));
		$this->service->handleMentions($this->card(), $board, $body, 'alice');

		// 5,000 distinct mentions, at most MAX_MENTIONS ACL resolutions.
		self::assertSame(MentionService::MAX_MENTIONS, $lookups);
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

		$this->service->handleMentions($this->card(), $board, 'hey @bob look', 'alice');
	}

	public function testHandleMentionsIsInertForNonMember(): void {
		$board = $this->board();
		$this->permissionService->method('getPermissions')
			->with($board, 'stranger')
			->willReturn(0);

		$this->subscriptionService->expects(self::never())->method('autoSubscribe');
		$this->notificationService->expects(self::never())->method('notifyCardMentioned');

		$this->service->handleMentions($this->card(), $board, 'hi @stranger', 'alice');
	}

	public function testHandleMentionsSkipsSelfMention(): void {
		$board = $this->board();
		// Self is never resolved/notified - short-circuited before the permission check.
		$this->permissionService->expects(self::never())->method('getPermissions');
		$this->subscriptionService->expects(self::never())->method('autoSubscribe');
		$this->notificationService->expects(self::never())->method('notifyCardMentioned');

		$this->service->handleMentions($this->card(), $board, 'note to @alice self', 'alice');
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

		$this->service->handleMentions($this->card(), $board, '@bob @stranger please look', 'alice');

		self::assertSame(['bob'], $subscribed);
		self::assertSame(['bob'], $notified);
	}

	// ---- visibility (#3760): mentions inside a hidden card are inert --------

	public function testMentionInsideHiddenCardIsInertForExcludedViewer(): void {
		// exty holds READ on the board but sits outside the provider-internal
		// card's visibility: no bell, no watch row - either would be an
		// existence oracle for a card they cannot open. inty (same side) is
		// still pinged.
		$board = $this->board();
		$card = $this->card(9, CardVisibilityScope::VISIBILITY_INTERNAL, ViewerContext::ROLE_INTERNAL);
		$this->permissionService->method('getPermissions')->willReturn(PermissionService::PERMISSION_READ);
		$this->rolesOnBoard = [
			'inty' => ViewerContext::ROLE_INTERNAL,
			'exty' => ViewerContext::ROLE_EXTERNAL,
		];

		$this->subscriptionService->expects(self::once())
			->method('autoSubscribe')
			->with(9, SubscriptionMapper::THREAD_CARD, 'inty');
		$this->notificationService->expects(self::once())
			->method('notifyCardMentioned')
			->with(9, 'inty', 'alice');

		$this->service->handleMentions($card, $board, 'cc @inty @exty', 'alice');
	}

	public function testMentionInsidePrivateCardOnlyReachesItsOwner(): void {
		$board = $this->board();
		$card = $this->card(9, CardVisibilityScope::VISIBILITY_PRIVATE, ViewerContext::ROLE_INTERNAL, 'owner');
		$this->permissionService->method('getPermissions')->willReturn(PermissionService::PERMISSION_READ);
		$this->rolesOnBoard = [
			'owner' => ViewerContext::ROLE_INTERNAL,
			'mgr' => ViewerContext::ROLE_INTERNAL, // manager is NOT a backdoor
		];

		$this->subscriptionService->expects(self::once())
			->method('autoSubscribe')
			->with(9, SubscriptionMapper::THREAD_CARD, 'owner');
		$this->notificationService->expects(self::once())
			->method('notifyCardMentioned')
			->with(9, 'owner', 'alice');

		$this->service->handleMentions($card, $board, 'cc @owner @mgr', 'alice');
	}
}
