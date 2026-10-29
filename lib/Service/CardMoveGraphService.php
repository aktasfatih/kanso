<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardRelation;
use OCA\Kanso\Db\CardRelationMapper;
use OCA\Kanso\Db\RecurRuleMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class CardMoveGraphService {
	public function __construct(
		private CardMapper $cards,
		private CardRelationMapper $relations,
		private CardVisibilityGuard $visibility,
		private RecurRuleMapper $rules,
		private PermissionService $permissions,
	) {
	}

	/**
	 * @return array{cards: list<Card>, relations: list<CardRelation>}
	 * @throws InvalidInputException
	 */
	public function discover(Card $source, Board $board, string $uid): array {
		$recurrenceNeighbours = [];
		foreach ($this->rules->findByBoard($board->getId()) as $rule) {
			$templateId = $rule->getTemplateCardId();
			$lastId = $rule->getLastSpawnedAt();
			if ($lastId !== 0) {
				$recurrenceNeighbours[$templateId][] = $lastId;
				$recurrenceNeighbours[$lastId][] = $templateId;
			}
		}
		$pending = [(int)$source->getId()];
		$seen = [];
		$cards = [];
		$relations = [];
		$recurrenceAuthorized = false;
		for ($i = 0; $i < count($pending); $i++) {
			$id = $pending[$i];
			if (isset($seen[$id])) {
				continue;
			}
			$seen[$id] = true;
			try {
				$card = $id === $source->getId() ? $source : $this->cards->find($id);
			} catch (DoesNotExistException) {
				throw new InvalidInputException('A connected card is unavailable and cannot be moved');
			}
			if ($card->getBoardId() !== $board->getId() || $card->getDeletedAt() !== 0
				|| !$this->visibility->isVisible($board, $card, $uid)) {
				throw new InvalidInputException('A connected card is unavailable and cannot be moved');
			}
			$cards[] = $card;
			if (isset($recurrenceNeighbours[$id])) {
				if (!$recurrenceAuthorized) {
					$this->permissions->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);
					$recurrenceAuthorized = true;
				}
				foreach ($recurrenceNeighbours[$id] as $neighbour) {
					$pending[] = $neighbour;
				}
			}
			$parentId = $card->getParentCardId();
			if ($parentId !== null) {
				$pending[] = $parentId;
			}
			foreach ($this->cards->findChildren($id) as $child) {
				$pending[] = (int)$child->getId();
			}
			foreach ($this->relations->findTouching($id) as $relation) {
				if ($relation->getBoardId() !== $board->getId()) {
					throw new InvalidInputException('A connected card is unavailable and cannot be moved');
				}
				$relations[(int)$relation->getId()] = $relation;
				$pending[] = $relation->getCardId();
				$pending[] = $relation->getOtherCardId();
			}
		}
		return ['cards' => $cards, 'relations' => array_values($relations)];
	}

	/**
	 * @param list<Card> $sourceCards
	 * @param list<CardRelation> $sourceRelations
	 * @param array<int, Card> $movedCards keyed by original card id
	 * @throws InvalidInputException
	 */
	public function recreate(array $sourceCards, array $sourceRelations, array $movedCards, int $targetBoardId): void {
		foreach ($sourceCards as $source) {
			$parentId = $source->getParentCardId();
			if ($parentId === null) {
				continue;
			}
			$copy = $movedCards[$source->getId()] ?? null;
			$parent = $movedCards[$parentId] ?? null;
			if ($copy === null || $parent === null) {
				throw new InvalidInputException('A connected card is unavailable and cannot be moved');
			}
			$copy->setParentCardId((int)$parent->getId());
			$this->cards->update($copy);
		}

		foreach ($sourceRelations as $relation) {
			$from = $movedCards[$relation->getCardId()] ?? null;
			$to = $movedCards[$relation->getOtherCardId()] ?? null;
			if ($from === null || $to === null) {
				throw new InvalidInputException('A connected card is unavailable and cannot be moved');
			}
			$fromId = (int)$from->getId();
			$toId = (int)$to->getId();
			if (CardRelation::isSymmetric($relation->getType()) && $fromId > $toId) {
				[$fromId, $toId] = [$toId, $fromId];
			}
			$copy = new CardRelation();
			$copy->setBoardId($targetBoardId);
			$copy->setCardId($fromId);
			$copy->setOtherCardId($toId);
			$copy->setType($relation->getType());
			$copy->setCreatedAt($relation->getCreatedAt());
			$this->relations->insert($copy);
		}
	}
}
