<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;

final class AiActorName {
	public static function normalize(Board $board, ?string $actorName): ?string {
		$name = $actorName !== null ? trim($actorName) : null;
		if ($name === '') {
			$name = null;
		}
		if ($name !== null && $board->getAllowAiActors() !== true) {
			throw new InvalidInputException('This board does not allow AI actors');
		}
		if ($name !== null && mb_strlen($name) > 255) {
			throw new InvalidInputException('Actor name is too long');
		}
		return $name;
	}
}
