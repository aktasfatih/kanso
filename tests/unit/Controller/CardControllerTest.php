<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\CardController;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Service\AssigneeService;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\LabelService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CardControllerTest extends TestCase {
	private CardService&MockObject $cardService;
	private LabelService&MockObject $labelService;
	private AssigneeService&MockObject $assigneeService;
	private CardLabelMapper&MockObject $cardLabelMapper;
	private CardAssigneeMapper&MockObject $cardAssigneeMapper;
	private ChecklistItemMapper&MockObject $checklistItemMapper;
	private CardMapper&MockObject $cardMapper;
	private CommentMapper&MockObject $commentMapper;
	private CardController $controller;

	protected function setUp(): void {
		parent::setUp();
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$this->cardService = $this->createMock(CardService::class);
		$this->labelService = $this->createMock(LabelService::class);
		$this->assigneeService = $this->createMock(AssigneeService::class);
		$this->cardLabelMapper = $this->createMock(CardLabelMapper::class);
		$this->cardAssigneeMapper = $this->createMock(CardAssigneeMapper::class);
		$this->checklistItemMapper = $this->createMock(ChecklistItemMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->commentMapper = $this->createMock(CommentMapper::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession->method('getUser')->willReturn($user);

		$this->controller = new CardController(
			'kanso',
			$request,
			$userSession,
			$this->cardService,
			$this->labelService,
			$this->assigneeService,
			$this->cardLabelMapper,
			$this->cardAssigneeMapper,
			$this->checklistItemMapper,
			$this->cardMapper,
			$this->commentMapper
		);
	}

	private function card(int $id = 9): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId(1);
		$card->setStackId(5);
		$card->setTitle('A card');
		$card->setSortKey('I');
		return $card;
	}

	public function testCreateReturnsCard(): void {
		$card = $this->card();
		$this->cardService->method('create')->with(5, 'A card', 'alice')->willReturn($card);

		$response = $this->controller->create(5, 'A card');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($card, $response->getData());
	}

	public function testCreateMapsInvalidInputTo400(): void {
		$this->cardService->method('create')
			->willThrowException(new InvalidInputException('Title must not be empty'));

		$response = $this->controller->create(5, '');
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('Title must not be empty', $response->getData()['error']);
	}

	public function testCreateMapsNotPermittedTo403(): void {
		$this->cardService->method('create')->willThrowException(new NotPermittedException());

		$response = $this->controller->create(5, 'A card');
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testShowReturnsFullCardWithDescriptionLabelIdsAndAssigneeIds(): void {
		$card = $this->card();
		$card->setDescription('Full detail');
		$this->cardService->method('find')->with(9, 'alice')->willReturn($card);
		$this->cardLabelMapper->method('findLabelIdsByCard')->with(9)->willReturn([3, 7]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->with(9)->willReturn(['bob', 'carol']);

		$response = $this->controller->show(9);
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		self::assertSame(9, $data['id']);
		self::assertSame('Full detail', $data['description']);
		self::assertSame([3, 7], $data['labelIds']);
		self::assertSame(['bob', 'carol'], $data['assigneeIds']);
	}

	public function testShowMapsDoesNotExistTo404(): void {
		$this->cardService->method('find')
			->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->show(9);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testShowMapsNotPermittedTo403(): void {
		$this->cardService->method('find')->willThrowException(new NotPermittedException());

		$response = $this->controller->show(9);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testUpdatePassesFieldsThrough(): void {
		$card = $this->card();
		$this->cardService->method('update')
			->with(9, 'New title', 'New description', '2026-08-01T10:00:00.000Z', true, null, 'alice')
			->willReturn($card);

		$response = $this->controller->update(9, 'New title', 'New description', '2026-08-01T10:00:00.000Z', true);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($card, $response->getData());
	}

	public function testUpdateMapsInvalidDuedateTo400(): void {
		$this->cardService->method('update')
			->willThrowException(new InvalidInputException('Due date must be an ISO 8601 datetime like 2026-07-22T12:00:00Z'));

		$response = $this->controller->update(9, null, null, 'not-a-date');
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testUpdateMapsDoesNotExistTo404(): void {
		$this->cardService->method('update')
			->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->update(9, 'New title');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testDestroyReturnsEmptyBody(): void {
		$this->cardService->expects(self::once())->method('delete')->with(9, 'alice');

		$response = $this->controller->destroy(9);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData());
	}

	public function testDestroyMapsNotPermittedTo403(): void {
		$this->cardService->method('delete')->willThrowException(new NotPermittedException());

		$response = $this->controller->destroy(9);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testDestroyMapsDoesNotExistTo404(): void {
		$this->cardService->method('delete')
			->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->destroy(9);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testMoveReturnsCard(): void {
		$card = $this->card();
		$this->cardService->method('move')->with(9, 6, 10, 'alice')->willReturn($card);

		$response = $this->controller->move(9, 6, 10);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($card, $response->getData());
	}

	public function testMoveDefaultsAfterCardIdToNull(): void {
		$card = $this->card();
		$this->cardService->method('move')->with(9, 6, null, 'alice')->willReturn($card);

		$response = $this->controller->move(9, 6);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testMoveMapsOverflowTo409RebalanceRequired(): void {
		$this->cardService->method('move')
			->willThrowException(new \OverflowException('Sort key would exceed 64 characters, rebalance needed'));

		$response = $this->controller->move(9, 6, 10);
		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame('rebalance_required', $response->getData()['error']);
	}

	public function testMoveMapsSortKeyInvalidArgumentTo400(): void {
		// Defensive: SortKeyService throws InvalidArgumentException on
		// malformed/misordered keys — must not surface as a 500.
		$this->cardService->method('move')
			->willThrowException(new \InvalidArgumentException('between() requires a < b'));

		$response = $this->controller->move(9, 6, 10);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testMoveMapsNotPermittedTo403(): void {
		$this->cardService->method('move')->willThrowException(new NotPermittedException());

		$response = $this->controller->move(9, 6, 10);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testMoveMapsDoesNotExistTo404(): void {
		$this->cardService->method('move')
			->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->move(9, 6, 10);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testAssignLabelReturnsEmptyBody(): void {
		$this->labelService->expects(self::once())->method('assign')->with(9, 7, 'alice');

		$response = $this->controller->assignLabel(9, 7);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData());
	}

	public function testAssignLabelMapsNotPermittedTo403(): void {
		$this->labelService->method('assign')->willThrowException(new NotPermittedException());

		$response = $this->controller->assignLabel(9, 7);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testAssignLabelMapsCrossBoardTo400(): void {
		$this->labelService->method('assign')
			->willThrowException(new InvalidInputException('Cannot assign a label from another board'));

		$response = $this->controller->assignLabel(9, 7);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('Cannot assign a label from another board', $response->getData()['error']);
	}

	public function testAssignLabelMapsDoesNotExistTo404(): void {
		$this->labelService->method('assign')
			->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->assignLabel(9, 7);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testUnassignLabelReturnsEmptyBody(): void {
		$this->labelService->expects(self::once())->method('unassign')->with(9, 7, 'alice');

		$response = $this->controller->unassignLabel(9, 7);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData());
	}

	public function testUnassignLabelMapsNotPermittedTo403(): void {
		$this->labelService->method('unassign')->willThrowException(new NotPermittedException());

		$response = $this->controller->unassignLabel(9, 7);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testAssignUserReturnsEmptyBody(): void {
		$this->assigneeService->expects(self::once())->method('assign')->with(9, 'bob', 'alice');

		$response = $this->controller->assignUser(9, 'bob');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData());
	}

	public function testAssignUserMapsNotPermittedTo403(): void {
		$this->assigneeService->method('assign')->willThrowException(new NotPermittedException());

		$response = $this->controller->assignUser(9, 'bob');
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testAssignUserMapsNonMemberTo400(): void {
		$this->assigneeService->method('assign')
			->willThrowException(new InvalidInputException('User has no access to this board'));

		$response = $this->controller->assignUser(9, 'stranger');
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('User has no access to this board', $response->getData()['error']);
	}

	public function testAssignUserMapsDoesNotExistTo404(): void {
		$this->assigneeService->method('assign')
			->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->assignUser(9, 'bob');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testUnassignUserReturnsEmptyBody(): void {
		$this->assigneeService->expects(self::once())->method('unassign')->with(9, 'bob', 'alice');

		$response = $this->controller->unassignUser(9, 'bob');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData());
	}

	public function testUnassignUserMapsNotPermittedTo403(): void {
		$this->assigneeService->method('unassign')->willThrowException(new NotPermittedException());

		$response = $this->controller->unassignUser(9, 'bob');
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}
}
