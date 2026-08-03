<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAttachment;
use OCA\Kanso\Db\CardAttachmentMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Service\CardAttachmentService;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CardAttachmentServiceTest extends TestCase {
	private CardAttachmentMapper&MockObject $attachmentMapper;
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private PermissionService&MockObject $permissionService;
	private ChangeNotifier&MockObject $changeNotifier;
	private IAppData&MockObject $appData;
	private ISecureRandom&MockObject $secureRandom;
	private ISimpleFolder&MockObject $folder;
	private CardAttachmentService $service;

	/** @var string[] Temp files created for upload tests, cleaned up in tearDown. */
	private array $tmpFiles = [];

	protected function setUp(): void {
		parent::setUp();
		$this->attachmentMapper = $this->createMock(CardAttachmentMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->appData = $this->createMock(IAppData::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->folder = $this->createMock(ISimpleFolder::class);

		// A card's folder resolves (or is created) transparently.
		$this->appData->method('getFolder')->willReturn($this->folder);
		$this->appData->method('newFolder')->willReturn($this->folder);
		$this->secureRandom->method('generate')->willReturn('deadbeefdeadbeefdeadbeefdeadbeef');

		$this->service = new CardAttachmentService(
			$this->attachmentMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->permissionService,
			$this->changeNotifier,
			$this->appData,
			$this->secureRandom,
		);
	}

	protected function tearDown(): void {
		foreach ($this->tmpFiles as $f) {
			if (is_file($f)) {
				unlink($f);
			}
		}
		parent::tearDown();
	}

	private function board(int $id = 1): Board {
		$b = new Board();
		$b->setId($id);
		$b->setDeletedAt(0);
		return $b;
	}

	private function card(int $id = 9, int $boardId = 1): Card {
		$c = new Card();
		$c->setId($id);
		$c->setBoardId($boardId);
		$c->setDeletedAt(0);
		return $c;
	}

	private function expectCardLoaded(): Board {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		return $board;
	}

	/**
	 * @param array<string, mixed> $override
	 * @return array<string, mixed>
	 */
	private function upload(array $override = [], string $content = 'hello world'): array {
		$tmp = tempnam(sys_get_temp_dir(), 'kanso-attach-test');
		file_put_contents($tmp, $content);
		$this->tmpFiles[] = $tmp;
		return array_merge([
			'name' => 'report.pdf',
			'type' => 'application/pdf',
			'size' => strlen($content),
			'tmp_name' => $tmp,
			'error' => UPLOAD_ERR_OK,
		], $override);
	}

	// ---- listForCard ------------------------------------------------------

	public function testListForCardRequiresRead(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'stranger', PermissionService::PERMISSION_READ)
			->willThrowException(new NotPermittedException());

		$this->expectException(NotPermittedException::class);
		$this->service->listForCard(9, 'stranger');
	}

	public function testListForCardReturnsMetadata(): void {
		$this->expectCardLoaded();
		$a = new CardAttachment();
		$a->setId(1);
		$a->setCardId(9);
		$this->attachmentMapper->method('findByCard')->with(9)->willReturn([$a]);

		$result = $this->service->listForCard(9, 'bob');
		self::assertCount(1, $result);
		self::assertSame(1, $result[0]->getId());
	}

	// ---- upload -----------------------------------------------------------

	public function testUploadRequiresEdit(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'stranger', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->attachmentMapper->expects(self::never())->method('insert');

		$this->expectException(NotPermittedException::class);
		$this->service->upload(9, $this->upload(), 'stranger');
	}

	public function testUploadStoresBytesUnderServerKeyAndRecordsRow(): void {
		$this->expectCardLoaded();

		// The object is created under the SERVER-GENERATED key, never the
		// client filename.
		$this->folder->expects(self::once())
			->method('newFile')
			->with('deadbeefdeadbeefdeadbeefdeadbeef', self::anything())
			->willReturn($this->createMock(ISimpleFile::class));

		$captured = null;
		$this->attachmentMapper->method('insert')->willReturnCallback(
			function (CardAttachment $a) use (&$captured): CardAttachment {
				$a->setId(7);
				$captured = $a;
				return $a;
			}
		);
		$this->changeNotifier->expects(self::once())->method('notify');

		$result = $this->service->upload(9, $this->upload(['name' => 'report.pdf']), 'bob');

		self::assertSame(7, $result->getId());
		self::assertSame('report.pdf', $captured->getFilename());
		self::assertSame('deadbeefdeadbeefdeadbeefdeadbeef', $captured->getStorageKey());
		self::assertSame(9, $captured->getCardId());
		self::assertSame(1, $captured->getBoardId());
		self::assertSame('bob', $captured->getUploadedBy());
	}

	public function testUploadIgnoresClientFilenameForStoragePath(): void {
		$this->expectCardLoaded();
		// A path-traversal filename must NOT become the storage key.
		$this->folder->expects(self::once())
			->method('newFile')
			->with('deadbeefdeadbeefdeadbeefdeadbeef', self::anything())
			->willReturn($this->createMock(ISimpleFile::class));

		$captured = null;
		$this->attachmentMapper->method('insert')->willReturnCallback(
			function (CardAttachment $a) use (&$captured): CardAttachment {
				$captured = $a;
				return $a;
			}
		);

		$this->service->upload(9, $this->upload(['name' => '../../../../etc/passwd']), 'bob');

		self::assertSame('deadbeefdeadbeefdeadbeefdeadbeef', $captured->getStorageKey());
		// The label is basename-stripped, never a path.
		self::assertSame('passwd', $captured->getFilename());
	}

	public function testUploadRejectsOversizedByReportedSize(): void {
		$this->expectCardLoaded();
		// The client-reported size exceeds the cap - rejected before any bytes
		// are read or written.
		$this->folder->expects(self::never())->method('newFile');
		$this->attachmentMapper->expects(self::never())->method('insert');

		$oversized = $this->upload(['size' => CardAttachmentService::MAX_SIZE + 1]);

		$this->expectException(InvalidInputException::class);
		$this->service->upload(9, $oversized, 'bob');
	}

	public function testUploadRejectsMissingFile(): void {
		$this->expectCardLoaded();
		$this->folder->expects(self::never())->method('newFile');

		$this->expectException(InvalidInputException::class);
		$this->service->upload(9, null, 'bob');
	}

	public function testUploadRejectsEmptyFile(): void {
		$this->expectCardLoaded();
		$this->folder->expects(self::never())->method('newFile');

		$this->expectException(InvalidInputException::class);
		$this->service->upload(9, $this->upload([], ''), 'bob');
	}

	public function testUploadSanitizesBogusMime(): void {
		$this->expectCardLoaded();
		$this->folder->method('newFile')->willReturn($this->createMock(ISimpleFile::class));
		$captured = null;
		$this->attachmentMapper->method('insert')->willReturnCallback(
			function (CardAttachment $a) use (&$captured): CardAttachment {
				$captured = $a;
				return $a;
			}
		);

		$this->service->upload(9, $this->upload(['type' => 'not a mime type']), 'bob');
		self::assertSame('application/octet-stream', $captured->getMime());
	}

	/**
	 * @dataProvider unsafeMimeProvider
	 */
	public function testUploadCoercesInlineRenderableMimeToBinary(string $clientMime): void {
		$this->expectCardLoaded();
		$this->folder->method('newFile')->willReturn($this->createMock(ISimpleFile::class));
		$captured = null;
		$this->attachmentMapper->method('insert')->willReturnCallback(
			function (CardAttachment $a) use (&$captured): CardAttachment {
				$captured = $a;
				return $a;
			}
		);

		$this->service->upload(9, $this->upload(['type' => $clientMime]), 'bob');
		self::assertSame('application/octet-stream', $captured->getMime());
	}

	/** @return array<string, array{0: string}> */
	public static function unsafeMimeProvider(): array {
		return [
			'html' => ['text/html'],
			'svg' => ['image/svg+xml'],
			'xhtml' => ['application/xhtml+xml'],
			'xml' => ['application/xml'],
			'js' => ['application/javascript'],
		];
	}

	// ---- download ---------------------------------------------------------

	public function testDownloadRequiresRead(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'stranger', PermissionService::PERMISSION_READ)
			->willThrowException(new NotPermittedException());

		$this->expectException(NotPermittedException::class);
		$this->service->download(9, 1, 'stranger');
	}

	public function testDownloadRejectsCrossCardAttachment(): void {
		$this->expectCardLoaded();
		$other = new CardAttachment();
		$other->setId(5);
		$other->setCardId(99); // belongs to a different card - IDOR guard
		$this->attachmentMapper->method('find')->with(5)->willReturn($other);
		$this->folder->expects(self::never())->method('getFile');

		$this->expectException(DoesNotExistException::class);
		$this->service->download(9, 5, 'bob');
	}

	public function testDownloadStreamsBytes(): void {
		$this->expectCardLoaded();
		$a = new CardAttachment();
		$a->setId(5);
		$a->setCardId(9);
		$a->setStorageKey('deadbeefdeadbeefdeadbeefdeadbeef');
		$a->setFilename('report.pdf');
		$a->setMime('application/pdf');
		$this->attachmentMapper->method('find')->with(5)->willReturn($a);

		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn('PDFBYTES');
		$this->folder->method('getFile')
			->with('deadbeefdeadbeefdeadbeefdeadbeef')
			->willReturn($file);

		[$meta, $bytes] = $this->service->download(9, 5, 'bob');
		self::assertSame('report.pdf', $meta->getFilename());
		self::assertSame('PDFBYTES', $bytes);
	}

	public function testDownloadMissingObjectIs404(): void {
		$this->expectCardLoaded();
		$a = new CardAttachment();
		$a->setId(5);
		$a->setCardId(9);
		$a->setStorageKey('deadbeefdeadbeefdeadbeefdeadbeef');
		$this->attachmentMapper->method('find')->with(5)->willReturn($a);
		$this->folder->method('getFile')->willThrowException(new NotFoundException());

		$this->expectException(DoesNotExistException::class);
		$this->service->download(9, 5, 'bob');
	}

	// ---- delete -----------------------------------------------------------

	public function testDeleteRequiresEdit(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'stranger', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->attachmentMapper->expects(self::never())->method('delete');

		$this->expectException(NotPermittedException::class);
		$this->service->delete(9, 1, 'stranger');
	}

	public function testDeleteRejectsCrossCardAttachment(): void {
		$this->expectCardLoaded();
		$other = new CardAttachment();
		$other->setId(5);
		$other->setCardId(99);
		$this->attachmentMapper->method('find')->with(5)->willReturn($other);
		$this->attachmentMapper->expects(self::never())->method('delete');

		$this->expectException(DoesNotExistException::class);
		$this->service->delete(9, 5, 'bob');
	}

	public function testDeleteRemovesRowObjectAndNotifies(): void {
		$this->expectCardLoaded();
		$a = new CardAttachment();
		$a->setId(5);
		$a->setCardId(9);
		$a->setStorageKey('deadbeefdeadbeefdeadbeefdeadbeef');
		$this->attachmentMapper->method('find')->with(5)->willReturn($a);
		$this->attachmentMapper->expects(self::once())->method('delete')->with($a);

		$file = $this->createMock(ISimpleFile::class);
		$file->expects(self::once())->method('delete');
		$this->folder->method('getFile')
			->with('deadbeefdeadbeefdeadbeefdeadbeef')
			->willReturn($file);

		$this->changeNotifier->expects(self::once())->method('notify');

		$this->service->delete(9, 5, 'bob');
	}
}
