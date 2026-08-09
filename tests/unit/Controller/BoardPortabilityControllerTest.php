<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Controller\BoardPortabilityController;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\ExportService;
use OCA\Kanso\Service\ImportService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BoardPortabilityControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private IUserSession&MockObject $userSession;
	private BoardService&MockObject $boardService;
	private ExportService&MockObject $exportService;
	private ImportService&MockObject $importService;
	private BoardAccess&MockObject $boardAccess;
	/** The board side contextFor resolves for the acting user (#3744). */
	private string $viewerRole = ViewerContext::ROLE_INTERNAL;
	private BoardPortabilityController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->boardService = $this->createMock(BoardService::class);
		$this->exportService = $this->createMock(ExportService::class);
		$this->importService = $this->createMock(ImportService::class);
		// export() resolves the viewer's context after the READ gate and hands
		// it to the viewer-scoped export (#3743). Role defaults to internal;
		// the #3744 external-denial tests flip $this->viewerRole.
		$this->boardAccess = $this->createMock(BoardAccess::class);
		$this->boardAccess->method('contextFor')->willReturnCallback(
			fn (Board $board, string $uid): ViewerContext => ViewerContext::forMember($uid, (int)$board->getId(), $this->viewerRole, true),
		);
		$this->controller = new BoardPortabilityController(
			'kanso',
			$this->request,
			$this->userSession,
			$this->boardService,
			$this->exportService,
			$this->importService,
			$this->boardAccess,
		);
	}

	private function loginAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testExportGatesOnBoardReadAndReturnsEnvelope(): void {
		$this->loginAs('alice');
		$board = new Board();
		$board->setId(5);
		// find() is the READ gate: called with the requesting uid.
		$this->boardService->expects(self::once())->method('find')->with(5, 'alice')->willReturn($board);
		$this->exportService->method('export')
			->with($board, self::isInstanceOf(ViewerContext::class))
			->willReturn(['kanso' => 1, 'exportedAt' => 1, 'board' => []]);

		$response = $this->controller->export(5);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(1, $response->getData()['kanso']);
	}

	public function testExportDeniedWhenNoReadPermission(): void {
		$this->loginAs('mallory');
		$this->boardService->method('find')->willThrowException(new NotPermittedException('nope'));

		$response = $this->controller->export(5);

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testImportMakesRequesterTheOwner(): void {
		$this->loginAs('carol');
		// The controller passes the CURRENT user as the importer/owner.
		$this->importService->expects(self::once())->method('import')
			->with('{"kanso":1}', 'carol')
			->willReturn(['boardId' => 42, 'title' => 'T', 'stacks' => 0, 'cards' => 0, 'labels' => 0]);

		$response = $this->controller->import('{"kanso":1}');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(42, $response->getData()['boardId']);
	}

	public function testImportBadDocumentIsBadRequest(): void {
		$this->loginAs('carol');
		$this->importService->method('import')->willThrowException(new InvalidInputException('bad version'));

		$response = $this->controller->import('{}');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('bad version', $response->getData()['error']);
	}

	// ── whole-board egress is internal-only (#3744) ───────────────────────────

	public function testExportIsForbiddenForExternalMembers(): void {
		$this->viewerRole = ViewerContext::ROLE_EXTERNAL;
		$this->loginAs('client');
		$board = new Board();
		$board->setId(5);
		$this->boardService->method('find')->with(5, 'client')->willReturn($board);
		// The denial must fire BEFORE any export content is built.
		$this->exportService->expects(self::never())->method('export');

		$response = $this->controller->export(5);

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testDuplicateIsForbiddenForExternalMembers(): void {
		$this->viewerRole = ViewerContext::ROLE_EXTERNAL;
		$this->loginAs('client');
		$board = new Board();
		$board->setId(5);
		$this->boardService->method('find')->with(5, 'client')->willReturn($board);
		$this->importService->expects(self::never())->method('duplicate');

		$response = $this->controller->duplicate(5, true);

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testDuplicateStillWorksForInternalMembers(): void {
		$this->loginAs('alice');
		$board = new Board();
		$board->setId(5);
		$this->boardService->method('find')->with(5, 'alice')->willReturn($board);
		$this->importService->expects(self::once())->method('duplicate')
			->with($board, 'alice', true)
			->willReturn(['boardId' => 43, 'title' => 'T (copy)', 'stacks' => 1, 'cards' => 2, 'labels' => 0]);

		$response = $this->controller->duplicate(5, true);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(43, $response->getData()['boardId']);
	}
}
