<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `kanso_mail_seen`.
 *
 * @template-extends QBMapper<MailSeenMessage>
 */
class MailSeenMessageMapper extends QBMapper {
	/**
	 * How long a dedupe key is remembered. Long enough to cover a mailbox
	 * re-delivering after an outage or a restore, short enough that the table
	 * does not grow forever. A message older than this that arrives again is
	 * genuinely new mail as far as anyone is concerned.
	 */
	public const RETENTION_SECONDS = 60 * 60 * 24 * 60;

	public function __construct(
		IDBConnection $db,
		private ITimeFactory $time,
	) {
		parent::__construct($db, 'kanso_mail_seen', MailSeenMessage::class);
	}

	/**
	 * Records a message as carded, returning false if it already was.
	 *
	 * The UNIQUE index does the work: we INSERT and treat a constraint violation
	 * as "already seen". A SELECT-then-INSERT would have a window between the
	 * two in which a concurrent poll of the same mailbox inserts the same key -
	 * unlikely, but the whole point of this table is to make double-carding
	 * impossible rather than improbable.
	 *
	 * @return bool true when this call claimed the message, false when another
	 *              already had
	 */
	public function claim(int $intakeId, string $dedupeKey): bool {
		$entity = new MailSeenMessage();
		$entity->setIntakeId($intakeId);
		$entity->setDedupeKey($dedupeKey);
		$entity->setCreatedAt($this->time->getTime());

		try {
			$this->insert($entity);
			return true;
		} catch (Exception $e) {
			if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false;
			}
			throw $e;
		}
	}

	/**
	 * Drops keys past the retention window.
	 *
	 * @return int rows removed
	 * @throws Exception
	 */
	public function pruneOlderThan(int $cutoff): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * Drops every key for one mailbox - used when its config is deleted, so the
	 * rows do not outlive the thing they belonged to.
	 *
	 * @throws Exception
	 */
	public function deleteByIntake(int $intakeId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('intake_id', $qb->createNamedParameter($intakeId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
