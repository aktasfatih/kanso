<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Service\CardMutationService;
use OCA\Kanso\Service\MutationConflictException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CardMutationServiceTest extends TestCase {
	/** @return array{IDBConnection, CardMutationService, IQueryBuilder} */
	private function sqliteScope(): array {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_SQLITE);
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['update', 'set', 'where'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('createNamedParameter')->willReturn(':board');
		$expression = new class {
			public function eq(mixed $left, mixed $right): string {
				return 'id = :board';
			}
		};
		$qb->method('expr')->willReturn($expression);
		$db->method('getQueryBuilder')->willReturn($qb);
		return [$db, new CardMutationService($db, $this->createMock(CardMapper::class), $this->createMock(LoggerInterface::class)), $qb];
	}

	private function lockTimeout(): DbException {
		$error = $this->createMock(DbException::class);
		$error->method('getReason')->willReturn(DbException::REASON_LOCK_WAIT_TIMEOUT);
		return $error;
	}

	public function testSqliteReservesWriterBeforeOrdinaryAndExclusiveCallbacks(): void {
		foreach ([false, true] as $exclusive) {
			[$db, $scope, $qb] = $this->sqliteScope();
			$events = [];
			$qb->expects(self::once())->method('executeStatement')->willReturnCallback(static function () use (&$events): int {
				$events[] = 'reserve';
				return 1;
			});
			$db->expects(self::once())->method('executeQuery')->willReturnCallback(function () use (&$events): IResult {
				self::assertSame(['reserve'], $events);
				$events[] = 'snapshot';
				$result = $this->createMock(IResult::class);
				$result->method('fetch')->willReturn(['id' => 7]);
				return $result;
			});
			$scope->runForBoards([7], static function () use (&$events): void {
				$events[] = 'write';
			}, $exclusive);
			self::assertSame(['reserve', 'snapshot', 'write'], $events);
		}
	}

	public function testSqliteReservationTimeoutNeverRunsTheMutationAndResetsScope(): void {
		[$db, $scope, $qb] = $this->sqliteScope();
		$error = $this->lockTimeout();
		$qb->method('executeStatement')->willThrowException($error);
		$db->expects(self::once())->method('rollBack');
		try {
			$scope->runForBoards([7], static fn () => self::fail('A failed reservation must not run the mutation'));
			self::fail('Expected contention');
		} catch (MutationConflictException $conflict) {
			self::assertSame($error, $conflict->getPrevious());
		}
		$completed = false;
		$scope->afterCommit(static function () use (&$completed): void {
			$completed = true;
		});
		self::assertTrue($completed);
	}

	public function testKnownSqliteRollbackReportsConflictWithoutRetryingCallbacks(): void {
		[$db, $scope] = $this->sqliteScope();
		$db->expects(self::once())->method('rollBack');
		$calls = 0;
		$cleaned = false;
		try {
			$scope->runForBoards([], function () use ($scope, &$calls, &$cleaned): void {
				$calls++;
				$scope->afterCommit(static fn () => self::fail('Rolled-back data must not notify'));
				$scope->afterRollback(static function () use (&$cleaned): void {
					$cleaned = true;
				});
				throw $this->lockTimeout();
			});
			self::fail('Expected contention');
		} catch (MutationConflictException) {
		}
		self::assertSame(1, $calls);
		self::assertTrue($cleaned);
	}

	public function testNestedContentionWaitsForConfirmedOuterRollback(): void {
		[$db, $scope] = $this->sqliteScope();
		$error = $this->lockTimeout();
		$rollbacks = 0;
		$db->expects(self::exactly(2))->method('rollBack')->willReturnCallback(static function () use (&$rollbacks): void {
			if (++$rollbacks === 2) {
				throw new \RuntimeException('Outer rollback outcome unknown');
			}
		});
		try {
			$scope->runForBoards([], function () use ($scope, $error): void {
				$scope->runForBoards([], static fn () => throw $error);
			});
			self::fail('Expected original database failure');
		} catch (DbException $actual) {
			self::assertSame($error, $actual);
		}
	}

	public function testUnknownSqliteCommitDoesNotBecomeRetryableConflict(): void {
		[$db, $scope] = $this->sqliteScope();
		$error = $this->lockTimeout();
		$db->method('commit')->willThrowException($error);
		$db->expects(self::once())->method('rollBack');
		try {
			$scope->runForBoards([], function () use ($scope): void {
				$scope->afterCommit(static fn () => self::fail('Unknown commit must not notify'));
				$scope->afterRollback(static fn () => self::fail('Unknown commit must retain storage'));
			});
			self::fail('Expected original commit failure');
		} catch (DbException $actual) {
			self::assertSame($error, $actual);
		}
	}

	public function testUnrelatedSqliteDatabaseFailureRemainsUnchanged(): void {
		[$db, $scope] = $this->sqliteScope();
		$error = $this->createMock(DbException::class);
		$error->method('getReason')->willReturn(DbException::REASON_CONNECTION_LOST);
		$db->expects(self::once())->method('rollBack');
		try {
			$scope->runForBoards([], static fn () => throw $error);
			self::fail('Expected original database failure');
		} catch (DbException $actual) {
			self::assertSame($error, $actual);
		}
	}

	public function testSqlServerSharedFenceAndErrorsRemainUnchanged(): void {
		foreach ([IDBConnection::PLATFORM_POSTGRES => ' FOR SHARE', IDBConnection::PLATFORM_MYSQL => ' LOCK IN SHARE MODE'] as $provider => $suffix) {
			$db = $this->createMock(IDBConnection::class);
			$db->method('getDatabaseProvider')->willReturn($provider);
			$db->expects(self::never())->method('getQueryBuilder');
			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturn(['id' => 7]);
			$db->expects(self::once())->method('executeQuery')->with('SELECT id FROM *PREFIX*kanso_boards WHERE id = ?' . $suffix)->willReturn($result);
			$scope = new CardMutationService($db, $this->createMock(CardMapper::class), $this->createMock(LoggerInterface::class));
			$error = $this->lockTimeout();
			try {
				$scope->runForBoards([7], static fn () => throw $error);
				self::fail('Expected original SQL-server failure');
			} catch (DbException $actual) {
				self::assertSame($error, $actual);
			}
		}
	}

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
		[$db] = $this->sqliteScope();
		$cards = $this->createMock(CardMapper::class);
		$scope = new CardMutationService($db, $cards, $this->createMock(LoggerInterface::class));
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
