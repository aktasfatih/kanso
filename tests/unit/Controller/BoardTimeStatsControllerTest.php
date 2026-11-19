<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Controller\BoardTimeStatsController;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\BoardTimeStatsService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BoardTimeStatsControllerTest extends TestCase {
	private IUserSession&MockObject $session;
	private BoardService&MockObject $boards;
	private BoardAccess&MockObject $access;
	private BoardTimeStatsService&MockObject $stats;
	private BoardTimeStatsController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->session = $this->createMock(IUserSession::class);
		$this->boards = $this->createMock(BoardService::class);
		$this->access = $this->createMock(BoardAccess::class);
		$this->stats = $this->createMock(BoardTimeStatsService::class);
		$this->controller = new BoardTimeStatsController('kanso', $this->createMock(IRequest::class), $this->session, $this->boards, $this->access, $this->stats);
	}

	private function authenticate(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);
	}

	public function testRequiresAuthenticationBeforeReadingTheBoard(): void {
		$this->boards->expects(self::never())->method('find');
		$this->stats->expects(self::never())->method('get');

		self::assertSame(403, $this->controller->show(7)->getStatus());
	}

	public function testRequiresBoardReadAccessBeforeAggregating(): void {
		$this->authenticate();
		$this->boards->expects(self::once())->method('find')->with(7, 'alice')->willThrowException(new NotPermittedException());
		$this->stats->expects(self::never())->method('get');
		$this->access->expects(self::never())->method('contextFor');

		self::assertSame(403, $this->controller->show(7)->getStatus());
	}

	public function testMissingBoardReturnsNotFound(): void {
		$this->authenticate();
		$this->boards->method('find')->willThrowException(new DoesNotExistException('missing'));
		$this->stats->expects(self::never())->method('get');

		self::assertSame(404, $this->controller->show(7)->getStatus());
	}

	public function testForwardsOnlyTheResolvedViewerAndRequestedDates(): void {
		$this->authenticate();
		$board = new Board();
		$board->setId(7);
		$this->boards->expects(self::once())->method('find')->with(7, 'alice')->willReturn($board);
		$viewer = ViewerContext::forMember('alice', 7, ViewerContext::ROLE_EXTERNAL, false);
		$this->access->expects(self::once())->method('contextFor')->with($board, 'alice')->willReturn($viewer);
		$expected = ['enabled' => true, 'from' => '2026-11-01', 'to' => '2026-11-18', 'totalSeconds' => 300, 'byUser' => []];
		$this->stats->expects(self::once())->method('get')->with($board, $viewer, '2026-11-01', '2026-11-18')->willReturn($expected);

		$response = $this->controller->show(7, '2026-11-01', '2026-11-18');

		self::assertSame(200, $response->getStatus());
		self::assertSame($expected, $response->getData());
	}

	public function testInvalidDatesReturnBadRequest(): void {
		$this->authenticate();
		$this->boards->method('find')->willReturn(new Board());
		$this->access->method('contextFor')->willReturn(ViewerContext::forMember('alice', 7, ViewerContext::ROLE_INTERNAL, true));
		$this->stats->method('get')->willThrowException(new InvalidInputException('Dates must be YYYY-MM-DD'));

		self::assertSame(400, $this->controller->show(7, 'bad-date')->getStatus());
	}
}
