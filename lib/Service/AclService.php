<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\AclMapper;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\Change;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Board sharing (ACL) management. SHARE lets a user hand out or adjust
 * sharing rules, but never with more permission bits than they hold
 * themselves — only MANAGE may grant beyond the actor's own bits (the
 * escalation cap). Revoking needs MANAGE, except for self-removal (leaving
 * a board). Every mutation appends an ENTITY_ACL row to the `kanso_changes`
 * log so shared boards delta-sync their member lists like any other entity.
 */
class AclService {
	private const SEARCH_LIMIT = 25;

	public function __construct(
		private AclMapper $aclMapper,
		private BoardMapper $boardMapper,
		private CardAssigneeMapper $cardAssigneeMapper,
		private ChangeNotifier $changeNotifier,
		private PermissionService $permissionService,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
	) {
	}

	/**
	 * Shares the board with a user or group. READ is always included in the
	 * stored mask — a share nobody can see is never valid.
	 *
	 * @param string $participantType 'user' or 'group'
	 * @param int $permission bitmask of PermissionService::PERMISSION_* bits
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not share the board, or
	 *                               lacks MANAGE and tries to grant bits beyond their own
	 * @throws InvalidInputException on unknown permission bits, an invalid
	 *                               participant type, the board owner or a nonexistent participant,
	 *                               or a participant the board is already shared with
	 */
	public function create(int $boardId, string $participant, string $participantType, int $permission, string $actorUid): Acl {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_SHARE);

		$permission = $this->validateMask($permission);
		$type = $this->parseParticipantType($participantType);

		if ($type === Acl::TYPE_USER && $participant === $board->getOwner()) {
			throw new InvalidInputException('Cannot share a board with its owner');
		}
		if ($type === Acl::TYPE_USER && !$this->userManager->userExists($participant)) {
			throw new InvalidInputException('User does not exist');
		}
		if ($type === Acl::TYPE_GROUP && !$this->groupManager->groupExists($participant)) {
			throw new InvalidInputException('Group does not exist');
		}

		$this->assertNoEscalation($board, $actorUid, $permission);

		if ($this->aclMapper->findByParticipant($boardId, $type, $participant) !== null) {
			throw new InvalidInputException('Already shared with this participant');
		}

		$acl = new Acl();
		$acl->setBoardId($boardId);
		$acl->setParticipantType($type);
		$acl->setParticipant($participant);
		$acl->setPermission($permission);

		try {
			$acl = $this->aclMapper->insert($acl);
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				// Concurrent POST lost the check-then-insert race — unlike the
				// idempotent assignment PUTs, POST reports the duplicate.
				throw new InvalidInputException('Already shared with this participant');
			}
			throw $e;
		}

		$this->changeNotifier->notify(
			$boardId,
			Change::ENTITY_ACL,
			$acl->getId(),
			Change::ACTION_CREATE,
			$actorUid
		);

		return $acl;
	}

	/**
	 * Replaces the permission mask of a sharing rule. The escalation cap
	 * applies to the CHANGED bits only, so an actor without MANAGE may
	 * re-submit an existing mask untouched even when it contains bits they
	 * do not hold — they just cannot flip such bits.
	 *
	 * @param int $permission bitmask of PermissionService::PERMISSION_* bits
	 * @throws DoesNotExistException if the board or the sharing rule does not exist
	 * @throws NotPermittedException if the actor may not share the board, or
	 *                               lacks MANAGE and flips bits beyond their own
	 * @throws InvalidInputException on unknown permission bits or a rule of another board
	 */
	public function update(int $boardId, int $aclId, int $permission, string $actorUid): Acl {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_SHARE);

		$acl = $this->loadAcl($boardId, $aclId);
		$permission = $this->validateMask($permission);
		$this->assertNoEscalation($board, $actorUid, $permission ^ $acl->getPermission());

		$acl->setPermission($permission);
		$acl = $this->aclMapper->update($acl);

		$this->changeNotifier->notify(
			$boardId,
			Change::ENTITY_ACL,
			$aclId,
			Change::ACTION_UPDATE,
			$actorUid
		);

		return $acl;
	}

	/**
	 * Revokes a sharing rule. Needs MANAGE, with one exception: a user may
	 * always remove their own user-type rule (leaving a board they were
	 * shared on). Afterwards, if a removed user no longer holds READ through
	 * any remaining path, their card assignments on the board are cleaned up
	 * — the board payload could never resolve them again.
	 *
	 * @throws DoesNotExistException if the board or the sharing rule does not exist
	 * @throws NotPermittedException if the actor may not manage the board (and it is not self-removal)
	 * @throws InvalidInputException on a rule of another board
	 */
	public function delete(int $boardId, int $aclId, string $actorUid): void {
		$board = $this->loadBoard($boardId);
		$acl = $this->loadAcl($boardId, $aclId);

		$selfRemoval = $acl->getParticipantType() === Acl::TYPE_USER
			&& $acl->getParticipant() === $actorUid;
		if (!$selfRemoval) {
			$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);
		}

		$this->aclMapper->delete($acl);

		$this->changeNotifier->notify(
			$boardId,
			Change::ENTITY_ACL,
			$aclId,
			Change::ACTION_DELETE,
			$actorUid
		);

		if ($acl->getParticipantType() === Acl::TYPE_USER) {
			// Recompute AFTER the delete: the user may retain READ through a
			// group rule, in which case their assignments stay valid.
			$remaining = $this->permissionService->getPermissions($board, $acl->getParticipant());
			if (($remaining & PermissionService::PERMISSION_READ) === 0) {
				$this->cardAssigneeMapper->deleteByBoardAndUser($boardId, $acl->getParticipant());
			}
		}
		// Group rules skip the cleanup: expanding the membership and
		// recomputing every member's access here is unbounded work, and
		// members may retain access through other paths. Stale assignees
		// from group unshares are deferred — Backlog #3393.
	}

	/**
	 * Share-dialog search: users and groups matching $q that the board is
	 * not already shared with. The owner is excluded too — sharing with
	 * them is rejected by create().
	 *
	 * @return list<array{id: string, displayName: string, type: string}>
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not share the board
	 */
	public function search(int $boardId, string $q, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_SHARE);

		$excludedUsers = [$board->getOwner() => true];
		$excludedGroups = [];
		foreach ($this->aclMapper->findByBoard($boardId) as $acl) {
			if ($acl->getParticipantType() === Acl::TYPE_USER) {
				$excludedUsers[$acl->getParticipant()] = true;
			} else {
				$excludedGroups[$acl->getParticipant()] = true;
			}
		}

		$results = [];
		foreach ($this->userManager->searchDisplayName($q, self::SEARCH_LIMIT) as $user) {
			if (isset($excludedUsers[$user->getUID()])) {
				continue;
			}
			$results[] = [
				'id' => $user->getUID(),
				'displayName' => $user->getDisplayName(),
				'type' => 'user',
			];
		}
		foreach ($this->groupManager->search($q, self::SEARCH_LIMIT) as $group) {
			if (isset($excludedGroups[$group->getGID()])) {
				continue;
			}
			$displayName = $group->getDisplayName();
			$results[] = [
				'id' => $group->getGID(),
				'displayName' => $displayName === '' ? $group->getGID() : $displayName,
				'type' => 'group',
			];
		}
		return $results;
	}

	/**
	 * Validates that the mask uses only known PERMISSION_* bits and forces
	 * READ into it.
	 *
	 * @throws InvalidInputException on bits outside PERMISSION_ALL
	 */
	private function validateMask(int $mask): int {
		if (($mask & ~PermissionService::PERMISSION_ALL) !== 0) {
			throw new InvalidInputException('Unknown permission bits');
		}
		return $mask | PermissionService::PERMISSION_READ;
	}

	/**
	 * @throws InvalidInputException on anything but 'user' or 'group'
	 */
	private function parseParticipantType(string $participantType): int {
		return match ($participantType) {
			'user' => Acl::TYPE_USER,
			'group' => Acl::TYPE_GROUP,
			default => throw new InvalidInputException("Participant type must be 'user' or 'group'"),
		};
	}

	/**
	 * The escalation cap: an actor without MANAGE may only hand out (or
	 * flip, for updates — pass the XOR of old and new mask) bits they hold
	 * themselves.
	 *
	 * @param int $bits the granted bits (create) or the changed bits (update)
	 * @throws NotPermittedException
	 */
	private function assertNoEscalation(Board $board, string $actorUid, int $bits): void {
		$actorBits = $this->permissionService->getPermissions($board, $actorUid);
		if (($actorBits & PermissionService::PERMISSION_MANAGE) === 0
			&& ($bits & ~$actorBits) !== 0) {
			throw new NotPermittedException('Cannot grant permissions beyond your own');
		}
	}

	/**
	 * @throws DoesNotExistException if the sharing rule does not exist
	 * @throws InvalidInputException if it belongs to another board
	 */
	private function loadAcl(int $boardId, int $aclId): Acl {
		$acl = $this->aclMapper->find($aclId);
		if ($acl->getBoardId() !== $boardId) {
			throw new InvalidInputException('ACL entry does not belong to this board');
		}
		return $acl;
	}

	/**
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 */
	private function loadBoard(int $id): Board {
		$board = $this->boardMapper->find($id);
		if ($board->getDeletedAt() > 0) {
			throw new DoesNotExistException('Board ' . $id . ' is deleted');
		}
		return $board;
	}
}
