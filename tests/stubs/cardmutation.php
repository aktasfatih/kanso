<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Stubs;

use OCP\IDBConnection;

class CardMutationService extends \OCA\Kanso\Service\CardMutationService {
	/** @var list<callable():void> */
	private array $cleanup = [];
	/** @var list<callable():void> */
	private array $notifications = [];
	private int $depth = 0;

	public function __construct(
		private ?IDBConnection $connection = null,
		private bool $allTransactions = false,
	) {
	}

	public function run(int $cardId, callable $write): mixed {
		return $this->runForBoards([], $write);
	}

	public function runForBoards(array $boardIds, callable $write, bool $exclusive = false): mixed {
		$offset = count($this->cleanup);
		$notificationOffset = count($this->notifications);
		$transactional = $exclusive || $this->allTransactions;
		$this->depth++;
		if ($transactional) {
			$this->connection?->beginTransaction();
		}
		$commitStarted = false;
		try {
			$result = $write();
			$commitStarted = true;
			if ($transactional) {
				$this->connection?->commit();
			}
		} catch (\Throwable $e) {
			if ($transactional) {
				try {
					$this->connection?->rollBack();
				} catch (\Throwable) {
				}
			}
			$callbacks = array_splice($this->cleanup, $offset);
			$this->notifications = array_slice($this->notifications, 0, $notificationOffset);
			$this->depth--;
			if (!$commitStarted) {
				foreach (array_reverse($callbacks) as $callback) {
					$callback();
				}
			}
			throw $e;
		}
		$this->depth--;
		if ($this->depth === 0) {
			$this->cleanup = [];
			$callbacks = $this->notifications;
			$this->notifications = [];
			foreach ($callbacks as $callback) {
				$callback();
			}
		}
		return $result;
	}

	public function afterCommit(callable $callback): void {
		if ($this->depth > 0) {
			$this->notifications[] = $callback;
		} else {
			$callback();
		}
	}

	public function afterRollback(callable $callback): void {
		$this->cleanup[] = $callback;
	}
}
