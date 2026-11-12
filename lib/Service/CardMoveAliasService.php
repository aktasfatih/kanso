<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\BoardPrefix;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardMoveAliasMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class CardMoveAliasService {
	public function __construct(
		private CardMoveAliasMapper $aliases,
		private CardMapper $cards,
		private BoardMapper $boards,
		private PermissionService $permissions,
		private CardVisibilityGuard $visibilityGuard,
	) {
	}

	/**
	 * @param array<int, Card> $movedCards
	 * @param list<Card> $sourceCards
	 */
	public function record(array $movedCards, array $sourceCards, Board $sourceBoard): void {
		foreach ($sourceCards as $source) {
			$this->aliases->record(
				$source->getId(), $movedCards[$source->getId()]->getId(),
				$sourceBoard->getId(), $sourceBoard->getPrefix() ?? BoardPrefix::DEFAULT, $source->getBoardSeq(),
			);
		}
	}

	public function resolveRef(int $boardId, string $prefix, int $seq, string $uid): ?Card {
		$alias = $this->aliases->findByRef($boardId, $prefix, $seq);
		if ($alias === null || $this->cards->hasLiveBoardSeq($boardId, $seq)) {
			return null;
		}
		try {
			return $this->resolve($alias->getOldCardId(), $uid);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** Resolve for navigation only, never for mutation endpoints. */
	public function resolve(int $id, string $uid): Card {
		$seen = [];
		while (true) {
			if (isset($seen[$id])) {
				throw new DoesNotExistException('Card does not exist');
			}
			$seen[$id] = true;
			try {
				$card = $this->cards->find($id);
			} catch (DoesNotExistException) {
				$card = null;
			}
			if ($card !== null && $card->getDeletedAt() === 0) {
				try {
					$board = $this->boards->find($card->getBoardId());
					if ($board->getDeletedAt() !== 0) {
						throw new DoesNotExistException('Card does not exist');
					}
					$this->permissions->assertPermission($board, $uid, PermissionService::PERMISSION_READ);
					$this->visibilityGuard->assertVisible($board, $card, $uid);
					return $card;
				} catch (NotPermittedException|DoesNotExistException) {
					throw new DoesNotExistException('Card does not exist');
				}
			}
			$alias = $this->aliases->findByOldId($id);
			if ($alias === null) {
				throw new DoesNotExistException('Card does not exist');
			}
			$id = $alias->getTargetCardId();
		}
	}
}
