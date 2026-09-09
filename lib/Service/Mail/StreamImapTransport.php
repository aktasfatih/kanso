<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service\Mail;

/**
 * {@see ImapTransport} over a PHP stream socket.
 *
 * Why a hand-rolled socket at all: the Nextcloud runtime ships neither
 * `ext-imap` (dropped from core PHP, absent from the official images) nor any
 * IMAP client library in `3rdparty`, and Kanso ships no `vendor/` of its own -
 * runtime dependencies must already exist on the server. Streams plus
 * `ext-openssl` are always there.
 *
 * TLS is NOT optional and certificates are NOT trusted blindly: `verify_peer`
 * and `verify_peer_name` stay on, so a mailbox credential is never handed to an
 * unauthenticated middlebox. A server with a self-signed certificate fails to
 * connect, loudly, rather than silently downgrading.
 */
class StreamImapTransport implements ImapTransport {
	/** @var resource|null */
	private $stream = null;

	private int $timeoutSeconds = 30;

	#[\Override]
	public function open(string $host, int $port, bool $implicitTls, int $timeoutSeconds): void {
		$this->timeoutSeconds = $timeoutSeconds;

		$context = stream_context_create([
			'ssl' => [
				'verify_peer' => true,
				'verify_peer_name' => true,
				'allow_self_signed' => false,
				'SNI_enabled' => true,
				'peer_name' => $host,
			],
		]);

		// The scheme carries implicit TLS; STARTTLS connects in the clear and is
		// upgraded by enableCrypto() once the server has agreed.
		$scheme = $implicitTls ? 'ssl://' : 'tcp://';
		$errno = 0;
		$errstr = '';
		$stream = @stream_socket_client(
			$scheme . $host . ':' . $port,
			$errno,
			$errstr,
			$timeoutSeconds,
			STREAM_CLIENT_CONNECT,
			$context,
		);

		if ($stream === false) {
			// $errstr can carry the host but never a credential - safe to surface.
			throw new ImapException('Cannot connect to ' . $host . ':' . $port . ' (' . trim($errstr) . ')');
		}

		stream_set_timeout($stream, $timeoutSeconds);
		$this->stream = $stream;
	}

	#[\Override]
	public function enableCrypto(): void {
		$stream = $this->requireStream();
		// CLIENT (not CLIENT_TLS_ANY_CLIENT) so the negotiated version follows
		// PHP's own modern default rather than pinning a version that ages out.
		$ok = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
		if ($ok !== true) {
			throw new ImapException('STARTTLS upgrade failed');
		}
	}

	#[\Override]
	public function writeLine(string $line): void {
		$stream = $this->requireStream();
		$payload = $line . "\r\n";
		$written = @fwrite($stream, $payload);
		if ($written === false || $written < strlen($payload)) {
			throw new ImapException('Write to IMAP server failed');
		}
	}

	#[\Override]
	public function readLine(): string {
		$stream = $this->requireStream();
		$line = @fgets($stream);
		if ($line === false) {
			// Distinguish the two ways fgets fails - a timeout is a server that
			// went quiet, EOF is one that hung up. Both stop the poll, but they
			// point at different problems in the config UI.
			$meta = stream_get_meta_data($stream);
			if (!empty($meta['timed_out'])) {
				throw new ImapException('IMAP server timed out after ' . $this->timeoutSeconds . 's');
			}
			throw new ImapException('IMAP server closed the connection');
		}
		return rtrim($line, "\r\n");
	}

	#[\Override]
	public function readBytes(int $length): string {
		if ($length <= 0) {
			return '';
		}
		$stream = $this->requireStream();
		$buffer = '';
		$remaining = $length;
		while ($remaining > 0) {
			$chunk = @fread($stream, $remaining);
			// '' means EOF or a timed-out read; either way no more bytes are
			// coming and the literal we were promised is incomplete.
			if ($chunk === false || $chunk === '') {
				throw new ImapException('IMAP server closed mid-message');
			}
			$buffer .= $chunk;
			$remaining -= strlen($chunk);
		}
		return $buffer;
	}

	#[\Override]
	public function close(): void {
		// Cleared BEFORE the fclose so the property never holds a closed handle -
		// a second close() (disconnect() is called from a finally, so it happens)
		// must be a no-op rather than an fclose on dead bytes.
		$stream = $this->stream;
		$this->stream = null;
		if ($stream !== null) {
			@fclose($stream);
		}
	}

	/**
	 * @return resource
	 * @throws ImapException if used before open() or after close()
	 */
	private function requireStream() {
		if ($this->stream === null) {
			throw new ImapException('IMAP connection is not open');
		}
		return $this->stream;
	}
}
