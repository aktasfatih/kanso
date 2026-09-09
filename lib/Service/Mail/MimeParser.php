<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service\Mail;

/**
 * Turns raw RFC 822/2045 bytes into the {@see MimeMessage} intake acts on.
 *
 * Why hand-rolled: NC's `3rdparty` carries `symfony/mime`, which COMPOSES
 * messages and cannot parse one (it ships no parser), and Kanso ships no
 * `vendor/`. What it does give us is enough - the decoding primitives this
 * needs are all PHP builtins: `iconv_mime_decode` for encoded-word headers,
 * `quoted_printable_decode` and `base64_decode` for bodies, `mb_convert_encoding`
 * for charsets.
 *
 * Scope: headers, the MIME part tree, and ONE text body - `text/plain` if the
 * message has one anywhere, else `text/html` flattened to text. Attached parts
 * are named, not stored. Everything is normalised to UTF-8, because a card
 * description that renders as mojibake is worse than one that lost an accent.
 *
 * Robustness over strictness throughout: real mail is malformed constantly, and
 * the failure mode that matters is a poll that throws and stalls a mailbox. A
 * message this cannot make sense of yields empty strings, never an exception.
 */
class MimeParser {
	/**
	 * How deep the multipart tree is walked. `multipart/mixed` wrapping
	 * `multipart/alternative` wrapping `multipart/related` is ordinary mail and
	 * only three deep; beyond this is a malformed or hostile message, and
	 * unbounded recursion on attacker-shaped input is how a parser becomes a DoS.
	 */
	private const MAX_DEPTH = 10;

	/** Bounds the parts walked in one message, for the same reason. */
	private const MAX_PARTS = 200;

	/** Bounds the attachment list a single card description can carry. */
	private const MAX_ATTACHMENT_NAMES = 50;

	/**
	 * Longest header value kept, in bytes. A single header is otherwise bounded
	 * only by the 5 MiB message ceiling, and the address pattern in
	 * {@see parseFrom} backtracks superlinearly - a multi-megabyte `From:` is a
	 * cheap way to burn cron CPU. No legitimate header comes close to 2 KB.
	 */
	private const MAX_HEADER_VALUE_BYTES = 2048;

	/**
	 * Bidi controls and other invisible formatting characters. A subject can use
	 * these to render as something other than what it contains - the classic
	 * "invoice\u{202E}fdp.exe" trick - and a card title is read by people
	 * deciding whether to trust it. Stripped from every header-derived string.
	 * U+200B..U+200F, U+202A..U+202E, U+2066..U+2069, U+FEFF, plus C0/C1.
	 */
	private const INVISIBLE_CHARS = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u';

	private int $partsSeen = 0;

	public function parse(string $raw): MimeMessage {
		$this->partsSeen = 0;

		// Normalise line endings first: the boundary and header-block scans below
		// both key on "\n", and real mail arrives with CRLF, bare LF, and
		// occasionally bare CR from older agents.
		$raw = str_replace(["\r\n", "\r"], "\n", $raw);

		[$headerBlock, $body] = $this->splitHeaders($raw);
		$headers = $this->parseHeaders($headerBlock);

		$from = $this->parseFrom($this->headerValue($headers, 'from'));
		$subject = $this->decodeHeader($this->headerValue($headers, 'subject'));

		// Attachments are collected by their OWN complete walk, not as a side
		// effect of the text search: the search stops at the first part it wants,
		// so a message whose text part precedes its attachments - the ordinary
		// shape - would report no attachments at all.
		$attachmentNames = [];
		$this->collectAttachments($headers, $body, 0, $attachmentNames);

		// Two exact passes rather than one pass that guesses. Preference must be
		// by the type of the LEAF that produced the text, and a single walk only
		// ever sees the type of the subtree it descended into - so a text/plain
		// nested inside a multipart/related inside a multipart/alternative would
		// be indistinguishable from HTML that came the same way.
		$text = $this->findText($headers, $body, 0, 'text/plain');
		if ($text === '') {
			$text = $this->findText($headers, $body, 0, 'text/html');
		}

		return new MimeMessage(
			$from['address'],
			$from['name'],
			$this->normaliseWhitespace($subject),
			$this->stripInvisible(trim($text)),
			$attachmentNames,
			$this->parseMessageId($this->headerValue($headers, 'message-id')),
			$this->looksAutomated($headers),
			$this->looksLikeSpam($headers),
			// ONLY the topmost Authentication-Results is read - see the class
			// docblock on AuthenticationResults for why the rest are the sender's
			// to forge.
			AuthenticationResults::fromHeader($this->headerValue($headers, 'authentication-results')),
		);
	}

	/**
	 * Whether the message is machine-generated and must not become a card.
	 *
	 * This is the loop breaker. A card created by intake can raise a Nextcloud
	 * notification email; an out-of-office or a bounce answers it; that answer
	 * lands back in the intake mailbox as a new card, and the cycle runs until
	 * someone notices. The header set below is the standard one senders use to
	 * say "do not reply to this automatically", and honouring it is how every
	 * other ticket-by-mail system avoids the same loop.
	 *
	 * @param array<string, list<string>> $headers
	 */
	private function looksAutomated(array $headers): bool {
		// RFC 3834. Anything but 'no' means automatic; the header exists solely
		// to mark auto-replies, auto-forwards and system notifications.
		$autoSubmitted = strtolower(trim($this->headerValue($headers, 'auto-submitted')));
		if ($autoSubmitted !== '' && !str_starts_with($autoSubmitted, 'no')) {
			return true;
		}

		// Bulk/list traffic: newsletters and mailing-list posts.
		$precedence = strtolower(trim($this->headerValue($headers, 'precedence')));
		if (in_array($precedence, ['bulk', 'list', 'junk', 'auto_reply'], true)) {
			return true;
		}

		// Mailing lists identify themselves with these regardless of Precedence.
		foreach (['list-id', 'list-unsubscribe', 'list-post'] as $listHeader) {
			if ($this->headerValue($headers, $listHeader) !== '') {
				return true;
			}
		}

		// Microsoft's equivalent of Auto-Submitted, and the older X-Autoreply /
		// X-Autorespond pair.
		foreach (['x-auto-response-suppress', 'x-autoreply', 'x-autorespond', 'x-autoresponder'] as $msHeader) {
			if ($this->headerValue($headers, $msHeader) !== '') {
				return true;
			}
		}

		// A null return path is the signature of a BOUNCE (RFC 5321 requires
		// delivery-status notifications to use an empty envelope sender, exactly
		// so that replying to them cannot loop).
		$returnPath = trim($this->headerValue($headers, 'return-path'));
		if ($returnPath === '<>' || $returnPath === '') {
			// '' only counts when the header is present but empty, not when it is
			// absent - plenty of ordinary mail has no Return-Path by the time it
			// reaches IMAP.
			if (isset($headers['return-path'])) {
				return true;
			}
		}

		// The content type a bounce carries.
		$contentType = strtolower($this->headerValue($headers, 'content-type'));
		if (str_contains($contentType, 'multipart/report') || str_contains($contentType, 'delivery-status')) {
			return true;
		}

		return false;
	}

	/**
	 * Whether an upstream filter already judged this spam. Kanso does not
	 * re-implement spam detection - it honours the verdict of whatever did,
	 * which on a normal mail host is SpamAssassin or Rspamd.
	 *
	 * @param array<string, list<string>> $headers
	 */
	private function looksLikeSpam(array $headers): bool {
		if (str_starts_with(strtolower(trim($this->headerValue($headers, 'x-spam-flag'))), 'yes')) {
			return true;
		}
		// 'Yes, score=8.1 required=5.0 ...'
		if (str_starts_with(strtolower(trim($this->headerValue($headers, 'x-spam-status'))), 'yes')) {
			return true;
		}
		// Rspamd's verdict header.
		$action = strtolower(trim($this->headerValue($headers, 'x-spamd-result')));
		if (str_contains($action, 'reject') || str_contains($action, 'add header')) {
			return true;
		}
		return false;
	}

	/** The Message-ID with its angle brackets removed. */
	private function parseMessageId(string $raw): string {
		$raw = trim($this->stripInvisible($raw));
		if (preg_match('/<([^<>]{1,512})>/', $raw, $m) === 1) {
			return trim($m[1]);
		}
		// Some senders omit the brackets. Keep it only if it looks like an id
		// rather than a sentence.
		return preg_match('/^\S{1,512}$/', $raw) === 1 ? $raw : '';
	}

	/**
	 * Splits the header block from the body at the first empty line.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function splitHeaders(string $raw): array {
		$split = strpos($raw, "\n\n");
		if ($split === false) {
			// All headers and no body is legal (and common for a subject-only
			// message sent from a phone).
			return [$raw, ''];
		}
		return [substr($raw, 0, $split), substr($raw, $split + 2)];
	}

	/**
	 * Header name (lowercased) => list of raw values. A list, not a scalar,
	 * because `Received` and friends repeat - we only read single-valued headers,
	 * but collapsing duplicates silently would make the first `Content-Type` win
	 * or lose unpredictably depending on the implementation.
	 *
	 * @return array<string, list<string>>
	 */
	private function parseHeaders(string $headerBlock): array {
		$headers = [];
		$name = null;
		$value = '';

		foreach (explode("\n", $headerBlock) as $line) {
			if ($line === '') {
				continue;
			}
			// A leading space or tab continues (folds into) the previous header.
			if ($name !== null && ($line[0] === ' ' || $line[0] === "\t")) {
				$value .= ' ' . trim($line);
				continue;
			}
			if ($name !== null) {
				$headers[$name][] = $value;
			}
			$colon = strpos($line, ':');
			if ($colon === false) {
				// Garbage between headers - skip it rather than treating the rest of
				// the block as a continuation of whatever came before.
				$name = null;
				$value = '';
				continue;
			}
			$name = strtolower(trim(substr($line, 0, $colon)));
			$value = trim(substr($line, $colon + 1));
		}
		if ($name !== null) {
			$headers[$name][] = $value;
		}

		return $headers;
	}

	/**
	 * The FIRST occurrence of a header, clamped to a sane length.
	 *
	 * "First" is load-bearing for `Authentication-Results`: hops prepend, so the
	 * first is the one our own MTA wrote and every later one is the sender's to
	 * forge. {@see parseHeaders} preserves arrival order to make this true.
	 *
	 * @param array<string, list<string>> $headers
	 */
	private function headerValue(array $headers, string $name): string {
		$value = $headers[$name][0] ?? '';
		if (strlen($value) > self::MAX_HEADER_VALUE_BYTES) {
			$value = substr($value, 0, self::MAX_HEADER_VALUE_BYTES);
		}
		return $value;
	}

	/**
	 * Removes zero-width and bidi-override characters.
	 *
	 * A card title is something a person reads to decide whether to trust a
	 * card, and these characters let a subject display as something other than
	 * what it says. Applied to headers and to the body.
	 */
	private function stripInvisible(string $value): string {
		$stripped = preg_replace(self::INVISIBLE_CHARS, '', $value);
		// preg_replace returns null on a UTF-8 failure; the /u pattern above can
		// hit that on a body that survived scrubbing but is still odd.
		return $stripped ?? $value;
	}

	/**
	 * Walks the part tree depth-first and returns the first body whose leaf is
	 * exactly `$wantedType`, decoded to UTF-8 (and flattened, for HTML).
	 *
	 * Called once per candidate type, which is what makes `multipart/alternative`
	 * come out right: senders list the plain alternative first and the HTML one
	 * last, so "first text part wins" would pick plain by luck here and HTML the
	 * moment a sender reorders them. Searching the whole tree for plain before
	 * considering HTML is a preference, not an ordering accident.
	 *
	 * @param array<string, list<string>> $headers
	 * @param string $wantedType 'text/plain' or 'text/html'
	 */
	private function findText(array $headers, string $body, int $depth, string $wantedType): string {
		if ($depth === 0) {
			// Each pass gets the full part budget; sharing it would let a wide
			// message exhaust the budget on the plain pass and return nothing at
			// all rather than falling back to its HTML.
			$this->partsSeen = 0;
		}
		if ($depth > self::MAX_DEPTH || ++$this->partsSeen > self::MAX_PARTS) {
			return '';
		}

		$contentType = $this->parseContentType($this->headerValue($headers, 'content-type'));

		if (str_starts_with($contentType['type'], 'multipart/')) {
			$boundary = $contentType['params']['boundary'] ?? '';
			if ($boundary === '') {
				return '';
			}
			foreach ($this->splitParts($body, $boundary) as $partRaw) {
				[$partHeaderBlock, $partBody] = $this->splitHeaders($partRaw);
				$partHeaders = $this->parseHeaders($partHeaderBlock);
				$found = $this->findText($partHeaders, $partBody, $depth + 1, $wantedType);
				if ($found !== '') {
					return $found;
				}
			}
			return '';
		}

		// ---- a leaf part -----------------------------------------------------

		if ($this->isAttachment($headers, $contentType) !== '') {
			// An attached text/plain is a file, not the message body.
			return '';
		}

		if ($contentType['type'] !== $wantedType) {
			return '';
		}

		$decoded = $this->decodeBody(
			$body,
			strtolower(trim($this->headerValue($headers, 'content-transfer-encoding'))),
		);
		$decoded = $this->toUtf8($decoded, $contentType['params']['charset'] ?? '');

		if ($wantedType === 'text/html') {
			$decoded = $this->htmlToText($decoded);
		}

		// An empty part must not count as a match, or an empty plain alternative
		// would suppress the HTML fallback that carries the real body.
		return trim($decoded) === '' ? '' : $decoded;
	}

	/**
	 * Walks the WHOLE tree naming every attached part. Unlike {@see findText}
	 * this never short-circuits - the point is the complete list.
	 *
	 * @param array<string, list<string>> $headers
	 * @param string[] $names collected by reference
	 */
	private function collectAttachments(array $headers, string $body, int $depth, array &$names): void {
		if ($depth === 0) {
			$this->partsSeen = 0;
		}
		if ($depth > self::MAX_DEPTH || ++$this->partsSeen > self::MAX_PARTS || count($names) >= self::MAX_ATTACHMENT_NAMES) {
			return;
		}

		$contentType = $this->parseContentType($this->headerValue($headers, 'content-type'));

		if (str_starts_with($contentType['type'], 'multipart/')) {
			$boundary = $contentType['params']['boundary'] ?? '';
			if ($boundary === '') {
				return;
			}
			foreach ($this->splitParts($body, $boundary) as $partRaw) {
				[$partHeaderBlock, $partBody] = $this->splitHeaders($partRaw);
				$this->collectAttachments($this->parseHeaders($partHeaderBlock), $partBody, $depth + 1, $names);
			}
			return;
		}

		$filename = $this->isAttachment($headers, $contentType);
		// Deduped: `multipart/related` legitimately references the same inline
		// file from more than one alternative.
		if ($filename !== '' && !in_array($filename, $names, true)) {
			$names[] = $filename;
		}
	}

	/**
	 * The part's file name if it is an attachment, '' if it is body content.
	 *
	 * A NAMED part counts as an attachment even when the sender omitted
	 * Content-Disposition, which older clients do routinely - otherwise its
	 * bytes are read as the message body.
	 *
	 * @param array{type: string, params: array<string, string>} $contentType
	 * @param array<string, list<string>> $headers
	 */
	private function isAttachment(array $headers, array $contentType): string {
		$disposition = strtolower(trim(explode(';', $this->headerValue($headers, 'content-disposition'))[0]));
		$filename = $this->partFilename($headers, $contentType);

		if ($disposition === 'attachment') {
			// An attachment the sender did not name still has to suppress the body
			// read, so fall back to a placeholder rather than ''.
			return $filename !== '' ? $filename : 'unnamed attachment';
		}
		if ($filename !== '' && $disposition !== 'inline') {
			return $filename;
		}
		return '';
	}

	/**
	 * Splits a multipart body on its boundary.
	 *
	 * @return string[] the part bodies, preamble and epilogue discarded
	 */
	private function splitParts(string $body, string $boundary): array {
		$delimiter = '--' . $boundary;
		$chunks = explode($delimiter, $body);

		// The first chunk is the preamble (text before the first boundary, which
		// mail clients use for "this is a MIME message" notices) - never a part.
		array_shift($chunks);

		$parts = [];
		foreach ($chunks as $chunk) {
			// '--' immediately after the boundary is the terminator; everything
			// after it is the epilogue.
			if (str_starts_with($chunk, '--')) {
				break;
			}
			// Drop the CRLF that belongs to the boundary line itself.
			$parts[] = ltrim($chunk, "\n");
		}
		return $parts;
	}

	/**
	 * @return array{type: string, params: array<string, string>}
	 */
	private function parseContentType(string $value): array {
		if (trim($value) === '') {
			// RFC 2045: a part with no Content-Type is text/plain; us-ascii.
			return ['type' => 'text/plain', 'params' => []];
		}

		$segments = $this->splitParameters($value);
		$type = strtolower(trim(array_shift($segments) ?? ''));

		$params = [];
		foreach ($segments as $segment) {
			$eq = strpos($segment, '=');
			if ($eq === false) {
				continue;
			}
			$key = strtolower(trim(substr($segment, 0, $eq)));
			$raw = trim(substr($segment, $eq + 1));
			// Quoted parameter values are the norm for boundaries, which routinely
			// contain '=' and other characters that would otherwise re-split.
			if (strlen($raw) >= 2 && $raw[0] === '"') {
				$closing = strrpos($raw, '"');
				// An unterminated quote (the closing one IS the opening one) still
				// has to yield the rest of the value rather than an empty string.
				$raw = ($closing !== false && $closing > 0)
					? substr($raw, 1, $closing - 1)
					: substr($raw, 1);
			}
			$params[$key] = $raw;
		}

		return ['type' => $type, 'params' => $params];
	}

	/**
	 * Splits a header value on semicolons that are NOT inside a quoted string -
	 * a plain explode(';') mangles `boundary="a;b"`, which is legal and does
	 * occur.
	 *
	 * @return string[]
	 */
	private function splitParameters(string $value): array {
		$segments = [];
		$current = '';
		$inQuotes = false;
		$length = strlen($value);

		for ($i = 0; $i < $length; $i++) {
			$char = $value[$i];
			if ($char === '"') {
				$inQuotes = !$inQuotes;
				$current .= $char;
				continue;
			}
			if ($char === ';' && !$inQuotes) {
				$segments[] = $current;
				$current = '';
				continue;
			}
			$current .= $char;
		}
		$segments[] = $current;

		return $segments;
	}

	/**
	 * The part's file name, from Content-Disposition's `filename` or
	 * Content-Type's `name`.
	 *
	 * @param array{type: string, params: array<string, string>} $contentType
	 * @param array<string, list<string>> $headers
	 */
	private function partFilename(array $headers, array $contentType): string {
		$disposition = $this->parseContentType($this->headerValue($headers, 'content-disposition'));
		$name = $disposition['params']['filename'] ?? ($contentType['params']['name'] ?? '');
		$name = $this->decodeHeader($name);
		// The name is only ever displayed inside a card description, but it comes
		// from the sender - keep it to one line and a sane length.
		$name = $this->normaliseWhitespace($name);
		return mb_substr($name, 0, 120);
	}

	private function decodeBody(string $body, string $encoding): string {
		return match ($encoding) {
			'base64' => (string)base64_decode($body, false),
			'quoted-printable' => quoted_printable_decode($body),
			// 7bit / 8bit / binary / absent / anything unrecognised: the bytes are
			// already the content.
			default => $body,
		};
	}

	/**
	 * Converts a part body to UTF-8, falling back progressively rather than
	 * throwing - the caller cannot do anything useful with a charset failure,
	 * and a lossy body beats a stalled mailbox.
	 */
	private function toUtf8(string $text, string $charset): string {
		$charset = trim($charset);
		if ($charset === '' || strtolower($charset) === 'utf-8') {
			// Claimed UTF-8 is often not valid UTF-8. Scrub rather than trust:
			// invalid bytes reaching the database can fail the INSERT outright on
			// a strict connection.
			return $this->scrubUtf8($text);
		}

		$converted = @mb_convert_encoding($text, 'UTF-8', $charset);
		if (is_string($converted) && $converted !== '') {
			return $this->scrubUtf8($converted);
		}

		// mb doesn't know this charset; iconv knows several it doesn't. //IGNORE
		// keeps the rest of the body when one sequence is undecodable.
		$converted = @iconv($charset, 'UTF-8//IGNORE', $text);
		if (is_string($converted)) {
			return $this->scrubUtf8($converted);
		}

		return $this->scrubUtf8($text);
	}

	/** Drops byte sequences that are not valid UTF-8. */
	private function scrubUtf8(string $text): string {
		if (mb_check_encoding($text, 'UTF-8')) {
			return $text;
		}
		return (string)mb_convert_encoding($text, 'UTF-8', 'UTF-8');
	}

	/**
	 * Decodes RFC 2047 encoded-words (`=?utf-8?B?...?=`) in a header value.
	 */
	private function decodeHeader(string $value): string {
		if ($value === '') {
			return '';
		}
		// CONTINUE_ON_ERROR: a header mixing a broken encoded-word with good text
		// should still yield the good text.
		$decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
		return $this->scrubUtf8(is_string($decoded) ? $decoded : $value);
	}

	/**
	 * @return array{address: string, name: string}
	 */
	private function parseFrom(string $value): array {
		$value = $this->decodeHeader($value);
		if (trim($value) === '') {
			return ['address' => '', 'name' => ''];
		}

		// 'Display Name <addr@example.com>' is the common form; a bare address is
		// equally legal. Only the LAST angle-bracket pair is the address, so a
		// display name containing brackets cannot shadow it.
		if (preg_match('/^(.*)<([^<>]*)>[^<>]*$/s', $value, $m) === 1) {
			$name = trim($m[1], " \t\"'");
			$address = trim($m[2]);
		} else {
			$name = '';
			$address = trim($value);
		}

		return [
			'address' => mb_strtolower($this->normaliseWhitespace($address)),
			'name' => $this->normaliseWhitespace($name),
		];
	}

	/**
	 * Flattens HTML to readable text. Not a renderer - just enough that an
	 * HTML-only message produces a description a person can read instead of a
	 * wall of markup.
	 */
	private function htmlToText(string $html): string {
		// Script and style CONTENT would otherwise survive strip_tags as visible
		// text - their bodies are not markup.
		$text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
		// Keep the block structure that carries meaning in a mail body.
		$text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
		$text = preg_replace('#</(p|div|tr|li|h[1-6])\s*>#i', "\n", $text) ?? $text;
		$text = strip_tags($text);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		// HTML mail is full of indentation that means nothing once tags are gone.
		$text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
		$text = preg_replace('/\n[ \t]+/', "\n", $text) ?? $text;
		$text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
		return trim($text);
	}

	/**
	 * Collapses all whitespace to single spaces and drops invisible characters -
	 * for one-line values such as the subject and attachment names.
	 */
	private function normaliseWhitespace(string $value): string {
		$value = $this->stripInvisible($value);
		return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
	}
}
