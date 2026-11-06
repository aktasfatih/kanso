<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\ProjectAccess;
use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Project;
use OCA\Kanso\Db\ProjectCardMapper;
use OCA\Kanso\Db\ProjectCommentMapper;
use OCA\Kanso\Db\ProjectMapper;
use OCA\Kanso\Db\ProjectPinMapper;
use OCA\Kanso\Db\ProjectShare;
use OCA\Kanso\Db\ProjectShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Projects: cross-board card collections, shareable with users and
 * groups. {@see ProjectAccess} gates the project record; the cards inside stay
 * filtered to what each viewer can read on their boards, so sharing a project
 * never grants access to a card.
 *
 * Project state is NOT written to `kanso_changes`: what a viewer sees of it
 * differs per viewer, so board reads validate it through
 * {@see ProjectCardMapper::boardDigest()} instead.
 */
class ProjectService {
	private const MAX_TITLE_LENGTH = 255;
	public const MAX_DESCRIPTION_LENGTH = 65536;

	public function __construct(
		private ProjectMapper $projectMapper,
		private ProjectCardMapper $projectCardMapper,
		private BoardService $boardService,
		private PermissionService $permissionService,
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private StatsService $statsService,
		private BoardAccess $boardAccess,
		private CardVisibilityGuard $visibilityGuard,
		private CardAssigneeMapper $cardAssigneeMapper,
		private ProjectForecast $forecast,
		private ProjectPinMapper $projectPinMapper,
		private ProjectAccess $projectAccess,
		private ProjectShareMapper $shareMapper,
		private ProjectCommentMapper $commentMapper,
		private IUserManager $userManager,
		private IDBConnection $db,
		private IGroupManager $groupManager,
	) {
	}

	/**
	 * The projects the user owns or was shared, in title order.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function findMine(string $uid): array {
		$pinned = $this->projectPinMapper->pinnedMap($uid);
		$projects = $this->projectMapper->findAccessible($uid);

		$shares = [];
		foreach ($this->shareMapper->findByProjects(array_map(static fn (Project $project): int => $project->getId(), $projects)) as $share) {
			$shares[$share->getProjectId()][] = $share;
		}

		return array_values(array_map(
			fn (Project $project): array => $project->jsonSerialize()
				+ ['pinned' => isset($pinned[$project->getId()])]
				+ $this->accessFields($project, $shares[$project->getId()] ?? [], $uid),
			$projects
		));
	}

	/**
	 * @param ProjectShare[] $shares
	 * @return array{isOwner: bool, canEdit: bool, canLeave: bool, shared: bool, ownerDisplayName: string}
	 */
	private function accessFields(Project $project, array $shares, string $uid): array {
		$owner = (string)$project->getOwner();
		$isOwner = $owner === $uid;
		$permissions = $isOwner ? PermissionService::PERMISSION_ALL : $this->projectAccess->foldPermissions($shares, $uid);
		$hasOwnRow = false;
		foreach ($shares as $share) {
			$hasOwnRow = $hasOwnRow || ($share->getParticipantType() === Acl::TYPE_USER && $share->getParticipant() === $uid);
		}

		return [
			'isOwner' => $isOwner,
			'canEdit' => ($permissions & PermissionService::PERMISSION_EDIT) !== 0,
			// Group members cannot leave: only the owner can drop the group's rule.
			'canLeave' => $hasOwnRow,
			'shared' => $shares !== [],
			'ownerDisplayName' => $this->userManager->getDisplayName($owner) ?? $owner,
		];
	}

	/**
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor cannot read the project
	 */
	public function pin(int $id, string $uid): void {
		$project = $this->loadProject($id, $uid, PermissionService::PERMISSION_READ);
		$this->projectPinMapper->pin($uid, $project->getId());
	}

	/**
	 * Idempotent; removing one's own pin needs no access check.
	 */
	public function unpin(int $id, string $uid): void {
		$this->projectPinMapper->unpin($uid, $id);
	}

	/**
	 * Creates a project owned by $uid.
	 *
	 * @throws InvalidInputException on an empty or over-long title, an over-long description, or a malformed date
	 */
	public function create(string $uid, string $title, ?string $description, ?string $color, ?string $startDate = null, ?string $targetDate = null): Project {
		$title = trim($title);
		if ($title === '') {
			throw new InvalidInputException('Project title must not be empty');
		}
		if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
			throw new InvalidInputException('Project title is too long');
		}
		if ($description !== null) {
			$this->assertDescriptionLength($description);
		}

		$project = new Project();
		$project->setTitle($title);
		$project->setDescription($description);
		$project->setColor(ColorValidator::assertValid($color));
		$project->setStartDate($this->parseDate($startDate));
		$project->setTargetDate($this->parseDate($targetDate));
		$this->assertDateOrder($project);
		$project->setOwner($uid);
		$project->setCreatedAt(time());

		return $this->projectMapper->insert($project);
	}

	/**
	 * Updates the provided fields of a project.
	 *
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor cannot edit the project
	 * @throws InvalidInputException on an empty or over-long title, a changed description
	 *                               over the cap, or a malformed date
	 */
	public function update(int $id, string $uid, ?string $title, ?string $description, ?string $color, ?string $startDate = null, ?string $targetDate = null): Project {
		$project = $this->loadProject($id, $uid, PermissionService::PERMISSION_EDIT);

		if ($title !== null) {
			$title = trim($title);
			if ($title === '') {
				throw new InvalidInputException('Project title must not be empty');
			}
			if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
				throw new InvalidInputException('Project title is too long');
			}
			$project->setTitle($title);
		}
		// Unchanged text passes, so a row longer than the cap stays saveable.
		if ($description !== null && $description !== $project->getDescription()) {
			$this->assertDescriptionLength($description);
			$project->setDescription($description);
		}
		if ($color !== null) {
			// An empty string clears the color (matches BoardService/StackService).
			$project->setColor(ColorValidator::assertValid($color));
		}
		// An empty string clears a date; null leaves it untouched.
		if ($startDate !== null) {
			$project->setStartDate($this->parseDate($startDate));
		}
		if ($targetDate !== null) {
			$project->setTargetDate($this->parseDate($targetDate));
		}
		$this->assertDateOrder($project);

		return $this->projectMapper->update($project);
	}

	/**
	 * Deletes a project with its discussion, memberships and shares. Only the owner may act.
	 * The member cards themselves are untouched.
	 *
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor is not the owner
	 */
	public function delete(int $id, string $uid): void {
		$project = $this->projectMapper->find($id);
		$this->projectAccess->assertOwner($project, $uid);
		$this->purge($project);
	}

	/**
	 * Settles a project whose owner's account is gone: the longest-standing
	 * editor takes over, direct shares ahead of groups. With nobody left who can
	 * edit, the project is deleted; view-only recipients are never promoted.
	 *
	 * @throws \RuntimeException if nobody can take over and an editor could not
	 *                           be resolved, since a backend outage must not delete the project
	 */
	public function handOver(Project $project, string $formerOwner, bool $trustEmptyGroups = false): void {
		$successor = $this->findSuccessor($project, $formerOwner, $trustEmptyGroups);
		if ($successor === null) {
			$this->purge($project);
			return;
		}

		$this->db->beginTransaction();
		try {
			$ownShare = $this->shareMapper->findByParticipant($project->getId(), Acl::TYPE_USER, $successor);
			if ($ownShare !== null) {
				$this->shareMapper->delete($ownShare);
			}
			$project->setOwner($successor);
			$this->projectMapper->update($project);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	private function findSuccessor(Project $project, string $formerOwner, bool $trustEmptyGroups): ?string {
		$editors = array_filter(
			$this->shareMapper->findByProject($project->getId()),
			static fn (ProjectShare $share): bool => ($share->getPermission() & PermissionService::PERMISSION_EDIT) !== 0,
		);

		$unresolved = false;

		foreach ($editors as $share) {
			$uid = (string)$share->getParticipant();
			if ($share->getParticipantType() === Acl::TYPE_USER && $uid !== $formerOwner) {
				$user = $this->userManager->get($uid);
				if ($user === null) {
					$unresolved = true;
				} elseif ($user->isEnabled()) {
					return $user->getUID();
				}
			}
		}

		foreach ($editors as $share) {
			if ($share->getParticipantType() === Acl::TYPE_GROUP) {
				$group = $this->groupManager->get((string)$share->getParticipant());
				$users = $group?->getUsers() ?? [];
				// A backend outage looks like an empty group, and purging cannot be undone.
				if ($group === null || ($users === [] && !$trustEmptyGroups)) {
					$unresolved = true;
					continue;
				}
				$members = [];
				foreach ($users as $user) {
					if ($user->isEnabled() && $user->getUID() !== $formerOwner) {
						$members[] = $user->getUID();
					}
				}
				sort($members);
				if ($members !== []) {
					return $members[0];
				}
			}
		}

		if ($unresolved) {
			throw new \RuntimeException('Project ' . $project->getId() . ' has an editor that could not be resolved');
		}

		return null;
	}

	private function purge(Project $project): void {
		$this->db->beginTransaction();
		try {
			$this->commentMapper->deleteByProject($project->getId());
			$this->projectCardMapper->deleteByProject($project->getId());
			$this->projectPinMapper->deleteByProject($project->getId());
			$this->shareMapper->deleteByProject($project->getId());
			$this->projectMapper->delete($project);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Adds a card to a project. The actor must be able to edit the project and
	 * see the card. Idempotent per (project, card).
	 *
	 * @throws DoesNotExistException if the project, card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor cannot edit the project or see the card
	 */
	public function addCard(int $projectId, int $cardId, string $uid): void {
		$project = $this->loadProject($projectId, $uid, PermissionService::PERMISSION_EDIT);
		$card = $this->loadCard($cardId);
		$this->assertCardReadable($card, $uid);

		$this->projectCardMapper->add($project->getId(), $card->getId());
	}

	/**
	 * Removes a card from a project. Idempotent. Members must be able to see the
	 * card; the owner need not, so they can clear out a card they lost access to.
	 *
	 * @throws DoesNotExistException if the project does not exist, or a member names a missing card
	 * @throws NotPermittedException if the actor cannot edit the project, or a member cannot see the card
	 */
	public function removeCard(int $projectId, int $cardId, string $uid): void {
		$project = $this->loadProject($projectId, $uid, PermissionService::PERMISSION_EDIT);
		if ($project->getOwner() !== $uid) {
			$this->assertCardReadable($this->loadCard($cardId), $uid);
		}
		$this->projectCardMapper->remove($project->getId(), $cardId);
	}

	/**
	 * The project's cards, filtered to the ones the viewer can see on their
	 * readable boards.
	 *
	 * @return list<array<string, mixed>>
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor cannot read the project
	 */
	public function listCards(int $projectId, string $uid): array {
		$project = $this->loadProject($projectId, $uid, PermissionService::PERMISSION_READ);
		$cards = $this->resolveCards($project, $uid);
		$assignees = $this->cardAssigneeMapper->findUserIdsByCards(array_column($cards, 'id'));

		return array_map(static function (array $card) use ($assignees): array {
			$card['assigneeIds'] = $assignees[(int)$card['id']] ?? [];
			return $card;
		}, $cards);
	}

	/**
	 * Progress, forecast, health and burn-up for the project - see
	 * {@see ProjectForecast}. Same card set as {@see self::listCards()}.
	 *
	 * @return array<string, mixed>
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor cannot read the project
	 */
	public function plan(int $projectId, string $uid): array {
		$project = $this->loadProject($projectId, $uid, PermissionService::PERMISSION_READ);

		return $this->forecast->compute(
			$this->resolveCards($project, $uid),
			(int)$project->getCreatedAt(),
			$project->getStartDate(),
			$project->getTargetDate(),
			time(),
		);
	}

	/**
	 * The project's cards on the viewer's active, readable boards, each with its
	 * count of open (visible) blockers.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function resolveCards(Project $project, string $uid): array {
		// Active boards only (#10126): an archived board is shelved, so its
		// cards leave the project's card set (and its metrics with them).
		$boards = $this->boardService->findAllActive($uid);
		$boardIds = array_map(static fn (Board $board): int => $board->getId(), $boards);
		$roles = $this->boardAccess->rolesFor($boards, $uid);

		$cards = $this->projectCardMapper->findCardsInProjectAndBoards($project->getId(), $boardIds, $uid, $roles);
		$blockers = $this->projectCardMapper->openBlockerCounts(array_column($cards, 'id'), $boardIds, $uid, $roles);

		return array_map(static function (array $card) use ($blockers): array {
			$card['blockers'] = $blockers[(int)$card['id']] ?? 0;
			return $card;
		}, $cards);
	}

	/**
	 * Cross-board analytics over the card set {@see self::listCards()} resolves
	 * for this viewer, so a card they cannot see never contributes to a metric.
	 * Board-specific panels are omitted - see {@see StatsService::projectStats()}.
	 *
	 * @return array<string, mixed> the project-stats DTO
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor cannot read the project
	 * @throws \OCP\DB\Exception
	 */
	public function stats(int $projectId, string $uid): array {
		$project = $this->loadProject($projectId, $uid, PermissionService::PERMISSION_READ);

		// Active boards only (#10126): an archived board is shelved, so its
		// cards leave the project's card set (and its metrics with them).
		$boards = $this->boardService->findAllActive($uid);
		$cards = $this->projectCardMapper->findCardsInProjectAndBoards(
			$project->getId(),
			array_map(static fn (Board $board): int => $board->getId(), $boards),
			$uid,
			$this->boardAccess->rolesFor($boards, $uid),
		);
		$cardIds = array_map(static fn (array $card): int => (int)$card['id'], $cards);

		return $this->statsService->projectStats($cardIds);
	}

	/**
	 * @throws InvalidInputException if the description is over the cap
	 */
	private function assertDescriptionLength(string $description): void {
		if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
			throw new InvalidInputException('Description must not exceed ' . self::MAX_DESCRIPTION_LENGTH . ' characters');
		}
	}

	/**
	 * @throws InvalidInputException unless empty (→ null) or a real YYYY-MM-DD date
	 */
	private function parseDate(?string $date): ?string {
		$date = trim((string)$date);
		if ($date === '') {
			return null;
		}
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			throw new InvalidInputException('Dates must be YYYY-MM-DD');
		}
		return $date;
	}

	/**
	 * @throws InvalidInputException if the target date is before the start date
	 */
	private function assertDateOrder(Project $project): void {
		$start = $project->getStartDate();
		$target = $project->getTargetDate();
		if ($start !== null && $target !== null && $target < $start) {
			throw new InvalidInputException('The target date must not be before the start date');
		}
	}

	/**
	 * @param int $permission bitmask of PermissionService::PERMISSION_* bits
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor lacks a required bit
	 */
	private function loadProject(int $id, string $uid, int $permission): Project {
		$project = $this->projectMapper->find($id);
		$this->projectAccess->assertPermission($project, $uid, $permission);
		return $project;
	}

	/**
	 * A card the actor cannot see must not be collectable or removable - a
	 * success would confirm it exists.
	 *
	 * @throws DoesNotExistException if the card's board does not exist or is deleted
	 * @throws NotPermittedException if the actor cannot read the board or see the card
	 */
	private function assertCardReadable(Card $card, string $uid): void {
		$board = $this->loadBoard($card->getBoardId());
		if (($this->permissionService->getPermissions($board, $uid) & PermissionService::PERMISSION_READ) === 0) {
			throw new NotPermittedException('User has no access to this board');
		}
		$this->visibilityGuard->assertVisible($board, $card, $uid);
	}

	/**
	 * @throws DoesNotExistException if the card does not exist or is deleted
	 */
	private function loadCard(int $id): Card {
		$card = $this->cardMapper->find($id);
		if ($card->getDeletedAt() > 0) {
			throw new DoesNotExistException('Card ' . $id . ' is deleted');
		}
		return $card;
	}

	/**
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 */
	private function loadBoard(int $boardId): Board {
		$board = $this->boardMapper->find($boardId);
		if ($board->getDeletedAt() > 0) {
			throw new DoesNotExistException('Board ' . $boardId . ' is deleted');
		}
		return $board;
	}
}
