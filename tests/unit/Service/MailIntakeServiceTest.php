<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\MailIntake;
use OCA\Kanso\Db\MailIntakeMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\Mail\ImapClient;
use OCA\Kanso\Service\Mail\ImapClientFactory;
use OCA\Kanso\Service\Mail\ImapException;
use OCA\Kanso\Service\Mail\MimeParser;
use OCA\Kanso\Service\MailIntakeService;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MailIntakeServiceTest extends TestCase {
	private const NOW = 1_800_000_000;
	private const BOARD_ID = 7;
	private const STACK_ID = 22;
	private const OWNER = 'alice';

	private MailIntakeMapper&MockObject $mapper;
	private BoardMapper&MockObject $boardMapper;
	private StackMapper&MockObject $stackMapper;
	private CardService&MockObject $cardService;
	private PermissionService&MockObject $permissionService;
	private ICrypto&MockObject $crypto;
	private ITimeFactory&MockObject $time;
	private LoggerInterface&MockObject $logger;
	private ImapClientFactory&MockObject $clientFactory;
	private ImapClient&MockObject $client;
	private MailIntakeService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(MailIntakeMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->stackMapper = $this->createMock(StackMapper::class);
		$this->cardService = $this->createMock(CardService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->clientFactory = $this->createMock(ImapClientFactory::class);
		$this->client = $this->createMock(ImapClient::class);

		$this->time->method('getTime')->willReturn(self::NOW);
		$this->clientFactory->method('create')->willReturn($this->client);
		// The cipher is NC's business; round-trip through a marker so the tests
		// can tell encrypted from plaintext.
		$this->crypto->method('encrypt')->willReturnCallback(static fn (string $v): string => 'enc:' . $v);
		$this->crypto->method('decrypt')->willReturnCallback(static function (string $v): string {
			if (!str_starts_with($v, 'enc:')) {
				throw new \Exception('bad ciphertext');
			}
			return substr($v, 4);
		});

		$this->service = new MailIntakeService(
			$this->mapper,
			$this->boardMapper,
			$this->stackMapper,
			$this->cardService,
			$this->permissionService,
			// The parser is pure and its own tests cover it - a mock here would
			// only assert that this class calls something.
			new MimeParser(),
			$this->crypto,
			$this->time,
			$this->logger,
			$this->clientFactory,
		);
	}

	private function board(bool $deleted = false): Board {
		$board = new Board();
		$board->setId(self::BOARD_ID);
		$board->setOwner(self::OWNER);
		$board->setDeletedAt($deleted ? self::NOW : 0);
		return $board;
	}

	private function stack(int $boardId = self::BOARD_ID, bool $deleted = false): Stack {
		$stack = new Stack();
		$stack->setId(self::STACK_ID);
		$stack->setBoardId($boardId);
		$stack->setDeletedAt($deleted ? self::NOW : 0);
		return $stack;
	}

	private function config(string $allowlist = '', int $lastUid = 10, int $uidValidity = 99): MailIntake {
		$config = new MailIntake();
		$config->setId(1);
		$config->setBoardId(self::BOARD_ID);
		$config->setStackId(self::STACK_ID);
		$config->setHost('mail.example.com');
		$config->setPort(993);
		$config->setEncryption(MailIntake::ENCRYPTION_SSL);
		$config->setUsername('inbox@example.com');
		$config->setPassword('enc:secret');
		$config->setMailbox('INBOX');
		$config->setSenderAllowlist($allowlist === '' ? null : $allowlist);
		$config->setEnabled(true);
		$config->setLastUid($lastUid);
		$config->setUidValidity($uidValidity);
		$config->setLastRun(0);
		return $config;
	}

	private function rawMessage(string $from, string $subject, string $body = 'Body text'): string {
		return "From: {$from}\r\nSubject: {$subject}\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n{$body}";
	}

	private function card(int $id = 500): Card {
		$card = new Card();
		$card->setId($id);
		return $card;
	}

	private function expectBoardAndStackResolve(): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$this->stackMapper->method('find')->willReturn($this->stack());
	}

	// ---- permissions -------------------------------------------------------

	public function testGetConfigRequiresManage(): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException('nope'));

		$this->expectException(NotPermittedException::class);
		$this->service->getConfig(self::BOARD_ID, 'mallory');
	}

	public function testSaveConfigRequiresManage(): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException('nope'));
		$this->mapper->expects(self::never())->method('insert');

		$this->expectException(NotPermittedException::class);
		$this->save(password: 'pw', actorUid: 'mallory');
	}

	public function testDeleteConfigRequiresManage(): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException('nope'));
		$this->mapper->expects(self::never())->method('deleteByBoard');

		$this->expectException(NotPermittedException::class);
		$this->service->deleteConfig(self::BOARD_ID, 'mallory');
	}

	// ---- config validation -------------------------------------------------

	/** Saves with sensible defaults, overridable per test. */
	private function save(
		?string $password = 'pw',
		string $host = 'mail.example.com',
		int $port = 993,
		string $encryption = MailIntake::ENCRYPTION_SSL,
		string $username = 'inbox@example.com',
		string $mailbox = 'INBOX',
		string $allowlist = '',
		bool $enabled = true,
		string $actorUid = self::OWNER,
		int $stackId = self::STACK_ID,
	): MailIntake {
		return $this->service->saveConfig(
			self::BOARD_ID,
			$stackId,
			$host,
			$port,
			$encryption,
			$username,
			$password,
			$mailbox,
			$allowlist,
			$enabled,
			$actorUid,
		);
	}

	public function testSaveConfigStoresThePasswordEncrypted(): void {
		$this->expectBoardAndStackResolve();
		$this->mapper->method('findByBoard')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('none'));
		$this->mapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static fn (MailIntake $c): MailIntake => $c);

		$saved = $this->save(password: 'hunter2');

		// The credential must never be at rest in the clear.
		self::assertSame('enc:hunter2', $saved->getPassword());
		self::assertStringNotContainsString('hunter2', json_encode($saved->jsonSerialize()) ?: '');
	}

	public function testJsonNeverCarriesThePassword(): void {
		$json = $this->config()->jsonSerialize();

		self::assertArrayNotHasKey('password', $json);
		self::assertTrue($json['hasPassword']);
	}

	public function testSaveConfigKeepsTheStoredPasswordWhenNoneIsSupplied(): void {
		$existing = $this->config();
		$this->expectBoardAndStackResolve();
		$this->mapper->method('findByBoard')->willReturn($existing);
		$this->mapper->method('update')->willReturnCallback(static fn (MailIntake $c): MailIntake => $c);

		// The UI never receives the password, so re-saving to change the stack
		// must not blank it.
		$saved = $this->save(password: null);

		self::assertSame('enc:secret', $saved->getPassword());
	}

	public function testSaveConfigRejectsAFirstSaveWithNoPassword(): void {
		$this->expectBoardAndStackResolve();
		$this->mapper->method('findByBoard')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('none'));

		$this->expectException(InvalidInputException::class);
		$this->save(password: null);
	}

	public function testSaveConfigRejectsAnOutOfRangePort(): void {
		$this->expectBoardAndStackResolve();

		$this->expectException(InvalidInputException::class);
		$this->save(port: 99999);
	}

	public function testSaveConfigRejectsCleartextImap(): void {
		$this->expectBoardAndStackResolve();

		// A plaintext LOGIN would put the mailbox password on the wire.
		$this->expectException(InvalidInputException::class);
		$this->save(encryption: 'none');
	}

	public function testSaveConfigRejectsLineBreaksInTheHost(): void {
		$this->expectBoardAndStackResolve();

		// Rejected at the config boundary so it is a 400 now, not a mailbox that
		// silently fails every poll.
		$this->expectException(InvalidInputException::class);
		$this->save(host: "mail.example.com\r\nK999 DELETE INBOX");
	}

	public function testSaveConfigRejectsAStackFromAnotherBoard(): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$this->stackMapper->method('find')->willReturn($this->stack(boardId: 999));

		$this->expectException(InvalidInputException::class);
		$this->save();
	}

	public function testSaveConfigResetsTheWatermarkWhenTheMailboxIdentityChanges(): void {
		$existing = $this->config(lastUid: 500, uidValidity: 99);
		$this->expectBoardAndStackResolve();
		$this->mapper->method('findByBoard')->willReturn($existing);
		$this->mapper->method('update')->willReturnCallback(static fn (MailIntake $c): MailIntake => $c);

		// UIDs mean nothing across accounts - carrying 500 over would skip
		// everything below it on the new mailbox.
		$saved = $this->save(username: 'other@example.com');

		self::assertSame(0, $saved->getLastUid());
		self::assertSame(0, $saved->getUidValidity());
	}

	public function testSaveConfigKeepsTheWatermarkWhenOnlyTheStackChanges(): void {
		$existing = $this->config(lastUid: 500);
		$this->expectBoardAndStackResolve();
		$this->mapper->method('findByBoard')->willReturn($existing);
		$this->mapper->method('update')->willReturnCallback(static fn (MailIntake $c): MailIntake => $c);

		$saved = $this->save();

		// Same mailbox: re-reading it from the start would duplicate every card.
		self::assertSame(500, $saved->getLastUid());
	}

	// ---- polling -----------------------------------------------------------

	public function testPollCreatesACardPerMessageAsTheBoardOwner(): void {
		$config = $this->config();
		$this->expectBoardAndStackResolve();
		$this->client->method('selectMailbox')->willReturn(['uidValidity' => 99, 'uidNext' => 30]);
		$this->client->method('searchUidsAbove')->willReturn([11, 12]);
		$this->client->method('fetchMessage')->willReturnMap([
			[11, $this->rawMessage('jacek@example.com', 'First subject')],
			[12, $this->rawMessage('jacek@example.com', 'Second subject')],
		]);

		$titles = [];
		$this->cardService->expects(self::exactly(2))
			->method('create')
			->willReturnCallback(function (int $stackId, string $title, string $uid) use (&$titles): Card {
				self::assertSame(self::STACK_ID, $stackId);
				// Never the user whose address the forgeable From claims.
				self::assertSame(self::OWNER, $uid);
				$titles[] = $title;
				return $this->card();
			});
		$this->mapper->method('update')->willReturnArgument(0);

		self::assertSame(2, $this->service->poll($config));
		self::assertSame(['First subject', 'Second subject'], $titles);
		self::assertSame(12, $config->getLastUid());
	}

	public function testPollPutsTheSenderAndBodyInTheDescription(): void {
		$config = $this->config();
		$this->expectBoardAndStackResolve();
		$this->client->method('selectMailbox')->willReturn(['uidValidity' => 99, 'uidNext' => 30]);
		$this->client->method('searchUidsAbove')->willReturn([11]);
		$this->client->method('fetchMessage')
			->willReturn($this->rawMessage('Jacek <jacek@example.com>', 'Subj', 'The body'));
		$this->cardService->method('create')->willReturn($this->card());
		$this->mapper->method('update')->willReturnArgument(0);

		$description = null;
		$this->cardService->expects(self::once())
			->method('update')
			->willReturnCallback(function (int $id, ?string $title, ?string $desc) use (&$description): Card {
				$description = $desc;
				return $this->card();
			});

		$this->service->poll($config);

		self::assertStringContainsString('Jacek <jacek@example.com>', (string)$description);
		self::assertStringContainsString('The body', (string)$description);
	}

	public function testPollSkipsSendersOutsideTheAllowlist(): void {
		$config = $this->config(allowlist: "jacek@example.com\n@trusted.example");
		$this->expectBoardAndStackResolve();
		$this->client->method('selectMailbox')->willReturn(['uidValidity' => 99, 'uidNext' => 30]);
		$this->client->method('searchUidsAbove')->willReturn([11, 12, 13, 14]);
		$this->client->method('fetchMessage')->willReturnMap([
			[11, $this->rawMessage('jacek@example.com', 'Allowed exactly')],
			[12, $this->rawMessage('anyone@trusted.example', 'Allowed by domain')],
			[13, $this->rawMessage('spammer@evil.test', 'Blocked')],
			[14, $this->rawMessage('', 'Unparseable sender')],
		]);
		$this->mapper->method('update')->willReturnArgument(0);

		$titles = [];
		$this->cardService->method('create')
			->willReturnCallback(function (int $stackId, string $title, string $uid) use (&$titles): Card {
				$titles[] = $title;
				return $this->card();
			});

		self::assertSame(2, $this->service->poll($config));
		self::assertSame(['Allowed exactly', 'Allowed by domain'], $titles);
		// A message that fails the filter still advances the watermark, or every
		// poll would re-examine it forever.
		self::assertSame(14, $config->getLastUid());
	}

	public function testPollAcceptsAnySenderWhenTheAllowlistIsEmpty(): void {
		$config = $this->config(allowlist: '');
		$this->expectBoardAndStackResolve();
		$this->client->method('selectMailbox')->willReturn(['uidValidity' => 99, 'uidNext' => 30]);
		$this->client->method('searchUidsAbove')->willReturn([11]);
		$this->client->method('fetchMessage')->willReturn($this->rawMessage('anyone@anywhere.test', 'Open intake'));
		$this->cardService->method('create')->willReturn($this->card());
		$this->mapper->method('update')->willReturnArgument(0);

		self::assertSame(1, $this->service->poll($config));
	}

	public function testPollResetsTheWatermarkWhenUidValidityChanges(): void {
		$config = $this->config(lastUid: 500, uidValidity: 99);
		$this->expectBoardAndStackResolve();
		// The server renumbered: the old UIDs address different messages now.
		$this->client->method('selectMailbox')->willReturn(['uidValidity' => 100, 'uidNext' => 5]);
		$this->client->expects(self::once())
			->method('searchUidsAbove')
			->with(0)
			->willReturn([]);
		$this->mapper->method('update')->willReturnArgument(0);

		$this->service->poll($config);

		self::assertSame(100, $config->getUidValidity());
	}

	public function testPollPersistsTheWatermarkEvenWhenItFailsMidBatch(): void {
		$config = $this->config(lastUid: 10);
		$this->expectBoardAndStackResolve();
		$this->client->method('selectMailbox')->willReturn(['uidValidity' => 99, 'uidNext' => 30]);
		$this->client->method('searchUidsAbove')->willReturn([11, 12, 13]);
		$this->client->method('fetchMessage')->willReturnCallback(function (int $uid): string {
			if ($uid === 13) {
				throw new ImapException('connection lost');
			}
			return $this->rawMessage('a@example.com', 'Subject ' . $uid);
		});
		$this->cardService->method('create')->willReturn($this->card());

		$persisted = null;
		$this->mapper->expects(self::once())
			->method('update')
			->willReturnCallback(static function (MailIntake $c) use (&$persisted): MailIntake {
				$persisted = $c->getLastUid();
				return $c;
			});

		try {
			$this->service->poll($config);
			self::fail('Expected the ImapException to propagate');
		} catch (ImapException) {
			// Expected - pollAll() records and steps over it.
		}

		// The two messages already carded must not be carded again on the next
		// run. Without persisting on the failure path they would be.
		self::assertSame(12, $persisted);
		self::assertNotNull($config->getLastError());
	}

	public function testPollCapsHowManyMessagesOneRunCards(): void {
		$config = $this->config(lastUid: 0);
		$this->expectBoardAndStackResolve();
		$this->client->method('selectMailbox')->willReturn(['uidValidity' => 99, 'uidNext' => 500]);
		// A mailbox with years of history.
		$this->client->method('searchUidsAbove')->willReturn(range(1, 400));
		$this->client->method('fetchMessage')
			->willReturnCallback(fn (int $uid): string => $this->rawMessage('a@example.com', 'Subject ' . $uid));
		$this->cardService->method('create')->willReturn($this->card());
		$this->mapper->method('update')->willReturnArgument(0);

		// Bounded per run; the watermark makes the next run continue.
		self::assertSame(50, $this->service->poll($config));
		self::assertSame(50, $config->getLastUid());
	}

	public function testPollDoesNotConnectWhenTheTargetStackIsGone(): void {
		$config = $this->config();
		$this->boardMapper->method('find')->willReturn($this->board());
		$this->stackMapper->method('find')->willReturn($this->stack(deleted: true));
		// Handing a credential to a remote server to accomplish nothing is the
		// thing to avoid here.
		$this->client->expects(self::never())->method('connect');
		$this->mapper->method('update')->willReturnArgument(0);

		$this->expectException(ImapException::class);
		$this->service->poll($config);
	}

	public function testPollLeavesTheWatermarkAloneWhenTheStackIsGone(): void {
		$config = $this->config(lastUid: 10);
		$this->boardMapper->method('find')->willReturn($this->board());
		$this->stackMapper->method('find')->willReturn($this->stack(deleted: true));
		$this->mapper->method('update')->willReturnArgument(0);

		try {
			$this->service->poll($config);
		} catch (ImapException) {
			// Expected.
		}

		// The mail is still on the server, waiting for a live stack.
		self::assertSame(10, $config->getLastUid());
	}

	public function testPollReportsAnUndecryptableCredentialAsSomethingActionable(): void {
		$config = $this->config();
		$config->setPassword('not-encrypted-by-this-secret');
		$this->expectBoardAndStackResolve();
		$this->mapper->method('update')->willReturnArgument(0);

		$this->expectException(ImapException::class);
		$this->expectExceptionMessage('re-enter it');
		$this->service->poll($config);
	}

	public function testPollUsesTheSenderWhenTheSubjectIsEmpty(): void {
		$config = $this->config();
		$this->expectBoardAndStackResolve();
		$this->client->method('selectMailbox')->willReturn(['uidValidity' => 99, 'uidNext' => 30]);
		$this->client->method('searchUidsAbove')->willReturn([11]);
		$this->client->method('fetchMessage')->willReturn($this->rawMessage('jacek@example.com', '   '));
		$this->mapper->method('update')->willReturnArgument(0);

		$title = null;
		$this->cardService->method('create')
			->willReturnCallback(function (int $stackId, string $t) use (&$title): Card {
				$title = $t;
				return $this->card();
			});

		$this->service->poll($config);

		// create() throws on an empty title, so a blank subject must never reach it.
		self::assertNotSame('', trim((string)$title));
		self::assertStringContainsString('jacek@example.com', (string)$title);
	}

	public function testPollTruncatesAnOverlongSubject(): void {
		$config = $this->config();
		$this->expectBoardAndStackResolve();
		$this->client->method('selectMailbox')->willReturn(['uidValidity' => 99, 'uidNext' => 30]);
		$this->client->method('searchUidsAbove')->willReturn([11]);
		$this->client->method('fetchMessage')
			->willReturn($this->rawMessage('a@example.com', str_repeat('x', 300)));
		$this->mapper->method('update')->willReturnArgument(0);

		$title = null;
		$this->cardService->method('create')
			->willReturnCallback(function (int $stackId, string $t) use (&$title): Card {
				$title = $t;
				return $this->card();
			});

		$this->service->poll($config);

		self::assertLessThanOrEqual(CardService::MAX_TITLE_LENGTH, mb_strlen((string)$title));
	}

	public function testPollSkipsAMessageThatVanishedBetweenSearchAndFetch(): void {
		$config = $this->config();
		$this->expectBoardAndStackResolve();
		$this->client->method('selectMailbox')->willReturn(['uidValidity' => 99, 'uidNext' => 30]);
		$this->client->method('searchUidsAbove')->willReturn([11]);
		// '' is what fetch returns for a message deleted mid-poll.
		$this->client->method('fetchMessage')->willReturn('');
		$this->cardService->expects(self::never())->method('create');
		$this->mapper->method('update')->willReturnArgument(0);

		self::assertSame(0, $this->service->poll($config));
		// Still advanced, so it is not retried forever.
		self::assertSame(11, $config->getLastUid());
	}

	public function testPollAlwaysDisconnects(): void {
		$config = $this->config();
		$this->expectBoardAndStackResolve();
		$this->client->method('selectMailbox')->willThrowException(new ImapException('boom'));
		$this->mapper->method('update')->willReturnArgument(0);
		$this->client->expects(self::once())->method('disconnect');

		try {
			$this->service->poll($config);
		} catch (ImapException) {
			// Expected.
		}
	}

	public function testPollAllStepsOverAFailingMailbox(): void {
		$broken = $this->config();
		$broken->setId(1);
		$working = $this->config();
		$working->setId(2);

		$this->mapper->method('findEnabled')->willReturn([$broken, $working]);
		$this->expectBoardAndStackResolve();
		$this->mapper->method('update')->willReturnArgument(0);

		$call = 0;
		$this->client->method('selectMailbox')->willReturnCallback(function () use (&$call): array {
			$call++;
			if ($call === 1) {
				throw new ImapException('server unreachable');
			}
			return ['uidValidity' => 99, 'uidNext' => 30];
		});
		$this->client->method('searchUidsAbove')->willReturn([11]);
		$this->client->method('fetchMessage')->willReturn($this->rawMessage('a@example.com', 'Subject'));
		$this->cardService->method('create')->willReturn($this->card());

		// One board's expired password must not stop every other board's intake.
		self::assertSame(1, $this->service->pollAll());
	}

	public function testTestConnectionReportsAFailureInsteadOfThrowing(): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$this->mapper->method('findByBoard')->willReturn($this->config());
		$this->client->method('connect')->willThrowException(new ImapException('Cannot connect to mail.example.com:993'));

		$result = $this->service->testConnection(self::BOARD_ID, self::OWNER);

		self::assertFalse($result['ok']);
		self::assertStringContainsString('Cannot connect', (string)$result['error']);
	}

	public function testTestConnectionSucceeds(): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$this->mapper->method('findByBoard')->willReturn($this->config());
		$this->client->method('selectMailbox')->willReturn(['uidValidity' => 99, 'uidNext' => 1]);

		$result = $this->service->testConnection(self::BOARD_ID, self::OWNER);

		self::assertTrue($result['ok']);
		self::assertNull($result['error']);
	}
}
