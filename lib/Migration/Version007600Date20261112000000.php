<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Migration;

use Closure;
use OCA\Kanso\Db\BoardPrefix;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version007600Date20261112000000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/** @psalm-suppress UndefinedDocblockClass */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('kanso_card_sequences')) {
			$table = $schema->createTable('kanso_card_sequences');
			$table->addColumn('board_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
			$table->addColumn('last_seq', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 8]);
			$table->setPrimaryKey(['board_id'], 'kanso_cseq_pk');
		}
		$table = $schema->getTable('kanso_card_move_aliases');
		if (!$table->hasColumn('board_id')) {
			$table->addColumn('board_id', Types::BIGINT, ['notnull' => false, 'length' => 8]);
			$table->addColumn('prefix', Types::STRING, ['notnull' => false, 'length' => BoardPrefix::MAX_LENGTH]);
			$table->addColumn('board_seq', Types::BIGINT, ['notnull' => false, 'length' => 8]);
		}
		if (!$table->hasIndex('kanso_cmove_alias_ref')) {
			$table->addUniqueIndex(['board_id', 'prefix', 'board_seq'], 'kanso_cmove_alias_ref');
		}
		return $schema;
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->db->executeStatement('INSERT INTO *PREFIX*kanso_card_sequences (board_id, last_seq) '
			. 'SELECT b.id, COALESCE(MAX(c.board_seq), 0) FROM *PREFIX*kanso_boards b '
			. 'LEFT JOIN *PREFIX*kanso_cards c ON c.board_id = b.id '
			. 'WHERE NOT EXISTS (SELECT 1 FROM *PREFIX*kanso_card_sequences s WHERE s.board_id = b.id) GROUP BY b.id');

		$qb = $this->db->getQueryBuilder();
		$qb->select('a.id', 'c.board_id', 'c.board_seq', 'b.prefix')
			->from('kanso_card_move_aliases', 'a')
			->innerJoin('a', 'kanso_cards', 'c', $qb->expr()->eq('c.id', 'a.old_card_id'))
			->innerJoin('c', 'kanso_boards', 'b', $qb->expr()->eq('b.id', 'c.board_id'))
			->where($qb->expr()->isNull('a.board_id'))
			->andWhere($qb->expr()->isNotNull('c.board_seq'));
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$update = $this->db->getQueryBuilder();
			$update->update('kanso_card_move_aliases')
				->set('board_id', $update->createNamedParameter((int)$row['board_id'], IQueryBuilder::PARAM_INT))
				->set('prefix', $update->createNamedParameter($row['prefix'] ?? BoardPrefix::DEFAULT))
				->set('board_seq', $update->createNamedParameter((int)$row['board_seq'], IQueryBuilder::PARAM_INT))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
			$update->executeStatement();
		}
		$result->closeCursor();
	}
}
