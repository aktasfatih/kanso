<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardRelation;
use OCA\Kanso\Db\CardRelationMapper;
use OCA\Kanso\Service\CardRelationService;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\CardVisibilityScope;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CardRelationServiceTest extends TestCase {
	private CardRelationMapper&MockObject $relationMapper;
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private PermissionService&MockObject $permissionService;
	private ChangeNotifier&MockObject $changeNotifier;
	private CardVisibilityGuard&MockObject $visibilityGuard;
	private CardRelationService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->relationMapper = $this->createMock(CardRelationMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->visibilityGuard = $this->createMock(CardVisibilityGuard::class);
		$this->visibilityGuard->method('isVisible')->willReturn(true);
		$this->visibilityGuard->method('roleOn')->willReturn(ViewerContext::ROLE_INTERNAL);
		// A REAL scope - the masking rule is the behaviour under test, not a stub.
		$this->service = new CardRelationService(
			$this->relationMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->permissionService,
			$this->changeNotifier,
			$this->visibilityGuard,
			new CardVisibilityScope(),
		);
	}

	/** Every card maps to board 1 unless overridden in a specific test. */
	private function wireCards(array $boardByCardId = []): void {
		$this->cardMapper->method('find')->willReturnCallback(function (int $id) use ($boardByCardId): Card {
			$card = new Card();
			$card->setId($id);
			$card->setBoardId($boardByCardId[$id] ?? 1);
			$card->setDeletedAt(0);
			return $card;
		});
		$board = new Board();
		$board->setId(1);
		$board->setDeletedAt(0);
		$this->boardMapper->method('find')->willReturn($board);
	}

	public function testAddRejectsUnknownKind(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->addRelation(1, 2, 'mentions', 'alice');
	}

	public function testAddRejectsSelfRelation(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->addRelation(5, 5, 'blocks', 'alice');
	}

	public function testAddRejectsCrossBoardPair(): void {
		$this->wireCards([20 => 2]); // card 20 lives on a different board
		$this->expectException(InvalidInputException::class);
		$this->service->addRelation(10, 20, 'relates', 'alice');
	}

	public function testAddAssertsEditPermission(): void {
		$this->wireCards();
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException());
		$this->relationMapper->expects($this->never())->method('insert');
		$this->expectException(NotPermittedException::class);
		$this->service->addRelation(10, 20, 'blocks', 'alice');
	}

	public function testAddBlocksStoresDirectionalRowAndNotifiesBoth(): void {
		$this->wireCards();
		$this->relationMapper->method('findBlocksEdgesByBoard')->willReturn([]);
		$this->relationMapper->method('exists')->willReturn(false);
		$captured = null;
		$this->relationMapper->method('insert')->willReturnCallback(function (CardRelation $r) use (&$captured): CardRelation {
			$captured = $r;
			return $r;
		});
		$this->changeNotifier->expects($this->exactly(2))->method('notify');

		$this->service->addRelation(10, 20, 'blocks', 'alice');
		self::assertSame(10, $captured->getCardId());
		self::assertSame(20, $captured->getOtherCardId());
		self::assertSame(CardRelation::TYPE_BLOCKS, $captured->getType());
	}

	public function testBlockedByStoresSwappedBlocksRow(): void {
		$this->wireCards();
		$this->relationMapper->method('findBlocksEdgesByBoard')->willReturn([]);
		$this->relationMapper->method('exists')->willReturn(false);
		$captured = null;
		$this->relationMapper->method('insert')->willReturnCallback(function (CardRelation $r) use (&$captured): CardRelation {
			$captured = $r;
			return $r;
		});

		// "10 blocked_by 20" is stored as "20 blocks 10".
		$this->service->addRelation(10, 20, 'blocked_by', 'alice');
		self::assertSame(20, $captured->getCardId());
		self::assertSame(10, $captured->getOtherCardId());
		self::assertSame(CardRelation::TYPE_BLOCKS, $captured->getType());
	}

	public function testSymmetricRelationStoredInCanonicalOrder(): void {
		$this->wireCards();
		$this->relationMapper->method('exists')->willReturn(false);
		$captured = null;
		$this->relationMapper->method('insert')->willReturnCallback(function (CardRelation $r) use (&$captured): CardRelation {
			$captured = $r;
			return $r;
		});

		// Adding from the higher id - still stored min→max.
		$this->service->addRelation(20, 10, 'duplicates', 'alice');
		self::assertSame(10, $captured->getCardId());
		self::assertSame(20, $captured->getOtherCardId());
		self::assertSame(CardRelation::TYPE_DUPLICATES, $captured->getType());
	}

	public function testAddBlocksRejectsCycle(): void {
		$this->wireCards();
		// 20 already blocks 10; adding "10 blocks 20" would close a cycle.
		$this->relationMapper->method('findBlocksEdgesByBoard')->willReturn([['from' => 20, 'to' => 10]]);
		$this->relationMapper->expects($this->never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->addRelation(10, 20, 'blocks', 'alice');
	}

	public function testRemoveAssertsEditAndDeletes(): void {
		$relation = new CardRelation();
		$relation->setId(7);
		$relation->setBoardId(1);
		$relation->setCardId(10);
		$relation->setOtherCardId(20);
		$this->relationMapper->method('find')->with(7)->willReturn($relation);
		$board = new Board();
		$board->setId(1);
		$board->setDeletedAt(0);
		$this->boardMapper->method('find')->willReturn($board);
		$this->permissionService->expects($this->once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_EDIT);
		$this->relationMapper->expects($this->once())->method('delete')->with($relation);
		$this->changeNotifier->expects($this->exactly(2))->method('notify');

		$this->service->removeRelation(7, 'alice');
	}

	public function testRelationsForCardGroupsByDirectionAndType(): void {
		$this->wireCards();
		$this->permissionService->method('assertPermission'); // READ ok
		$this->relationMapper->method('findOutgoing')->willReturn([
			['id' => 1, 'type' => 'blocks', 'otherCardId' => 20, 'otherTitle' => 'B', 'otherDone' => false, 'otherVisibility' => 'public', 'otherCreatorRole' => 'internal', 'otherOwner' => 'someone'],
			['id' => 2, 'type' => 'duplicates', 'otherCardId' => 30, 'otherTitle' => 'D', 'otherDone' => true, 'otherVisibility' => 'public', 'otherCreatorRole' => 'internal', 'otherOwner' => 'someone'],
		]);
		$this->relationMapper->method('findIncoming')->willReturn([
			['id' => 3, 'type' => 'blocks', 'otherCardId' => 40, 'otherTitle' => 'A', 'otherDone' => false, 'otherVisibility' => 'public', 'otherCreatorRole' => 'internal', 'otherOwner' => 'someone'],
		]);

		$grouped = $this->service->relationsForCard(10, 'alice');
		self::assertCount(1, $grouped['blocks']);
		self::assertSame(20, $grouped['blocks'][0]['cardId']);
		self::assertFalse($grouped['blocks'][0]['hidden']);
		self::assertCount(1, $grouped['blockedBy']);
		self::assertSame(40, $grouped['blockedBy'][0]['cardId']);
		self::assertCount(1, $grouped['duplicates']);
		self::assertSame(30, $grouped['duplicates'][0]['cardId']);
		self::assertCount(0, $grouped['relates']);
	}

	public function testGroupedForCardMasksInvisibleCounterpart(): void {
		// A private counterpart owned by someone else: the row survives
		// (the relation stays removable) but its content must be masked.
		$this->relationMapper->method('findOutgoing')->willReturn([
			['id' => 5, 'type' => 'relates', 'otherCardId' => 20, 'otherTitle' => 'Secret', 'otherDone' => true, 'otherVisibility' => 'private', 'otherCreatorRole' => null, 'otherOwner' => 'someone-else'],
		]);
		$this->relationMapper->method('findIncoming')->willReturn([]);
		$board = new Board();
		$board->setId(1);

		$grouped = $this->service->groupedForCard(10, $board, 'alice');
		self::assertSame(
			['id' => 5, 'cardId' => null, 'title' => null, 'done' => false, 'hidden' => true],
			$grouped['relates'][0],
		);
	}

	// ---- blocksEdgesForBoard(): the board-scoped timeline edge list ---------

	/**
	 * One mapper row in findBlocksEdgesWithVisibilityByBoard() shape. Endpoints
	 * default to public/internal-created/someone-else's, so each test only
	 * states the triple it actually cares about.
	 */
	private function edgeRow(int $from, int $to, array $overrides = []): array {
		return $overrides + [
			'from' => $from,
			'to' => $to,
			'fromVisibility' => 'public',
			'fromCreatorRole' => 'internal',
			'fromOwner' => 'someone-else',
			'toVisibility' => 'public',
			'toCreatorRole' => 'internal',
			'toOwner' => 'someone-else',
		];
	}

	/**
	 * No endpoint of a masked edge may appear ANYWHERE in the emitted graph -
	 * asserted structurally over the from/to values rather than by searching the
	 * serialized JSON (where "10" also matches 100, 210, ...).
	 *
	 * @param list<array{from: int, to: int}> $edges
	 */
	private function assertCardIdAbsent(int $cardId, array $edges): void {
		foreach ($edges as $edge) {
			self::assertNotSame($cardId, $edge['from']);
			self::assertNotSame($cardId, $edge['to']);
		}
	}

	private function boardOne(): Board {
		$board = new Board();
		$board->setId(1);
		$board->setDeletedAt(0);
		return $board;
	}

	public function testBlocksEdgesForBoardReturnsAllVisibleEdgesInOrder(): void {
		// The mapper already orders by (card_id, other_card_id); the service
		// must preserve that order, not re-key or re-sort.
		$this->relationMapper->method('findBlocksEdgesWithVisibilityByBoard')->with(1)->willReturn([
			$this->edgeRow(10, 20),
			$this->edgeRow(10, 30),
			$this->edgeRow(20, 30),
		]);

		self::assertSame(
			[['from' => 10, 'to' => 20], ['from' => 10, 'to' => 30], ['from' => 20, 'to' => 30]],
			$this->service->blocksEdgesForBoard($this->boardOne(), 'alice'),
		);
	}

	public function testBlocksEdgesForBoardDropsWholeEdgeWhenTargetIsHidden(): void {
		// Card 99 is private and owned by someone else. Emitting ANY trace of
		// the edge - even just the visible source id 10 - would confirm a
		// restricted card exists on this board. The whole edge must vanish.
		$this->relationMapper->method('findBlocksEdgesWithVisibilityByBoard')->willReturn([
			$this->edgeRow(10, 99, ['toVisibility' => 'private', 'toCreatorRole' => null, 'toOwner' => 'someone-else']),
			$this->edgeRow(10, 20),
		]);

		$edges = $this->service->blocksEdgesForBoard($this->boardOne(), 'alice');
		// The all-public edge in the same fixture still survives.
		self::assertSame([['from' => 10, 'to' => 20]], $edges);
		// No id from the masked edge leaks anywhere in the output.
		$this->assertCardIdAbsent(99, $edges);
	}

	public function testBlocksEdgesForBoardDropsWholeEdgeWhenSourceIsHidden(): void {
		// Same rule from the other direction: a hidden BLOCKER must not show up
		// as an arrow into a card the viewer can see.
		$this->relationMapper->method('findBlocksEdgesWithVisibilityByBoard')->willReturn([
			$this->edgeRow(99, 10, ['fromVisibility' => 'private', 'fromCreatorRole' => null, 'fromOwner' => 'someone-else']),
			$this->edgeRow(20, 30),
		]);

		$edges = $this->service->blocksEdgesForBoard($this->boardOne(), 'alice');
		self::assertSame([['from' => 20, 'to' => 30]], $edges);
		$this->assertCardIdAbsent(99, $edges);
		// The visible endpoint of the masked edge is not emitted on its own.
		$this->assertCardIdAbsent(10, $edges);
	}

	public function testBlocksEdgesForBoardKeepsPrivateEndpointOwnedByTheViewer(): void {
		// Private is owner-scoped, not blanket-hidden: alice's own private card
		// is visible TO ALICE, so the edge stays.
		$this->relationMapper->method('findBlocksEdgesWithVisibilityByBoard')->willReturn([
			$this->edgeRow(10, 20, ['toVisibility' => 'private', 'toCreatorRole' => null, 'toOwner' => 'alice']),
		]);

		self::assertSame(
			[['from' => 10, 'to' => 20]],
			$this->service->blocksEdgesForBoard($this->boardOne(), 'alice'),
		);
	}

	public function testBlocksEdgesForBoardMasksInternalEndpointFromTheOtherSide(): void {
		// The viewer resolves as 'internal' (see setUp). An 'internal' card
		// CREATED by the external side is not theirs to see - masked whole-edge,
		// exactly like the private case.
		$this->relationMapper->method('findBlocksEdgesWithVisibilityByBoard')->willReturn([
			$this->edgeRow(10, 99, ['toVisibility' => 'internal', 'toCreatorRole' => 'external']),
			$this->edgeRow(20, 30),
		]);

		$edges = $this->service->blocksEdgesForBoard($this->boardOne(), 'alice');
		self::assertSame([['from' => 20, 'to' => 30]], $edges);
		$this->assertCardIdAbsent(99, $edges);
	}

	public function testBlocksEdgesForBoardTreatsNullVisibilityAsPublic(): void {
		// Rows predating the visibility migration carry NULLs; they must read
		// as public (the backfill value), not fail closed and hide the graph.
		$this->relationMapper->method('findBlocksEdgesWithVisibilityByBoard')->willReturn([
			$this->edgeRow(10, 20, [
				'fromVisibility' => null, 'fromCreatorRole' => null,
				'toVisibility' => null, 'toCreatorRole' => null,
			]),
		]);

		self::assertSame(
			[['from' => 10, 'to' => 20]],
			$this->service->blocksEdgesForBoard($this->boardOne(), 'alice'),
		);
	}

	public function testAddToInvisibleCounterpartReadsAsNotFound(): void {
		$this->wireCards();
		// The actor may see card 10 but NOT card 20 - relating to a hidden
		// card must fail exactly like a missing id (no existence oracle).
		$this->visibilityGuard->method('assertVisible')
			->willReturnCallback(static function ($board, Card $card): void {
				if ($card->getId() === 20) {
					throw new DoesNotExistException('hidden');
				}
			});
		$this->relationMapper->expects($this->never())->method('insert');

		$this->expectException(DoesNotExistException::class);
		$this->service->addRelation(10, 20, 'relates', 'alice');
	}
}
