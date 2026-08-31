<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

/**
 * INTERNAL marker: the description-revision compare-and-set
 * (`claimDescriptionRevision()`) affected 0 rows, so somebody else's
 * description write landed first.
 *
 * Thrown from inside the {@see CardService::writeCardChange()} callback purely
 * to roll that transaction back (the card row, its change row and its detail row
 * all go away together, and the realtime push never fires). CardService catches
 * it immediately after, re-reads the card OUTSIDE the doomed transaction and
 * rethrows the public {@see DescriptionConflictException}.
 *
 * Never leaves the service layer and is deliberately NOT mapped in
 * {@see \OCA\Kanso\Controller\ApiErrorTrait} - if one ever escaped, a 500 is the
 * honest answer.
 */
class StaleDescriptionRevisionException extends \Exception {
}
