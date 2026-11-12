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
		if (!isset($options['tablePrefix']) || !is_string($options['tablePrefix'])) {
			throw new \InvalidArgumentException('The migration requires the database table prefix');
		}
		$floor = $this->retainedFloor($options['tablePrefix'] . 'kanso_cards');
		$this->db->executeStatement('INSERT INTO *PREFIX*kanso_card_sequences (board_id, last_seq) '
			. 'SELECT b.id, ? FROM *PREFIX*kanso_boards b '
			. 'WHERE NOT EXISTS (SELECT 1 FROM *PREFIX*kanso_card_sequences s WHERE s.board_id = b.id)',
			[$floor], [IQueryBuilder::PARAM_INT]);

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

	private function retainedFloor(string $table): int {
		$floor = $this->identityFloor($table);
		foreach ([
			'SELECT MAX(id) AS card_id, MAX(board_seq) AS board_seq FROM *PREFIX*kanso_cards',
			'SELECT MAX(old_card_id) AS old_id, MAX(target_card_id) AS target_id, MAX(board_seq) AS board_seq FROM *PREFIX*kanso_card_move_aliases',
		] as $sql) {
			$result = $this->db->executeQuery($sql);
			$row = $result->fetch();
			$result->closeCursor();
			if ($row === false) {
				throw new \RuntimeException('Card number history is unavailable');
			}
			foreach ($row as $value) {
				$floor = max($floor, $this->integerBound($value ?? 0));
			}
		}
		return $floor;
	}

	private function identityFloor(string $table): int {
		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE) {
			$result = $this->db->executeQuery('SELECT seq FROM sqlite_sequence WHERE name = ?', [$table]);
			$value = $result->fetchOne();
			$result->closeCursor();
			return $this->integerBound($value === false ? 0 : $value);
		}
		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES) {
			$result = $this->db->executeQuery("SELECT pg_get_serial_sequence(quote_ident(?), 'id') AS sequence_name, pg_sequence_last_value(pg_get_serial_sequence(quote_ident(?), 'id')::regclass) AS last_value", [$table, $table]);
			$row = $result->fetch();
			$result->closeCursor();
			if ($row === false || $row['sequence_name'] === null) {
				throw new \RuntimeException('Card identity sequence is unavailable');
			}
			return $this->integerBound($row['last_value'] ?? 0);
		}
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_MYSQL) {
			throw new \RuntimeException('Unsupported card identity provider');
		}
		$result = $this->db->executeQuery('SELECT VERSION()');
		$version = (string)$result->fetchOne();
		$result->closeCursor();
		$expiry = null;
		if (stripos($version, 'MariaDB') === false) {
			$result = $this->db->executeQuery('SELECT @@SESSION.information_schema_stats_expiry');
			$expiry = $this->integerBound($result->fetchOne());
			$result->closeCursor();
			$this->db->executeStatement('SET SESSION information_schema_stats_expiry = 0');
		}
		try {
			$result = $this->db->executeQuery('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
			$value = $result->fetchOne();
			$result->closeCursor();
			return $this->integerBound($value, nextValue: true);
		} finally {
			if ($expiry !== null) {
				$this->db->executeStatement('SET SESSION information_schema_stats_expiry = ?', [$expiry], [IQueryBuilder::PARAM_INT]);
			}
		}
	}

	private function integerBound(mixed $value, bool $nextValue = false): int {
		if ((!is_int($value) && !is_string($value)) || !preg_match('/^\d+$/D', (string)$value)) {
			throw new \RuntimeException('Invalid card number history');
		}
		$digits = ltrim((string)$value, '0');
		$max = (string)PHP_INT_MAX;
		if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
			return PHP_INT_MAX;
		}
		return max(0, (int)$digits - ($nextValue ? 1 : 0));
	}
}
