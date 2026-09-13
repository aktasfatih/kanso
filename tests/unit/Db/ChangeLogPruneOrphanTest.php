<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Db\ChangeDetailMapper;
use OCA\Kanso\Db\ChangeMapper;
use OCA\Kanso\Service\CardVisibilityScope;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The change-log prune must not strand its side table.
 *
 * A `kanso_change_details` row carries neither a board id nor a card id, so its
 * parent `kanso_changes` row is the only handle anything has on it: delete the
 * parent alone and the child is unreachable instance-wide, by every purge path
 * there is. This test does not settle for "the child delete was called" - it
 * REPLAYS the DELETE statements the mappers actually issue against a two-table
 * in-memory store and then SCANS the surviving detail rows for one whose parent
 * id is gone. Skip the child delete in
 * {@see ChangeMapper::deleteByIds()} and the scan below goes red.
 *
 * The reverse direction is asserted just as explicitly: the child delete is
 * scoped to the pruned change ids, so a detail belonging to a change row that is
 * NOT being pruned must still be there afterwards.
 */
class ChangeLogPruneOrphanTest extends TestCase {
	/** @var array<int, array{id: int, board_id: int}> id → row */
	private array $changes = [];

	/** @var array<int, array{id: int, change_id: int}> id → row */
	private array $details = [];

	/** @var list<string> every DELETE the code issued, as "table:column", in order */
	private array $statements = [];

	/** The longest id list any single statement bound. */
	private int $widestIdList = 0;

	private ChangeMapper $mapper;

	protected function setUp(): void {
		parent::setUp();

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->newDeleteQb());
		$this->mapper = new ChangeMapper($db, new CardVisibilityScope(), new ChangeDetailMapper($db));
	}

	/**
	 * Seeds $changeCount change rows (ids 1..n, two boards) and one detail row
	 * per change (detail id = 100 + change id), the shape the description-edit
	 * and attachment verbs write.
	 */
	private function seed(int $changeCount): void {
		for ($id = 1; $id <= $changeCount; $id++) {
			$this->changes[$id] = ['id' => $id, 'board_id' => $id % 2 === 0 ? 7 : 9];
			$this->details[100 + $id] = ['id' => 100 + $id, 'change_id' => $id];
		}
	}

	/**
	 * A query builder that records and APPLIES one `DELETE FROM <table> WHERE
	 * <column> IN (<ids>)` against the in-memory store - the only statement
	 * shape either delete path builds.
	 */
	private function newDeleteQb(): IQueryBuilder&MockObject {
		$state = new \stdClass();
		$state->table = '';
		$state->column = '';
		$state->ids = [];

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('delete')->willReturnCallback(static function (mixed $table) use ($qb, $state): IQueryBuilder {
			$state->table = (string)$table;
			return $qb;
		});
		$qb->method('createNamedParameter')->willReturnCallback(static function (mixed $value) use ($state): string {
			$state->ids = array_map('intval', (array)$value);
			return '?';
		});
		$qb->method('expr')->willReturn(new class($state) {
			public function __construct(
				private \stdClass $state,
			) {
			}

			public function in(string $column, string $placeholder): string {
				$this->state->column = $column;
				return $column . ' IN (' . $placeholder . ')';
			}
		});
		$qb->method('where')->willReturnSelf();
		$qb->method('executeStatement')->willReturnCallback(fn (): int => $this->applyDelete($state));

		return $qb;
	}

	private function applyDelete(\stdClass $state): int {
		$this->statements[] = $state->table . ':' . $state->column;
		$this->widestIdList = max($this->widestIdList, count($state->ids));

		/** @var string $column */
		$column = $state->column;
		/** @var list<int> $ids */
		$ids = $state->ids;
		$keep = static fn (array $row): bool => !in_array($row[$column], $ids, true);

		if ($state->table === 'kanso_changes') {
			$before = count($this->changes);
			$this->changes = array_filter($this->changes, $keep);
			return $before - count($this->changes);
		}
		if ($state->table === 'kanso_change_details') {
			$before = count($this->details);
			$this->details = array_filter($this->details, $keep);
			return $before - count($this->details);
		}

		self::fail('the change prune issued a DELETE against an unexpected table: ' . $state->table);
	}

	/**
	 * THE assertion: detail rows whose parent change row no longer exists. Not a
	 * record of which calls were made - a scan of the state they left behind.
	 *
	 * @return list<int> orphaned detail ids
	 */
	private function scanForOrphans(): array {
		$orphans = [];
		foreach ($this->details as $detail) {
			if (!isset($this->changes[$detail['change_id']])) {
				$orphans[] = $detail['id'];
			}
		}

		return $orphans;
	}

	public function testPruningAChangeLeavesNoOrphanedDetailRows(): void {
		$this->seed(6);

		$this->mapper->deleteByIds([1, 2, 3]);

		self::assertSame([], $this->scanForOrphans(), 'pruned change rows left their detail rows behind');
		// The details of the pruned changes are gone, not merely unreachable.
		self::assertSame([104, 105, 106], array_keys($this->details));
	}

	public function testDetailsAreDeletedBeforeTheirParentChangeRows(): void {
		$this->seed(2);

		$this->mapper->deleteByIds([1, 2]);

		// Ordering is the whole fix: the child delete finds its rows by
		// change_id, which only resolves while the parents still exist.
		self::assertSame(
			['kanso_change_details:change_id', 'kanso_changes:id'],
			$this->statements,
		);
	}

	public function testDetailsOfRetainedChangeRowsSurvive(): void {
		$this->seed(4);

		$this->mapper->deleteByIds([2]);

		// The child delete is scoped to the pruned ids - it must not sweep the
		// side table for change rows that are staying.
		self::assertSame([101, 103, 104], array_keys($this->details));
		self::assertSame([1, 3, 4], array_keys($this->changes));
		self::assertSame([], $this->scanForOrphans());
	}

	public function testALargeBatchChunksTheChildDeleteAndStillLeavesNoOrphans(): void {
		$this->seed(2500);

		$this->mapper->deleteByIds(range(1, 2500));

		self::assertSame([], $this->scanForOrphans());
		self::assertSame([], $this->details);
		// 2500 ids → three chunked child DELETEs, then three chunked parent
		// ones. Neither side may build a single 2500-placeholder IN (...):
		// SQLite's default bound-variable limit is 999 on builds before 3.32.
		self::assertSame(
			[
				'kanso_change_details:change_id',
				'kanso_change_details:change_id',
				'kanso_change_details:change_id',
				'kanso_changes:id',
				'kanso_changes:id',
				'kanso_changes:id',
			],
			$this->statements,
		);
		self::assertSame(ChangeDetailMapper::DELETE_CHUNK_SIZE, $this->widestIdList);
	}

	public function testEmptyIdSetIssuesNoStatements(): void {
		$this->seed(2);

		self::assertSame(0, $this->mapper->deleteByIds([]));
		self::assertSame([], $this->statements);
		self::assertSame([], $this->scanForOrphans());
	}
}
