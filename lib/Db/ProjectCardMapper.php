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
		$row->setAddedAt(time());

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
	 * The ids of the VIEWER'S OWN projects a card belongs to - powers the
	 * `projectIds` list on card detail.
	 *
	 * Scoped by owner (#10737): projects are private, owner-only collections
	 * (there is no sharing/ACL table - see {@see \OCA\Kanso\Service\ProjectService}),
	 * and collecting a card needs only READ on its board, so an unscoped list
	 * told every reader of a shared card how many OTHER people had filed it into
	 * their private collections. The join to `kanso_projects` keeps the query at
	 * one round trip; "the projects the viewer may see" is exactly "the projects
	 * the viewer owns".
	 *
	 * @return int[]
	 * @throws Exception
	 */
	public function findProjectIdsByCard(int $cardId, string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('pc.project_id')
			->from($this->getTableName(), 'pc')
			->innerJoin('pc', 'kanso_projects', 'p', $qb->expr()->eq('pc.project_id', 'p.id'))
			->where($qb->expr()->eq('pc.card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('p.owner', $qb->createNamedParameter($uid)))
			->orderBy('pc.project_id', 'ASC');

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
	 * board the viewer cannot read is silently dropped. Rows extend the shape of
	 * {@see CardMapper::findAssignedInBoards} with the planning fields the
	 * project forecast and timeline read.
	 *
	 * Scope: deleted, ARCHIVED and TEMPLATE cards are all excluded - the same
	 * open scope every board-side card query applies
	 * ({@see CardMapper::findSummariesByBoard()}). Project stats aggregate over
	 * exactly this id set, so anything looser made the project's numbers disagree
	 * with the board's over the same cards - a template counted as work, an
	 * archived card counted as outstanding.
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
			->addSelect('c.board_id', 'c.title', 'c.duedate', 'c.start_date', 'c.priority', 'c.done_at', 'c.started_at', 'c.stack_id', 'c.parent_card_id', 'c.sort_key', 'c.estimate', 'c.board_seq', 'c.created_at', 'pc.added_at')
			->selectAlias('b.title', 'board_title')
			->selectAlias('b.prefix', 'board_prefix')
			->selectAlias('b.estimate_scale', 'estimate_scale')
			->selectAlias('b.disabled_card_features', 'disabled_card_features')
			->selectAlias('b.estimate_custom', 'estimate_custom')
			->selectAlias('s.title', 'stack_title')
			->from($this->getTableName(), 'pc')
			->innerJoin('pc', 'kanso_cards', 'c', $qb->expr()->eq('pc.card_id', 'c.id'))
			->innerJoin('c', 'kanso_boards', 'b', $qb->expr()->eq('c.board_id', 'b.id'))
			->leftJoin('c', 'kanso_stacks', 's', $qb->expr()->eq('c.stack_id', 's.id'))
			->where($qb->expr()->eq('pc.project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('c.board_id', $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('c.is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->orderBy('board_title', 'ASC')
			->addOrderBy('c.sort_key', 'ASC')
			->addOrderBy('c.id', 'ASC');
		$this->visibilityScope->apply($qb, 'c', $uid, null, $rolesByBoard);

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$due = $row['duedate'] !== null ? (new \DateTime((string)$row['duedate']))->format(\DateTimeInterface::ATOM) : null;
			$estimateShown = CardFeatures::decode($row['disabled_card_features'] !== null ? (string)$row['disabled_card_features'] : null)[CardFeatures::ESTIMATE];
			$estimate = $estimateShown && $row['estimate'] !== null && $row['estimate'] !== '' ? (string)$row['estimate'] : null;
			$scale = (string)($row['estimate_scale'] ?? EstimateScale::NONE);
			$start = $row['start_date'] !== null ? (new \DateTime((string)$row['start_date']))->format(\DateTimeInterface::ATOM) : null;
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
				'startDate' => $start,
				'estimate' => $estimate,
				'estimateScale' => $scale,
				'estimateValue' => EstimateScale::value($scale, $estimate, EstimateScale::decodeCustom($row['estimate_custom'] !== null ? (string)$row['estimate_custom'] : null)),
				'boardSeq' => $row['board_seq'] !== null ? (int)$row['board_seq'] : null,
				'boardPrefix' => (string)($row['board_prefix'] ?? ''),
				'createdAt' => (int)$row['created_at'],
				'addedAt' => (int)$row['added_at'],
			];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * How many open blockers each of $cardIds has, counting only blockers the
	 * viewer can see - an invisible blocker must not leak through a count.
	 *
	 * @param int[] $cardIds
	 * @param int[] $boardIds the viewer's readable board ids
	 * @param array<int, string> $rolesByBoard the viewer's role per board id
	 * @return array<int, int> blocked card id => open blocker count (absent = 0)
	 * @throws Exception
	 */
	public function openBlockerCounts(array $cardIds, array $boardIds, string $uid, array $rolesByBoard): array {
		if ($cardIds === [] || $boardIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('r.other_card_id')
			->selectAlias($qb->func()->count('r.id'), 'blockers')
			->from('kanso_card_relations', 'r')
			->innerJoin('r', 'kanso_cards', 'src', $qb->expr()->eq('r.card_id', 'src.id'))
			->where($qb->expr()->eq('r.type', $qb->createNamedParameter(CardRelation::TYPE_BLOCKS)))
			->andWhere($qb->expr()->in('r.other_card_id', $qb->createNamedParameter(array_values($cardIds), IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->in('src.board_id', $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('src.done_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('src.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('src.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->groupBy('r.other_card_id');
		$this->visibilityScope->apply($qb, 'src', $uid, null, $rolesByBoard);

		$result = $qb->executeQuery();
		$counts = [];
		while (($row = $result->fetch()) !== false) {
			$counts[(int)$row['other_card_id']] = (int)$row['blockers'];
		}
		$result->closeCursor();

		return $counts;
	}
}
