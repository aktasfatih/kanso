<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

/**
 * An optimistic-concurrency conflict on a card description: the caller supplied
 * a base version (`baseLastModified`) that is older than the card's current
 * `lastModified`, AND the description it wants to write differs from what is
 * stored - so accepting the write would silently discard somebody else's text.
 *
 * A best-effort guard rather than a lock: `lastModified` has second resolution
 * and the check is read-then-write, so a competing write inside either window
 * still gets through (see the guard in {@see CardService::update()}).
 *
 * Mapped to HTTP 409 {"error": "description_conflict", ...} by
 * {@see \OCA\Kanso\Controller\ApiErrorTrait}, matching the existing
 * `rebalance_required` conflict shape. The exception carries the CURRENT stored
 * description and version so the client can show both sides and let the user
 * recover their own text - nothing is discarded on either end.
 */
class DescriptionConflictException extends \Exception {
	public function __construct(
		private string $currentDescription,
		private int $currentLastModified,
	) {
		parent::__construct('description_conflict');
	}

	public function getCurrentDescription(): string {
		return $this->currentDescription;
	}

	public function getCurrentLastModified(): int {
		return $this->currentLastModified;
	}
}
