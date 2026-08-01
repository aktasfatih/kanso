<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\RecurRule;
use OCA\Kanso\Db\RecurRuleMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use Sabre\VObject\Recur\RRuleIterator;

/**
 * Recurring card rules: board automation that spawns a card on a schedule
 * (RFC 5545 RRULE). Rules are board-automation config (like labels and
 * auto-archive rules), so creating/editing them needs MANAGE and listing needs
 * READ. The schedule is expanded with sabre/vobject's {@see RRuleIterator},
 * anchored at the rule's `createdAt` (its DTSTART), and the next fire time is
 * cached in `next_occurrence_at` so the cron scan is a single indexed range
 * query.
 *
 * Two modes (see {@see RecurRule} MODE_* constants):
 *   - CLONE: each occurrence creates a fresh card in the target stack, copying
 *     the template's title, description, labels and assignees;
 *   - RESET: each occurrence moves the template card itself back to the target
 *     stack and clears its done state (household-chore style).
 *
 * KANSO note vs the Deck-recurrence port this mirrors: Kanso's
 * {@see PermissionService} is actor-independent (no session setUserId), so the
 * rule's owner uid is passed explicitly to every CardService call; the spawned
 * card's ordering comes from SortKeyService via CardService (Kanso has no
 * `order` column); there is no host-app-installed guard; and spawn failures are
 * logged only (no notification manager yet).
 */
class RecurrenceService {
	/** Ten years, a sane upper bound for a due-date offset. */
	private const MAX_OFFSET_SECONDS = 315360000;

	public function __construct(
		private RecurRuleMapper $ruleMapper,
		private CardMapper $cardMapper,
		private StackMapper $stackMapper,
		private BoardMapper $boardMapper,
		private CardLabelMapper $cardLabelMapper,
		private CardAssigneeMapper $cardAssigneeMapper,
		private CardService $cardService,
		private PermissionService $permissionService,
		private ITimeFactory $time,
		private IDBConnection $db,
		private \Psr\Log\LoggerInterface $logger,
	) {
	}

	// ---- RRULE expansion --------------------------------------------------

	/**
	 * First occurrence strictly after $afterTs (unix seconds), honoring any
	 * COUNT/UNTIL embedded in the RRULE. Returns 0 when the rule is exhausted
	 * (no further occurrence) - the caller treats 0 as "self-disable".
	 *
	 * The RRULE is anchored at $dtstartTs (the rule's creation time), so the
	 * iterator only ever emits occurrences at or after that anchor.
	 *
	 * @throws InvalidInputException if the RRULE cannot be parsed
	 */
	public function computeNextOccurrence(string $rrule, int $afterTs, int $dtstartTs): int {
		$start = new \DateTimeImmutable('@' . $dtstartTs);
		try {
			$iterator = new RRuleIterator($rrule, $start);
		} catch (\Exception $e) {
			// sabre throws assorted exception types for malformed input;
			// normalize to the API's InvalidInputException.
			throw new InvalidInputException('Invalid recurrence rule');
		}

		// fastForward positions at the first occurrence >= the target; asking
		// for afterTs + 1 makes the result strictly after afterTs.
		$target = new \DateTimeImmutable('@' . ($afterTs + 1));
		try {
			$iterator->fastForward($target);
		} catch (\Exception $e) {
			// Some malformed rules only fail while iterating.
			throw new InvalidInputException('Invalid recurrence rule');
		}

		if (!$iterator->valid()) {
			return 0;
		}
		return $iterator->current()->getTimestamp();
	}

	// ---- rule CRUD --------------------------------------------------------

	/**
	 * Rules on a board.
	 *
	 * @return RecurRule[]
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the user may not read the board
	 */
	public function listForBoard(int $boardId, string $uid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_READ);
		return $this->ruleMapper->findByBoard($boardId);
	}

	/**
	 * Creates a rule. The template card and the target stack must both belong
	 * to $boardId; the RRULE must parse. The rule's owner is the creating user
	 * - spawns run as them, so revoked board access naturally disables spawning.
	 * `next_occurrence_at` is computed and stored on creation.
	 *
	 * @throws DoesNotExistException if the board, template card or target stack does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 * @throws InvalidInputException on invalid mode, policy, offset, RRULE or cross-board references
	 */
	public function create(
		int $boardId,
		int $templateCardId,
		int $targetStackId,
		int $mode,
		string $rrule,
		int $duedatePolicy,
		int $duedateOffsetSeconds,
		bool $skipWhileOpen,
		string $uid,
	): RecurRule {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);
		$this->validate($boardId, $templateCardId, $targetStackId, $mode, $rrule, $duedatePolicy, $duedateOffsetSeconds);

		$now = $this->time->getTime();
		$rule = new RecurRule();
		$rule->setBoardId($boardId);
		$rule->setTemplateCardId($templateCardId);
		$rule->setTargetStackId($targetStackId);
		$rule->setMode($mode);
		$rule->setRrule($rrule);
		$rule->setDuedatePolicy($duedatePolicy);
		$rule->setDuedateOffsetSeconds($duedateOffsetSeconds);
		$rule->setSkipWhileOpen($skipWhileOpen);
		$rule->setEnabled(true);
		$rule->setOwner($uid);
		$rule->setLastSpawnedAt(0);
		$rule->setOccurrencesSpawned(0);
		$rule->setCreatedAt($now);
		// Anchor the schedule at creation; the first fire is the next occurrence
		// at or after now.
		$rule->setNextOccurrenceAt($this->computeNextOccurrence($rrule, $now - 1, $now));

		return $this->ruleMapper->insert($rule);
	}

	/**
	 * Updates the given fields of a rule (null = leave unchanged). Any change to
	 * the RRULE or the enabled flag recomputes `next_occurrence_at` from now.
	 *
	 * @throws DoesNotExistException if the rule, its board, the template card or the target stack does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 * @throws InvalidInputException on invalid mode, policy, offset, RRULE or cross-board references
	 */
	public function update(
		int $id,
		?int $templateCardId,
		?int $targetStackId,
		?int $mode,
		?string $rrule,
		?int $duedatePolicy,
		?int $duedateOffsetSeconds,
		?bool $skipWhileOpen,
		?bool $enabled,
		string $uid,
	): RecurRule {
		$rule = $this->ruleMapper->find($id);
		$board = $this->loadBoard($rule->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);

		$newTemplate = $templateCardId ?? $rule->getTemplateCardId();
		$newStack = $targetStackId ?? $rule->getTargetStackId();
		$newMode = $mode ?? $rule->getMode();
		$newRrule = $rrule ?? $rule->getRrule();
		$newPolicy = $duedatePolicy ?? $rule->getDuedatePolicy();
		$newOffset = $duedateOffsetSeconds ?? $rule->getDuedateOffsetSeconds();
		$this->validate($rule->getBoardId(), $newTemplate, $newStack, $newMode, $newRrule, $newPolicy, $newOffset);

		$rule->setTemplateCardId($newTemplate);
		$rule->setTargetStackId($newStack);
		$rule->setMode($newMode);
		$rule->setRrule($newRrule);
		$rule->setDuedatePolicy($newPolicy);
		$rule->setDuedateOffsetSeconds($newOffset);
		if ($skipWhileOpen !== null) {
			$rule->setSkipWhileOpen($skipWhileOpen);
		}
		if ($enabled !== null) {
			$rule->setEnabled($enabled);
		}

		// Re-arm the cached next fire time whenever the schedule or its enabled
		// state might have changed (cheap; keeps the cron scan honest).
		$now = $this->time->getTime();
		if ($rule->getEnabled()) {
			$rule->setNextOccurrenceAt($this->computeNextOccurrence($rule->getRrule(), $now - 1, $rule->getCreatedAt()));
		}

		return $this->ruleMapper->update($rule);
	}

	/**
	 * @throws DoesNotExistException if the rule or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 */
	public function delete(int $id, string $uid): RecurRule {
		$rule = $this->ruleMapper->find($id);
		$board = $this->loadBoard($rule->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);
		return $this->ruleMapper->delete($rule);
	}

	/**
	 * Spawns a rule once immediately, ignoring its schedule, then persists the
	 * usual bookkeeping (occurrences_spawned, last_spawned_at, recomputed
	 * next_occurrence_at). Manual creation still honors skip_while_open? No -
	 * create-now is an explicit user action, so it always spawns.
	 *
	 * Schedule-advance decision: create-now does NOT bring the schedule forward
	 * on its own. After the manual spawn we recompute next_occurrence_at as the
	 * next occurrence at or after now, exactly as a scheduled spawn would - so a
	 * missed/early manual spawn never skips the upcoming scheduled fire, it just
	 * stamps an extra card now. (Matches deck-recurrence "create now": it stamps
	 * a card and leaves the cadence intact.)
	 *
	 * @throws DoesNotExistException if the rule, its board, the template card or the target stack does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 */
	public function createNow(int $id, string $uid): ?Card {
		$rule = $this->ruleMapper->find($id);
		$board = $this->loadBoard($rule->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);
		return $this->spawn($rule, true);
	}

	// ---- spawning ---------------------------------------------------------

	/**
	 * Spawns one occurrence of a rule and advances its bookkeeping. CLONE mode
	 * creates a fresh card in the target stack (copying description, labels and
	 * assignees) with a due date per the rule's policy; RESET mode moves the
	 * template card back to the target stack and clears its done state.
	 *
	 * $manual is set by create-now: it ignores skip_while_open (an explicit user
	 * action always spawns). Returns the spawned/reset card, or null when a
	 * scheduled CLONE spawn was skipped because the previous card is still open.
	 *
	 * Bookkeeping always runs (except on a skip): occurrences_spawned and
	 * last_spawned_at are bumped, and next_occurrence_at is recomputed from now
	 * - 0 (COUNT/UNTIL exhausted) self-disables the rule.
	 *
	 * Atomicity (idempotency on cron retry): the card mutation AND the rule
	 * bookkeeping/schedule advance are wrapped in a single DB transaction, so a
	 * crash (or a throwing enrich write) after the card insert but before
	 * next_occurrence_at is advanced rolls the whole occurrence back - both the
	 * card and the un-advanced rule. Without this, a half-done CLONE spawn left
	 * the rule still due and the next cron run stamped a duplicate card
	 * (RESET self-corrected; CLONE duplicated unboundedly). Nextcloud DB
	 * transactions nest via savepoints, so CardService's own transactions run
	 * inside this outer one. Ordering decision: single-transaction (not
	 * advance-cursor-first, not an occurrence key) - it fits the existing
	 * IDBConnection begin/commit/rollBack idiom used across the services and
	 * keeps every write in the occurrence rolled back together. A sibling card
	 * (#3575) adds the spawned-card change-log row and can hang it off this same
	 * transaction.
	 *
	 * @throws DoesNotExistException if the template card or target stack is gone
	 * @throws NotPermittedException if the owner lost board access
	 * @throws InvalidInputException on a card mutation error
	 */
	public function spawn(RecurRule $rule, bool $manual = false): ?Card {
		$occurrenceTs = $rule->getNextOccurrenceAt() > 0
			? $rule->getNextOccurrenceAt()
			: $this->time->getTime();

		$this->db->beginTransaction();
		try {
			if ($rule->getMode() === RecurRule::MODE_RESET) {
				$card = $this->spawnReset($rule, $occurrenceTs);
			} else {
				if (!$manual && $rule->getSkipWhileOpen() && $this->previousCardOpen($rule)) {
					$this->logger->info(
						'kanso: recurring rule ' . $rule->getId()
						. ' skipped, previously spawned card is still open'
					);
					// A skip is not an occurrence: leave the counters be, but still
					// advance the schedule so the rule does not re-fire immediately.
					$this->advanceSchedule($rule);
					$this->ruleMapper->update($rule);
					$this->db->commit();
					return null;
				}
				$card = $this->spawnClone($rule, $occurrenceTs);
			}

			$rule->setOccurrencesSpawned($rule->getOccurrencesSpawned() + 1);
			$rule->setLastSpawnedAt($card->getId());
			$this->advanceSchedule($rule);
			$this->ruleMapper->update($rule);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $card;
	}

	/**
	 * CLONE: a new card at the bottom of the target stack (CardService handles
	 * the EDIT check, the sort key and the CREATE change), then the template's
	 * description, due date, labels and assignees copied over. The description
	 * and due date ride the CREATE - no extra UPDATE change row.
	 */
	private function spawnClone(RecurRule $rule, int $occurrenceTs): Card {
		$template = $this->cardMapper->find($rule->getTemplateCardId());

		// CardService::create runs as the owner: EDIT check, bottom-of-stack
		// sort key, CREATE change - all in one place.
		$card = $this->cardService->create($rule->getTargetStackId(), $template->getTitle(), $rule->getOwner());

		$card->setDescription($template->getDescription());
		$card->setDuedate($this->duedateFor($rule, $occurrenceTs));
		$card->setLastModified($this->time->getTime());
		$card = $this->cardMapper->update($card);

		foreach ($this->cardLabelMapper->findLabelIdsByCard($template->getId()) as $labelId) {
			if (!$this->cardLabelMapper->exists($card->getId(), $labelId)) {
				$this->cardLabelMapper->insertAssignment($card->getId(), $labelId);
			}
		}
		foreach ($this->cardAssigneeMapper->findUserIdsByCard($template->getId()) as $assigneeUid) {
			if (!$this->cardAssigneeMapper->exists($card->getId(), $assigneeUid)) {
				$this->cardAssigneeMapper->insertAssignment($card->getId(), $assigneeUid);
			}
		}

		return $card;
	}

	/**
	 * RESET: the template card is the one working card. Move it back to the
	 * target stack (CardService::move handles the EDIT check, the sort key and
	 * the MOVE change), then clear its done/archived state and re-arm the due
	 * date per policy. The done/duedate reset rides a plain card UPDATE - no
	 * extra change row beyond the move.
	 */
	private function spawnReset(RecurRule $rule, int $occurrenceTs): Card {
		// Move to the bottom of the target stack (afterCardId = last card).
		$last = $this->cardMapper->findLastInStack($rule->getTargetStackId());
		$afterCardId = $last !== null && $last->getId() !== $rule->getTemplateCardId()
			? $last->getId()
			: null;
		$card = $this->cardService->move(
			$rule->getTemplateCardId(),
			$rule->getTargetStackId(),
			$afterCardId,
			$rule->getOwner(),
		);

		$card->setDoneAt(0);
		$card->setArchived(false);
		$card->setDuedate($this->duedateFor($rule, $occurrenceTs));
		$card->setLastModified($this->time->getTime());
		return $this->cardMapper->update($card);
	}

	/**
	 * Due date for a card spawned at $occurrenceTs, per the rule's policy:
	 * at-occurrence → the occurrence time; offset-after → occurrence + offset;
	 * none → null.
	 */
	private function duedateFor(RecurRule $rule, int $occurrenceTs): ?\DateTime {
		return match ($rule->getDuedatePolicy()) {
			RecurRule::POLICY_OFFSET_AFTER => new \DateTime('@' . ($occurrenceTs + $rule->getDuedateOffsetSeconds())),
			RecurRule::POLICY_NONE => null,
			default => new \DateTime('@' . $occurrenceTs),
		};
	}

	/**
	 * Recomputes and stores the rule's next fire time from now; 0 (COUNT/UNTIL
	 * exhausted) self-disables the rule. A malformed RRULE (should be
	 * impossible past create/update validation, but a rule could predate a
	 * stricter parser) is treated as exhausted and disables the rule rather than
	 * throwing out of the spawn.
	 */
	private function advanceSchedule(RecurRule $rule): void {
		$now = $this->time->getTime();
		try {
			$next = $this->computeNextOccurrence($rule->getRrule(), $now, $rule->getCreatedAt());
		} catch (InvalidInputException $e) {
			$this->logger->error(
				'kanso: recurring rule ' . $rule->getId() . ' has an invalid RRULE, disabling',
				['exception' => $e]
			);
			$next = 0;
		}
		$rule->setNextOccurrenceAt($next);
		if ($next === 0) {
			$rule->setEnabled(false);
		}
	}

	/**
	 * Whether the rule's most recently spawned card is still open (exists, not
	 * done, not archived, not deleted). A rule that has never spawned, or whose
	 * last card is gone, counts as not-open.
	 */
	private function previousCardOpen(RecurRule $rule): bool {
		if ($rule->getLastSpawnedAt() === 0) {
			return false;
		}
		try {
			$card = $this->cardMapper->find($rule->getLastSpawnedAt());
		} catch (DoesNotExistException) {
			return false;
		}
		return $card->getDoneAt() === 0
			&& !$card->getArchived()
			&& $card->getDeletedAt() === 0;
	}

	// ---- cron entry -------------------------------------------------------

	/**
	 * Spawns every due rule - the cron entry point. Each rule runs in its own
	 * try/catch so one broken rule (deleted template, lost board access) cannot
	 * stall the rest; failures are logged and skipped.
	 *
	 * @return int number of rules successfully spawned this run
	 */
	public function runDueRules(): int {
		$spawned = 0;
		foreach ($this->ruleMapper->findDueEnabled($this->time->getTime()) as $rule) {
			try {
				if ($this->spawn($rule) !== null) {
					$spawned++;
				}
			} catch (\Throwable $e) {
				$this->logger->warning(
					'kanso: could not spawn recurring rule ' . $rule->getId(),
					['exception' => $e]
				);
			}
		}
		return $spawned;
	}

	// ---- helpers ----------------------------------------------------------

	/**
	 * @throws InvalidInputException on invalid mode/policy/offset/RRULE or cross-board references
	 * @throws DoesNotExistException if the template card or target stack does not exist or is deleted
	 */
	private function validate(
		int $boardId,
		int $templateCardId,
		int $targetStackId,
		int $mode,
		string $rrule,
		int $duedatePolicy,
		int $duedateOffsetSeconds,
	): void {
		if ($mode !== RecurRule::MODE_CLONE && $mode !== RecurRule::MODE_RESET) {
			throw new InvalidInputException('Invalid recurrence mode');
		}
		if (!in_array($duedatePolicy, [RecurRule::POLICY_AT_OCCURRENCE, RecurRule::POLICY_OFFSET_AFTER, RecurRule::POLICY_NONE], true)) {
			throw new InvalidInputException('Invalid due-date policy');
		}
		if ($duedateOffsetSeconds < 0 || $duedateOffsetSeconds > self::MAX_OFFSET_SECONDS) {
			throw new InvalidInputException('Invalid due-date offset');
		}
		// Parse-validate the RRULE (throws InvalidInputException on garbage).
		// We anchor at "now" purely to construct the iterator; the result is
		// discarded here - the point is to reject unparseable rules.
		$this->computeNextOccurrence($rrule, $this->time->getTime(), $this->time->getTime());

		$card = $this->loadCard($templateCardId);
		if ($card->getBoardId() !== $boardId) {
			throw new InvalidInputException('The template card does not belong to the board');
		}
		$stack = $this->loadStack($targetStackId);
		if ($stack->getBoardId() !== $boardId) {
			throw new InvalidInputException('The target stack does not belong to the board');
		}
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
	 * @throws DoesNotExistException if the stack does not exist or is deleted
	 */
	private function loadStack(int $id): Stack {
		$stack = $this->stackMapper->find($id);
		if ($stack->getDeletedAt() > 0) {
			throw new DoesNotExistException('Stack ' . $id . ' is deleted');
		}
		return $stack;
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
