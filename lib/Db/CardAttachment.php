<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A file attachment on a card (table `kanso_card_attachments`, #3526). The
 * bytes live in Kanso's own app-data, addressed by `storageKey` (a
 * server-generated opaque name); `filename` is the original client name kept
 * only as a display label. `boardId` is denormalized from the card for gating.
 *
 * @method int getCardId()
 * @method void setCardId(int $cardId)
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method string getFilename()
 * @method void setFilename(string $filename)
 * @method string getMime()
 * @method void setMime(string $mime)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method string getStorageKey()
 * @method void setStorageKey(string $storageKey)
 * @method string getUploadedBy()
 * @method void setUploadedBy(string $uploadedBy)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class CardAttachment extends Entity implements \JsonSerializable {
	protected ?int $cardId = null;
	protected ?int $boardId = null;
	protected ?string $filename = null;
	protected ?string $mime = null;
	protected ?int $size = null;
	protected ?string $storageKey = null;
	protected ?string $uploadedBy = null;
	protected ?int $createdAt = null;

	public function __construct() {
		$this->addType('cardId', Types::INTEGER);
		$this->addType('boardId', Types::INTEGER);
		$this->addType('filename', Types::STRING);
		$this->addType('mime', Types::STRING);
		$this->addType('size', Types::INTEGER);
		$this->addType('storageKey', Types::STRING);
		$this->addType('uploadedBy', Types::STRING);
		$this->addType('createdAt', Types::INTEGER);
	}

	/**
	 * Metadata only - the storage key is deliberately NOT exposed to clients
	 * (bytes are served through the download endpoint by attachment id).
	 *
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'cardId' => $this->getCardId(),
			'filename' => $this->getFilename(),
			'mime' => $this->getMime(),
			'size' => $this->getSize(),
			'uploadedBy' => $this->getUploadedBy(),
			'createdAt' => $this->getCreatedAt(),
		];
	}
}
