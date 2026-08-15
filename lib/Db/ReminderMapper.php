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
 * Mapper for `kanso_reminders`, the personal one-shot card reminders (#3816).
 *
 * @template-extends QBMapper<Reminder>
 */
class ReminderMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_reminders', Reminder::class);
	}

	/**
	 * A single reminder row by id, or null.
	 *
	 * @throws Exception
	 */
	public function findById(int $id): ?Reminder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/**
	 * The given user's OWN pending (un-fired) reminders on a card, soonest first
	 * - the card-detail "your reminders" list. Personal: scoped to $userId, so a
	 * user never sees another user's reminder on the same card.
	 *
	 * @return Reminder[]
	 * @throws Exception
	 */
	public function findPendingForUserCard(string $userId, int $cardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('fired_at'))
			->orderBy('remind_at', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Reminders whose time has arrived and that have not fired yet - the source
	 * for the personal-reminder cron ({@see \OCA\Kanso\Service\ReminderService}).
	 * `fired_at IS NULL AND remind_at <= now` catches up any overdue backlog. The
	 * per-user visibility re-check and the actual notify happen in PHP; this is
	 * just the bounded candidate set. Oldest-owed first, capped at $limit.
	 *
	 * @return Reminder[]
	 * @throws Exception
	 */
	public function findDue(int $now, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNull('fired_at'))
			->andWhere($qb->expr()->lte('remind_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->orderBy('remind_at', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * @throws Exception
	 */
	public function insertReminder(string $userId, int $cardId, ?int $commentId, int $remindAt): Reminder {
		$reminder = new Reminder();
		$reminder->setUserId($userId);
		$reminder->setCardId($cardId);
		$reminder->setCommentId($commentId);
		$reminder->setRemindAt($remindAt);
		$reminder->setFiredAt(null);
		$reminder->setCreatedAt(time());

		return $this->insert($reminder);
	}

	/**
	 * Removes every reminder of a card - cascade for a card purge.
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
