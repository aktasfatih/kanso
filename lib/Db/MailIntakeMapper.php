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
 * Mapper for `kanso_mail_intake`.
 *
 * @template-extends QBMapper<MailIntake>
 */
class MailIntakeMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_mail_intake', MailIntake::class);
	}

	/**
	 * The board's mailbox config.
	 *
	 * @throws DoesNotExistException if the board has no mailbox configured
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function findByBoard(int $boardId): MailIntake {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Every enabled mailbox across all boards - the cron's work list. Ordered by
	 * id so a run that hits the per-run budget always resumes in the same order
	 * rather than starving the tail.
	 *
	 * @return MailIntake[]
	 * @throws Exception
	 */
	public function findEnabled(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Drops a board's mailbox config. Used when the board itself goes away, so
	 * credentials do not outlive the thing they fed.
	 *
	 * @throws Exception
	 */
	public function deleteByBoard(int $boardId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
