<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `kanso_card_reviews`, the flat card review-request rows.
 *
 * @template-extends QBMapper<CardReview>
 */
class CardReviewMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_card_reviews', CardReview::class);
	}

	/**
	 * Every review row of a card, in request order.
	 *
	 * @return CardReview[]
	 * @throws Exception
	 */
	public function findByCard(int $cardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Aggregate review state of every non-deleted card on a board that has any
	 * review rows, as one query joining through `kanso_cards`. The aggregate
	 * follows urgency precedence: changes_requested > pending > approved — so
	 * the tile chip shows the state that most needs attention.
	 *
	 * @return array<int, string> map of cardId => aggregate state
	 * @throws Exception
	 */
	public function reviewStatesByBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('r.card_id', 'r.state')
			->from($this->getTableName(), 'r')
			->innerJoin('r', 'kanso_cards', 'c', $qb->expr()->eq('r.card_id', 'c.id'))
			->where($qb->expr()->eq('c.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$map = [];
		while (($row = $result->fetch()) !== false) {
			$cardId = (int)$row['card_id'];
			$state = (string)$row['state'];
			$map[$cardId] = $this->moreUrgent($map[$cardId] ?? null, $state);
		}
		$result->closeCursor();

		return $map;
	}

	/**
	 * Whether the card has any review that is not yet approved — the condition
	 * the done-gate blocks on. A card with no review rows returns false (nothing
	 * to wait for).
	 *
	 * @throws Exception
	 */
	public function hasUnapprovedReviews(int $cardId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('state', $qb->createNamedParameter(CardReview::STATE_APPROVED)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$hasUnapproved = $result->fetchOne() !== false;
		$result->closeCursor();

		return $hasUnapproved;
	}

	/**
	 * @throws Exception
	 */
	public function exists(int $cardId, string $reviewer): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('reviewer', $qb->createNamedParameter($reviewer)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$exists = $result->fetchOne() !== false;
		$result->closeCursor();

		return $exists;
	}

	/**
	 * Loads a single review row, or null when the reviewer holds none on the card.
	 *
	 * @throws Exception
	 */
	public function findReview(int $cardId, string $reviewer): ?CardReview {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('reviewer', $qb->createNamedParameter($reviewer)))
			->setMaxResults(1);

		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/**
	 * @throws Exception
	 */
	public function insertRequest(int $cardId, string $reviewer, string $requestedBy, ?int $reviewTypeId = null): CardReview {
		$review = new CardReview();
		$review->setCardId($cardId);
		$review->setReviewer($reviewer);
		$review->setState(CardReview::STATE_PENDING);
		$review->setRequestedBy($requestedBy);
		$review->setCreatedAt(time());
		$review->setReviewTypeId($reviewTypeId);

		return $this->insert($review);
	}

	/**
	 * Clears a review type from every review that used it (the review survives,
	 * untyped) — the set-null cascade when a review type is deleted.
	 *
	 * @return int number of rows updated
	 * @throws Exception
	 */
	public function clearType(int $reviewTypeId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('review_type_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('review_type_id', $qb->createNamedParameter($reviewTypeId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * @return int number of deleted rows (0 when the request was absent)
	 * @throws Exception
	 */
	public function deleteReview(int $cardId, string $reviewer): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('reviewer', $qb->createNamedParameter($reviewer)));

		return $qb->executeStatement();
	}

	/**
	 * Removes every review of a card — cascade for a card purge.
	 *
	 * @return int number of deleted rows
	 * @throws Exception
	 */
	public function deleteByCard(int $cardId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * Urgency precedence for the board aggregate: changes_requested beats
	 * pending beats approved. $current may be null (first row seen).
	 */
	private function moreUrgent(?string $current, string $incoming): string {
		$rank = [
			CardReview::STATE_APPROVED => 0,
			CardReview::STATE_PENDING => 1,
			CardReview::STATE_CHANGES_REQUESTED => 2,
		];
		if ($current === null) {
			return $incoming;
		}
		return ($rank[$incoming] ?? 0) > ($rank[$current] ?? 0) ? $incoming : $current;
	}
}
