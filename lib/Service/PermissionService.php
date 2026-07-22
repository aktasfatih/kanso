<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\AclMapper;
use OCA\Kanso\Db\Board;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Board-level permission checks.
 *
 * The board owner holds every permission bit; other users accumulate bits
 * from `kanso_board_acl` rows addressing them directly or one of their
 * groups. Bit order matches Deck for familiarity.
 */
class PermissionService {
	public const PERMISSION_READ = 1;
	public const PERMISSION_EDIT = 2;
	public const PERMISSION_SHARE = 4;
	public const PERMISSION_MANAGE = 8;

	public const PERMISSION_ALL = self::PERMISSION_READ
		| self::PERMISSION_EDIT
		| self::PERMISSION_SHARE
		| self::PERMISSION_MANAGE;

	public function __construct(
		private AclMapper $aclMapper,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
	) {
	}

	/**
	 * Asserts that the user holds every bit of $permission on the board.
	 *
	 * @param int $permission bitmask of PERMISSION_* bits
	 * @throws NotPermittedException if any required bit is missing
	 */
	public function assertPermission(Board $board, string $uid, int $permission): void {
		if (($this->getPermissions($board, $uid) & $permission) !== $permission) {
			throw new NotPermittedException('Operation not allowed on this board');
		}
	}

	/**
	 * Effective permission bitmask of the user on the board.
	 */
	public function getPermissions(Board $board, string $uid): int {
		if ($board->getOwner() === $uid) {
			return self::PERMISSION_ALL;
		}

		$permissions = 0;
		$groupIds = null;
		foreach ($this->aclMapper->findByBoard($board->getId()) as $acl) {
			if ($acl->getParticipantType() === Acl::TYPE_USER) {
				if ($acl->getParticipant() === $uid) {
					$permissions |= $acl->getPermission();
				}
			} elseif ($acl->getParticipantType() === Acl::TYPE_GROUP) {
				$groupIds ??= $this->getUserGroupIds($uid);
				if (in_array($acl->getParticipant(), $groupIds, true)) {
					$permissions |= $acl->getPermission();
				}
			}
		}
		return $permissions;
	}

	/**
	 * Group ids of the given user, [] for unknown users.
	 *
	 * @return string[]
	 */
	public function getUserGroupIds(string $uid): array {
		$user = $this->userManager->get($uid);
		if ($user === null) {
			return [];
		}
		return $this->groupManager->getUserGroupIds($user);
	}
}
