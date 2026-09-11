<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One already-carded message (table `kanso_mail_seen`), identified by the hash
 * of its Message-ID - see {@see \OCA\Kanso\Service\Mail\MimeMessage::dedupeKey}.
 *
 * Exists so that a mailbox re-delivering a message, or a watermark rewind after
 * the server renumbers, cannot produce a second card for mail already handled.
 *
 * @method int getIntakeId()
 * @method void setIntakeId(int $intakeId)
 * @method string getDedupeKey()
 * @method void setDedupeKey(string $dedupeKey)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class MailSeenMessage extends Entity {
	protected ?int $intakeId = null;
	protected ?string $dedupeKey = null;
	protected ?int $createdAt = null;

	public function __construct() {
		$this->addType('intakeId', Types::INTEGER);
		$this->addType('dedupeKey', Types::STRING);
		$this->addType('createdAt', Types::INTEGER);
	}
}
