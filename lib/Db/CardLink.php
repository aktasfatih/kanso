<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A GitHub link attached to a card (table `kanso_card_links`). `kind` is
 * pr|issue|other; `state` is open|closed|merged|unknown (best-effort, refreshed
 * by a throttled unauthenticated GitHub poll). `title` and `state` may be stale
 * or unknown — the chip always renders regardless.
 *
 * @method int getCardId()
 * @method void setCardId(int $cardId)
 * @method string getUrl()
 * @method void setUrl(string $url)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getState()
 * @method void setState(string $state)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method int getLastPolled()
 * @method void setLastPolled(int $lastPolled)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class CardLink extends Entity implements \JsonSerializable {
	public const KIND_PR = 'pr';
	public const KIND_ISSUE = 'issue';
	public const KIND_OTHER = 'other';

	public const STATE_OPEN = 'open';
	public const STATE_CLOSED = 'closed';
	public const STATE_MERGED = 'merged';
	public const STATE_UNKNOWN = 'unknown';

	protected ?int $cardId = null;
	protected ?string $url = null;
	protected ?string $kind = null;
	protected ?string $state = null;
	protected ?string $title = null;
	protected ?int $lastPolled = null;
	protected ?int $createdAt = null;

	public function __construct() {
		$this->addType('cardId', Types::INTEGER);
		$this->addType('url', Types::STRING);
		$this->addType('kind', Types::STRING);
		$this->addType('state', Types::STRING);
		$this->addType('title', Types::STRING);
		$this->addType('lastPolled', Types::INTEGER);
		$this->addType('createdAt', Types::INTEGER);
	}

	/**
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'cardId' => $this->getCardId(),
			'url' => $this->getUrl(),
			'kind' => $this->getKind(),
			'state' => $this->getState(),
			'title' => $this->getTitle(),
			'lastPolled' => $this->getLastPolled(),
			'createdAt' => $this->getCreatedAt(),
		];
	}
}
