<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * The before/after text detail of one change row (table `kanso_change_details`).
 *
 * Kept out of `kanso_changes` (the hot delta-sync/ETag log) so its wide nullable
 * TEXT payload never bloats board polls. `changeId` links back to the
 * `kanso_changes.id` this detail belongs to; the Activity feed batch-loads these
 * for description-update rows to render a from → to diff.
 *
 * @method int getChangeId()
 * @method void setChangeId(int $changeId)
 * @method string|null getFromText()
 * @method void setFromText(?string $fromText)
 * @method string|null getToText()
 * @method void setToText(?string $toText)
 */
class ChangeDetail extends Entity implements \JsonSerializable {
	protected ?int $changeId = null;
	protected ?string $fromText = null;
	protected ?string $toText = null;

	public function __construct() {
		$this->addType('changeId', Types::INTEGER);
		$this->addType('fromText', Types::TEXT);
		$this->addType('toText', Types::TEXT);
	}

	/**
	 * @return array{id: int, changeId: int, from: ?string, to: ?string}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'changeId' => $this->getChangeId(),
			'from' => $this->getFromText(),
			'to' => $this->getToText(),
		];
	}
}
