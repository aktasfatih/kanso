<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

/**
 * A request carried invalid input (empty/overlong title, malformed color).
 * Mapped to HTTP 400 by the controllers; the message is safe to expose.
 */
class InvalidInputException extends \Exception {
}
