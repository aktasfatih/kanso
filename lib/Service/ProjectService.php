<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Project;
use OCA\Kanso\Db\ProjectCardMapper;
use OCA\Kanso\Db\ProjectMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Projects: owner-only, cross-board card collections (#3447).
 *
 * A project is private to its `owner` in v1 - there is no sharing/ACL table, so
 * every mutation asserts the actor IS the owner. The one place ACL still bites
 * is the card list: a project may hold cards from boards the owner has since
 * lost access to, so listCards restricts to the owner's readable board set
 * (mirrors {@see ReviewService::findMine}) and adding a card additionally
 * requires the owner hold READ on that card's board (a card they cannot see is
 * meaningless to collect).
 *
 * Project membership is deliberately NOT written to `kanso_changes`: it is
 * per-user, cross-board metadata with no per-board consumer.
 */
class ProjectService {
	private const MAX_TITLE_LENGTH = 255;

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
	) {
	}

	/**
	 * The current user's projects, in title order.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function findMine(string $uid): array {
		return array_values(array_map(
			static fn (Project $project): array => $project->jsonSerialize(),
			$this->projectMapper->findByOwner($uid)
		));
	}

	/**
	 * Creates a project owned by $uid.
	 *
	 * @throws InvalidInputException on an empty or over-long title, or a malformed date
	 */
	public function create(string $uid, string $title, ?string $description, ?string $color, ?string $startDate = null, ?string $targetDate = null): Project {
		$title = trim($title);
		if ($title === '') {
			throw new InvalidInputException('Project title must not be empty');
		}
		if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
			throw new InvalidInputException('Project title is too long');
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
	 * Updates the provided fields of a project. Only the owner may act.
	 *
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor is not the owner
	 * @throws InvalidInputException on an empty or over-long title, or a malformed date
	 */
	public function update(int $id, string $uid, ?string $title, ?string $description, ?string $color, ?string $startDate = null, ?string $targetDate = null): Project {
		$project = $this->loadOwnedProject($id, $uid);

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
		if ($description !== null) {
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
	 * Deletes a project and all its memberships. Only the owner may act. The
	 * member cards themselves are untouched.
	 *
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor is not the owner
	 */
	public function delete(int $id, string $uid): void {
		$project = $this->loadOwnedProject($id, $uid);
		$this->projectCardMapper->deleteByProject($project->getId());
		$this->projectMapper->delete($project);
	}

	/**
	 * Adds a card to a project. Only the owner may act, and they must hold READ
	 * on the card's board (a card they cannot see is meaningless to collect).
	 * Idempotent per (project, card).
	 *
	 * @throws DoesNotExistException if the project, card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor is not the owner, or cannot read the card's board
	 */
	public function addCard(int $projectId, int $cardId, string $uid): void {
		$project = $this->loadOwnedProject($projectId, $uid);
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());

		if (($this->permissionService->getPermissions($board, $uid) & PermissionService::PERMISSION_READ) === 0) {
			throw new NotPermittedException('User has no access to this board');
		}
		// A card the collector cannot SEE is meaningless to collect - and a
		// successful add would confirm its existence (#3743).
		$this->visibilityGuard->assertVisible($board, $card, $uid);

		$this->projectCardMapper->add($project->getId(), $card->getId());
	}

	/**
	 * Removes a card from a project. Only the owner may act. Idempotent.
	 *
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor is not the owner
	 */
	public function removeCard(int $projectId, int $cardId, string $uid): void {
		$project = $this->loadOwnedProject($projectId, $uid);
		$this->projectCardMapper->remove($project->getId(), $cardId);
	}

	/**
	 * The project's cards, ACL-filtered to the owner's readable board set. Only
	 * the owner may act. Cards on boards the owner can no longer read are
	 * silently dropped.
	 *
	 * @return list<array<string, mixed>>
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor is not the owner
	 */
	public function listCards(int $projectId, string $uid): array {
		$project = $this->loadOwnedProject($projectId, $uid);
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
	 * @throws NotPermittedException if the actor is not the owner
	 */
	public function plan(int $projectId, string $uid): array {
		$project = $this->loadOwnedProject($projectId, $uid);

		return $this->forecast->compute(
			$this->resolveCards($project, $uid),
			(int)$project->getCreatedAt(),
			$project->getStartDate(),
			$project->getTargetDate(),
			time(),
		);
	}

	/**
	 * The project's cards on the owner's active, readable boards, each with its
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
	 * Cross-board analytics over the project's ACL-resolved card set - mirrors
	 * board analytics but for a project (#3568). Only the owner may act. The card
	 * set is resolved EXACTLY as {@see self::listCards()} does - one ACL-filtered
	 * pass through the owner's readable-board set (never per-row), so a card on a
	 * board the owner cannot READ is never in the set and can never contribute to
	 * a metric. The resolved card ids are then handed to StatsService, which
	 * re-uses the board-stats aggregation over an explicit card id set (no second
	 * stats engine, no board scope). Board-specific panels (byStack, estimate
	 * totals / points) are omitted - see {@see StatsService::projectStats()}.
	 *
	 * @return array<string, mixed> the project-stats DTO
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor is not the owner
	 * @throws \OCP\DB\Exception
	 */
	public function stats(int $projectId, string $uid): array {
		$project = $this->loadOwnedProject($projectId, $uid);

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
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor does not own the project
	 */
	private function loadOwnedProject(int $id, string $uid): Project {
		$project = $this->projectMapper->find($id);
		if ($project->getOwner() !== $uid) {
			throw new NotPermittedException('Only the owner may act on this project');
		}
		return $project;
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
