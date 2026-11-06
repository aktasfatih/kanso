<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One project sharing rule (table `kanso_project_shares`). `participant_type`
 * uses the Acl::TYPE_* values; `permission` is READ, or READ|EDIT, from the
 * PermissionService::PERMISSION_* bits.
 *
 * @method int getProjectId()
 * @method void setProjectId(int $projectId)
 * @method int getParticipantType()
 * @method void setParticipantType(int $participantType)
 * @method string getParticipant()
 * @method void setParticipant(string $participant)
 * @method int getPermission()
 * @method void setPermission(int $permission)
 */
class ProjectShare extends Entity implements \JsonSerializable {
	// Null defaults: Entity::setter() skips values equal to the current one, so
	// a non-null default would keep that value out of INSERT statements.
	protected ?int $projectId = null;
	protected ?int $participantType = null;
	protected ?string $participant = null;
	protected ?int $permission = null;

	public function __construct() {
		$this->addType('projectId', Types::INTEGER);
		$this->addType('participantType', Types::INTEGER);
		$this->addType('participant', Types::STRING);
		$this->addType('permission', Types::INTEGER);
	}

	/**
	 * @return array{id: int, projectId: ?int, participant: ?string, participantType: string, permission: ?int}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'projectId' => $this->projectId,
			'participant' => $this->participant,
			'participantType' => $this->participantType === Acl::TYPE_GROUP ? 'group' : 'user',
			'permission' => $this->permission,
		];
	}
}
