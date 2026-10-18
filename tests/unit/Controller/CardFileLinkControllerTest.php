<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Db\CardFileLink;
use OCA\Kanso\Service\CardFileLinkService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class CardFileLinkControllerTest extends TestCase {
	public function testIndexReturnsTheRowsWithTheirWireShape(): void {
		$service = $this->createMock(CardFileLinkService::class);
		$service->expects(self::once())->method('listForCard')->with(9, 'bob')
			->willReturn([$this->link(1, 42, 'report.pdf')]);

		$response = $this->controller($service)->index(9);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([
			'id' => 1,
			'cardId' => 9,
			'fileId' => 42,
			'name' => 'report.pdf',
			'mime' => 'application/pdf',
			'linkedBy' => 'alice',
			'createdAt' => 1700000000,
		], $response->getData()[0]->jsonSerialize());
	}

	public function testCreatePassesTheFileIdThrough(): void {
		$service = $this->createMock(CardFileLinkService::class);
		$service->expects(self::once())->method('addLink')->with(9, 42, 'bob')
			->willReturn($this->link(1, 42, 'report.pdf'));

		$response = $this->controller($service)->create(9, 42);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testDestroyAnswersAnEmptyBody(): void {
		$service = $this->createMock(CardFileLinkService::class);
		$service->expects(self::once())->method('deleteLink')->with(9, 5, 'bob');

		$response = $this->controller($service)->destroy(9, 5);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData());
	}

	public function testADeniedWriteAnswersForbidden(): void {
		$service = $this->createMock(CardFileLinkService::class);
		$service->method('addLink')->willThrowException(new NotPermittedException('Not allowed'));
		$service->method('deleteLink')->willThrowException(new NotPermittedException('Not allowed'));

		$controller = $this->controller($service);

		self::assertSame(Http::STATUS_FORBIDDEN, $controller->create(9, 42)->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $controller->destroy(9, 5)->getStatus());
	}

	public function testADeniedReadAnswersForbidden(): void {
		$service = $this->createMock(CardFileLinkService::class);
		$service->method('listForCard')->willThrowException(new NotPermittedException('Not allowed'));

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller($service)->index(9)->getStatus());
	}

	public function testAnUnreadableFileAnswersBadRequest(): void {
		$service = $this->createMock(CardFileLinkService::class);
		$service->method('addLink')->willThrowException(new InvalidInputException('File not found'));

		$response = $this->controller($service)->create(9, 42);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('File not found', $response->getData()['error']);
	}

	public function testALinkOnAnotherCardAnswersNotFound(): void {
		$service = $this->createMock(CardFileLinkService::class);
		$service->method('deleteLink')->willThrowException(new DoesNotExistException('nope'));

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller($service)->destroy(9, 5)->getStatus());
	}

	public function testOnlyTheCreatePathIsRateLimited(): void {
		$create = new \ReflectionMethod(CardFileLinkController::class, 'create');
		$attributes = $create->getAttributes(UserRateLimit::class);
		self::assertCount(1, $attributes);
		self::assertGreaterThan(0, $attributes[0]->newInstance()->getLimit());

		$index = new \ReflectionMethod(CardFileLinkController::class, 'index');
		self::assertCount(0, $index->getAttributes(UserRateLimit::class));
	}

	private function link(int $id, int $fileId, string $name): CardFileLink {
		$l = new CardFileLink();
		$l->setId($id);
		$l->setCardId(9);
		$l->setFileId($fileId);
		$l->setName($name);
		$l->setMime('application/pdf');
		$l->setLinkedBy('alice');
		$l->setCreatedAt(1700000000);
		return $l;
	}

	private function controller(CardFileLinkService $service): CardFileLinkController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new CardFileLinkController(
			'kanso',
			$this->createMock(IRequest::class),
			$session,
			$service,
		);
	}
}
