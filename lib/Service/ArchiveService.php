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
use OCP\IDBConnection;

/**
 * Auto-archive rules: board-hygiene automation that archives done cards once
 * they cross an age threshold. Rules are board-automation config (like
 * labels), so creating and editing them needs MANAGE; listing needs READ.
 *
 * The sweep itself is a plain query + per-card UPDATE, driven both by the
 * {@see \OCA\Kanso\Cron\ArchiveDoneCards} cron and the manual archive-now
 * endpoint. It is idempotent - the eligibility query excludes anything
 * already archived, deleted or not done - and capped at MAX_PER_SWEEP so a
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
		private IDBConnection $db,
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
	 * Visibility decision (#3760): the returned count is a MANAGE-only
	 * aggregate that INCLUDES cards hidden from the caller, and deliberately
	 * stays that way. The sweep is hygiene automation and must process every
	 * eligible card regardless of visibility (a scoped sweep would leave
	 * hidden cards unarchived forever); the count reports the work that
	 * sweep actually did - scoping it to the caller's visible subset would
	 * misreport the mutation. What leaks is at most "N done cards crossed
	 * the threshold" to a MANAGE holder - no ids, titles or content - which
	 * is accepted for a board-hygiene admin surface.
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
	 * Sweeps every enabled rule across all boards - the cron entry point.
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
	 * MAX_PER_SWEEP). Delegates the predicate to the mapper - see
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
	 * MAX_PER_SWEEP), recording a per-card change row so open clients refetch.
	 * Idempotent: cards already archived are excluded by the eligibility
	 * query, so re-running the sweep is a no-op for them.
	 *
	 * Realtime pushes are COALESCED (#3418): each card's UPDATE + its change row
	 * is written per-item, but the notify_push fan-out (per-recipient, board-wide)
	 * fires ONCE for the board at the end of the batch instead of once per card.
	 * The push body is only {boardId}, so a single push is enough - clients delta/
	 * refetch and collapse the N change rows regardless. A 100-card sweep with M
	 * recipients thus emits M pushes, not 100xM.
	 *
	 * Each card's archive write and its change row are committed atomically (#3579):
	 * a failed change-row write rolls that card's archive flag back, so no card is
	 * left archived without a delta-sync row (and no delta row points at a write
	 * that never landed). A single card that throws aborts this rule's sweep but is
	 * isolated by {@see self::runEnabledRules()} so the other rules still run; the
	 * sweep is idempotent, so a retry picks up whatever committed.
	 *
	 * @return int number of cards archived
	 */
	public function sweep(ArchiveRule $rule): int {
		$count = 0;
		foreach ($this->findEligibleCards($rule) as $card) {
			$this->db->beginTransaction();
			try {
				$card->setArchived(true);
				$this->cardMapper->update($card);
				// System actor (null) - the sweep is not attributed to a user.
				// Record the row only; the coalesced push fires once after the loop.
				$this->changeNotifier->recordChange(
					$rule->getBoardId(),
					Change::ENTITY_CARD,
					$card->getId(),
					Change::ACTION_UPDATE,
					null,
				);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			$count++;
		}

		// One coalesced realtime push for the whole batch (#3418): only broadcast
		// when at least one card actually changed, so an empty sweep stays a no-op.
		if ($count > 0) {
			$this->changeNotifier->pushBoardChanged($rule->getBoardId());
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
