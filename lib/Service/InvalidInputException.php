<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

/**
 * A request carried invalid input (empty/overlong title, malformed color, or a
 * malformed/misordered sort key derived from stale client state — see
 * {@see SortKeyService}). Mapped to HTTP 400 by the controllers; the message is
 * safe to expose. A genuinely unrelated \InvalidArgumentException is left to
 * surface as a 500 rather than being laundered into a 400.
 */
class InvalidInputException extends \Exception {
}
