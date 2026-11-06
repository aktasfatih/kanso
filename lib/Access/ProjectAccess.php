<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Access;

use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\Project;
use OCA\Kanso\Db\ProjectShare;
use OCA\Kanso\Db\ProjectShareMapper;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Who may reach a project. The owner holds every bit; everyone else folds the
 * `kanso_project_shares` rows addressing them or one of their groups.
 *
 * This gates the project record only. Its cards stay filtered per viewer by
 * their boards' ACL and card visibility, so two members can see different cards.
 */
class ProjectAccess {
	public function __construct(
		private IDBConnection $db,
		private ProjectShareMapper $shareMapper,
		private PermissionService $permissionService,
	) {
	}

	/**
	 * @param int $permission bitmask of PermissionService::PERMISSION_* bits
	 * @throws DoesNotExistException if the actor cannot reach the project at all
	 * @throws NotPermittedException if any required bit is missing
	 */
	public function assertPermission(Project $project, string $uid, int $permission): void {
		$held = $this->assertReachable($project, $uid);
		if (($held & $permission) !== $permission) {
			throw new NotPermittedException('Operation not allowed on this project');
		}
	}

	/**
	 * @throws DoesNotExistException if the actor cannot reach the project at all
	 * @throws NotPermittedException if the actor is a recipient, not the owner
	 */
	public function assertOwner(Project $project, string $uid): void {
		if ($project->getOwner() !== $uid) {
			$this->assertReachable($project, $uid);
			throw new NotPermittedException('Only the owner may act on this project');
		}
	}

	/**
	 * Strangers get the same answer as for a missing id, so sequential ids
	 * cannot be probed for existence.
	 *
	 * @throws DoesNotExistException
	 */
	private function assertReachable(Project $project, string $uid): int {
		$held = $this->getPermissions($project, $uid);
		if ($held === 0) {
			throw new DoesNotExistException('Project ' . $project->getId() . ' not found');
		}
		return $held;
	}

	public function getPermissions(Project $project, string $uid): int {
		if ($project->getOwner() === $uid) {
			return PermissionService::PERMISSION_ALL;
		}
		return $this->foldPermissions($this->shareMapper->findByProject($project->getId()), $uid);
	}

	/**
	 * @param ProjectShare[] $shares
	 */
	public function foldPermissions(array $shares, string $uid): int {
		$permissions = 0;
		$groupIds = null;
		foreach ($shares as $share) {
			if ($share->getParticipantType() === Acl::TYPE_GROUP) {
				$groupIds ??= $this->permissionService->getUserGroupIds($uid);
				$applies = in_array($share->getParticipant(), $groupIds, true);
			} else {
				$applies = $share->getParticipant() === $uid;
			}
			if ($applies) {
				$permissions |= $share->getPermission();
			}
		}
		return $permissions;
	}

	/**
	 * Restricts a query to the projects $uid owns or has been shared. A subquery
	 * rather than a JOIN: a user can match several share rows through groups,
	 * which would duplicate the selected rows.
	 *
	 * @param string $projectAlias alias of `kanso_projects`, '' when unaliased
	 */
	public function restrict(IQueryBuilder $qb, string $projectAlias, string $uid): void {
		$prefix = $projectAlias === '' ? '' : $projectAlias . '.';

		$sub = $this->db->getQueryBuilder();
		$branches = [$sub->expr()->andX(
			$sub->expr()->eq('s.participant_type', $qb->createNamedParameter(Acl::TYPE_USER, IQueryBuilder::PARAM_INT)),
			$sub->expr()->eq('s.participant', $qb->createNamedParameter($uid)),
		)];
		$groupIds = $this->permissionService->getUserGroupIds($uid);
		if ($groupIds !== []) {
			$branches[] = $sub->expr()->andX(
				$sub->expr()->eq('s.participant_type', $qb->createNamedParameter(Acl::TYPE_GROUP, IQueryBuilder::PARAM_INT)),
				$sub->expr()->in('s.participant', $qb->createNamedParameter(array_values($groupIds), IQueryBuilder::PARAM_STR_ARRAY)),
			);
		}
		$sub->select('s.project_id')
			->from('kanso_project_shares', 's')
			->where($sub->expr()->orX(...$branches));

		$qb->andWhere($qb->expr()->orX(
			$qb->expr()->eq($prefix . 'owner', $qb->createNamedParameter($uid)),
			$qb->expr()->in($prefix . 'id', $qb->createFunction($sub->getSQL())),
		));
	}
}
