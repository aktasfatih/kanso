<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\CardFeatures;
use OCA\Kanso\Db\CardMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;

class BoardTimeStatsService {
	public function __construct(
		private CardMapper $cardMapper,
		private IUserManager $userManager,
		private ITimeFactory $timeFactory,
	) {
	}

	/** @return array{enabled: bool, from: string, to: string, totalSeconds: int, byUser: list<array{uid: string, displayName: string, actorName: ?string, seconds: int}>} */
	public function get(Board $board, ViewerContext $viewer, ?string $from = null, ?string $to = null): array {
		$today = gmdate('Y-m-d', $this->timeFactory->getTime());
		$end = $this->parseDate($to ?? $today);
		$start = $this->parseDate($from ?? $end->modify('-29 days')->format('Y-m-d'));
		$until = $end->modify('+1 day')->getTimestamp();
		if ($start > $end || $until - $start->getTimestamp() > 366 * 86400) {
			throw new InvalidInputException('Choose a date range of 1 to 366 days');
		}

		$enabled = CardFeatures::decode($board->getDisabledCardFeatures())[CardFeatures::TIME_TRACKING];
		$rows = $enabled ? $this->cardMapper->timeSpentByUser($board->getId(), $start->getTimestamp(), $until, $viewer) : [];
		$names = [];
		$byUser = [];
		$total = 0;
		foreach ($rows as $row) {
			$uid = $row['uid'];
			$names[$uid] ??= $this->userManager->getDisplayName($uid) ?? $uid;
			$byUser[] = $row + ['displayName' => $names[$uid]];
			$total += $row['seconds'];
		}

		return [
			'enabled' => $enabled,
			'from' => $start->format('Y-m-d'),
			'to' => $end->format('Y-m-d'),
			'totalSeconds' => $total,
			'byUser' => $byUser,
		];
	}

	private function parseDate(string $date): \DateTimeImmutable {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1
			|| !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
			throw new InvalidInputException('Dates must be YYYY-MM-DD');
		}
		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
		if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
			throw new InvalidInputException('Dates must be YYYY-MM-DD');
		}
		return $parsed;
	}
}
