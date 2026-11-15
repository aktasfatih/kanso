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
use OCA\Kanso\Db\CardMoveAlias;
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
			$target = $movedCards[$source->getId()];
			$targetSeq = $target->getBoardSeq();
			if ($targetSeq === null || $targetSeq < 1) {
				throw new \LogicException('A moved card requires a destination number');
			}
			$this->aliases->record(
				$source->getId(), $target->getId(),
				$sourceBoard->getId(), $sourceBoard->getPrefix() ?? BoardPrefix::DEFAULT, $source->getBoardSeq(),
				$target->getBoardId(), $targetSeq,
			);
		}
	}

	public function resolveRef(int $boardId, string $prefix, int $seq, string $uid): ?Card {
		$alias = $this->aliases->findByRef($boardId, $prefix, $seq);
		if ($alias === null || $this->cards->hasLiveBoardSeq($boardId, $seq)) {
			return null;
		}
		try {
			return $this->resolveHumanAlias($alias, $uid);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	private function resolveHumanAlias(CardMoveAlias $alias, string $uid): ?Card {
		$seen = [];
		while (!isset($seen[$alias->getOldCardId()])) {
			$seen[$alias->getOldCardId()] = true;
			$boardId = $alias->getTargetBoardId();
			$seq = $alias->getTargetBoardSeq();
			if ($boardId === null || $boardId < 1 || $seq === null || $seq < 1) {
				return null;
			}
			$id = $alias->getTargetCardId();
			try {
				$card = $this->cards->find($id);
			} catch (DoesNotExistException) {
				$card = null;
			}
			if ($card !== null && $card->getDeletedAt() === 0 && $card->getBoardId() === $boardId && $card->getBoardSeq() === $seq) {
				return $this->visibleCard($card, $uid);
			}
			$next = $this->aliases->findByOldId($id);
			if ($next === null || $next->getBoardId() !== $boardId || $next->getBoardSeq() !== $seq) {
				return null;
			}
			$alias = $next;
		}
		return null;
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
				return $this->visibleCard($card, $uid);
			}
			$alias = $this->aliases->findByOldId($id);
			if ($alias === null) {
				throw new DoesNotExistException('Card does not exist');
			}
			$id = $alias->getTargetCardId();
		}
	}

	private function visibleCard(Card $card, string $uid): Card {
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
}
