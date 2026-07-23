<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One card/assignee assignment (table `kanso_card_assignees`).
 *
 * A plain join row — (card_id, participant, type) is unique. Only user
 * assignees (TYPE_USER) are supported for now; the `type` column stays for
 * future group support and every query filters on it. Assignments are never
 * serialized directly; board and card payloads carry plain assigneeId (uid)
 * arrays instead (see {@see CardAssigneeMapper}).
 *
 * @method int getCardId()
 * @method void setCardId(int $cardId)
 * @method string getParticipant()
 * @method void setParticipant(string $participant)
 * @method int getType()
 * @method void setType(int $type)
 */
class CardAssignee extends Entity {
	public const TYPE_USER = 0;
	public const TYPE_GROUP = 1;

	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?int $cardId = null;
	protected ?string $participant = null;
	protected ?int $type = null;

	public function __construct() {
		$this->addType('cardId', Types::INTEGER);
		$this->addType('participant', Types::STRING);
		$this->addType('type', Types::INTEGER);
	}
}
