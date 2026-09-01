<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\CardAttachment;
use OCA\Kanso\Db\CardAttachmentMapper;
use OCA\Kanso\Service\BoardArchiveService;
use OCA\Kanso\Service\CardAttachmentService;
use OCA\Kanso\Service\ExportService;
use OCP\ITempManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The archive writer (#10060): every export and every scheduled backup used to
 * drop 100% of the attachments. These pin that the bytes now ride along, and -
 * the invariant that matters - that the server-side `storage_key` never does.
 */
class BoardArchiveServiceTest extends TestCase {
	private ExportService&MockObject $exportService;
	private CardAttachmentMapper&MockObject $attachmentMapper;
	private CardAttachmentService&MockObject $attachmentService;
	private ITempManager&MockObject $tempManager;
	private LoggerInterface&MockObject $logger;
	private BoardArchiveService $service;

	/** Every temp file the fake ITempManager handed out, cleaned up after each test. */
	private array $tempFiles = [];
	/** Attachment rows the mapper returns, per card id. */
	private array $rowsByCard = [];
	/** Stored bytes, keyed by "<cardId>:<storageKey>" - the app-data stand-in. */
	private array $objects = [];
	/** Every (cardId, storageKey) pair the writer actually asked for. */
	private array $reads = [];

	private const KEY_VISIBLE = 'aaaa1111bbbb2222cccc3333dddd4444';
	private const KEY_HIDDEN = 'ffff9999eeee8888dddd7777cccc6666';

	protected function setUp(): void {
		parent::setUp();
		$this->exportService = $this->createMock(ExportService::class);
		$this->attachmentMapper = $this->createMock(CardAttachmentMapper::class);
		$this->attachmentService = $this->createMock(CardAttachmentService::class);
		$this->tempManager = $this->createMock(ITempManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->tempManager->method('getTemporaryFile')->willReturnCallback(function (): string {
			$path = tempnam(sys_get_temp_dir(), 'kanso-archive-test-');
			self::assertIsString($path);
			$this->tempFiles[] = $path;
			return $path;
		});

		$this->attachmentMapper->method('findByCard')->willReturnCallback(
			fn (int $cardId): array => $this->rowsByCard[$cardId] ?? [],
		);

		// Stands in for app-data: hands back a read stream for a stored object,
		// null when nothing lives under that key. Records every lookup so a test
		// can assert which bytes were even reached for.
		$this->attachmentService->method('openStoredObject')->willReturnCallback(
			function (int $cardId, string $storageKey) {
				$this->reads[] = $cardId . ':' . $storageKey;
				$bytes = $this->objects[$cardId . ':' . $storageKey] ?? null;
				if ($bytes === null) {
					return null;
				}
				$stream = fopen('php://temp', 'r+b');
				self::assertIsResource($stream);
				fwrite($stream, $bytes);
				rewind($stream);
				return $stream;
			},
		);

		$this->service = new BoardArchiveService(
			$this->exportService,
			$this->attachmentMapper,
			$this->attachmentService,
			$this->tempManager,
			$this->logger,
		);
	}

	protected function tearDown(): void {
		foreach ($this->tempFiles as $path) {
			@unlink($path);
		}
		$this->tempFiles = [];
		parent::tearDown();
	}

	private function board(): Board {
		$board = new Board();
		$board->setId(7);
		$board->setTitle('Roadmap');
		return $board;
	}

	private function row(int $id, int $cardId, string $filename, string $storageKey): CardAttachment {
		$attachment = new CardAttachment();
		$attachment->setId($id);
		$attachment->setCardId($cardId);
		$attachment->setBoardId(7);
		$attachment->setFilename($filename);
		$attachment->setMime('application/pdf');
		$attachment->setSize(11);
		$attachment->setStorageKey($storageKey);
		$attachment->setUploadedBy('bob');
		$attachment->setCreatedAt(700);
		return $attachment;
	}

	/**
	 * A one-card envelope shaped exactly like {@see ExportService::export()}
	 * builds it, with the card's attachment manifest.
	 */
	private function envelope(array $manifest, int $cardId = 41): array {
		return [
			'kanso' => ExportService::FORMAT_VERSION,
			'exportedAt' => 1234,
			'board' => [
				'title' => 'Roadmap',
				'cards' => [[
					'id' => $cardId,
					'title' => 'Fix login',
					'attachments' => $manifest,
				]],
			],
		];
	}

	/** @return array<string, string> entry name => content, read back from the built zip */
	private function readArchive(string $path): array {
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($path) === true, 'the archive must be a readable zip');
		$entries = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			self::assertIsString($name);
			$entries[$name] = (string)$zip->getFromIndex($i);
		}
		$zip->close();
		return $entries;
	}

	// ── happy path ────────────────────────────────────────────────────────────

	public function testArchiveCarriesBoardJsonAndEveryAttachmentsBytes(): void {
		$manifest = [[
			'id' => 9,
			'filename' => 'spec.pdf',
			'mime' => 'application/pdf',
			'size' => 11,
			'uploadedBy' => 'bob',
			'createdAt' => 700,
			'path' => 'attachments/9/spec.pdf',
		]];
		$this->exportService->method('export')->willReturn($this->envelope($manifest));
		$this->rowsByCard = [41 => [$this->row(9, 41, 'spec.pdf', self::KEY_VISIBLE)]];
		$this->objects = ['41:' . self::KEY_VISIBLE => 'PDF-BYTES-1'];

		$path = $this->service->build($this->board(), $this->viewer());
		$this->tempFiles[] = $path;
		$entries = $this->readArchive($path);

		self::assertArrayHasKey('board.json', $entries);
		$doc = json_decode($entries['board.json'], true);
		self::assertSame(ExportService::FORMAT_VERSION, $doc['kanso']);
		self::assertSame('Roadmap', $doc['board']['title']);

		// The file the export used to drop entirely, at exactly the path its own
		// manifest advertises.
		self::assertArrayHasKey('attachments/9/spec.pdf', $entries);
		self::assertSame('PDF-BYTES-1', $entries['attachments/9/spec.pdf']);
	}

	public function testAViewerScopedArchiveIsBuiltForThatViewer(): void {
		$this->exportService->expects(self::once())->method('export')
			->with(self::isInstanceOf(Board::class), self::isInstanceOf(ViewerContext::class))
			->willReturn($this->envelope([]));

		$path = $this->service->build($this->board(), $this->viewer());
		$this->tempFiles[] = $path;

		self::assertSame(['board.json'], array_keys($this->readArchive($path)));
	}

	public function testTheBackupCronsArchiveIsBuiltAtSystemScopeWithItsAttachments(): void {
		// The decided #10060 policy: a backup passes a null viewer, so it carries
		// the FULL card set and therefore the files of cards a normal exporter
		// could not see. A backup that dropped them would not restore.
		$manifest = [[
			'id' => 10,
			'filename' => 'private.pdf',
			'mime' => 'application/pdf',
			'size' => 11,
			'uploadedBy' => 'bob',
			'createdAt' => 700,
			'path' => 'attachments/10/private.pdf',
		]];
		$this->exportService->expects(self::once())->method('export')
			->with(self::isInstanceOf(Board::class), null)
			->willReturn($this->envelope($manifest, 42));
		$this->rowsByCard = [42 => [$this->row(10, 42, 'private.pdf', self::KEY_HIDDEN)]];
		$this->objects = ['42:' . self::KEY_HIDDEN => 'HIDDEN-BYTE'];

		$path = $this->service->build($this->board(), null);
		$this->tempFiles[] = $path;
		$entries = $this->readArchive($path);

		self::assertSame('HIDDEN-BYTE', $entries['attachments/10/private.pdf'] ?? null);
	}

	// ── the withheld-key invariant ────────────────────────────────────────────

	public function testTheStorageKeyAppearsNowhereInTheArchive(): void {
		// `storage_key` is the server-generated object name; it is withheld from
		// every API response and must not escape through the archive either -
		// not in the document, not in an entry name, not in an entry's bytes.
		$manifest = [[
			'id' => 9,
			'filename' => 'spec.pdf',
			'mime' => 'application/pdf',
			'size' => 11,
			'uploadedBy' => 'bob',
			'createdAt' => 700,
			'path' => 'attachments/9/spec.pdf',
		]];
		$this->exportService->method('export')->willReturn($this->envelope($manifest));
		$this->rowsByCard = [41 => [$this->row(9, 41, 'spec.pdf', self::KEY_VISIBLE)]];
		$this->objects = ['41:' . self::KEY_VISIBLE => 'PDF-BYTES-1'];

		$path = $this->service->build($this->board(), $this->viewer());
		$this->tempFiles[] = $path;

		// The strongest form of the assertion: scan the RAW archive bytes, so
		// neither a new manifest field nor a future entry-naming scheme can
		// reintroduce the key without turning this red.
		$raw = file_get_contents($path);
		self::assertIsString($raw);
		self::assertStringNotContainsString(self::KEY_VISIBLE, $raw, 'the storage key must never leave the server');
		self::assertStringNotContainsString('storageKey', $raw);
		self::assertStringNotContainsString('storage_key', $raw);
	}

	// ── visibility ────────────────────────────────────────────────────────────

	public function testACardTheExporterCannotSeeContributesNoFile(): void {
		// The writer packs EXACTLY the manifest it was handed, so a card the
		// viewer-scoped export already filtered out contributes no bytes - even
		// though its attachment rows are still right there in the mapper. (The
		// other half, that the scoped export omits such a card's manifest in the
		// first place, is pinned by ExportServiceTest.)
		$manifest = [[
			'id' => 9,
			'filename' => 'visible.pdf',
			'mime' => 'application/pdf',
			'size' => 11,
			'uploadedBy' => 'bob',
			'createdAt' => 700,
			'path' => 'attachments/9/visible.pdf',
		]];
		$this->exportService->method('export')->willReturn($this->envelope($manifest));
		$this->rowsByCard = [
			41 => [$this->row(9, 41, 'visible.pdf', self::KEY_VISIBLE)],
			42 => [$this->row(10, 42, 'hidden.pdf', self::KEY_HIDDEN)],
		];
		$this->objects = [
			'41:' . self::KEY_VISIBLE => 'PDF-BYTES-1',
			'42:' . self::KEY_HIDDEN => 'HIDDEN-BYTE',
		];

		$path = $this->service->build($this->board(), $this->viewer());
		$this->tempFiles[] = $path;
		$entries = $this->readArchive($path);

		self::assertSame(['board.json', 'attachments/9/visible.pdf'], array_keys($entries));
		self::assertStringNotContainsString('HIDDEN-BYTE', (string)file_get_contents($path));
		// The hidden card's bytes were never even opened.
		self::assertSame([41 . ':' . self::KEY_VISIBLE], $this->reads);
	}

	// ── robustness ────────────────────────────────────────────────────────────

	public function testAVanishedObjectIsLoggedAndSkippedRatherThanFatal(): void {
		// One missing blob must not cost an admin the rest of the board's backup.
		$manifest = [
			['id' => 9, 'filename' => 'gone.pdf', 'path' => 'attachments/9/gone.pdf'],
			['id' => 10, 'filename' => 'here.pdf', 'path' => 'attachments/10/here.pdf'],
		];
		$this->exportService->method('export')->willReturn($this->envelope($manifest));
		$this->rowsByCard = [41 => [
			$this->row(9, 41, 'gone.pdf', self::KEY_VISIBLE),
			$this->row(10, 41, 'here.pdf', self::KEY_HIDDEN),
		]];
		// Only the second object still exists in app-data.
		$this->objects = ['41:' . self::KEY_HIDDEN => 'STILL-HERE'];
		$this->logger->expects(self::once())->method('warning');

		$path = $this->service->build($this->board(), $this->viewer());
		$this->tempFiles[] = $path;
		$entries = $this->readArchive($path);

		self::assertArrayNotHasKey('attachments/9/gone.pdf', $entries);
		self::assertSame('STILL-HERE', $entries['attachments/10/here.pdf'] ?? null);
		self::assertArrayHasKey('board.json', $entries);
	}

	public function testFilenameForSlugsTheBoardTitle(): void {
		$board = new Board();
		$board->setTitle('Q3 / Roadmap "2026"');
		self::assertSame('kanso-Q3-Roadmap-2026.zip', $this->service->filenameFor($board));

		$blank = new Board();
		$blank->setTitle('///');
		self::assertSame('kanso-board.zip', $this->service->filenameFor($blank));
	}

	private function viewer(): ViewerContext {
		return ViewerContext::forMember('alice', 7, ViewerContext::ROLE_INTERNAL, true);
	}
}
