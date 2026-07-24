<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\BoardController;
use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\AclMapper;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\ChangeMapper;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\ReviewTypeMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\ParticipantService;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\SubscriptionService;
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
	private ParticipantService&MockObject $participantService;
	private ChangeMapper&MockObject $changeMapper;
	private StackMapper&MockObject $stackMapper;
	private CardMapper&MockObject $cardMapper;
	private LabelMapper&MockObject $labelMapper;
	private CardLabelMapper&MockObject $cardLabelMapper;
	private CardAssigneeMapper&MockObject $cardAssigneeMapper;
	private CardReviewMapper&MockObject $cardReviewMapper;
	private ReviewTypeMapper&MockObject $reviewTypeMapper;
	private ChecklistItemMapper&MockObject $checklistItemMapper;
	private CommentMapper&MockObject $commentMapper;
	private AclMapper&MockObject $aclMapper;
	private PermissionService&MockObject $permissionService;
	private SubscriptionService&MockObject $subscriptionService;
	private BoardController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->boardService = $this->createMock(BoardService::class);
		$this->participantService = $this->createMock(ParticipantService::class);
		$this->changeMapper = $this->createMock(ChangeMapper::class);
		$this->stackMapper = $this->createMock(StackMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->labelMapper = $this->createMock(LabelMapper::class);
		$this->cardLabelMapper = $this->createMock(CardLabelMapper::class);
		$this->cardAssigneeMapper = $this->createMock(CardAssigneeMapper::class);
		$this->cardReviewMapper = $this->createMock(CardReviewMapper::class);
		$this->reviewTypeMapper = $this->createMock(ReviewTypeMapper::class);
		$this->checklistItemMapper = $this->createMock(ChecklistItemMapper::class);
		$this->commentMapper = $this->createMock(CommentMapper::class);
		$this->aclMapper = $this->createMock(AclMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->subscriptionService = $this->createMock(SubscriptionService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new BoardController(
			'kanso',
			$this->request,
			$this->userSession,
			$this->boardService,
			$this->participantService,
			$this->changeMapper,
			$this->stackMapper,
			$this->cardMapper,
			$this->labelMapper,
			$this->cardLabelMapper,
			$this->cardAssigneeMapper,
			$this->cardReviewMapper,
			$this->reviewTypeMapper,
			$this->checklistItemMapper,
			$this->commentMapper,
			$this->aclMapper,
			$this->permissionService,
			$this->subscriptionService
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

	public function testShowReturns304WithoutTouchingStacksCardsAndLabels(): void {
		$this->boardService->method('find')->with(1, 'alice')->willReturn($this->board());
		$this->changeMapper->method('getLatestChangeId')->with(1)->willReturn(7);
		$this->request->method('getHeader')->with('If-None-Match')->willReturn('"7"');

		$this->stackMapper->expects(self::never())->method('findByBoard');
		$this->cardMapper->expects(self::never())->method('findSummariesByBoard');
		$this->labelMapper->expects(self::never())->method('findByBoard');
		$this->cardLabelMapper->expects(self::never())->method('findLabelIdsByBoard');
		$this->cardAssigneeMapper->expects(self::never())->method('findUserIdsByBoard');
		$this->aclMapper->expects(self::never())->method('findByBoard');
		$this->permissionService->expects(self::never())->method('getPermissions');

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
		$unlabeled = new Card();
		$unlabeled->setId(4);
		$unlabeled->setBoardId(1);
		$unlabeled->setStackId(2);
		$unlabeled->setTitle('No labels');
		$this->cardMapper->method('findSummariesByBoard')->with(1)->willReturn([$card, $unlabeled]);

		$label = new Label();
		$label->setId(7);
		$label->setBoardId(1);
		$label->setTitle('Urgent');
		$this->labelMapper->method('findByBoard')->with(1)->willReturn([$label]);
		$this->cardLabelMapper->method('findLabelIdsByBoard')->with(1)->willReturn([3 => [7]]);
		$this->cardAssigneeMapper->method('findUserIdsByBoard')->with(1)->willReturn([3 => ['bob']]);
		$this->checklistItemMapper->method('progressByBoard')->with(1)->willReturn([3 => ['total' => 4, 'done' => 1]]);
		$this->cardMapper->method('childProgressByBoard')->with(1)->willReturn([3 => ['total' => 2, 'done' => 1]]);
		$this->commentMapper->method('countsByBoard')->with(1)->willReturn([3 => 5]);

		$acl = new Acl();
		$acl->setId(40);
		$acl->setBoardId(1);
		$acl->setParticipantType(Acl::TYPE_USER);
		$acl->setParticipant('bob');
		$acl->setPermission(PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT);
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([$acl]);
		$this->permissionService->method('getPermissions')
			->with($board, 'alice')
			->willReturn(PermissionService::PERMISSION_ALL);

		$response = $this->controller->show(1);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('7', $response->getETag());

		$data = $response->getData();
		self::assertSame($board, $data['board']);
		self::assertSame([$stack], $data['stacks']);
		self::assertSame([$label], $data['labels']);
		self::assertSame([$acl], $data['acl']);
		self::assertSame('user', $acl->jsonSerialize()['participantType']);
		self::assertSame(PermissionService::PERMISSION_ALL, $data['permissions']);
		self::assertCount(2, $data['cards']);
		self::assertSame(3, $data['cards'][0]['id']);
		self::assertSame([7], $data['cards'][0]['labelIds']);
		self::assertSame(['bob'], $data['cards'][0]['assigneeIds']);
		self::assertSame([], $data['cards'][1]['labelIds']);
		self::assertSame([], $data['cards'][1]['assigneeIds']);
		self::assertSame(['total' => 4, 'done' => 1], $data['cards'][0]['checklist']);
		self::assertSame(['total' => 0, 'done' => 0], $data['cards'][1]['checklist']);
		self::assertSame(['total' => 2, 'done' => 1], $data['cards'][0]['childProgress']);
		self::assertSame(['total' => 0, 'done' => 0], $data['cards'][1]['childProgress']);
		self::assertSame(5, $data['cards'][0]['commentCount']);
		self::assertSame(0, $data['cards'][1]['commentCount']);
		self::assertNull($data['cards'][0]['parentCardId']);
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

	public function testParticipantsReturnsList(): void {
		$participants = [
			['uid' => 'alice', 'displayName' => 'Alice Adams'],
			['uid' => 'bob', 'displayName' => 'Bob Baker'],
		];
		$this->participantService->method('getParticipants')
			->with(1, 'alice')
			->willReturn($participants);

		$response = $this->controller->participants(1);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($participants, $response->getData());
	}

	public function testParticipantsMapsNotPermittedTo403(): void {
		$this->participantService->method('getParticipants')
			->willThrowException(new NotPermittedException());

		$response = $this->controller->participants(1);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testParticipantsMapsDoesNotExistTo404(): void {
		$this->participantService->method('getParticipants')
			->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->participants(1);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
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
