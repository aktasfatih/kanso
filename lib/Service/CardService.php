<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\BoardPrefix;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChecklistItem;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\EstimateScale;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Db\Subscription;
use OCA\Kanso\Db\SubscriptionMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

/**
 * Card CRUD and moves. Every mutation appends a row to the `kanso_changes`
 * log in the same flow (see BoardService). Ordering uses fractional sort
 * keys: creation appends to the bottom of the stack and a move rewrites a
 * single card row - no sibling renumbering, ever.
 */
class CardService {
	// Public: the webhook's issue intake pre-truncates external titles to it.
	public const MAX_TITLE_LENGTH = 100;

	// Max insert attempts in create(): absorbs sort-key AND board-wide board_seq
	// unique collisions under concurrency before surfacing a retryable 409.
	private const MAX_CREATE_ATTEMPTS = 5;

	public function __construct(
		private CardMapper $cardMapper,
		private StackMapper $stackMapper,
		private BoardMapper $boardMapper,
		private ChangeNotifier $changeNotifier,
		private PermissionService $permissionService,
		private SortKeyService $sortKeyService,
		private CardReviewMapper $cardReviewMapper,
		private IDBConnection $db,
		private SubscriptionService $subscriptionService,
		private AutomationService $automationService,
		private MentionService $mentionService,
		private LabelService $labelService,
		private ChecklistService $checklistService,
		private LabelMapper $labelMapper,
		private CardLabelMapper $cardLabelMapper,
		private ChecklistItemMapper $checklistItemMapper,
		private CardAssigneeMapper $cardAssigneeMapper,
		private SubscriptionMapper $subscriptionMapper,
		private BoardAccess $boardAccess,
		private CardVisibilityGuard $visibilityGuard,
	) {
	}

	/**
	 * Full card detail including the description.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not read the board
	 */
	public function find(int $id, string $uid): Card {
		$card = $this->loadCard($id);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_READ);
		// Visibility (#3743): a hidden card 404s exactly like a missing one -
		// no existence oracle. Runs AFTER the board gate so non-members keep
		// today's 403.
		$this->visibilityGuard->assertVisible($board, $card, $uid);
		return $card;
	}

	/**
	 * Summaries of the per-board template cards (#3409) - the template picker
	 * source. READ on the board is enough (same gate as the board payload): the
	 * picker only reveals blueprint titles the reader can already see the board
	 * for. Creating FROM a template is separately EDIT-gated.
	 *
	 * @return Card[]
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the user may not read the board
	 */
	public function listTemplates(int $boardId, string $uid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_READ);
		return $this->cardMapper->findTemplatesByBoard(
			$boardId,
			$this->boardAccess->contextFor($board, $uid),
		);
	}

	/**
	 * Board-scoped resolution of a `PREFIX-<board_seq>` human reference (e.g.
	 * "KAN-123") to a card on the given board (#3611). Per-board prefixes make a
	 * reference unambiguous only WITHIN a board, so this is deliberately scoped to
	 * a single board (cross-board references are a follow-up). The reference's
	 * prefix half is validated against the board's own prefix - a reference whose
	 * prefix does not match the board resolves to null rather than silently
	 * matching on the number alone. Only the numeric sequence is looked up (the
	 * (board_id, board_seq) unique index); a trashed card resolves to null.
	 *
	 * Returns null (not an exception) for a malformed/mismatched/unknown reference
	 * so the caller can fall back to plain text; only a genuinely missing/deleted
	 * board or a permission failure throws.
	 *
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the user may not read the board
	 */
	public function findByRef(int $boardId, string $ref, string $uid): ?Card {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_READ);

		// Split "PREFIX-<digits>" - uppercase letters/digits prefix, a dash, then
		// the sequence number. Mirrors the client-side token shape in markdown.js.
		if (!preg_match('/^([A-Z][A-Z0-9]*)-(\d+)$/', strtoupper(trim($ref)), $m)) {
			return null;
		}
		$prefix = $m[1];
		$seq = (int)$m[2];
		if ($seq <= 0) {
			return null;
		}

		// The board's prefix must match the reference's prefix (a reference to a
		// different board's prefix is not resolvable here). Fall back to the shared
		// default for boards created before the prefix backfill, mirroring the
		// board payload's default (Board::jsonSerialize / BoardPrefix::DEFAULT).
		$boardPrefix = $board->getPrefix() ?? BoardPrefix::DEFAULT;
		if ($prefix !== $boardPrefix) {
			return null;
		}

		// Viewer-scoped (#3743): a reference to a hidden card resolves to null,
		// indistinguishable from a reference that never matched.
		return $this->cardMapper->findByBoardAndSeq(
			$boardId,
			$seq,
			$this->boardAccess->contextFor($board, $uid),
		);
	}

	/**
	 * Creates a card at the bottom of the stack.
	 *
	 * An optional due date can be set on the same INSERT (#3416, the composer's
	 * natural-date tokens): one clean write + one kanso_changes row, never a
	 * create-then-update round-trip. $duedate uses the same wire format as
	 * update() (an ISO 8601 datetime; parsed/normalized to UTC via parseDuedate)
	 * and $allDay flags a date-only due date, mirroring update()'s duedate/allDay
	 * coupling. A null $duedate leaves the card with no due date (back-compat).
	 *
	 * $visibility / $creatorRole are the recurrence-inheritance overrides
	 * (#3760): a spawned clone must carry its template's visibility class and
	 * frozen creator side FROM THE INSERT on, so the create-time fan-outs
	 * (activity, board-watcher notifications) already see - and filter by -
	 * the final visibility. Null (every request-path caller) keeps the
	 * default: 'public' with the creator's own resolved side.
	 *
	 * @param string|null $visibility one of the CardVisibilityScope::VISIBILITIES, or null for 'public'
	 * @param string|null $creatorRole one of the ViewerContext::ROLES, or null for the creator's resolved side
	 * @throws DoesNotExistException if the stack or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board
	 * @throws InvalidInputException on invalid title, due date, visibility or creator role
	 * @throws \OverflowException if the appended sort key would overflow (stack needs a rebalance)
	 *                            or a concurrent create keeps colliding after one retry
	 */
	public function create(int $stackId, string $title, string $uid, ?string $duedate = null, ?bool $allDay = null, ?string $visibility = null, ?string $creatorRole = null): Card {
		$stack = $this->loadStack($stackId);
		$board = $this->loadBoard($stack->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);

		if ($visibility !== null && !in_array($visibility, CardVisibilityScope::VISIBILITIES, true)) {
			throw new InvalidInputException('Unknown card visibility: ' . $visibility);
		}
		if ($creatorRole !== null && !in_array($creatorRole, ViewerContext::ROLES, true)) {
			throw new InvalidInputException('Unknown creator role: ' . $creatorRole);
		}

		$title = $this->validateTitle($title);
		// Parse the optional due date up-front so an invalid value fails the create
		// cleanly (a 400 via InvalidInputException) before any INSERT is attempted,
		// matching update()'s validation. '' is treated as "no due date" too.
		$parsedDue = $duedate === null ? null : $this->parseDuedate($duedate);
		// The creator's board side, FROZEN at create (#3741) - never recomputed
		// later, so an 'internal' card keeps its side when the creator changes
		// role or leaves the board. Resolved once, outside the retry loop (the
		// EDIT assertion above guarantees a membership to resolve).
		$creatorSide = $this->boardAccess->contextFor($board, $uid)->role;
		$now = time();

		// Default: append to the bottom of the stack. When the board opts in,
		// place the new card at the TOP instead (before the current head). A
		// concurrent create into the same stack can derive the same key; the
		// (stack_id, sort_key, deleted_at) unique index rejects the loser, so
		// re-read the now-current neighbour and re-derive once before giving up
		// with a retryable 409. The same retry also covers a per-board sequence
		// collision (two creates on one board deriving the same board_seq): the
		// (board_id, board_seq) unique index rejects the loser and we recompute.
		$onTop = $board->getNewCardsOnTop() === true;
		for ($attempt = 0; ; $attempt++) {
			if ($onTop) {
				$firstCard = $this->cardMapper->findFirstInStack($stackId);
				$sortKey = $firstCard === null
					? $this->sortKeyService->initial()
					: $this->sortKeyService->before($firstCard->getSortKey());
			} else {
				$lastCard = $this->cardMapper->findLastInStack($stackId);
				$sortKey = $lastCard === null
					? $this->sortKeyService->initial()
					: $this->sortKeyService->after($lastCard->getSortKey());
			}

			// Assign the next per-board number atomically-ish: MAX+1 guarded by the
			// (board_id, board_seq) unique index. Recomputed each attempt so a retry
			// after a collision picks up the winner's number.
			$boardSeq = $this->cardMapper->nextBoardSeq($stack->getBoardId());

			$card = new Card();
			$card->setBoardId($stack->getBoardId());
			$card->setStackId($stackId);
			$card->setTitle($title);
			$card->setSortKey($sortKey);
			$card->setBoardSeq($boardSeq);
			// Creating a card directly in a role-bearing stack adopts that
			// column's status - matching move()'s status-automation for a
			// dragged-in card. Done-role → done; in-progress/review-role →
			// started; backlog/to-do/none → not started (both left at 0).
			$card->setDoneAt($stack->getRole() === Stack::ROLE_DONE ? $now : 0);
			$card->setStartedAt(
				in_array($stack->getRole(), [Stack::ROLE_IN_PROGRESS, Stack::ROLE_REVIEW], true) ? $now : 0,
			);
			$card->setArchived(false);
			$card->setOwner($uid);
			$card->setCreatedAt($now);
			$card->setLastModified($now);
			$card->setDeletedAt(0);
			// Optional due date from the composer's natural-date tokens (#3416).
			// Set on the same INSERT; $allDay flags a date-only token so the pill
			// hides the (midnight) time, mirroring update()'s duedate/allDay pair.
			if ($parsedDue !== null) {
				$card->setDuedate($parsedDue);
				if ($allDay !== null) {
					$card->setAllDay($allDay);
				}
			}
			// New cards are always ordinary live cards, never templates (#3409) -
			// a card is only flagged a template later via setTemplate(). Explicit so
			// the column is set on INSERT (the DB default backs pre-migration rows).
			$card->setIsTemplate(false);
			// Visibility (#3741): every card starts 'public' (whoever decides
			// nothing works in the open; a narrower visibility is a later,
			// explicit choice) with the creator's side frozen alongside it.
			// Explicit on INSERT, same rationale as is_template above. The
			// recurrence-inheritance overrides (#3760) replace both on the
			// INSERT itself, so no fan-out ever sees a wider interim class.
			$card->setVisibility($visibility ?? CardVisibilityScope::VISIBILITY_PUBLIC);
			$card->setCreatorRole($creatorRole ?? $creatorSide);

			// Insert the card AND its CREATE change row atomically (#3579): a
			// failed change-row write must roll the card INSERT back, never leave
			// a card without its delta-sync row. The realtime push is deferred to
			// after commit - a pre-commit push could surface a card the retry then
			// rolls back. A unique violation (sort-key or board_seq collision)
			// rolls the transaction back and drives the re-derive/retry.
			$this->db->beginTransaction();
			try {
				$card = $this->cardMapper->insert($card);
				$this->changeNotifier->recordChange(
					$stack->getBoardId(),
					Change::ENTITY_CARD,
					$card->getId(),
					Change::ACTION_CREATE,
					$uid,
					Change::VERB_CREATED,
				);
				$this->db->commit();

				// Commit succeeded - now it is safe to broadcast the create.
				$this->changeNotifier->pushBoardChanged($stack->getBoardId());

				// Surface the create in the Nextcloud Activity stream (best-effort,
				// never fatal to the create). User-initiated only - $uid is the actor.
				$this->changeNotifier->publishCardActivity(
					$stack->getBoardId(),
					'card_created',
					$card,
					$uid,
				);

				// Fan a "new card on a board you watch" notification out to board
				// watchers. Best-effort - a notification hiccup must never fail the
				// create (the card + its change row are already committed).
				try {
					$this->subscriptionService->notifyBoardCardCreated($stack->getBoardId(), $card, $uid);
				} catch (\Throwable) {
					// Ignore - board-activity fan-out is a non-critical side effect.
				}

				return $card;
			} catch (\OCP\DB\Exception $e) {
				$this->db->rollBack();
				if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				// A unique violation here is either a sort-key collision (≤2 writers
				// on the same stack head/tail) or a board_seq collision. The latter
				// contends board-wide (every concurrent create derives the same
				// MAX+1), so allow a few more re-derives before surfacing a
				// retryable 409 - each retry re-reads the now-current neighbour and
				// the now-current max.
				if ($attempt >= self::MAX_CREATE_ATTEMPTS - 1) {
					throw new \OverflowException('card create key conflict (sort key or board_seq) after retries', 0, $e);
				}
			} catch (\Throwable $e) {
				// Any other failure (e.g. the change-row insert throwing) also rolls
				// back the card INSERT so no orphan card lands without a delta row.
				$this->db->rollBack();
				throw $e;
			}
		}
	}

	/**
	 * Duplicates a card's CONTENT into $targetStackId (same board or another
	 * board the actor can EDIT). What is cloned: title (suffixed " (copy)"),
	 * description, priority, type, status (started/done timestamps), estimate,
	 * labels and checklist items. What is NOT cloned: comments, activity/history,
	 * relations, subscriptions, parent/children and assignees - a copy is a
	 * fresh, standalone card. The new card is appended to the target stack via
	 * {@see self::create()} (fresh id + sort key + its own change row) and the
	 * per-field/label/checklist writes reuse the existing services.
	 *
	 * Labels are board-scoped:
	 *   - same-board copy re-assigns the source label ids directly;
	 *   - cross-board copy maps each source label to a target-board label with
	 *     the SAME title (case-insensitive) AND color, and DROPS any that has no
	 *     such twin (labels are never auto-created on the target).
	 *
	 * Estimate is likewise board-scoped: a source estimate token that the target
	 * board's scale does not allow is dropped (the copy simply has no estimate).
	 *
	 * @throws DoesNotExistException if the source card, either board or the target stack does not exist or is deleted
	 * @throws NotPermittedException if the actor lacks EDIT on the source OR the target board
	 * @throws InvalidInputException if the source title is invalid
	 * @throws \OverflowException if the appended sort key would overflow (target stack needs a rebalance)
	 */
	public function copy(int $id, int $targetStackId, string $uid): Card {
		// Load the source with its description and assert EDIT on its board (read
		// access to clone the content is gated at EDIT per the card's rule, not
		// the weaker READ that find() would use).
		$source = $this->loadCard($id);
		$sourceBoard = $this->loadBoard($source->getBoardId());
		$this->permissionService->assertPermission($sourceBoard, $uid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($sourceBoard, $source, $uid);

		$targetStack = $this->loadStack($targetStackId);
		$targetBoard = $this->loadBoard($targetStack->getBoardId());
		// create() also asserts EDIT on the target board, but assert it up-front
		// so a permission failure never leaves a half-copied card behind.
		$this->permissionService->assertPermission($targetBoard, $uid, PermissionService::PERMISSION_EDIT);

		// 1. Create the shell at the bottom of the target stack (fresh id + key +
		//    change row + board-watcher fan-out - all reused from create()). The
		//    source's visibility LEVEL rides the INSERT itself (#3760): the
		//    create-time fan-outs (activity, board watchers) carry the source's
		//    title, so they must already be filtered by the copy's final class -
		//    a post-hoc narrowing would leak the title of a hidden source.
		$copy = $this->create(
			$targetStackId,
			$this->copyTitle($source->getTitle()),
			$uid,
			null,
			null,
			$source->getVisibility() ?? CardVisibilityScope::VISIBILITY_PUBLIC,
		);

		// 2. Clone the content (description / priority / type / status / estimate /
		//    labels / checklist) into the shell - shared with createFromTemplate().
		$this->cloneContentInto($source, $copy, $sourceBoard, $targetBoard, $uid);

		return $copy;
	}

	/**
	 * MOVES a single card to a stack on ANOTHER board (#3679): the card is
	 * re-created on the target board and REMOVED from the source, in ONE enclosing
	 * transaction. Unlike {@see self::copy()} (a standalone duplicate that drops
	 * assignees/watchers) this is a true relocation - the card leaves the source
	 * board and lands on the target with as much of its context as the target
	 * board's ACL permits.
	 *
	 * What crosses over:
	 *   - content: title (unchanged, no " (copy)" suffix), description, priority,
	 *     type, status timestamps, due/start dates, cover colour, and estimate
	 *     (dropped when the target board's scale rejects the token);
	 *   - labels: mapped by title+color to the target board (unmatched drop),
	 *     exactly like {@see self::copyLabels()} - labels are never auto-created;
	 *   - checklist items, in order, with their done state;
	 *   - assignees AND watchers/subscriptions: KEPT only for uids that can READ
	 *     the target board; any uid that cannot is DROPPED (the leak guard - a move
	 *     must never carry a card reference to someone who cannot access it).
	 *
	 * What does NOT cross over (mirrors copy / the charter non-goals): comments,
	 * activity/history, relations/back-links, and parent/children links (a moved
	 * card is detached from its source-board hierarchy). The human KAN-id is
	 * RE-ISSUED on the target board (a fresh per-board sequence) - the reference
	 * changes, as it does for a copy.
	 *
	 * The whole thing runs in a single begin/commit/rollBack: the target INSERT +
	 * its label/checklist/assignee/subscription rows + the source soft-delete +
	 * BOTH boards' `kanso_changes` rows commit together, so a permission or DB
	 * failure never leaves the card half-moved (created on the target but still on
	 * the source, or vice versa). Both boards are pushed AFTER commit so realtime /
	 * ETag advance on each.
	 *
	 * @throws DoesNotExistException if the source card, either board or the target stack does not exist or is deleted
	 * @throws NotPermittedException if the actor lacks EDIT on the source OR the target board
	 * @throws InvalidInputException if the target stack is on the SAME board (use move() for an in-board move)
	 * @throws \OverflowException if the appended sort key or the board sequence keeps colliding after retries
	 */
	public function moveToBoard(int $id, int $targetStackId, string $uid): Card {
		// Load the source with its description and gate EDIT on its board up-front.
		$source = $this->loadCard($id);
		$sourceBoard = $this->loadBoard($source->getBoardId());
		$this->permissionService->assertPermission($sourceBoard, $uid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($sourceBoard, $source, $uid);

		$targetStack = $this->loadStack($targetStackId);
		$targetBoard = $this->loadBoard($targetStack->getBoardId());
		// EDIT on the target too - asserted up-front so a denial never soft-deletes
		// the source before the target write is even attempted.
		$this->permissionService->assertPermission($targetBoard, $uid, PermissionService::PERMISSION_EDIT);

		// A same-board "move to board" is meaningless (and would try to soft-delete
		// then re-create on one board): route in-board moves through move() instead.
		if ($targetBoard->getId() === $sourceBoard->getId()) {
			throw new InvalidInputException('Use the in-board move for a card staying on the same board');
		}

		// Snapshot the source's carried context ONCE, before the transaction, so a
		// retry re-reads only the sort-key/sequence neighbours (which can shift
		// under concurrency), never the whole card graph.
		$sourceLabelIds = $this->cardLabelMapper->findLabelIdsByCard($id);
		$sourceItems = $this->checklistItemMapper->findByCard($id);
		// Assignees / watchers that may cross the boundary: only uids that can READ
		// the TARGET board. Dropping the rest is the leak guard - a move must never
		// carry the card to someone who cannot access where it landed.
		$targetLabelByKey = $this->targetLabelIndex($sourceLabelIds, $targetBoard);
		// De-duplicate the uid lists after the READ filter: the target rows are
		// fresh, so the only way a per-uid insert could hit a unique violation is a
		// duplicate uid in the source - which the retry loop would otherwise
		// misread as a sort-key/board_seq collision. array_unique keeps that from
		// happening (and documents that one row per uid is intended).
		$carriedAssignees = array_values(array_unique($this->filterReadableOnTarget(
			$this->cardAssigneeMapper->findUserIdsByCard($id),
			$targetBoard,
		)));
		$carriedWatchers = array_values(array_unique($this->filterReadableOnTarget(
			$this->subscriptionMapper->findCardSubscriberUids($id),
			$targetBoard,
		)));

		$title = $this->validateTitle($source->getTitle());

		for ($attempt = 0; ; $attempt++) {
			$this->db->beginTransaction();
			try {
				// Fresh shell on the target: append to the bottom of the target stack
				// and re-issue the per-board KAN-id (MAX+1 guarded by the unique
				// (board_id, board_seq) index, like create()).
				$lastCard = $this->cardMapper->findLastInStack($targetStackId);
				$sortKey = $lastCard === null
					? $this->sortKeyService->initial()
					: $this->sortKeyService->after($lastCard->getSortKey());
				$boardSeq = $this->cardMapper->nextBoardSeq($targetBoard->getId());

				$now = time();
				$moved = $this->buildMovedCard($source, $targetBoard, $targetStackId, $sortKey, $boardSeq, $now, $uid);
				$moved = $this->cardMapper->insert($moved);
				$newId = $moved->getId();

				// Labels: assign the mapped twins directly (no self-committing service
				// call inside our transaction).
				foreach ($sourceLabelIds as $labelId) {
					$twinId = $targetLabelByKey[$labelId] ?? null;
					if ($twinId !== null) {
						$this->cardLabelMapper->insertAssignment($newId, $twinId);
					}
				}

				// Checklist items, in display order, carrying their done state.
				$itemKey = $this->sortKeyService->initial();
				foreach ($sourceItems as $index => $item) {
					if ($index > 0) {
						$itemKey = $this->sortKeyService->after($itemKey);
					}
					$clone = new ChecklistItem();
					$clone->setCardId($newId);
					$clone->setTitle($item->getTitle());
					$clone->setDone($item->getDone());
					$clone->setSortKey($itemKey);
					$clone->setCreatedAt($now);
					$this->checklistItemMapper->insert($clone);
				}

				// Assignees / watchers that survived the READ filter.
				foreach ($carriedAssignees as $assigneeUid) {
					$this->cardAssigneeMapper->insertAssignment($newId, $assigneeUid);
				}
				foreach ($carriedWatchers as $watcherUid) {
					$sub = new Subscription();
					$sub->setSubscriber($watcherUid);
					$sub->setCardId($newId);
					$sub->setCommentThreadId(SubscriptionMapper::THREAD_CARD);
					$sub->setState(Subscription::STATE_SUBSCRIBED);
					$sub->setCreatedAt($now);
					$this->subscriptionMapper->insert($sub);
				}

				// Remove the card from the SOURCE: detach its children (same
				// self-healing as delete()) then soft-delete it.
				foreach ($this->cardMapper->findChildren($id) as $child) {
					$child->setParentCardId(null);
					$child->setLastModified($now);
					$this->cardMapper->update($child);
				}
				$source->setDeletedAt($now);
				$source->setLastModified($now);
				$this->cardMapper->update($source);

				// A change row on BOTH boards so delta-sync/ETag advance on each: a
				// CREATE on the target, a DELETE on the source.
				$this->changeNotifier->recordChange(
					$targetBoard->getId(),
					Change::ENTITY_CARD,
					$newId,
					Change::ACTION_CREATE,
					$uid,
					Change::VERB_CREATED,
				);
				$this->changeNotifier->recordChange(
					$sourceBoard->getId(),
					Change::ENTITY_CARD,
					$id,
					Change::ACTION_DELETE,
					$uid,
					Change::VERB_DELETED,
				);

				$this->db->commit();

				// Commit succeeded - broadcast BOTH boards and surface the move in the
				// Activity stream (best-effort, on the target where the card now lives).
				$this->changeNotifier->pushBoardChanged($targetBoard->getId());
				$this->changeNotifier->pushBoardChanged($sourceBoard->getId());
				$this->changeNotifier->publishCardActivity(
					$targetBoard->getId(),
					'card_moved',
					$moved,
					$uid,
				);

				return $moved;
			} catch (\OCP\DB\Exception $e) {
				$this->db->rollBack();
				if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				// A sort-key or board_seq collision on the target: re-derive both and
				// retry a few times before surfacing a retryable 409.
				if ($attempt >= self::MAX_CREATE_ATTEMPTS - 1) {
					throw new \OverflowException('card move key conflict (sort key or board_seq) after retries', 0, $e);
				}
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		}
	}

	/**
	 * Builds the target-board card entity for a cross-board move from the source's
	 * carried content. Estimate is board-scoped (dropped when the target scale
	 * rejects the token); the title crosses UNCHANGED (a move is not a copy, so no
	 * " (copy)" suffix). Never a template - a moved card is always an ordinary live
	 * card.
	 *
	 * Visibility (#3743) crosses over UNCHANGED - a private/internal card must
	 * never widen to 'public' by being moved. The creator SIDE is re-resolved
	 * as the MOVER's role on the TARGET board (the frozen-at-create rule, with
	 * the move as the create): the source side is meaningless there, and the
	 * mover - who could see the card to move it - keeps seeing it after.
	 */
	private function buildMovedCard(Card $source, Board $targetBoard, int $targetStackId, string $sortKey, int $boardSeq, int $now, string $moverUid): Card {
		$card = new Card();
		$card->setBoardId($targetBoard->getId());
		$card->setStackId($targetStackId);
		$card->setTitle($this->validateTitle($source->getTitle()));
		$card->setSortKey($sortKey);
		$card->setBoardSeq($boardSeq);
		$card->setDescription($source->getDescription());
		$card->setPriority($source->getPriority());
		$card->setType($source->getType());
		$card->setStartedAt($source->getStartedAt());
		$card->setDoneAt($source->getDoneAt());
		$card->setDuedate($source->getDuedate());
		$card->setStartDate($source->getStartDate());
		$card->setAllDay($source->getAllDay());
		$card->setCoverColor($source->getCoverColor());
		$estimate = $source->getEstimate();
		if ($estimate !== null && EstimateScale::allows($targetBoard->getEstimateScale(), $estimate)) {
			$card->setEstimate($estimate);
		}
		$card->setArchived($source->getArchived());
		$card->setOwner($source->getOwner());
		$card->setCreatedAt($now);
		$card->setLastModified($now);
		$card->setDeletedAt(0);
		$card->setIsTemplate(false);
		$card->setVisibility($source->getVisibility() ?? CardVisibilityScope::VISIBILITY_PUBLIC);
		$card->setCreatorRole($this->boardAccess->contextFor($targetBoard, $moverUid)->role);
		return $card;
	}

	/**
	 * Maps each of $sourceLabelIds to a target-board label id sharing the SAME
	 * title (case-insensitive, trimmed) AND color - the same title+color rule as
	 * {@see self::copyLabels()}. Source labels with no twin are simply absent from
	 * the map (they drop). Returns a source-label-id => target-label-id map.
	 *
	 * @param int[] $sourceLabelIds
	 * @return array<int,int>
	 */
	private function targetLabelIndex(array $sourceLabelIds, Board $targetBoard): array {
		if ($sourceLabelIds === []) {
			return [];
		}
		$targetByKey = [];
		foreach ($this->labelMapper->findByBoard($targetBoard->getId()) as $targetLabel) {
			$targetByKey[$this->labelKey($targetLabel)] ??= $targetLabel->getId();
		}
		$map = [];
		foreach ($sourceLabelIds as $labelId) {
			try {
				$sourceLabel = $this->labelMapper->find($labelId);
			} catch (DoesNotExistException) {
				continue;
			}
			$twinId = $targetByKey[$this->labelKey($sourceLabel)] ?? null;
			if ($twinId !== null) {
				$map[$labelId] = $twinId;
			}
		}
		return $map;
	}

	/**
	 * Keeps only the uids that can READ the target board - the leak guard for a
	 * cross-board move's assignees and watchers. A uid with no permission (or an
	 * unknown uid) is dropped so the moved card never references someone who cannot
	 * see where it landed. Mirrors the importer/participant read rule.
	 *
	 * @param string[] $uids
	 * @return string[]
	 */
	private function filterReadableOnTarget(array $uids, Board $targetBoard): array {
		$kept = [];
		foreach ($uids as $candidate) {
			if (($this->permissionService->getPermissions($targetBoard, $candidate) & PermissionService::PERMISSION_READ) !== 0) {
				$kept[] = $candidate;
			}
		}
		return $kept;
	}

	/**
	 * Creates a NEW card in $targetStackId pre-filled from a per-board template
	 * (#3409). The template must be a template card (`is_template = true`) on the
	 * SAME board as the target stack - templates are per-board only (no
	 * cross-board gallery). The new card is an ordinary, live card (never itself a
	 * template) and gets the template's title/description/labels/checklist/
	 * priority/type/estimate cloned via the SAME content clone {@see self::copy()}
	 * uses; comments, assignees, history, relations and parent/children are NOT
	 * cloned. EDIT on the board is required.
	 *
	 * @throws DoesNotExistException if the template, the board or the target stack does not exist or is deleted
	 * @throws NotPermittedException if the actor lacks EDIT on the board
	 * @throws InvalidInputException if the source is not a template, is on another board, or its title is invalid
	 * @throws \OverflowException if the appended sort key would overflow (target stack needs a rebalance)
	 */
	public function createFromTemplate(int $templateId, int $targetStackId, string $uid): Card {
		$template = $this->loadCard($templateId);
		$board = $this->loadBoard($template->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $template, $uid);

		if (!$template->getIsTemplate()) {
			throw new InvalidInputException('Card ' . $templateId . ' is not a template');
		}

		$targetStack = $this->loadStack($targetStackId);
		if ($targetStack->getBoardId() !== $template->getBoardId()) {
			throw new InvalidInputException('A template can only create a card on its own board');
		}

		// Fresh live card at the bottom of the target stack (fresh id + key + change
		// row + watcher fan-out). Same-board, so the content clone re-assigns labels
		// by id and keeps the estimate as-is. The template's visibility level rides
		// the INSERT (#3760) so the create-time fan-outs are filtered by the final
		// class, never an interim 'public' bearing a hidden template's title.
		$card = $this->create(
			$targetStackId,
			$this->validateTitle($template->getTitle()),
			$uid,
			null,
			null,
			$template->getVisibility() ?? CardVisibilityScope::VISIBILITY_PUBLIC,
		);
		$this->cloneContentInto($template, $card, $board, $board, $uid);

		return $card;
	}

	/**
	 * Clones a source card's CONTENT into an already-created shell $target -
	 * description, priority, type, status timestamps, estimate, labels and
	 * checklist items. Comments, assignees, history, relations and parent/children
	 * are deliberately NOT touched. Shared by {@see self::copy()} and
	 * {@see self::createFromTemplate()}. The `is_template` flag is never carried:
	 * a card made FROM a template (or a copy) is always an ordinary live card.
	 *
	 * Estimate is board-scoped: a source token the TARGET board's scale rejects is
	 * dropped. Labels are board-scoped too (see {@see self::copyLabels()}).
	 */
	private function cloneContentInto(Card $source, Card $target, Board $sourceBoard, Board $targetBoard, string $uid): void {
		$sameBoard = $sourceBoard->getId() === $targetBoard->getId();

		// Carry the scalar content the create() shell does not set. Estimate is
		// only kept when the TARGET board's scale accepts the token.
		$target->setDescription($source->getDescription());
		$target->setPriority($source->getPriority());
		$target->setType($source->getType());
		$target->setStartedAt($source->getStartedAt());
		$target->setDoneAt($source->getDoneAt());
		// Visibility is CONTENT too (#3743): a copy of (or a card spawned
		// from) a private/internal source must not silently widen to
		// 'public'. The clone keeps the source's LEVEL while owner and
		// creator side are the CLONER's (create() set both), so the actor
		// always sees what they just made.
		$target->setVisibility($source->getVisibility() ?? CardVisibilityScope::VISIBILITY_PUBLIC);
		$estimate = $source->getEstimate();
		if ($estimate !== null && EstimateScale::allows($targetBoard->getEstimateScale(), $estimate)) {
			$target->setEstimate($estimate);
		}
		$target->setLastModified(time());
		$this->cardMapper->update($target);

		// Labels - same-board re-assigns ids directly; cross-board maps by
		// title+color to the target board's labels (unmatched ones drop).
		$this->copyLabels($source, $target, $sameBoard, $targetBoard, $uid);

		// Checklist items, in display order (reuses ChecklistService::addItem,
		// which appends and writes its own change row). The done state rides the
		// same insert, so a done item is a single write - not add+toggle.
		foreach ($this->checklistItemMapper->findByCard($source->getId()) as $item) {
			$this->checklistService->addItem($target->getId(), $item->getTitle(), $uid, $item->getDone());
		}
	}

	/**
	 * Flags ($isTemplate true) or unflags ($isTemplate false) a card as a
	 * per-board template (#3409). EDIT on the card's board is required. A flagged
	 * card is excluded from the live board render (the board query filters it out)
	 * and offered in the per-board template picker. A no-op (already in the
	 * requested state) still writes a change row so the board ETag advances and
	 * clients drop/re-add the card from the live list. Marking is a pure flag flip
	 * - the card keeps its stack, sort key and content untouched.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board
	 */
	public function setTemplate(int $id, bool $isTemplate, string $uid): Card {
		$card = $this->loadCard($id);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $uid);

		$card->setIsTemplate($isTemplate);
		$card->setLastModified(time());

		// Atomic entity-write + change-row (#3579); push after commit.
		$card = $this->writeCardChange(
			$card->getBoardId(),
			$id,
			Change::ACTION_UPDATE,
			$uid,
			Change::VERB_UPDATED,
			fn (): Card => $this->cardMapper->update($card),
		);

		return $card;
	}

	/**
	 * Re-labels the copy. Same-board: re-assign every source label id directly.
	 * Cross-board: for each source label, find a target-board label with the same
	 * title (case-insensitive, trimmed) AND color and assign that; drop the rest.
	 */
	private function copyLabels(Card $source, Card $copy, bool $sameBoard, Board $targetBoard, string $uid): void {
		$sourceLabelIds = $this->cardLabelMapper->findLabelIdsByCard($source->getId());
		if ($sourceLabelIds === []) {
			return;
		}

		if ($sameBoard) {
			foreach ($sourceLabelIds as $labelId) {
				$this->labelService->assign($copy->getId(), $labelId, $uid);
			}
			return;
		}

		// Index the target board's labels by a normalized (title|color) key so a
		// source label maps to at most one target twin.
		$targetByKey = [];
		foreach ($this->labelMapper->findByBoard($targetBoard->getId()) as $targetLabel) {
			$targetByKey[$this->labelKey($targetLabel)] ??= $targetLabel->getId();
		}

		foreach ($sourceLabelIds as $labelId) {
			try {
				$sourceLabel = $this->labelMapper->find($labelId);
			} catch (DoesNotExistException) {
				continue;
			}
			$twinId = $targetByKey[$this->labelKey($sourceLabel)] ?? null;
			if ($twinId !== null) {
				$this->labelService->assign($copy->getId(), $twinId, $uid);
			}
			// else: no title+color twin on the target board - drop this label.
		}
	}

	/**
	 * Normalized identity of a label for cross-board matching: trimmed,
	 * lower-cased title joined with the color (labels compare equal only when
	 * BOTH match).
	 */
	private function labelKey(Label $label): string {
		return mb_strtolower(trim((string)$label->getTitle())) . '|' . (string)$label->getColor();
	}

	/**
	 * Derives the copy's title, suffixing " (copy)" while respecting the
	 * MAX_TITLE_LENGTH cap (the suffix wins - the base is truncated to fit).
	 */
	private function copyTitle(string $title): string {
		$suffix = ' (copy)';
		$title = trim($title);
		if (mb_strlen($title) + mb_strlen($suffix) > self::MAX_TITLE_LENGTH) {
			// Re-trim after truncation so we never render "…word (copy)" with a
			// doubled space at the seam.
			$title = rtrim(mb_substr($title, 0, self::MAX_TITLE_LENGTH - mb_strlen($suffix)));
		}
		return $title . $suffix;
	}

	/**
	 * Updates the given fields (null = leave unchanged). An empty duedate
	 * string clears the due date; an empty description string clears the
	 * description. done=true stamps done_at only once (idempotent),
	 * done=false clears it. Moving the due date re-arms the due-date reminders
	 * (#3545) by clearing their "already sent" markers; $dueReminderDayBefore
	 * toggles the optional "1 day before" reminder. An empty $coverColor string
	 * clears the cover colour; a non-empty one must be a bare 6-hex value (#3549).
	 * $type sets the card's built-in issue type (#3402): '' clears it (none), a
	 * non-empty value must be one of Card::TYPES.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board
	 * @throws InvalidInputException on invalid title, duedate, cover colour or type
	 */
	public function update(
		int $id,
		?string $title,
		?string $description,
		?string $duedate,
		?bool $done,
		?bool $archived,
		string $uid,
		?int $priority = null,
		?string $startDate = null,
		?string $status = null,
		?string $estimate = null,
		?bool $allDay = null,
		?bool $dueReminderDayBefore = null,
		?string $coverColor = null,
		?string $type = null,
		?string $visibility = null,
	): Card {
		$card = $this->loadCard($id);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $uid);

		if ($title !== null) {
			$card->setTitle($this->validateTitle($title));
		}
		if ($estimate !== null) {
			// '' clears the estimate; any other value must belong to the board's
			// scale (which rejects everything when the scale is 'none').
			if ($estimate === '') {
				$card->setEstimate(null);
			} elseif (EstimateScale::allows($board->getEstimateScale(), $estimate)) {
				$card->setEstimate($estimate);
			} else {
				throw new InvalidInputException('Estimate is not valid for this board\'s scale');
			}
		}
		if ($priority !== null) {
			if ($priority < Card::PRIORITY_NONE || $priority > Card::PRIORITY_URGENT) {
				throw new InvalidInputException('Priority must be between 0 and 4');
			}
			$card->setPriority($priority);
		}
		if ($coverColor !== null) {
			// Shared colour validation ('' clears the cover; a non-empty value must
			// be a bare 6-hex string, same as label/stack/board colours).
			$card->setCoverColor(ColorValidator::assertValid($coverColor));
		}
		if ($type !== null) {
			// '' clears the type (none); any other value must be a built-in type.
			// The built-in set is fixed (no custom-type editor - #3402's trap).
			if ($type !== Card::TYPE_NONE && !in_array($type, Card::TYPES, true)) {
				throw new InvalidInputException('Unknown card type: ' . $type);
			}
			$card->setType($type);
		}
		$descriptionChanged = false;
		if ($description !== null && $description !== $card->getDescription()) {
			$card->setDescription($description);
			$descriptionChanged = true;
		}
		if ($duedate !== null) {
			$parsedDue = $this->parseDuedate($duedate);
			$oldDue = $card->getDuedate();
			$card->setDuedate($parsedDue);
			// Clearing the due date also clears the all-day flag (no date to qualify).
			if ($parsedDue === null) {
				$card->setAllDay(false);
			}
			// Re-arm the due-date reminders (#3545) whenever the due date actually
			// moves: clear both "already sent" markers so the reminder cron fires
			// again for the new date. Compared by unix instant (how due dates are
			// stored/compared elsewhere); a no-op set of the same date leaves the
			// markers alone so it does not re-spam.
			if ($oldDue?->getTimestamp() !== $parsedDue?->getTimestamp()) {
				$card->setDueReminderSent(0);
				$card->setDayBeforeReminderSent(0);
			}
		}
		if ($dueReminderDayBefore !== null) {
			$card->setDueReminderDayBefore($dueReminderDayBefore);
		}
		if ($allDay !== null) {
			$card->setAllDay($allDay);
		}
		if ($startDate !== null) {
			// Same wire format + parsing as duedate; '' clears it.
			$card->setStartDate($this->parseDuedate($startDate));
		}
		if ($done !== null) {
			if ($done) {
				if ($card->getDoneAt() === 0) {
					$card->setDoneAt(time());
				}
			} else {
				$card->setDoneAt(0);
			}
		}
		if ($archived !== null) {
			$card->setArchived($archived);
		}
		if ($status !== null) {
			$this->applyStatus($card, $status);
		}
		if ($visibility !== null) {
			$this->applyVisibility($card, $board, $visibility, $uid);
		}

		$now = time();
		$card->setLastModified($now);

		// Atomic entity-write + change-row (#3579): the UPDATE and its delta-sync
		// row commit together (or roll back together); the realtime push fires only
		// after commit. Side effects below (mentions, parent auto-complete) run
		// after the card + its change row have landed.
		$card = $this->writeCardChange(
			$card->getBoardId(),
			$id,
			Change::ACTION_UPDATE,
			$uid,
			Change::VERB_UPDATED,
			fn (): Card => $this->cardMapper->update($card),
		);

		// A new @mention in the description pings + auto-subscribes readable-board
		// participants (only when the description actually changed).
		if ($descriptionChanged) {
			$this->mentionService->handleMentions($card, $board, (string)$description, $uid);
		}

		// Completing (or archiving) the last open child auto-completes the parent.
		$this->maybeCompleteParent($card, $uid);

		return $card;
	}

	/**
	 * Soft-deletes the card (sets deleted_at). Any children are first detached
	 * (parent_card_id cleared) so no live card is left pointing at a hidden
	 * parent - the one-level hierarchy stays self-healing. The parent's DELETE
	 * change row bumps the board ETag, so clients refetch and see the detached
	 * children; the per-child clears ride along without their own change rows.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board
	 */
	public function delete(int $id, string $uid): void {
		$card = $this->loadCard($id);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $uid);

		$now = time();

		// Detach children, soft-delete the card and append the DELETE change row
		// atomically (#3579): the child clears, the delete and its delta-sync row
		// commit together, so a client never sees a detached child without the
		// parent's DELETE row (or vice versa). Push after commit.
		$this->writeCardChange(
			$card->getBoardId(),
			$id,
			Change::ACTION_DELETE,
			$uid,
			Change::VERB_DELETED,
			function () use ($id, $card, $now): Card {
				foreach ($this->cardMapper->findChildren($id) as $child) {
					$child->setParentCardId(null);
					$child->setLastModified($now);
					$this->cardMapper->update($child);
				}
				$card->setDeletedAt($now);
				$card->setLastModified($now);
				return $this->cardMapper->update($card);
			},
		);
	}

	/**
	 * Moves the card inside its board: into $targetStackId, directly after
	 * $afterCardId, or to the top of the target stack when $afterCardId is
	 * null. The transaction makes the card update and its change row atomic
	 * (rollback on failure).
	 *
	 * Concurrent moves are NOT serialized: two moves into the same gap can each
	 * read the same neighbours under READ COMMITTED and derive the same key.
	 * The composite unique index on (stack_id, sort_key, deleted_at) rejects the
	 * loser's UPDATE, so we re-read the neighbours and re-derive once; if it
	 * still collides, a retryable 409 (\OverflowException → rebalance_required)
	 * is surfaced rather than persisting a duplicate key.
	 *
	 * @throws DoesNotExistException if the card, its board or the target stack does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board, or the review gate blocks a review-role → done-role move with unapproved reviews
	 * @throws InvalidInputException if the target stack is on another board or $afterCardId is unusable
	 * @throws \OverflowException if the new sort key would overflow (stack needs a rebalance) or keeps colliding after one retry
	 */
	public function move(int $id, int $targetStackId, ?int $afterCardId, string $uid): Card {
		$card = $this->loadCard($id);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $uid);

		$targetStack = $this->loadStack($targetStackId);
		if ($targetStack->getBoardId() !== $card->getBoardId()) {
			throw new InvalidInputException('Cannot move a card to a stack on another board');
		}

		// Source stack role for the done-automation. A move within the same
		// stack keeps the target's role - done state then stays put.
		$sourceStack = $targetStackId === $card->getStackId()
			? $targetStack
			: $this->stackMapper->find($card->getStackId());

		// Review gate: a card leaving a review-role stack for a done-role stack
		// may not be marked done while any requested review is still unapproved.
		// A board with no review-role stack never trips this - the gate is
		// naturally opt-in via stack roles. Pure precondition, checked once
		// before the write/retry loop so it fails fast without a DB write.
		if ($sourceStack->getRole() === Stack::ROLE_REVIEW
			&& $targetStack->getRole() === Stack::ROLE_DONE
			&& $this->cardReviewMapper->hasUnapprovedReviews($id)) {
			throw new NotPermittedException('All requested reviews must be approved before this card can be marked done');
		}

		for ($attempt = 0; ; $attempt++) {
			$afterCard = $afterCardId === null
				? null
				: $this->loadAfterCard($afterCardId, $targetStackId, $id, $board, $uid);
			try {
				$moved = $this->persistMove($card, $targetStackId, $afterCard, $sourceStack, $targetStack, $uid);
				// Moving the last open child into a done-role stack (which stamps
				// it done) auto-completes the parent.
				$this->maybeCompleteParent($moved, $uid);
				// A move into a DIFFERENT stack fires the board's card_entered_role
				// automation rules (best-effort; runs after the move is committed).
				if ($targetStackId !== $sourceStack->getId()) {
					$this->automationService->runCardEnteredRole($moved, $targetStack->getRole(), $uid);
				}
				return $moved;
			} catch (\OCP\DB\Exception $e) {
				if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				if ($attempt >= 1) {
					throw new \OverflowException('sort key conflict on move after retry', 0, $e);
				}
				// Discard the mutations from the rolled-back attempt before retrying.
				$card = $this->loadCard($id);
			}
		}
	}

	/**
	 * Persists a move inside a transaction: derive the key, update the single
	 * card row and append the change row atomically (rollback on failure). A
	 * unique-constraint violation from a concurrent move into the same gap
	 * propagates to {@see self::move()} for a re-derive/retry.
	 *
	 * @throws \OCP\DB\Exception on a DB error (including the unique-key race)
	 * @throws \OverflowException if the derived key would overflow - rebalance needed
	 */
	private function persistMove(
		Card $card,
		int $targetStackId,
		?Card $afterCard,
		Stack $sourceStack,
		Stack $targetStack,
		string $uid,
	): Card {
		$this->db->beginTransaction();
		try {
			$sortKey = $this->deriveMoveKey($targetStackId, $afterCard);

			$now = time();
			// Whether THIS move flips the card into the done state (was open before,
			// lands in a done-role column). Drives the "marked done" Activity subject
			// below - a plain reshuffle or a move between open columns is "moved".
			$wasDone = ($card->getDoneAt() ?? 0) > 0;
			$card->setStackId($targetStackId);
			$card->setSortKey($sortKey);
			$card->setLastModified($now);
			$this->applyDoneAutomation($card, $sourceStack, $targetStack, $now);
			$becameDone = !$wasDone && ($card->getDoneAt() ?? 0) > 0;
			$card = $this->cardMapper->update($card);

			// Write the change row inside the transaction (delta-sync source of
			// truth), but DEFER the realtime push until after commit - otherwise a
			// client could refetch pre-commit state, or get an event for a move
			// that the unique-key retry then rolls back.
			$this->changeNotifier->recordChange(
				$card->getBoardId(),
				Change::ENTITY_CARD,
				$card->getId(),
				Change::ACTION_MOVE,
				$uid,
				Change::VERB_MOVED,
			);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Commit succeeded - now it is safe to broadcast the move.
		$this->changeNotifier->pushBoardChanged($card->getBoardId());

		// Surface the move in the Nextcloud Activity stream (best-effort). A move
		// that completes the card reads as "marked done", any other move as "moved".
		$this->changeNotifier->publishCardActivity(
			$card->getBoardId(),
			$becameDone ? 'card_done' : 'card_moved',
			$card,
			$uid,
		);

		return $card;
	}

	/**
	 * Sets ($parentCardId given) or clears ($parentCardId null) the card's
	 * parent. Requires EDIT on the card's board. The hierarchy is ONE level and
	 * same-board only:
	 *   - the parent must be on the same board and not the card itself;
	 *   - the parent must not itself have a parent (no grandparents);
	 *   - a card that already has children may not become a child.
	 * A no-op (parent already as requested) writes no change row.
	 *
	 * The checks and the write are not serialized (like {@see self::move()}):
	 * two concurrent setParent calls could interleave to build a 2-level chain
	 * (set A's parent = B while B's parent is set = C, each seeing the other's
	 * pre-state). Accepted for now - cosmetic, a subsequent edit repairs it, no
	 * data loss; a DB-level guard is the planned mitigation.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board
	 * @throws InvalidInputException if the parent is invalid (self, other board, deleted, or the one-level rule is violated)
	 */
	public function setParent(int $id, ?int $parentCardId, string $uid): Card {
		$card = $this->loadCard($id);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $uid);

		if ($parentCardId === null) {
			if ($card->getParentCardId() === null) {
				return $card;
			}
			$card->setParentCardId(null);
		} else {
			if ($parentCardId === $id) {
				throw new InvalidInputException('A card cannot be its own parent');
			}
			$parent = $this->loadParentCard($parentCardId);
			// A parent the actor cannot SEE reads as missing (#3743) - same
			// masking as loadParentCard's absence case, no existence oracle.
			if (!$this->visibilityGuard->isVisible($board, $parent, $uid)) {
				throw new InvalidInputException('Parent card ' . $parentCardId . ' does not exist');
			}
			if ($parent->getBoardId() !== $card->getBoardId()) {
				throw new InvalidInputException('Parent card must be on the same board');
			}
			if ($parent->getParentCardId() !== null) {
				throw new InvalidInputException('Cards can only be nested one level deep');
			}
			if ($this->cardMapper->hasChildren($id)) {
				throw new InvalidInputException('A card that has children cannot become a child');
			}
			if ($card->getParentCardId() === $parentCardId) {
				return $card;
			}
			$card->setParentCardId($parentCardId);
		}

		$card->setLastModified(time());

		// Atomic entity-write + change-row (#3579); push after commit.
		$card = $this->writeCardChange(
			$card->getBoardId(),
			$id,
			Change::ACTION_UPDATE,
			$uid,
			Change::VERB_UPDATED,
			fn (): Card => $this->cardMapper->update($card),
		);

		return $card;
	}

	/**
	 * Applies a visibility change (#3743). The value must be one of the three
	 * known levels, and only the card's CREATOR (owner) or a board MANAGER may
	 * narrow/widen it - a regular member must not flip someone else's card
	 * across the fence. The creator side (`creator_role`) stays FROZEN: a
	 * manager marking another member's card 'internal' scopes it to the
	 * CREATOR's side, never their own.
	 *
	 * @throws InvalidInputException on an unknown visibility value
	 * @throws NotPermittedException if the actor is neither creator nor manager
	 */
	private function applyVisibility(Card $card, Board $board, string $visibility, string $uid): void {
		if (!in_array($visibility, CardVisibilityScope::VISIBILITIES, true)) {
			throw new InvalidInputException('Unknown card visibility: ' . $visibility);
		}
		if ($card->getOwner() !== $uid && !$this->boardAccess->contextFor($board, $uid)->isManager) {
			throw new NotPermittedException('Only the card creator or a board manager may change its visibility');
		}
		$card->setVisibility($visibility);
	}

	/**
	 * Loads the prospective parent card, mapping absence/deletion to invalid
	 * input (the client picked a card that is gone) rather than a 404 on the
	 * card being edited.
	 *
	 * @throws InvalidInputException
	 */
	private function loadParentCard(int $parentCardId): Card {
		try {
			$parent = $this->cardMapper->find($parentCardId);
		} catch (DoesNotExistException) {
			throw new InvalidInputException('Parent card ' . $parentCardId . ' does not exist');
		}
		if ($parent->getDeletedAt() > 0) {
			throw new InvalidInputException('Parent card ' . $parentCardId . ' does not exist');
		}
		return $parent;
	}

	/**
	 * Status-automation for a move: a column's role IS its status, so dragging a
	 * card into a column adopts that column's lifecycle stage -
	 *   Backlog / To do → Not started (both timestamps cleared),
	 *   In progress / Review → In progress (started stamped, done cleared),
	 *   Done → Done (done stamped once, keeping an already-done card's time).
	 * A role-less column (ROLE_NONE) carries no status and leaves the card as-is,
	 * and a reorder within the same column never rewrites the status. The
	 * timestamp changes ride the same card UPDATE as the move, so no extra change
	 * row is written.
	 */
	private function applyDoneAutomation(Card $card, Stack $sourceStack, Stack $targetStack, int $now): void {
		// A reorder within the same column must never rewrite the card's status -
		// only entering a DIFFERENT column applies that column's role.
		if ($sourceStack->getId() === $targetStack->getId()) {
			return;
		}

		switch ($targetStack->getRole()) {
			case Stack::ROLE_BACKLOG:
			case Stack::ROLE_TODO:
				// Not started - clear both timestamps.
				$card->setStartedAt(0);
				$card->setDoneAt(0);
				break;
			case Stack::ROLE_IN_PROGRESS:
			case Stack::ROLE_REVIEW:
				// In progress - started, not done.
				$card->setDoneAt(0);
				if (($card->getStartedAt() ?? 0) === 0) {
					$card->setStartedAt($now);
				}
				break;
			case Stack::ROLE_DONE:
				// Done - stamp once (an already-done card keeps its time).
				if ($card->getDoneAt() === 0) {
					$card->setDoneAt($now);
				}
				break;
			case Stack::ROLE_NONE:
			default:
				// No associated status - leave the card's status as-is.
				break;
		}
	}

	/**
	 * Sets the card's derived status directly (the card-view control). Status is
	 * two timestamps: done_at (Done) and started_at (In progress) - this is the
	 * one place a card can be moved BACKWARD (e.g. Done → In progress), unlike
	 * the forward-only move automation.
	 *
	 * @throws InvalidInputException on an unknown status
	 */
	private function applyStatus(Card $card, string $status): void {
		$now = time();
		switch ($status) {
			case 'done':
				if ($card->getDoneAt() === 0) {
					$card->setDoneAt($now);
				}
				break;
			case 'in_progress':
				$card->setDoneAt(0);
				if (($card->getStartedAt() ?? 0) === 0) {
					$card->setStartedAt($now);
				}
				break;
			case 'not_started':
				$card->setDoneAt(0);
				$card->setStartedAt(0);
				break;
			default:
				throw new InvalidInputException('Unknown status: ' . $status);
		}
	}

	/**
	 * Auto-completes a parent card once ALL of its children are resolved (done
	 * or archived) - the "all children done → parent done" workflow. Called from
	 * a CHILD's update()/move() after it persists; a card with no parent is a
	 * fast no-op (the common case). Forward-only for v1: it never RE-OPENS a
	 * parent (a parent a human marked done is left alone), and since the
	 * hierarchy is one level (a parent has no parent) stamping it done cannot
	 * cascade - no loop guard needed.
	 */
	private function maybeCompleteParent(Card $child, string $uid): void {
		$parentId = $child->getParentCardId();
		if ($parentId === null) {
			return;
		}
		try {
			$parent = $this->cardMapper->find($parentId);
		} catch (DoesNotExistException) {
			return;
		}
		if ($parent->getDeletedAt() > 0 || $parent->getDoneAt() > 0) {
			return;
		}

		foreach ($this->cardMapper->findChildren($parentId) as $sibling) {
			if ($sibling->getDoneAt() === 0 && !$sibling->getArchived()) {
				return; // an unresolved child remains
			}
		}

		$now = time();
		$parent->setDoneAt($now);
		$parent->setLastModified($now);

		// Atomic entity-write + change-row (#3579); push after commit.
		$this->writeCardChange(
			$parent->getBoardId(),
			$parentId,
			Change::ACTION_UPDATE,
			$uid,
			Change::VERB_UPDATED,
			fn (): Card => $this->cardMapper->update($parent),
		);
	}

	/**
	 * Runs a single-card entity write and its `kanso_changes` row atomically
	 * (#3579): the mapper write in $write and the change-row insert commit
	 * together, or roll back together on any failure - so no card mutation is ever
	 * visible without its delta-sync row, and no delta row points at a write that
	 * was rolled back. The realtime push is emitted only AFTER commit (never inside
	 * the transaction - a pre-commit push could make a client refetch state a
	 * rollback then discards). Mirrors {@see self::persistMove()}'s pattern for the
	 * non-move single-entity mutators (create/update/delete/setParent/…).
	 *
	 * @param callable():Card $write performs the entity write and returns the row
	 * @throws \Throwable rethrows whatever the write or the change-row insert throws
	 */
	private function writeCardChange(int $boardId, int $entityId, int $action, ?string $uid, ?int $verb, callable $write): Card {
		$this->db->beginTransaction();
		try {
			$card = $write();
			$this->changeNotifier->recordChange(
				$boardId,
				Change::ENTITY_CARD,
				$entityId,
				$action,
				$uid,
				$verb,
			);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Commit succeeded - now it is safe to broadcast.
		$this->changeNotifier->pushBoardChanged($boardId);

		return $card;
	}

	/**
	 * Rewrites every live card in $stackId to a fresh, short, evenly-spaced
	 * `sort_key`, preserving the current display order (#3379). This is the
	 * recovery for the pathological case the move endpoint reports as 409
	 * `rebalance_required`: repeated bisection between the same neighbours grows
	 * a fractional key past {@see SortKeyService::MAX_KEY_LENGTH}, at which point
	 * no new key fits between them. A rebalance resets the whole stack to
	 * two-character keys, restoring generous gaps so subsequent between()/after()
	 * inserts no longer overflow.
	 *
	 * Concurrency: the stack's rows are read with SELECT ... FOR UPDATE inside a
	 * single transaction (no new global lock - it matches the app's
	 * READ-COMMITTED move posture used by {@see self::persistMove()}, just
	 * pessimistically for this rare maintenance path), so a concurrent move
	 * blocks on the same rows until the rebalance commits.
	 *
	 * The (stack_id, sort_key, deleted_at) unique index forbids two live rows in
	 * a stack sharing a key even transiently, so the rewrite runs in two passes:
	 * pass 1 parks every row at a distinct three-character temporary key that is
	 * disjoint from all current keys (and, by length, from the two-character
	 * finals), pass 2 writes the final evenly-spaced keys. A single stack-level
	 * MOVE change row (delta-sync) is recorded inside the transaction and the
	 * board push emitted after commit, mirroring persistMove().
	 *
	 * @return int the number of cards rewritten (0 if the stack was empty)
	 * @throws DoesNotExistException if the stack does not exist or is deleted
	 * @throws \OCP\DB\Exception on a DB error
	 * @throws \RuntimeException if the stack is too large for the temporary
	 *                           three-character key grid (pathological only)
	 */
	public function rebalanceStack(int $stackId): int {
		$stack = $this->loadStack($stackId);
		$boardId = $stack->getBoardId();

		$this->db->beginTransaction();
		try {
			$cards = $this->cardMapper->findByStackForUpdate($stackId);
			$count = count($cards);
			if ($count === 0) {
				$this->db->commit();
				return 0;
			}

			$freshKeys = $this->sortKeyService->evenlySpaced($count);
			$currentKeys = [];
			foreach ($cards as $card) {
				$currentKeys[$card->getSortKey()] = true;
			}
			$tempKeys = $this->temporaryKeys($currentKeys, $count);

			// Pass 1: park each row at a distinct temporary key that is disjoint
			// from every current key, so no UPDATE collides with a
			// not-yet-rewritten row.
			foreach ($cards as $index => $card) {
				$this->cardMapper->updateSortKeyById($card->getId(), $tempKeys[$index]);
			}
			// Pass 2: write the final evenly-spaced keys, order preserved. The
			// finals are the short two-character grid and the temp band the
			// three-character grid, so the two are disjoint and this pass never
			// collides either.
			foreach ($cards as $index => $card) {
				$this->cardMapper->updateSortKeyById($card->getId(), $freshKeys[$index]);
			}

			// One stack-level MOVE change row is enough for delta-sync clients to
			// know the stack reordered and refetch it - cheaper than one row per
			// card.
			$this->changeNotifier->recordChange(
				$boardId,
				Change::ENTITY_STACK,
				$stackId,
				Change::ACTION_MOVE,
				null,
				Change::VERB_MOVED,
			);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Commit succeeded - now it is safe to broadcast the reordering.
		$this->changeNotifier->pushBoardChanged($boardId);

		return $count;
	}

	/**
	 * Rebalances every stack on $boardId (the `occ kanso:rebalance --board`
	 * path). Each stack is rebalanced in its own transaction, so one empty or
	 * failing stack does not roll back the others.
	 *
	 * @return array<int,int> map of stack id => number of cards rewritten
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws \OCP\DB\Exception on a DB error
	 */
	public function rebalanceBoard(int $boardId): array {
		$board = $this->loadBoard($boardId);
		$result = [];
		foreach ($this->stackMapper->findByBoard($board->getId()) as $stack) {
			$result[$stack->getId()] = $this->rebalanceStack($stack->getId());
		}
		return $result;
	}

	/**
	 * $count distinct temporary sort keys for a rebalance's pass 1, drawn from
	 * the three-character base-36 grid and disjoint from every key currently in
	 * the stack. Three-character keys are always length-safe (well under
	 * MAX_KEY_LENGTH) and, by length alone, disjoint from the two-character final
	 * keys - so parking here never collides with a not-yet-rewritten row and the
	 * final pass never collides with the parking band. Any grid slot already used
	 * by a current key is skipped; with 36^3 = 46656 slots this always yields
	 * enough keys for a realistic stack.
	 *
	 * @param array<string,true> $currentKeys the stack's current keys as a set
	 * @return list<string>
	 * @throws \RuntimeException if the three-character grid cannot supply $count
	 *                           free keys (only for a pathologically huge stack)
	 */
	private function temporaryKeys(array $currentKeys, int $count): array {
		$alphabet = SortKeyService::ALPHABET;
		$base = SortKeyService::BASE;
		$keys = [];
		for ($slot = 0; $slot < $base * $base * $base && count($keys) < $count; $slot++) {
			$key = $alphabet[intdiv($slot, $base * $base) % $base]
				. $alphabet[intdiv($slot, $base) % $base]
				. $alphabet[$slot % $base];
			if (!isset($currentKeys[$key])) {
				$keys[] = $key;
			}
		}
		if (count($keys) < $count) {
			throw new \RuntimeException('Stack too large to rebalance: exhausted the temporary sort-key grid');
		}
		return $keys;
	}

	/**
	 * New sort key for a card landing in $targetStackId after $afterCard
	 * (null = top of the stack). The moved card may itself be one of the
	 * neighbours - its still-current key then bounds the result, which is
	 * fine: the derived key remains strictly ordered.
	 *
	 * @throws \OverflowException if the key would overflow - rebalance needed
	 */
	private function deriveMoveKey(int $targetStackId, ?Card $afterCard): string {
		if ($afterCard !== null) {
			$next = $this->cardMapper->findNextInStack($targetStackId, $afterCard->getSortKey());
			return $next === null
				? $this->sortKeyService->after($afterCard->getSortKey())
				: $this->sortKeyService->between($afterCard->getSortKey(), $next->getSortKey());
		}
		$first = $this->cardMapper->findFirstInStack($targetStackId);
		return $first === null
			? $this->sortKeyService->initial()
			: $this->sortKeyService->before($first->getSortKey());
	}

	/**
	 * Loads and validates the move anchor. Any unusable anchor (missing,
	 * deleted, wrong stack, the moved card itself) is invalid input - the
	 * client's picture of the stack is stale, not the moved card's fault.
	 *
	 * @throws InvalidInputException
	 */
	private function loadAfterCard(int $afterCardId, int $targetStackId, int $movedCardId, Board $board, string $uid): Card {
		if ($afterCardId === $movedCardId) {
			throw new InvalidInputException('afterCardId must not be the moved card itself');
		}
		try {
			$afterCard = $this->cardMapper->find($afterCardId);
		} catch (DoesNotExistException) {
			throw new InvalidInputException('Card ' . $afterCardId . ' does not exist');
		}
		if ($afterCard->getDeletedAt() > 0) {
			throw new InvalidInputException('Card ' . $afterCardId . ' does not exist');
		}
		// An anchor the mover cannot SEE reads as missing (#3743) - probing
		// stack positions with foreign ids must not confirm a hidden card.
		if (!$this->visibilityGuard->isVisible($board, $afterCard, $uid)) {
			throw new InvalidInputException('Card ' . $afterCardId . ' does not exist');
		}
		if ($afterCard->getStackId() !== $targetStackId) {
			throw new InvalidInputException('Card ' . $afterCardId . ' is not in the target stack');
		}
		return $afterCard;
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

	/**
	 * @throws InvalidInputException
	 */
	private function validateTitle(string $title): string {
		$title = trim($title);
		if ($title === '') {
			throw new InvalidInputException('Title must not be empty');
		}
		if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
			throw new InvalidInputException(
				'Title must not exceed ' . self::MAX_TITLE_LENGTH . ' characters'
			);
		}
		return $title;
	}

	/**
	 * Strict ISO 8601 due dates, normalized to UTC. The empty string clears
	 * the due date. Two accepted shapes: RFC 3339 without fractional seconds
	 * (2026-07-22T12:00:00Z / +02:00) and with milliseconds
	 * (2026-07-22T12:00:00.000Z) - the latter is what JS Date.toISOString()
	 * produces.
	 *
	 * @throws InvalidInputException on any other shape
	 */
	private function parseDuedate(string $duedate): ?\DateTime {
		if ($duedate === '') {
			return null;
		}
		$parsed = \DateTime::createFromFormat(\DateTimeInterface::ATOM, $duedate)
			?: \DateTime::createFromFormat('Y-m-d\TH:i:s.vP', $duedate);
		// createFromFormat rolls over out-of-range components (2026-02-30
		// becomes March 2nd) and only records it in getLastErrors - reject
		// those too, or clients get a silently wrong date back.
		$errors = \DateTime::getLastErrors();
		if ($parsed === false
			|| ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
			throw new InvalidInputException(
				'Due date must be an ISO 8601 datetime like 2026-07-22T12:00:00Z'
			);
		}
		$parsed->setTimezone(new \DateTimeZone('UTC'));
		return $parsed;
	}
}
