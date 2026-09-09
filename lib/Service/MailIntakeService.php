<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\MailIntake;
use OCA\Kanso\Db\MailIntakeMapper;
use OCA\Kanso\Db\MailSeenMessageMapper;
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
 * so sort keys, change rows and realtime all fire. What differs is the trust
 * level, and it differs completely: this is the ONLY path in the app where an
 * unauthenticated stranger causes a write, and their text lands in a card body
 * that colleagues read. Everything unusual in this class follows from that.
 *
 * ## What the sender is not
 *
 * `From:` is not authentication. SMTP lets anyone claim any address, and the
 * envelope is long gone by the time a message sits in IMAP. So:
 *
 * - The card is created as the board owner and NEVER attributed to the NC user
 *   whose address the header claims - that would turn a forged header into a
 *   way to act as someone else. The claimed sender is recorded as plain text.
 * - `$notifyMentions: false` on the description write. A description normally
 *   re-parses `@name` and sends real notifications; leaving that on would let
 *   anyone who knows the address ping arbitrary members with the owner's name
 *   attached.
 * - The allowlist is a FILTER, not an identity check, and is documented as
 *   such. `requireAuth` is what actually authenticates: it demands the
 *   receiving MTA's DMARC pass ({@see \OCA\Kanso\Service\Mail\AuthenticationResults}).
 *
 * ## Why a message gets skipped
 *
 * Automated mail, spam-flagged mail, disallowed senders, unauthenticated
 * senders and already-seen messages are all skipped, and the watermark still
 * advances past them - a skipped message that stayed unread would be
 * re-examined on every poll forever.
 *
 * The ONE exception is the whole-mailbox daily cap: hitting it stops the run
 * WITHOUT advancing, so the excess waits on the server instead of being
 * silently destroyed. A per-sender cap does the opposite and skips-with-advance
 * on purpose - otherwise one abusive sender wedges the mailbox for everyone.
 *
 * ## Not in scope
 *
 * Attachments are named in the card body, not stored; a reply is a new card,
 * not a comment. Both are deliberate - see docs/email-intake-security.md.
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

	/** Default whole-mailbox daily cap, overridable per mailbox. */
	public const DEFAULT_DAILY_LIMIT = 200;

	/** Default per-sender daily cap, overridable per mailbox. */
	public const DEFAULT_PER_SENDER_DAILY_LIMIT = 50;

	/**
	 * Wall-clock budget for one cron tick across ALL mailboxes. Each mailbox can
	 * burn a 30 s connect timeout, so without this a dozen unreachable servers
	 * turn a 5-minute job into a 6-minute one and cron starts overlapping.
	 */
	private const MAX_RUN_SECONDS = 120;

	/** Keeps a runaway server's error text out of the database and the UI. */
	private const MAX_ERROR_LENGTH = 500;

	/** Roughly daily, given the 5-minute poll interval. */
	private const PRUNE_PROBABILITY = 288;

	public function __construct(
		private MailIntakeMapper $mapper,
		private MailSeenMessageMapper $seenMapper,
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
		bool $requireAuth = false,
		int $dailyLimit = 0,
		int $perSenderDailyLimit = 0,
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
		if ($dailyLimit < 0 || $dailyLimit > 100000 || $perSenderDailyLimit < 0 || $perSenderDailyLimit > 100000) {
			throw new InvalidInputException('Daily limits must be between 0 and 100000');
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
		$entity->setRequireAuth($requireAuth);
		$entity->setDailyLimit($dailyLimit);
		$entity->setPerSenderDailyLimit($perSenderDailyLimit);
		if ($identityChanged) {
			$entity->setLastUid(0);
			$entity->setUidValidity(0);
		}

		if ($existing === null) {
			$entity->setLastUid(0);
			$entity->setUidValidity(0);
			$entity->setLastRun(0);
			$entity->setDailyState(null);
			$entity->setCreatedAt($this->time->getTime());
			// A config that has never been polled has no error to report, and a
			// stale one from a previous setup would be misleading.
			$entity->setLastError(null);
			$saved = $this->mapper->insert($entity);
			$this->audit('created', $boardId, $actorUid, $host, $username, $mailbox, $enabled);
			return $saved;
		}

		$entity->setLastError(null);
		$saved = $this->mapper->update($entity);
		$this->audit('updated', $boardId, $actorUid, $host, $username, $mailbox, $enabled);
		return $saved;
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

		try {
			$existing = $this->mapper->findByBoard($boardId);
			// The dedupe keys belong to this mailbox; they must not outlive it, or
			// a later mailbox reusing the row id would inherit them.
			$this->seenMapper->deleteByIntake($existing->getId());
		} catch (DoesNotExistException) {
			// Nothing configured - the delete below is still a safe no-op.
		}

		$this->mapper->deleteByBoard($boardId);
		$this->audit('deleted', $boardId, $actorUid, '', '', '', false);
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
			return ['ok' => false, 'error' => $this->clampError($e->getMessage(), $config)];
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
		$deadline = $this->time->getTime() + self::MAX_RUN_SECONDS;
		$skippedForTime = 0;

		foreach ($this->mapper->findEnabled() as $config) {
			// Whole-run budget. Mailboxes are ordered by id, so the ones deferred
			// here are picked up by the next tick rather than starved - and the
			// count is logged, because a silent partial run reads as a complete one.
			if ($this->time->getTime() >= $deadline) {
				$skippedForTime++;
				continue;
			}

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

		if ($skippedForTime > 0) {
			$this->logger->info(
				'Kanso mail intake ran out of time; ' . $skippedForTime . ' mailbox(es) deferred to the next run',
				['app' => 'kanso'],
			);
		}

		$this->pruneSeenOccasionally();

		return $created;
	}

	/**
	 * Polls one mailbox: fetch everything above the watermark, apply the intake
	 * policy, card what survives, advance the watermark.
	 *
	 * The watermark advances per message, immediately after that message is
	 * handled, and is persisted even when the run ends in an error. A crash
	 * halfway through therefore re-fetches at most the one message it was
	 * working on, rather than replaying the batch as duplicate cards.
	 *
	 * @return int cards created
	 */
	public function poll(MailIntake $config): int {
		$client = $this->clientFactory->create();
		$created = 0;
		$skipped = [];

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
			$this->applyUidValidity($config, $status);

			$day = (int)gmdate('Ymd', $this->time->getTime());
			$dailyLimit = $config->getDailyLimit() > 0 ? $config->getDailyLimit() : self::DEFAULT_DAILY_LIMIT;
			$perSenderLimit = $config->getPerSenderDailyLimit() > 0
				? $config->getPerSenderDailyLimit()
				: self::DEFAULT_PER_SENDER_DAILY_LIMIT;

			$uids = $client->searchUidsAbove($config->getLastUid());
			$allowed = $config->allowedSenders();

			foreach (array_slice($uids, 0, self::MAX_MESSAGES_PER_RUN) as $uid) {
				// The whole-mailbox cap STOPS the run without advancing, so the
				// excess waits on the server. Every other skip advances past the
				// message - see the class docblock.
				if ($config->cardedToday($day) >= $dailyLimit) {
					$skipped['daily_limit'] = ($skipped['daily_limit'] ?? 0) + 1;
					break;
				}

				$raw = $client->fetchMessage($uid);
				// '' means the message vanished between SEARCH and FETCH, or was
				// over the size ceiling. Either way it is skipped, and the watermark
				// still moves past it so it is not retried forever.
				if ($raw !== '') {
					$message = $this->mimeParser->parse($raw);
					$reason = $this->rejectionReason($message, $config, $allowed, $perSenderLimit, $day);

					if ($reason !== null) {
						$skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
					} elseif (!$this->seenMapper->claim($config->getId(), $message->dedupeKey())) {
						// Already carded - a re-delivery, or a watermark rewind.
						$skipped['duplicate'] = ($skipped['duplicate'] ?? 0) + 1;
					} elseif ($this->createCard($board, $stack, $message)) {
						$config->recordCarded($message->fromAddress, $day);
						$created++;
					}
				}

				$config->setLastUid($uid);
			}

			$config->setLastError($this->skipSummary($skipped));
			return $created;
		} catch (\Throwable $e) {
			$config->setLastError($this->clampError($e->getMessage(), $config));
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

	// ---- policy ------------------------------------------------------------

	/**
	 * Why this message must not become a card, or null to card it.
	 *
	 * @param string[] $allowed
	 */
	private function rejectionReason(
		MimeMessage $message,
		MailIntake $config,
		array $allowed,
		int $perSenderLimit,
		int $day,
	): ?string {
		// Loop breaker, checked FIRST and regardless of the allowlist: a bounce
		// from an allowed sender is still a bounce, and the notification->
		// autoreply->card cycle is the failure that runs away fastest.
		if ($message->isAutomated) {
			return 'automated';
		}
		if ($message->isSpam) {
			return 'spam';
		}
		if (!$this->senderAllowed($message->fromAddress, $allowed)) {
			return 'sender_not_allowed';
		}
		// The only check that actually authenticates the sender; the allowlist
		// above merely filters a forgeable header.
		if ($config->getRequireAuth() && !$message->isAuthenticated()) {
			return 'not_authenticated';
		}
		// Per-sender cap skips just this message: making one sender's flood stop
		// the mailbox would hand them a denial of service against the board.
		if ($message->fromAddress !== '' && $config->cardedTodayBy($message->fromAddress, $day) >= $perSenderLimit) {
			return 'sender_daily_limit';
		}
		return null;
	}

	/**
	 * How the intake position responds to the server's UIDVALIDITY.
	 *
	 * A mailbox that has never been polled starts at 0 and ingests what is
	 * already sitting there - that is what someone setting up a dedicated
	 * address expects.
	 *
	 * A mailbox whose UIDVALIDITY CHANGED was renumbered by the server (restored
	 * from backup, recreated). The old watermark now addresses different
	 * messages, and restarting at 0 would re-card the entire history. So intake
	 * jumps to the server's UIDNEXT: "card what arrives from now on". The dedupe
	 * table is the safety net either way.
	 *
	 * @param array{uidValidity: int, uidNext: int} $status
	 */
	private function applyUidValidity(MailIntake $config, array $status): void {
		if ($status['uidValidity'] === $config->getUidValidity()) {
			return;
		}

		$firstEverPoll = $config->getUidValidity() === 0;
		$config->setUidValidity($status['uidValidity']);

		if ($firstEverPoll) {
			$config->setLastUid(0);
			return;
		}

		// UIDNEXT is the id the NEXT message will get, so one less is "everything
		// currently in the mailbox". A server that did not report it leaves us
		// with 0, which is the safe-but-noisy fallback of reading from the start.
		$config->setLastUid(max(0, $status['uidNext'] - 1));
		$this->logger->info(
			'Kanso mail intake: mailbox for board ' . $config->getBoardId()
			. ' was renumbered (UIDVALIDITY changed); resuming from new mail only',
			['app' => 'kanso'],
		);
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

	// ---- card creation -----------------------------------------------------

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
					// Positional run-up to the one argument that matters here.
					null, null, null, null, null, null, null, null, null, null, null,
					// The whole reason this call is spelled out: the body is a
					// stranger's text being written as the board owner, so it must not
					// be allowed to fire @mention notifications in the owner's name.
					notifyMentions: false,
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
	 * The card body: where it came from, whether that origin was verified, the
	 * text, and the names of anything attached.
	 *
	 * The provenance line is not decoration. A reader seeing a card with a
	 * plausible sender and a link in it has no other way to know the content
	 * arrived from outside and that nobody proved who sent it - and `linkify`
	 * will have made that link clickable.
	 */
	private function buildDescription(MimeMessage $message): string {
		$parts = [];

		$from = $message->fromLabel();
		$provenance = $from !== ''
			? 'Received by email from ' . $from
			: 'Received by email from an unknown sender';
		$provenance .= $message->isAuthenticated()
			? ' (sender domain verified)'
			: ' (unverified sender - anyone can put any address here)';
		$parts[] = $provenance;

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
	 * A human-readable note about what a healthy run declined to card, or null
	 * when it carded everything. Written to `lastError` so a mailbox that is
	 * silently rejecting mail is diagnosable from the config screen - the
	 * commonest support question this feature can generate is "I sent an email
	 * and nothing happened".
	 *
	 * @param array<string, int> $skipped
	 */
	private function skipSummary(array $skipped): ?string {
		if ($skipped === []) {
			return null;
		}

		$labels = [
			'automated' => 'automated/bounce messages',
			'spam' => 'messages flagged as spam',
			'sender_not_allowed' => 'messages from senders not on the allowlist',
			'not_authenticated' => 'messages that failed sender authentication',
			'sender_daily_limit' => 'messages over a sender\'s daily limit',
			'daily_limit' => 'messages held back by the mailbox daily limit',
			'duplicate' => 'messages already turned into cards',
		];

		$parts = [];
		foreach ($skipped as $reason => $count) {
			$parts[] = $count . ' ' . ($labels[$reason] ?? $reason);
		}

		return $this->truncate('Last run skipped ' . implode(', ', $parts) . '.');
	}

	/**
	 * Clamps an error for storage, and removes the configured username from it -
	 * some servers echo the account name back in their error text, and
	 * `lastError` is shown in the UI and kept in the database.
	 */
	private function clampError(string $message, ?MailIntake $config = null): string {
		$message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

		$username = $config?->getUsername() ?? '';
		if ($username !== '' && mb_strlen($username) > 2) {
			$message = str_ireplace($username, '<account>', $message);
		}

		return $this->truncate($message);
	}

	private function truncate(string $message): string {
		return mb_substr($message, 0, self::MAX_ERROR_LENGTH);
	}

	/**
	 * Records a config change. Changing which mailbox feeds a board - or who it
	 * accepts mail from - is a security-relevant act, and it previously left no
	 * trace at all.
	 */
	private function audit(
		string $action,
		int $boardId,
		string $actorUid,
		string $host,
		string $username,
		string $mailbox,
		bool $enabled,
	): void {
		$this->logger->info('Kanso mail intake config ' . $action, [
			'app' => 'kanso',
			'action' => $action,
			'boardId' => $boardId,
			'actor' => $actorUid,
			'host' => $host,
			// The account name, never the credential.
			'username' => $username,
			'mailbox' => $mailbox,
			'enabled' => $enabled,
		]);
	}

	/**
	 * Prunes expired dedupe keys on roughly one run a day. Sampling rather than
	 * a second cron job: the work is tiny and its exact timing does not matter,
	 * and a dedicated job would be more moving parts than the task deserves.
	 */
	private function pruneSeenOccasionally(): void {
		try {
			if (random_int(1, self::PRUNE_PROBABILITY) !== 1) {
				return;
			}
			$cutoff = $this->time->getTime() - MailSeenMessageMapper::RETENTION_SECONDS;
			$removed = $this->seenMapper->pruneOlderThan($cutoff);
			if ($removed > 0) {
				$this->logger->debug('Kanso mail intake pruned ' . $removed . ' dedupe keys', ['app' => 'kanso']);
			}
		} catch (\Throwable $e) {
			// Housekeeping must never break a poll.
			$this->logger->warning('Kanso mail intake could not prune dedupe keys', [
				'exception' => $e,
				'app' => 'kanso',
			]);
		}
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
