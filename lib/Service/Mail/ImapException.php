<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service\Mail;

/**
 * Anything that stops an IMAP poll: a refused connection, a failed TLS upgrade,
 * bad credentials, a missing mailbox, a protocol answer we cannot act on.
 *
 * The message is surfaced to board managers in the intake config (`lastError`),
 * so it must stay short and free of the credential.
 */
class ImapException extends \RuntimeException {
}
