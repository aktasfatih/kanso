<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One card/label assignment (table `kanso_card_labels`).
 *
 * A plain join row — (card_id, label_id) is unique. Assignments are never
 * serialized directly; board and card payloads carry plain labelId arrays
 * instead (see {@see CardLabelMapper}).
 *
 * @method int getCardId()
 * @method void setCardId(int $cardId)
 * @method int getLabelId()
 * @method void setLabelId(int $labelId)
 */
class CardLabel extends Entity {
	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?int $cardId = null;
	protected ?int $labelId = null;

	public function __construct() {
		$this->addType('cardId', Types::INTEGER);
		$this->addType('labelId', Types::INTEGER);
	}
}
