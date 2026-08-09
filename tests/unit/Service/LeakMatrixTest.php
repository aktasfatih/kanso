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
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\CardVisibilityScope;
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
