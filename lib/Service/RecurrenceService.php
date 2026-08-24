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
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\RecurRule;
use OCA\Kanso\Db\RecurRuleMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use Sabre\VObject\Recur\RRuleIterator;

/**
 * Recurring card rules: board automation that spawns a card on a schedule
 * (RFC 5545 RRULE). Rules are board-automation config (like labels and
 * auto-archive rules), so creating/editing them needs MANAGE and listing needs
 * READ. The schedule is expanded with sabre/vobject's {@see RRuleIterator},
 * anchored at the rule's `createdAt` (its DTSTART), and the next fire time is
 * cached in `next_occurrence_at` so the cron scan is a single indexed range
 * query. The schedule is expanded as floating wall-clock time (RFC 5545 /
 * CalDAV) in the rule's IANA `timezone` (defaulting to the owner's personal
 * timezone, server default as fallback), so e.g. "daily at 09:00" fires 09:00
 * local on both sides of a DST boundary. A delayed/downed cron catches up on
 * every MISSED occurrence - one card per occurrence - bounded per run by
 * {@see self::MAX_CATCHUP}; see {@see self::runDueRules}.
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

	/**
	 * Catch-up cap: the most occurrences a single rule may spawn in one cron
	 * run. A rule dormant for months (server down, rule re-enabled) must not
	 * flood a board with hundreds of cards in one pass - it stamps up to this
	 * many, logs that catch-up was truncated, and the remainder continue on the
	 * next run (the cursor is durable per occurrence). Kept modest: a daily rule
	 * catches up ~2 months of backlog per run, hourly ~2 days.
	 */
	public const MAX_CATCHUP = 50;

	public function __construct(
		private RecurRuleMapper $ruleMapper,
		private CardMapper $cardMapper,
		private StackMapper $stackMapper,
		private BoardMapper $boardMapper,
		private CardLabelMapper $cardLabelMapper,
		private CardAssigneeMapper $cardAssigneeMapper,
		private CardService $cardService,
		private ChangeNotifier $changeNotifier,
		private PermissionService $permissionService,
		private CardVisibilityGuard $visibilityGuard,
		private ITimeFactory $time,
		private IDBConnection $db,
		private IConfig $config,
		private \Psr\Log\LoggerInterface $logger,
	) {
	}

	// ---- RRULE expansion --------------------------------------------------

	/**
	 * First occurrence strictly after $afterTs (unix seconds), honoring any
	 * COUNT/UNTIL embedded in the RRULE. Returns 0 when the rule is exhausted
	 * (no further occurrence) - the caller treats 0 as "self-disable".
	 *
	 * The RRULE is anchored at $dtstartTs (the rule's creation time) reinterpreted
	 * as a wall-clock time in $timezone, so occurrences are floating local times
	 * per RFC 5545 / CalDAV: "daily at 09:00" fires 09:00 local on both sides of a
	 * DST boundary and the concrete UTC instant shifts to keep the local hour
	 * stable. $timezone null falls back to the server default timezone (back-compat
	 * for rules created before the timezone column existed). We do NOT hand-roll
	 * DST math - sabre's {@see RRuleIterator} does it when anchored in a real zone.
	 *
	 * @throws InvalidInputException if the RRULE cannot be parsed
	 */
	public function computeNextOccurrence(string $rrule, int $afterTs, int $dtstartTs, ?string $timezone = null): int {
		$tz = $this->timezoneFor($timezone);
		// Anchor as a floating wall-clock time in the rule's zone: take the UTC
		// instant of $dtstartTs and re-interpret its calendar fields in $tz.
		$start = (new \DateTimeImmutable('@' . $dtstartTs))->setTimezone($tz);
		try {
			$iterator = new RRuleIterator($rrule, $start);
		} catch (\Exception $e) {
			// sabre throws assorted exception types for malformed input;
			// normalize to the API's InvalidInputException.
			throw new InvalidInputException('Invalid recurrence rule');
		}

		// fastForward positions at the first occurrence >= the target; asking
		// for afterTs + 1 makes the result strictly after afterTs.
		$target = (new \DateTimeImmutable('@' . ($afterTs + 1)))->setTimezone($tz);
		try {
			$iterator->fastForward($target);
		} catch (\Exception $e) {
			// Some malformed rules only fail while iterating.
			throw new InvalidInputException('Invalid recurrence rule');
		}

		if (!$iterator->valid()) {
			return 0;
		}
		// vobject 5 typed current() as nullable (?DateTimeImmutable); the valid()
		// guard above already ensures a current occurrence, but psalm can't narrow
		// that, so coalesce defensively.
		$occurrence = $iterator->current();
		return $occurrence?->getTimestamp() ?? 0;
	}

	/**
	 * Resolves the rule's stored IANA timezone id to a DateTimeZone. A null or
	 * empty stored value (pre-#3587 rules) or an unparseable id falls back to the
	 * server default timezone, then UTC as a last resort.
	 */
	private function timezoneFor(?string $timezone): \DateTimeZone {
		if ($timezone !== null && $timezone !== '') {
			try {
				return new \DateTimeZone($timezone);
			} catch (\Exception $e) {
				// fall through to the server default
			}
		}
		try {
			return new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
		} catch (\Exception $e) {
			return new \DateTimeZone('UTC');
		}
	}

	/**
	 * The IANA timezone a new rule owned by $uid should carry: the user's
	 * Nextcloud personal timezone (Settings → Personal), falling back to the
	 * server default. Stored on the rule so its schedule is stable even if the
	 * user later changes their personal timezone.
	 */
	private function defaultTimezoneFor(string $uid): string {
		$tz = $this->config->getUserValue($uid, 'core', 'timezone', '');
		if ($tz !== '') {
			try {
				new \DateTimeZone($tz);
				return $tz;
			} catch (\Exception $e) {
				// invalid stored value - fall through to server default
			}
		}
		return date_default_timezone_get() ?: 'UTC';
	}

	// ---- rule CRUD --------------------------------------------------------

	/**
	 * Rules on a board, excluding any whose template card is in the trash (#67):
	 * a soft-deleted template pauses its rule (it can't spawn) and resurrects on
	 * restore, so the rule row is kept — but showing it in the automation list
	 * makes it look like a live orphan. Filter those out here rather than in the
	 * mapper so card reads stay behind CardMapper (architecture rule #3741). Card
	 * counts per board are small, so the per-rule template read is not a concern.
	 *
	 * @return RecurRule[]
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the user may not read the board
	 */
	public function listForBoard(int $boardId, string $uid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_READ);
		$rules = $this->ruleMapper->findByBoard($boardId);

		return array_values(array_filter($rules, function (RecurRule $rule): bool {
			try {
				return $this->cardMapper->find($rule->getTemplateCardId())->getDeletedAt() === 0;
			} catch (DoesNotExistException $e) {
				// Template hard-deleted (purged) — the rule is an orphan that the
				// purge cascade should already have removed; hide it regardless.
				return false;
			}
		}));
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
		// Visibility (#3760): a rule may only be anchored on a template card its
		// creator can SEE - a hidden template reads as "does not exist" (404,
		// same as a bogus id - no existence oracle). Spawns re-check against the
		// rule OWNER, so a later visibility narrowing cannot keep leaking copies.
		$template = $this->loadCard($templateCardId);
		$this->visibilityGuard->assertVisible($board, $template, $uid);

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
		// Default the rule's timezone to the owner's personal timezone (server
		// default fallback); the schedule is expanded as floating wall-clock time
		// in this zone.
		$rule->setTimezone($this->defaultTimezoneFor($uid));
		// Work out when this rule should fire for the FIRST time.
		//
		// The schedule is anchored at the card's Start date (its End date, then the
		// creation time, as fallbacks - see anchorFor()). We pick the first
		// occurrence at or after that anchor, but NEVER the occurrence that coincides
		// with "now": the card the user just set up already exists, and firing on it
		// would immediately reset/clone the card and overwrite the date they just
		// picked (that was bug #80 - a "Yearly" repeat re-stamped to today). A Start
		// date set for the future fires on that date; otherwise the first fire is the
		// next occurrence after now. See firstFireFor(). "Create now" is the way to
		// spawn one right away on purpose.
		$rule->setNextOccurrenceAt($this->firstFireFor($rule, $this->anchorFor($template, $rule)));

		return $this->ruleMapper->insert($rule);
	}

	/**
	 * Updates the given fields of a rule (null = leave unchanged). The cached
	 * `next_occurrence_at` is re-armed only when the schedule actually changes (a
	 * different RRULE) or the rule is re-enabled (disabled → enabled), and even
	 * then it is never rewound onto an occurrence the cron already spawned - a
	 * no-op edit leaves the cursor exactly where it was, so editing a rule can no
	 * longer duplicate an already-fired occurrence dated today (#65).
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
		// Same gate as create() (#3760): re-anchoring on a hidden template is a 404.
		$template = $this->loadCard($newTemplate);
		$this->visibilityGuard->assertVisible($board, $template, $uid);

		// Capture the pre-edit schedule + enabled state BEFORE applying the setters
		// so we can tell an actual schedule change from a no-op edit (#65). The
		// cursor may only be re-armed when the schedule really changed, and never
		// rewound onto an occurrence the cron has already spawned.
		$originalRrule = $rule->getRrule();
		$wasEnabled = $rule->getEnabled();

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

		// Do we need to recalculate when this rule fires next?
		//
		// Only in two cases: the user changed the actual schedule (a different
		// repeat rule), or they switched the rule back on after it was off. If they
		// changed nothing about the schedule - e.g. just re-saved the card's Repeat
		// control, or toggled "enabled" while it was already on - we must leave the
		// next fire time exactly as it is. Recalculating a no-op edit could pull the
		// fire time back onto a date the system already acted on, and re-firing it
		// makes a duplicate card dated today (that was bug #65). The two flags below
		// make sure a no-op edit skips this block entirely.
		//
		// When we DO recalculate, we pick the next date STRICTLY AFTER now (that is
		// what passing $now does). Two reasons:
		//   1. It fixes the "yearly reset to today" family of bugs (#80) - the rule
		//      is never left ready to fire the instant the next cron runs.
		//   2. If the user speeds up a rule (say Weekly -> Daily), it starts on the
		//      new schedule right away. The old code kept the far-off weekly date, so
		//      "Daily" did nothing for up to a week. It's also safe: a date after now
		//      is always in the future, so it can never be one we already fired -
		//      meaning no duplicate card (still safe for #65).
		$scheduleChanged = $newRrule !== $originalRrule;
		$reEnabled = $rule->getEnabled() && !$wasEnabled;
		if ($rule->getEnabled() && ($scheduleChanged || $reEnabled)) {
			$rule->setNextOccurrenceAt($this->firstFireFor($rule, $this->anchorFor($template, $rule)));
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
		// Visibility (#3760): the spawned/reset card (title, description) is
		// returned to the ACTOR - a template hidden from them must read as
		// missing (404), or create-now would be a read oracle for hidden
		// content. The spawn itself re-checks against the rule OWNER.
		$this->visibilityGuard->assertVisible($board, $this->loadCard($rule->getTemplateCardId()), $uid);
		return $this->spawn($rule, true);
	}

	/**
	 * Re-point every repeat anchored on this card after its Start/End date was
	 * edited, so the series follows the new dates - what a user naturally expects
	 * when they reschedule a repeating card ("move it to the 15th" should make it
	 * repeat from the 15th). Called by {@see CardService::update} whenever a card's
	 * start or due date changes; a card with no rules is a cheap no-op.
	 *
	 * The next fire is recomputed from the card's new anchor exactly like create()
	 * (first occurrence at/after the anchor, never one that coincides with "now"),
	 * so a Start pushed into the future fires then and a Start moved earlier picks
	 * up the next occurrence after now - never a back-dated spawn. A disabled or
	 * exhausted rule is left alone; a rule whose stored RRULE cannot be parsed is
	 * logged and skipped so a bad rule can never break the card edit.
	 */
	public function rearmForTemplateCard(Card $card): void {
		foreach ($this->ruleMapper->findByTemplateCard($card->getId()) as $rule) {
			if (!$rule->getEnabled()) {
				continue;
			}
			try {
				$rule->setNextOccurrenceAt($this->firstFireFor($rule, $this->anchorFor($card, $rule)));
			} catch (InvalidInputException $e) {
				$this->logger->warning(
					'kanso: could not re-arm recurring rule ' . $rule->getId() . ' after a date edit',
					['exception' => $e]
				);
				continue;
			}
			$this->ruleMapper->update($rule);
		}
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
	 * Spawns exactly ONE occurrence - the one currently cached in
	 * next_occurrence_at - and advances the cursor to the NEXT occurrence strictly
	 * after it (NOT to now). Advancing per occurrence, rather than jumping the
	 * cursor to now, is what lets a delayed cron catch up: {@see self::runDueRules}
	 * calls spawn() repeatedly while the rule stays due, so a rule N intervals in
	 * the past yields N cards - one per missed occurrence - each in its own
	 * transaction, so partial progress is durable (a crash mid-catch-up never
	 * re-spawns an already-committed occurrence). Manual create-now fires the
	 * cached occurrence too, then advances the same way.
	 *
	 * Bookkeeping always runs (except on a skip): occurrences_spawned and
	 * last_spawned_at are bumped, and next_occurrence_at is advanced past the
	 * fired occurrence - 0 (COUNT/UNTIL exhausted) self-disables the rule.
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

		// Read the template up front - both the soft-trash pause below and the CLONE
		// enrichment need it, so read once (a missing/hard-deleted template stays
		// null here and falls through to the mode branch, which throws its usual
		// DoesNotExistException that the cron logs and retries).
		$template = $this->findTemplateOrNull($rule);
		// The cadence is anchored at the card's Start date (see anchorFor()); read it
		// once here so every advanceSchedule() below walks the same anchor.
		$anchorTs = $this->anchorFor($template, $rule);

		// Pause on a SOFT-trashed template (#4124): create/update guard the template
		// via loadCard() (throws on deleted_at > 0), but the spawn hot path read it
		// raw, so a template moved to the trash kept cloning/resetting. Treat a
		// soft-trashed template as a pause, not an error and not a hard-disable: skip
		// the spawn but still advance the schedule past this occurrence (like a
		// skip_while_open skip) so the cron does not busy-loop, and leave the rule
		// enabled so it resumes automatically when the template is restored. A PURGED
		// template is a different case - the purge drops the rule outright (#4123).
		if ($template !== null && $template->getDeletedAt() > 0) {
			$this->db->beginTransaction();
			try {
				$this->logger->info(
					'kanso: recurring rule ' . $rule->getId()
					. ' paused, template card is in the trash'
				);
				$this->advanceSchedule($rule, $this->advanceFrom($rule, $occurrenceTs, $manual), $anchorTs);
				$this->ruleMapper->update($rule);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			return null;
		}

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
					// advance the schedule past this occurrence so the rule does not
					// re-fire it (the next due occurrence is still handled next loop).
					$this->advanceSchedule($rule, $this->advanceFrom($rule, $occurrenceTs, $manual), $anchorTs);
					$this->ruleMapper->update($rule);
					$this->db->commit();
					return null;
				}
				$card = $this->spawnClone($rule, $occurrenceTs, $template);
			}

			$rule->setOccurrencesSpawned($rule->getOccurrencesSpawned() + 1);
			$rule->setLastSpawnedAt($card->getId());
			$this->advanceSchedule($rule, $this->advanceFrom($rule, $occurrenceTs, $manual), $anchorTs);
			$this->ruleMapper->update($rule);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Commit succeeded - now it is safe to broadcast the enrichment UPDATE
		// row that spawnClone deferred (push=false inside the transaction).
		$this->changeNotifier->pushBoardChanged($card->getBoardId());

		return $card;
	}

	/**
	 * CLONE: a new card at the bottom of the target stack (CardService handles
	 * the EDIT check, the sort key and the CREATE change), then the template's
	 * description, due date, labels and assignees copied over.
	 *
	 * The enrichment (description + due date + labels + assignees) is written
	 * straight through the mappers - it bypasses CardService, so none of it
	 * lands in the change log on its own. CardService::create logged only a
	 * title-only CREATE, so a delta-only client that consumed that CREATE would
	 * refetch a card whose description/labels/assignees had not yet reached the
	 * board's change log, and would keep that stripped copy until an unrelated
	 * mutation happened to bump the board. To close that gap we append an
	 * ACTION_UPDATE change row for the enriched card here, inside the spawn
	 * transaction (#3574), so the enrichment advances getLatestChangeId / the
	 * board ETag atomically with the CREATE. The push is deferred (push=false)
	 * and emitted by {@see self::spawn()} after commit - a pre-commit push could
	 * make a client refetch state the transaction may still roll back.
	 */
	private function spawnClone(RecurRule $rule, int $occurrenceTs, ?Card $template = null): Card {
		// $template is the copy spawn() already read; null only when spawn() found
		// no template (hard-deleted) - re-read to raise the usual DoesNotExistException.
		$template ??= $this->cardMapper->find($rule->getTemplateCardId());

		// Visibility (#3760): the spawn runs as the rule OWNER - if the template
		// has been narrowed past them since the rule was created, copying its
		// content into a card the owner CAN see would be a leak. Fails like a
		// missing template (DoesNotExistException); the cron logs and retries,
		// exactly as when the owner lost board access.
		$board = $this->loadBoard($rule->getBoardId());
		$this->visibilityGuard->assertVisible($board, $template, $rule->getOwner());

		// CardService::create runs as the owner: EDIT check, bottom-of-stack
		// sort key, CREATE change - all in one place. The spawned card inherits
		// the template's visibility class AND frozen creator side VERBATIM
		// (#3760) - set on the INSERT itself, so the create-time fan-outs
		// (activity, board watchers) never see a wider interim 'public' card.
		$card = $this->cardService->create(
			$rule->getTargetStackId(),
			$template->getTitle(),
			$rule->getOwner(),
			null,
			null,
			$template->getVisibility() ?? CardVisibilityScope::VISIBILITY_PUBLIC,
			$template->getCreatorRole(),
		);

		$card->setDescription($template->getDescription());
		// Slide the template's Start→End window forward to this occurrence (see
		// windowFor): the occurrence becomes the new Start, the End keeps the same
		// gap. The clone inherits the template's dates shifted, not a stamped-on due
		// date.
		[$newStart, $newEnd] = $this->windowFor($template, $occurrenceTs);
		$card->setStartDate($newStart);
		$card->setDuedate($newEnd);
		// Carry the template's all-day flag (#4125): without it the clone defaults
		// to all_day=false and shows a spurious 00:00 time on an all-day template.
		$card->setAllDay($template->getAllDay() ?? false);
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

		// Log the enrichment as an UPDATE so delta-sync (?since=) and the board
		// ETag reflect the full card, not just its title. Deferred push - the
		// spawn transaction owns the commit and the post-commit broadcast.
		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$card->getId(),
			Change::ACTION_UPDATE,
			$rule->getOwner(),
			false,
			Change::VERB_UPDATED,
		);

		return $card;
	}

	/**
	 * RESET: the template card is the one working card. Move it back to the
	 * target stack (CardService::move handles the EDIT check, the sort key and
	 * the MOVE change), then clear its done/archived state and re-arm the due
	 * date per policy.
	 *
	 * The done/archived/duedate reset is a direct mapper update - like CLONE's
	 * enrichment it bypasses CardService and so writes no change row of its own;
	 * only the MOVE reaches the log. A delta-only client that consumed the MOVE
	 * would refetch a card still flagged done / carrying its old due date until
	 * an unrelated mutation bumped the board. Append an ACTION_UPDATE change row
	 * for the reset (deferred push - the spawn transaction broadcasts after
	 * commit) so the cleared state advances getLatestChangeId / the board ETag
	 * atomically.
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
		// Slide this card's OWN Start→End window forward to the occurrence (the reset
		// card is its own template). $card still carries its pre-reset dates here, so
		// windowFor reads the old window and returns the shifted one.
		[$newStart, $newEnd] = $this->windowFor($card, $occurrenceTs);
		$card->setStartDate($newStart);
		$card->setDuedate($newEnd);
		// Re-arm the due-date reminders (#3545) for the reset card's new End date.
		$card->setDueReminderSent(0);
		$card->setDayBeforeReminderSent(0);
		$card->setLastModified($this->time->getTime());
		$card = $this->cardMapper->update($card);

		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$card->getId(),
			Change::ACTION_UPDATE,
			$rule->getOwner(),
			false,
			Change::VERB_UPDATED,
		);

		return $card;
	}

	/**
	 * The timestamp the repeat schedule is anchored at - its RFC 5545 DTSTART.
	 * The template card's Start date, else its End (due) date, else the rule's
	 * creation time. Anchoring at the card's own dates is what makes "starts
	 * Jan 5, repeats weekly" land on Jan 5, 12, 19 rather than on whatever day
	 * Repeat happened to be switched on. A card with no dates falls back to the
	 * rule's creation time (the pre-window behaviour).
	 */
	private function anchorFor(?Card $template, RecurRule $rule): int {
		if ($template !== null) {
			$start = $template->getStartDate()?->getTimestamp();
			if ($start !== null) {
				return $start;
			}
			$end = $template->getDuedate()?->getTimestamp();
			if ($end !== null) {
				return $end;
			}
		}
		return $rule->getCreatedAt();
	}

	/**
	 * The first fire for a freshly created or re-armed rule: the next occurrence
	 * at or after the anchor, but never the occurrence that coincides with "now"
	 * itself - firing that one would immediately reset/clone the card the user
	 * just set up and overwrite the date they picked (#80). A Start date set for
	 * the FUTURE fires on that date; a past/now anchor fires on the next
	 * occurrence strictly after now.
	 */
	private function firstFireFor(RecurRule $rule, int $anchorTs): int {
		$now = $this->time->getTime();
		$after = max($anchorTs - 1, $now);
		return $this->computeNextOccurrence($rule->getRrule(), $after, $anchorTs, $rule->getTimezone());
	}

	/**
	 * The [start, end] dates a card spawned/reset at $occurrenceTs should carry.
	 * The template's Start→End window slides forward to the occurrence, keeping
	 * its length (the calendar-event model): the occurrence becomes the new Start
	 * and the End keeps the same distance after it. A template with only one of
	 * the two dates slides just that one; a template with neither stays date-less
	 * (a repeat never invents a date the user did not set).
	 *
	 * @return array{0: ?\DateTime, 1: ?\DateTime} [start, end]
	 */
	private function windowFor(Card $template, int $occurrenceTs): array {
		$start = $template->getStartDate()?->getTimestamp();
		$end = $template->getDuedate()?->getTimestamp();

		if ($start !== null && $end !== null) {
			$duration = max(0, $end - $start);
			return [
				new \DateTime('@' . $occurrenceTs),
				new \DateTime('@' . ($occurrenceTs + $duration)),
			];
		}
		if ($start !== null) {
			return [new \DateTime('@' . $occurrenceTs), null];
		}
		if ($end !== null) {
			return [null, new \DateTime('@' . $occurrenceTs)];
		}
		return [null, null];
	}

	/**
	 * Advances the rule's cached next fire time to the first occurrence strictly
	 * after the occurrence that just fired ($firedOccurrenceTs) - NOT to now.
	 * Walking the cursor occurrence-by-occurrence is what lets a delayed cron
	 * catch up on every missed occurrence instead of skipping to the next future
	 * one. 0 (COUNT/UNTIL exhausted) self-disables the rule. A malformed RRULE
	 * (should be impossible past create/update validation, but a rule could
	 * predate a stricter parser) is treated as exhausted and disables the rule
	 * rather than throwing out of the spawn.
	 */
	/**
	 * The point the schedule should advance PAST after this spawn.
	 *
	 * Scheduled spawns advance strictly past the occurrence that just fired, so
	 * the cursor walks occurrence-by-occurrence and the cron catches up on every
	 * missed one. Manual create-now instead re-arms to the next occurrence at or
	 * after now (advance from now - 1): it stamps an extra card without bringing
	 * the cadence forward, so a missed/early manual spawn never skips the upcoming
	 * scheduled fire.
	 */
	private function advanceFrom(RecurRule $rule, int $occurrenceTs, bool $manual): int {
		return $manual ? $this->time->getTime() - 1 : $occurrenceTs;
	}

	private function advanceSchedule(RecurRule $rule, int $firedOccurrenceTs, int $anchorTs): void {
		try {
			$next = $this->computeNextOccurrence($rule->getRrule(), $firedOccurrenceTs, $anchorTs, $rule->getTimezone());
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
	 * The rule's template card, or null if it is hard-gone. spawn() reads it once
	 * up front for both the soft-trash pause check (#4124) and CLONE enrichment.
	 * A MISSING template returns null (not an exception here) so spawn() falls
	 * through to the mode branch, which raises the usual DoesNotExistException the
	 * cron logs and retries; a purge cascade drops the rule outright (#4123).
	 */
	private function findTemplateOrNull(RecurRule $rule): ?Card {
		try {
			return $this->cardMapper->find($rule->getTemplateCardId());
		} catch (DoesNotExistException) {
			return null;
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
	 * Spawns every due rule - the cron entry point. For each due rule this
	 * catches up on ALL missed occurrences (server was down / cron delayed):
	 * spawn() fires the cached occurrence and advances the cursor by exactly one
	 * occurrence, so while the rule stays due (next_occurrence_at <= now) we keep
	 * spawning - one card per missed occurrence, each in its own transaction, so
	 * partial progress survives a crash and is never double-spawned.
	 *
	 * BOUNDED: at most {@see self::MAX_CATCHUP} occurrences per rule per run. A
	 * rule dormant for months cannot flood a board in one pass; when the cap is
	 * hit we log the truncation and leave the rule due, so the remaining
	 * occurrences continue on the next run. Each rule runs in its own try/catch so
	 * one broken rule (deleted template, lost board access) cannot stall the rest;
	 * a rule that throws mid-catch-up keeps the occurrences it already committed
	 * and is retried next run from its durable cursor.
	 *
	 * @return int number of cards successfully spawned this run (across all rules)
	 */
	public function runDueRules(): int {
		$spawned = 0;
		$now = $this->time->getTime();
		foreach ($this->ruleMapper->findDueEnabled($now) as $rule) {
			$count = 0;
			try {
				// Catch up occurrence-by-occurrence while the rule stays due, up to
				// the per-run cap. spawn() advances next_occurrence_at past each
				// fired occurrence; a skip (skip_while_open) advances it too, so the
				// loop still terminates.
				while ($rule->getEnabled()
					&& $rule->getNextOccurrenceAt() > 0
					&& $rule->getNextOccurrenceAt() <= $now
					&& $count < self::MAX_CATCHUP) {
					if ($this->spawn($rule) !== null) {
						$spawned++;
					}
					$count++;
				}
				if ($count >= self::MAX_CATCHUP
					&& $rule->getEnabled()
					&& $rule->getNextOccurrenceAt() > 0
					&& $rule->getNextOccurrenceAt() <= $now) {
					$this->logger->warning(
						'kanso: recurring rule ' . $rule->getId()
						. ' catch-up truncated at ' . self::MAX_CATCHUP
						. ' occurrences; remaining occurrences continue next run'
					);
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
