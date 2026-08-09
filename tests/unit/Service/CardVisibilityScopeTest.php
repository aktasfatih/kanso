<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Service\CardVisibilityScope;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The three visibility branches (#3741), asserted against a RENDERING
 * query-builder mock: expression-builder calls compose readable strings and
 * createNamedParameter() inlines the bound value, so each test pins the
 * exact WHERE shape - which branch exists, which role/owner/board values
 * are bound - not just "some filter was added".
 */
class CardVisibilityScopeTest extends TestCase {
	private CardVisibilityScope $scope;
	/** @var list<string> every andWhere() argument, rendered */
	private array $wheres = [];

	protected function setUp(): void {
		parent::setUp();
		$this->scope = new CardVisibilityScope();
		$this->wheres = [];
	}

	/**
	 * A query-builder mock whose expression builder renders comparisons as
	 * strings (eq/in/andX/orX) and whose named parameters inline the value:
	 * eq(c.visibility, 'public'), in(c.board_id, [1,2]), or(a, b), and(a, b).
	 */
	private function qb(): IQueryBuilder {
		$expr = new class {
			public function eq(string $x, string $y): string {
				return "eq($x, $y)";
			}

			public function in(string $x, string $y): string {
				return "in($x, $y)";
			}

			public function andX(mixed ...$parts): string {
				return 'and(' . implode(', ', $parts) . ')';
			}

			public function orX(mixed ...$parts): string {
				return 'or(' . implode(', ', $parts) . ')';
			}
		};

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR),
		);
		$qb->method('andWhere')->willReturnCallback(function (mixed $where) use ($qb): IQueryBuilder {
			$this->wheres[] = (string)$where;
			return $qb;
		});
		return $qb;
	}

	public function testBoardScopedInternalViewerGetsAllThreeBranches(): void {
		$this->scope->apply($this->qb(), 'c', 'alice', 7, [7 => ViewerContext::ROLE_INTERNAL]);

		self::assertSame([
			// public OR (internal AND creator side = viewer side) OR (private AND own card)
			'or(eq(c.visibility, "public"), '
				. 'and(eq(c.visibility, "internal"), eq(c.creator_role, "internal")), '
				. 'and(eq(c.visibility, "private"), eq(c.owner, "alice")))',
			'eq(c.board_id, 7)',
		], $this->wheres);
	}

	public function testBoardScopedExternalViewerMatchesExternalCreators(): void {
		// The symmetric half: an external (client-side) viewer's internal
		// branch matches EXTERNAL-created internal cards - and, by the same
		// single eq, can never match provider-side internal cards.
		$this->scope->apply($this->qb(), 'c', 'bob', 7, [7 => ViewerContext::ROLE_EXTERNAL]);

		self::assertStringContainsString(
			'and(eq(c.visibility, "internal"), eq(c.creator_role, "external"))',
			$this->wheres[0],
		);
	}

	public function testBoardScopedViewerWithoutRoleGetsNoInternalBranch(): void {
		// No resolved role on this board → the internal branch is DROPPED
		// entirely (never a wildcard): only public and own-private remain.
		$this->scope->apply($this->qb(), 'c', 'eve', 7, []);

		self::assertSame([
			'or(eq(c.visibility, "public"), '
				. 'and(eq(c.visibility, "private"), eq(c.owner, "eve")))',
			'eq(c.board_id, 7)',
		], $this->wheres);
	}

	public function testCrossBoardAppliesTheRolePerBoard(): void {
		// One query over many boards: the internal branch splits into
		// per-side board_id IN (...) lists, so the role that holds on EACH
		// board applies - board 9's external membership can never leak
		// board 1/2's provider-internal cards.
		$this->scope->apply($this->qb(), 'c', 'alice', null, [
			1 => ViewerContext::ROLE_INTERNAL,
			2 => ViewerContext::ROLE_INTERNAL,
			9 => ViewerContext::ROLE_EXTERNAL,
		]);

		self::assertSame([
			'or(eq(c.visibility, "public"), '
				. 'and(eq(c.visibility, "internal"), or('
					. 'and(in(c.board_id, [1,2]), eq(c.creator_role, "internal")), '
					. 'and(in(c.board_id, [9]), eq(c.creator_role, "external")))), '
				. 'and(eq(c.visibility, "private"), eq(c.owner, "alice")))',
		], $this->wheres);
	}

	public function testCrossBoardSingleSideSkipsTheInnerOr(): void {
		$this->scope->apply($this->qb(), 'c', 'alice', null, [
			1 => ViewerContext::ROLE_INTERNAL,
			2 => ViewerContext::ROLE_INTERNAL,
		]);

		self::assertSame([
			'or(eq(c.visibility, "public"), '
				. 'and(eq(c.visibility, "internal"), '
					. 'and(in(c.board_id, [1,2]), eq(c.creator_role, "internal"))), '
				. 'and(eq(c.visibility, "private"), eq(c.owner, "alice")))',
		], $this->wheres);
	}

	public function testCrossBoardWithNoMembershipsKeepsOnlyPublicAndOwnPrivate(): void {
		$this->scope->apply($this->qb(), 'c', 'eve', null, []);

		self::assertSame([
			'or(eq(c.visibility, "public"), '
				. 'and(eq(c.visibility, "private"), eq(c.owner, "eve")))',
		], $this->wheres);
	}

	public function testUnknownRoleValueInMapIsIgnored(): void {
		// Defensive: a role outside the two sides never reaches SQL - the
		// board simply contributes no internal branch (mirrors
		// ViewerContext's a-typo-must-not-read-as-anything stance).
		$this->scope->apply($this->qb(), 'c', 'alice', null, [1 => 'admin']);

		self::assertSame([
			'or(eq(c.visibility, "public"), '
				. 'and(eq(c.visibility, "private"), eq(c.owner, "alice")))',
		], $this->wheres);
	}
}
