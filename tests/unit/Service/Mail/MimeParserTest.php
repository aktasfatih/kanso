<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service\Mail;

use OCA\Kanso\Service\Mail\MimeParser;
use PHPUnit\Framework\TestCase;

class MimeParserTest extends TestCase {
	private MimeParser $parser;

	protected function setUp(): void {
		parent::setUp();
		$this->parser = new MimeParser();
	}

	/** Builds a message with CRLF endings, the way one actually arrives. */
	private function message(string $body): string {
		return str_replace("\n", "\r\n", $body);
	}

	public function testParsesASimplePlainTextMessage(): void {
		$raw = $this->message(<<<EOM
			From: Jacek <jacek@example.com>
			To: inbox@example.com
			Subject: Buy more milk
			Content-Type: text/plain; charset=utf-8

			Two litres, semi-skimmed.
			EOM);

		$message = $this->parser->parse($raw);

		self::assertSame('jacek@example.com', $message->fromAddress);
		self::assertSame('Jacek', $message->fromName);
		self::assertSame('Buy more milk', $message->subject);
		self::assertSame('Two litres, semi-skimmed.', $message->body);
		self::assertSame('Jacek <jacek@example.com>', $message->fromLabel());
	}

	public function testMessageWithNoBodyIsNotAnError(): void {
		// Subject-only mail is how a phone sends a one-line note.
		$raw = $this->message("From: a@example.com\nSubject: Just the subject");

		$message = $this->parser->parse($raw);

		self::assertSame('Just the subject', $message->subject);
		self::assertSame('', $message->body);
	}

	public function testDecodesEncodedWordSubject(): void {
		// =?UTF-8?B?...?= is RFC 2047; the payload is 'Überraschung ✓'.
		$encoded = '=?UTF-8?B?' . base64_encode('Überraschung ✓') . '?=';
		$raw = $this->message("From: a@example.com\nSubject: {$encoded}\n\nbody");

		self::assertSame('Überraschung ✓', $this->parser->parse($raw)->subject);
	}

	public function testFoldedHeaderIsUnfolded(): void {
		$raw = $this->message(<<<EOM
			From: a@example.com
			Subject: A subject that was
			 folded across two lines

			body
			EOM);

		self::assertSame('A subject that was folded across two lines', $this->parser->parse($raw)->subject);
	}

	public function testDecodesBase64Body(): void {
		$raw = $this->message(<<<EOM
			From: a@example.com
			Subject: b64
			Content-Type: text/plain; charset=utf-8
			Content-Transfer-Encoding: base64

			EOM) . "\r\n" . base64_encode('Decoded body text');

		self::assertSame('Decoded body text', $this->parser->parse($raw)->body);
	}

	public function testDecodesQuotedPrintableBody(): void {
		$raw = $this->message(<<<EOM
			From: a@example.com
			Subject: qp
			Content-Type: text/plain; charset=utf-8
			Content-Transfer-Encoding: quoted-printable

			Caf=C3=A9 =E2=80=94 open till 6
			EOM);

		self::assertSame('Café — open till 6', $this->parser->parse($raw)->body);
	}

	public function testConvertsNonUtf8CharsetToUtf8(): void {
		$body = mb_convert_encoding('Grüße aus Köln', 'ISO-8859-1', 'UTF-8');
		$raw = $this->message(<<<EOM
			From: a@example.com
			Subject: latin
			Content-Type: text/plain; charset=iso-8859-1

			EOM) . "\r\n" . $body;

		self::assertSame('Grüße aus Köln', $this->parser->parse($raw)->body);
	}

	public function testPrefersPlainTextOverHtmlRegardlessOfPartOrder(): void {
		// The HTML alternative is listed FIRST here. A parser that takes the first
		// text part it meets would return the HTML.
		$raw = $this->message(<<<EOM
			From: a@example.com
			Subject: alternative
			Content-Type: multipart/alternative; boundary="bnd-alt"

			--bnd-alt
			Content-Type: text/html; charset=utf-8

			<p>The HTML one</p>
			--bnd-alt
			Content-Type: text/plain; charset=utf-8

			The plain one
			--bnd-alt--
			EOM);

		self::assertSame('The plain one', $this->parser->parse($raw)->body);
	}

	public function testPrefersPlainTextEvenWhenItIsNestedDeeperThanTheHtml(): void {
		// The regression this guards: preference must be decided by the type of
		// the LEAF that produced the text, not by the type of the subtree that was
		// descended into. The plain part is two levels down, the HTML one level.
		$raw = $this->message(<<<EOM
			From: a@example.com
			Subject: nested
			Content-Type: multipart/mixed; boundary="bnd-outer"

			--bnd-outer
			Content-Type: text/html; charset=utf-8

			<p>Shallow HTML</p>
			--bnd-outer
			Content-Type: multipart/related; boundary="bnd-inner"

			--bnd-inner
			Content-Type: text/plain; charset=utf-8

			Deep plain text
			--bnd-inner--
			--bnd-outer--
			EOM);

		self::assertSame('Deep plain text', $this->parser->parse($raw)->body);
	}

	public function testFallsBackToHtmlWhenThePlainAlternativeIsEmpty(): void {
		// Senders do emit an empty text/plain alongside real HTML. Treating the
		// empty part as a match would produce a blank card.
		$raw = $this->message(<<<EOM
			From: a@example.com
			Subject: empty plain
			Content-Type: multipart/alternative; boundary="bnd-e"

			--bnd-e
			Content-Type: text/plain; charset=utf-8


			--bnd-e
			Content-Type: text/html; charset=utf-8

			<p>The real body</p>
			--bnd-e--
			EOM);

		self::assertSame('The real body', $this->parser->parse($raw)->body);
	}

	public function testFlattensHtmlAndDropsScriptAndStyleContent(): void {
		$raw = $this->message(<<<EOM
			From: a@example.com
			Subject: html only
			Content-Type: text/html; charset=utf-8

			<html><head><style>p { color: red }</style></head>
			<body><p>First line</p><p>Second<br>third</p>
			<script>alert('nope')</script></body></html>
			EOM);

		$body = $this->parser->parse($raw)->body;

		self::assertStringContainsString('First line', $body);
		self::assertStringContainsString('Second', $body);
		self::assertStringContainsString('third', $body);
		// Stylesheet and script BODIES are not markup, so strip_tags alone leaves
		// them behind as visible text.
		self::assertStringNotContainsString('color: red', $body);
		self::assertStringNotContainsString('alert', $body);
	}

	public function testDecodesHtmlEntities(): void {
		$raw = $this->message(<<<EOM
			From: a@example.com
			Subject: entities
			Content-Type: text/html; charset=utf-8

			<p>Tom &amp; Jerry &lt;3</p>
			EOM);

		self::assertSame('Tom & Jerry <3', $this->parser->parse($raw)->body);
	}

	public function testNamesAttachmentsWithoutPuttingThemInTheBody(): void {
		$raw = $this->message(<<<EOM
			From: a@example.com
			Subject: with attachment
			Content-Type: multipart/mixed; boundary="bnd-att"

			--bnd-att
			Content-Type: text/plain; charset=utf-8

			See attached.
			--bnd-att
			Content-Type: application/pdf; name="invoice.pdf"
			Content-Disposition: attachment; filename="invoice.pdf"
			Content-Transfer-Encoding: base64

			JVBERi0xLjQKJSVFT0Y=
			--bnd-att--
			EOM);

		$message = $this->parser->parse($raw);

		self::assertSame('See attached.', $message->body);
		self::assertSame(['invoice.pdf'], $message->attachmentNames);
		// The encoded bytes must not leak into the description.
		self::assertStringNotContainsString('JVBERi', $message->body);
	}

	public function testTreatsANamedPartWithoutDispositionAsAnAttachment(): void {
		$raw = $this->message(<<<EOM
			From: a@example.com
			Subject: legacy client
			Content-Type: multipart/mixed; boundary="bnd-legacy"

			--bnd-legacy
			Content-Type: text/plain; charset=utf-8

			Body here.
			--bnd-legacy
			Content-Type: image/png; name="screenshot.png"

			binary-ish
			--bnd-legacy--
			EOM);

		self::assertSame(['screenshot.png'], $this->parser->parse($raw)->attachmentNames);
	}

	public function testHandlesBoundaryParameterContainingASemicolon(): void {
		// Legal, and a plain explode(';') on the header mangles it.
		$raw = $this->message(<<<EOM
			From: a@example.com
			Subject: tricky boundary
			Content-Type: multipart/mixed; boundary="ab;cd"

			--ab;cd
			Content-Type: text/plain; charset=utf-8

			Survived the split.
			--ab;cd--
			EOM);

		self::assertSame('Survived the split.', $this->parser->parse($raw)->body);
	}

	public function testParsesBareAddressWithoutDisplayName(): void {
		$raw = $this->message("From: plain@example.com\nSubject: s\n\nb");

		$message = $this->parser->parse($raw);

		self::assertSame('plain@example.com', $message->fromAddress);
		self::assertSame('', $message->fromName);
		self::assertSame('plain@example.com', $message->fromLabel());
	}

	public function testLowercasesTheSenderAddress(): void {
		// The allowlist comparison is case-sensitive by the time it runs, so
		// normalisation has to happen here.
		$raw = $this->message("From: Loud <SHOUTING@Example.COM>\nSubject: s\n\nb");

		self::assertSame('shouting@example.com', $this->parser->parse($raw)->fromAddress);
	}

	public function testDisplayNameContainingAngleBracketsDoesNotShadowTheAddress(): void {
		$raw = $this->message("From: \"<evil@attacker.test>\" <real@example.com>\nSubject: s\n\nb");

		self::assertSame('real@example.com', $this->parser->parse($raw)->fromAddress);
	}

	public function testMalformedMessageYieldsEmptyFieldsRatherThanThrowing(): void {
		// A poll must not die on junk - a stalled mailbox is the failure that
		// matters.
		$message = $this->parser->parse("not a message at all\x00\xff\xfe");

		self::assertSame('', $message->fromAddress);
		self::assertSame('', $message->subject);
	}

	public function testMultipartWithoutABoundaryYieldsNoBody(): void {
		$raw = $this->message(<<<EOM
			From: a@example.com
			Subject: broken multipart
			Content-Type: multipart/mixed

			--something
			Content-Type: text/plain

			unreachable
			--something--
			EOM);

		self::assertSame('', $this->parser->parse($raw)->body);
	}

	public function testInvalidUtf8InABodyClaimingUtf8IsScrubbed(): void {
		// Invalid bytes reaching the database can fail the INSERT outright.
		$raw = $this->message("From: a@example.com\nSubject: s\nContent-Type: text/plain; charset=utf-8\n\n")
			. "valid \xC3\x28 tail";

		$body = $this->parser->parse($raw)->body;

		self::assertTrue(mb_check_encoding($body, 'UTF-8'));
		self::assertStringContainsString('valid', $body);
	}

	public function testHandlesBareLineFeedMessages(): void {
		// Some agents (and some IMAP servers' literals) use bare LF.
		$raw = "From: a@example.com\nSubject: LF only\n\nBody with LF";

		$message = $this->parser->parse($raw);

		self::assertSame('LF only', $message->subject);
		self::assertSame('Body with LF', $message->body);
	}

	// ---- classification ----------------------------------------------------

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function automatedHeaders(): array {
		return [
			'auto-replied' => ['Auto-Submitted: auto-replied'],
			'auto-generated' => ['Auto-Submitted: auto-generated'],
			'precedence bulk' => ['Precedence: bulk'],
			'precedence list' => ['Precedence: list'],
			'precedence junk' => ['Precedence: junk'],
			'list-id' => ['List-Id: <announce.example.com>'],
			'list-unsubscribe' => ['List-Unsubscribe: <mailto:x@example.com>'],
			'ms suppress' => ['X-Auto-Response-Suppress: OOF'],
			'x-autoreply' => ['X-Autoreply: yes'],
			'null return path' => ['Return-Path: <>'],
		];
	}

	/**
	 * @dataProvider automatedHeaders
	 */
	public function testDetectsAutomatedMail(string $header): void {
		$raw = $this->message("From: a@example.com\nSubject: s\n{$header}\n\nbody");

		self::assertTrue($this->parser->parse($raw)->isAutomated);
	}

	public function testOrdinaryMailIsNotFlaggedAsAutomated(): void {
		// The loop breaker must not swallow real mail.
		$raw = $this->message("From: a@example.com\nSubject: s\nReturn-Path: <a@example.com>\n\nbody");

		self::assertFalse($this->parser->parse($raw)->isAutomated);
	}

	public function testAutoSubmittedNoIsNotAutomated(): void {
		// RFC 3834: 'no' is the explicit "this is a human" value.
		$raw = $this->message("From: a@example.com\nSubject: s\nAuto-Submitted: no\n\nbody");

		self::assertFalse($this->parser->parse($raw)->isAutomated);
	}

	public function testAbsentReturnPathIsNotABounce(): void {
		// Plenty of ordinary mail has no Return-Path by the time it reaches IMAP;
		// only a PRESENT-but-empty one is the bounce signature.
		$raw = $this->message("From: a@example.com\nSubject: s\n\nbody");

		self::assertFalse($this->parser->parse($raw)->isAutomated);
	}

	public function testDetectsADeliveryReport(): void {
		$raw = $this->message("From: mailer@example.com\nSubject: Undelivered\nContent-Type: multipart/report; boundary=\"b\"\n\n--b--");

		self::assertTrue($this->parser->parse($raw)->isAutomated);
	}

	public function testDetectsSpamFlags(): void {
		$flag = $this->message("From: a@example.com\nSubject: s\nX-Spam-Flag: YES\n\nbody");
		$status = $this->message("From: a@example.com\nSubject: s\nX-Spam-Status: Yes, score=9.4 required=5.0\n\nbody");
		$clean = $this->message("From: a@example.com\nSubject: s\nX-Spam-Status: No, score=-1.2\n\nbody");

		self::assertTrue($this->parser->parse($flag)->isSpam);
		self::assertTrue($this->parser->parse($status)->isSpam);
		self::assertFalse($this->parser->parse($clean)->isSpam);
	}

	public function testReadsOnlyTheTopmostAuthenticationResults(): void {
		// Hops PREPEND, so the first is our own MTA's and everything below it is
		// the sender's to forge.
		$raw = $this->message(<<<EOM
			From: liar@example.com
			Subject: s
			Authentication-Results: mx.ours.test; dmarc=fail header.from=example.com
			Authentication-Results: attacker-supplied; dmarc=pass header.from=example.com

			body
			EOM);

		self::assertFalse($this->parser->parse($raw)->isAuthenticated());
	}

	public function testHonoursAGenuineTopmostPass(): void {
		$raw = $this->message(<<<EOM
			From: real@example.com
			Subject: s
			Authentication-Results: mx.ours.test; dmarc=pass header.from=example.com

			body
			EOM);

		self::assertTrue($this->parser->parse($raw)->isAuthenticated());
	}

	// ---- dedupe key --------------------------------------------------------

	public function testExtractsTheMessageId(): void {
		$raw = $this->message("From: a@example.com\nSubject: s\nMessage-ID: <abc123@example.com>\n\nbody");

		self::assertSame('abc123@example.com', $this->parser->parse($raw)->messageId);
	}

	public function testTheSameMessageIdYieldsTheSameDedupeKey(): void {
		$one = $this->message("From: a@example.com\nSubject: First\nMessage-ID: <same@example.com>\n\nbody");
		// Same id, different everything else - a re-delivery of one message.
		$two = $this->message("From: b@example.com\nSubject: Second\nMessage-ID: <same@example.com>\n\nother");

		self::assertSame(
			$this->parser->parse($one)->dedupeKey(),
			$this->parser->parse($two)->dedupeKey(),
		);
	}

	public function testMessagesWithoutAnIdStillDedupeOnTheirContent(): void {
		// Message-ID is optional in practice, and a mailbox re-delivering such a
		// message would otherwise card it twice.
		$raw = "From: a@example.com\r\nSubject: s\r\n\r\nidentical body";

		self::assertSame(
			$this->parser->parse($raw)->dedupeKey(),
			$this->parser->parse($raw)->dedupeKey(),
		);
	}

	public function testDifferentMessagesGetDifferentDedupeKeys(): void {
		$one = $this->message("From: a@example.com\nSubject: One\n\nbody one");
		$two = $this->message("From: a@example.com\nSubject: Two\n\nbody two");

		self::assertNotSame(
			$this->parser->parse($one)->dedupeKey(),
			$this->parser->parse($two)->dedupeKey(),
		);
	}

	// ---- hardening ---------------------------------------------------------

	public function testStripsBidiOverridesFromTheSubject(): void {
		// The "invoice<RLO>fdp.exe" trick: the title renders as something other
		// than what it contains, and a card title is what a person judges.
		$raw = $this->message("From: a@example.com\nSubject: invoice\u{202E}gnp.exe\n\nbody");

		$subject = $this->parser->parse($raw)->subject;

		self::assertStringNotContainsString("\u{202E}", $subject);
		self::assertStringContainsString('invoice', $subject);
	}

	public function testStripsZeroWidthCharacters(): void {
		// Zero-width joiners let two visually identical subjects differ, which
		// defeats a reader comparing a card against what they expected.
		$raw = $this->message("From: a@example.com\nSubject: pay\u{200B}ment\n\nbody");

		self::assertSame('payment', $this->parser->parse($raw)->subject);
	}

	public function testAnAbsurdlyLongHeaderIsClampedRatherThanParsedWhole(): void {
		// A single header is otherwise bounded only by the 5 MiB message ceiling,
		// and the address pattern backtracks superlinearly - a cheap way to burn
		// cron CPU. Should be fast and must not hang.
		$long = str_repeat('<', 200000);
		$raw = "From: {$long}\r\nSubject: s\r\n\r\nbody";

		$start = microtime(true);
		$message = $this->parser->parse($raw);
		$elapsed = microtime(true) - $start;

		self::assertLessThan(2.0, $elapsed, 'header parsing should not blow up on a pathological value');
		self::assertSame('s', $message->subject);
	}

	public function testPartWithNoContentTypeIsTreatedAsPlainText(): void {
		// RFC 2045: an absent Content-Type means text/plain.
		$raw = $this->message("From: a@example.com\nSubject: s\n\nImplicitly plain");

		self::assertSame('Implicitly plain', $this->parser->parse($raw)->body);
	}
}
