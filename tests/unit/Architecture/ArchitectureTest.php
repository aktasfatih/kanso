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
