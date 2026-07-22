<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\CardController;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\InvalidInputException;
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
	private CardController $controller;

	protected function setUp(): void {
		parent::setUp();
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$this->cardService = $this->createMock(CardService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession->method('getUser')->willReturn($user);

		$this->controller = new CardController(
			'kanso',
			$request,
			$userSession,
			$this->cardService
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

	public function testShowReturnsFullCardWithDescription(): void {
		$card = $this->card();
		$card->setDescription('Full detail');
		$this->cardService->method('find')->with(9, 'alice')->willReturn($card);

		$response = $this->controller->show(9);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($card, $response->getData());
		self::assertSame('Full detail', $card->jsonSerialize()['description']);
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
}
