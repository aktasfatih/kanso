<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardMoveAlias;
use OCA\Kanso\Db\CardMoveAliasMapper;
use OCA\Kanso\Service\CardMoveAliasService;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

class CardMoveAliasServiceTest extends TestCase {
	private function card(int $id, int $boardId, int $deletedAt): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId($boardId);
		$card->setDeletedAt($deletedAt);
		return $card;
	}

	private function alias(int $targetId): CardMoveAlias {
		$alias = new CardMoveAlias();
		$alias->setTargetCardId($targetId);
		return $alias;
	}

	public function testResolvesChainAfterSourcePurgeUsingDestinationAccess(): void {
		$aliases = $this->createMock(CardMoveAliasMapper::class);
		$cards = $this->createMock(CardMapper::class);
		$boards = $this->createMock(BoardMapper::class);
		$permissions = $this->createMock(PermissionService::class);
		$guard = $this->createMock(CardVisibilityGuard::class);
		$target = $this->card(30, 3, 0);
		$cards->method('find')->willReturnCallback(function (int $id) use ($target): Card {
			return match ($id) {
				10 => throw new DoesNotExistException('purged'),
				20 => $this->card(20, 2, 100),
				30 => $target,
			};
		});
		$aliases->method('findByOldId')->willReturnMap([[10, $this->alias(20)], [20, $this->alias(30)]]);
		$board = new Board();
		$board->setId(3);
		$board->setDeletedAt(0);
		$boards->method('find')->with(3)->willReturn($board);
		$permissions->expects(self::once())->method('assertPermission')->with($board, 'bob', PermissionService::PERMISSION_READ);
		$guard->expects(self::once())->method('assertVisible')->with($board, $target, 'bob');
		$service = new CardMoveAliasService($aliases, $cards, $boards, $permissions, $guard);
		self::assertSame($target, $service->resolve(10, 'bob'));
	}

	public function testRestoredCardWinsOverAlias(): void {
		$aliases = $this->createMock(CardMoveAliasMapper::class);
		$cards = $this->createMock(CardMapper::class);
		$boards = $this->createMock(BoardMapper::class);
		$permissions = $this->createMock(PermissionService::class);
		$guard = $this->createMock(CardVisibilityGuard::class);
		$original = $this->card(10, 1, 0);
		$cards->method('find')->with(10)->willReturn($original);
		$aliases->expects(self::never())->method('findByOldId');
		$board = new Board();
		$board->setDeletedAt(0);
		$boards->method('find')->with(1)->willReturn($board);
		$service = new CardMoveAliasService($aliases, $cards, $boards, $permissions, $guard);
		self::assertSame($original, $service->resolve(10, 'bob'));
	}

	public function testInaccessibleLiveCardDoesNotFallThroughToAlias(): void {
		$aliases = $this->createMock(CardMoveAliasMapper::class);
		$cards = $this->createMock(CardMapper::class);
		$boards = $this->createMock(BoardMapper::class);
		$permissions = $this->createMock(PermissionService::class);
		$guard = $this->createMock(CardVisibilityGuard::class);
		$cards->method('find')->with(10)->willReturn($this->card(10, 1, 0));
		$aliases->expects(self::never())->method('findByOldId');
		$board = new Board();
		$board->setDeletedAt(0);
		$boards->method('find')->with(1)->willReturn($board);
		$permissions->method('assertPermission')->willThrowException(new NotPermittedException('secret'));
		$service = new CardMoveAliasService($aliases, $cards, $boards, $permissions, $guard);
		$this->expectException(DoesNotExistException::class);
		$this->expectExceptionMessage('Card does not exist');
		$service->resolve(10, 'eve');
	}

	public function testAliasCycleFailsClosed(): void {
		$aliases = $this->createMock(CardMoveAliasMapper::class);
		$cards = $this->createMock(CardMapper::class);
		$boards = $this->createMock(BoardMapper::class);
		$permissions = $this->createMock(PermissionService::class);
		$guard = $this->createMock(CardVisibilityGuard::class);
		$cards->method('find')->willThrowException(new DoesNotExistException('gone'));
		$aliases->method('findByOldId')->willReturnMap([[10, $this->alias(20)], [20, $this->alias(10)]]);
		$service = new CardMoveAliasService($aliases, $cards, $boards, $permissions, $guard);
		$this->expectException(DoesNotExistException::class);
		$service->resolve(10, 'bob');
	}

	public function testRecordsEveryMovedCard(): void {
		$aliases = $this->createMock(CardMoveAliasMapper::class);
		$calls = [];
		$aliases->method('record')->willReturnCallback(static function (int $old, int $new, int $boardId, string $prefix, ?int $seq, int $targetBoard, int $targetSeq) use (&$calls): void {
			$calls[] = [$old, $new, $boardId, $prefix, $seq, $targetBoard, $targetSeq];
		});
		$service = new CardMoveAliasService($aliases, $this->createMock(CardMapper::class), $this->createMock(BoardMapper::class), $this->createMock(PermissionService::class), $this->createMock(CardVisibilityGuard::class));
		$board = new Board();
		$board->setId(1);
		$board->setPrefix('OLD');
		$source = $this->card(10, 1, 0);
		$source->setBoardSeq(9);
		$other = $this->card(11, 1, 0);
		$other->setBoardSeq(10);
		$target = $this->card(20, 2, 0);
		$target->setBoardSeq(1);
		$next = $this->card(21, 2, 0);
		$next->setBoardSeq(2);
		$service->record([10 => $target, 11 => $next], [$source, $other], $board);
		self::assertSame([[10, 20, 1, 'OLD', 9, 2, 1], [11, 21, 1, 'OLD', 10, 2, 2]], $calls);
	}

	public function testHumanReferenceDoesNotReplaceALiveSequence(): void {
		$aliases = $this->createMock(CardMoveAliasMapper::class);
		$aliases->method('findByRef')->with(1, 'OLD', 9)->willReturn($this->alias(20));
		$cards = $this->createMock(CardMapper::class);
		$cards->method('hasLiveBoardSeq')->with(1, 9)->willReturn(true);
		$cards->expects(self::never())->method('find');
		$service = new CardMoveAliasService($aliases, $cards, $this->createMock(BoardMapper::class), $this->createMock(PermissionService::class), $this->createMock(CardVisibilityGuard::class));
		self::assertNull($service->resolveRef(1, 'OLD', 9, 'bob'));
	}

	public function testHumanReferenceMasksInaccessibleDestination(): void {
		$aliases = $this->createMock(CardMoveAliasMapper::class);
		$alias = $this->alias(20);
		$alias->setOldCardId(10);
		$alias->setTargetBoardId(2);
		$alias->setTargetBoardSeq(1);
		$aliases->method('findByRef')->with(1, 'OLD', 9)->willReturn($alias);
		$aliases->method('findByOldId')->with(10)->willReturn($alias);
		$cards = $this->createMock(CardMapper::class);
		$target = $this->card(20, 2, 0);
		$target->setBoardSeq(1);
		$cards->method('find')->with(20)->willReturn($target);
		$boards = $this->createMock(BoardMapper::class);
		$board = new Board();
		$board->setDeletedAt(0);
		$boards->method('find')->with(2)->willReturn($board);
		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('assertPermission')->willThrowException(new NotPermittedException('secret'));
		$service = new CardMoveAliasService($aliases, $cards, $boards, $permissions, $this->createMock(CardVisibilityGuard::class));
		self::assertNull($service->resolveRef(1, 'OLD', 9, 'bob'));
	}

	public function testHumanReferenceWithUnknownDestinationIdentityFailsClosed(): void {
		$aliases = $this->createMock(CardMoveAliasMapper::class);
		$aliases->method('findByRef')->with(1, 'OLD', 9)->willReturn($this->alias(20));
		$cards = $this->createMock(CardMapper::class);
		$cards->expects(self::never())->method('find');
		$service = new CardMoveAliasService($aliases, $cards, $this->createMock(BoardMapper::class), $this->createMock(PermissionService::class), $this->createMock(CardVisibilityGuard::class));
		self::assertNull($service->resolveRef(1, 'OLD', 9, 'bob'));
	}

	public function testHumanReferenceRejectsAnOutgoingAliasForAReusedIdentity(): void {
		$aliases = $this->createMock(CardMoveAliasMapper::class);
		$first = $this->alias(20);
		$first->setOldCardId(10);
		$first->setTargetBoardId(2);
		$first->setTargetBoardSeq(1);
		$unrelated = $this->alias(30);
		$unrelated->setOldCardId(20);
		$unrelated->setBoardId(3);
		$unrelated->setBoardSeq(1);
		$aliases->method('findByRef')->with(1, 'OLD', 9)->willReturn($first);
		$aliases->method('findByOldId')->with(20)->willReturn($unrelated);
		$cards = $this->createMock(CardMapper::class);
		$cards->method('find')->with(20)->willThrowException(new DoesNotExistException('purged'));
		$service = new CardMoveAliasService($aliases, $cards, $this->createMock(BoardMapper::class), $this->createMock(PermissionService::class), $this->createMock(CardVisibilityGuard::class));
		self::assertNull($service->resolveRef(1, 'OLD', 9, 'bob'));
	}
}
