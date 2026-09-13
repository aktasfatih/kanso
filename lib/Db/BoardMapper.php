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
 * Mapper for `kanso_boards`.
 *
 * @template-extends QBMapper<Board>
 */
class BoardMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_boards', Board::class);
	}

	/**
	 * @throws DoesNotExistException if the board does not exist
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id): Board {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Ids of soft-deleted boards whose tombstone is older than $deletedBefore -
	 * the reaping work list for the purge job. Oldest deletion first, so a
	 * backlog drains in the order it accumulated.
	 *
	 * Deliberately NOT filtered on `deleted_at = 0` like every other read path:
	 * this is the one query whose subject IS the deleted board.
	 *
	 * @return list<int> at most $limit ids
	 * @throws Exception
	 */
	public function findPurgeableIds(int $deletedBefore, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->gt('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('deleted_at', $qb->createNamedParameter($deletedBefore, IQueryBuilder::PARAM_INT)))
			->orderBy('deleted_at', 'ASC')
			->setMaxResults($limit);

		$ids = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();

		return $ids;
	}

	/**
	 * Hard-deletes the board row by id - the LAST step of a board purge, run
	 * once every dependent table has been emptied. Irreversible.
	 *
	 * @return int number of deleted rows
	 * @throws Exception
	 */
	public function deleteById(int $id): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * Every non-deleted board across the whole instance, oldest id first - the
	 * system-wide work list for instance-level cron jobs (e.g. the scheduled
	 * backup sweep). Not user-scoped: the caller runs as the system.
	 *
	 * @return Board[]
	 * @throws Exception
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * The single non-deleted board whose `public_share_token` equals the given
	 * token (#3531). The token column is UNIQUE, so this is one indexed probe
	 * that can only ever resolve to exactly one board - a token can never pivot
	 * to a different board. An empty token is rejected up-front so a board with a
	 * NULL token can never be reached with a blank input.
	 *
	 * @throws DoesNotExistException if no live board carries that exact token
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function findByPublicToken(string $token): Board {
		if ($token === '') {
			throw new DoesNotExistException('Empty public share token');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('public_share_token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * The single non-deleted board whose `ical_feed_token` equals the given token
	 * (#3541). The token column is UNIQUE, so this is one indexed probe that can
	 * only ever resolve to exactly one board - a token can never pivot to a
	 * different board. An empty token is rejected up-front so a board with a NULL
	 * token can never be reached with a blank input.
	 *
	 * @throws DoesNotExistException if no live board carries that exact token
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function findByIcalFeedToken(string $token): Board {
		if ($token === '') {
			throw new DoesNotExistException('Empty iCal feed token');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('ical_feed_token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * All non-deleted boards owned by the given user, most recently
	 * modified first.
	 *
	 * @return Board[]
	 * @throws Exception
	 */
	public function findAllForOwner(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('last_modified', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * All non-deleted boards visible to the given user - owned boards plus
	 * boards shared with the user (or one of their groups) via
	 * `kanso_board_acl` - most recently modified first.
	 *
	 * @param string[] $groupIds ids of the groups the user is a member of
	 * @return Board[]
	 * @throws Exception
	 */
	public function findAllForUser(string $uid, array $groupIds): array {
		$byId = [];
		foreach ($this->findAllForOwner($uid) as $board) {
			$byId[$board->getId()] = $board;
		}
		foreach ($this->findAllSharedWith($uid, $groupIds) as $board) {
			$byId[$board->getId()] = $board;
		}

		$boards = array_values($byId);
		usort(
			$boards,
			static fn (Board $a, Board $b): int => $b->getLastModified() <=> $a->getLastModified()
		);
		return $boards;
	}

	/**
	 * All non-deleted boards shared with the user directly or via one of
	 * their groups. May contain a board twice-joined only once per row -
	 * callers dedupe by id.
	 *
	 * @param string[] $groupIds
	 * @return Board[]
	 * @throws Exception
	 */
	private function findAllSharedWith(string $uid, array $groupIds): array {
		$qb = $this->db->getQueryBuilder();

		$participantMatch = $qb->expr()->orX(
			$qb->expr()->andX(
				$qb->expr()->eq('acl.participant_type', $qb->createNamedParameter(Acl::TYPE_USER, IQueryBuilder::PARAM_INT)),
				$qb->expr()->eq('acl.participant', $qb->createNamedParameter($uid))
			)
		);
		if ($groupIds !== []) {
			$participantMatch->add(
				$qb->expr()->andX(
					$qb->expr()->eq('acl.participant_type', $qb->createNamedParameter(Acl::TYPE_GROUP, IQueryBuilder::PARAM_INT)),
					$qb->expr()->in('acl.participant', $qb->createNamedParameter($groupIds, IQueryBuilder::PARAM_STR_ARRAY))
				)
			);
		}

		$qb->selectDistinct('b.*')
			->from($this->getTableName(), 'b')
			->innerJoin('b', 'kanso_board_acl', 'acl', $qb->expr()->eq('acl.board_id', 'b.id'))
			->where($qb->expr()->eq('b.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($participantMatch)
			// A row without the READ bit (e.g. a zeroed share) must not list
			// the board: index() and find() share one definition of visible.
			->andWhere($qb->expr()->eq(
				$qb->expr()->bitwiseAnd('acl.permission', 1),
				$qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)
			));

		return $this->findEntities($qb);
	}
}
