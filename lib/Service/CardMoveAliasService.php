<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\BoardMapper;
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

	/** @param array<int, Card> $movedCards */
	public function record(array $movedCards): void {
		foreach ($movedCards as $oldId => $card) {
			$this->aliases->record($oldId, $card->getId());
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
