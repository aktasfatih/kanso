<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A live link from a card to a Nextcloud Files node (table
 * `kanso_card_file_links`, KANSO-307). Unlike a {@see CardAttachment} no bytes
 * are copied: `fileId` is the reference, opened through NC's `/f/<fileid>`
 * permalink so Files' own ACL applies on open. `name`/`mime` are a display
 * snapshot from link time and may go stale after a rename - the chip still
 * opens the right node because the id, not the name, is what resolves.
 *
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
