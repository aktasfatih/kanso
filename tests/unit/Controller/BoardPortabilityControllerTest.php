<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Controller\BoardPortabilityController;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Service\BoardArchiveService;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\ImportService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BoardPortabilityControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private IUserSession&MockObject $userSession;
	private BoardService&MockObject $boardService;
	private BoardArchiveService&MockObject $archiveService;
	private ImportService&MockObject $importService;
	private BoardAccess&MockObject $boardAccess;
	/** The board side contextFor resolves for the acting user (#3744). */
	private string $viewerRole = ViewerContext::ROLE_INTERNAL;
	private BoardPortabilityController $controller;
	/** Temp archives created by a test, removed afterwards. */
	private array $archives = [];

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->boardService = $this->createMock(BoardService::class);
		$this->archiveService = $this->createMock(BoardArchiveService::class);
		$this->importService = $this->createMock(ImportService::class);
		// export() resolves the viewer's context after the READ gate and hands
		// it to the viewer-scoped archive build (#3743). Role defaults to internal;
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
			$this->archiveService,
			$this->importService,
			$this->boardAccess,
		);
	}

	protected function tearDown(): void {
		foreach ($this->archives as $path) {
			@unlink($path);
		}
		$this->archives = [];
		parent::tearDown();
	}

	private function loginAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testExportGatesOnBoardReadAndStreamsTheArchive(): void {
		$this->loginAs('alice');
		$board = new Board();
		$board->setId(5);
		$board->setTitle('Roadmap');
		// find() is the READ gate: called with the requesting uid.
		$this->boardService->expects(self::once())->method('find')->with(5, 'alice')->willReturn($board);
		$archive = $this->tempArchive();
		$this->archiveService->expects(self::once())->method('build')
			->with($board, self::isInstanceOf(ViewerContext::class))
			->willReturn($archive);
		$this->archiveService->method('filenameFor')->willReturn('kanso-Roadmap.zip');

		$response = $this->controller->export(5);

		self::assertInstanceOf(StreamResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		// getHeaders() would need the NC service container (it merges in CSP and
		// the request id), so read the response's OWN header bag directly.
		$headers = $this->ownHeaders($response);
		self::assertSame('application/zip', $headers['Content-Type']);
		self::assertSame('attachment; filename="kanso-Roadmap.zip"', $headers['Content-Disposition']);
		self::assertSame('nosniff', $headers['X-Content-Type-Options']);
		// The temp archive is unlinked as soon as the handle is open, so a
		// client that walks away cannot leave the bytes on disk.
		self::assertFileDoesNotExist($archive);
	}

	/** The headers the controller itself set, without the container-backed merge. */
	private function ownHeaders(\OCP\AppFramework\Http\Response $response): array {
		$property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
		/** @var array<string, string> $headers */
		$headers = $property->getValue($response);
		return $headers;
	}

	/** A throwaway zip standing in for a built board archive. */
	private function tempArchive(): string {
		$path = tempnam(sys_get_temp_dir(), 'kanso-portability-test-');
		self::assertIsString($path);
		$this->archives[] = $path;
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
		$zip->addFromString('board.json', '{"kanso":3}');
		$zip->close();
		return $path;
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
		// The denial must fire BEFORE any export content - or any attachment
		// byte - is packed.
		$this->archiveService->expects(self::never())->method('build');

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
