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
	 * @return array{id: int, boardId: int, stackId: int, host: string, port: int, encryption: string, username: string, hasPassword: bool, mailbox: string, senderAllowlist: string, enabled: bool, lastRun: int, lastError: string|null}
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
			'lastRun' => (int)$this->lastRun,
			'lastError' => $this->lastError,
		];
	}
}
