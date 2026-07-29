<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `kanso_comments`.
 *
 * @template-extends QBMapper<Comment>
 */
class CommentMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_comments', Comment::class);
	}

	/**
	 * @throws DoesNotExistException if the comment does not exist
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id): Comment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * All non-deleted comments of a card, oldest first - the flat thread the
	 * client nests by parent_comment_id.
	 *
	 * @return Comment[]
	 * @throws Exception
	 */
	public function findByCard(int $cardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * The non-deleted direct replies of a top-level comment - used to cascade a
	 * delete over a whole thread.
	 *
	 * @return Comment[]
	 * @throws Exception
	 */
	public function findReplies(int $parentCommentId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('parent_comment_id', $qb->createNamedParameter($parentCommentId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	/**
	 * Non-deleted comment count for every non-deleted card on a board that has
	 * comments, as one grouped query joining through `kanso_cards` - the board
	 * payload stays a constant number of queries. Cards without comments are
	 * absent from the map (callers default to 0).
	 *
	 * @return array<int, int> map of cardId => count
	 * @throws Exception
	 */
	public function countsByBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('cm.card_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName(), 'cm')
			->innerJoin('cm', 'kanso_cards', 'c', $qb->expr()->eq('cm.card_id', 'c.id'))
			->where($qb->expr()->eq('c.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('cm.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->groupBy('cm.card_id');

		$result = $qb->executeQuery();
		$map = [];
		while (($row = $result->fetch()) !== false) {
			$map[(int)$row['card_id']] = (int)$row['cnt'];
		}
		$result->closeCursor();

		return $map;
	}

	/**
	 * Soft-deletes every non-deleted reply of a top-level comment in one
	 * set-based UPDATE - the cascade for a thread delete. Doing it as a single
	 * statement (rather than read-then-update-each) closes the window where a
	 * reply inserted concurrently with the parent's delete would survive as an
	 * orphan, and avoids N round-trips.
	 *
	 * @throws Exception
	 */
	public function softDeleteRepliesOf(int $parentCommentId, int $deletedAt): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('deleted_at', $qb->createNamedParameter($deletedAt, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('parent_comment_id', $qb->createNamedParameter($parentCommentId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Non-deleted comment count for a single card. Precondition: the caller has
	 * already loaded a LIVE card (this does not re-check the card's deleted_at);
	 * it is only reached via the single-card detail payload of a non-deleted
	 * card. The board-wide {@see self::countsByBoard()} does join and exclude
	 * soft-deleted cards.
	 *
	 * @throws Exception
	 */
	public function countByCard(int $cardId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * Non-deleted comments whose body matches a LIKE pattern, restricted to the
	 * given readable boards (joined through non-deleted cards). Portable
	 * case-insensitive LIKE; the pattern is pre-escaped/wrapped by the caller.
	 * Each row carries the parent card's id, board and title so a hit can be
	 * shown and deep-linked without a second query. $boardIds must be non-empty.
	 *
	 * @param int[] $boardIds
	 * @return array<int, array{id: int, cardId: int, boardId: int, cardTitle: string, body: string}>
	 * @throws Exception
	 */
	public function searchInBoards(array $boardIds, string $likePattern, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('cm.id', 'cm.card_id', 'cm.body', 'c.board_id', 'c.title')
			->from($this->getTableName(), 'cm')
			->innerJoin('cm', 'kanso_cards', 'c', $qb->expr()->eq('cm.card_id', 'c.id'))
			->where($qb->expr()->in('c.board_id', $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('cm.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->iLike('cm.body', $qb->createNamedParameter($likePattern)))
			->orderBy('cm.id', 'DESC')
			->setMaxResults($limit);

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = [
				'id' => (int)$row['id'],
				'cardId' => (int)$row['card_id'],
				'boardId' => (int)$row['board_id'],
				'cardTitle' => (string)$row['title'],
				'body' => (string)$row['body'],
			];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * The Inbox feed: recent comments on the given followed cards, enriched with
	 * card + board context, newest first, excluding the viewer's own comments.
	 * The caller supplies both the followed-card set and the ACL-filtered
	 * readable board set (mirrors the readable-boards discipline), so no per-row
	 * permission check is needed. Empty card set → [].
	 *
	 * @param int[] $cardIds followed card ids
	 * @param int[] $boardIds the viewer's readable board ids
	 * @return list<array{id: int, cardId: int, boardId: int, cardTitle: string, boardTitle: string, author: string, body: string, createdAt: int}>
	 * @throws Exception
	 */
	public function findInboxForCards(array $cardIds, array $boardIds, string $excludeAuthor, int $limit): array {
		if ($cardIds === [] || $boardIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('cm.id', 'cm.card_id', 'cm.author', 'cm.body', 'cm.created_at')
			->selectAlias('c.title', 'card_title')
			->addSelect('c.board_id')
			->selectAlias('b.title', 'board_title')
			->from($this->getTableName(), 'cm')
			->innerJoin('cm', 'kanso_cards', 'c', $qb->expr()->eq('cm.card_id', 'c.id'))
			->innerJoin('c', 'kanso_boards', 'b', $qb->expr()->eq('c.board_id', 'b.id'))
			->where($qb->expr()->in('cm.card_id', $qb->createNamedParameter($cardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->in('c.board_id', $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('cm.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('cm.author', $qb->createNamedParameter($excludeAuthor)))
			->orderBy('cm.created_at', 'DESC')
			->addOrderBy('cm.id', 'DESC')
			->setMaxResults($limit);

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = [
				'id' => (int)$row['id'],
				'cardId' => (int)$row['card_id'],
				'boardId' => (int)$row['board_id'],
				'cardTitle' => (string)$row['card_title'],
				'boardTitle' => (string)$row['board_title'],
				'author' => (string)$row['author'],
				'body' => (string)$row['body'],
				'createdAt' => (int)$row['created_at'],
			];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * Count of non-deleted comments posted on a board's non-deleted cards since
	 * $sinceTs - the "comment activity" board-stats metric. One grouped-free
	 * COUNT joining through `kanso_cards`. `created_at` is a plain unix int, so
	 * the window is a direct integer comparison.
	 *
	 * @throws Exception
	 */
	public function countRecentForBoard(int $boardId, int $sinceTs): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName(), 'cm')
			->innerJoin('cm', 'kanso_cards', 'c', $qb->expr()->eq('cm.card_id', 'c.id'))
			->where($qb->expr()->eq('c.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('cm.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('cm.created_at', $qb->createNamedParameter($sinceTs, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * Hard-deletes every comment of a card (all threads) - cascade for a card
	 * purge.
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
}
