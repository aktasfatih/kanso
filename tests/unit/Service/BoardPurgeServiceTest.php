<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\BoardCascade;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Service\BoardPurgeService;
use OCA\Kanso\Service\CardAttachmentService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BoardPurgeServiceTest extends TestCase {
	private IDBConnection&MockObject $db;
	private BoardMapper&MockObject $boardMapper;
	private CardMapper&MockObject $cardMapper;
	private BoardCascade&MockObject $cascade;
	private CardAttachmentService&MockObject $attachments;
	private LoggerInterface&MockObject $logger;
	private BoardPurgeService $service;

	/** @var list<string> ordered log of the destructive steps taken */
	private array $steps = [];

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->cascade = $this->createMock(BoardCascade::class);
		$this->attachments = $this->createMock(CardAttachmentService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new BoardPurgeService(
			$this->db,
			$this->boardMapper,
			$this->cardMapper,
			$this->cascade,
			$this->attachments,
			$this->logger,
		);
	}

	/**
	 * Wires every collaborator to append to {@see $steps} so the ORDER of the
	 * purge - bytes before rows, grandchildren before parents, board row last -
	 * is assertable rather than assumed.
	 *
	 * @param list<int> $cardIds
	 */
	private function recordSteps(array $cardIds, int $storageFailures = 0): void {
		$this->cardMapper->method('findAllIdsByBoard')->willReturn($cardIds);
		$this->attachments->method('deleteObjectsForCards')
			->willReturnCallback(function (array $ids) use ($storageFailures): int {
				$this->steps[] = 'storage:' . implode(',', $ids);
				return $storageFailures;
			});
		$this->cascade->method('idsByBoardId')
			->willReturnCallback(function (string $table): array {
				return match ($table) {
					// 6 is one of the board's cards; 99 is a foreign card id an
					// attachment row's board_id/card_id drift could point at.
					'kanso_card_attachments' => [6, 99],
					'kanso_changes' => [901],
					'kanso_mail_intake' => [77],
					default => [],
				};
			});
		$this->cascade->method('idsIn')
			->willReturnCallback(static function (string $table): array {
				return $table === 'kanso_card_attachments' ? [5] : [501];
			});
		$this->cascade->method('deleteIn')
			->willReturnCallback(function (string $table, string $column, array $ids): int {
				$this->steps[] = 'deleteIn:' . $table . ':' . implode(',', $ids);
				return count($ids);
			});
		$this->cascade->method('deleteByCardIds')
			->willReturnCallback(function (array $ids): int {
				$this->steps[] = 'cards-scoped:' . implode(',', $ids);
				return 0;
			});
		$this->cascade->method('deleteByBoardId')
			->willReturnCallback(function (int $boardId): int {
				$this->steps[] = 'board-scoped:' . $boardId;
				return 0;
			});
		$this->cardMapper->method('deleteByBoard')
			->willReturnCallback(function (int $boardId): int {
				$this->steps[] = 'cards:' . $boardId;
				return 0;
			});
		$this->boardMapper->method('deleteById')
			->willReturnCallback(function (int $boardId): int {
				$this->steps[] = 'board:' . $boardId;
				return 1;
			});
		$this->db->method('beginTransaction')->willReturnCallback(function (): void {
			$this->steps[] = 'begin';
		});
		$this->db->method('commit')->willReturnCallback(function (): void {
			$this->steps[] = 'commit';
		});
		$this->db->method('rollBack')->willReturnCallback(function (): void {
			$this->steps[] = 'rollback';
		});
	}

	public function testPurgeSweepsBytesFirstThenEveryRowInDependencyOrder(): void {
		$this->recordSteps([5, 6]);

		self::assertTrue($this->service->purge(42));

		self::assertSame(
			[
				// The bytes go first, outside the transaction entirely, and only
				// for cards that both hold an attachment row AND belong to this
				// board: the foreign id 99 must not survive the intersection, or
				// the purge would delete a live board's attachment bytes.
				'storage:6,5',
				'begin',
				// Grandchildren before the parents whose ids located them.
				'deleteIn:kanso_comment_reactions:501',
				'deleteIn:kanso_change_details:901',
				'deleteIn:kanso_mail_seen:77',
				'cards-scoped:5,6',
				'board-scoped:42',
				'cards:42',
				'board:42',
				'commit',
			],
			$this->steps,
		);
	}

	public function testStorageFailureLeavesTheBoardWholeAndReapable(): void {
		// One card's bytes survive the sweep. The board must NOT be half-purged:
		// nothing is deleted, the tombstone stays, and the next run retries it.
		$this->recordSteps([5, 6], storageFailures: 1);
		$this->db->expects(self::never())->method('beginTransaction');
		$this->cascade->expects(self::never())->method('deleteByBoardId');
		$this->cardMapper->expects(self::never())->method('deleteByBoard');
		$this->boardMapper->expects(self::never())->method('deleteById');
		$this->logger->expects(self::once())->method('warning');

		self::assertFalse($this->service->purge(42));
		self::assertSame(['storage:6,5'], $this->steps);
	}

	public function testTheByteSweepNeverLeavesTheBoardsOwnCards(): void {
		// The one way this service could destroy a SURVIVING board's data: an
		// attachment row whose board_id and card_id disagree pointing the sweep
		// at a foreign card, whose app-data folder is named by card id alone.
		$swept = [];
		$this->cardMapper->method('findAllIdsByBoard')->willReturn([5, 6]);
		$this->cascade->method('idsByBoardId')
			->willReturnCallback(static fn (string $table): array => $table === 'kanso_card_attachments'
				? [6, 99, 1234]
				: []);
		$this->cascade->method('idsIn')->willReturn([]);
		$this->cascade->method('deleteIn')->willReturn(0);
		$this->cascade->method('deleteByCardIds')->willReturn(0);
		$this->cascade->method('deleteByBoardId')->willReturn(0);
		$this->cardMapper->method('deleteByBoard')->willReturn(0);
		$this->boardMapper->method('deleteById')->willReturn(1);
		$this->attachments->method('deleteObjectsForCards')
			->willReturnCallback(function (array $ids) use (&$swept): int {
				$swept = $ids;
				return 0;
			});

		self::assertTrue($this->service->purge(42));
		self::assertSame([6], $swept);
	}

	public function testRowFailureRollsBackSoNoBoardIsLeftHalfDeleted(): void {
		$this->cardMapper->method('findAllIdsByBoard')->willReturn([5]);
		$this->attachments->method('deleteObjectsForCards')->willReturn(0);
		$this->cascade->method('idsByBoardId')->willReturn([]);
		$this->cascade->method('idsIn')->willReturn([]);
		$this->cascade->method('deleteIn')->willReturn(0);
		$this->cascade->method('deleteByCardIds')->willReturn(0);
		$this->cascade->method('deleteByBoardId')
			->willThrowException(new \RuntimeException('boom'));
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');
		// The board row must survive a mid-cascade failure, or the rows that were
		// already deleted become unattributable and the rest unreachable.
		$this->boardMapper->expects(self::never())->method('deleteById');

		$this->expectException(\RuntimeException::class);
		$this->service->purge(42);
	}

	public function testBoardWithoutCardsStillPurgesItsBoardScopedRows(): void {
		$this->recordSteps([]);

		self::assertTrue($this->service->purge(7));

		self::assertContains('board-scoped:7', $this->steps);
		self::assertContains('board:7', $this->steps);
	}

	public function testFindPurgeableDelegatesTheRetentionCutoff(): void {
		$this->boardMapper->expects(self::once())
			->method('findPurgeableIds')
			->with(1_700_000_000, 20)
			->willReturn([3, 4]);

		self::assertSame([3, 4], $this->service->findPurgeable(1_700_000_000, 20));
	}
}
