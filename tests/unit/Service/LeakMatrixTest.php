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

	// ---- naming matrix: one card named inside ANOTHER card's feed ----------
	//
	// The Activity change detail written when two cards are linked, or a
	// sub-card attached/detached, names the card at the OTHER end and is stored
	// with NO viewer: it is later served verbatim from the HOST card's feed to
	// everyone who may read that host. The test is therefore audience
	// DOMINANCE - does the counterpart's audience contain the host's? - which
	// is what CardVisibilityScope::mayBeNamedIn() decides. Public-only
	// (isPublic) is its floor; the cells below are the full table, written out
	// like the matrix above so a widening is reviewed as a diff of the table.

	/**
	 * The card classes for the naming table. Everything is on ONE board except
	 * the explicit cross-board cells in
	 * {@see self::testDominanceIsNotClaimedAcrossBoards()}.
	 *
	 * @return array<string, Card>
	 */
	private function namingCards(): array {
		$make = static function (?string $visibility, ?string $creatorRole, string $owner): Card {
			$card = new Card();
			$card->setBoardId(self::BOARD_ID);
			if ($visibility !== null) {
				$card->setVisibility($visibility);
			}
			if ($creatorRole !== null) {
				$card->setCreatorRole($creatorRole);
			}
			$card->setOwner($owner);
			return $card;
		};

		return [
			'public' => $make('public', 'internal', 'inty'),
			// Pre-migration row: NULL visibility AND null creator side.
			'legacy-null' => $make(null, null, 'inty'),
			'internal-provider' => $make('internal', 'internal', 'inty'),
			// Same side, DIFFERENT owner - internal is per-side, not per-person,
			// so this must behave exactly like 'internal-provider'.
			'internal-provider-mgr' => $make('internal', 'internal', 'mgr'),
			'internal-client' => $make('internal', 'external', 'exty'),
			'private-of-inty' => $make('private', 'internal', 'inty'),
			'private-of-exty' => $make('private', 'external', 'exty'),
			// An unknown stored value must fail closed on BOTH axes.
			'unknown' => $make('everyone', 'internal', 'inty'),
		];
	}

	/**
	 * THE NAMING MATRIX: host (whose feed the detail lands in) => counterpart
	 * (the card being named) => is the name recorded? Every cell spelled out.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private function expectedNamingMatrix(): array {
		return [
			// A public host is read by everyone, so only a card everyone can
			// read may be named - this row IS the old isPublic() rule.
			'public' => [
				'public' => true,
				'legacy-null' => true,
				'internal-provider' => false,
				'internal-provider-mgr' => false,
				'internal-client' => false,
				'private-of-inty' => false,
				'private-of-exty' => false,
				'unknown' => false,
			],
			'legacy-null' => [
				'public' => true,
				'legacy-null' => true,
				'internal-provider' => false,
				'internal-provider-mgr' => false,
				'internal-client' => false,
				'private-of-inty' => false,
				'private-of-exty' => false,
				'unknown' => false,
			],
			// Provider-internal host: read by exactly the provider side of this
			// board - which is exactly who can read a provider-internal
			// counterpart, whoever owns it. THE widened row.
			'internal-provider' => [
				'public' => true,
				'legacy-null' => true,
				'internal-provider' => true,
				'internal-provider-mgr' => true,
				'internal-client' => false, // the other side of the fence
				'private-of-inty' => false,
				'private-of-exty' => false,
				'unknown' => false,
			],
			'internal-provider-mgr' => [
				'public' => true,
				'legacy-null' => true,
				'internal-provider' => true,
				'internal-provider-mgr' => true,
				'internal-client' => false,
				'private-of-inty' => false,
				'private-of-exty' => false,
				'unknown' => false,
			],
			// Symmetric: the client side has internals too, and they dominate
			// each other and nothing else.
			'internal-client' => [
				'public' => true,
				'legacy-null' => true,
				'internal-provider' => false,
				'internal-provider-mgr' => false,
				'internal-client' => true,
				'private-of-inty' => false,
				'private-of-exty' => false,
				'unknown' => false,
			],
			// Private host: an audience of one, so only that same one person's
			// private card is dominated - private is per-PERSON, never per-side.
			'private-of-inty' => [
				'public' => true,
				'legacy-null' => true,
				'internal-provider' => false,
				'internal-provider-mgr' => false,
				'internal-client' => false,
				'private-of-inty' => true,
				'private-of-exty' => false,
				'unknown' => false,
			],
			'private-of-exty' => [
				'public' => true,
				'legacy-null' => true,
				'internal-provider' => false,
				'internal-provider-mgr' => false,
				'internal-client' => false,
				'private-of-inty' => false,
				'private-of-exty' => true,
				'unknown' => false,
			],
			// An unknown host value is dominated by nothing narrower than
			// public - matching an identical unknown counterpart is NOT a
			// dominance proof, it is two values nobody can reason about.
			'unknown' => [
				'public' => true,
				'legacy-null' => true,
				'internal-provider' => false,
				'internal-provider-mgr' => false,
				'internal-client' => false,
				'private-of-inty' => false,
				'private-of-exty' => false,
				'unknown' => false,
			],
		];
	}

	public function testEveryHostCounterpartCellMatchesTheNamingMatrix(): void {
		$cards = $this->namingCards();
		$expected = $this->expectedNamingMatrix();

		foreach ($cards as $hostName => $host) {
			foreach ($cards as $otherName => $other) {
				self::assertSame(
					$expected[$hostName][$otherName],
					$this->scope->mayBeNamedIn($other, $host),
					sprintf('naming cell [host %s × named %s]', $hostName, $otherName),
				);
			}
		}
	}

	public function testNamingIsAPureWideningOfPublicOnly(): void {
		// The floor: every (host, counterpart) pair the public-only rule
		// allowed must STILL be allowed. A widening that loses a case is a
		// history regression of its own.
		$cards = $this->namingCards();

		foreach ($cards as $hostName => $host) {
			foreach ($cards as $otherName => $other) {
				if (!$this->scope->isPublic($other)) {
					continue;
				}
				self::assertTrue(
					$this->scope->mayBeNamedIn($other, $host),
					sprintf('public counterpart %s lost its name in %s', $otherName, $hostName),
				);
			}
		}
	}

	public function testEveryNameableCellIsReadableByTheWholeHostAudience(): void {
		// The SOUNDNESS proof behind the table: for every cell that records the
		// name, every viewer who can read the host can already read the named
		// card - derived from isVisibleTo(), not from the table, so a cell
		// flipped to true without that property fails here.
		$cards = $this->namingCards();

		foreach ($cards as $hostName => $host) {
			foreach ($cards as $otherName => $other) {
				if (!$this->scope->mayBeNamedIn($other, $host)) {
					continue;
				}
				foreach ($this->viewers() as $uid => $role) {
					if (!$this->scope->isVisibleTo($host, $uid, $role)) {
						continue;
					}
					self::assertTrue(
						$this->scope->isVisibleTo($other, $uid, $role),
						sprintf(
							'%s is named in %s but %s can read %s without being able to read %s',
							$otherName,
							$hostName,
							$uid,
							$hostName,
							$otherName,
						),
					);
				}
			}
		}
	}

	public function testDominanceIsNotClaimedAcrossBoards(): void {
		// A role is resolved PER board, so an equal creator side on a DIFFERENT
		// board is a different audience; a private owner may not even be a
		// member there. Both fall back to the public-only floor.
		foreach (['internal-provider', 'private-of-inty'] as $className) {
			$host = $this->namingCards()[$className];
			$elsewhere = clone $host;
			$elsewhere->setBoardId(self::BOARD_ID + 1);

			self::assertFalse(
				$this->scope->mayBeNamedIn($elsewhere, $host),
				'cross-board counterpart named in ' . $className,
			);
			self::assertFalse(
				$this->scope->mayBeNamedIn($host, $elsewhere),
				'cross-board host naming ' . $className,
			);
		}

		// A counterpart with no board at all cannot be proven to dominate
		// anything either.
		$host = $this->namingCards()['internal-provider'];
		$boardless = new Card();
		$boardless->setVisibility('internal');
		$boardless->setCreatorRole('internal');
		$boardless->setOwner('inty');
		self::assertFalse($this->scope->mayBeNamedIn($boardless, $host));
	}

	public function testWithoutAHostOnlyPublicMayBeNamed(): void {
		// The deleted-endpoint case (removeRelation, where one end is gone):
		// there is no host to dominate, so the rule collapses to isPublic().
		foreach ($this->namingCards() as $name => $card) {
			self::assertSame(
				$this->scope->isPublic($card),
				$this->scope->mayBeNamedIn($card, null),
				'host-less naming cell [' . $name . ']',
			);
		}
	}

	public function testABogusCreatorSideNeverDominates(): void {
		// A creator_role outside the two sides makes the card visible to NOBODY
		// (isVisibleTo requires the role to be a real side), so it must not be
		// named anywhere - not even beside an identical bogus value.
		$bogus = new Card();
		$bogus->setBoardId(self::BOARD_ID);
		$bogus->setVisibility('internal');
		$bogus->setCreatorRole('admin');
		$bogus->setOwner('inty');

		self::assertFalse($this->scope->mayBeNamedIn($bogus, clone $bogus));
		self::assertFalse($this->scope->mayBeNamedIn($bogus, $this->namingCards()['internal-provider']));
	}

	public function testAnOwnerlessPrivateCardNeverDominates(): void {
		// "Both have no owner" is not an identity match - a private card with
		// no owner is readable by nobody and provable as nothing.
		$ownerless = new Card();
		$ownerless->setBoardId(self::BOARD_ID);
		$ownerless->setVisibility('private');

		self::assertFalse($this->scope->mayBeNamedIn($ownerless, clone $ownerless));
		self::assertFalse($this->scope->mayBeNamedIn($ownerless, $this->namingCards()['private-of-inty']));
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
