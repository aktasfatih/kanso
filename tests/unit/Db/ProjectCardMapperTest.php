<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Db\ProjectCardMapper;
use OCA\Kanso\Service\CardVisibilityScope;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Mapper-level test for the owner scoping of `projectIds` (#10737).
 *
 * Projects are private, owner-only collections, but collecting a card needs
 * only READ on its board - so an unscoped `findProjectIdsByCard` told every
 * reader of a shared card how many OTHER people had filed it away privately.
 *
 * The DB is mocked (as everywhere in tests/unit/Db), so what is asserted here
 * is the shape of the query that reaches it: the join to `kanso_projects` and
 * the `p.owner = <viewer>` predicate, with the viewer's uid actually bound to
 * it. Drop either and these tests fail. The end-to-end denial - peer A's
 * project never reaching peer B - is pinned in tests/e2e/card-attr-acl.spec.js
 * against a real database and two real users.
 */
class ProjectCardMapperTest extends TestCase {
	private IDBConnection&MockObject $db;
	private ProjectCardMapper $mapper;

	/** @var list<string> every where()/andWhere() predicate, in call order */
	private array $predicates = [];
	/** @var list<array<int, mixed>> every innerJoin() argument list */
	private array $joins = [];
	/** @var array<string, mixed> bound parameter token => value */
	private array $params = [];

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->mapper = new ProjectCardMapper($this->db, $this->createMock(CardVisibilityScope::class));
	}

	/**
	 * Stubs the query builder, recording the predicates, joins and bound
	 * parameters the mapper builds, and replaying $rows as the result set.
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	private function stubQuery(array $rows): void {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'orderBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		foreach (['where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(function (string $predicate) use ($qb): IQueryBuilder {
				$this->predicates[] = $predicate;
				return $qb;
			});
		}
		$qb->method('innerJoin')->willReturnCallback(
			function (...$args) use ($qb): IQueryBuilder {
				$this->joins[] = $args;
				return $qb;
			}
		);

		// An expression sink that renders each call as `name(arg, arg)`, so a
		// predicate carries the column and the parameter token it compares.
		$qb->method('expr')->willReturn(new class {
			public function __call(string $name, array $args): string {
				return $name . '(' . implode(',', array_map(strval(...), $args)) . ')';
			}
		});
		$next = 0;
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value) use (&$next): string {
				$token = ':p' . $next++;
				$this->params[$token] = $value;
				return $token;
			}
		);

		$result = $this->createMock(IResult::class);
		$queue = $rows;
		$result->method('fetch')->willReturnCallback(static function () use (&$queue) {
			$row = array_shift($queue);
			return $row ?? false;
		});
		$qb->method('executeQuery')->willReturn($result);

		$this->db->method('getQueryBuilder')->willReturn($qb);
	}

	/** The predicate that compares $column, or null if the query has none. */
	private function predicateOn(string $column): ?string {
		foreach ($this->predicates as $predicate) {
			if (str_contains($predicate, $column . ',')) {
				return $predicate;
			}
		}
		return null;
	}

	public function testFindProjectIdsByCardJoinsTheProjectsTable(): void {
		$this->stubQuery([]);

		$this->mapper->findProjectIdsByCard(9, 'bob');

		self::assertCount(1, $this->joins);
		[$fromAlias, $table, $alias] = $this->joins[0];
		self::assertSame('pc', $fromAlias);
		self::assertSame('kanso_projects', $table);
		self::assertSame('p', $alias);
	}

	public function testFindProjectIdsByCardFiltersByTheViewerAsOwner(): void {
		$this->stubQuery([]);

		$this->mapper->findProjectIdsByCard(9, 'bob');

		// THE denial: without this predicate every reader of the card sees every
		// collector's project id.
		$owner = $this->predicateOn('p.owner');
		self::assertNotNull($owner, 'the query must constrain p.owner');
		self::assertStringStartsWith('eq(p.owner,', $owner);

		// …and it must be the VIEWER that is bound to it, not some other uid.
		preg_match('/eq\(p\.owner,(:p\d+)\)/', $owner, $m);
		self::assertArrayHasKey($m[1], $this->params);
		self::assertSame('bob', $this->params[$m[1]]);
	}

	public function testFindProjectIdsByCardStillFiltersByTheCard(): void {
		$this->stubQuery([]);

		$this->mapper->findProjectIdsByCard(9, 'bob');

		$card = $this->predicateOn('pc.card_id');
		self::assertNotNull($card, 'the query must constrain pc.card_id');
		preg_match('/eq\(pc\.card_id,(:p\d+)\)/', $card, $m);
		self::assertSame(9, $this->params[$m[1]]);
	}

	public function testFindProjectIdsByCardReturnsTheRemainingIdsAsInts(): void {
		$this->stubQuery([
			['project_id' => '4'],
			['project_id' => '11'],
		]);

		self::assertSame([4, 11], $this->mapper->findProjectIdsByCard(9, 'alice'));
	}

	public function testFindProjectIdsByCardReturnsNothingWhenTheViewerOwnsNone(): void {
		// The read-only peer's case: the card IS in a project, but not in one of
		// theirs, so the owner filter leaves no row at all.
		$this->stubQuery([]);

		self::assertSame([], $this->mapper->findProjectIdsByCard(9, 'bob'));
	}
}
