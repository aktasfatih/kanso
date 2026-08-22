<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One row of the per-board change log (table `kanso_changes`).
 *
 * The log powers delta sync: clients poll with their last seen change id
 * (`?since=<changeId>`) and receive only newer rows.
 *
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method int getEntityType()
 * @method void setEntityType(int $entityType)
 * @method int getEntityId()
 * @method void setEntityId(int $entityId)
 * @method int getAction()
 * @method void setAction(int $action)
 * @method string|null getActor()
 * @method void setActor(?string $actor)
 * @method int|null getVerb()
 * @method void setVerb(?int $verb)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Change extends Entity {
	public const ENTITY_BOARD = 0;
	public const ENTITY_STACK = 1;
	public const ENTITY_CARD = 2;
	public const ENTITY_LABEL = 3;
	public const ENTITY_ACL = 4;
	public const ENTITY_REVIEW_TYPE = 5;
	public const ENTITY_CARD_FIELD = 6;

	public const ACTION_CREATE = 0;
	public const ACTION_UPDATE = 1;
	public const ACTION_DELETE = 2;
	public const ACTION_MOVE = 3;

	// Fine-grained "what happened" for the per-card Activity feed. Nullable and
	// additive over (entity_type, action): a null verb renders as a generic
	// "updated". Only card-scoped mutations stamp these.
	public const VERB_CREATED = 1;
	public const VERB_UPDATED = 2;
	public const VERB_MOVED = 3;
	public const VERB_DELETED = 4;
	public const VERB_COMMENTED = 5;
	public const VERB_LABELED = 6;
	public const VERB_UNLABELED = 7;
	public const VERB_ASSIGNED = 8;
	public const VERB_UNASSIGNED = 9;
	public const VERB_REVIEW_REQUESTED = 10;
	public const VERB_REVIEW_VERDICT = 11;
	public const VERB_CHECKLIST = 12;
	public const VERB_CONTACT_LINKED = 13;
	public const VERB_CONTACT_UNLINKED = 14;
	// Field-specific card update verbs (#70). The card-field update path picks the
	// matching verb when exactly one tracked field changed; multi-field or no-op
	// saves keep the generic VERB_UPDATED. Verb-only (no from/to values, deferred).
	public const VERB_RENAMED = 15;              // title changed
	public const VERB_DESCRIPTION_UPDATED = 16;
	public const VERB_DUE_CHANGED = 17;          // due date set/changed/cleared
	public const VERB_START_CHANGED = 18;        // start date
	public const VERB_PRIORITY_CHANGED = 19;
	public const VERB_STATUS_CHANGED = 20;       // status applied timestamp-only (no workflow column)
	public const VERB_ESTIMATE_CHANGED = 21;
	public const VERB_TYPE_CHANGED = 22;

	// Properties default to null (not to 0 / a constant): Entity::setter()
	// skips values equal to the current one, so e.g. a default of
	// ACTION_CREATE would silently drop `action` from INSERTs of create
	// changes and violate the NOT NULL constraint.
	protected ?int $boardId = null;
	protected ?int $entityType = null;
	protected ?int $entityId = null;
	protected ?int $action = null;
	protected ?string $actor = null;
	protected ?int $verb = null;
	protected ?int $createdAt = null;

	public function __construct() {
		$this->addType('boardId', Types::INTEGER);
		$this->addType('entityType', Types::INTEGER);
		$this->addType('entityId', Types::INTEGER);
		$this->addType('action', Types::INTEGER);
		$this->addType('actor', Types::STRING);
		$this->addType('verb', Types::INTEGER);
		$this->addType('createdAt', Types::INTEGER);
	}
}
