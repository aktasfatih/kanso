<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service\Mail;

use OCA\Kanso\Db\MailIntake;
use OCA\Kanso\Service\Mail\ImapClient;
use OCA\Kanso\Service\Mail\ImapException;
use OCA\Kanso\Service\Mail\ImapTransport;
use PHPUnit\Framework\TestCase;

class ImapClientTest extends TestCase {
	private FakeImapTransport $transport;
	private ImapClient $client;

	protected function setUp(): void {
		parent::setUp();
		$this->transport = new FakeImapTransport();
		$this->client = new ImapClient($this->transport);
	}

	/** Queues the greeting plus whatever the scripted server answers next. */
	private function script(string ...$lines): void {
		$this->transport->queue(implode("\r\n", $lines) . "\r\n");
	}

	private function connect(): void {
		$this->script('* OK [CAPABILITY IMAP4rev1] Dovecot ready');
		$this->client->connect('mail.example.com', 993, MailIntake::ENCRYPTION_SSL);
	}

	public function testConnectReadsTheGreeting(): void {
		$this->connect();

		self::assertSame('mail.example.com', $this->transport->host);
		self::assertSame(993, $this->transport->port);
		self::assertTrue($this->transport->implicitTls);
		// Implicit TLS needs no in-band upgrade.
		self::assertFalse($this->transport->cryptoEnabled);
	}

	public function testStartTlsUpgradesTheConnectionBeforeAnyCredentialIsSent(): void {
		$this->script('* OK ready', 'K001 OK STARTTLS completed');

		$this->client->connect('mail.example.com', 143, MailIntake::ENCRYPTION_TLS);

		self::assertFalse($this->transport->implicitTls);
		self::assertSame(['K001 STARTTLS'], $this->transport->written);
		self::assertTrue($this->transport->cryptoEnabled);
		// Nothing but STARTTLS may precede the upgrade - a LOGIN sent first would
		// have gone out in the clear.
		self::assertSame(1, $this->transport->writesBeforeCrypto);
	}

	public function testConnectRejectsAByeGreeting(): void {
		$this->script('* BYE Too many connections');

		$this->expectException(ImapException::class);
		$this->client->connect('mail.example.com', 993, MailIntake::ENCRYPTION_SSL);
	}

	public function testLoginQuotesAndEscapesTheCredentials(): void {
		$this->connect();
		$this->script('K001 OK Logged in');

		$this->client->login('bo"b', 'pa\\ss');

		// Backslash and double quote are the only characters an IMAP quoted
		// string escapes; getting this wrong ends the string early.
		self::assertSame(['K001 LOGIN "bo\\"b" "pa\\\\ss"'], $this->transport->written);
	}

	public function testLoginRejectsALineBreakInTheCredentials(): void {
		$this->connect();

		// A CR here would terminate the LOGIN line and let the rest be read as a
		// further command - injection straight through a config field.
		$this->expectException(ImapException::class);
		$this->client->login("user\r\nK999 DELETE INBOX", 'pw');
	}

	public function testLoginFailureNeverEchoesThePassword(): void {
		$this->connect();
		// Some servers quote the offending command back in their NO text.
		$this->script('K001 NO [AUTHENTICATIONFAILED] LOGIN "bob" "hunter2" failed');

		try {
			$this->client->login('bob', 'hunter2');
			self::fail('Expected an ImapException');
		} catch (ImapException $e) {
			self::assertStringNotContainsString('hunter2', $e->getMessage());
			self::assertStringContainsString('login failed', $e->getMessage());
		}
	}

	public function testSelectMailboxParsesUidValidityAndUidNext(): void {
		$this->connect();
		$this->script(
			'* 42 EXISTS',
			'* OK [UIDVALIDITY 1234567890] UIDs valid',
			'* OK [UIDNEXT 987] Predicted next UID',
			'K001 OK [READ-ONLY] EXAMINE completed',
		);

		$status = $this->client->selectMailbox('INBOX');

		self::assertSame(1234567890, $status['uidValidity']);
		self::assertSame(987, $status['uidNext']);
		// EXAMINE, not SELECT: intake never needs write access to a person's mail.
		self::assertSame(['K001 EXAMINE "INBOX"'], $this->transport->written);
	}

	public function testSelectMailboxFailsWhenTheServerOmitsUidValidity(): void {
		$this->connect();
		$this->script('* 42 EXISTS', 'K001 OK EXAMINE completed');

		// Without it the watermark is meaningless, and guessing risks replaying
		// the whole mailbox as cards.
		$this->expectException(ImapException::class);
		$this->client->selectMailbox('INBOX');
	}

	public function testSelectMailboxFailsOnAMissingMailbox(): void {
		$this->connect();
		$this->script('K001 NO Mailbox does not exist');

		$this->expectException(ImapException::class);
		$this->client->selectMailbox('Archive/Nope');
	}

	public function testSearchReturnsUidsAboveTheWatermark(): void {
		$this->connect();
		$this->script('* SEARCH 12 15 18', 'K001 OK SEARCH completed');

		self::assertSame([12, 15, 18], $this->client->searchUidsAbove(11));
		self::assertSame(['K001 UID SEARCH UID 12:*'], $this->transport->written);
	}

	public function testSearchDiscardsTheTrailingMessageAServerReturnsForAnOutOfRangeQuery(): void {
		// The IMAP trap this exists for: ranges are inclusive and normalised, and
		// '*' is the highest UID present. So 'UID 9:*' against a mailbox whose
		// highest UID is 4 is read as '4:9' and returns message 4 - already
		// carded. Every real server does this; without the client-side filter,
		// every idle poll re-cards the newest message.
		$this->connect();
		$this->script('* SEARCH 4', 'K001 OK SEARCH completed');

		self::assertSame([], $this->client->searchUidsAbove(8));
	}

	public function testSearchHandlesAnEmptyResult(): void {
		$this->connect();
		$this->script('* SEARCH', 'K001 OK SEARCH completed');

		self::assertSame([], $this->client->searchUidsAbove(5));
	}

	public function testSearchSortsAndDedupesUids(): void {
		$this->connect();
		$this->script('* SEARCH 18 12 12 15', 'K001 OK SEARCH completed');

		self::assertSame([12, 15, 18], $this->client->searchUidsAbove(1));
	}

	public function testFetchReadsALiteralExactly(): void {
		$this->connect();
		// The literal contains CRLF and a ')' - a line-based reader would stop
		// early and desynchronise every later command.
		$body = "Subject: hi\r\n\r\nLine one\r\nLine two (with a paren)\r\n";
		$this->transport->queue(
			'* 7 FETCH (UID 42 BODY[] {' . strlen($body) . "}\r\n"
			. $body
			. ")\r\n"
			. "K001 OK FETCH completed\r\n"
		);

		self::assertSame($body, $this->client->fetchMessage(42));
		self::assertSame(['K001 UID FETCH 42 (BODY.PEEK[])'], $this->transport->written);
	}

	public function testFetchUsesPeekSoPollingNeverMarksMailAsRead(): void {
		$this->connect();
		$this->script('K001 OK FETCH completed');

		$this->client->fetchMessage(42);

		// BODY[] (without PEEK) sets \Seen as a side effect of reading.
		self::assertStringContainsString('BODY.PEEK[]', $this->transport->written[0]);
		self::assertStringNotContainsString('(BODY[])', $this->transport->written[0]);
	}

	public function testFetchReturnsEmptyWhenTheMessageVanished(): void {
		// Deleted between SEARCH and FETCH: a tagged OK with no FETCH data.
		$this->connect();
		$this->script('K001 OK FETCH completed');

		self::assertSame('', $this->client->fetchMessage(42));
	}

	public function testOversizedMessageIsDrainedAndSkippedRatherThanRead(): void {
		$this->connect();
		// One byte over the 5 MiB ceiling.
		$huge = str_repeat('x', 5 * 1024 * 1024 + 1);
		$this->transport->queue(
			'* 7 FETCH (UID 42 BODY[] {' . strlen($huge) . "}\r\n"
			. $huge
			. ")\r\n"
			. "K001 OK FETCH completed\r\n"
			. "K002 OK LOGOUT\r\n"
		);

		self::assertSame('', $this->client->fetchMessage(42));
		// Drained, not skipped: the bytes are already on the wire, so leaving them
		// queued would corrupt every later response. Proof is that the next
		// command still parses.
		$this->client->disconnect();
		self::assertSame('K002 LOGOUT', $this->transport->written[1]);
	}

	public function testNoResponseBecomesAnException(): void {
		$this->connect();
		$this->script('K001 NO Server said no');

		$this->expectException(ImapException::class);
		$this->expectExceptionMessage('NO Server said no');
		$this->client->searchUidsAbove(0);
	}

	public function testUnexpectedContinuationRequestIsAnException(): void {
		$this->connect();
		// Nothing this client sends can prompt one, so it means the connection is
		// out of sync and every later response would be misread.
		$this->script('+ go ahead');

		$this->expectException(ImapException::class);
		$this->client->searchUidsAbove(0);
	}

	public function testDisconnectSurvivesAServerThatHangsUpOnLogout(): void {
		$this->connect();
		// Nothing queued: LOGOUT's read hits EOF.
		$this->client->disconnect();

		self::assertTrue($this->transport->closed);
	}
}

/**
 * A scripted IMAP server: byte-for-byte what the peer would send, consumed the
 * way a socket consumes it. Lets the protocol logic - literals, tags, ranges -
 * be tested without a network.
 */
class FakeImapTransport implements ImapTransport {
	public string $host = '';
	public int $port = 0;
	public bool $implicitTls = false;
	public bool $cryptoEnabled = false;
	public bool $closed = false;
	public int $writesBeforeCrypto = 0;

	/** @var string[] every command line the client sent, in order */
	public array $written = [];

	private string $buffer = '';

	public function queue(string $bytes): void {
		$this->buffer .= $bytes;
	}

	#[\Override]
	public function open(string $host, int $port, bool $implicitTls, int $timeoutSeconds): void {
		$this->host = $host;
		$this->port = $port;
		$this->implicitTls = $implicitTls;
	}

	#[\Override]
	public function enableCrypto(): void {
		$this->cryptoEnabled = true;
		$this->writesBeforeCrypto = count($this->written);
	}

	#[\Override]
	public function writeLine(string $line): void {
		$this->written[] = $line;
	}

	#[\Override]
	public function readLine(): string {
		$end = strpos($this->buffer, "\r\n");
		if ($end === false) {
			throw new ImapException('IMAP server closed the connection');
		}
		$line = substr($this->buffer, 0, $end);
		$this->buffer = substr($this->buffer, $end + 2);
		return $line;
	}

	#[\Override]
	public function readBytes(int $length): string {
		if (strlen($this->buffer) < $length) {
			throw new ImapException('IMAP server closed mid-message');
		}
		$bytes = substr($this->buffer, 0, $length);
		$this->buffer = substr($this->buffer, $length);
		return $bytes;
	}

	#[\Override]
	public function close(): void {
		$this->closed = true;
	}
}
