<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Service\CardMutationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CardMutationServiceTest extends TestCase {
	public function testNestedCallbacksWaitForOutermostCommit(): void {
		$db = $this->createMock(IDBConnection::class);
		$scope = new CardMutationService($db, $this->createMock(CardMapper::class), $this->createMock(LoggerInterface::class));
		$events = [];
		$db->expects(self::exactly(2))->method('commit')->willReturnCallback(static function () use (&$events): void {
			$events[] = 'commit';
		});
		$result = $scope->runForBoards([], function () use ($scope, &$events): string {
			$scope->runForBoards([], function () use ($scope, &$events): void {
				$scope->afterCommit(static function () use (&$events): void {
					$events[] = 'notify';
				});
			});
			self::assertSame(['commit'], $events);
			return 'saved';
		});
		self::assertSame('saved', $result);
		self::assertSame(['commit', 'commit', 'notify'], $events);
	}

	public function testRollbackDiscardsNotificationsAndRunsAllCleanup(): void {
		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');
		$scope = new CardMutationService($db, $this->createMock(CardMapper::class), $logger);
		$cleaned = false;
		$db->expects(self::once())->method('rollBack');
		try {
			$scope->runForBoards([], function () use ($scope, &$cleaned): void {
				$scope->afterCommit(static fn () => self::fail('Rolled-back changes must not notify'));
				$scope->afterRollback(static function () use (&$cleaned): void {
					$cleaned = true;
				});
				$scope->afterRollback(static fn () => throw new \RuntimeException('cleanup failure'));
				throw new \RuntimeException('write failure');
			});
			self::fail('Expected the write failure');
		} catch (\RuntimeException $e) {
			self::assertSame('write failure', $e->getMessage());
		}
		self::assertTrue($cleaned);
	}

	public function testFailedNestedScopeRetainsOuterCallbacks(): void {
		$scope = new CardMutationService($this->createMock(IDBConnection::class), $this->createMock(CardMapper::class), $this->createMock(LoggerInterface::class));
		$events = [];
		$scope->runForBoards([], function () use ($scope, &$events): void {
			$scope->afterCommit(static function () use (&$events): void {
				$events[] = 'outer';
			});
			try {
				$scope->runForBoards([], function () use ($scope): void {
					$scope->afterCommit(static fn () => self::fail('Failed savepoint must not notify'));
					throw new \RuntimeException('savepoint failure');
				});
			} catch (\RuntimeException) {
			}
			self::assertSame([], $events);
		});
		self::assertSame(['outer'], $events);
	}

	public function testUnknownCommitRetainsStorageAndOriginalError(): void {
		$db = $this->createMock(IDBConnection::class);
		$scope = new CardMutationService($db, $this->createMock(CardMapper::class), $this->createMock(LoggerInterface::class));
		$db->method('commit')->willThrowException(new \RuntimeException('commit response lost'));
		$db->method('rollBack')->willThrowException(new \RuntimeException('connection closed'));
		$this->expectExceptionMessage('commit response lost');
		$scope->runForBoards([], function () use ($scope): void {
			$scope->afterCommit(static fn () => self::fail('Unconfirmed commit must not notify'));
			$scope->afterRollback(static fn () => self::fail('Unconfirmed commit must retain storage'));
		});
	}

	public function testNotificationFailureDoesNotFailCommittedMutation(): void {
		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);
		$scope = new CardMutationService($db, $this->createMock(CardMapper::class), $logger);
		$db->expects(self::once())->method('commit');
		$db->expects(self::never())->method('rollBack');
		$logger->expects(self::once())->method('warning');
		$continued = false;
		self::assertSame('saved', $scope->runForBoards([], function () use ($scope, &$continued): string {
			$scope->afterCommit(static fn () => throw new \RuntimeException('notification offline'));
			$scope->afterCommit(static function () use (&$continued): void {
				$continued = true;
			});
			return 'saved';
		}));
		self::assertTrue($continued);
	}

	public function testRejectsSourceDeletedWhileAcquiringTheFence(): void {
		$db = $this->createMock(IDBConnection::class);
		$cards = $this->createMock(CardMapper::class);
		$scope = new CardMutationService($db, $cards, $this->createMock(LoggerInterface::class));
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_SQLITE);
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(['id' => 1]);
		$db->method('executeQuery')->willReturn($result);
		$live = new Card();
		$live->setId(1);
		$live->setBoardId(1);
		$live->setDeletedAt(0);
		$deleted = clone $live;
		$deleted->setDeletedAt(time());
		$cards->method('find')->willReturn($live);
		$cards->method('findForMutation')->willReturn($deleted);
		$db->expects(self::once())->method('rollBack');
		$this->expectException(DoesNotExistException::class);
		$scope->run(1, static fn () => self::fail('Moved source must not be edited'));
	}

	public function testRejectsAnUnmanagedOuterTransactionBeforeWriting(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturn(true);
		$db->expects(self::never())->method('beginTransaction');
		$scope = new CardMutationService($db, $this->createMock(CardMapper::class), $this->createMock(LoggerInterface::class));
		$this->expectException(\LogicException::class);
		$scope->runForBoards([], static fn () => self::fail('Transaction ownership must be established first'));
	}
}
