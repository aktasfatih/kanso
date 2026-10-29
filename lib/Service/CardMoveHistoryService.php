<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\CardActivityHistory;
use OCA\Kanso\Db\CardActivityHistoryMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeDetailMapper;
use OCA\Kanso\Db\ChangeMapper;

class CardMoveHistoryService {
	private const PAGE_SIZE = 500;

	public function __construct(
		private CardActivityHistoryMapper $history,
		private ChangeMapper $changes,
		private ChangeDetailMapper $details,
	) {
	}

	public function copy(int $sourceCardId, int $targetCardId, int $sourceBoardId): void {
		if ($sourceCardId === $targetCardId) {
			throw new InvalidInputException('Cannot copy card history to the same card');
		}
		$after = 0;
		do {
			$page = $this->history->findPage($sourceCardId, $after, self::PAGE_SIZE);
			foreach ($page as $item) {
				$copy = new CardActivityHistory();
				$copy->setCardId($targetCardId);
				$copy->setAction($item->getAction());
				$copy->setActor($item->getActor());
				$copy->setVerb($item->getVerb());
				$copy->setCreatedAt($item->getCreatedAt());
				$copy->setHasDetail($item->getHasDetail());
				$copy->setFromText($item->getFromText());
				$copy->setToText($item->getToText());
				$this->history->insert($copy);
				$after = $item->getId();
			}
		} while (count($page) === self::PAGE_SIZE);

		$after = 0;
		do {
			$page = $this->changes->findEntityPage($sourceBoardId, Change::ENTITY_CARD, $sourceCardId, $after, self::PAGE_SIZE);
			$changeIds = array_map(static fn (Change $change): int => $change->getId(), $page);
			$details = $this->details->findByChangeIds($changeIds);
			foreach ($page as $change) {
				$detail = $details[$change->getId()] ?? null;
				$copy = new CardActivityHistory();
				$copy->setCardId($targetCardId);
				$copy->setAction($change->getAction());
				$copy->setActor($change->getActor());
				$copy->setVerb($change->getVerb());
				$copy->setCreatedAt($change->getCreatedAt());
				$copy->setHasDetail($detail !== null);
				$copy->setFromText($detail?->getFromText());
				$copy->setToText($detail?->getToText());
				$this->history->insert($copy);
				$after = $change->getId();
			}
		} while (count($page) === self::PAGE_SIZE);
	}
}
