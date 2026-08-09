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
