<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;

/**
 * One-click import of a Deck board into Kanso. Reads the source via
 * {@see DeckReader} (read-only) and writes a fresh Kanso board owned by the
 * importing user — a copy, never a link, so the two stay independent.
 *
 * v1 imports the structure that maps cleanly: board (title/color), stacks
 * (order preserved), cards (title/description/archived/due date/done state),
 * labels + their card assignments, and user assignees that still exist. Board
 * SHARING/ACL, comments and attachments are deliberately out of scope for v1
 * (they need participant/actor remapping and the core comments/files APIs) —
 * tracked as follow-ups.
 */
class DeckImportService {
	public function __construct(
		private DeckReader $deckReader,
		private BoardService $boardService,
		private StackMapper $stackMapper,
		private CardMapper $cardMapper,
		private LabelMapper $labelMapper,
		private CardLabelMapper $cardLabelMapper,
		private CardAssigneeMapper $cardAssigneeMapper,
		private SortKeyService $sortKeyService,
		private IUserManager $userManager,
	) {
	}

	/**
	 * The Deck boards the user can import (owned or directly shared), or an empty
	 * list when Deck is not installed.
	 *
	 * @return list<array{id: int, title: string, color: ?string, archived: bool, cardCount: int}>
	 */
	public function listImportableBoards(string $actorUid): array {
		if (!$this->deckReader->isAvailable()) {
			return [];
		}
		return $this->deckReader->listImportableBoards($actorUid);
	}

	/** Whether the Deck app is available to import from at all. */
	public function isDeckAvailable(): bool {
		return $this->deckReader->isAvailable();
	}

	/**
	 * Imports one Deck board into a new Kanso board owned by the actor.
	 *
	 * @return array{boardId: int, title: string, stacks: int, cards: int, labels: int}
	 * @throws InvalidInputException if Deck is not available
	 * @throws NotPermittedException if the actor cannot read the Deck board
	 * @throws DoesNotExistException if the Deck board does not exist
	 */
	public function importBoard(int $deckBoardId, string $actorUid): array {
		if (!$this->deckReader->isAvailable()) {
			throw new InvalidInputException('The Deck app is not available to import from');
		}
		if (!$this->deckReader->userCanReadBoard($actorUid, $deckBoardId)) {
			throw new NotPermittedException('You do not have access to that Deck board');
		}
		$deckBoard = $this->deckReader->readBoard($deckBoardId);
		if ($deckBoard === null) {
			throw new DoesNotExistException('Deck board ' . $deckBoardId . ' does not exist');
		}

		$board = $this->boardService->create($deckBoard['title'], $deckBoard['color'], $actorUid);
		$boardId = $board->getId();
		$now = time();

		// Labels first, so card assignments can reference the new ids.
		$labelIdMap = [];
		foreach ($this->deckReader->readLabels($deckBoardId) as $dl) {
			$label = new Label();
			$label->setBoardId($boardId);
			$label->setTitle($dl['title']);
			$label->setColor($dl['color']);
			$labelIdMap[$dl['id']] = $this->labelMapper->insert($label)->getId();
		}

		// Stacks (order preserved via sequential sort keys), then their cards.
		$cardIdMap = [];
		$deckCardIds = [];
		$stackCount = 0;
		$cardCount = 0;
		$stackKey = null;
		foreach ($this->deckReader->readStacks($deckBoardId) as $ds) {
			$stack = new Stack();
			$stack->setBoardId($boardId);
			$stack->setTitle($ds['title']);
			$stackKey = $stackKey === null ? $this->sortKeyService->initial() : $this->sortKeyService->after($stackKey);
			$stack->setSortKey($stackKey);
			$stack->setArchived(false);
			$stack->setRole(Stack::ROLE_NONE);
			$stack->setWipLimit(null);
			$stack->setDeletedAt(0);
			$newStackId = $this->stackMapper->insert($stack)->getId();
			$stackCount++;

			$cardKey = null;
			foreach ($this->deckReader->readCards($ds['id']) as $dc) {
				$card = new Card();
				$card->setBoardId($boardId);
				$card->setStackId($newStackId);
				$card->setTitle($dc['title']);
				$card->setDescription($dc['description']);
				$cardKey = $cardKey === null ? $this->sortKeyService->initial() : $this->sortKeyService->after($cardKey);
				$card->setSortKey($cardKey);
				$card->setDuedate($dc['duedate'] !== null ? (new \DateTime())->setTimestamp($dc['duedate']) : null);
				$card->setDoneAt($dc['doneAt']);
				$card->setArchived($dc['archived']);
				$card->setOwner($actorUid);
				$card->setCreatedAt($dc['createdAt'] > 0 ? $dc['createdAt'] : $now);
				$card->setLastModified($now);
				$card->setDeletedAt(0);
				$card->setParentCardId(null);
				$card->setPriority(0);
				$newCardId = $this->cardMapper->insert($card)->getId();
				$cardIdMap[$dc['id']] = $newCardId;
				$deckCardIds[] = $dc['id'];
				$cardCount++;
			}
		}

		$this->importLabelAssignments($deckCardIds, $cardIdMap, $labelIdMap);
		$this->importUserAssignees($deckCardIds, $cardIdMap);

		return [
			'boardId' => $boardId,
			'title' => $board->getTitle(),
			'stacks' => $stackCount,
			'cards' => $cardCount,
			'labels' => count($labelIdMap),
		];
	}

	/**
	 * @param int[] $deckCardIds
	 * @param array<int, int> $cardIdMap deck card id → kanso card id
	 * @param array<int, int> $labelIdMap deck label id → kanso label id
	 */
	private function importLabelAssignments(array $deckCardIds, array $cardIdMap, array $labelIdMap): void {
		foreach ($this->deckReader->readAssignedLabels($deckCardIds) as $deckCardId => $labelIds) {
			$newCardId = $cardIdMap[$deckCardId] ?? null;
			if ($newCardId === null) {
				continue;
			}
			foreach ($labelIds as $deckLabelId) {
				$newLabelId = $labelIdMap[$deckLabelId] ?? null;
				if ($newLabelId !== null) {
					$this->cardLabelMapper->insertAssignment($newCardId, $newLabelId);
				}
			}
		}
	}

	/**
	 * @param int[] $deckCardIds
	 * @param array<int, int> $cardIdMap deck card id → kanso card id
	 */
	private function importUserAssignees(array $deckCardIds, array $cardIdMap): void {
		foreach ($this->deckReader->readAssignedUsers($deckCardIds) as $deckCardId => $uids) {
			$newCardId = $cardIdMap[$deckCardId] ?? null;
			if ($newCardId === null) {
				continue;
			}
			foreach ($uids as $uid) {
				// Only assign users that still exist on this instance.
				if ($this->userManager->userExists($uid)) {
					$this->cardAssigneeMapper->insertAssignment($newCardId, $uid);
				}
			}
		}
	}
}
