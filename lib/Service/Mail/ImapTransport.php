<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service\Mail;

/**
 * The byte pipe {@see ImapClient} speaks over, kept behind an interface for one
 * reason: the protocol logic is the part worth testing, and a real socket makes
 * that untestable. {@see StreamImapTransport} is the production implementation;
 * the unit tests drive a scripted fake.
 *
 * Implementations throw {@see ImapException} on any I/O failure - the client
 * never inspects error codes.
 */
interface ImapTransport {
	/**
	 * Opens the connection. `$implicitTls` selects TLS from the first byte
	 * (port 993); a STARTTLS upgrade is a separate {@see enableCrypto} call
	 * after the greeting.
	 *
	 * @throws ImapException if the connection cannot be established
	 */
	public function open(string $host, int $port, bool $implicitTls, int $timeoutSeconds): void;

	/**
	 * Upgrades an already-open cleartext connection to TLS (the STARTTLS half of
	 * the handshake, called once the server has answered OK).
	 *
	 * @throws ImapException if the upgrade fails
	 */
	public function enableCrypto(): void;

	/**
	 * Writes one command line. The CRLF terminator is the implementation's job,
	 * so no caller can forget it.
	 *
	 * @throws ImapException on a short or failed write
	 */
	public function writeLine(string $line): void;

	/**
	 * Reads one CRLF-terminated line, WITHOUT the terminator.
	 *
	 * @throws ImapException on timeout or if the peer closed mid-response
	 */
	public function readLine(): string;

	/**
	 * Reads exactly `$length` bytes - an IMAP literal, which is counted, not
	 * delimited, and may contain CRLF anywhere inside it.
	 *
	 * @throws ImapException if the peer closes before `$length` bytes arrive
	 */
	public function readBytes(int $length): string;

	/** Closes the connection. Never throws; a close failure is not actionable. */
	public function close(): void;
}
