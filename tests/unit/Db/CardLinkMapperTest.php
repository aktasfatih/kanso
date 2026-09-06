<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Db\CardLinkMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Mapper-level test for the bound on a card's GitHub links. The DB is mocked, so
 * what is verified is the query CONTRACT: reading a card's links asks the
 * database for at most {@see CardLinkMapper::MAX_PER_CARD} rows (each returned
 * link can cost a blocking outbound poll), while the cap check counts without
 * that bound so rows beyond the cap are still seen.
 */
class CardLinkMapperTest extends TestCase {
	private IDBConnection&MockObject $db;
	private CardLinkMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->mapper = new CardLinkMapper($this->db);
	}

	/**
	 * A stand-in for the expression/function builders (see BoardPinMapperTest):
	 * any method call returns an empty string, avoiding a createMock() on the OCP
	 * builder interfaces (which reference non-autoloadable Doctrine symbols).
	 */
	private static function builderSink(): object {
		return new class {
			public function __call(string $name, array $args): string {
				return '';
			}
		};
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @param mixed $fetchOne value returned by fetchOne() (the count query)
	 */
	private function stubQuery(array $rows, mixed $fetchOne = false): IQueryBuilder&MockObject {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$sink = self::builderSink();
		$qb->method('expr')->willReturn($sink);
		$qb->method('func')->willReturn($sink);
		$qb->method('createNamedParameter')->willReturn('?');

		$result = $this->createMock(IResult::class);
		$queue = $rows;
		$result->method('fetch')->willReturnCallback(static function () use (&$queue) {
			$row = array_shift($queue);
			return $row ?? false;
		});
		$result->method('fetchOne')->willReturn($fetchOne);
		$qb->method('executeQuery')->willReturn($result);

		$this->db->method('getQueryBuilder')->willReturn($qb);
		return $qb;
	}

	public function testFindByCardBoundsTheRowsItAsksFor(): void {
		$qb = $this->stubQuery([
			['id' => 1, 'card_id' => 9, 'url' => 'https://github.com/octo/app/pull/1'],
			['id' => 2, 'card_id' => 9, 'url' => 'https://github.com/octo/app/pull/2'],
		]);
		$qb->expects(self::once())->method('setMaxResults')
			->with(CardLinkMapper::MAX_PER_CARD)
			->willReturnSelf();

		$links = $this->mapper->findByCard(9);

		self::assertCount(2, $links);
		self::assertSame('https://github.com/octo/app/pull/1', $links[0]->getUrl());
	}

	public function testCountByCardIsNotBoundedByTheCap(): void {
		// The count is the cap check itself, so it must see rows beyond the cap -
		// a card that somehow holds more links still reports the true number.
		$qb = $this->stubQuery([], CardLinkMapper::MAX_PER_CARD + 5);
		$qb->expects(self::never())->method('setMaxResults');

		self::assertSame(CardLinkMapper::MAX_PER_CARD + 5, $this->mapper->countByCard(9));
	}
}
