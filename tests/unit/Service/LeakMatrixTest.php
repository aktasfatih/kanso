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
use OCA\Kanso\Db\CardReview;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ReviewTypeMapper;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\CardVisibilityScope;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\CommentService;
use OCA\Kanso\Service\NotificationService;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\ReviewService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * The leak matrix (#3743): EVERY viewer class × EVERY card class, asserted as
 * one exhaustive truth table against the single visibility decision point
 * ({@see CardVisibilityScope::isVisibleTo()}) - the same rule every SQL read
 * path binds via apply()/applyForViewer() and every card-id-addressed
 * endpoint enforces via {@see CardVisibilityGuard}.
 *
 * The matrix is written out in FULL (no derivation in the test): if anyone
 * changes the rule, the diff of this table is the review artifact. Counts are
 * asserted too - a count is a leak surface of its own.
 *
 * The endpoint-level counterpart (real HTTP × real SQL, N users × board /
 * changes / search / my-cards / trash / stats / export / public share) lives
 * in tests/e2e/visibility-leak-matrix.spec.js.
 */
class LeakMatrixTest extends TestCase {
	private const BOARD_ID = 7;

	private CardVisibilityScope $scope;

	protected function setUp(): void {
		parent::setUp();
		$this->scope = new CardVisibilityScope();
	}

	/**
	 * The card fixture: one card per (visibility, creator side, owner) class.
	 *
	 * @return array<string, Card>
	 */
	private function cards(): array {
		$make = static function (string $visibility, string $creatorRole, string $owner): Card {
			$card = new Card();
			$card->setVisibility($visibility);
			$card->setCreatorRole($creatorRole);
			$card->setOwner($owner);
			return $card;
		};

		return [
			'public' => $make('public', 'internal', 'inty'),
			'internal-provider' => $make('internal', 'internal', 'inty'),
			'internal-client' => $make('internal', 'external', 'exty'),
			'private-of-inty' => $make('private', 'internal', 'inty'),
			'private-of-exty' => $make('private', 'external', 'exty'),
		];
	}

	/**
	 * The viewer fixture: uid => resolved role on the board (null = the
	 * membership could not be resolved - a non-member, or a revoked one).
	 *
	 * @return array<string, ?string>
	 */
	private function viewers(): array {
		return [
			'mgr' => ViewerContext::ROLE_INTERNAL,   // internal manager - NO backdoor
			'inty' => ViewerContext::ROLE_INTERNAL,  // internal member, owns some cards
			'exty' => ViewerContext::ROLE_EXTERNAL,  // external member, owns some cards
			'other-ext' => ViewerContext::ROLE_EXTERNAL, // external member owning nothing
			'spy' => null,                            // no resolvable membership
		];
	}

	/**
	 * THE MATRIX. Every cell spelled out; no clever derivation.
	 *
	 * @return array<string, array<string, bool>> viewer => card => visible?
	 */
	private function expectedMatrix(): array {
		return [
			'mgr' => [
				'public' => true,
				'internal-provider' => true,
				'internal-client' => false, // manager is NOT a backdoor across the fence
				'private-of-inty' => false, // nor into someone else's private card
				'private-of-exty' => false,
			],
			'inty' => [
				'public' => true,
				'internal-provider' => true,
				'internal-client' => false,
				'private-of-inty' => true,  // their own private card
				'private-of-exty' => false,
			],
			'exty' => [
				'public' => true,
				'internal-provider' => false,
				'internal-client' => true,  // symmetric: the client side has internals too
				'private-of-inty' => false,
				'private-of-exty' => true,  // their own private card
			],
			'other-ext' => [
				'public' => true,
				'internal-provider' => false,
				'internal-client' => true,  // same side as the creator
				'private-of-inty' => false,
				'private-of-exty' => false, // private is per-PERSON, not per-side
			],
			'spy' => [
				'public' => true,            // board access itself is gated elsewhere
				'internal-provider' => false, // no role → the internal branch drops
				'internal-client' => false,
				'private-of-inty' => false,
				'private-of-exty' => false,
			],
		];
	}

	public function testEveryViewerCardCellMatchesTheMatrix(): void {
		$cards = $this->cards();
		$expected = $this->expectedMatrix();

		foreach ($this->viewers() as $uid => $role) {
			foreach ($cards as $cardName => $card) {
				self::assertSame(
					$expected[$uid][$cardName],
					$this->scope->isVisibleTo($card, $uid, $role),
					sprintf('leak matrix cell [%s × %s]', $uid, $cardName),
				);
			}
		}
	}

	public function testVisibleCountsPerViewerMatchTheMatrix(): void {
		// Counts leak too: the number of visible fixture cards per viewer must
		// equal the matrix row sum - the same figure every scoped aggregate
		// (board stats, boards-list signals, child progress) must produce.
		$cards = $this->cards();

		foreach ($this->viewers() as $uid => $role) {
			$expectedCount = count(array_filter($this->expectedMatrix()[$uid]));
			$actualCount = count(array_filter(
				$cards,
				fn (Card $card): bool => $this->scope->isVisibleTo($card, $uid, $role),
			));
			self::assertSame($expectedCount, $actualCount, 'visible count for ' . $uid);
		}
	}

	public function testGuardMapsEveryHiddenCellToNotFoundNeverForbidden(): void {
		// The single-card gate: every hidden (viewer, card) pair must surface
		// as DoesNotExistException (→404, like a missing id) - never a 403,
		// which would confirm the id exists (an existence oracle).
		$board = new Board();
		$board->setOwner('board-owner');

		foreach ($this->viewers() as $uid => $role) {
			$guard = $this->guardFor($uid, $role);
			foreach ($this->cards() as $cardName => $card) {
				if ($this->expectedMatrix()[$uid][$cardName]) {
					$guard->assertVisible($board, $card, $uid); // must not throw
					continue;
				}
				try {
					$guard->assertVisible($board, $card, $uid);
					self::fail(sprintf('hidden cell [%s × %s] did not throw', $uid, $cardName));
				} catch (DoesNotExistException) {
					// Exactly the not-found a missing card id raises.
				}
			}
		}
		// One assertion per no-throw path already ran; keep PHPUnit satisfied.
		self::assertTrue(true);
	}

	// ---- background paths (#3760) ------------------------------------------
	//
	// The off-request emissions - due reminders, comment/mention fan-outs,
	// board-watcher notifications and the Activity audience - all route their
	// recipient set through ONE choke point: CardVisibilityGuard::filterVisible()
	// (batched BoardAccess::rolesOn + the same isVisibleTo the matrix pins).
	// These cells assert that for every card class, the audience that receives
	// an artifact is EXACTLY the matrix column - a viewer outside the card's
	// visibility gets no notification/activity artifact. The per-service
	// wiring is covered in each service's own test; the RULE is pinned here.
	// (Webhook egress has no viewer at all and is public-only - see
	// testExternalEgressIsPublicOnly below. The render-time bell gate reuses
	// isVisible(), already covered by the guard cells above.)

	public function testBackgroundAudienceFilterMatchesTheMatrixColumns(): void {
		$board = new Board();
		$board->setId(self::BOARD_ID);
		$board->setOwner('board-owner');
		$allViewers = array_keys($this->viewers());

		foreach ($this->cards() as $cardName => $card) {
			$expectedAudience = array_values(array_keys(array_filter(
				array_map(
					fn (string $uid): bool => $this->expectedMatrix()[$uid][$cardName],
					array_combine($allViewers, $allViewers),
				),
			)));

			$guard = new CardVisibilityGuard($this->boardAccessResolvingFixtureRoles(), $this->scope);
			self::assertSame(
				$expectedAudience,
				$guard->filterVisible($board, $card, $allViewers),
				'background audience for card class [' . $cardName . ']',
			);
		}
	}

	public function testBackgroundAudienceFilterIsBatchedAndSkipsAclForPublicCards(): void {
		$board = new Board();
		$board->setId(self::BOARD_ID);
		$board->setOwner('board-owner');

		// Public card: every candidate passes WITHOUT any role resolution -
		// and duplicates collapse.
		$boardAccess = $this->createMock(BoardAccess::class);
		$boardAccess->expects(self::never())->method('rolesOn');
		$guard = new CardVisibilityGuard($boardAccess, $this->scope);
		self::assertSame(
			['mgr', 'spy'],
			$guard->filterVisible($board, $this->cards()['public'], ['mgr', 'spy', 'mgr']),
		);

		// Non-public card: exactly ONE batched rolesOn call for the whole
		// candidate set - never per-recipient resolution (cron-scale fan-outs).
		$boardAccess = $this->createMock(BoardAccess::class);
		$boardAccess->expects(self::once())
			->method('rolesOn')
			->willReturn(['inty' => ViewerContext::ROLE_INTERNAL, 'exty' => ViewerContext::ROLE_EXTERNAL]);
		$guard = new CardVisibilityGuard($boardAccess, $this->scope);
		self::assertSame(
			['inty'],
			$guard->filterVisible($board, $this->cards()['internal-provider'], ['inty', 'exty', 'spy']),
		);
	}

	public function testExternalEgressIsPublicOnly(): void {
		// Emissions with NO viewer (the webhook response body): only the
		// 'public' class may ever be named - every narrower class, a legacy
		// NULL row (reads public, matching the backfill) and an unknown value
		// (fails closed) behave exactly like applyPublicOnly() in SQL.
		$expected = [
			'public' => true,
			'internal-provider' => false,
			'internal-client' => false,
			'private-of-inty' => false,
			'private-of-exty' => false,
		];
		foreach ($this->cards() as $cardName => $card) {
			self::assertSame(
				$expected[$cardName],
				$this->scope->isPublic($card),
				'egress cell [' . $cardName . ']',
			);
		}

		$legacy = new Card();
		$legacy->setOwner('anyone');
		self::assertTrue($this->scope->isPublic($legacy));

		$bogus = new Card();
		$bogus->setVisibility('everyone');
		self::assertFalse($this->scope->isPublic($bogus));
	}

	public function testDeferredReviewFireRespectsTheMatrixColumn(): void {
		// #3761: a stage-gated review is requested while its reviewer can SEE
		// the card, then the card narrows before the blocking review approves.
		// The deferred fire in ReviewService::fireDeferredNotifications() must
		// re-check THE rule per reviewer: for every viewer × card cell, the
		// un-gating approval delivers the deferred notification (stamping
		// notified_at) iff the matrix says visible - a hidden reviewer is
		// skipped WITHOUT the stamp, so a later widening can still deliver.
		foreach ($this->cards() as $cardName => $fixtureCard) {
			foreach ($this->viewers() as $reviewerUid => $role) {
				$expected = $this->expectedMatrix()[$reviewerUid][$cardName];

				$card = clone $fixtureCard;
				$card->setId(9);
				$card->setBoardId(self::BOARD_ID);
				$card->setDeletedAt(0);
				$board = new Board();
				$board->setId(self::BOARD_ID);
				$board->setOwner('board-owner');
				$board->setDeletedAt(0);
				// The un-gating actor is the card's own creator - visible to
				// themselves in every card class, whatever the class hides.
				$actor = (string)$card->getOwner();

				$blocker = new CardReview();
				$blocker->setId(1);
				$blocker->setCardId(9);
				$blocker->setReviewer($actor);
				$blocker->setState(CardReview::STATE_PENDING);
				$blocker->setRequestedBy('requester');
				$blocker->setReviewTypeId(1); // stage 0
				$blocker->setNotifiedAt(100); // already notified at request time
				$deferred = new CardReview();
				$deferred->setId(2);
				$deferred->setCardId(9);
				$deferred->setReviewer($reviewerUid);
				$deferred->setState(CardReview::STATE_PENDING);
				$deferred->setRequestedBy('requester');
				$deferred->setReviewTypeId(2); // stage 1, gated behind the blocker
				$deferred->setNotifiedAt(null);

				$cardMapper = $this->createMock(CardMapper::class);
				$cardMapper->method('find')->with(9)->willReturn($card);
				$boardMapper = $this->createMock(BoardMapper::class);
				$boardMapper->method('find')->with(self::BOARD_ID)->willReturn($board);
				$changeNotifier = $this->createMock(ChangeNotifier::class);
				$changeNotifier->method('notify')->willReturn(new Change());
				$permissionService = $this->createMock(PermissionService::class);
				$permissionService->method('getPermissions')->willReturn(PermissionService::PERMISSION_READ);
				$reviewTypeMapper = $this->createMock(ReviewTypeMapper::class);
				$reviewTypeMapper->method('stageMapForBoard')->with(self::BOARD_ID)->willReturn([1 => 0, 2 => 1]);
				$cardReviewMapper = $this->createMock(CardReviewMapper::class);
				$cardReviewMapper->method('findById')->with(1)->willReturn($blocker);
				$cardReviewMapper->method('findByCard')->with(9)->willReturn([$blocker, $deferred]);

				$notificationService = $this->createMock(NotificationService::class);
				$notificationService->expects($expected ? self::once() : self::never())
					->method('notifyReviewRequested')
					->with(9, $reviewerUid, 'requester');

				$service = new ReviewService(
					$cardReviewMapper,
					$cardMapper,
					$boardMapper,
					$changeNotifier,
					$permissionService,
					$notificationService,
					$reviewTypeMapper,
					$this->createMock(BoardService::class),
					$this->createMock(CommentService::class),
					$this->boardAccessResolvingFixtureContexts(),
					new CardVisibilityGuard($this->boardAccessResolvingFixtureContexts(), $this->scope),
				);

				$service->setState(9, 1, CardReview::STATE_APPROVED, $actor);

				self::assertSame(
					$expected,
					$deferred->getNotifiedAt() !== null,
					sprintf('deferred-fire stamp for cell [%s × %s]', $reviewerUid, $cardName),
				);
			}
		}
	}

	/**
	 * A BoardAccess whose contextFor() resolves exactly the viewer fixture
	 * (throwing NotAMemberException for a null role) plus the card-owner uids -
	 * the per-uid counterpart of {@see self::boardAccessResolvingFixtureRoles()},
	 * wired to the REAL scope so the deferred-fire cells exercise the same rule
	 * as the matrix.
	 */
	private function boardAccessResolvingFixtureContexts(): BoardAccess {
		$boardAccess = $this->createMock(BoardAccess::class);
		$boardAccess->method('contextFor')->willReturnCallback(
			function (Board $board, string $uid): ViewerContext {
				$role = $this->viewers()[$uid] ?? null;
				if ($role === null) {
					throw new NotAMemberException('not a member');
				}
				return ViewerContext::forMember($uid, self::BOARD_ID, $role, false);
			},
		);
		return $boardAccess;
	}

	/**
	 * A BoardAccess whose rolesOn() resolves exactly the viewer fixture -
	 * the batched counterpart of guardFor()'s contextFor stub, wired to the
	 * REAL scope so the audience cells exercise the same rule as the matrix.
	 */
	private function boardAccessResolvingFixtureRoles(): BoardAccess {
		$boardAccess = $this->createMock(BoardAccess::class);
		$boardAccess->method('rolesOn')->willReturnCallback(
			fn (Board $board, array $uids): array => array_filter(
				array_intersect_key($this->viewers(), array_flip($uids)),
				static fn (?string $role): bool => $role !== null,
			),
		);
		return $boardAccess;
	}

	public function testLegacyRowsReadAsPublicAndUnknownValuesFailClosed(): void {
		// Pre-migration rows (visibility NULL) read as 'public' - existing
		// data behaves exactly as before the feature.
		$legacy = new Card();
		$legacy->setOwner('anyone');
		self::assertTrue($this->scope->isVisibleTo($legacy, 'spy', null));

		// An unknown stored value must fail CLOSED - never "sees everything".
		$bogus = new Card();
		$bogus->setVisibility('everyone');
		$bogus->setOwner('inty');
		self::assertFalse($this->scope->isVisibleTo($bogus, 'inty', ViewerContext::ROLE_INTERNAL));

		// A bogus ROLE never unlocks the internal branch either.
		$internal = new Card();
		$internal->setVisibility('internal');
		$internal->setCreatorRole('internal');
		$internal->setOwner('inty');
		self::assertFalse($this->scope->isVisibleTo($internal, 'x', 'admin'));
	}

	/**
	 * A guard whose BoardAccess resolves exactly the fixture role (or throws
	 * NotAMemberException for a null role), wired to the REAL scope - so the
	 * guard test exercises the same rule the matrix pins.
	 */
	private function guardFor(string $uid, ?string $role): CardVisibilityGuard {
		$boardAccess = $this->createMock(BoardAccess::class);
		if ($role === null) {
			$boardAccess->method('contextFor')
				->willThrowException(new NotAMemberException('not a member'));
		} else {
			$boardAccess->method('contextFor')
				->willReturnCallback(static fn (Board $board, string $u): ViewerContext => ViewerContext::forMember($u, self::BOARD_ID, $role, false));
		}
		return new CardVisibilityGuard($boardAccess, $this->scope);
	}
}
