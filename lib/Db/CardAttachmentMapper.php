<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Service\CardVisibilityScope;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `kanso_card_attachments`.
 *
 * @template-extends QBMapper<CardAttachment>
 */
class CardAttachmentMapper extends QBMapper {
	public function __construct(
		IDBConnection $db,
		private CardVisibilityScope $visibilityScope,
	) {
		parent::__construct($db, 'kanso_card_attachments', CardAttachment::class);
	}

	/**
	 * A single attachment by id.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if it does not exist
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id): CardAttachment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * A card's attachments, oldest first.
	 *
	 * @return CardAttachment[]
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
	 * ONE page of a BOARD's attachments, newest first, with the owning card's
	 * title alongside each row - the board-wide attachment listing (#10670).
	 *
	 * ONE indexed query, never a per-card loop: the filter is
	 * `a.board_id = ?`, served by the `kanso_cattach_board` index that the
	 * denormalized `board_id` column was added for
	 * ({@see \OCA\Kanso\Migration\Version003200Date20260820000000}), so the
	 * cost does not grow with the number of cards on the board.
	 *
	 * The JOIN is what makes it SAFE, and both halves of it matter:
	 *  - INNER JOIN on the card drops attachments whose card is in the trash
	 *    (`c.deleted_at != 0`) - a trashed card's files are not board content.
	 *  - {@see CardVisibilityScope::applyForViewer()} on the joined card applies
	 *    THE card-visibility rule, so an attachment on a card this viewer may
	 *    not see is not listed here either. Without it this listing would be a
	 *    second read path around the rule - the exact leak the scope exists to
	 *    prevent. It also re-asserts `c.board_id`, so a row whose denormalized
	 *    `board_id` ever drifted from its card's cannot cross boards.
	 *
	 * `storage_key` is deliberately NOT selected: the listing never needs it and
	 * the opaque object name must not travel further than the single-attachment
	 * read paths.
	 *
	 * @param int $limit page size - the caller hard-caps it
	 * @param int $offset rows to skip
	 * @return list<array{attachment: CardAttachment, cardTitle: string}>
	 * @throws Exception
	 */
	public function findByBoard(int $boardId, ViewerContext $viewer, int $limit, int $offset): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('a.id', 'a.card_id', 'a.board_id', 'a.filename', 'a.mime', 'a.size', 'a.uploaded_by', 'a.created_at')
			->selectAlias('c.title', 'card_title')
			->from($this->getTableName(), 'a')
			->innerJoin('a', 'kanso_cards', 'c', $qb->expr()->eq('a.card_id', 'c.id'))
			->where($qb->expr()->eq('a.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('a.created_at', 'DESC')
			->addOrderBy('a.id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		$this->visibilityScope->applyForViewer($qb, 'c', $viewer);

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$cardTitle = (string)($row['card_title'] ?? '');
			// The joined column is not a column of the entity - strip it before
			// hydrating or Entity::fromRow() would look for a setCardTitle().
			unset($row['card_title']);
			$rows[] = [
				'attachment' => CardAttachment::fromRow($row),
				'cardTitle' => $cardTitle,
			];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * How many attachments the viewer may see on the board - the `total` behind
	 * the hard-capped page above, so the UI can say a page is partial.
	 *
	 * The SAME board filter, live-card JOIN and visibility scope as
	 * {@see self::findByBoard()}: a count that saw more than the listing does
	 * would itself leak the existence of a hidden card's file.
	 *
	 * @throws Exception
	 */
	public function countByBoard(int $boardId, ViewerContext $viewer): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName(), 'a')
			->innerJoin('a', 'kanso_cards', 'c', $qb->expr()->eq('a.card_id', 'c.id'))
			->where($qb->expr()->eq('a.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$this->visibilityScope->applyForViewer($qb, 'c', $viewer);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['cnt'] ?? 0);
	}

	/**
	 * Removes every attachment ROW of a card - the DB half of the cascade when a
	 * card is permanently purged. The bytes are removed separately (the
	 * app-data objects), so callers must drop those too; see
	 * {@see \OCA\Kanso\Service\CardAttachmentService::deleteAllForCard()}.
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
	 * Total stored attachment bytes across the WHOLE instance - the aggregate
	 * the optional instance-wide storage cap is measured against
	 * ({@see \OCA\Kanso\Service\CardAttachmentService::assertStorageHeadroom()}).
	 *
	 * One `SUM(size)` over the table, never a row scan in PHP, and the caller
	 * only reaches it when an admin has actually configured a cap - an install
	 * with no cap (the default) never runs this query at all.
	 *
	 * @throws Exception
	 */
	public function totalSize(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->sum('size'), 'total')
			->from($this->getTableName());

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		// SUM over an empty table is NULL, not 0.
		return (int)($row['total'] ?? 0);
	}

	/**
	 * Number of attachments on a card - powers the card-detail count without
	 * loading the rows.
	 *
	 * @throws Exception
	 */
	public function countByCard(int $cardId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['cnt'] ?? 0);
	}
}
