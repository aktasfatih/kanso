<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\CardContactMapper;
use OCA\Kanso\Db\CardLink;
use OCA\Kanso\Db\CardLinkMapper;
use OCA\Kanso\Db\CardTimeEntry;
use OCA\Kanso\Db\CardTimeEntryMapper;

class CardMoveMetadataService {
	public function __construct(
		private CardContactMapper $contacts,
		private CardLinkMapper $links,
		private CardTimeEntryMapper $timeEntries,
	) {
	}

	public function copy(int $sourceId, int $targetId, Board $targetBoard): void {
		$entries = $this->timeEntries->findByCard($sourceId);
		foreach ($entries as $entry) {
			if ($entry->getActorName() !== null && $targetBoard->getAllowAiActors() !== true) {
				throw new InvalidInputException('The destination board does not allow AI-attributed time entries');
			}
		}

		foreach ($this->contacts->findContactsByCard($sourceId) as $contact) {
			$this->contacts->insertLink($targetId, $contact['contactUri'], $contact['displayName']);
		}

		foreach ($this->links->findAllByCard($sourceId) as $link) {
			$copy = new CardLink();
			$copy->setCardId($targetId);
			$copy->setProvider($link->getProvider());
			$copy->setUrl($link->getUrl());
			$copy->setKind($link->getKind());
			$copy->setState($link->getState());
			$copy->setTitle($link->getTitle());
			$copy->setLastPolled($link->getLastPolled());
			$copy->setCreatedAt($link->getCreatedAt());
			$this->links->insert($copy);
		}

		// findByCard returns newest first; insert oldest first to retain that order.
		foreach (array_reverse($entries) as $entry) {
			$copy = new CardTimeEntry();
			$copy->setCardId($targetId);
			$copy->setBoardId($targetBoard->getId());
			$copy->setSeconds($entry->getSeconds());
			$copy->setNote($entry->getNote());
			$copy->setCreatedBy($entry->getCreatedBy());
			$copy->setActorName($entry->getActorName());
			$copy->setCreatedAt($entry->getCreatedAt());
			$this->timeEntries->insert($copy);
		}
	}
}
