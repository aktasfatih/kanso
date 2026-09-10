<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

/**
 * The user has already imported this Deck board and did not confirm that they
 * want a second copy (#10300).
 *
 * This is a CONFLICT, not a failure: nothing was written, and the import is
 * still available - the caller only has to ask first. It deliberately carries
 * no board id; "did my first attempt land?" is answered by re-opening the
 * picker, where the board now shows when it was imported.
 */
class AlreadyImportedException extends \RuntimeException {
}
