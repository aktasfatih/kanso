<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

/**
 * Card CRUD and moves. Every mutation appends a row to the `kanso_changes`
 * log in the same flow (see BoardService). Ordering uses fractional sort
 * keys: creation appends to the bottom of the stack and a move rewrites a
 * single card row — no sibling renumbering, ever.
 */
class CardService {
	private const MAX_TITLE_LENGTH = 100;

	public function __construct(
		private CardMapper $cardMapper,
		private StackMapper $stackMapper,
		private BoardMapper $boardMapper,
		private ChangeNotifier $changeNotifier,
		private PermissionService $permissionService,
		private SortKeyService $sortKeyService,
		private IDBConnection $db,
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
		return $card;
	}

	/**
	 * Creates a card at the bottom of the stack.
	 *
	 * @throws DoesNotExistException if the stack or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board
	 * @throws InvalidInputException on invalid title
	 * @throws \OverflowException if the appended sort key would overflow (stack needs a rebalance)
	 */
	public function create(int $stackId, string $title, string $uid): Card {
		$stack = $this->loadStack($stackId);
		$board = $this->loadBoard($stack->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);

		$lastCard = $this->cardMapper->findLastInStack($stackId);
		$sortKey = $lastCard === null
			? $this->sortKeyService->initial()
			: $this->sortKeyService->after($lastCard->getSortKey());

		$now = time();

		$card = new Card();
		$card->setBoardId($stack->getBoardId());
		$card->setStackId($stackId);
		$card->setTitle($this->validateTitle($title));
		$card->setSortKey($sortKey);
		$card->setDoneAt(0);
		$card->setArchived(false);
		$card->setOwner($uid);
		$card->setCreatedAt($now);
		$card->setLastModified($now);
		$card->setDeletedAt(0);
		$card = $this->cardMapper->insert($card);

		$this->changeNotifier->notify(
			$stack->getBoardId(),
			Change::ENTITY_CARD,
			$card->getId(),
			Change::ACTION_CREATE,
			$uid
		);

		return $card;
	}

	/**
	 * Updates the given fields (null = leave unchanged). An empty duedate
	 * string clears the due date; an empty description string clears the
	 * description. done=true stamps done_at only once (idempotent),
	 * done=false clears it.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board
	 * @throws InvalidInputException on invalid title or duedate
	 */
	public function update(
		int $id,
		?string $title,
		?string $description,
		?string $duedate,
		?bool $done,
		?bool $archived,
		string $uid,
	): Card {
		$card = $this->loadCard($id);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);

		if ($title !== null) {
			$card->setTitle($this->validateTitle($title));
		}
		if ($description !== null) {
			$card->setDescription($description);
		}
		if ($duedate !== null) {
			$card->setDuedate($this->parseDuedate($duedate));
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

		$now = time();
		$card->setLastModified($now);
		$card = $this->cardMapper->update($card);

		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$id,
			Change::ACTION_UPDATE,
			$uid
		);

		return $card;
	}

	/**
	 * Soft-deletes the card (sets deleted_at).
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board
	 */
	public function delete(int $id, string $uid): void {
		$card = $this->loadCard($id);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);

		$now = time();
		$card->setDeletedAt($now);
		$card->setLastModified($now);
		$this->cardMapper->update($card);

		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$id,
			Change::ACTION_DELETE,
			$uid
		);
	}

	/**
	 * Moves the card inside its board: into $targetStackId, directly after
	 * $afterCardId, or to the top of the target stack when $afterCardId is
	 * null. The transaction makes the card update and its change row atomic
	 * (rollback on failure) — it does NOT serialize concurrent moves: two
	 * moves into the same gap can each read the same neighbours under READ
	 * COMMITTED and derive the same key, leaving duplicate sort keys whose
	 * relative order is undefined. Accepted for MVP (cosmetic wobble, next
	 * move repairs it); a unique index on (stack_id, sort_key) is the
	 * planned mitigation.
	 *
	 * @throws DoesNotExistException if the card, its board or the target stack does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board
	 * @throws InvalidInputException if the target stack is on another board or $afterCardId is unusable
	 * @throws \OverflowException if the new sort key would overflow (stack needs a rebalance)
	 */
	public function move(int $id, int $targetStackId, ?int $afterCardId, string $uid): Card {
		$card = $this->loadCard($id);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);

		$targetStack = $this->loadStack($targetStackId);
		if ($targetStack->getBoardId() !== $card->getBoardId()) {
			throw new InvalidInputException('Cannot move a card to a stack on another board');
		}

		$afterCard = $afterCardId === null
			? null
			: $this->loadAfterCard($afterCardId, $targetStackId, $id);

		// Source stack role for the done-automation. A move within the same
		// stack keeps the target's role — done state then stays put.
		$sourceStack = $targetStackId === $card->getStackId()
			? $targetStack
			: $this->stackMapper->find($card->getStackId());

		$this->db->beginTransaction();
		try {
			$sortKey = $this->deriveMoveKey($targetStackId, $afterCard);

			$now = time();
			$card->setStackId($targetStackId);
			$card->setSortKey($sortKey);
			$card->setLastModified($now);
			$this->applyDoneAutomation($card, $sourceStack, $targetStack, $now);
			$card = $this->cardMapper->update($card);

			$this->changeNotifier->notify(
				$card->getBoardId(),
				Change::ENTITY_CARD,
				$id,
				Change::ACTION_MOVE,
				$uid
			);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $card;
	}

	/**
	 * Done-automation for a move: entering a done-role stack stamps the card
	 * done (once — an already-done card is left alone); leaving a done-role
	 * stack for a non-done one clears it. The done_at change rides the same
	 * card UPDATE as the move, so no extra change row is written. Unconditional
	 * for v1 — a done-role stack stamping done is the expected default.
	 */
	private function applyDoneAutomation(Card $card, Stack $sourceStack, Stack $targetStack, int $now): void {
		$sourceDone = $sourceStack->getRole() === Stack::ROLE_DONE;
		$targetDone = $targetStack->getRole() === Stack::ROLE_DONE;

		if ($targetDone) {
			if ($card->getDoneAt() === 0) {
				$card->setDoneAt($now);
			}
		} elseif ($sourceDone) {
			$card->setDoneAt(0);
		}
	}

	/**
	 * New sort key for a card landing in $targetStackId after $afterCard
	 * (null = top of the stack). The moved card may itself be one of the
	 * neighbours — its still-current key then bounds the result, which is
	 * fine: the derived key remains strictly ordered.
	 *
	 * @throws \OverflowException if the key would overflow — rebalance needed
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
	 * deleted, wrong stack, the moved card itself) is invalid input — the
	 * client's picture of the stack is stale, not the moved card's fault.
	 *
	 * @throws InvalidInputException
	 */
	private function loadAfterCard(int $afterCardId, int $targetStackId, int $movedCardId): Card {
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
	 * (2026-07-22T12:00:00.000Z) — the latter is what JS Date.toISOString()
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
		// becomes March 2nd) and only records it in getLastErrors — reject
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
