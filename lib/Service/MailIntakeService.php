<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\MailIntake;
use OCA\Kanso\Db\MailIntakeMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\Mail\ImapClient;
use OCA\Kanso\Service\Mail\ImapClientFactory;
use OCA\Kanso\Service\Mail\ImapException;
use OCA\Kanso\Service\Mail\MimeMessage;
use OCA\Kanso\Service\Mail\MimeParser;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Email intake (#117): a board can point at an IMAP mailbox, and every message
 * that arrives there becomes a card.
 *
 * Shape follows the forge-webhook config it sits beside - MANAGE-gated config,
 * an intake stack, cards created as the board OWNER through {@see CardService}
 * so sort keys, change rows and realtime all fire. What differs is the
 * direction: a webhook is pushed to us and authenticated by HMAC, whereas a
 * mailbox is PULLED by cron and authenticated by a stored credential, which
 * drags in three problems a webhook does not have.
 *
 * 1. The credential is at rest. It is stored {@see ICrypto}-encrypted (the same
 *    server-secret-derived cipher NC uses for external storage passwords) and
 *    never leaves this class in the clear - not in the config payload, not in
 *    `lastError`, not in the log.
 *
 * 2. `From:` is not authentication. Anyone can send mail claiming to be anyone,
 *    so the sender allowlist is a FILTER, not an identity, and the card is
 *    never attributed to the NC user whose address matches. Attributing it
 *    would turn a forged header into a way to create cards as someone else -
 *    the card records the claimed sender as TEXT in its description instead,
 *    where it reads as what it is: something the message said about itself.
 *    A mailbox with an empty allowlist accepts anyone who knows the address,
 *    which is the feature working as asked (a public intake address) and is
 *    called out in the config UI.
 *
 * 3. A poll can fail forever. Every failure is caught per mailbox, recorded in
 *    `lastError` for the config UI, and never allowed to escape into the cron
 *    worker - one board's expired password must not stop every other board's
 *    intake.
 *
 * Not in scope here: attachments are NAMED in the card body, not stored as card
 * attachments, and a reply to an intake card is a new card rather than a
 * comment. Both are follow-on work, and both are stated in the UI rather than
 * left to be discovered.
 */
class MailIntakeService {
	/** The encryption modes offered. Cleartext IMAP is deliberately absent. */
	public const ENCRYPTIONS = [MailIntake::ENCRYPTION_SSL, MailIntake::ENCRYPTION_TLS];

	/**
	 * Messages carded per mailbox per run. A first poll of a mailbox with years
	 * of history would otherwise create thousands of cards in one cron tick;
	 * the watermark makes the next run continue where this one stopped.
	 */
	private const MAX_MESSAGES_PER_RUN = 50;

	/** Keeps a runaway server's error text out of the database and the UI. */
	private const MAX_ERROR_LENGTH = 500;

	public function __construct(
		private MailIntakeMapper $mapper,
		private BoardMapper $boardMapper,
		private StackMapper $stackMapper,
		private CardService $cardService,
		private PermissionService $permissionService,
		private MimeParser $mimeParser,
		private ICrypto $crypto,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private ImapClientFactory $clientFactory,
	) {
	}

	// ---- config (MANAGE) ---------------------------------------------------

	/**
	 * The board's intake config, or null when none is set up. The password is
	 * never included - {@see MailIntake::jsonSerialize} reports `hasPassword`.
	 *
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function getConfig(int $boardId, string $actorUid): ?MailIntake {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);

		try {
			return $this->mapper->findByBoard($boardId);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Creates or replaces the board's mailbox config. Requires MANAGE.
	 *
	 * `$password` null means "keep the stored one" - the config UI never receives
	 * the password back, so it cannot send it back either, and re-saving the form
	 * to change the stack must not wipe the credential.
	 *
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 * @throws InvalidInputException if any field is unusable
	 */
	public function saveConfig(
		int $boardId,
		int $stackId,
		string $host,
		int $port,
		string $encryption,
		string $username,
		?string $password,
		string $mailbox,
		string $senderAllowlist,
		bool $enabled,
		string $actorUid,
	): MailIntake {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);

		$host = trim($host);
		$username = trim($username);
		$mailbox = trim($mailbox);
		$mailbox = $mailbox === '' ? 'INBOX' : $mailbox;

		if ($host === '') {
			throw new InvalidInputException('Mail server host is required');
		}
		if (mb_strlen($host) > 255 || mb_strlen($username) > 255 || mb_strlen($mailbox) > 255) {
			throw new InvalidInputException('Mail server settings must not exceed 255 characters');
		}
		// The same rejection ImapClient makes, applied at the config boundary so a
		// bad value is a 400 at save time rather than a mailbox that fails every
		// poll for a reason nobody can see.
		foreach (['host' => $host, 'username' => $username, 'mailbox' => $mailbox] as $what => $value) {
			if (preg_match('/[\r\n\x00]/', $value) === 1) {
				throw new InvalidInputException('Invalid ' . $what . ': line breaks are not allowed');
			}
		}
		if ($username === '') {
			throw new InvalidInputException('Mail account username is required');
		}
		if ($port < 1 || $port > 65535) {
			throw new InvalidInputException('Port must be between 1 and 65535');
		}
		if (!in_array($encryption, self::ENCRYPTIONS, true)) {
			throw new InvalidInputException('Encryption must be one of: ' . implode(', ', self::ENCRYPTIONS));
		}
		if (mb_strlen($senderAllowlist) > 4000) {
			throw new InvalidInputException('Sender allowlist is too long');
		}
		if ($this->findAliveStack($boardId, $stackId) === null) {
			throw new InvalidInputException('Stack does not belong to this board');
		}
		if ($password !== null && preg_match('/[\r\n\x00]/', $password) === 1) {
			throw new InvalidInputException('Invalid password: line breaks are not allowed');
		}

		$existing = null;
		try {
			$existing = $this->mapper->findByBoard($boardId);
		} catch (DoesNotExistException) {
			// First-time setup.
		}

		if ($password === null || $password === '') {
			if ($existing === null || $existing->getPassword() === '') {
				throw new InvalidInputException('Mail account password is required');
			}
		}

		$entity = $existing ?? new MailIntake();
		$entity->setBoardId($boardId);
		$entity->setStackId($stackId);
		// A move to a different account or mailbox invalidates the watermark: UIDs
		// are only meaningful within one mailbox on one server, so carrying the
		// old number over would skip everything below it on the new one.
		$identityChanged = $existing !== null && (
			$existing->getHost() !== $host
			|| $existing->getUsername() !== $username
			|| $existing->getMailbox() !== $mailbox
		);
		$entity->setHost($host);
		$entity->setPort($port);
		$entity->setEncryption($encryption);
		$entity->setUsername($username);
		if ($password !== null && $password !== '') {
			$entity->setPassword($this->crypto->encrypt($password));
		}
		$entity->setMailbox($mailbox);
		$entity->setSenderAllowlist(trim($senderAllowlist) === '' ? null : $senderAllowlist);
		$entity->setEnabled($enabled);
		if ($identityChanged) {
			$entity->setLastUid(0);
			$entity->setUidValidity(0);
		}

		if ($existing === null) {
			$entity->setLastUid(0);
			$entity->setUidValidity(0);
			$entity->setLastRun(0);
			$entity->setCreatedAt($this->time->getTime());
			// A config that has never been polled has no error to report, and a
			// stale one from a previous setup would be misleading.
			$entity->setLastError(null);
			return $this->mapper->insert($entity);
		}

		$entity->setLastError(null);
		return $this->mapper->update($entity);
	}

	/**
	 * Deletes the board's mailbox config, credential included. Requires MANAGE.
	 * Idempotent.
	 *
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function deleteConfig(int $boardId, string $actorUid): void {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);
		$this->mapper->deleteByBoard($boardId);
	}

	/**
	 * Connects, authenticates and selects the mailbox, then hangs up without
	 * reading anything. Requires MANAGE.
	 *
	 * Worth its own endpoint because the alternative is a person saving a config
	 * and waiting up to five minutes for cron to tell them the password is
	 * wrong.
	 *
	 * @return array{ok: bool, error: string|null}
	 * @throws DoesNotExistException if the board or its config does not exist
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function testConnection(int $boardId, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);

		$config = $this->mapper->findByBoard($boardId);

		$client = $this->clientFactory->create();
		try {
			$this->openMailbox($client, $config);
			return ['ok' => true, 'error' => null];
		} catch (ImapException $e) {
			return ['ok' => false, 'error' => $this->clampError($e->getMessage())];
		} finally {
			$client->disconnect();
		}
	}

	// ---- polling (cron) ----------------------------------------------------

	/**
	 * Polls every enabled mailbox. The cron entry point.
	 *
	 * One mailbox's failure is recorded and stepped over: a shared cron job that
	 * dies on the first unreachable server would let one board's expired password
	 * silently stop intake for every other board.
	 *
	 * @return int how many cards were created across all mailboxes
	 */
	public function pollAll(): int {
		$created = 0;
		foreach ($this->mapper->findEnabled() as $config) {
			try {
				$created += $this->poll($config);
			} catch (\Throwable $e) {
				// Already recorded on the row by poll(); this is the operator-facing
				// copy. The message can carry a server hostname but never a
				// credential - login failures are rewritten in ImapClient.
				$this->logger->warning('Kanso mail intake failed for board ' . $config->getBoardId(), [
					'exception' => $e,
					'app' => 'kanso',
				]);
			}
		}
		return $created;
	}

	/**
	 * Polls one mailbox: fetch everything above the watermark, card it, advance
	 * the watermark.
	 *
	 * The watermark advances per message, immediately after that message's card
	 * is created, and is persisted even when the run ends in an error. A crash
	 * halfway through therefore re-fetches at most the one message it was
	 * working on, rather than replaying the whole batch as duplicate cards.
	 *
	 * @return int cards created
	 */
	public function poll(MailIntake $config): int {
		$client = $this->clientFactory->create();
		$created = 0;

		try {
			// Board and stack are checked BEFORE the socket opens: a board in the
			// trash, or a config pointing at a deleted stack, has nowhere to put a
			// card, and connecting first would hand a credential to a remote server
			// on every tick to accomplish nothing. Neither advances the watermark,
			// so the mail is still waiting when the board or stack comes back.
			$board = $this->loadBoard($config->getBoardId());
			$stack = $this->findAliveStack($config->getBoardId(), $config->getStackId());
			if ($stack === null) {
				throw new ImapException('The stack email intake was pointing at no longer exists');
			}

			$status = $this->openMailbox($client, $config);

			// UIDs are only comparable within one UIDVALIDITY generation. When the
			// server renumbers (a restored or recreated mailbox), the old watermark
			// addresses different messages entirely - start from 0 and treat the
			// mailbox as new rather than skipping everything below a meaningless
			// number.
			if ($status['uidValidity'] !== $config->getUidValidity()) {
				$config->setUidValidity($status['uidValidity']);
				$config->setLastUid(0);
			}

			$uids = $client->searchUidsAbove($config->getLastUid());
			$allowed = $config->allowedSenders();

			foreach (array_slice($uids, 0, self::MAX_MESSAGES_PER_RUN) as $uid) {
				$raw = $client->fetchMessage($uid);
				// '' means the message vanished between SEARCH and FETCH, or was
				// over the size ceiling. Either way it is skipped, and the watermark
				// still moves past it so it is not retried forever.
				if ($raw !== '') {
					$message = $this->mimeParser->parse($raw);
					if ($this->senderAllowed($message->fromAddress, $allowed)) {
						if ($this->createCard($board, $stack, $message)) {
							$created++;
						}
					}
				}

				$config->setLastUid($uid);
			}

			$config->setLastError(null);
			return $created;
		} catch (\Throwable $e) {
			$config->setLastError($this->clampError($e->getMessage()));
			throw $e;
		} finally {
			$client->disconnect();
			// Always persisted, on both paths: the watermark advanced by the loop
			// above is what stops a failed run from re-carding what it already
			// carded, so it must survive the exception that interrupted it.
			$config->setLastRun($this->time->getTime());
			try {
				$this->mapper->update($config);
			} catch (\Throwable $persistError) {
				$this->logger->error('Kanso mail intake could not save its position for board ' . $config->getBoardId(), [
					'exception' => $persistError,
					'app' => 'kanso',
				]);
			}
		}
	}

	// ---- helpers -----------------------------------------------------------

	/**
	 * @return array{uidValidity: int, uidNext: int}
	 * @throws ImapException
	 */
	private function openMailbox(ImapClient $client, MailIntake $config): array {
		try {
			$password = $this->crypto->decrypt($config->getPassword());
		} catch (\Throwable) {
			// Typically a changed server secret, which makes every stored
			// credential undecryptable. Say what to do about it.
			throw new ImapException('The stored mail password could not be decrypted - re-enter it');
		}

		$client->connect($config->getHost(), $config->getPort(), $config->getEncryption());
		$client->login($config->getUsername(), $password);
		return $client->selectMailbox($config->getMailbox());
	}

	/**
	 * An empty allowlist accepts anything - the "public intake address" case.
	 * Otherwise the sender must match exactly, or match a `@domain.example`
	 * entry, which is how people actually want to express "anyone at my company".
	 *
	 * @param string[] $allowed already lowercased by {@see MailIntake::allowedSenders}
	 */
	private function senderAllowed(string $fromAddress, array $allowed): bool {
		if ($allowed === []) {
			return true;
		}
		if ($fromAddress === '') {
			// A message whose From we could not parse cannot satisfy a non-empty
			// allowlist. Fail closed.
			return false;
		}
		foreach ($allowed as $entry) {
			if ($entry === $fromAddress) {
				return true;
			}
			if (str_starts_with($entry, '@') && str_ends_with($fromAddress, $entry)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Creates the card. Returns false when the create was rejected, which is
	 * logged and skipped rather than aborting the batch.
	 */
	private function createCard(Board $board, Stack $stack, MimeMessage $message): bool {
		$title = $message->subject;
		if (mb_strlen($title) > CardService::MAX_TITLE_LENGTH) {
			$title = trim(mb_substr($title, 0, CardService::MAX_TITLE_LENGTH));
		}
		// AFTER truncation, so a title of nothing but spaces cannot reach create()
		// empty and throw.
		if ($title === '') {
			$title = 'Email from ' . ($message->fromLabel() !== '' ? $message->fromLabel() : 'unknown sender');
			$title = trim(mb_substr($title, 0, CardService::MAX_TITLE_LENGTH));
		}

		try {
			// As the board OWNER, exactly like forge issue intake - never as the
			// user whose address the (forgeable) From header claims.
			$card = $this->cardService->create($stack->getId(), $title, $board->getOwner());
		} catch (\Throwable $e) {
			$this->logger->warning('Kanso mail intake could not create a card on board ' . $board->getId(), [
				'exception' => $e,
				'app' => 'kanso',
			]);
			return false;
		}

		$description = $this->buildDescription($message);
		if ($description !== '') {
			try {
				$this->cardService->update(
					$card->getId(),
					null,
					$description,
					null,
					null,
					null,
					$board->getOwner(),
				);
			} catch (\Throwable $e) {
				// The card exists with its subject as the title; losing the body is
				// bad but recoverable, and failing here would re-card the message on
				// the next run.
				$this->logger->warning('Kanso mail intake could not save a card description on board ' . $board->getId(), [
					'exception' => $e,
					'app' => 'kanso',
				]);
			}
		}

		return true;
	}

	/**
	 * The card body: who the message claimed to be from, the text, and the names
	 * of anything attached.
	 *
	 * The sender line is plain text, not a mention or a link, because `From:` is
	 * unverified - see the class docblock.
	 */
	private function buildDescription(MimeMessage $message): string {
		$parts = [];

		$from = $message->fromLabel();
		if ($from !== '') {
			$parts[] = 'From: ' . $from;
		}
		if ($message->body !== '') {
			$parts[] = $message->body;
		}
		if ($message->attachmentNames !== []) {
			// Named, not stored - intake creates no card attachments yet, and a
			// silently dropped file is worse than a listed one.
			$parts[] = 'Attachments (not imported): ' . implode(', ', $message->attachmentNames);
		}

		$description = implode("\n\n", $parts);
		if (mb_strlen($description) > CardService::MAX_DESCRIPTION_LENGTH) {
			$description = mb_substr($description, 0, CardService::MAX_DESCRIPTION_LENGTH);
		}
		return $description;
	}

	private function clampError(string $message): string {
		$message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
		return mb_substr($message, 0, self::MAX_ERROR_LENGTH);
	}

	/**
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 */
	private function loadBoard(int $boardId): Board {
		$board = $this->boardMapper->find($boardId);
		if ($board->getDeletedAt() > 0) {
			throw new DoesNotExistException('Board ' . $boardId . ' is deleted');
		}
		return $board;
	}

	private function findAliveStack(int $boardId, int $stackId): ?Stack {
		try {
			$stack = $this->stackMapper->find($stackId);
		} catch (\Throwable) {
			return null;
		}
		if ($stack->getBoardId() !== $boardId || $stack->getDeletedAt() > 0) {
			return null;
		}
		return $stack;
	}
}
