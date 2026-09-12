<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Db\BoardCascade;
use PHPUnit\Framework\TestCase;

/**
 * The anti-rot guard for the board purge.
 *
 * Kanso's schema declares ZERO foreign keys, so a board purge cascades only to
 * the tables it names. That list is exactly the kind of thing that rots
 * silently: someone adds a board-scoped table, every test still passes, and
 * deleted boards quietly leave rows behind forever with nothing to notice.
 *
 * So this scans the migrations for every table the app creates and asserts each
 * one is ACCOUNTED FOR - registered in {@see BoardCascade} (by board id, by card
 * id or by a parent id), handled by its own mapper (the boards and cards tables,
 * the roots of the graph), or declared not board-scoped on purpose. A new table
 * therefore cannot ship without someone deciding what a board purge does with
 * it: adding it to the schema fails this test until it is classified.
 *
 * Static source scan, no database needed.
 */
class BoardCascadeCompletenessTest extends TestCase {
	private const MIGRATION_DIR = __DIR__ . '/../../../lib/Migration';

	/**
	 * The two tables the cascade registry deliberately omits because they are
	 * the roots of the board graph, not dependents of it, and are deleted
	 * through their own mappers as the last two steps of a purge:
	 * BoardPurgeService calls the card mapper's deleteByBoard() and then the
	 * board mapper's deleteById().
	 */
	private const HANDLED_BY_OWN_MAPPER = [
		'kanso_cards',
		'kanso_boards',
	];

	/**
	 * Every logical table name passed to createTable() across all migrations,
	 * mapped to the set of columns the migrations add to it.
	 *
	 * Each createTable()/getTable() call opens a block that runs until the next
	 * such call, so the addColumn() names in between belong to that table.
	 *
	 * @return array<string, list<string>> table => sorted column names
	 */
	private static function schemaInMigrations(): array {
		$tables = [];
		$columns = [];
		foreach (glob(self::MIGRATION_DIR . '/*.php') ?: [] as $file) {
			$content = file_get_contents($file);
			self::assertIsString($content, 'unreadable migration: ' . $file);
			preg_match_all(
				"/(createTable|getTable)\('([a-z0-9_]+)'\)/",
				$content,
				$matches,
				PREG_OFFSET_CAPTURE,
			);
			foreach ($matches[2] as $index => [$table, $_]) {
				if ($matches[1][$index][0] === 'createTable') {
					$tables[$table] = true;
				}
				$start = $matches[0][$index][1] + strlen($matches[0][$index][0]);
				$end = $matches[0][$index + 1][1] ?? strlen($content);
				preg_match_all(
					"/addColumn\('([a-z0-9_]+)'/",
					substr($content, $start, $end - $start),
					$columnMatches,
				);
				foreach ($columnMatches[1] as $column) {
					$columns[$table][$column] = true;
				}
			}
		}

		$schema = [];
		foreach (array_keys($tables) as $table) {
			$names = array_keys($columns[$table] ?? []);
			sort($names);
			$schema[$table] = $names;
		}
		ksort($schema);

		return $schema;
	}

	/**
	 * Every logical table name passed to createTable() across all migrations.
	 *
	 * @return list<string>
	 */
	private static function tablesInMigrations(): array {
		return array_keys(self::schemaInMigrations());
	}

	public function testEveryTableIsClassifiedForTheBoardPurge(): void {
		$registered = array_merge(
			BoardCascade::BY_BOARD_ID,
			array_keys(BoardCascade::BY_CARD_ID),
			array_keys(BoardCascade::BY_PARENT_ID),
			BoardCascade::NOT_BOARD_SCOPED,
			self::HANDLED_BY_OWN_MAPPER,
		);
		$registered = array_values(array_unique($registered));
		sort($registered);

		$found = self::tablesInMigrations();
		self::assertNotEmpty($found, 'the migration scan found no tables at all');

		self::assertSame(
			[],
			array_values(array_diff($found, $registered)),
			'A kanso_ table exists that the board purge knows nothing about. Deleting a '
			. 'board would leave its rows behind forever, unreachable and unreapable. '
			. 'Classify it in BoardCascade: BY_BOARD_ID (it has a board_id), BY_CARD_ID '
			. '(it hangs off a card), BY_PARENT_ID (it hangs off a row in one of those) '
			. 'or NOT_BOARD_SCOPED (it genuinely outlives the board - say why).',
		);

		self::assertSame(
			[],
			array_values(array_diff($registered, $found)),
			'The board purge names a table the migrations do not create. Drop the stale '
			. 'entry from BoardCascade - a DELETE against a table that does not exist '
			. 'fails the whole purge transaction.',
		);
	}

	/**
	 * The other half of registry rot, and the more dangerous one: a table name
	 * that exists but a COLUMN name that does not. The purge would then throw
	 * "column does not exist" inside its transaction and abort - for every
	 * board, forever, with nothing but a cron log line to show for it. Names are
	 * only ever checked against the real schema at run time, so check them here.
	 */
	public function testEveryColumnTheCascadeNamesActuallyExists(): void {
		$schema = self::schemaInMigrations();
		$declared = [];
		foreach (BoardCascade::BY_BOARD_ID as $table) {
			$declared[$table][] = 'board_id';
		}
		foreach (BoardCascade::BY_CARD_ID as $table => $columns) {
			foreach ($columns as $column) {
				$declared[$table][] = $column;
			}
		}
		foreach (BoardCascade::BY_PARENT_ID as $table => [$column, $parentTable, $parentLink]) {
			$declared[$table][] = $column;
			$declared[$parentTable][] = $parentLink;
		}

		foreach ($declared as $table => $columns) {
			foreach (array_unique($columns) as $column) {
				self::assertContains(
					$column,
					$schema[$table] ?? [],
					'BoardCascade purges ' . $table . ' by a column `' . $column . '` that no '
					. 'migration ever adds. The DELETE would fail at run time and abort the '
					. 'whole purge transaction, for every board.',
				);
			}
		}
	}

	public function testEveryGrandchildParentIsItselfPurged(): void {
		$purged = array_merge(
			BoardCascade::BY_BOARD_ID,
			array_keys(BoardCascade::BY_CARD_ID),
		);

		foreach (BoardCascade::BY_PARENT_ID as $table => [, $parentTable]) {
			self::assertContains(
				$parentTable,
				$purged,
				$table . ' is purged via ' . $parentTable . ', but that parent is not itself '
				. 'board-scoped - the id lookup would return nothing and the rows would survive.',
			);
		}
	}

	public function testNoTableIsBothPurgedAndDeclaredOutOfScope(): void {
		$purged = array_merge(
			BoardCascade::BY_BOARD_ID,
			array_keys(BoardCascade::BY_CARD_ID),
			array_keys(BoardCascade::BY_PARENT_ID),
		);

		self::assertSame(
			[],
			array_values(array_intersect(BoardCascade::NOT_BOARD_SCOPED, $purged)),
			'A table is declared NOT_BOARD_SCOPED and purged anyway. This is the one '
			. 'card on the board that destroys user data irreversibly - the two lists '
			. 'must not disagree about what survives a board delete.',
		);
	}
}
