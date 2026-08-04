<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardPinMapper;
use OCA\Kanso\Service\BoardPinService;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BoardPinServiceTest extends TestCase {
	private BoardPinMapper&MockObject $pinMapper;
	private BoardService&MockObject $boardService;
	private BoardPinService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->pinMapper = $this->createMock(BoardPinMapper::class);
		$this->boardService = $this->createMock(BoardService::class);
		$this->service = new BoardPinService($this->pinMapper, $this->boardService);
	}

	private function board(int $id): Board {
		$board = new Board();
		$board->setId($id);
		$board->setDeletedAt(0);
		return $board;
	}

	// ── pin (READ-gated + scoped to caller) ─────────────────────────────────────

	public function testPinReadGatesThenPinsForCaller(): void {
		// find() asserts READ; returning a board means it passed.
		$this->boardService->expects(self::once())->method('find')->with(7, 'alice')->willReturn($this->board(7));
		// The pin is written for the CALLER's uid (per-user isolation).
		$this->pinMapper->expects(self::once())->method('pin')->with('alice', 7);

		$this->service->pin('alice', 7);
	}

	public function testPinIsScopedPerUser(): void {
		// A different user's pin of the same board writes under THEIR uid, never
		// touching alice's - the mapper is always called with the caller's uid.
		$this->boardService->method('find')->with(7, 'bob')->willReturn($this->board(7));
		$this->pinMapper->expects(self::once())->method('pin')->with('bob', 7);

		$this->service->pin('bob', 7);
	}

	public function testPinOfBoardYouCannotReadIsDenied(): void {
		// find() enforces board READ and throws for an unreadable board.
		$this->boardService->method('find')->with(99, 'mallory')
			->willThrowException(new NotPermittedException('nope'));
		// The read-gate fails BEFORE any pin is written (IDOR guard).
		$this->pinMapper->expects(self::never())->method('pin');

		$this->expectException(NotPermittedException::class);
		$this->service->pin('mallory', 99);
	}

	public function testPinOfMissingBoardThrows(): void {
		$this->boardService->method('find')->with(50, 'alice')
			->willThrowException(new DoesNotExistException('gone'));
		$this->pinMapper->expects(self::never())->method('pin');

		$this->expectException(DoesNotExistException::class);
		$this->service->pin('alice', 50);
	}

	// ── unpin (own row only, no read-gate) ──────────────────────────────────────

	public function testUnpinRemovesOwnPinWithoutReadGate(): void {
		// Unpinning is always allowed for one's own row - no board read needed,
		// so a board that is no longer readable can still be unpinned.
		$this->boardService->expects(self::never())->method('find');
		$this->pinMapper->expects(self::once())->method('unpin')->with('alice', 7);

		$this->service->unpin('alice', 7);
	}
}
