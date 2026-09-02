<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * String-scan architecture rules (#3741/#3742) - cheap greps over lib/ that
 * keep the client-collaboration access model in ONE place each:
 *
 * - ViewerContext::forMember() is mintable only by the BoardAccess resolver
 *   (one door: holding a context always means the ACL was read).
 * - The `kanso_cards` table may only be addressed by the existing, known
 *   query classes; every new read path must go through CardMapper or take
 *   the CardVisibilityScope filter. The allowlist may only SHRINK - the
 *   scan asserts exact set equality, so both a NEW raw query site and a
 *   stale allowlist entry fail the build.
 */
class ArchitectureTest extends TestCase {
	private const LIB_DIR = __DIR__ . '/../../../lib';

	/**
	 * Every non-migration file under lib/ that may address the `kanso_cards`
	 * table by name (the quoted literal a QueryBuilder from()/join() takes;
	 * comments and prose mentions don't use quotes). This list is FROZEN and
	 * may only SHRINK: as read paths adopt CardVisibilityScope (epic 3,
	 * #3743) entries migrate out; a NEW file addressing the table - i.e. a
	 * raw card query bypassing CardMapper + the visibility scope - fails the
	 * scan. Migrations are exempt (schema DDL, not queries).
	 */
	private const KANSO_CARDS_QUERY_ALLOWLIST = [
		'Db/CardAssigneeMapper.php',
		'Db/CardContactMapper.php',
		'Db/CardLabelMapper.php',
		'Db/CardLinkMapper.php',
		'Db/CardMapper.php',
		'Db/CardRelationMapper.php',
		'Db/CardReviewMapper.php',
		'Db/ChangeMapper.php',
		'Db/ChecklistItemMapper.php',
		'Db/CommentMapper.php',
		'Db/ProjectCardMapper.php',
	];

	/**
	 * Every file under lib/ (relative paths, '/' separators) whose content
	 * matches $predicate.
	 *
	 * @param callable(string): bool $predicate content predicate
	 * @return string[] sorted relative paths
	 */
	private static function scanLib(callable $predicate): array {
		$hits = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(self::LIB_DIR, \FilesystemIterator::SKIP_DOTS),
		);
		/** @var \SplFileInfo $file */
		foreach ($iterator as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}
			$content = file_get_contents($file->getPathname());
			self::assertIsString($content, 'unreadable file: ' . $file->getPathname());
			if ($predicate($content)) {
				$relative = substr($file->getPathname(), strlen(self::LIB_DIR) + 1);
				$hits[] = str_replace('\\', '/', $relative);
			}
		}
		sort($hits);
		return $hits;
	}

	public function testKansoCardsIsQueriedOnlyByAllowlistedClasses(): void {
		$actual = self::scanLib(
			static fn (string $content): bool => str_contains($content, "'kanso_cards'"),
		);
		// Schema migrations legitimately name the table (getTable/DDL) and
		// are not query sites - exempt the whole directory.
		$actual = array_values(array_filter(
			$actual,
			static fn (string $path): bool => !str_starts_with($path, 'Migration/'),
		));

		self::assertSame(
			self::KANSO_CARDS_QUERY_ALLOWLIST,
			$actual,
			'The set of classes addressing the kanso_cards table changed. This allowlist '
			. 'exists so the card-visibility rule (#3741) lives in ONE place: a NEW query '
			. 'site would bypass CardVisibilityScope - route it through CardMapper or '
			. 'apply the scope instead (see #3743 for the wiring pattern). If a listed '
			. 'class no longer queries the table, SHRINK the allowlist - it must never grow.',
		);
	}

	/**
	 * Read-path completeness ratchet (#3743): every lib/ class that touches
	 * CardMapper either carries a visibility construct (CardVisibilityScope /
	 * CardVisibilityGuard / ViewerContext / BoardAccess - i.e. it resolved a
	 * viewer or gates per card) or sits on this FROZEN allowlist of paths that
	 * are deliberately viewer-less. The list may only SHRINK: a NEW service or
	 * controller reading cards without any visibility construct fails the
	 * build instead of silently shipping the "second read path that leaks".
	 *
	 * Why each entry is allowed to stay viewer-less:
	 * - Db/BoardPinMapper.php ............ docblock mention only, no card query
	 * - Service/ArchiveService.php ....... auto-archive cron (system actor,
	 *                                      write-only sweep); the MANAGE-only
	 *                                      archiveNow count deliberately stays
	 *                                      an unscoped aggregate - decision
	 *                                      documented on archiveNow (#3760)
	 * - Service/BulkCardService.php ...... delegates every mutation to per-card
	 *                                      services, which gate visibility and
	 *                                      convert hidden → skipped
	 * - Service/CalendarFeedService.php .. anonymous feed; the PUBLIC-ONLY
	 *                                      scope is applied inside
	 *                                      CardMapper::findWithDuedateByBoard
	 * - Service/CsvImportService.php ..... importer, creates cards only
	 * - Service/DeckImportService.php .... importer, creates cards only
	 * - Service/PublicShareService.php ... anonymous snapshot; PUBLIC-ONLY is
	 *                                      applied inside findPublicByBoard /
	 *                                      progressByBoardPublicOnly
	 * - Service/TrelloImportService.php .. importer, creates cards only
	 *
	 * Shrunk by #3760: Notification/Notifier.php (render-time audience gate),
	 * Service/DueReminderService.php (batched recipient filter) and
	 * Service/RecurrenceService.php (template-visibility gates + verbatim
	 * visibility inheritance) now carry visibility constructs.
	 */
	private const VIEWERLESS_CARD_READER_ALLOWLIST = [
		'Db/BoardPinMapper.php',
		'Service/ArchiveService.php',
		'Service/BulkCardService.php',
		'Service/CalendarFeedService.php',
		'Service/CsvImportService.php',
		'Service/DeckImportService.php',
		'Service/PublicShareService.php',
		'Service/TrelloImportService.php',
	];

	public function testEveryCardMapperConsumerCarriesAVisibilityConstruct(): void {
		$actual = self::scanLib(
			static fn (string $content): bool => str_contains($content, 'CardMapper')
				&& !str_contains($content, 'CardVisibilityScope')
				&& !str_contains($content, 'CardVisibilityGuard')
				&& !str_contains($content, 'ViewerContext')
				&& !str_contains($content, 'BoardAccess'),
		);
		// Schema migrations and the mapper's own entity are not read paths.
		$actual = array_values(array_filter(
			$actual,
			static fn (string $path): bool => !str_starts_with($path, 'Migration/')
				&& $path !== 'Db/Card.php',
		));

		self::assertSame(
			self::VIEWERLESS_CARD_READER_ALLOWLIST,
			$actual,
			'A lib/ class reads cards (references CardMapper) without any visibility '
			. 'construct. Every viewer-facing read path must resolve the viewer '
			. '(BoardAccess/ViewerContext) and scope its queries (CardVisibilityScope) '
			. 'or gate per card (CardVisibilityGuard) - see #3743 for the wiring '
			. 'pattern. If the new path is genuinely viewer-less (cron, importer, '
			. 'anonymous-with-scope-in-the-mapper), justify it in the allowlist doc '
			. 'above; if a listed class gained a visibility construct, SHRINK the list.',
		);
	}

	/**
	 * Archived-board ratchet (#10126). Every CROSS-BOARD feed resolves its board
	 * set through {@see \OCA\Kanso\Service\BoardService::findAllActive()}, which
	 * drops boards the user has archived. `findAll()` keeps them and exists for
	 * exactly one consumer - the boards-LIST payload, which the boards page
	 * splits into its Active / Archived tabs - so it is called only from inside
	 * BoardService itself ({@see \OCA\Kanso\Service\BoardService::findAllWithStats()}).
	 *
	 * This is the cross-surface guarantee the per-service tests cannot give: a
	 * NEW feed wired to `$this->boardService->findAll(...)` re-opens the leak on
	 * a surface nobody wrote an archived-board test for, and fails here instead.
	 */
	private const BOARD_SERVICE_FIND_ALL_ALLOWLIST = [];

	public function testCrossBoardFeedsResolveBoardsThroughFindAllActive(): void {
		$actual = self::scanLib(
			static fn (string $content): bool => str_contains($content, 'boardService->findAll('),
		);

		self::assertSame(
			self::BOARD_SERVICE_FIND_ALL_ALLOWLIST,
			$actual,
			'A lib/ class resolves its board set with BoardService::findAll(), which still '
			. 'carries boards the user has ARCHIVED (#10126). Cross-board feeds must call '
			. 'findAllActive() instead, so a shelved board stops surfacing its cards - '
			. 'unconditionally, independent of the archived-CARDS facet. findAll() is for '
			. 'the boards-LIST payload only (it feeds the boards page Archived tab), and '
			. 'BoardService reaches it internally as $this->findAll().',
		);
	}

	public function testViewerContextIsMintedOnlyByBoardAccess(): void {
		$expected = [
			'Access/BoardAccess.php',
			'Access/ViewerContext.php', // the definition itself
		];

		$actual = self::scanLib(
			static fn (string $content): bool => str_contains($content, 'forMember('),
		);

		self::assertSame(
			$expected,
			$actual,
			'ViewerContext::forMember() has exactly ONE caller in lib/: the BoardAccess '
			. 'resolver. Minting a context anywhere else bypasses the ACL fold '
			. '(internal-wins, owner-implicit, manager-stripping-for-externals) and '
			. 'with it the whole card-visibility model. Resolve through BoardAccess instead.',
		);
	}
}
