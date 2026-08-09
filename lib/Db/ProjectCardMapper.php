<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCA\Kanso\Service\CardVisibilityScope;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `kanso_project_cards`, the flat project/card membership rows.
 *
 * @template-extends QBMapper<ProjectCard>
 */
class ProjectCardMapper extends QBMapper {
	public function __construct(
		IDBConnection $db,
		private CardVisibilityScope $visibilityScope,
	) {
		parent::__construct($db, 'kanso_project_cards', ProjectCard::class);
	}

	/**
	 * Adds a card to a project. Idempotent per (project, card): a repeat add of
	 * the same pair is a no-op, swallowing the unique-constraint violation
	 * (mirrors {@see CardReviewMapper::insertRequest} / ReviewService idempotency).
	 *
	 * @throws Exception on any DB error other than the unique-constraint clash
	 */
	public function add(int $projectId, int $cardId): void {
		$row = new ProjectCard();
		$row->setProjectId($projectId);
		$row->setCardId($cardId);

		try {
			$this->insert($row);
		} catch (Exception $e) {
			if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				// The card is already in the project - the idempotent success case.
				return;
			}
			throw $e;
		}
	}

	/**
	 * Removes a card from a project. Idempotent - removing an absent membership
	 * deletes nothing.
	 *
	 * @throws Exception
	 */
	public function remove(int $projectId, int $cardId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * Removes a card from every project it belongs to - the cascade for a card
	 * purge.
	 *
	 * @throws Exception
	 */
	public function deleteByCard(int $cardId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * Removes every membership of a project - the cascade for a project delete
	 * (the cards themselves are untouched).
	 *
	 * @throws Exception
	 */
	public function deleteByProject(int $projectId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * The ids of every project a card belongs to - powers the `projectIds` list
	 * on card detail.
	 *
	 * @return int[]
	 * @throws Exception
	 */
	public function findProjectIdsByCard(int $cardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('project_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->orderBy('project_id', 'ASC');

		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[] = (int)$row['project_id'];
		}
		$result->closeCursor();

		return $ids;
	}

	/**
	 * The cards of a project that live on one of the given (readable) boards,
	 * enriched with their board + stack context - the ACL-filtered project card
	 * list. One query joining through `kanso_cards` and `kanso_boards`; the
	 * caller supplies the readable board id set (see the readable-boards
	 * discipline in {@see \OCA\Kanso\Service\ReviewService}), so a card on a
	 * board the viewer cannot read is silently dropped. Deleted cards are
	 * excluded. Rows are shaped exactly like {@see CardMapper::findAssignedInBoards}.
	 *
	 * Visibility (#3743): the scope runs on the joined card, so a collected
	 * card that is hidden from the project owner (e.g. someone else's private
	 * card that was public when collected) drops out of the list - and, since
	 * project stats aggregate over exactly this id set, out of every project
	 * metric too.
	 *
	 * @param int[] $boardIds the viewer's readable board ids (empty → [])
	 * @param array<int, string> $rolesByBoard the viewer's role per board id
	 * @return list<array<string, mixed>>
	 * @throws Exception
	 */
	public function findCardsInProjectAndBoards(int $projectId, array $boardIds, string $uid, array $rolesByBoard): array {
		if ($boardIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('c.id')
			->addSelect('c.board_id', 'c.title', 'c.duedate', 'c.priority', 'c.done_at', 'c.started_at', 'c.stack_id', 'c.parent_card_id', 'c.sort_key')
			->selectAlias('b.title', 'board_title')
			->selectAlias('s.title', 'stack_title')
			->from($this->getTableName(), 'pc')
			->innerJoin('pc', 'kanso_cards', 'c', $qb->expr()->eq('pc.card_id', 'c.id'))
			->innerJoin('c', 'kanso_boards', 'b', $qb->expr()->eq('c.board_id', 'b.id'))
			->leftJoin('c', 'kanso_stacks', 's', $qb->expr()->eq('c.stack_id', 's.id'))
			->where($qb->expr()->eq('pc.project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('c.board_id', $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('board_title', 'ASC')
			->addOrderBy('c.sort_key', 'ASC')
			->addOrderBy('c.id', 'ASC');
		$this->visibilityScope->apply($qb, 'c', $uid, null, $rolesByBoard);

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$due = $row['duedate'] !== null ? (new \DateTime((string)$row['duedate']))->format(\DateTimeInterface::ATOM) : null;
			$rows[] = [
				'id' => (int)$row['id'],
				'boardId' => (int)$row['board_id'],
				'boardTitle' => (string)$row['board_title'],
				'stackTitle' => $row['stack_title'] !== null ? (string)$row['stack_title'] : null,
				'title' => (string)$row['title'],
				'duedate' => $due,
				'priority' => (int)$row['priority'],
				'doneAt' => (int)$row['done_at'],
				'startedAt' => (int)$row['started_at'],
				'parentCardId' => ((int)($row['parent_card_id'] ?? 0)) ?: null,
			];
		}
		$result->closeCursor();

		return $rows;
	}
}
