<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class ParentCompletionService {

	public function __construct(
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private ChecklistItemMapper $checklistItemMapper,
		private CardReviewMapper $cardReviewMapper,
		private ChangeNotifier $changeNotifier,
	) {
	}

	/** @internal The caller must hold the board's card-mutation fence. */
	public function maybeComplete(int $parentId, int $boardId, ?string $uid, bool $push = true): void {
		try {
			$board = $this->boardMapper->find($boardId);
		} catch (DoesNotExistException) {
			return;
		}
		if ($board->getDeletedAt() > 0 || $board->getAutoCompleteParent() !== true) {
			return;
		}
		try {
			// Serialize the gate reads with checklist/review writes on the parent.
			$parent = $this->cardMapper->findForMutation($parentId);
		} catch (DoesNotExistException) {
			return;
		}
		if ($parent->getDeletedAt() > 0 || $parent->getDoneAt() > 0 || $parent->getBoardId() !== $boardId) {
			return;
		}
		$children = $this->cardMapper->findChildren($parentId);
		if ($children === []) {
			return;
		}
		foreach ($children as $child) {
			if ($child->getDoneAt() === 0 && !$child->getArchived()) {
				return;
			}
		}
		foreach ($this->checklistItemMapper->findByCard($parentId) as $item) {
			if (!$item->getDone()) {
				return;
			}
		}
		if ($this->cardReviewMapper->hasUnapprovedReviews($parentId)) {
			return;
		}

		$now = time();
		$parent->setDoneAt($now);
		$parent->setLastModified($now);
		$this->cardMapper->update($parent);
		$this->changeNotifier->recordChange($boardId, Change::ENTITY_CARD, $parentId, Change::ACTION_UPDATE, $uid, Change::VERB_UPDATED);
		if ($push) {
			$this->changeNotifier->pushBoardChanged($boardId);
		}
	}
}
