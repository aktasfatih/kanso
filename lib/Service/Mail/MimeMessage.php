<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service\Mail;

/**
 * The handful of things email intake takes from a message, already decoded to
 * UTF-8 by {@see MimeParser}. Everything else in an email - routing headers,
 * alternative parts, inline images - is deliberately dropped.
 *
 * `attachmentNames` records what was attached WITHOUT storing the bytes.
 * Intake does not create card attachments (that is a separate, larger piece of
 * work); naming the files in the card body is the difference between a person
 * knowing something was left behind and silently losing it.
 */
class MimeMessage {
	/**
	 * @param string $fromAddress lowercased sender address, '' when unparseable
	 * @param string $fromName the sender's display name, '' when absent
	 * @param string $subject decoded Subject, '' when absent
	 * @param string $body the text body, UTF-8, '' when the message had none
	 * @param string[] $attachmentNames file names of the attached parts
	 */
	public function __construct(
		public readonly string $fromAddress,
		public readonly string $fromName,
		public readonly string $subject,
		public readonly string $body,
		public readonly array $attachmentNames = [],
	) {
	}

	/** The sender as a person reads it: "Name <addr>", or just the address. */
	public function fromLabel(): string {
		if ($this->fromName !== '' && $this->fromAddress !== '') {
			return $this->fromName . ' <' . $this->fromAddress . '>';
		}
		return $this->fromName !== '' ? $this->fromName : $this->fromAddress;
	}
}
