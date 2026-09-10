<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service\Mail;

/**
 * Hands out a fresh {@see ImapClient} per poll.
 *
 * A factory rather than an injected client because a client owns one socket and
 * one tag sequence: reusing an instance across two mailboxes would talk to the
 * second server on the first one's connection. It is also the seam the unit
 * tests replace to drive a scripted server without a network.
 */
class ImapClientFactory {
	public function __construct(
		private MailHostGuard $hostGuard,
	) {
	}

	public function create(): ImapClient {
		return new ImapClient(new StreamImapTransport(), $this->hostGuard);
	}
}
