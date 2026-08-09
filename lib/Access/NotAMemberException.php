<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Access;

use OCA\Kanso\Service\NotPermittedException;

/**
 * Thrown by {@see BoardAccess::contextFor()} when the user matches no ACL
 * row of the board (directly or via a group) and does not own it - i.e.
 * there is no membership to resolve a role from. Extends
 * NotPermittedException so the API layer's existing 403 mapping applies
 * unchanged when read paths adopt the resolver (epic 3).
 */
class NotAMemberException extends NotPermittedException {
}
