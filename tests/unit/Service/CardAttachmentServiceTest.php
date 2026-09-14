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
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeDetailMapper;
use OCA\Kanso\Service\CardAttachmentService;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\StorageLimitException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CardAttachmentServiceTest extends TestCase {
	private CardAttachmentMapper&MockObject $attachmentMapper;
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private PermissionService&MockObject $permissionService;
	private ChangeNotifier&MockObject $changeNotifier;
	private IAppData&MockObject $appData;
	private ISecureRandom&MockObject $secureRandom;
	private IRootFolder&MockObject $rootFolder;
	private ISimpleFolder&MockObject $folder;
	private CardVisibilityGuard&MockObject $visibilityGuard;
	private ChangeDetailMapper&MockObject $changeDetailMapper;
	private IConfig&MockObject $config;
	private CardAttachmentService $service;

	/**
	 * The raw `attachment_storage_limit` app value the mocked config hands back.
	 * '' is what an UNSET key returns - i.e. the shipped default, no cap - and
	 * every test inherits it unless it opts a limit in.
	 */
	private string $storageLimitValue = '';

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
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->folder = $this->createMock(ISimpleFolder::class);

		// A card's folder resolves (or is created) transparently.
		$this->appData->method('getFolder')->willReturn($this->folder);
		$this->appData->method('newFolder')->willReturn($this->folder);
		$this->secureRandom->method('generate')->willReturn('deadbeefdeadbeefdeadbeefdeadbeef');

		$this->visibilityGuard = $this->createMock(CardVisibilityGuard::class);
		$this->visibilityGuard->method('isVisible')->willReturn(true);
		$this->changeDetailMapper = $this->createMock(ChangeDetailMapper::class);
		// Every attachment add/remove records a change row whose id the filename
		// detail hangs off (#119); hand back a real Change so that id is a real one.
		$change = new Change();
		$change->setId(77);
		$this->changeNotifier->method('notify')->willReturn($change);

		// Mirrors a real IConfig: an unset app value yields the caller's default,
		// so the suite runs against the SHIPPED state (no storage cap) unless a
		// test sets $storageLimitValue.
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				if ($app === 'kanso' && $key === CardAttachmentService::KEY_ATTACHMENT_STORAGE_LIMIT) {
					return $this->storageLimitValue;
				}
				return $default;
			}
		);

		$this->service = new CardAttachmentService(
			$this->attachmentMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->permissionService,
			$this->changeNotifier,
			$this->appData,
			$this->secureRandom,
			$this->rootFolder,
			$this->visibilityGuard,
			$this->changeDetailMapper,
			$this->config,
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * A File node in the actor's userfolder, resolved by id. `$readable` false
	 * makes fopen() fail (an unreadable node).
	 */
	private function fileNode(int $id = 42, int $size = 11, string $name = 'notes.txt', string $mime = 'text/plain'): File&MockObject {
		$node = $this->createMock(File::class);
		$node->method('getSize')->willReturn($size);
		$node->method('getName')->willReturn($name);
		$node->method('getMimetype')->willReturn($mime);
		$node->method('fopen')->willReturnCallback(static function () use ($size, $name) {
			$stream = fopen('php://temp', 'rb+');
			fwrite($stream, str_pad($name, $size));
			rewind($stream);
			return $stream;
		});
		return $node;
	}

	/**
	 * Wires the actor's userfolder to return $nodes for getById($fileId).
	 *
	 * @param array<int, \OCP\Files\Node> $nodes
	 */
	private function expectUserFolderById(int $fileId, array $nodes, string $uid = 'bob'): void {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with($fileId)->willReturn($nodes);
		$this->rootFolder->method('getUserFolder')->with($uid)->willReturn($userFolder);
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

	/**
	 * #119: the change row an upload writes carries VERB_ATTACHMENT_ADDED and the
	 * filename as the detail's `to` side, so the Activity feed can name the file
	 * instead of rendering a bare "updated this card".
	 */
	public function testUploadRecordsAnAttachmentAddedChangeNamingTheFile(): void {
		$this->expectCardLoaded();
		$this->folder->method('newFile')->willReturn($this->createMock(ISimpleFile::class));
		$this->attachmentMapper->method('insert')->willReturnCallback(
			static function (CardAttachment $a): CardAttachment {
				$a->setId(7);
				return $a;
			}
		);

		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_CARD,
				9,
				Change::ACTION_UPDATE,
				'bob',
				// Pinned: an upload is not inside a caller-managed transaction, so
				// the realtime push must fire right away.
				true,
				Change::VERB_ATTACHMENT_ADDED,
			);
		$this->changeDetailMapper->expects(self::once())
			->method('insertDetail')
			->with(77, null, 'report.pdf');

		$this->service->upload(9, $this->upload(['name' => 'report.pdf']), 'bob');
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

	public function testDownloadFromHiddenCardReadsAsNotFound(): void {
		// A card the actor cannot see must not leak its attachments (#3743):
		// the failure is a 404, indistinguishable from a missing card id.
		$this->expectCardLoaded();
		$this->visibilityGuard->method('assertVisible')
			->willThrowException(new DoesNotExistException('hidden'));
		// Bail before any attachment row or bytes are touched.
		$this->attachmentMapper->expects(self::never())->method('find');
		$this->folder->expects(self::never())->method('getFile');

		$this->expectException(DoesNotExistException::class);
		$this->service->download(9, 5, 'bob');
	}

	// ---- inline (#3525) ---------------------------------------------------

	public function testInlineRequiresRead(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'stranger', PermissionService::PERMISSION_READ)
			->willThrowException(new NotPermittedException());

		$this->expectException(NotPermittedException::class);
		$this->service->inline(9, 1, 'stranger');
	}

	public function testInlineRejectsCrossCardAttachment(): void {
		$this->expectCardLoaded();
		$other = new CardAttachment();
		$other->setId(5);
		$other->setCardId(99); // different card - IDOR guard
		$other->setMime('image/png');
		$this->attachmentMapper->method('find')->with(5)->willReturn($other);
		$this->folder->expects(self::never())->method('getFile');

		$this->expectException(DoesNotExistException::class);
		$this->service->inline(9, 5, 'bob');
	}

	/**
	 * @dataProvider inlineImageMimeProvider
	 */
	public function testInlineServesAllowListedRasterImage(string $mime): void {
		$this->expectCardLoaded();
		$a = new CardAttachment();
		$a->setId(5);
		$a->setCardId(9);
		$a->setStorageKey('deadbeefdeadbeefdeadbeefdeadbeef');
		$a->setFilename('shot.png');
		$a->setMime($mime);
		$this->attachmentMapper->method('find')->with(5)->willReturn($a);

		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn('IMGBYTES');
		$this->folder->method('getFile')
			->with('deadbeefdeadbeefdeadbeefdeadbeef')
			->willReturn($file);

		[$meta, $bytes] = $this->service->inline(9, 5, 'bob');
		self::assertSame($mime, $meta->getMime());
		self::assertSame('IMGBYTES', $bytes);
	}

	/** @return array<string, array{0: string}> */
	public static function inlineImageMimeProvider(): array {
		return [
			'png' => ['image/png'],
			'jpeg' => ['image/jpeg'],
			'gif' => ['image/gif'],
			'webp' => ['image/webp'],
		];
	}

	/**
	 * A non-raster / scriptable / arbitrary attachment is NOT inlined: a 404,
	 * bytes are never read. Covers svg (scriptable), html, txt, pdf and a mime
	 * that only *contains* an allow-listed token.
	 *
	 * @dataProvider nonInlineMimeProvider
	 */
	public function testInlineRejectsNonAllowListedMime(string $mime): void {
		$this->expectCardLoaded();
		$a = new CardAttachment();
		$a->setId(5);
		$a->setCardId(9);
		$a->setStorageKey('deadbeefdeadbeefdeadbeefdeadbeef');
		$a->setMime($mime);
		$this->attachmentMapper->method('find')->with(5)->willReturn($a);
		// The gate is checked BEFORE any bytes are touched.
		$this->folder->expects(self::never())->method('getFile');

		$this->expectException(DoesNotExistException::class);
		$this->service->inline(9, 5, 'bob');
	}

	/** @return array<string, array{0: string}> */
	public static function nonInlineMimeProvider(): array {
		return [
			'svg' => ['image/svg+xml'],
			'html' => ['text/html'],
			'txt' => ['text/plain'],
			'pdf' => ['application/pdf'],
			'octet-stream' => ['application/octet-stream'],
			'empty' => [''],
			'png-with-suffix' => ['image/png; charset=utf-8'],
			'not-a-mime' => ['image/png-evil'],
		];
	}

	public function testInlineMissingObjectIs404(): void {
		$this->expectCardLoaded();
		$a = new CardAttachment();
		$a->setId(5);
		$a->setCardId(9);
		$a->setStorageKey('deadbeefdeadbeefdeadbeefdeadbeef');
		$a->setMime('image/png');
		$this->attachmentMapper->method('find')->with(5)->willReturn($a);
		$this->folder->method('getFile')->willThrowException(new NotFoundException());

		$this->expectException(DoesNotExistException::class);
		$this->service->inline(9, 5, 'bob');
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

	/**
	 * #119: a removal is the case with NO other trace - the row and the bytes are
	 * both gone - so the change row must carry VERB_ATTACHMENT_REMOVED and the
	 * filename on the detail's `from` side, read BEFORE the row is dropped.
	 */
	public function testDeleteRecordsAnAttachmentRemovedChangeNamingTheFile(): void {
		$this->expectCardLoaded();
		$a = new CardAttachment();
		$a->setId(5);
		$a->setCardId(9);
		$a->setFilename('invoice-2026.pdf');
		$a->setStorageKey('deadbeefdeadbeefdeadbeefdeadbeef');
		$this->attachmentMapper->method('find')->with(5)->willReturn($a);
		$this->folder->method('getFile')->willReturn($this->createMock(ISimpleFile::class));

		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_CARD,
				9,
				Change::ACTION_UPDATE,
				'bob',
				true,
				Change::VERB_ATTACHMENT_REMOVED,
			);
		$this->changeDetailMapper->expects(self::once())
			->method('insertDetail')
			->with(77, 'invoice-2026.pdf', null);

		$this->service->delete(9, 5, 'bob');
	}

	// ---- deleteAllForCard (cascade on card purge) -------------------------

	private function attachment(int $id, string $key): CardAttachment {
		$a = new CardAttachment();
		$a->setId($id);
		$a->setCardId(9);
		$a->setStorageKey($key);
		return $a;
	}

	public function testDeleteAllForCardRemovesEveryObjectAndAllRows(): void {
		$this->attachmentMapper->method('findByCard')->with(9)->willReturn([
			$this->attachment(1, 'aaaa'),
			$this->attachment(2, 'bbbb'),
		]);

		// Both stored objects are removed...
		$fileA = $this->createMock(ISimpleFile::class);
		$fileA->expects(self::once())->method('delete');
		$fileB = $this->createMock(ISimpleFile::class);
		$fileB->expects(self::once())->method('delete');
		$this->folder->method('getFile')->willReturnMap([
			['aaaa', $fileA],
			['bbbb', $fileB],
		]);
		// ...the per-card folder is torn down...
		$this->folder->expects(self::once())->method('delete');
		// ...and every row is dropped in one shot.
		$this->attachmentMapper->expects(self::once())->method('deleteByCard')->with(9);
		// No permission check and no realtime notification on an internal cascade -
		// and no per-file "removed" activity either (#119): the card itself is being
		// purged and emits its own DELETE row.
		$this->permissionService->expects(self::never())->method('assertPermission');
		$this->changeNotifier->expects(self::never())->method('notify');
		$this->changeDetailMapper->expects(self::never())->method('insertDetail');

		$this->service->deleteAllForCard(9);
	}

	public function testDeleteAllForCardIsSafeWithNoAttachments(): void {
		$this->attachmentMapper->method('findByCard')->with(9)->willReturn([]);
		// No object deletes to attempt, but the folder + rows are still cleaned
		// up defensively, and the call must not blow up.
		$this->folder->expects(self::once())->method('delete');
		$this->attachmentMapper->expects(self::once())->method('deleteByCard')->with(9);

		$this->service->deleteAllForCard(9);
	}

	public function testDeleteAllForCardContinuesWhenOneObjectDeleteFails(): void {
		$this->attachmentMapper->method('findByCard')->with(9)->willReturn([
			$this->attachment(1, 'aaaa'),
			$this->attachment(2, 'bbbb'),
		]);

		// The first object delete blows up; the second must still be attempted,
		// and the rows must still be dropped.
		$fileA = $this->createMock(ISimpleFile::class);
		$fileA->method('delete')->willThrowException(new \RuntimeException('storage hiccup'));
		$fileB = $this->createMock(ISimpleFile::class);
		$fileB->expects(self::once())->method('delete');
		$this->folder->method('getFile')->willReturnMap([
			['aaaa', $fileA],
			['bbbb', $fileB],
		]);
		$this->attachmentMapper->expects(self::once())->method('deleteByCard')->with(9);

		$this->service->deleteAllForCard(9);
	}

	public function testDeleteAllForCardStillDropsRowsWhenFolderMissing(): void {
		$this->attachmentMapper->method('findByCard')->with(9)->willReturn([]);
		// A card that never had a folder: getFolder throws NotFound - the rows
		// must still be dropped and the call must not surface the error.
		$this->appData = $this->createMock(IAppData::class);
		$this->appData->method('getFolder')->willThrowException(new NotFoundException());
		$this->appData->method('newFolder')->willReturn($this->folder);
		$this->service = new CardAttachmentService(
			$this->attachmentMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->permissionService,
			$this->changeNotifier,
			$this->appData,
			$this->secureRandom,
			$this->rootFolder,
			$this->visibilityGuard,
			$this->changeDetailMapper,
			$this->config,
			$this->createMock(LoggerInterface::class),
		);
		$this->attachmentMapper->expects(self::once())->method('deleteByCard')->with(9);

		$this->service->deleteAllForCard(9);
	}

	// ---- attachFromFileNode ("Share from Files", #3645) -------------------

	public function testAttachFromFileRequiresEdit(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'stranger', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		// Bail before ever touching the actor's files or storage.
		$this->rootFolder->expects(self::never())->method('getUserFolder');
		$this->folder->expects(self::never())->method('newFile');
		$this->attachmentMapper->expects(self::never())->method('insert');

		$this->expectException(NotPermittedException::class);
		$this->service->attachFromFileNode(9, 42, 'stranger');
	}

	public function testAttachFromFileCopiesBytesUnderServerKeyAndRecordsRow(): void {
		$this->expectCardLoaded();
		$this->expectUserFolderById(42, [$this->fileNode(42, 11, 'notes.txt', 'text/plain')]);

		// Copied under the SERVER-GENERATED key, never the source filename.
		$this->folder->expects(self::once())
			->method('newFile')
			->with('deadbeefdeadbeefdeadbeefdeadbeef', self::anything())
			->willReturn($this->createMock(ISimpleFile::class));

		$captured = null;
		$this->attachmentMapper->method('insert')->willReturnCallback(
			function (CardAttachment $a) use (&$captured): CardAttachment {
				$a->setId(12);
				$captured = $a;
				return $a;
			}
		);
		// The mutation appends a change row (realtime/delta-sync stays correct).
		$this->changeNotifier->expects(self::once())->method('notify');

		$result = $this->service->attachFromFileNode(9, 42, 'bob');

		self::assertSame(12, $result->getId());
		self::assertSame('notes.txt', $captured->getFilename());
		self::assertSame('text/plain', $captured->getMime());
		self::assertSame(11, $captured->getSize());
		self::assertSame('deadbeefdeadbeefdeadbeefdeadbeef', $captured->getStorageKey());
		self::assertSame(9, $captured->getCardId());
		self::assertSame(1, $captured->getBoardId());
		self::assertSame('bob', $captured->getUploadedBy());
	}

	/**
	 * A file copied from Files is an ordinary attachment, so it must log the same
	 * "attached {file}" activity as a multipart upload (#119) - otherwise the two
	 * doors into the same store leave different traces.
	 */
	public function testAttachFromFileRecordsAnAttachmentAddedChangeNamingTheFile(): void {
		$this->expectCardLoaded();
		$this->expectUserFolderById(42, [$this->fileNode(42, 11, 'notes.txt', 'text/plain')]);
		$this->folder->method('newFile')->willReturn($this->createMock(ISimpleFile::class));
		$this->attachmentMapper->method('insert')->willReturnCallback(
			static function (CardAttachment $a): CardAttachment {
				$a->setId(12);
				return $a;
			}
		);

		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'bob', true, Change::VERB_ATTACHMENT_ADDED);
		$this->changeDetailMapper->expects(self::once())
			->method('insertDetail')
			->with(77, null, 'notes.txt');

		$this->service->attachFromFileNode(9, 42, 'bob');
	}

	public function testAttachFromFileRejectsUnreadableFileId(): void {
		$this->expectCardLoaded();
		// getById returns nothing - the actor cannot reach this node.
		$this->expectUserFolderById(42, []);
		$this->folder->expects(self::never())->method('newFile');
		$this->attachmentMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->attachFromFileNode(9, 42, 'bob');
	}

	public function testAttachFromFileRejectsFolderNode(): void {
		$this->expectCardLoaded();
		// A directory id is not a File - rejected, never streamed.
		$this->expectUserFolderById(42, [$this->createMock(Folder::class)]);
		$this->folder->expects(self::never())->method('newFile');

		$this->expectException(InvalidInputException::class);
		$this->service->attachFromFileNode(9, 42, 'bob');
	}

	public function testAttachFromFileRejectsOversizedNodeBeforeStreaming(): void {
		$this->expectCardLoaded();
		$big = $this->createMock(File::class);
		$big->method('getSize')->willReturn(CardAttachmentService::MAX_SIZE + 1);
		// The size cap is checked BEFORE any bytes are opened or written.
		$big->expects(self::never())->method('fopen');
		$this->expectUserFolderById(42, [$big]);
		$this->folder->expects(self::never())->method('newFile');
		$this->attachmentMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->attachFromFileNode(9, 42, 'bob');
	}

	public function testAttachFromFileRejectsEmptyNode(): void {
		$this->expectCardLoaded();
		$empty = $this->createMock(File::class);
		$empty->method('getSize')->willReturn(0);
		$empty->expects(self::never())->method('fopen');
		$this->expectUserFolderById(42, [$empty]);
		$this->folder->expects(self::never())->method('newFile');

		$this->expectException(InvalidInputException::class);
		$this->service->attachFromFileNode(9, 42, 'bob');
	}

	public function testAttachFromFileSanitizesSourceNameAndMime(): void {
		$this->expectCardLoaded();
		// A path-y name is basename-stripped; a scriptable mime is coerced binary.
		$this->expectUserFolderById(42, [$this->fileNode(42, 8, '../../evil.svg', 'image/svg+xml')]);
		$this->folder->method('newFile')->willReturn($this->createMock(ISimpleFile::class));

		$captured = null;
		$this->attachmentMapper->method('insert')->willReturnCallback(
			function (CardAttachment $a) use (&$captured): CardAttachment {
				$captured = $a;
				return $a;
			}
		);

		$this->service->attachFromFileNode(9, 42, 'bob');
		self::assertSame('evil.svg', $captured->getFilename());
		self::assertSame('application/octet-stream', $captured->getMime());
	}

	public function testAttachFromFileRejectsDeletedCard(): void {
		$deleted = $this->card();
		$deleted->setDeletedAt(time());
		$this->cardMapper->method('find')->with(9)->willReturn($deleted);
		$this->rootFolder->expects(self::never())->method('getUserFolder');

		$this->expectException(DoesNotExistException::class);
		$this->service->attachFromFileNode(9, 42, 'bob');
	}

	// ---- instance-wide attachment storage cap -----------------------------

	/**
	 * THE property the whole feature rests on: with no `attachment_storage_limit`
	 * app value set - the shipped default - an upload behaves exactly as it did
	 * before the cap existed, and the aggregate `SUM(size)` is never even run. An
	 * install that has not opted in pays nothing and sees nothing change.
	 */
	public function testUploadWithNoConfiguredLimitStoresTheFileAndRunsNoAggregateQuery(): void {
		$this->expectCardLoaded();
		$this->attachmentMapper->expects(self::never())->method('totalSize');
		$this->folder->expects(self::once())
			->method('newFile')
			->willReturn($this->createMock(ISimpleFile::class));
		$this->attachmentMapper->expects(self::once())->method('insert')->willReturnCallback(
			static function (CardAttachment $a): CardAttachment {
				$a->setId(7);
				return $a;
			}
		);

		self::assertSame(7, $this->service->upload(9, $this->upload(), 'bob')->getId());
	}

	/**
	 * The same for the "attach from Files" copy - no cap configured, no query, no
	 * behaviour change.
	 */
	public function testAttachFromFileWithNoConfiguredLimitStoresTheFileAndRunsNoAggregateQuery(): void {
		$this->expectCardLoaded();
		$this->expectUserFolderById(42, [$this->fileNode(42, 11, 'notes.txt', 'text/plain')]);
		$this->attachmentMapper->expects(self::never())->method('totalSize');
		$this->folder->expects(self::once())
			->method('newFile')
			->willReturn($this->createMock(ISimpleFile::class));
		$this->attachmentMapper->expects(self::once())->method('insert')->willReturnCallback(
			static function (CardAttachment $a): CardAttachment {
				$a->setId(12);
				return $a;
			}
		);

		self::assertSame(12, $this->service->attachFromFileNode(9, 42, 'bob')->getId());
	}

	/**
	 * Anything that is not a positive number means "no cap" - a cleared value, an
	 * explicit 0, a negative, or a typo. None of them may start rejecting uploads.
	 *
	 * @dataProvider noCapValues
	 */
	public function testStorageLimitTreatsNonPositiveValuesAsNoCap(string $configured): void {
		$this->storageLimitValue = $configured;
		self::assertSame(0, $this->service->storageLimit());
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function noCapValues(): array {
		return [
			'unset' => [''],
			'blank' => ['   '],
			'zero' => ['0'],
			'negative' => ['-1'],
			'not a number' => ['plenty'],
		];
	}

	public function testStorageLimitReadsAConfiguredByteCount(): void {
		$this->storageLimitValue = '10737418240';
		self::assertSame(10737418240, $this->service->storageLimit());
	}

	public function testUploadUnderTheStorageLimitStillSucceeds(): void {
		$this->expectCardLoaded();
		$this->storageLimitValue = '1000';
		// 100 bytes stored + an 11-byte upload is comfortably under the cap.
		$this->attachmentMapper->expects(self::once())->method('totalSize')->willReturn(100);
		$this->folder->expects(self::once())
			->method('newFile')
			->willReturn($this->createMock(ISimpleFile::class));
		$this->attachmentMapper->expects(self::once())->method('insert')->willReturnCallback(
			static function (CardAttachment $a): CardAttachment {
				$a->setId(7);
				return $a;
			}
		);

		self::assertSame(7, $this->service->upload(9, $this->upload(), 'bob')->getId());
	}

	/**
	 * The boundary is inclusive: a file that fits EXACTLY is stored. Pinned so
	 * the comparison cannot quietly drift to `>=` and start refusing the upload
	 * that lands the instance precisely on its limit.
	 */
	public function testUploadThatFitsTheStorageLimitExactlyIsStored(): void {
		$this->expectCardLoaded();
		$this->storageLimitValue = '100';
		// 89 stored + 11 incoming = exactly 100.
		$this->attachmentMapper->method('totalSize')->willReturn(89);
		$this->folder->expects(self::once())
			->method('newFile')
			->willReturn($this->createMock(ISimpleFile::class));
		$this->attachmentMapper->expects(self::once())->method('insert')->willReturnCallback(
			static function (CardAttachment $a): CardAttachment {
				$a->setId(7);
				return $a;
			}
		);

		self::assertSame(7, $this->service->upload(9, $this->upload(), 'bob')->getId());
	}

	public function testUploadOverTheStorageLimitIsRejectedBeforeAnyBytesAreWritten(): void {
		$this->expectCardLoaded();
		$this->storageLimitValue = '100';
		// 95 stored + 11 incoming = 106 > 100.
		$this->attachmentMapper->method('totalSize')->willReturn(95);
		$this->folder->expects(self::never())->method('newFile');
		$this->attachmentMapper->expects(self::never())->method('insert');

		$this->expectException(StorageLimitException::class);
		$this->service->upload(9, $this->upload(), 'bob');
	}

	public function testAttachFromFileUnderTheStorageLimitStillSucceeds(): void {
		$this->expectCardLoaded();
		$this->expectUserFolderById(42, [$this->fileNode(42, 11, 'notes.txt', 'text/plain')]);
		$this->storageLimitValue = '1000';
		$this->attachmentMapper->expects(self::once())->method('totalSize')->willReturn(100);
		$this->folder->expects(self::once())
			->method('newFile')
			->willReturn($this->createMock(ISimpleFile::class));
		$this->attachmentMapper->expects(self::once())->method('insert')->willReturnCallback(
			static function (CardAttachment $a): CardAttachment {
				$a->setId(12);
				return $a;
			}
		);

		self::assertSame(12, $this->service->attachFromFileNode(9, 42, 'bob')->getId());
	}

	/**
	 * The door that is easiest to forget: "attach from Files" copies the SAME
	 * bytes into the SAME app-data as an upload, so the cap has to hold here too,
	 * and it has to hold before the source node is ever opened.
	 */
	public function testAttachFromFileOverTheStorageLimitIsRejectedBeforeStreaming(): void {
		$this->expectCardLoaded();
		$node = $this->createMock(File::class);
		$node->method('getSize')->willReturn(11);
		$node->expects(self::never())->method('fopen');
		$this->expectUserFolderById(42, [$node]);

		$this->storageLimitValue = '100';
		$this->attachmentMapper->method('totalSize')->willReturn(95);
		$this->folder->expects(self::never())->method('newFile');
		$this->attachmentMapper->expects(self::never())->method('insert');

		$this->expectException(StorageLimitException::class);
		$this->service->attachFromFileNode(9, 42, 'bob');
	}

	/**
	 * An instance already over the cap must not be bricked: listing, downloading
	 * and deleting all keep working, so the way back under the line stays open.
	 * They are read/remove paths, so they never run the aggregate query either.
	 */
	public function testAnOverCapInstanceCanStillListDownloadAndDelete(): void {
		$this->expectCardLoaded();
		$this->storageLimitValue = '100';
		$this->attachmentMapper->expects(self::never())->method('totalSize');

		$stored = $this->attachment(3, 'cccc');
		$this->attachmentMapper->method('findByCard')->with(9)->willReturn([$stored]);
		$this->attachmentMapper->method('find')->with(3)->willReturn($stored);

		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn('hello world');
		$this->folder->method('getFile')->with('cccc')->willReturn($file);

		self::assertCount(1, $this->service->listForCard(9, 'bob'));

		[$meta, $bytes] = $this->service->download(9, 3, 'bob');
		self::assertSame(3, $meta->getId());
		self::assertSame('hello world', $bytes);

		$this->attachmentMapper->expects(self::once())->method('delete')->with($stored);
		$this->service->delete(9, 3, 'bob');
	}
}
