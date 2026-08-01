<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;

/**
 * Per-card activity feed - a READ-only view over the existing `kanso_changes`
 * log (no separate audit store). Each row carries a fine-grained `verb`
 * (Change::VERB_*) recording WHAT happened; a null verb renders as a generic
 * "updated". Capped and newest-first; the (entity_type, entity_id) index keeps
 * the query card-scoped rather than a board-history scan.
 */
class ActivityService {
	private const MAX_LIMIT = 50;

	public function __construct(
		private ChangeMapper $changeMapper,
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private PermissionService $permissionService,
		private IUserManager $userManager,
	) {
	}

	/**
	 * A card's activity, newest-first. Requires READ on the card's board.
	 *
	 * @return list<array{id: int, actor: ?string, actorName: ?string, verb: ?int, action: int, timestamp: int}>
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not read the board
	 */
	public function getCardActivity(int $cardId, string $uid, int $limit = self::MAX_LIMIT): array {
		$card = $this->loadCard($cardId);
		$board = $this->boardMapper->find($card->getBoardId());
		if ($board->getDeletedAt() > 0) {
			throw new DoesNotExistException('Board ' . $board->getId() . ' is deleted');
		}
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_READ);

		$limit = max(1, min($limit, self::MAX_LIMIT));
		$rows = $this->changeMapper->findByEntity($card->getBoardId(), Change::ENTITY_CARD, $cardId, $limit);

		$names = [];
		return array_map(function (Change $change) use (&$names): array {
			$actor = $change->getActor();
			if ($actor !== null && !array_key_exists($actor, $names)) {
				$user = $this->userManager->get($actor);
				$names[$actor] = $user !== null ? $user->getDisplayName() : $actor;
			}
			return [
				'id' => $change->getId(),
				'actor' => $actor,
				'actorName' => $actor !== null ? $names[$actor] : null,
				'verb' => $change->getVerb(),
				'action' => $change->getAction() ?? Change::ACTION_UPDATE,
				'timestamp' => $change->getCreatedAt() ?? 0,
			];
		}, $rows);
	}

	/**
	 * @throws DoesNotExistException if the card does not exist or is deleted
	 */
	private function loadCard(int $id): Card {
		$card = $this->cardMapper->find($id);
		if ($card->getDeletedAt() > 0) {
			throw new DoesNotExistException('Card ' . $id . ' is deleted');
		}
		return $card;
	}
}
