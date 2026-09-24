<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\CardAttachmentMapper;
use OCA\Kanso\Service\CardVisibilityScope;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Mapper-level tests for the BOARD-wide attachment listing (#10670) - the one
 * query that answers "every file on this board" without opening each card.
 *
 * The DB is mocked and the query builder records every predicate it is handed,
 * so a test asserts WHICH filters the query really emits: delete one and its
 * assertion goes red, where a fed-rows-only test would happily keep passing.
 * That matters most for the visibility filter - the fed rows can not tell you
 * whether the hidden card's file was excluded by the DB or simply never fed.
 */
class CardAttachmentMapperTest extends TestCase {
	/**
	 * A spying expression builder recording every comparison as
	 * (operator, column, bound value). The OCP expression interface references
	 * Doctrine symbols that are not autoloadable in the unit env, so this is a
	 * __call sink - the same helper the sibling mapper tests use.
	 *
	 * @param list<array{op: string, col: mixed, value: mixed}> $collector
	 */
	private static function predicateSpy(array &$collector): object {
		return new class($collector) {
			/** @param list<array{op: string, col: mixed, value: mixed}> $seen */
			public function __construct(
				private array &$seen,
			) {
			}

			public function __call(string $name, array $args): string {
				$this->seen[] = [
					'op' => $name,
					'col' => $args[0] ?? null,
					'value' => $args[1] ?? null,
				];
				return '';
			}
		};
	}

	/** A harmless stand-in for the function builder (COUNT(*) and friends). */
	private static function funcSink(): object {
		return new class {
			public function __call(string $name, array $args): string {
				return 'fn';
			}
		};
	}

	/**
	 * Runs $call against a mapper whose query builder records every predicate
	 * and feeds back $rows.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @param callable(CardAttachmentMapper): mixed $call
	 * @param array{maxResults: ?int, firstResult: ?int} $paging filled in with what the query asked the DB for
	 * @return list<array{op: string, col: mixed, value: mixed}>
	 */
	private function recordQuery(array $rows, callable $call, mixed &$returned = null, ?array &$paging = null): array {
		$predicates = [];
		$paging = ['maxResults' => null, 'firstResult' => null];

		$qb = $this->createMock(IQueryBuilder::class);
		foreach ([
			'select', 'selectAlias', 'addSelect', 'from', 'innerJoin',
			'where', 'andWhere', 'orderBy', 'addOrderBy',
		] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('setMaxResults')->willReturnCallback(function (?int $value) use (&$paging, &$qb): IQueryBuilder {
			$paging['maxResults'] = $value;
			return $qb;
		});
		$qb->method('setFirstResult')->willReturnCallback(function (?int $value) use (&$paging, &$qb): IQueryBuilder {
			$paging['firstResult'] = $value;
			return $qb;
		});
		$qb->method('expr')->willReturn(self::predicateSpy($predicates));
		$qb->method('func')->willReturn(self::funcSink());
		// Identity, so the recorded predicates carry the real bound values.
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => $value);

		$result = $this->createMock(IResult::class);
		$queue = $rows;
		$result->method('fetch')->willReturnCallback(static function () use (&$queue) {
			$row = array_shift($queue);
			return $row ?? false;
		});
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$returned = $call(new CardAttachmentMapper($db, new CardVisibilityScope()));

		return $predicates;
	}

	/**
	 * @param list<array{op: string, col: mixed, value: mixed}> $predicates
	 * @return list<mixed> the bound values of every $op comparison on $column
	 */
	private static function boundValues(array $predicates, string $op, string $column): array {
		$values = [];
		foreach ($predicates as $predicate) {
			if ($predicate['op'] === $op && $predicate['col'] === $column) {
				$values[] = $predicate['value'];
			}
		}
		return $values;
	}

	private static function viewer(int $boardId = 7, string $role = ViewerContext::ROLE_INTERNAL, string $uid = 'alice'): ViewerContext {
		return ViewerContext::forMember($uid, $boardId, $role, true);
	}

	/**
	 * One row as the joined query returns it: the attachment's own columns plus
	 * the owning card's title under its alias.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(int $id, int $cardId, string $filename, string $cardTitle): array {
		return [
			'id' => $id,
			'card_id' => $cardId,
			'board_id' => 7,
			'filename' => $filename,
			'mime' => 'text/plain',
			'size' => 12,
			'uploaded_by' => 'bob',
			'created_at' => 1700000000,
			'card_title' => $cardTitle,
		];
	}

	// ---- the board listing ---------------------------------------------------

	/**
	 * Happy path: each row becomes an attachment entity plus the owning card's
	 * title, so the listing can name the file AND the card to jump to.
	 */
	public function testFindByBoardPairsEachAttachmentWithItsCardTitle(): void {
		$rows = null;
		$this->recordQuery(
			[self::row(1, 30, 'spec.pdf', 'Write the spec'), self::row(2, 31, 'logo.png', 'Design')],
			fn (CardAttachmentMapper $m) => $m->findByBoard(7, self::viewer(), 50, 0),
			$rows
		);

		self::assertCount(2, $rows);
		self::assertSame('spec.pdf', $rows[0]['attachment']->getFilename());
		self::assertSame(30, $rows[0]['attachment']->getCardId());
		self::assertSame('Write the spec', $rows[0]['cardTitle']);
		self::assertSame('logo.png', $rows[1]['attachment']->getFilename());
		self::assertSame('Design', $rows[1]['cardTitle']);
	}

	/**
	 * The joined title column is NOT an entity column: it has to be stripped
	 * before hydration or the row would blow up on a missing setter. Keeping the
	 * entity intact is what lets the controller reuse the ONE jsonSerialize().
	 */
	public function testFindByBoardHydratesAWholeEntityAndNeverLeaksTheStorageKey(): void {
		$rows = null;
		$this->recordQuery(
			[self::row(1, 30, 'spec.pdf', 'Write the spec')],
			fn (CardAttachmentMapper $m) => $m->findByBoard(7, self::viewer(), 50, 0),
			$rows
		);

		$serialized = $rows[0]['attachment']->jsonSerialize();
		self::assertSame(
			['id' => 1, 'cardId' => 30, 'filename' => 'spec.pdf', 'mime' => 'text/plain', 'size' => 12, 'uploadedBy' => 'bob', 'createdAt' => 1700000000],
			$serialized
		);
		// The opaque object name is not even SELECTed by this listing, so it can
		// not ride along in a response by accident.
		self::assertNull($rows[0]['attachment']->getStorageKey());
	}

	/**
	 * The filters that make the listing MEAN "this board's live files": the
	 * board (twice - the aggregate's own denormalized filter plus the scope's
	 * board-scoped one, which is what stops a drifted board_id crossing boards)
	 * and non-trashed cards only.
	 */
	public function testFindByBoardStaysOnTheBoardAndSkipsTrashedCards(): void {
		$predicates = $this->recordQuery(
			[],
			fn (CardAttachmentMapper $m) => $m->findByBoard(7, self::viewer(), 50, 0)
		);

		self::assertSame([7], self::boundValues($predicates, 'eq', 'a.board_id'), 'the indexed board filter');
		self::assertSame([7], self::boundValues($predicates, 'eq', 'c.board_id'), 'and the joined card must be on the SAME board');
		self::assertSame([0], self::boundValues($predicates, 'eq', 'c.deleted_at'), 'a trashed card\'s files are not board content');
	}

	/**
	 * DENIAL - the security-critical assertion of this card. The listing is
	 * viewer-scoped in the QUERY, so an attachment on a card hidden from the
	 * viewer can never be returned: all three visibility branches are bound, the
	 * internal branch with the viewer's OWN side and the private branch with
	 * their OWN uid - never a wildcard.
	 *
	 * Remove the applyForViewer() call from findByBoard() and every assertion
	 * below goes red (the three visibility values, the role and the owner all
	 * disappear from the query) - that is the mutation this test exists to kill.
	 */
	public function testFindByBoardNeverListsAFileOfACardHiddenFromTheViewer(): void {
		$predicates = $this->recordQuery(
			[],
			fn (CardAttachmentMapper $m) => $m->findByBoard(7, self::viewer(7, ViewerContext::ROLE_EXTERNAL, 'exty'), 50, 0)
		);

		self::assertSame(
			[
				CardVisibilityScope::VISIBILITY_PUBLIC,
				CardVisibilityScope::VISIBILITY_INTERNAL,
				CardVisibilityScope::VISIBILITY_PRIVATE,
			],
			self::boundValues($predicates, 'eq', 'c.visibility'),
			'the visibility rule must be applied to the joined cards table'
		);
		self::assertSame(
			[ViewerContext::ROLE_EXTERNAL],
			self::boundValues($predicates, 'eq', 'c.creator_role'),
			'the internal branch must bind the VIEWER\'s side, not a wildcard'
		);
		self::assertSame(
			['exty'],
			self::boundValues($predicates, 'eq', 'c.owner'),
			'the private branch must bind the viewer as owner'
		);
	}

	/**
	 * The page is what the caller asked for, both halves of it - a cap that only
	 * limited the rows and ignored the offset would re-serve page one forever.
	 */
	public function testFindByBoardAsksTheDbForExactlyTheRequestedPage(): void {
		$paging = null;
		$this->recordQuery(
			[],
			fn (CardAttachmentMapper $m) => $m->findByBoard(7, self::viewer(), 25, 50),
			$ignored,
			$paging
		);

		self::assertSame(25, $paging['maxResults']);
		self::assertSame(50, $paging['firstResult']);
	}

	// ---- the total behind the page -------------------------------------------

	public function testCountByBoardReturnsTheAggregate(): void {
		$count = null;
		$this->recordQuery(
			[['cnt' => 9]],
			fn (CardAttachmentMapper $m) => $m->countByBoard(7, self::viewer()),
			$count
		);

		self::assertSame(9, $count);
	}

	/**
	 * A count that saw MORE than the listing does would itself leak: it would
	 * tell the viewer how many files hang off cards they may not see. So it
	 * carries the identical board, live-card and visibility filters, and the
	 * same mutation (dropping applyForViewer) turns this red too.
	 */
	public function testCountByBoardCountsOnlyWhatTheViewerMaySee(): void {
		$predicates = $this->recordQuery(
			[['cnt' => 0]],
			fn (CardAttachmentMapper $m) => $m->countByBoard(7, self::viewer(7, ViewerContext::ROLE_EXTERNAL, 'exty'))
		);

		self::assertSame([7], self::boundValues($predicates, 'eq', 'a.board_id'));
		self::assertSame([0], self::boundValues($predicates, 'eq', 'c.deleted_at'));
		self::assertSame(
			[
				CardVisibilityScope::VISIBILITY_PUBLIC,
				CardVisibilityScope::VISIBILITY_INTERNAL,
				CardVisibilityScope::VISIBILITY_PRIVATE,
			],
			self::boundValues($predicates, 'eq', 'c.visibility')
		);
		self::assertSame([ViewerContext::ROLE_EXTERNAL], self::boundValues($predicates, 'eq', 'c.creator_role'));
		self::assertSame(['exty'], self::boundValues($predicates, 'eq', 'c.owner'));
	}

	/**
	 * An empty result is an empty page, never a warning or a null row - a board
	 * with no files at all still answers.
	 */
	public function testFindByBoardOnABoardWithNoFilesIsAnEmptyPage(): void {
		$rows = null;
		$this->recordQuery([], fn (CardAttachmentMapper $m) => $m->findByBoard(7, self::viewer(), 50, 0), $rows);

		self::assertSame([], $rows);
	}
}
