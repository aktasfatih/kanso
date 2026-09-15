<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCP\AppFramework\Db\DoesNotExistException;

/**
 * A public board link that RESOLVED but has passed its expiry instant.
 *
 * Deliberately a SUBCLASS of {@see DoesNotExistException}: every existing
 * `catch (DoesNotExistException)` on the public path keeps catching it, so the
 * default answer is still the throttled, indistinguishable 404 that keeps the
 * token space un-enumerable. Only {@see \OCA\Kanso\Controller\PublicShareController::show()}
 * - the HTML page a human lands on - catches it separately, so a visitor is told
 * the link EXPIRED instead of being left to wonder whether they mistyped the URL.
 *
 * Why that extra answer is safe, stated explicitly because this is the public
 * surface: reaching it requires presenting the board's CURRENT 64-char token.
 * A rotated or disabled token clears/replaces the column, so it resolves to
 * nothing at all and still gets the generic page; guessing a live token is
 * ~380 bits of work behind a brute-force throttle. So the only person who can
 * ever see the "expired" page is somebody who was handed the link - and telling
 * them the truth is the whole point. The machine-readable payload endpoint
 * ({@see \OCA\Kanso\Controller\PublicShareController::data()}) is deliberately
 * NOT widened: it keeps one uniform `not_found` for every rejection reason.
 */
class PublicShareExpiredException extends DoesNotExistException {
}
