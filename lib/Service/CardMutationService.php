<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\CardMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class CardMutationService {
	/** @var list<callable():void> */
	private array $committed = [];
	/** @var list<callable():void> */
	private array $rolledBack = [];
	private int $depth = 0;

	public function __construct(
		private IDBConnection $db,
		private CardMapper $cards,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @template T
	 * @param callable():T $write
	 * @return T
	 */
	public function run(int $cardId, callable $write): mixed {
		$boardId = $this->cards->find($cardId)->getBoardId();
		return $this->runForBoards([$boardId], function () use ($cardId, $boardId, $write): mixed {
			$card = $this->cards->findForMutation($cardId);
			if ($card->getDeletedAt() !== 0 || $card->getBoardId() !== $boardId) {
				throw new DoesNotExistException('Card is no longer available; reload before editing');
			}
			return $write();
		});
	}

	/**
	 * @template T
	 * @param list<int> $boardIds
	 * @param callable():T $write
	 * @return T
	 */
	public function runForBoards(array $boardIds, callable $write, bool $exclusive = false): mixed {
		$boardIds = array_values(array_unique($boardIds));
		sort($boardIds, SORT_NUMERIC);
		$commitOffset = count($this->committed);
		$rollbackOffset = count($this->rolledBack);
		$outermost = $this->depth === 0;
		if ($outermost && $this->db->inTransaction()) {
			throw new \LogicException('Card mutations must own the outer transaction');
		}
		$this->db->beginTransaction();
		$this->depth++;
		$commitStarted = false;
		try {
			foreach ($boardIds as $boardId) {
				$this->lockBoard($boardId, $exclusive);
			}
			$result = $write();
			$commitStarted = true;
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->depth--;
			$this->committed = array_slice($this->committed, 0, $commitOffset);
			$cleanup = array_splice($this->rolledBack, $rollbackOffset);
			$rollbackConfirmed = false;
			try {
				$this->db->rollBack();
				$rollbackConfirmed = true;
				if (!$commitStarted) {
					foreach (array_reverse($cleanup) as $callback) {
						try {
							$callback();
						} catch (\Throwable $cleanupError) {
							$this->logger->warning('kanso: rollback cleanup failed', ['exception' => $cleanupError]);
						}
					}
				}
			} catch (\Throwable) {
				// Retain storage when the transaction outcome cannot be confirmed.
			}
			if ($outermost && !$commitStarted && $rollbackConfirmed
				&& $this->db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE
				&& $e instanceof DbException && $e->getReason() === DbException::REASON_LOCK_WAIT_TIMEOUT) {
				throw new MutationConflictException($e);
			}
			throw $e;
		}
		$this->depth--;
		if ($outermost) {
			$callbacks = $this->committed;
			$this->committed = [];
			$this->rolledBack = [];
			foreach ($callbacks as $callback) {
				try {
					$callback();
				} catch (\Throwable $e) {
					$this->logger->warning('kanso: post-commit callback failed', ['exception' => $e]);
				}
			}
		}
		return $result;
	}

	/** @param callable():void $callback */
	public function afterCommit(callable $callback): void {
		if ($this->depth === 0) {
			$callback();
		} else {
			$this->committed[] = $callback;
		}
	}

	/** @param callable():void $callback */
	public function afterRollback(callable $callback): void {
		if ($this->depth > 0) {
			$this->rolledBack[] = $callback;
		}
	}

	private function lockBoard(int $boardId, bool $exclusive): void {
		$provider = $this->db->getDatabaseProvider();
		if ($provider === IDBConnection::PLATFORM_SQLITE) {
			// SQLite snapshots fail their write upgrade if another writer commits.
			$qb = $this->db->getQueryBuilder();
			$qb->update('kanso_boards')->set('last_modified', 'last_modified')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
			$this->lockRow('kanso_boards', $boardId, '');
			return;
		}
		// Ordinary card writes share the topology fence; graph moves take it exclusively.
		$suffix = $exclusive ? ' FOR UPDATE'
			: ($provider === IDBConnection::PLATFORM_POSTGRES ? ' FOR SHARE' : ' LOCK IN SHARE MODE');
		$this->lockRow('kanso_boards', $boardId, $suffix);
	}

	private function lockRow(string $table, int $id, string $suffix): void {
		$result = $this->db->executeQuery('SELECT id FROM *PREFIX*' . $table . ' WHERE id = ?' . $suffix, [(string)$id], [IQueryBuilder::PARAM_INT]);
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			throw new DoesNotExistException('The requested resource no longer exists');
		}
	}
}
