<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

/**
 * The acting user lacks the required permission on a board.
 * Mapped to HTTP 403 by the controllers.
 */
class NotPermittedException extends \Exception {
}
