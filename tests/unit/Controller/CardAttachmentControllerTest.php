<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Db\CardAttachment;
use OCA\Kanso\Service\CardAttachmentService;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Rate limiting, and the wire shape of an attachment row.
 *
 * Both attachment WRITE paths land bytes in the app's own app-data, so both carry
 * the same per-user rate limit - limiting only the multipart upload would leave
 * the "attach from Files" copy as the open door beside the closed one. Asserted on
 * the ATTRIBUTE (that is where the app framework reads it) and only that a limit
 * exists, so the numbers stay tunable without a red test.
 */
class CardAttachmentControllerTest extends TestCase {
	/**
	 * @return array<string, array{string}>
	 */
	public static function writePaths(): array {
		return [
			'multipart upload' => ['create'],
			'copy from Files' => ['createFromFile'],
		];
	}

	/**
	 * @dataProvider writePaths
	 */
	public function testEveryAttachmentWritePathCarriesAPerUserRateLimit(string $method): void {
		$reflected = new \ReflectionMethod(CardAttachmentController::class, $method);
		$attributes = $reflected->getAttributes(UserRateLimit::class);

		self::assertCount(1, $attributes, $method . '() must declare a UserRateLimit');
		$limit = $attributes[0]->newInstance();
		self::assertGreaterThan(0, $limit->getLimit());
		self::assertGreaterThan(0, $limit->getPeriod());
	}

	/**
	 * The limit belongs on the WRITE paths only: reads are cheap and a board with
	 * many attachments would otherwise 429 an ordinary viewer scrolling it.
	 *
	 * @dataProvider readPaths
	 */
	public function testReadPathsAreNotRateLimited(string $method): void {
		$reflected = new \ReflectionMethod(CardAttachmentController::class, $method);

		self::assertCount(0, $reflected->getAttributes(UserRateLimit::class), $method . '() must stay unlimited');
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function readPaths(): array {
		return [
			'list' => ['index'],
			'download' => ['download'],
			'inline render' => ['inline'],
		];
	}

	/**
	 * #119: the card shows WHO attached a file, so the listing carries the
	 * uploader's resolved display name beside the uid - the uid stays, the avatar
	 * endpoint is keyed on it.
	 */
	public function testIndexCarriesTheUploaderDisplayName(): void {
		$service = $this->createMock(CardAttachmentService::class);
		$service->method('listForCard')->with(9, 'bob')->willReturn([
			$this->attachment(1, 'report.pdf', 'alice'),
			$this->attachment(2, 'notes.txt', 'alice'),
			$this->attachment(3, 'photo.png', 'ghost'),
		]);

		$response = $this->controller($service, $this->userManager(['alice' => 'Alice Adams']))->index(9);
		$body = $response->getData();

		self::assertCount(3, $body);
		self::assertSame('alice', $body[0]['uploadedBy']);
		self::assertSame('Alice Adams', $body[0]['uploadedByName']);
		self::assertSame('Alice Adams', $body[1]['uploadedByName']);
		// An account that no longer resolves falls back to its uid rather than
		// rendering an empty byline.
		self::assertSame('ghost', $body[2]['uploadedByName']);
		// The timestamp the whole issue is about must survive serialization.
		self::assertSame(1700000000, $body[0]['createdAt']);
	}

	/**
	 * The upload response feeds the same row the listing renders, so it must carry
	 * the display name too - otherwise a just-uploaded file shows a raw uid until
	 * the list refetches.
	 */
	public function testCreateCarriesTheUploaderDisplayName(): void {
		$service = $this->createMock(CardAttachmentService::class);
		$service->method('upload')->willReturn($this->attachment(1, 'report.pdf', 'alice'));

		$body = $this->controller($service, $this->userManager(['alice' => 'Alice Adams']))->create(9)->getData();

		self::assertSame('Alice Adams', $body['uploadedByName']);
	}

	private function attachment(int $id, string $filename, string $uploadedBy): CardAttachment {
		$a = new CardAttachment();
		$a->setId($id);
		$a->setCardId(9);
		$a->setFilename($filename);
		$a->setMime('application/octet-stream');
		$a->setSize(11);
		$a->setUploadedBy($uploadedBy);
		$a->setCreatedAt(1700000000);
		return $a;
	}

	/**
	 * @param array<string, string> $names uid => display name; a uid absent here
	 *                                     resolves to no user at all
	 */
	private function userManager(array $names): IUserManager {
		$manager = $this->createMock(IUserManager::class);
		$manager->method('get')->willReturnCallback(function (string $uid) use ($names): ?IUser {
			if (!isset($names[$uid])) {
				return null;
			}
			$user = $this->createMock(IUser::class);
			$user->method('getDisplayName')->willReturn($names[$uid]);
			return $user;
		});
		return $manager;
	}

	private function controller(CardAttachmentService $service, IUserManager $userManager): CardAttachmentController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new CardAttachmentController(
			'kanso',
			$this->createMock(IRequest::class),
			$session,
			$service,
			$userManager,
		);
	}
}
