<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCA\Kanso\Access\ViewerContext;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One board sharing rule (table `kanso_board_acl`).
 *
 * `permission` is a bitmask of the PermissionService::PERMISSION_* bits.
 * Rows are written by AclService (the sharing endpoints) and read by
 * PermissionService for every permission check.
 *
 * `role` (#3742) is the member's board side - 'internal' (provider) or
 * 'external' (client), one of the ViewerContext::ROLE_* values. A user
 * matching several rows (via groups) gets ONE effective role, folded
 * internal-wins by \OCA\Kanso\Access\BoardAccess.
 *
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method int getParticipantType()
 * @method void setParticipantType(int $participantType)
 * @method string getParticipant()
 * @method void setParticipant(string $participant)
 * @method int getPermission()
 * @method void setPermission(int $permission)
 * @method string|null getRole()
 * @method void setRole(string $role)
 */
class Acl extends Entity implements \JsonSerializable {
	public const TYPE_USER = 0;
	public const TYPE_GROUP = 1;

	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?int $boardId = null;
	protected ?int $participantType = null;
	protected ?string $participant = null;
	protected ?int $permission = null;
	protected ?string $role = null;

	public function __construct() {
		$this->addType('boardId', Types::INTEGER);
		$this->addType('participantType', Types::INTEGER);
		$this->addType('participant', Types::STRING);
		$this->addType('permission', Types::INTEGER);
		$this->addType('role', Types::STRING);
	}

	/**
	 * `participantType` serializes as 'user'/'group' - the API never leaks
	 * the numeric storage constants.
	 *
	 * @return array{id: int, boardId: ?int, participant: ?string, participantType: string, permission: ?int, role: string}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'boardId' => $this->boardId,
			'participant' => $this->participant,
			'participantType' => $this->participantType === self::TYPE_GROUP ? 'group' : 'user',
			'permission' => $this->permission,
			// A row hydrated before the role column existed reads as
			// 'internal', matching the migration backfill.
			'role' => $this->role ?? ViewerContext::ROLE_INTERNAL,
		];
	}
}
