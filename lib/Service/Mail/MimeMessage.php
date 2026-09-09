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
 * Beyond the content there are three CLASSIFICATION flags, computed by the
 * parser because that is what holds the headers, and acted on by
 * {@see \OCA\Kanso\Service\MailIntakeService} because that is what holds the
 * policy:
 *
 * - `isAutomated` - the message is machine-generated (an out-of-office reply, a
 *   bounce, a mailing-list post). Carding these is how an intake mailbox and a
 *   notification mailbox saw each other into an infinite card loop.
 * - `isSpam` - the upstream filter already judged it. Kanso does not
 *   re-implement spam detection; it honours the verdict of whatever did.
 * - `authentication` - the receiving MTA's SPF/DKIM/DMARC verdict, for mailboxes
 *   configured to require one.
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
	 * @param string $messageId the RFC 5322 Message-ID, '' when absent
	 * @param bool $isAutomated the message is machine-generated
	 * @param bool $isSpam an upstream filter flagged it
	 */
	public function __construct(
		public readonly string $fromAddress,
		public readonly string $fromName,
		public readonly string $subject,
		public readonly string $body,
		public readonly array $attachmentNames = [],
		public readonly string $messageId = '',
		public readonly bool $isAutomated = false,
		public readonly bool $isSpam = false,
		public readonly ?AuthenticationResults $authentication = null,
	) {
	}

	/** The sender as a person reads it: "Name <addr>", or just the address. */
	public function fromLabel(): string {
		if ($this->fromName !== '' && $this->fromAddress !== '') {
			return $this->fromName . ' <' . $this->fromAddress . '>';
		}
		return $this->fromName !== '' ? $this->fromName : $this->fromAddress;
	}

	/**
	 * Whether the receiving MTA authenticated this message as really coming from
	 * the domain its From claims. False when no verdict was recorded at all.
	 */
	public function isAuthenticated(): bool {
		return $this->authentication?->authenticates($this->fromAddress) ?? false;
	}

	/**
	 * A stable dedupe key: the Message-ID when the sender supplied one, else a
	 * hash of the parts of the message that identify it.
	 *
	 * The fallback matters because Message-ID is optional in practice - plenty
	 * of automated senders omit it - and a mailbox that re-delivers such a
	 * message would otherwise card it twice.
	 */
	public function dedupeKey(): string {
		if ($this->messageId !== '') {
			return 'mid:' . hash('sha256', $this->messageId);
		}
		return 'syn:' . hash('sha256', $this->fromAddress . "\x00" . $this->subject . "\x00" . $this->body);
	}
}
