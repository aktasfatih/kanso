<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\AclMapper;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Resolves who can participate on a board: the owner plus everyone the ACL
 * grants access to, with group rows expanded to their members. This is the
 * assignee-picker data source — a read-only view, kept out of BoardService
 * so the user/group directory dependencies stay local to this one concern.
 */
class ParticipantService {
	public function __construct(
		private BoardMapper $boardMapper,
		private AclMapper $aclMapper,
		private PermissionService $permissionService,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
	) {
	}

	/**
	 * All users with access to the board (owner, user ACLs, members of group
	 * ACLs), deduplicated by uid and sorted by display name. Unresolvable
	 * uids fall back to the uid as display name rather than disappearing —
	 * ACL rows can outlive their users.
	 *
	 * @return list<array{uid: string, displayName: string}>
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the user may not read the board
	 */
	public function getParticipants(int $boardId, string $uid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_READ);

		// Keyed by uid for deduplication; a user reachable both directly and
		// through a group appears once.
		$byUid = [];
		$byUid[$board->getOwner()] = $this->resolve($board->getOwner());

		foreach ($this->aclMapper->findByBoard($boardId) as $acl) {
			if ($acl->getParticipantType() === Acl::TYPE_USER) {
				$byUid[$acl->getParticipant()] ??= $this->resolve($acl->getParticipant());
			} elseif ($acl->getParticipantType() === Acl::TYPE_GROUP) {
				foreach ($this->groupManager->get($acl->getParticipant())?->getUsers() ?? [] as $user) {
					$byUid[$user->getUID()] ??= [
						'uid' => $user->getUID(),
						'displayName' => $user->getDisplayName(),
					];
				}
			}
		}

		$participants = array_values($byUid);
		usort(
			$participants,
			static fn (array $a, array $b): int
				=> strcasecmp($a['displayName'], $b['displayName'])
					?: strcmp($a['uid'], $b['uid'])
		);
		return $participants;
	}

	/**
	 * @return array{uid: string, displayName: string}
	 */
	private function resolve(string $uid): array {
		return [
			'uid' => $uid,
			'displayName' => $this->userManager->get($uid)?->getDisplayName() ?? $uid,
		];
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
