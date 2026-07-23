<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\ArchiveRule;
use OCA\Kanso\Db\ArchiveRuleMapper;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Auto-archive rules: board-hygiene automation that archives done cards once
 * they cross an age threshold. Rules are board-automation config (like
 * labels), so creating and editing them needs MANAGE; listing needs READ.
 *
 * The sweep itself is a plain query + per-card UPDATE, driven both by the
 * {@see \OCA\Kanso\Cron\ArchiveDoneCards} cron and the manual archive-now
 * endpoint. It is idempotent — the eligibility query excludes anything
 * already archived, deleted or not done — and capped at MAX_PER_SWEEP so a
 * board with years of done cards cannot stall the cron in one pass.
 */
class ArchiveService {
	/**
	 * Cards archived per rule per sweep. Bounds a single pass; the remainder
	 * is picked up on subsequent cron runs.
	 */
	public const MAX_PER_SWEEP = 100;

	/** Ten years, far beyond any sensible cleanup threshold. */
	private const MAX_THRESHOLD_SECONDS = 315360000;

	public function __construct(
		private ArchiveRuleMapper $ruleMapper,
		private CardMapper $cardMapper,
		private StackMapper $stackMapper,
		private BoardMapper $boardMapper,
		private ChangeNotifier $changeNotifier,
		private PermissionService $permissionService,
		private ITimeFactory $time,
	) {
	}

	// ---- rule CRUD --------------------------------------------------------

	/**
	 * Rules on a board.
	 *
	 * @return ArchiveRule[]
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the user may not read the board
	 */
	public function listForBoard(int $boardId, string $uid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_READ);
		return $this->ruleMapper->findByBoard($boardId);
	}

	/**
	 * Creates a rule. stackId null covers the whole board; otherwise the
	 * stack must belong to the board.
	 *
	 * @throws DoesNotExistException if the board or stack does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 * @throws InvalidInputException on invalid condition, threshold or stack
	 */
	public function create(int $boardId, ?int $stackId, int $condition, int $thresholdSeconds, string $uid): ArchiveRule {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);
		$this->validate($boardId, $stackId, $condition, $thresholdSeconds);

		$rule = new ArchiveRule();
		$rule->setBoardId($boardId);
		$rule->setStackId($stackId);
		$rule->setCondition($condition);
		$rule->setThresholdSeconds($thresholdSeconds);
		$rule->setEnabled(true);
		$rule->setCreatedAt($this->time->getTime());

		return $this->ruleMapper->insert($rule);
	}

	/**
	 * Updates the given fields of a rule (null = leave unchanged).
	 *
	 * @throws DoesNotExistException if the rule, its board or the new stack does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 * @throws InvalidInputException on invalid condition, threshold or stack
	 */
	public function update(
		int $id,
		?int $stackId,
		bool $stackIdProvided,
		?int $condition,
		?int $thresholdSeconds,
		?bool $enabled,
		string $uid,
	): ArchiveRule {
		$rule = $this->loadRule($id);
		$board = $this->loadBoard($rule->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);

		$newStackId = $stackIdProvided ? $stackId : $rule->getStackId();
		$newCondition = $condition ?? $rule->getCondition();
		$newThreshold = $thresholdSeconds ?? $rule->getThresholdSeconds();
		$this->validate($rule->getBoardId(), $newStackId, $newCondition, $newThreshold);

		if ($stackIdProvided) {
			$rule->setStackId($stackId);
		}
		if ($condition !== null) {
			$rule->setCondition($condition);
		}
		if ($thresholdSeconds !== null) {
			$rule->setThresholdSeconds($thresholdSeconds);
		}
		if ($enabled !== null) {
			$rule->setEnabled($enabled);
		}

		return $this->ruleMapper->update($rule);
	}

	/**
	 * @throws DoesNotExistException if the rule or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 */
	public function delete(int $id, string $uid): ArchiveRule {
		$rule = $this->loadRule($id);
		$board = $this->loadBoard($rule->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);
		return $this->ruleMapper->delete($rule);
	}

	/**
	 * Runs a rule's sweep once, on demand.
	 *
	 * @return int number of cards archived
	 * @throws DoesNotExistException if the rule or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 */
	public function archiveNow(int $id, string $uid): int {
		$rule = $this->loadRule($id);
		$board = $this->loadBoard($rule->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);
		return $this->sweep($rule);
	}

	// ---- sweep ------------------------------------------------------------

	/**
	 * Sweeps every enabled rule across all boards — the cron entry point.
	 * Each rule is capped at MAX_PER_SWEEP per pass so a huge board cannot
	 * stall the job; a rule that throws (e.g. its board was hard-deleted out
	 * from under it) is skipped so it cannot block the others.
	 *
	 * @return int total number of cards archived this run
	 */
	public function runEnabledRules(): int {
		$total = 0;
		foreach ($this->ruleMapper->findAllEnabled() as $rule) {
			try {
				$total += $this->sweep($rule);
			} catch (\Throwable) {
				// Best-effort: a broken rule must not stall the rest.
				continue;
			}
		}
		return $total;
	}

	/**
	 * Cards eligible for archive under this rule right now (capped at
	 * MAX_PER_SWEEP). Delegates the predicate to the mapper — see
	 * {@see CardMapper::findEligibleForArchive()}.
	 *
	 * @return Card[]
	 */
	public function findEligibleCards(ArchiveRule $rule): array {
		$cutoff = $this->time->getTime() - $rule->getThresholdSeconds();
		return $this->cardMapper->findEligibleForArchive(
			$rule->getBoardId(),
			$rule->getStackId(),
			$rule->getCondition(),
			$cutoff,
			self::MAX_PER_SWEEP,
		);
	}

	/**
	 * Archives every eligible card in the rule's scope (capped at
	 * MAX_PER_SWEEP) and emits a per-card change so open clients refetch.
	 * Idempotent: cards already archived are excluded by the eligibility
	 * query, so re-running the sweep is a no-op for them.
	 *
	 * @return int number of cards archived
	 */
	public function sweep(ArchiveRule $rule): int {
		$count = 0;
		foreach ($this->findEligibleCards($rule) as $card) {
			$card->setArchived(true);
			$this->cardMapper->update($card);
			// System actor (null) — the sweep is not attributed to a user.
			$this->changeNotifier->notify(
				$rule->getBoardId(),
				Change::ENTITY_CARD,
				$card->getId(),
				Change::ACTION_UPDATE,
				null,
			);
			$count++;
		}
		return $count;
	}

	// ---- helpers ----------------------------------------------------------

	/**
	 * @throws InvalidInputException on invalid condition, threshold or stack scope
	 * @throws DoesNotExistException if the referenced stack does not exist or is deleted
	 */
	private function validate(int $boardId, ?int $stackId, int $condition, int $thresholdSeconds): void {
		if ($condition !== ArchiveRule::CONDITION_DONE_FOR && $condition !== ArchiveRule::CONDITION_DONE_AND_AGE) {
			throw new InvalidInputException('Invalid archive condition');
		}
		if ($thresholdSeconds < 0 || $thresholdSeconds > self::MAX_THRESHOLD_SECONDS) {
			throw new InvalidInputException('Invalid archive threshold');
		}
		if ($stackId !== null) {
			$stack = $this->loadStack($stackId);
			if ($stack->getBoardId() !== $boardId) {
				throw new InvalidInputException('The stack does not belong to the board');
			}
		}
	}

	/**
	 * @throws DoesNotExistException if the rule does not exist
	 */
	private function loadRule(int $id): ArchiveRule {
		return $this->ruleMapper->find($id);
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

	/**
	 * @throws DoesNotExistException if the stack does not exist or is deleted
	 */
	private function loadStack(int $id): Stack {
		$stack = $this->stackMapper->find($id);
		if ($stack->getDeletedAt() > 0) {
			throw new DoesNotExistException('Stack ' . $id . ' is deleted');
		}
		return $stack;
	}
}
