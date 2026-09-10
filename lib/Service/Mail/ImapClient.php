<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service\Mail;

use OCA\Kanso\Db\MailIntake;

/**
 * The slice of IMAP4rev1 (RFC 3501) email intake actually needs: connect,
 * authenticate, select a mailbox, list the UIDs that arrived since last time,
 * and fetch those messages whole.
 *
 * Deliberately NOT a general IMAP library. No IDLE, no partial fetch, no server
 * search beyond a UID range, no flag writes at all - {@see fetchMessage} uses
 * `BODY.PEEK[]` so polling never marks a human's mail as read.
 *
 * Intake position is tracked by a UID WATERMARK ({@see MailIntake} `lastUid`),
 * not by the \Seen flag. That choice is what makes the poller safe to point at
 * a mailbox a person also reads: opening a message in a mail client cannot make
 * the poller skip it, and marking one unread cannot make it card the message
 * twice.
 *
 * All I/O failures surface as {@see ImapException}.
 */
class ImapClient {
	/** Connect/read timeout. A poll runs inside cron; it must not hang a worker. */
	private const TIMEOUT_SECONDS = 30;

	/**
	 * Hard ceiling on one fetched message. A mailbox someone mails a DVD image
	 * to must not OOM the cron worker. An oversized message is drained (so the
	 * stream stays in sync) and reported as empty - the caller skips it and
	 * still advances the watermark, so one huge message cannot wedge intake.
	 */
	private const MAX_MESSAGE_BYTES = 5 * 1024 * 1024;

	/** Bounds a single response against a server that never sends its tag. */
	private const MAX_RESPONSE_LINES = 10_000;

	/** How many bytes are pulled per read when draining an oversized literal. */
	private const DRAIN_CHUNK_BYTES = 65_536;

	private int $tagCounter = 0;

	private bool $connected = false;

	public function __construct(
		private ImapTransport $transport,
		private MailHostGuard $hostGuard,
	) {
	}

	/**
	 * Opens the connection and completes TLS. `$encryption` is one of
	 * {@see MailIntake::ENCRYPTION_SSL} (implicit TLS) or
	 * {@see MailIntake::ENCRYPTION_TLS} (STARTTLS). There is no cleartext mode:
	 * a plaintext LOGIN would put the mailbox password on the wire.
	 *
	 * @throws ImapException
	 */
	public function connect(string $host, int $port, string $encryption): void {
		$this->assertArgumentSafe($host, 'host');

		// Resolved and vetted BEFORE the socket opens, and the vetted address is
		// what we dial - see MailHostGuard for why validating the name alone is
		// not enough.
		$address = $this->hostGuard->resolve($host);

		$implicitTls = $encryption === MailIntake::ENCRYPTION_SSL;
		$this->transport->open($address, $host, $port, $implicitTls, self::TIMEOUT_SECONDS);
		$this->connected = true;

		// The greeting arrives unprompted, before any command. PREAUTH means the
		// server has already authenticated us (rare, but legal); BYE is a refusal.
		$greeting = $this->transport->readLine();
		if (str_starts_with($greeting, '* BYE')) {
			throw new ImapException('IMAP server refused the connection');
		}
		if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
			throw new ImapException('Unexpected IMAP greeting');
		}

		if (!$implicitTls) {
			// STARTTLS is a normal tagged command; only after its OK may the
			// socket be upgraded. Everything before this point was cleartext,
			// which is why no credential has been sent yet.
			$this->command('STARTTLS');
			$this->transport->enableCrypto();
		}
	}

	/**
	 * @throws ImapException if the server rejects the credentials
	 */
	public function login(string $username, string $password): void {
		// A CR or LF here would end the LOGIN line early and let the remainder be
		// read as a further command - command injection through a config field.
		// Rejecting is correct rather than escaping: no real credential contains
		// a line break.
		$this->assertArgumentSafe($username, 'username');
		$this->assertArgumentSafe($password, 'password');

		try {
			$this->command('LOGIN ' . $this->quote($username) . ' ' . $this->quote($password));
		} catch (ImapException $e) {
			// The server's NO text is echoed into the board's `lastError`, and on
			// some servers that text quotes the command back. Replace it with a
			// fixed string so a password can never reach the config UI or the log.
			throw new ImapException('IMAP login failed - check the username and password');
		}
	}

	/**
	 * Selects the mailbox READ-ONLY (EXAMINE, not SELECT) - intake never needs
	 * write access, and read-only makes it structurally impossible for a bug
	 * here to delete or re-flag a person's mail.
	 *
	 * @return array{uidValidity: int, uidNext: int}
	 * @throws ImapException if the mailbox does not exist
	 */
	public function selectMailbox(string $mailbox): array {
		$this->assertArgumentSafe($mailbox, 'mailbox');

		$response = $this->command('EXAMINE ' . $this->quote($mailbox));

		$uidValidity = 0;
		$uidNext = 0;
		foreach ($response['untagged'] as $entry) {
			if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $entry['line'], $m) === 1) {
				$uidValidity = (int)$m[1];
			}
			if (preg_match('/\[UIDNEXT (\d+)\]/i', $entry['line'], $m) === 1) {
				$uidNext = (int)$m[1];
			}
		}

		if ($uidValidity === 0) {
			// Every compliant server sends UIDVALIDITY on select. Without it the
			// watermark cannot be trusted, and guessing would risk replaying the
			// whole mailbox as cards.
			throw new ImapException('IMAP server did not report UIDVALIDITY for ' . $mailbox);
		}

		return ['uidValidity' => $uidValidity, 'uidNext' => $uidNext];
	}

	/**
	 * The UIDs strictly greater than `$lastUid`, ascending.
	 *
	 * The client-side filter is not redundant. IMAP ranges are inclusive and `*`
	 * means "the highest UID in the mailbox", and a range is normalised rather
	 * than treated as empty when its endpoints are reversed - so `UID 9:*` in a
	 * mailbox whose highest UID is 4 matches `4:9` and returns message 4, one
	 * already carded. Every real server does this; it is the single most common
	 * way a home-grown IMAP poller ends up duplicating messages.
	 *
	 * @return int[]
	 * @throws ImapException
	 */
	public function searchUidsAbove(int $lastUid): array {
		$from = max(0, $lastUid) + 1;
		$response = $this->command('UID SEARCH UID ' . $from . ':*');

		$uids = [];
		foreach ($response['untagged'] as $entry) {
			// '* SEARCH 12 15 18', or '* SEARCH' alone when nothing matched.
			if (preg_match('/^\*\s+SEARCH\b(.*)$/i', $entry['line'], $m) !== 1) {
				continue;
			}
			foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $token) {
				if ($token !== '' && ctype_digit($token)) {
					$uid = (int)$token;
					if ($uid > $lastUid) {
						$uids[] = $uid;
					}
				}
			}
		}

		$uids = array_values(array_unique($uids));
		sort($uids);
		return $uids;
	}

	/**
	 * The complete RFC 822 source of one message, or '' if it exceeds
	 * {@see MAX_MESSAGE_BYTES} (drained and skipped rather than fetched).
	 *
	 * `BODY.PEEK[]` rather than `BODY[]`: the latter sets \Seen as a side effect
	 * of reading, which would silently mark a shared mailbox's mail as read.
	 *
	 * @throws ImapException
	 */
	public function fetchMessage(int $uid): string {
		$response = $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[])');

		foreach ($response['untagged'] as $entry) {
			if ($entry['literal'] !== null && preg_match('/\bFETCH\b/i', $entry['line']) === 1) {
				return $entry['literal'];
			}
		}

		// A UID that vanished between SEARCH and FETCH (someone deleted the mail
		// mid-poll) answers with a tagged OK and no FETCH data. Not an error -
		// the caller skips it and moves the watermark past it.
		return '';
	}

	public function disconnect(): void {
		if ($this->connected) {
			try {
				$this->command('LOGOUT');
			} catch (ImapException) {
				// A server that hangs up on LOGOUT is not a problem worth raising -
				// we are closing the socket next anyway.
			}
			$this->connected = false;
		}
		$this->transport->close();
	}

	// ---- protocol plumbing -------------------------------------------------

	/**
	 * Sends one tagged command and reads through to its tagged completion.
	 *
	 * @return array{untagged: list<array{line: string, literal: string|null}>, tagged: string}
	 * @throws ImapException if the server answers NO or BAD
	 */
	private function command(string $command): array {
		$tag = sprintf('K%03d', ++$this->tagCounter);
		$this->transport->writeLine($tag . ' ' . $command);
		$response = $this->readUntilTagged($tag);

		// 'TAG OK ...' is success; 'TAG NO ...' is a refusal, 'TAG BAD ...' a
		// protocol complaint. Both carry server text worth keeping for the UI -
		// except after LOGIN, where the caller replaces it.
		if (preg_match('/^' . preg_quote($tag, '/') . '\s+OK\b/i', $response['tagged']) !== 1) {
			$detail = trim(substr($response['tagged'], strlen($tag)));
			throw new ImapException($detail === '' ? 'IMAP command failed' : $detail);
		}

		return $response;
	}

	/**
	 * Reads untagged responses until the tagged completion line for `$tag`.
	 *
	 * Literals are the reason this cannot be a plain line loop: `{123}` at the
	 * end of a line announces exactly 123 following bytes that are counted, not
	 * delimited, and may contain CRLF - reading them as lines would desynchronise
	 * the connection for every later command.
	 *
	 * @return array{untagged: list<array{line: string, literal: string|null}>, tagged: string}
	 * @throws ImapException
	 */
	private function readUntilTagged(string $tag): array {
		$untagged = [];
		$lineCount = 0;

		while (true) {
			if (++$lineCount > self::MAX_RESPONSE_LINES) {
				throw new ImapException('IMAP response exceeded the line limit');
			}

			$line = $this->transport->readLine();

			if (preg_match('/\{(\d+)\}$/', $line, $m) === 1) {
				$length = (int)$m[1];
				if ($length > self::MAX_MESSAGE_BYTES) {
					// Drain it anyway: the bytes are already committed by the server,
					// so skipping the read would leave them queued as garbage in front
					// of every subsequent response.
					$this->drain($length);
					$untagged[] = ['line' => $line, 'literal' => null];
				} else {
					$untagged[] = ['line' => $line, 'literal' => $this->transport->readBytes($length)];
				}
				// The rest of this response continues on the next line read.
				continue;
			}

			if (str_starts_with($line, $tag . ' ')) {
				return ['untagged' => $untagged, 'tagged' => $line];
			}

			if (str_starts_with($line, '+')) {
				// A continuation request means the server wants literal data we never
				// offered to send. Nothing this client sends can prompt one, so it
				// means the connection is out of sync - keep talking and every later
				// response is misread.
				throw new ImapException('Unexpected IMAP continuation request');
			}

			$untagged[] = ['line' => $line, 'literal' => null];
		}
	}

	/**
	 * Reads and discards `$length` bytes in bounded chunks, so draining an
	 * oversized literal costs a fixed amount of memory rather than the whole
	 * message.
	 *
	 * @throws ImapException
	 */
	private function drain(int $length): void {
		$remaining = $length;
		while ($remaining > 0) {
			$chunk = min($remaining, self::DRAIN_CHUNK_BYTES);
			$this->transport->readBytes($chunk);
			$remaining -= $chunk;
		}
	}

	/**
	 * IMAP quoted-string form. Backslash and double-quote are the only
	 * characters that need escaping inside one; CR/LF are excluded upstream by
	 * {@see assertArgumentSafe} because no escape for them exists in this form.
	 */
	private function quote(string $value): string {
		return '"' . addcslashes($value, '\\"') . '"';
	}

	/**
	 * @throws ImapException if the value could break out of its command line
	 */
	private function assertArgumentSafe(string $value, string $what): void {
		if (preg_match('/[\r\n\x00]/', $value) === 1) {
			throw new ImapException('Invalid ' . $what . ': line breaks are not allowed');
		}
	}
}
