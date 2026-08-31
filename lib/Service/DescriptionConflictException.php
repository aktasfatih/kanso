<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

/**
 * An optimistic-concurrency conflict on a card description: the caller supplied
 * a base version the card has already moved past, AND the description it wants
 * to write differs from what is stored - so accepting the write would silently
 * discard somebody else's text.
 *
 * Two callers can raise it:
 *  - `baseDescriptionRevision` (#9848, what the browser editor sends): a real
 *    per-card revision counter, enforced by a single conditional UPDATE
 *    (`claimDescriptionRevision()`) inside the write transaction. Exactly one of
 *    two racing writers can win, whatever the timing.
 *  - `baseLastModified` (#9845, the original API parameter, kept working for
 *    clients written against it): a coarse best-effort guard - `lastModified`
 *    has second resolution and the check is read-then-write, so a competing
 *    write inside either window still gets through.
 *
 * Mapped to HTTP 409 {"error": "description_conflict", ...} by
 * {@see \OCA\Kanso\Controller\ApiErrorTrait}, matching the existing
 * `rebalance_required` conflict shape. The exception carries the CURRENT stored
 * description and both version tokens so the client can show both sides and let
 * the user recover their own text - nothing is discarded on either end.
 */
class DescriptionConflictException extends \Exception {
	public function __construct(
		private string $currentDescription,
		private int $currentLastModified,
		private int $currentRevision,
	) {
		parent::__construct('description_conflict');
	}

	public function getCurrentDescription(): string {
		return $this->currentDescription;
	}

	public function getCurrentLastModified(): int {
		return $this->currentLastModified;
	}

	/** The card's current `description_revision` - the base a retry must use. */
	public function getCurrentRevision(): int {
		return $this->currentRevision;
	}
}
