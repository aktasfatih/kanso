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
 * assignee-picker data source - a read-only view, kept out of BoardService
 * so the user/group directory dependencies stay local to this one concern.
 */
class ParticipantService {
	/**
	 * Result cap for the participants payload. Mirrors AclService::SEARCH_LIMIT
	 * so both member-facing pickers bound their payload the same way - a board
	 * shared with a several-thousand-member group must never serialize every
	 * member into the assignee picker.
	 */
	private const RESULT_LIMIT = 25;

	public function __construct(
		private BoardMapper $boardMapper,
		private AclMapper $aclMapper,
		private PermissionService $permissionService,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
	) {
	}

	/**
	 * Users with access to the board (owner, user ACLs, members of group ACLs),
	 * deduplicated by uid, filtered by an optional query, and capped. Unresolvable
	 * uids fall back to the uid as display name rather than disappearing -
	 * ACL rows can outlive their users.
	 *
	 * The payload is bounded so a board shared with a very large group cannot
	 * balloon the assignee picker. Directly-reachable members (the owner and
	 * every user-type ACL) are selected AHEAD of group-expanded members, so the
	 * cap only ever sheds people reachable purely through a large group. That
	 * ordering matters because the frontend consumes this same list as its
	 * uid->displayName resolution map (assignees, reviewers, swimlane titles):
	 * a directly-shared member always survives the cap and resolves to a real
	 * name; only a group-only member past the cap degrades to a bare-uid label.
	 *
	 * When $q is given, both tiers are filtered server-side (case-insensitive
	 * substring match against display name or uid) before the cap is applied.
	 *
	 * @param string|null $q optional case-insensitive filter over display name / uid
	 * @return list<array{uid: string, displayName: string}>
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the user may not read the board
	 */
	public function getParticipants(int $boardId, string $uid, ?string $q = null): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_READ);

		$needle = $q !== null ? mb_strtolower(trim($q)) : '';

		// Directly-reachable members first (owner + user ACLs). These are the
		// ones the cap must never shed, so they are gathered as their own tier.
		// $seen dedups across both tiers - a user reachable both directly and
		// through a group appears once, and always from the direct tier.
		$seen = [];
		$direct = [];
		$this->collect($direct, $seen, $this->resolve($board->getOwner()), $needle);

		$groupAcls = [];
		foreach ($this->aclMapper->findByBoard($boardId) as $acl) {
			if ($acl->getParticipantType() === Acl::TYPE_USER) {
				$this->collect($direct, $seen, $this->resolve($acl->getParticipant()), $needle);
			} elseif ($acl->getParticipantType() === Acl::TYPE_GROUP) {
				$groupAcls[] = $acl->getParticipant();
			}
		}

		$selected = $this->sortByDisplayName($direct);
		if (count($selected) >= self::RESULT_LIMIT) {
			return array_slice($selected, 0, self::RESULT_LIMIT);
		}

		// Fill the remaining slots from group members, skipping anyone already
		// reachable directly (dedup). The group tier is sorted by display name
		// and sliced to the remaining budget, so a several-thousand-member group
		// contributes at most a handful of rows to the serialized payload.
		$group = [];
		foreach ($groupAcls as $gid) {
			foreach ($this->groupManager->get($gid)?->getUsers() ?? [] as $user) {
				$this->collect($group, $seen, [
					'uid' => $user->getUID(),
					'displayName' => $user->getDisplayName(),
				], $needle);
			}
		}

		$remaining = self::RESULT_LIMIT - count($selected);
		$group = array_slice($this->sortByDisplayName($group), 0, $remaining);

		return array_merge($selected, $group);
	}

	/**
	 * Deduplicates by uid (via the shared $seen set, so the first tier to reach
	 * a user wins - the direct tier before the group tier) and, for a first
	 * sighting, appends the participant to $bucket when it passes the optional
	 * $needle filter (case-insensitive substring over display name or uid). An
	 * empty needle passes everyone. A uid is marked seen on first sighting even
	 * when the filter rejects it, so it can never re-enter through another tier.
	 *
	 * @param list<array{uid: string, displayName: string}> $bucket
	 * @param array<string, true> $seen uids already accounted for
	 * @param array{uid: string, displayName: string} $participant
	 */
	private function collect(array &$bucket, array &$seen, array $participant, string $needle): void {
		if (isset($seen[$participant['uid']])) {
			return;
		}
		$seen[$participant['uid']] = true;
		if ($needle !== ''
			&& !str_contains(mb_strtolower($participant['displayName']), $needle)
			&& !str_contains(mb_strtolower($participant['uid']), $needle)) {
			return;
		}
		$bucket[] = $participant;
	}

	/**
	 * @param list<array{uid: string, displayName: string}> $participants
	 * @return list<array{uid: string, displayName: string}>
	 */
	private function sortByDisplayName(array $participants): array {
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
