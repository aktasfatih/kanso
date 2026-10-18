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
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getMime()
 * @method void setMime(string $mime)
 * @method string getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class CardFileLink extends Entity implements \JsonSerializable {
	protected ?int $cardId = null;
	protected ?int $fileId = null;
	protected ?string $name = null;
	protected ?string $mime = null;
	protected ?string $linkedBy = null;
	protected ?int $createdAt = null;

	public function __construct() {
		$this->addType('cardId', Types::INTEGER);
		$this->addType('fileId', Types::INTEGER);
		$this->addType('name', Types::STRING);
		$this->addType('mime', Types::STRING);
		$this->addType('linkedBy', Types::STRING);
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
			'fileId' => $this->getFileId(),
			'name' => $this->getName(),
			'mime' => $this->getMime(),
			'linkedBy' => $this->getLinkedBy(),
			'createdAt' => $this->getCreatedAt(),
		];
	}
}
