<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\ProjectAccess;
use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\Project;
use OCA\Kanso\Db\ProjectMapper;
use OCA\Kanso\Db\ProjectPinMapper;
use OCA\Kanso\Db\ProjectShare;
use OCA\Kanso\Db\ProjectShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Project sharing. Only the owner manages shares; a directly-shared user may
 * leave. A share is READ (view) or READ|EDIT, and never reaches past the boards:
 * see {@see ProjectAccess}.
 */
class ProjectShareService {
	private const ALLOWED_PERMISSIONS = PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT;

	public function __construct(
		private ProjectShareMapper $shareMapper,
		private ProjectPinMapper $pinMapper,
		private ProjectMapper $projectMapper,
		private ProjectAccess $projectAccess,
		private ShareeSearchService $shareeSearch,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
	) {
	}

	/**
	 * @return list<array<string, mixed>>
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor is not the owner
	 */
	public function listForProject(int $projectId, string $actorUid): array {
		$this->loadOwnedProject($projectId, $actorUid);

		return array_values(array_map(
			fn (ProjectShare $share): array => $this->serialize($share),
			$this->shareMapper->findByProject($projectId)
		));
	}

	/**
	 * @param string $participantType 'user' or 'group'
	 * @param int $permission bitmask of READ and EDIT; READ is always included
	 * @return array<string, mixed>
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor is not the owner
	 * @throws InvalidInputException on unknown permission bits, an invalid participant
	 *                               type, the owner, a target the actor may not share with
	 *                               or a duplicate
	 */
	public function create(int $projectId, string $participant, string $participantType, int $permission, string $actorUid): array {
		$project = $this->loadOwnedProject($projectId, $actorUid);

		$permission = $this->validateMask($permission);
		$type = match ($participantType) {
			'user' => Acl::TYPE_USER,
			'group' => Acl::TYPE_GROUP,
			default => throw new InvalidInputException("Participant type must be 'user' or 'group'"),
		};
		$participant = $this->shareeSearch->resolveParticipant($type, $participant, $actorUid);
		if ($type === Acl::TYPE_USER && $participant === $project->getOwner()) {
			throw new InvalidInputException('Cannot share a project with its owner');
		}
		if ($this->shareMapper->findByParticipant($projectId, $type, $participant) !== null) {
			throw new InvalidInputException('Already shared with this participant');
		}

		$share = new ProjectShare();
		$share->setProjectId($projectId);
		$share->setParticipantType($type);
		$share->setParticipant($participant);
		$share->setPermission($permission);

		try {
			$share = $this->shareMapper->insert($share);
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new InvalidInputException('Already shared with this participant');
			}
			throw $e;
		}

		return $this->serialize($share);
	}

	/**
	 * @return array<string, mixed>
	 * @throws DoesNotExistException if the project or the share does not exist
	 * @throws NotPermittedException if the actor is not the owner
	 * @throws InvalidInputException on unknown permission bits, or added bits for a
	 *                               target the actor may no longer share with
	 */
	public function update(int $projectId, int $shareId, int $permission, string $actorUid): array {
		$this->loadOwnedProject($projectId, $actorUid);
		$share = $this->loadShare($projectId, $shareId);

		$permission = $this->validateMask($permission);
		if (($permission & ~$share->getPermission()) !== 0) {
			$this->shareeSearch->resolveParticipant($share->getParticipantType(), (string)$share->getParticipant(), $actorUid);
		}
		$share->setPermission($permission);

		return $this->serialize($this->shareMapper->update($share));
	}

	/**
	 * @throws DoesNotExistException if the project or the share does not exist
	 * @throws NotPermittedException if the actor is not the owner
	 */
	public function delete(int $projectId, int $shareId, string $actorUid): void {
		$project = $this->loadOwnedProject($projectId, $actorUid);
		$share = $this->loadShare($projectId, $shareId);
		$this->shareMapper->delete($share);
		if ($share->getParticipantType() === Acl::TYPE_USER) {
			$this->unpinIfUnreachable($project, (string)$share->getParticipant());
		}
	}

	/**
	 * Removes the actor's own user share.
	 *
	 * @throws DoesNotExistException if the project does not exist or the actor cannot reach it
	 * @throws NotPermittedException if the actor holds no direct share
	 */
	public function leave(int $projectId, string $actorUid): void {
		$project = $this->projectMapper->find($projectId);
		$this->projectAccess->assertPermission($project, $actorUid, PermissionService::PERMISSION_READ);
		$share = $this->shareMapper->findByParticipant($projectId, Acl::TYPE_USER, $actorUid);
		if ($share === null) {
			throw new NotPermittedException('This project was not shared with you directly');
		}
		$this->shareMapper->delete($share);
		$this->unpinIfUnreachable($project, $actorUid);
	}

	private function unpinIfUnreachable(Project $project, string $uid): void {
		if ($this->projectAccess->getPermissions($project, $uid) === 0) {
			$this->pinMapper->unpin($uid, $project->getId());
		}
	}

	/**
	 * Users and groups matching $q the project is not already shared with.
	 *
	 * @return list<array{id: string, displayName: string, type: string}>
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor is not the owner
	 */
	public function search(int $projectId, string $q, string $actorUid): array {
		$project = $this->loadOwnedProject($projectId, $actorUid);

		if (!$this->shareeSearch->isSearchable($q)) {
			return [];
		}

		$excludedUsers = [(string)$project->getOwner() => true];
		$excludedGroups = [];
		foreach ($this->shareMapper->findByProject($projectId) as $share) {
			if ($share->getParticipantType() === Acl::TYPE_USER) {
				$excludedUsers[$share->getParticipant()] = true;
			} else {
				$excludedGroups[$share->getParticipant()] = true;
			}
		}

		return $this->shareeSearch->search($q, $actorUid, $excludedUsers, $excludedGroups);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function serialize(ProjectShare $share): array {
		$participant = (string)$share->getParticipant();
		$displayName = $share->getParticipantType() === Acl::TYPE_GROUP
			? $this->groupManager->getDisplayName($participant)
			: $this->userManager->getDisplayName($participant);

		return $share->jsonSerialize() + ['displayName' => $displayName ?: $participant];
	}

	/**
	 * @throws InvalidInputException on bits other than READ and EDIT
	 */
	private function validateMask(int $mask): int {
		if (($mask & ~self::ALLOWED_PERMISSIONS) !== 0) {
			throw new InvalidInputException('Unknown permission bits');
		}
		return $mask | PermissionService::PERMISSION_READ;
	}

	/**
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor is not the owner
	 */
	private function loadOwnedProject(int $id, string $uid): Project {
		$project = $this->projectMapper->find($id);
		$this->projectAccess->assertOwner($project, $uid);
		return $project;
	}

	/**
	 * @throws DoesNotExistException if the share does not exist or belongs to another project
	 */
	private function loadShare(int $projectId, int $shareId): ProjectShare {
		$share = $this->shareMapper->find($shareId);
		if ($share->getProjectId() !== $projectId) {
			throw new DoesNotExistException('Project share ' . $shareId . ' not found');
		}
		return $share;
	}
}
