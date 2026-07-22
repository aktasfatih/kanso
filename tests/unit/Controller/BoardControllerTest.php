<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\BoardController;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\ChangeMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BoardControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private IUserSession&MockObject $userSession;
	private BoardService&MockObject $boardService;
	private ChangeMapper&MockObject $changeMapper;
	private StackMapper&MockObject $stackMapper;
	private CardMapper&MockObject $cardMapper;
	private BoardController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->boardService = $this->createMock(BoardService::class);
		$this->changeMapper = $this->createMock(ChangeMapper::class);
		$this->stackMapper = $this->createMock(StackMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new BoardController(
			'kanso',
			$this->request,
			$this->userSession,
			$this->boardService,
			$this->changeMapper,
			$this->stackMapper,
			$this->cardMapper
		);
	}

	private function board(int $id = 1): Board {
		$board = new Board();
		$board->setId($id);
		$board->setTitle('Board');
		$board->setOwner('alice');
		$board->setDeletedAt(0);
		return $board;
	}

	public function testShowReturns304WithoutTouchingStacksAndCards(): void {
		$this->boardService->method('find')->with(1, 'alice')->willReturn($this->board());
		$this->changeMapper->method('getLatestChangeId')->with(1)->willReturn(7);
		$this->request->method('getHeader')->with('If-None-Match')->willReturn('"7"');

		$this->stackMapper->expects(self::never())->method('findByBoard');
		$this->cardMapper->expects(self::never())->method('findSummariesByBoard');

		$response = $this->controller->show(1);
		self::assertSame(Http::STATUS_NOT_MODIFIED, $response->getStatus());
		self::assertSame('7', $response->getETag());
	}

	public function testShowReturns304ForWeakIfNoneMatch(): void {
		$this->boardService->method('find')->with(1, 'alice')->willReturn($this->board());
		$this->changeMapper->method('getLatestChangeId')->with(1)->willReturn(7);
		$this->request->method('getHeader')->with('If-None-Match')->willReturn('W/"7"');

		$this->cardMapper->expects(self::never())->method('findSummariesByBoard');

		$response = $this->controller->show(1);
		self::assertSame(Http::STATUS_NOT_MODIFIED, $response->getStatus());
	}

	public function testShowReturnsFullPayloadWithETagOnMiss(): void {
		$board = $this->board();
		$this->boardService->method('find')->with(1, 'alice')->willReturn($board);
		$this->changeMapper->method('getLatestChangeId')->with(1)->willReturn(7);
		$this->request->method('getHeader')->with('If-None-Match')->willReturn('"3"');

		$stack = new Stack();
		$stack->setId(2);
		$stack->setBoardId(1);
		$this->stackMapper->method('findByBoard')->with(1)->willReturn([$stack]);

		$card = new Card();
		$card->setId(3);
		$card->setBoardId(1);
		$card->setStackId(2);
		$card->setTitle('A card');
		$card->setDescription('must not leak into summaries');
		$this->cardMapper->method('findSummariesByBoard')->with(1)->willReturn([$card]);

		$response = $this->controller->show(1);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('7', $response->getETag());

		$data = $response->getData();
		self::assertSame($board, $data['board']);
		self::assertSame([$stack], $data['stacks']);
		self::assertCount(1, $data['cards']);
		self::assertSame(3, $data['cards'][0]['id']);
		self::assertArrayNotHasKey('description', $data['cards'][0]);
	}

	public function testShowMapsNotPermittedTo403(): void {
		$this->boardService->method('find')->willThrowException(new NotPermittedException());

		$response = $this->controller->show(1);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testShowMapsDoesNotExistTo404(): void {
		$this->boardService->method('find')
			->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->show(1);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testIndexReturnsBoards(): void {
		$boards = [$this->board()];
		$this->boardService->method('findAll')->with('alice')->willReturn($boards);

		$response = $this->controller->index();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($boards, $response->getData());
	}

	public function testCreateMapsInvalidInputTo400(): void {
		$this->boardService->method('create')
			->willThrowException(new InvalidInputException('Title must not be empty'));

		$response = $this->controller->create('');
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('Title must not be empty', $response->getData()['error']);
	}

	public function testDestroyMapsNotPermittedTo403(): void {
		$this->boardService->method('delete')->willThrowException(new NotPermittedException());

		$response = $this->controller->destroy(1);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testUpdateReturnsUpdatedBoard(): void {
		$board = $this->board();
		$this->boardService->method('update')
			->with(1, 'New', null, null, 'alice')
			->willReturn($board);

		$response = $this->controller->update(1, 'New');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($board, $response->getData());
	}
}
