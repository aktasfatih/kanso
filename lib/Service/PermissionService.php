<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\ViewerContext;
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
 *
 * Role cap (#3744): SHARE and MANAGE are internal-side concepts. A member
 * whose EFFECTIVE role folds to 'external' (every matching ACL row is
 * external - mixed grants fold internal-wins, mirroring
 * {@see \OCA\Kanso\Access\BoardAccess}) has those bits STRIPPED from the
 * effective mask, no matter what their rows store. Without this, an
 * external row carrying the MANAGE bit would pass every MANAGE assert
 * (board settings, ACL edits) and an external with SHARE could add new
 * INTERNAL members - a visibility escalation. Stripping here, at the
 * single mask fold, closes that for every assertPermission() caller and
 * for the permission bitmask the board payloads hand the frontend.
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

	/** The bits an external-role member can never hold effectively. */
	public const INTERNAL_ONLY_PERMISSIONS = self::PERMISSION_SHARE | self::PERMISSION_MANAGE;

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
		$sawInternal = false;
		$groupIds = null;
		foreach ($this->aclMapper->findByBoard($board->getId()) as $acl) {
			$applies = false;
			if ($acl->getParticipantType() === Acl::TYPE_USER) {
				$applies = $acl->getParticipant() === $uid;
			} elseif ($acl->getParticipantType() === Acl::TYPE_GROUP) {
				$groupIds ??= $this->getUserGroupIds($uid);
				$applies = in_array($acl->getParticipant(), $groupIds, true);
			}
			if ($applies) {
				$permissions |= $acl->getPermission();
				// Null stored role reads as 'internal' (migration backfill),
				// same as BoardAccess::foldRole.
				$sawInternal = $sawInternal
					|| ($acl->getRole() ?? ViewerContext::ROLE_INTERNAL) === ViewerContext::ROLE_INTERNAL;
			}
		}
		// Effective role folds internal-wins; a purely-external membership
		// can never hold the internal-only bits (see class doc).
		if ($permissions !== 0 && !$sawInternal) {
			$permissions &= ~self::INTERNAL_ONLY_PERMISSIONS;
		}
		return $permissions;
	}

	/**
	 * Effective permission bitmask of the user on MANY boards at once, backed
	 * by ONE batched ACL fetch over the whole set ({@see AclMapper::findByBoards()})
	 * — never a per-board query. Boards the user owns short-circuit to
	 * PERMISSION_ALL without touching the ACL table.
	 *
	 * @param Board[] $boards
	 * @return array<int, int> board id => permission bitmask
	 */
	public function getPermissionsForBoards(array $boards, string $uid): array {
		$map = [];
		$sharedIds = [];
		foreach ($boards as $board) {
			if ($board->getOwner() === $uid) {
				$map[$board->getId()] = self::PERMISSION_ALL;
			} else {
				$map[$board->getId()] = 0;
				$sharedIds[] = $board->getId();
			}
		}
		if ($sharedIds === []) {
			return $map;
		}

		$groupIds = null;
		/** @var array<int, bool> $sawInternal per shared board: any matching internal row */
		$sawInternal = [];
		foreach ($this->aclMapper->findByBoards($sharedIds) as $acl) {
			$applies = false;
			if ($acl->getParticipantType() === Acl::TYPE_USER) {
				$applies = $acl->getParticipant() === $uid;
			} elseif ($acl->getParticipantType() === Acl::TYPE_GROUP) {
				$groupIds ??= $this->getUserGroupIds($uid);
				$applies = in_array($acl->getParticipant(), $groupIds, true);
			}
			if ($applies) {
				$boardId = $acl->getBoardId();
				$map[$boardId] |= $acl->getPermission();
				$sawInternal[$boardId] = ($sawInternal[$boardId] ?? false)
					|| ($acl->getRole() ?? ViewerContext::ROLE_INTERNAL) === ViewerContext::ROLE_INTERNAL;
			}
		}
		// Same role cap as getPermissions(): a purely-external membership
		// never holds SHARE/MANAGE effectively (see class doc).
		foreach ($sharedIds as $boardId) {
			if ($map[$boardId] !== 0 && !($sawInternal[$boardId] ?? false)) {
				$map[$boardId] &= ~self::INTERNAL_ONLY_PERMISSIONS;
			}
		}
		return $map;
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
