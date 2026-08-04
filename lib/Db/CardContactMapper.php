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
 * Mapper for `kanso_card_contacts`, the card/contact link rows (#3530).
 *
 * READ-ONLY references to Nextcloud Contacts entries; `display_name` is a
 * denormalized snapshot stored at link time. Mirrors {@see CardAssigneeMapper}:
 * the board payload resolves every card's contacts in ONE query (no N+1).
 *
 * @template-extends QBMapper<CardContact>
 */
class CardContactMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_card_contacts', CardContact::class);
	}

	/**
	 * The contacts of every non-deleted card on a board, as ONE query joining
	 * through `kanso_cards` - the board payload stays a fixed number of queries
	 * no matter how many cards carry contacts.
	 *
	 * Map of cardId => contacts in link order.
	 *
	 * @return array<int, list<array{contactUri: string, displayName: string}>>
	 * @throws Exception
	 */
	public function findContactsByBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('cc.card_id', 'cc.contact_uri', 'cc.display_name')
			->from($this->getTableName(), 'cc')
			->innerJoin('cc', 'kanso_cards', 'c', $qb->expr()->eq('cc.card_id', 'c.id'))
			->where($qb->expr()->eq('c.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('cc.id', 'ASC');

		$result = $qb->executeQuery();
		$map = [];
		while (($row = $result->fetch()) !== false) {
			$map[(int)$row['card_id']][] = [
				'contactUri' => (string)$row['contact_uri'],
				'displayName' => (string)$row['display_name'],
			];
		}
		$result->closeCursor();

		return $map;
	}

	/**
	 * The contacts of one card, in link order.
	 *
	 * @return list<array{contactUri: string, displayName: string}>
	 * @throws Exception
	 */
	public function findContactsByCard(int $cardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('contact_uri', 'display_name')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$contacts = [];
		while (($row = $result->fetch()) !== false) {
			$contacts[] = [
				'contactUri' => (string)$row['contact_uri'],
				'displayName' => (string)$row['display_name'],
			];
		}
		$result->closeCursor();

		return $contacts;
	}

	/**
	 * Whether the contact is already linked to the card.
	 *
	 * @throws Exception
	 */
	public function exists(int $cardId, string $contactUri): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('contact_uri', $qb->createNamedParameter($contactUri)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$exists = $result->fetchOne() !== false;
		$result->closeCursor();

		return $exists;
	}

	/**
	 * @throws Exception
	 */
	public function insertLink(int $cardId, string $contactUri, string $displayName): CardContact {
		$link = new CardContact();
		$link->setCardId($cardId);
		$link->setContactUri($contactUri);
		$link->setDisplayName($displayName);

		return $this->insert($link);
	}

	/**
	 * @return int number of deleted rows (0 when the link was absent)
	 * @throws Exception
	 */
	public function deleteLink(int $cardId, string $contactUri): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('contact_uri', $qb->createNamedParameter($contactUri)));

		return $qb->executeStatement();
	}

	/**
	 * Removes every contact link of a card - cascade for a card purge.
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
