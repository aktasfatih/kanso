<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Composite UNIQUE index on kanso_cards (stack_id, sort_key, deleted_at).
 *
 * Two concurrent moves into the same gap can each read the same neighbours
 * under READ COMMITTED and derive the same fractional key (documented in
 * {@see \OCA\Kanso\Service\CardService::move()}). This index makes the second
 * writer's UPDATE fail with a unique-constraint violation instead of silently
 * persisting a duplicate key; CardService catches that, re-derives once, and
 * surfaces a retryable 409 if it still collides.
 *
 * deleted_at (non-null, default 0) is part of the key so soft-deleted rows -
 * which keep their sort_key - never collide with a live row that later reuses
 * the freed key: live rows all share deleted_at=0, while each soft-deleted row
 * carries a distinct deletion timestamp. This is portable across
 * Postgres/MySQL/SQLite (a plain composite unique index - no partial index,
 * no per-dialect SQL).
 *
 * Because the duplicate-key race existed before this index, an upgraded DB may
 * already carry colliding live rows that would make CREATE UNIQUE INDEX fail.
 * preSchemaChange() de-duplicates them first (self-healing): each loser gets a
 * disambiguated, still-valid key, and a later move re-derives a clean one.
 * Both steps are idempotent - a second run finds no duplicates and the index
 * already present.
 */
class Version001000Date20260729000000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Break any pre-existing duplicate (stack_id, sort_key) among LIVE rows so
	 * the unique index below can be created. Keeps the first row of each group
	 * and re-keys the rest; ordering self-heals on the next move. Uses the
	 * portable QueryBuilder (no per-dialect SQL).
	 */
	#[\Override]
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if (!$this->db->tableExists('kanso_cards')) {
			return;
		}

		// Pass 1: seed the FULL set of live (stack_id, sort_key) pairs currently
		// in use. Seeding every key up front - not just the rows already scanned
		// - guarantees a re-keyed loser can never land on an as-yet-unvisited
		// row's key and defeat the unique index the schema step then creates.
		/** @var array<string, true> $occupied composite "stackId\0sortKey" in use */
		$occupied = [];
		$scan = $this->db->getQueryBuilder();
		$scan->select('stack_id', 'sort_key')
			->from('kanso_cards')
			->where($scan->expr()->eq('deleted_at', $scan->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$rows = $scan->executeQuery();
		while ($row = $rows->fetch()) {
			$occupied[((int)$row['stack_id']) . "\0" . (string)$row['sort_key']] = true;
		}
		$rows->closeCursor();

		// Pass 2: keep the first row of each (stack_id, sort_key) group (stable
		// order), re-key the rest.
		/** @var array<string, true> $claimed groups whose first occurrence is kept */
		$claimed = [];
		$fixed = 0;
		$select = $this->db->getQueryBuilder();
		$select->select('id', 'stack_id', 'sort_key')
			->from('kanso_cards')
			->where($select->expr()->eq('deleted_at', $select->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('stack_id', 'ASC')
			->addOrderBy('sort_key', 'ASC')
			->addOrderBy('id', 'ASC');
		$result = $select->executeQuery();
		while ($row = $result->fetch()) {
			$stackId = (int)$row['stack_id'];
			$key = (string)$row['sort_key'];
			$composite = $stackId . "\0" . $key;
			if (!isset($claimed[$composite])) {
				$claimed[$composite] = true;
				continue;
			}

			// Disambiguate this loser against the full occupied set: append the
			// (unique) card id in base-36 plus a non-zero terminator, so the
			// result stays a valid sort key (0-9A-Z, never ending in '0') and is
			// unique within the stack. Record it so later losers avoid it too.
			$id = (int)$row['id'];
			$newKey = $key . strtoupper(base_convert((string)$id, 10, 36)) . 'I';
			while (isset($occupied[$stackId . "\0" . $newKey])) {
				$newKey .= 'I';
			}
			$occupied[$stackId . "\0" . $newKey] = true;

			$update = $this->db->getQueryBuilder();
			$update->update('kanso_cards')
				->set('sort_key', $update->createNamedParameter($newKey))
				->where($update->expr()->eq('id', $update->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$update->executeStatement();
			$fixed++;
		}
		$result->closeCursor();

		if ($fixed > 0) {
			$output->info('kanso: re-keyed ' . $fixed . ' duplicate live card sort key(s) before adding the unique index');
		}
	}

	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
	 *  stubs (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_cards')) {
			return null;
		}

		$table = $schema->getTable('kanso_cards');
		if ($table->hasIndex('kanso_cards_stack_sort_uniq')) {
			return null;
		}

		$table->addUniqueIndex(['stack_id', 'sort_key', 'deleted_at'], 'kanso_cards_stack_sort_uniq');

		return $schema;
	}
}
