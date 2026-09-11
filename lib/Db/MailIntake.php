<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A board's email-intake mailbox (table `kanso_mail_intake`): the IMAP account
 * {@see \OCA\Kanso\Cron\PollMailIntake} polls, and the stack the cards it
 * creates land in. At most one per board.
 *
 * `password` holds ICrypto CIPHERTEXT, never the secret itself, and
 * {@see jsonSerialize} does not emit it - not even redacted-but-present, so
 * there is no field a future UI could accidentally round-trip back as a literal
 * password. The config endpoint reports `hasPassword` instead.
 *
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method int getStackId()
 * @method void setStackId(int $stackId)
 * @method string getHost()
 * @method void setHost(string $host)
 * @method int getPort()
 * @method void setPort(int $port)
 * @method string getEncryption()
 * @method void setEncryption(string $encryption)
 * @method string getUsername()
 * @method void setUsername(string $username)
 * @method string getPassword()
 * @method void setPassword(string $password)
 * @method string getMailbox()
 * @method void setMailbox(string $mailbox)
 * @method string|null getSenderAllowlist()
 * @method void setSenderAllowlist(?string $senderAllowlist)
 * @method bool getEnabled()
 * @method void setEnabled(bool $enabled)
 * @method int getLastUid()
 * @method void setLastUid(int $lastUid)
 * @method int getUidValidity()
 * @method void setUidValidity(int $uidValidity)
 * @method int getLastRun()
 * @method void setLastRun(int $lastRun)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method bool getRequireAuth()
 * @method void setRequireAuth(bool $requireAuth)
 * @method string|null getDailyState()
 * @method void setDailyState(?string $dailyState)
 * @method int getDailyLimit()
 * @method void setDailyLimit(int $dailyLimit)
 * @method int getPerSenderDailyLimit()
 * @method void setPerSenderDailyLimit(int $perSenderDailyLimit)
 */
class MailIntake extends Entity implements \JsonSerializable {
	/** Implicit TLS from the first byte (the 993 default). */
	public const ENCRYPTION_SSL = 'ssl';
	/** Cleartext connect on 143, upgraded with STARTTLS before LOGIN. */
	public const ENCRYPTION_TLS = 'tls';

	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?int $boardId = null;
	protected ?int $stackId = null;
	protected ?string $host = null;
	protected ?int $port = null;
	protected ?string $encryption = null;
	protected ?string $username = null;
	protected ?string $password = null;
	protected ?string $mailbox = null;
	protected ?string $senderAllowlist = null;
	protected ?bool $enabled = null;
	protected ?int $lastUid = null;
	protected ?int $uidValidity = null;
	protected ?int $lastRun = null;
	protected ?string $lastError = null;
	protected ?int $createdAt = null;
	protected ?bool $requireAuth = null;
	protected ?string $dailyState = null;
	protected ?int $dailyLimit = null;
	protected ?int $perSenderDailyLimit = null;

	public function __construct() {
		$this->addType('boardId', Types::INTEGER);
		$this->addType('stackId', Types::INTEGER);
		$this->addType('host', Types::STRING);
		$this->addType('port', Types::INTEGER);
		$this->addType('encryption', Types::STRING);
		$this->addType('username', Types::STRING);
		$this->addType('password', Types::STRING);
		$this->addType('mailbox', Types::STRING);
		$this->addType('senderAllowlist', Types::STRING);
		$this->addType('enabled', Types::BOOLEAN);
		$this->addType('lastUid', Types::INTEGER);
		$this->addType('uidValidity', Types::INTEGER);
		$this->addType('lastRun', Types::INTEGER);
		$this->addType('lastError', Types::STRING);
		$this->addType('createdAt', Types::INTEGER);
		$this->addType('requireAuth', Types::BOOLEAN);
		$this->addType('dailyState', Types::STRING);
		$this->addType('dailyLimit', Types::INTEGER);
		$this->addType('perSenderDailyLimit', Types::INTEGER);
	}

	/**
	 * The allowlist as trimmed, lowercased addresses. Stored as one blob so the
	 * config surface stays a single textarea; split here so every consumer reads
	 * it the same way.
	 *
	 * @return string[] empty when the mailbox accepts any sender
	 */
	public function allowedSenders(): array {
		$raw = $this->senderAllowlist ?? '';
		if (trim($raw) === '') {
			return [];
		}
		// Commas and newlines both separate - people paste either.
		$parts = preg_split('/[\s,;]+/', $raw) ?: [];
		$out = [];
		foreach ($parts as $part) {
			$part = mb_strtolower(trim($part));
			if ($part !== '') {
				$out[] = $part;
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * How many distinct senders the per-sender counter tracks in a day. When the
	 * map is full it is pruned to the HIGHEST counts, which keeps precisely the
	 * senders a per-sender limit exists to catch; a sender that drops out starts
	 * again from zero but is still bounded by the whole-mailbox daily cap.
	 */
	private const MAX_TRACKED_SENDERS = 500;

	/**
	 * Today's counters, resetting automatically when the day rolls over.
	 *
	 * @param int $day the current day as YYYYMMDD
	 * @return array{day: int, total: int, senders: array<string, int>}
	 */
	public function dailyCounters(int $day): array {
		$empty = ['day' => $day, 'total' => 0, 'senders' => []];

		$raw = $this->dailyState ?? '';
		if (trim($raw) === '') {
			return $empty;
		}

		$decoded = json_decode($raw, true);
		// Corrupt or hand-edited state must not stall intake - start the day over.
		if (!is_array($decoded) || (int)($decoded['day'] ?? 0) !== $day) {
			return $empty;
		}

		$senders = [];
		if (isset($decoded['senders']) && is_array($decoded['senders'])) {
			foreach ($decoded['senders'] as $sender => $count) {
				if (is_string($sender) && is_int($count)) {
					$senders[$sender] = $count;
				}
			}
		}

		return [
			'day' => $day,
			'total' => max(0, (int)($decoded['total'] ?? 0)),
			'senders' => $senders,
		];
	}

	public function cardedToday(int $day): int {
		return $this->dailyCounters($day)['total'];
	}

	public function cardedTodayBy(string $sender, int $day): int {
		return $this->dailyCounters($day)['senders'][$sender] ?? 0;
	}

	/** Counts one carded message against today's totals. */
	public function recordCarded(string $sender, int $day): void {
		$counters = $this->dailyCounters($day);
		$counters['total']++;
		if ($sender !== '') {
			$counters['senders'][$sender] = ($counters['senders'][$sender] ?? 0) + 1;
		}

		if (count($counters['senders']) > self::MAX_TRACKED_SENDERS) {
			arsort($counters['senders']);
			$counters['senders'] = array_slice($counters['senders'], 0, self::MAX_TRACKED_SENDERS, true);
		}

		$this->setDailyState((string)json_encode($counters));
	}

	/**
	 * @return array{id: int, boardId: int, stackId: int, host: string, port: int, encryption: string, username: string, hasPassword: bool, mailbox: string, senderAllowlist: string, enabled: bool, requireAuth: bool, dailyLimit: int, perSenderDailyLimit: int, lastRun: int, lastError: string|null}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (int)$this->id,
			'boardId' => (int)$this->boardId,
			'stackId' => (int)$this->stackId,
			'host' => (string)$this->host,
			'port' => (int)$this->port,
			'encryption' => (string)$this->encryption,
			'username' => (string)$this->username,
			// Never the credential, not even a masked stand-in.
			'hasPassword' => ($this->password ?? '') !== '',
			'mailbox' => (string)$this->mailbox,
			'senderAllowlist' => $this->senderAllowlist ?? '',
			'enabled' => (bool)$this->enabled,
			'requireAuth' => (bool)$this->requireAuth,
			'dailyLimit' => (int)$this->dailyLimit,
			'perSenderDailyLimit' => (int)$this->perSenderDailyLimit,
			'lastRun' => (int)$this->lastRun,
			'lastError' => $this->lastError,
			// dailyState is operational detail, not config - deliberately absent.
		];
	}
}
