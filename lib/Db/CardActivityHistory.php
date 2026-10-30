<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getCardId()
 * @method void setCardId(int $cardId)
 * @method int getAction()
 * @method void setAction(int $action)
 * @method string|null getActor()
 * @method void setActor(?string $actor)
 * @method int|null getVerb()
 * @method void setVerb(?int $verb)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method bool getHasDetail()
 * @method void setHasDetail(bool $hasDetail)
 * @method string|null getFromText()
 * @method void setFromText(?string $fromText)
 * @method string|null getToText()
 * @method void setToText(?string $toText)
 */
class CardActivityHistory extends Entity {
	protected ?int $cardId = null;
	protected ?int $action = null;
	protected ?string $actor = null;
	protected ?int $verb = null;
	protected ?int $createdAt = null;
	protected ?bool $hasDetail = null;
	protected ?string $fromText = null;
	protected ?string $toText = null;

	public function __construct() {
		$this->addType('cardId', Types::INTEGER);
		$this->addType('action', Types::INTEGER);
		$this->addType('actor', Types::STRING);
		$this->addType('verb', Types::INTEGER);
		$this->addType('createdAt', Types::INTEGER);
		$this->addType('hasDetail', Types::BOOLEAN);
		$this->addType('fromText', Types::TEXT);
		$this->addType('toText', Types::TEXT);
	}
}
