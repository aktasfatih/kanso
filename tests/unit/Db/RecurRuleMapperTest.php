<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Db\RecurRule;
use OCA\Kanso\Db\RecurRuleMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Mapper-level tests for RecurRuleMapper::findByBoard(). The DB is mocked so
 * these verify the pure-PHP contract: the method correctly hydrates returned
 * rows and scopes the query to `board_id`. Trashed-template filtering lives in
 * RecurrenceService::listForBoard() (#67), not here — the mapper returns every
 * rule for the board (export relies on that).
 */
class RecurRuleMapperTest extends TestCase {
	private IDBConnection&MockObject $db;
	private RecurRuleMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->mapper = new RecurRuleMapper($this->db);
	}

	/**
	 * A stand-in for the expression builder: any method call returns an empty
	 * string (the mapper only passes these into where()/andWhere()/innerJoin(),
	 * which are self-returning no-ops in these tests).
	 */
	private static function exprSink(): object {
		return new class {
			public function __call(string $name, array $args): string {
				return '';
			}
		};
	}

	/**
	 * A spying expression builder that records the column name (first string
	 * argument) of every comparison into a shared collector. Lets a test assert
	 * that findByBoard() scopes the query to `board_id` — remove that filter and
	 * the matching assertion goes red.
	 *
	 * @param array<int, string> $collector
	 */
	private static function spyExpr(array &$collector): object {
		return new class($collector) {
			/** @param array<int, string> $seen */
			public function __construct(
				private array &$seen,
			) {
			}

			public function __call(string $name, array $args): string {
				if ($args !== [] && \is_string($args[0])) {
					$this->seen[] = $args[0];
				}
				return '';
			}
		};
	}

	/**
	 * Builds a fluent query-builder mock that ignores every chained call and,
	 * on executeQuery(), returns a result iterating $rows once (for findEntities).
	 *
	 * @param list<array<string, mixed>> $rows
	 * @param array<int, string>|null $columns filled with each filtered column when passed
	 */
	private function buildQb(array $rows, ?array &$columns = null): IQueryBuilder&MockObject {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'innerJoin', 'where', 'andWhere', 'orderBy', 'addOrderBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('expr')->willReturn(
			$columns !== null ? self::spyExpr($columns) : self::exprSink()
		);
		$qb->method('createNamedParameter')->willReturn('?');

		$result = $this->createMock(IResult::class);
		$queue = $rows;
		$result->method('fetch')->willReturnCallback(static function () use (&$queue) {
			$row = array_shift($queue);
			return $row ?? false;
		});
		$qb->method('executeQuery')->willReturn($result);

		return $qb;
	}

	/** Minimal valid RecurRule row as the DB would return it. */
	private static function ruleRow(int $id = 1, int $boardId = 7): array {
		return [
			'id' => $id,
			'board_id' => $boardId,
			'template_card_id' => 42,
			'target_stack_id' => 3,
			'mode' => RecurRule::MODE_CLONE,
			'rrule' => 'FREQ=WEEKLY',
			'duedate_policy' => RecurRule::POLICY_AT_OCCURRENCE,
			'duedate_offset_seconds' => 0,
			'skip_while_open' => false,
			'enabled' => true,
			'owner' => 'alice',
			'last_spawned_at' => 0,
			'next_occurrence_at' => 1000,
			'occurrences_spawned' => 0,
			'created_at' => 500,
			'timezone' => null,
		];
	}

	// ---- row hydration ---------------------------------------------------------

	public function testFindByBoardHydratesReturnedRules(): void {
		// Two rules come back from the DB for the board (the mock feeds the rows).
		$this->db->method('getQueryBuilder')->willReturn($this->buildQb([
			self::ruleRow(1, 7),
			self::ruleRow(2, 7),
		]));

		$rules = $this->mapper->findByBoard(7);

		self::assertCount(2, $rules);
		self::assertContainsOnlyInstancesOf(RecurRule::class, $rules);
		self::assertSame(1, $rules[0]->getId());
		self::assertSame(7, $rules[0]->getBoardId());
		self::assertSame(42, $rules[0]->getTemplateCardId());
		self::assertSame('FREQ=WEEKLY', $rules[0]->getRrule());
		self::assertSame(2, $rules[1]->getId());
	}

	public function testFindByBoardReturnsEmptyArrayWhenNoRules(): void {
		$this->db->method('getQueryBuilder')->willReturn($this->buildQb([]));

		self::assertSame([], $this->mapper->findByBoard(7));
	}

	// ---- filter assertions -----------------------------------------------------

	public function testFindByBoardFiltersOnBoardId(): void {
		// The spy records every column name passed to eq()/gt()/etc. so we can
		// assert the board_id scoping is present — dropping it makes this go red.
		$columns = [];
		$this->db->method('getQueryBuilder')->willReturn($this->buildQb([], $columns));

		$this->mapper->findByBoard(7);

		self::assertContains('board_id', $columns, 'findByBoard must filter on board_id');
	}

	/**
	 * The cron gate: findDueEnabled() only returns rules that are enabled, not
	 * exhausted (next_occurrence_at > 0) and actually due (next_occurrence_at <=
	 * now). Dropping any of those filters is how a not-yet-due rule would fire too
	 * early (the #80 family) or a disabled/exhausted rule would resurrect, so this
	 * pins that the query is built against both columns.
	 */
	public function testFindDueEnabledFiltersOnEnabledAndNextOccurrence(): void {
		$columns = [];
		$this->db->method('getQueryBuilder')->willReturn($this->buildQb([], $columns));

		$this->mapper->findDueEnabled(1_800_000_000);

		self::assertContains('enabled', $columns, 'findDueEnabled must filter on enabled');
		self::assertContains('next_occurrence_at', $columns, 'findDueEnabled must filter on next_occurrence_at (the due-time gate)');
	}
}
