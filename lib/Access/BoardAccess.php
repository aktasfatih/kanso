<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Access;

use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\AclMapper;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Service\PermissionService;
use OCP\IGroupManager;

/**
 * The effective-role resolver (#3742) - the single place that folds a
 * user's ACL rows into one (role, isManager) membership per board, and the
 * only class allowed to mint a {@see ViewerContext} (architecture-tested).
 *
 * The wrinkle: ACL entries can address GROUPS, so a user may match several
 * rows with different roles. The fold rule, in one place:
 *
 *   role      = INTERNAL if ANY matching row is internal, else EXTERNAL
 *               (internal-wins - mixed grants resolve to the wider side)
 *   perms     = bitwise OR of every matching row's permission mask
 *   isManager = perms carries MANAGE (ViewerContext strips it for externals)
 *
 * The board OWNER has no ACL row (PermissionService short-circuits them to
 * PERMISSION_ALL); here they resolve to an implicit (internal, manager)
 * membership. That is NOT a visibility backdoor: the owner's role feeds the
 * same symmetric visibility rules as anyone else's, so an owner still
 * cannot see external-side internal cards or other people's private cards.
 *
 * Deliberately NO caching: a cached role is the class of bug where a
 * revoked or flipped membership lingers - it shows up at the client, not
 * in tests. Batch via {@see self::rolesFor()} instead (mirrors
 * PermissionService::getPermissionsForBoards - ONE ACL fetch per board
 * set, never per-board queries).
 */
class BoardAccess {
	public function __construct(
		private AclMapper $aclMapper,
		private PermissionService $permissionService,
		private IGroupManager $groupManager,
	) {
	}

	/**
	 * Resolves the user's membership on ONE board, rejecting non-members
	 * BEFORE any card query can run.
	 *
	 * @throws NotAMemberException if the user neither owns the board nor
	 *                             matches any of its ACL rows
	 */
	public function contextFor(Board $board, string $uid): ViewerContext {
		if ($board->getOwner() === $uid) {
			return ViewerContext::forMember($uid, $board->getId(), ViewerContext::ROLE_INTERNAL, true);
		}

		$role = null;
		$perms = 0;
		$groupIds = null;
		foreach ($this->aclMapper->findByBoard($board->getId()) as $acl) {
			if (!$this->applies($acl, $uid, $groupIds)) {
				continue;
			}
			$role = $this->foldRole($role, $acl);
			$perms |= $acl->getPermission();
		}
		if ($role === null) {
			throw new NotAMemberException('User is not a member of this board');
		}

		return ViewerContext::forMember(
			$uid,
			$board->getId(),
			$role,
			($perms & PermissionService::PERMISSION_MANAGE) !== 0,
		);
	}

	/**
	 * Effective role of the user on MANY boards at once - the cross-board
	 * map that feeds the card-visibility scope (My Cards, Inbox) - backed
	 * by ONE batched ACL fetch over the whole set. Owned boards resolve to
	 * 'internal' without touching the ACL table; boards the user is no
	 * member of are simply absent from the map.
	 *
	 * @param Board[] $boards
	 * @return array<int, string> board id => ViewerContext::ROLE_* value
	 */
	public function rolesFor(array $boards, string $uid): array {
		$map = [];
		$sharedIds = [];
		foreach ($boards as $board) {
			if ($board->getOwner() === $uid) {
				$map[$board->getId()] = ViewerContext::ROLE_INTERNAL;
			} else {
				$sharedIds[] = $board->getId();
			}
		}
		if ($sharedIds === []) {
			return $map;
		}

		// Fold straight into the map: the fetched rows cover only the
		// non-owned ids, which are disjoint from the owner entries above.
		$groupIds = null;
		foreach ($this->aclMapper->findByBoards($sharedIds) as $acl) {
			if (!$this->applies($acl, $uid, $groupIds)) {
				continue;
			}
			$boardId = $acl->getBoardId();
			$map[$boardId] = $this->foldRole($map[$boardId] ?? null, $acl);
		}
		return $map;
	}

	/**
	 * Effective role of MANY users on ONE board at once - the audience-side
	 * counterpart of {@see self::rolesFor()} that feeds the background
	 * fan-outs (#3760: reminders, comment/mention notifications, activity
	 * audience). ONE ACL fetch for the board plus one member expansion per
	 * distinct group row - never a per-recipient query (these run in cron
	 * over whole boards). Same fold as {@see self::contextFor()}: the owner
	 * resolves to 'internal' without touching the ACL table, mixed grants
	 * fold internal-wins, and users with no matching row are simply absent
	 * from the map.
	 *
	 * @param string[] $uids
	 * @return array<string, string> uid => ViewerContext::ROLE_* value
	 */
	public function rolesOn(Board $board, array $uids): array {
		$map = [];
		$candidates = [];
		foreach (array_unique($uids) as $uid) {
			if ($board->getOwner() === $uid) {
				$map[$uid] = ViewerContext::ROLE_INTERNAL;
			} else {
				$candidates[$uid] = true;
			}
		}
		if ($candidates === []) {
			return $map;
		}

		/** @var array<string, array<string, bool>> $groupMembers gid => member uid set */
		$groupMembers = [];
		foreach ($this->aclMapper->findByBoard($board->getId()) as $acl) {
			if ($acl->getParticipantType() === Acl::TYPE_USER) {
				$uid = $acl->getParticipant();
				if (isset($candidates[$uid])) {
					$map[$uid] = $this->foldRole($map[$uid] ?? null, $acl);
				}
				continue;
			}
			if ($acl->getParticipantType() !== Acl::TYPE_GROUP) {
				continue;
			}
			// Group rows expand group -> members ONCE per distinct group (the
			// inverse direction of contextFor's lazy user -> groups lookup,
			// which would be a per-recipient query here).
			$gid = $acl->getParticipant();
			if (!isset($groupMembers[$gid])) {
				$members = [];
				foreach ($this->groupManager->get($gid)?->getUsers() ?? [] as $user) {
					$members[$user->getUID()] = true;
				}
				$groupMembers[$gid] = $members;
			}
			foreach (array_keys($candidates) as $uid) {
				if (isset($groupMembers[$gid][$uid])) {
					$map[$uid] = $this->foldRole($map[$uid] ?? null, $acl);
				}
			}
		}
		return $map;
	}

	/**
	 * Whether the ACL row addresses the user - directly, or via one of
	 * their groups (resolved lazily, once per call).
	 *
	 * @param string[]|null $groupIds lazily-filled group-id cache
	 */
	private function applies(Acl $acl, string $uid, ?array &$groupIds): bool {
		if ($acl->getParticipantType() === Acl::TYPE_USER) {
			return $acl->getParticipant() === $uid;
		}
		if ($acl->getParticipantType() === Acl::TYPE_GROUP) {
			$groupIds ??= $this->permissionService->getUserGroupIds($uid);
			return in_array($acl->getParticipant(), $groupIds, true);
		}
		return false;
	}

	/**
	 * Internal-wins fold of one more matching row into the accumulated
	 * role. A null stored role (rows predating hydration of the column)
	 * reads as 'internal', matching the migration backfill.
	 */
	private function foldRole(?string $acc, Acl $acl): string {
		$rowRole = $acl->getRole() ?? ViewerContext::ROLE_INTERNAL;
		if ($acc === ViewerContext::ROLE_INTERNAL || $rowRole === ViewerContext::ROLE_INTERNAL) {
			return ViewerContext::ROLE_INTERNAL;
		}
		return ViewerContext::ROLE_EXTERNAL;
	}
}
