<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service\Mail;

/**
 * The verdict an `Authentication-Results:` header records (RFC 8601).
 *
 * Kanso cannot check SPF or DKIM itself: both need the SMTP envelope and the
 * message as it arrived, and by the time a message is sitting in an IMAP
 * mailbox the envelope is long gone. What CAN be done is read the check the
 * receiving MTA already performed and stamped onto the message.
 *
 * That is only trustworthy under one condition, and it is the whole reason this
 * class is careful: `Authentication-Results` is an ORDINARY header, so a sender
 * can simply include a forged one claiming `dmarc=pass`. Each hop PREPENDS its
 * own, so the TOPMOST occurrence is the one written by the last MTA to touch
 * the message - our own. Anything below it is the sender's to invent.
 * {@see MimeParser} keeps headers in arrival order for exactly this reason, and
 * only the first is ever passed here.
 *
 * Even the topmost is only as trustworthy as the deployment: it means nothing
 * if mail reaches the mailbox without passing through an MTA that authenticates
 * (a mailbox fed directly by a third party, say). That is a deployment property
 * we cannot detect, so the admin doc states it and the option is off by default.
 */
class AuthenticationResults {
	/**
	 * @param array<string, string> $methods method => result, lowercased
	 *                                       (e.g. ['dmarc' => 'pass', 'spf' => 'fail'])
	 */
	private function __construct(
		private readonly array $methods,
		private readonly string $dmarcFromDomain,
	) {
	}

	/**
	 * Parses the TOPMOST Authentication-Results header. `$raw` empty (no header
	 * at all) yields an instance whose checks all report "not verified".
	 */
	public static function fromHeader(string $raw): self {
		$methods = [];
		$dmarcFromDomain = '';

		// 'mx.example.com; dmarc=pass header.from=example.com; spf=pass ...'
		// Method results are 'method=result', optionally followed by
		// 'ptype.property=value' pairs that must NOT be read as further methods.
		if (preg_match_all('/\b(dmarc|spf|dkim)\s*=\s*([a-z]+)/i', $raw, $matches, PREG_SET_ORDER) !== false) {
			foreach ($matches as $match) {
				$method = strtolower($match[1]);
				// First occurrence wins: a message can carry several dkim= results
				// (one per signature) and any single pass is what senders rely on,
				// but for our purposes the leading verdict is the summary one.
				if (!isset($methods[$method])) {
					$methods[$method] = strtolower($match[2]);
				}
			}
		}

		if (preg_match('/header\.from\s*=\s*"?([^\s;"]+)"?/i', $raw, $m) === 1) {
			$dmarcFromDomain = strtolower(trim($m[1]));
		}

		return new self($methods, $dmarcFromDomain);
	}

	/**
	 * Whether the message is authenticated as genuinely coming from the domain
	 * its `From:` claims.
	 *
	 * DMARC is the check that matters, because it is the only one that ties the
	 * authentication to the HEADER From - the address a person reads and the
	 * address the allowlist matches. SPF alone authenticates the envelope
	 * sender, which can differ entirely from the visible From, so an
	 * SPF-pass-only message is NOT accepted as proof of who sent it.
	 *
	 * The `header.from` in the header is cross-checked against the address we
	 * parsed, so a `dmarc=pass` for some other domain cannot vouch for this one.
	 */
	public function authenticates(string $fromAddress): bool {
		if (($this->methods['dmarc'] ?? '') !== 'pass') {
			return false;
		}

		$at = strrpos($fromAddress, '@');
		if ($at === false) {
			return false;
		}
		$fromDomain = strtolower(substr($fromAddress, $at + 1));
		if ($fromDomain === '') {
			return false;
		}

		// A dmarc=pass with no header.from to check it against cannot be tied to
		// this message's sender.
		if ($this->dmarcFromDomain === '') {
			return false;
		}

		// DMARC alignment allows an organisational-domain match, so a pass for
		// 'example.com' covers 'mail.example.com'.
		return $fromDomain === $this->dmarcFromDomain
			|| str_ends_with($fromDomain, '.' . $this->dmarcFromDomain);
	}

	/** The raw verdict for one method, '' when the header did not report it. */
	public function result(string $method): string {
		return $this->methods[strtolower($method)] ?? '';
	}
}
