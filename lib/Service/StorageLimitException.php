<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

/**
 * A write would push Kanso's stored attachment bytes past the instance-wide cap
 * an administrator configured
 * ({@see CardAttachmentService::KEY_ATTACHMENT_STORAGE_LIMIT}). Mapped to HTTP
 * 413 by the controllers; the message is safe to expose (it names no path and
 * no other user's data).
 *
 * Deliberately NOT an {@see InvalidInputException}: the request itself is
 * perfectly valid, it is the server that has no room, so it must not be
 * laundered into a 400. Reads, downloads and deletes are unaffected - an
 * over-cap instance can always be emptied again.
 */
class StorageLimitException extends \Exception {
}
