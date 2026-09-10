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
 * Mapper for `kanso_deck_imports` - which Deck boards a user has already
 * imported (#10300).
 *
 * Every lookup is scoped to the IMPORTING USER as well as the Deck board id: a
 * Deck board shared with several people is legitimately importable by each of
 * them, so a global key would refuse a valid import mid-migration.
 *
 * @template-extends QBMapper<DeckImport>
 */
class DeckImportMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_deck_imports', DeckImport::class);
	}

	/**
	 * The user's existing import record for a Deck board, or null if they have
	 * never imported it. This is the fast pre-check that turns the ordinary
	 * sequential retry into a clean "already imported" answer before any board
	 * row or attachment byte is written; the unique index is what covers the
	 * concurrent case.
	 *
	 * @throws Exception
	 */
	public function findForUser(int $deckBoardId, string $uid): ?DeckImport {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('deck_board_id', $qb->createNamedParameter($deckBoardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('imported_by', $qb->createNamedParameter($uid)))
			->setMaxResults(1);

		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/**
	 * Records an import. Called INSIDE the import transaction, so the row and the
	 * imported board commit (or roll back) together.
	 *
	 * A duplicate submit loses here on the unique index and takes the whole
	 * transaction down with it - which is the point: no second board, no second
	 * copy of the attachment bytes. The violation is left to propagate so the
	 * caller can turn it into an "already imported" answer; it is deliberately
	 * NOT swallowed, because on Postgres the surrounding transaction is aborted
	 * by the failed statement anyway and nothing more can be written in it.
	 *
	 * @throws Exception on any DB error, including the unique-constraint clash
	 */
	public function record(int $deckBoardId, int $kansoBoardId, string $uid, int $importedAt): DeckImport {
		$row = new DeckImport();
		$row->setDeckBoardId($deckBoardId);
		$row->setKansoBoardId($kansoBoardId);
		$row->setImportedBy($uid);
		$row->setImportedAt($importedAt);

		return $this->insert($row);
	}

	/**
	 * Drops the user's import record for a Deck board, so a CONFIRMED re-import
	 * can claim the pair again. Runs inside the same transaction as the re-import
	 * and immediately before {@see self::record()}, so the marker never points at
	 * a board that failed to import.
	 *
	 * @return int number of deleted rows (0 when there was nothing recorded)
	 * @throws Exception
	 */
	public function forget(int $deckBoardId, string $uid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('deck_board_id', $qb->createNamedParameter($deckBoardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('imported_by', $qb->createNamedParameter($uid)));

		return $qb->executeStatement();
	}
}
