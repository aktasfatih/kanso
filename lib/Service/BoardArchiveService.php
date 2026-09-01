<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\CardAttachmentMapper;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * Packs a board export into the single artifact users and the backup cron
 * actually receive: a zip carrying the {@see ExportService} envelope as
 * `board.json` at the root, plus one entry per card attachment under
 *
 *     attachments/<attachment id>/<filename>
 *
 * exactly at the `path` each card's manifest advertises. Before v3 the export
 * was a bare JSON document and every attachment was silently dropped - an
 * export you chose was a surprise, a scheduled backup you relied on was a
 * data-loss event.
 *
 * Memory: nothing is ever buffered whole. The document is a string (it always
 * was), and every attachment is stream-copied from app-data into a scratch file
 * in 8 KiB chunks, then handed to {@see \ZipArchive} by PATH - so a board with
 * gigabytes of attachments costs a constant amount of RAM. The finished archive
 * is likewise a file on disk that the caller streams out, never a PHP string.
 *
 * Visibility: the writer never decides what may be included. It packs exactly
 * the manifest {@see ExportService} produced for the caller's viewer scope, so
 * a card the exporter cannot see contributes no manifest entry and therefore no
 * bytes. Storage keys are read straight from the DB here and never enter the
 * envelope.
 */
class BoardArchiveService {
	/** The export document's entry name at the archive root. */
	public const DOCUMENT_ENTRY = 'board.json';

	/** Copy buffer for spooling one attachment out of app-data. */
	private const COPY_CHUNK = 8192;

	public function __construct(
		private ExportService $exportService,
		private CardAttachmentMapper $attachmentMapper,
		private CardAttachmentService $attachmentService,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Builds the board's export archive and returns the path of the temporary
	 * file holding it. The CALLER owns that file: stream it, then unlink it.
	 * (It also lives under Nextcloud's temp manager, so a request that dies
	 * mid-stream still gets it cleaned up at shutdown.)
	 *
	 * $viewer is passed straight through to {@see ExportService::export()}:
	 * a ViewerContext scopes the archive to that viewer's visible cards, null
	 * is the SYSTEM scope used by the admin backup cron.
	 *
	 * @throws \OCP\DB\Exception
	 * @throws \RuntimeException if the archive cannot be created or finalised
	 */
	public function build(Board $board, ?ViewerContext $viewer): string {
		$envelope = $this->exportService->export($board, $viewer);
		$json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			throw new \RuntimeException('Failed to encode board ' . $board->getId() . ' to JSON');
		}

		$zipPath = $this->tempManager->getTemporaryFile('.zip');
		if ($zipPath === false) {
			throw new \RuntimeException('No writable temp directory for the export archive');
		}

		$zip = new \ZipArchive();
		// getTemporaryFile() already created an empty file; OVERWRITE makes
		// ZipArchive treat it as a fresh archive rather than a corrupt one.
		if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			@unlink($zipPath);
			throw new \RuntimeException('Could not create the export archive');
		}

		// The scratch files backing the attachment entries. ZipArchive reads
		// them lazily at close(), so they MUST outlive the addFile() calls -
		// they are removed in the finally, after close() has consumed them.
		$spooled = [];
		try {
			$zip->addFromString(self::DOCUMENT_ENTRY, $json);
			$this->addAttachments($zip, $envelope, $spooled);
			$finalised = $zip->close();
		} catch (\Throwable $e) {
			$zip->unchangeAll();
			@$zip->close();
			@unlink($zipPath);
			throw $e;
		} finally {
			foreach ($spooled as $scratch) {
				@unlink($scratch);
			}
		}

		if ($finalised !== true) {
			@unlink($zipPath);
			throw new \RuntimeException('Could not finalise the export archive');
		}
		return $zipPath;
	}

	/**
	 * A download filename for the archive: `kanso-<board title>.zip`, with
	 * everything outside `[A-Za-z0-9._-]` collapsed to a dash so the name is
	 * safe in a Content-Disposition header and on every filesystem.
	 */
	public function filenameFor(Board $board): string {
		$slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$board->getTitle()) ?? '';
		$slug = trim($slug, '-.');
		if (strlen($slug) > 80) {
			$slug = rtrim(substr($slug, 0, 80), '-.');
		}
		if ($slug === '') {
			$slug = 'board';
		}
		return 'kanso-' . $slug . '.zip';
	}

	/**
	 * Adds one entry per manifest attachment, at the path the manifest already
	 * advertises - so the document and the archive layout can never disagree.
	 *
	 * The storage keys are looked up HERE, per card, straight from the DB. They
	 * are deliberately not carried in the envelope, so the writer pays one extra
	 * query for each card that actually has attachments (cards without one cost
	 * nothing) in exchange for the key never existing outside the server.
	 *
	 * A row or object that disappeared between the export and this walk is
	 * logged and skipped, never fatal: one missing blob must not cost an admin
	 * the rest of the board's backup.
	 *
	 * @param array{board: array<string, mixed>, ...} $envelope
	 * @param list<string> $spooled scratch files created here, appended in place
	 */
	private function addAttachments(\ZipArchive $zip, array $envelope, array &$spooled): void {
		$cards = $envelope['board']['cards'] ?? [];
		if (!is_array($cards)) {
			return;
		}

		foreach ($cards as $card) {
			$manifest = is_array($card) ? ($card['attachments'] ?? []) : [];
			if (!is_array($manifest) || $manifest === []) {
				continue;
			}
			$cardId = (int)($card['id'] ?? 0);

			/** @var array<int, string> $keys */
			$keys = [];
			foreach ($this->attachmentMapper->findByCard($cardId) as $row) {
				$keys[(int)$row->getId()] = (string)$row->getStorageKey();
			}

			foreach ($manifest as $entry) {
				if (!is_array($entry)) {
					continue;
				}
				$id = (int)($entry['id'] ?? 0);
				$path = (string)($entry['path'] ?? '');
				$storageKey = $keys[$id] ?? null;
				if ($path === '' || $storageKey === null) {
					$this->logger->warning('Kanso export: attachment row vanished mid-export', [
						'cardId' => $cardId,
						'attachmentId' => $id,
					]);
					continue;
				}

				$stream = $this->attachmentService->openStoredObject($cardId, $storageKey);
				if ($stream === null) {
					$this->logger->warning('Kanso export: attachment object is missing from app-data', [
						'cardId' => $cardId,
						'attachmentId' => $id,
					]);
					continue;
				}

				$scratch = $this->spool($stream);
				if ($scratch === null) {
					$this->logger->warning('Kanso export: could not spool attachment bytes', [
						'cardId' => $cardId,
						'attachmentId' => $id,
					]);
					continue;
				}
				$spooled[] = $scratch;
				$zip->addFile($scratch, $path);
			}
		}
	}

	/**
	 * Copies an app-data read stream into a scratch file in fixed-size chunks
	 * and returns its path (null if it could not be written). The stream is
	 * always closed. ZipArchive can only add an entry from a real path, and
	 * app-data may be object storage with no local path - this is the bridge,
	 * and it is what keeps a 100 MiB attachment off the heap.
	 *
	 * @param resource $stream
	 */
	private function spool($stream): ?string {
		$path = $this->tempManager->getTemporaryFile('.att');
		if ($path === false) {
			fclose($stream);
			return null;
		}
		$out = @fopen($path, 'wb');
		if ($out === false) {
			fclose($stream);
			@unlink($path);
			return null;
		}
		// An explicit chunk loop rather than stream_copy_to_stream(): the bound
		// on resident bytes is then visible in the code, not an implementation
		// detail of the runtime.
		$failed = false;
		while (!feof($stream)) {
			$chunk = fread($stream, self::COPY_CHUNK);
			if ($chunk === false || ($chunk !== '' && fwrite($out, $chunk) === false)) {
				$failed = true;
				break;
			}
		}
		fclose($stream);
		fclose($out);
		if ($failed) {
			@unlink($path);
			return null;
		}
		return $path;
	}
}
