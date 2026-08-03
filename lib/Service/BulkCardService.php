<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\CardMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Bulk (multi-select) card actions (#3523). Deliberately NOT a query-builder /
 * bulk-mutation engine: it applies ONE action from a FIXED set to a list of
 * card ids by looping the EXISTING per-card services once per card. That keeps
 * every guarantee the single-card path already provides - the board ACL is
 * asserted per card inside the service, each mutation appends its own
 * `kanso_changes` row (delta-sync / ETag / realtime stay correct), and a move
 * still travels the fractional sort-key path (a single-row UPDATE, no bulk
 * renumber). There is NO new bulk SQL statement anywhere in this class.
 *
 * A card the caller cannot edit (or that no longer exists) is SKIPPED, not
 * fatal: the loop records it in the per-card summary and carries on, so a
 * partially-permitted selection still applies to the cards it may touch.
 */
class BulkCardService {
	/** Fixed action set - one of these per request, nothing else. */
	public const ACTION_MOVE = 'move';
	public const ACTION_ADD_LABEL = 'add_label';
	public const ACTION_REMOVE_LABEL = 'remove_label';
	public const ACTION_ASSIGN_USER = 'assign_user';
	public const ACTION_SET_DUE_DATE = 'set_due_date';
	public const ACTION_ARCHIVE = 'archive';
	public const ACTION_DELETE = 'delete';

	public const ACTIONS = [
		self::ACTION_MOVE,
		self::ACTION_ADD_LABEL,
		self::ACTION_REMOVE_LABEL,
		self::ACTION_ASSIGN_USER,
		self::ACTION_SET_DUE_DATE,
		self::ACTION_ARCHIVE,
		self::ACTION_DELETE,
	];

	/**
	 * Upper bound on the selection size per request. Mirrors
	 * {@see ArchiveService::MAX_PER_SWEEP} - a single bulk request must not loop
	 * unboundedly. A larger selection is a 400 (the client should chunk it).
	 */
	public const MAX_CARDS = 100;

	public function __construct(
		private CardService $cardService,
		private LabelService $labelService,
		private AssigneeService $assigneeService,
		private CardMapper $cardMapper,
	) {
	}

	/**
	 * Applies $action to every card in $cardIds, per card, reusing the existing
	 * per-card services. Returns a per-card summary:
	 *   [ 'ok' => int[], 'skipped' => list<array{id:int, reason:string}> ]
	 * where a skip is a card the caller may not edit, a card that no longer
	 * exists, or a card the action's own validation rejected (e.g. a label from
	 * another board) - none of which fail the whole request.
	 *
	 * The action parameters live in $params, validated up-front (a malformed
	 * request is a 400 for the WHOLE call - it is the caller's mistake, not a
	 * per-card condition):
	 *   - move:          targetStackId (int, > 0)
	 *   - add_label:     labelId (int, > 0)
	 *   - remove_label:  labelId (int, > 0)
	 *   - assign_user:   userId (non-empty string)
	 *   - set_due_date:  duedate (string; '' clears it - same wire format as update())
	 *   - archive:       (none)
	 *   - delete:        (none)
	 *
	 * @param int[] $cardIds
	 * @param array<string, mixed> $params
	 * @return array{ok: int[], skipped: list<array{id: int, reason: string}>}
	 * @throws InvalidInputException on an unknown action, malformed params, an
	 *                               empty selection or one exceeding MAX_CARDS
	 */
	public function apply(array $cardIds, string $action, array $params, string $uid): array {
		if (!in_array($action, self::ACTIONS, true)) {
			throw new InvalidInputException('Unknown bulk action: ' . $action);
		}

		// Normalize, de-duplicate and validate the id list up-front. A malformed
		// or oversized list is the caller's error → 400 for the whole request.
		$ids = [];
		foreach ($cardIds as $raw) {
			$id = (int)$raw;
			if ($id > 0) {
				$ids[$id] = true;
			}
		}
		$ids = array_keys($ids);
		if ($ids === []) {
			throw new InvalidInputException('No cards selected');
		}
		if (count($ids) > self::MAX_CARDS) {
			throw new InvalidInputException('Too many cards selected (max ' . self::MAX_CARDS . ')');
		}

		// Build the per-card operation once (also validates $params → 400 for the
		// whole request when a required parameter is missing/invalid). Each op is a
		// closure over the resolved params that calls exactly one existing service
		// method for a single card id.
		$op = $this->resolveOperation($action, $params, $uid);

		$ok = [];
		$skipped = [];
		foreach ($ids as $id) {
			try {
				$op($id);
				$ok[] = $id;
			} catch (NotPermittedException) {
				// The caller may not edit THIS card's board - skip, don't fail.
				$skipped[] = ['id' => $id, 'reason' => 'forbidden'];
			} catch (DoesNotExistException) {
				// Stale selection: the card (or its board/stack) is gone - skip.
				$skipped[] = ['id' => $id, 'reason' => 'not_found'];
			} catch (InvalidInputException $e) {
				// A per-card rejection from the underlying service (e.g. a label
				// from another board, a review gate on a move). Record and move on
				// rather than aborting the rest of the selection.
				$skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
			} catch (\OverflowException) {
				// A move whose target stack needs a rebalance - skip this card so
				// the rest still apply; the client can retry it individually.
				$skipped[] = ['id' => $id, 'reason' => 'rebalance_required'];
			}
		}

		return ['ok' => $ok, 'skipped' => $skipped];
	}

	/**
	 * Resolves the fixed action + its params into a single-argument closure
	 * `fn(int $cardId): void` that calls exactly one existing per-card service
	 * method. Parameter validation happens here (once), so a malformed request
	 * surfaces as a whole-request 400 before the loop starts.
	 *
	 * @param array<string, mixed> $params
	 * @return callable(int): void
	 * @throws InvalidInputException on missing/invalid action params
	 */
	private function resolveOperation(string $action, array $params, string $uid): callable {
		switch ($action) {
			case self::ACTION_MOVE:
				$targetStackId = (int)($params['targetStackId'] ?? 0);
				if ($targetStackId <= 0) {
					throw new InvalidInputException('A target stack is required');
				}
				// Append to the END of the target stack: resolve the current tail per
				// card (a prior card in the same bulk move becomes the new tail, so the
				// selection lands in order). null afterCardId would put it on TOP.
				return function (int $cardId) use ($targetStackId, $uid): void {
					$last = $this->cardMapper->findLastInStack($targetStackId);
					$afterCardId = ($last !== null && $last->getId() !== $cardId) ? $last->getId() : null;
					$this->cardService->move($cardId, $targetStackId, $afterCardId, $uid);
				};

			case self::ACTION_ADD_LABEL:
				$labelId = (int)($params['labelId'] ?? 0);
				if ($labelId <= 0) {
					throw new InvalidInputException('A label is required');
				}
				return function (int $cardId) use ($labelId, $uid): void {
					$this->labelService->assign($cardId, $labelId, $uid);
				};

			case self::ACTION_REMOVE_LABEL:
				$labelId = (int)($params['labelId'] ?? 0);
				if ($labelId <= 0) {
					throw new InvalidInputException('A label is required');
				}
				return function (int $cardId) use ($labelId, $uid): void {
					$this->labelService->unassign($cardId, $labelId, $uid);
				};

			case self::ACTION_ASSIGN_USER:
				$userId = trim((string)($params['userId'] ?? ''));
				if ($userId === '') {
					throw new InvalidInputException('A user is required');
				}
				return function (int $cardId) use ($userId, $uid): void {
					$this->assigneeService->assign($cardId, $userId, $uid);
				};

			case self::ACTION_SET_DUE_DATE:
				// '' clears the due date; any other value is parsed/validated per card
				// by CardService::update (a bad shape becomes a per-card skip, which is
				// fine - the value is the same for every card, so it either fits all or
				// none, but keeping it per-card avoids a second date parser here).
				$duedate = (string)($params['duedate'] ?? '');
				return function (int $cardId) use ($duedate, $uid): void {
					$this->cardService->update($cardId, null, null, $duedate, null, null, $uid);
				};

			case self::ACTION_ARCHIVE:
				return function (int $cardId) use ($uid): void {
					$this->cardService->update($cardId, null, null, null, null, true, $uid);
				};

			case self::ACTION_DELETE:
				return function (int $cardId) use ($uid): void {
					$this->cardService->delete($cardId, $uid);
				};

			default:
				// Unreachable - apply() already validated the action.
				throw new InvalidInputException('Unknown bulk action: ' . $action);
		}
	}
}
