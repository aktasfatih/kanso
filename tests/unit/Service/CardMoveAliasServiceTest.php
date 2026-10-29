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
		$aliases->method('record')->willReturnCallback(static function (int $old, int $new) use (&$calls): void {
			$calls[] = [$old, $new];
		});
		$service = new CardMoveAliasService($aliases, $this->createMock(CardMapper::class), $this->createMock(BoardMapper::class), $this->createMock(PermissionService::class), $this->createMock(CardVisibilityGuard::class));
		$service->record([10 => $this->card(20, 2, 0), 11 => $this->card(21, 2, 0)]);
		self::assertSame([[10, 20], [11, 21]], $calls);
	}
}
