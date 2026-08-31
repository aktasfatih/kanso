<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A recurring card rule (table `kanso_recur_rules`): spawn a card on a schedule.
 *
 * `mode` selects CLONE (copy the template card into the target stack every
 * occurrence) or RESET (move the one template card back to the target stack and
 * clear its done state). `duedatePolicy` decides the spawned card's due date
 * (see the POLICY_* constants). `nextOccurrenceAt` caches the next fire time so
 * the cron scan is a single indexed range query; 0 means exhausted/never and,
 * together with `enabled = false`, marks a rule that self-disabled after its
 * COUNT/UNTIL ran out. The DTSTART anchor the RRULE is expanded from is the
 * template card's Start date, else its due date, else this rule's `createdAt`
 * as a last resort (see `RecurrenceService::anchorFor()`); a template dated in
 * the future first fires on that date itself, unfiltered by any BY* rule part.
 *
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method int getTemplateCardId()
 * @method void setTemplateCardId(int $templateCardId)
 * @method int getTargetStackId()
 * @method void setTargetStackId(int $targetStackId)
 * @method int getMode()
 * @method void setMode(int $mode)
 * @method string getRrule()
 * @method void setRrule(string $rrule)
 * @method int getDuedatePolicy()
 * @method void setDuedatePolicy(int $duedatePolicy)
 * @method int getDuedateOffsetSeconds()
 * @method void setDuedateOffsetSeconds(int $duedateOffsetSeconds)
 * @method bool getSkipWhileOpen()
 * @method void setSkipWhileOpen(bool $skipWhileOpen)
 * @method bool getEnabled()
 * @method void setEnabled(bool $enabled)
 * @method string getOwner()
 * @method void setOwner(string $owner)
 * @method int getLastSpawnedAt()
 * @method void setLastSpawnedAt(int $lastSpawnedAt)
 * @method int getNextOccurrenceAt()
 * @method void setNextOccurrenceAt(int $nextOccurrenceAt)
 * @method int getOccurrencesSpawned()
 * @method void setOccurrencesSpawned(int $occurrencesSpawned)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method ?string getTimezone()
 * @method void setTimezone(?string $timezone)
 */
class RecurRule extends Entity implements \JsonSerializable {
	/** Each occurrence creates a fresh copy of the template card. */
	public const MODE_CLONE = 0;
	/** Each occurrence moves the template card back and clears its done state. */
	public const MODE_RESET = 1;

	/** Due date = the occurrence time. */
	public const POLICY_AT_OCCURRENCE = 0;
	/** Due date = the occurrence time + `duedateOffsetSeconds`. */
	public const POLICY_OFFSET_AFTER = 1;
	/** Spawned cards get no due date. */
	public const POLICY_NONE = 2;

	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?int $boardId = null;
	protected ?int $templateCardId = null;
	protected ?int $targetStackId = null;
	protected ?int $mode = null;
	protected ?string $rrule = null;
	protected ?int $duedatePolicy = null;
	protected ?int $duedateOffsetSeconds = null;
	protected ?bool $skipWhileOpen = null;
	protected ?bool $enabled = null;
	protected ?string $owner = null;
	protected ?int $lastSpawnedAt = null;
	protected ?int $nextOccurrenceAt = null;
	protected ?int $occurrencesSpawned = null;
	protected ?int $createdAt = null;
	// IANA timezone id the RRULE is anchored in (floating wall-clock time).
	// NULL = fall back to the server default timezone (pre-#3587 rules).
	protected ?string $timezone = null;

	public function __construct() {
		$this->addType('boardId', Types::INTEGER);
		$this->addType('templateCardId', Types::INTEGER);
		$this->addType('targetStackId', Types::INTEGER);
		$this->addType('mode', Types::INTEGER);
		$this->addType('rrule', Types::STRING);
		$this->addType('duedatePolicy', Types::INTEGER);
		$this->addType('duedateOffsetSeconds', Types::INTEGER);
		$this->addType('skipWhileOpen', Types::BOOLEAN);
		$this->addType('enabled', Types::BOOLEAN);
		$this->addType('owner', Types::STRING);
		$this->addType('lastSpawnedAt', Types::INTEGER);
		$this->addType('nextOccurrenceAt', Types::INTEGER);
		$this->addType('occurrencesSpawned', Types::INTEGER);
		$this->addType('createdAt', Types::INTEGER);
		$this->addType('timezone', Types::STRING);
	}

	/**
	 * @return array{id: int, boardId: ?int, templateCardId: ?int, targetStackId: ?int, mode: int, rrule: ?string, duedatePolicy: int, duedateOffsetSeconds: int, skipWhileOpen: bool, enabled: bool, owner: ?string, lastSpawnedAt: int, nextOccurrenceAt: int, occurrencesSpawned: int, createdAt: int, timezone: ?string}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'boardId' => $this->boardId,
			'templateCardId' => $this->templateCardId,
			'targetStackId' => $this->targetStackId,
			'mode' => $this->mode ?? self::MODE_CLONE,
			'rrule' => $this->rrule,
			'duedatePolicy' => $this->duedatePolicy ?? self::POLICY_AT_OCCURRENCE,
			'duedateOffsetSeconds' => $this->duedateOffsetSeconds ?? 0,
			'skipWhileOpen' => $this->skipWhileOpen ?? false,
			'enabled' => $this->enabled ?? false,
			'owner' => $this->owner,
			'lastSpawnedAt' => $this->lastSpawnedAt ?? 0,
			'nextOccurrenceAt' => $this->nextOccurrenceAt ?? 0,
			'occurrencesSpawned' => $this->occurrencesSpawned ?? 0,
			'createdAt' => $this->createdAt ?? 0,
			'timezone' => $this->timezone,
		];
	}
}
