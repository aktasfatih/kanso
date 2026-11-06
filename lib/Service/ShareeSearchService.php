<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Acl;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;

/**
 * Who a user may share with, under the instance sharing settings: the picker
 * search, and the check every share goes through whether or not it came from
 * the picker. Follows core's UserPlugin, GroupPlugin and Share Manager.
 * Email full-match backends are out of scope for a share picker.
 */
class ShareeSearchService {
	private const SEARCH_LIMIT = 25;
	private const MIN_QUERY_LENGTH = 2;

	public function __construct(
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IShareManager $shareManager,
		private IAppConfig $appConfig,
	) {
	}

	public function isSearchable(string $q): bool {
		return mb_strlen(trim($q)) >= self::MIN_QUERY_LENGTH;
	}

	/**
	 * The canonical id to store for a share target. The single error does not
	 * distinguish a missing target from a forbidden one.
	 *
	 * @param int $type Acl::TYPE_USER or Acl::TYPE_GROUP
	 * @throws InvalidInputException if the actor may not share with the target
	 */
	public function resolveParticipant(int $type, string $participant, string $actorUid): string {
		$resolved = $this->canShare($actorUid)
			? ($type === Acl::TYPE_GROUP ? $this->resolveGroup($participant, $actorUid) : $this->resolveUser($participant, $actorUid))
			: null;
		if ($resolved === null) {
			throw new InvalidInputException('Cannot share with this participant');
		}
		return $resolved;
	}

	private function canShare(string $actorUid): bool {
		return $this->shareManager->shareApiEnabled() && !$this->shareManager->sharingDisabledForUser($actorUid);
	}

	private function resolveUser(string $participant, string $actorUid): ?string {
		$user = $this->userManager->get($participant);
		if ($user === null) {
			return null;
		}
		if ($this->shareManager->shareWithGroupMembersOnly() && !$this->sharesGroup($user, $this->actorGroupSet($actorUid, true))) {
			return null;
		}
		return $user->getUID();
	}

	private function resolveGroup(string $participant, string $actorUid): ?string {
		if (!$this->shareManager->allowGroupSharing()) {
			return null;
		}
		$group = $this->groupManager->get($participant);
		if ($group === null) {
			return null;
		}
		$gid = $group->getGID();
		if ($this->shareManager->shareWithGroupMembersOnly() && !isset($this->actorGroupSet($actorUid, true)[$gid])) {
			return null;
		}
		return $gid;
	}

	/**
	 * @param array<string, true> $excludedUsers uids that cannot be picked
	 * @param array<string, true> $excludedGroups gids that cannot be picked
	 * @return list<array{id: string, displayName: string, type: string}>
	 */
	public function search(string $q, string $actorUid, array $excludedUsers, array $excludedGroups): array {
		if (!$this->isSearchable($q) || !$this->canShare($actorUid)) {
			return [];
		}

		$q = trim($q);
		return array_merge(
			$this->searchUsers($q, $actorUid, $excludedUsers),
			$this->searchGroups($q, $actorUid, $excludedGroups),
		);
	}

	/**
	 * Exact matches first, then wide hits, each in display-name order. As in
	 * core, the group and phonebook limits narrow wide hits only and either one
	 * admits a user, while shareWithGroupMembersOnly filters every hit.
	 *
	 * @param array<string, true> $excludedUsers uids already shared / the owner
	 * @return list<array{id: string, displayName: string, type: string}>
	 */
	private function searchUsers(string $q, string $actorUid, array $excludedUsers): array {
		$enumerationAllowed = $this->shareManager->allowEnumeration();
		$fullMatch = $this->shareManager->allowEnumerationFullMatch();
		if (!$enumerationAllowed && !$fullMatch) {
			return [];
		}

		$limitToGroups = $enumerationAllowed && $this->shareManager->limitEnumerationToGroups();
		$limitToPhone = $enumerationAllowed && $this->shareManager->limitEnumerationToPhone();
		$unlimited = $enumerationAllowed && !$limitToGroups && !$limitToPhone;
		$matchDisplayName = $fullMatch && $this->fullMatchEnabled('shareapi_restrict_user_enumeration_full_match_displayname');
		$byDisplayName = $unlimited || $matchDisplayName ? $this->userManager->searchDisplayName($q, self::SEARCH_LIMIT) : [];

		$wide = [];
		if ($unlimited) {
			$wide = $this->indexByUid($byDisplayName);
		}
		if ($limitToGroups) {
			$wide += $this->indexByUid($this->groupMatesMatching($q, $actorUid));
		}
		if ($limitToPhone) {
			$wide += $this->indexByUid($this->userManager->searchKnownUsersByDisplayName($actorUid, $q, self::SEARCH_LIMIT));
		}

		$lowerQ = mb_strtolower($q);
		$exact = [];
		if ($matchDisplayName) {
			$exact = $this->indexByUid(array_filter(
				$byDisplayName,
				static fn (IUser $user): bool => mb_strtolower($user->getDisplayName()) === $lowerQ,
			));
		}
		if ($fullMatch && $this->fullMatchEnabled('shareapi_restrict_user_enumeration_full_match_user_id')) {
			$user = $this->userManager->get($q);
			// Backends may also resolve a login name or email here.
			if ($user !== null && mb_strtolower($user->getUID()) === $lowerQ) {
				$exact[$user->getUID()] = $user;
			}
		}

		$byName = static fn (IUser $a, IUser $b): int => strcasecmp($a->getDisplayName(), $b->getDisplayName());
		uasort($exact, $byName);
		uasort($wide, $byName);

		$membersOnlyGroups = $this->shareManager->shareWithGroupMembersOnly() ? $this->actorGroupSet($actorUid, true) : null;
		$results = [];
		foreach ($exact + $wide as $user) {
			$uid = $user->getUID();
			if (isset($excludedUsers[$uid]) || !$user->isEnabled()) {
				continue;
			}
			if ($membersOnlyGroups !== null && !$this->sharesGroup($user, $membersOnlyGroups)) {
				continue;
			}
			$results[] = [
				'id' => $uid,
				'displayName' => $user->getDisplayName(),
				'type' => 'user',
			];
			if (count($results) === self::SEARCH_LIMIT) {
				break;
			}
		}
		return $results;
	}

	private function fullMatchEnabled(string $key): bool {
		return $this->appConfig->getValueString('core', $key, 'yes') === 'yes';
	}

	/**
	 * @param iterable<IUser> $users
	 * @return array<string, IUser>
	 */
	private function indexByUid(iterable $users): array {
		$indexed = [];
		foreach ($users as $user) {
			$indexed[$user->getUID()] = $user;
		}
		return $indexed;
	}

	/**
	 * @return list<IUser>
	 */
	private function groupMatesMatching(string $q, string $actorUid): array {
		$mates = [];
		foreach (array_keys($this->actorGroupSet($actorUid, true)) as $gid) {
			foreach (array_keys($this->groupManager->displayNamesInGroup((string)$gid, $q, self::SEARCH_LIMIT)) as $uid) {
				$user = $this->userManager->get((string)$uid);
				if ($user !== null) {
					$mates[] = $user;
				}
			}
		}
		return $mates;
	}

	/**
	 * The actor's group ids as a lookup set. When $applyExcludeList is set the
	 * shareWithGroupMembersOnly exclude-group list is removed, matching core's
	 * treatment of that restriction.
	 *
	 * @return array<string, true>
	 */
	private function actorGroupSet(string $actorUid, bool $applyExcludeList): array {
		$actor = $this->userManager->get($actorUid);
		$groups = $actor !== null ? $this->groupManager->getUserGroupIds($actor) : [];
		if ($applyExcludeList) {
			$groups = array_diff($groups, $this->shareManager->shareWithGroupMembersOnlyExcludeGroupsList());
		}
		return array_fill_keys($groups, true);
	}

	/**
	 * @param array<string, true> $excludedGroups gids already shared
	 * @return list<array{id: string, displayName: string, type: string}>
	 */
	private function searchGroups(string $q, string $actorUid, array $excludedGroups): array {
		if (!$this->shareManager->allowGroupSharing()) {
			return [];
		}

		$enumerationAllowed = $this->shareManager->allowEnumeration();
		$membersOnly = $this->shareManager->shareWithGroupMembersOnly();
		$restrictToActorGroups = $membersOnly || $this->shareManager->limitEnumerationToGroups();
		$allowedGroups = $restrictToActorGroups ? $this->actorGroupSet($actorUid, $membersOnly) : null;

		$lowerQ = mb_strtolower($q);
		$results = [];
		foreach ($this->groupManager->search($q, self::SEARCH_LIMIT) as $group) {
			$gid = $group->getGID();
			if (isset($excludedGroups[$gid]) || $group->hideFromCollaboration()) {
				continue;
			}
			if ($allowedGroups !== null && !isset($allowedGroups[$gid])) {
				continue;
			}
			$displayName = $group->getDisplayName();
			$isExact = mb_strtolower($gid) === $lowerQ || mb_strtolower($displayName) === $lowerQ;
			if (!$isExact && !$enumerationAllowed) {
				continue;
			}
			$results[] = [
				'id' => $gid,
				'displayName' => $displayName === '' ? $gid : $displayName,
				'type' => 'group',
			];
		}
		return $results;
	}

	/**
	 * Whether $user is a member of any group in the allowed set.
	 *
	 * @param array<string, true> $allowedGroups
	 */
	private function sharesGroup(IUser $user, array $allowedGroups): bool {
		foreach ($this->groupManager->getUserGroupIds($user) as $gid) {
			if (isset($allowedGroups[$gid])) {
				return true;
			}
		}
		return false;
	}
}
