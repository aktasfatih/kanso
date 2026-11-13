<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Integration\ReferenceUpgrade;

use OC\DB\ConnectionAdapter;
use OC\DB\ConnectionFactory;
use OC\DB\SchemaWrapper;
use OCA\Kanso\AppInfo\Application;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Migration\Version006900Date20261022000000;
use OCA\Kanso\Migration\Version007600Date20261112000000;
use OCA\Kanso\Service\BoardPurgeService;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\CardVisibilityScope;
use OCA\Kanso\Service\StackService;
use OCA\Kanso\Service\TrashService;
use OCP\DB\Types;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;

if (PHP_SAPI !== 'cli' || getenv('KANSO_INTEGRATION_DISPOSABLE') !== '1') {
	throw new \RuntimeException('Set KANSO_INTEGRATION_DISPOSABLE=1 only for a disposable Nextcloud installation');
}
$nextcloudRoot = getenv('NEXTCLOUD_ROOT');
if ($nextcloudRoot === false || !is_file($nextcloudRoot . '/lib/base.php')) {
	throw new \RuntimeException('NEXTCLOUD_ROOT must point to an installed Nextcloud checkout');
}
define('OC_CONSOLE', true);
require_once $nextcloudRoot . '/lib/base.php';
set_exception_handler(static function (\Throwable $e): void {
	fwrite(STDERR, (string)$e . "\n");
	exit(1);
});
\OC::$composerAutoloader->addPsr4('OCA\\Kanso\\', dirname(__DIR__, 2) . '/lib/', true);
\OC_App::loadApps();
$container = (new Application())->getContainer();
$db = $container->get(IDBConnection::class);
$provider = $db->getDatabaseProvider();
$prefix = $container->get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
$options = ['tablePrefix' => $prefix];
if (($argv[1] ?? '') === 'sqlite-history') {
	verifySources($nextcloudRoot);
	sqliteSchemaHistory($container, $prefix);
	echo "OK isolated SQLite history; owned file removed\n";
	exit(0);
}
$admin = $container->get(IUserManager::class)->get('admin');
check($admin !== null, 'The disposable installation must have an admin user');
$container->get(IUserSession::class)->setUser($admin);
\OC_Util::setupFS('admin');

function check(bool $condition, string $message): void {
	if (!$condition) {
		throw new \RuntimeException($message);
	}
}

function scalar(IDBConnection $db, string $sql, array $parameters = []): mixed {
	$result = $db->executeQuery($sql, $parameters);
	try {
		return $result->fetchOne();
	} finally {
		$result->closeCursor();
	}
}

function counter(IDBConnection $db, int $boardId): int {
	$value = scalar($db, 'SELECT last_seq FROM *PREFIX*kanso_card_sequences WHERE board_id = ?', [$boardId]);
	check($value !== false, 'The board counter is missing');
	return (int)$value;
}

function verifySources(string $nextcloudRoot): void {
	foreach ([BoardService::class, BoardMapper::class, CardService::class, CardMapper::class, TrashService::class,
		BoardPurgeService::class, Version006900Date20261022000000::class, Version007600Date20261112000000::class,
		ConnectionFactory::class, ConnectionAdapter::class, SchemaWrapper::class] as $class) {
		$expected = str_starts_with($class, 'OCA\\Kanso\\')
			? dirname(__DIR__, 2) . '/lib/' . str_replace('\\', '/', substr($class, strlen('OCA\\Kanso\\'))) . '.php'
			: $nextcloudRoot . '/lib/private/' . str_replace('\\', '/', substr($class, strlen('OC\\'))) . '.php';
		$loaded = (new \ReflectionClass($class))->getFileName();
		check(realpath($loaded) === realpath($expected), 'Production class loaded outside the selected checkout: ' . $class);
		echo 'SOURCE ' . $class . ' ' . $loaded . ' sha256=' . hash_file('sha256', $loaded) . "\n";
	}
}

function insertFixtureCard(IDBConnection $db, int $boardId, int $sequence): int {
	$qb = $db->getQueryBuilder();
	$qb->insert('kanso_cards')->values([
		'board_id' => $qb->createNamedParameter($boardId),
		'board_seq' => $qb->createNamedParameter($sequence),
		'estimate' => $qb->createNamedParameter('1'),
	]);
	$qb->executeStatement();
	return $qb->getLastInsertId();
}

function allocateFixtureCard(IDBConnection $db, CardMapper $cards, int $boardId): array {
	$db->beginTransaction();
	try {
		$sequence = $cards->nextBoardSeq($boardId);
		$id = insertFixtureCard($db, $boardId, $sequence);
		$db->commit();
		return ['id' => $id, 'sequence' => $sequence];
	} catch (\Throwable $e) {
		$db->rollBack();
		throw $e;
	}
}

function sqliteSchemaHistory(object $container, string $prefix): void {
	$file = tempnam(sys_get_temp_dir(), 'kanso-reference-upgrade-');
	check($file !== false, 'Could not create an isolated SQLite fixture');
	$connection = null;
	try {
		$connection = $container->get(ConnectionFactory::class)->getConnection('sqlite3', [
			'path' => $file, 'tablePrefix' => $prefix, 'sqlite.journal_mode' => 'DELETE',
			'defaultTableOptions' => ['tablePrefix' => $prefix],
		]);
		check($connection->getParams()['path'] === $file && $connection->getPrefix() === $prefix,
			'Isolated SQLite connection selected an unexpected file or table prefix');
		$fixture = new ConnectionAdapter($connection);
		check($fixture->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE, 'Isolated fixture must use SQLite');
		$databaseList = $fixture->executeQuery('PRAGMA database_list');
		$mainFile = null;
		try {
			while (($database = $databaseList->fetch()) !== false) {
				if ($database['name'] === 'main') {
					$mainFile = $database['file'];
				}
			}
		} finally {
			$databaseList->closeCursor();
		}
		check(is_string($mainFile) && realpath($mainFile) === realpath($file), 'SQLite main database is not the newly owned fixture file');
		echo 'ISOLATED sqlite main=' . $mainFile . ' prefix=' . $prefix . "\n";
		$schema = new SchemaWrapper($connection);
		$boards = $schema->createTable('kanso_boards');
		$boards->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
		$boards->addColumn('prefix', Types::STRING, ['notnull' => true, 'length' => 12]);
		$boards->setPrimaryKey(['id'], 'ku_boards_pk');
		$cards = $schema->createTable('kanso_cards');
		$cards->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
		$cards->addColumn('board_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
		$cards->addColumn('board_seq', Types::BIGINT, ['notnull' => false, 'length' => 8]);
		$cards->addColumn('estimate', Types::STRING, ['notnull' => false, 'length' => 16]);
		$cards->setPrimaryKey(['id'], 'ku_cards_pk');
		$cards->addUniqueIndex(['board_id', 'board_seq'], 'ku_cards_ref');
		$aliases = $schema->createTable('kanso_card_move_aliases');
		$aliases->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
		$aliases->addColumn('old_card_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
		$aliases->addColumn('target_card_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
		$aliases->setPrimaryKey(['id'], 'ku_alias_pk');
		$aliases->addUniqueIndex(['old_card_id'], 'ku_alias_old');
		$connection->migrateToSchema($schema->getWrappedSchema());
		$fixture->executeStatement('INSERT INTO *PREFIX*kanso_boards (prefix) VALUES (?)', ['LOST']);
		$boardId = (int)scalar($fixture, 'SELECT id FROM *PREFIX*kanso_boards');
		$survivorId = insertFixtureCard($fixture, $boardId, 1);
		$purgedId = insertFixtureCard($fixture, $boardId, 2);
		$fixture->executeStatement('DELETE FROM *PREFIX*kanso_cards WHERE id = ?', [$purgedId]);
		$identityBefore = (int)scalar($fixture, 'SELECT seq FROM sqlite_sequence WHERE name = ?', [$prefix . 'kanso_cards']);
		check($identityBefore === $purgedId && $purgedId > $survivorId, 'Pre-widening fixture did not retain the purged identity');
		$oldSchema = new SchemaWrapper($connection);
		$widened = (new Version006900Date20261022000000())->changeSchema(new \OC\Migration\NullOutput(), static fn () => $oldSchema, ['tablePrefix' => $prefix]);
		check($widened !== null && $widened->getTable('kanso_cards')->getColumn('estimate')->getLength() === 32,
			'Published estimate migration did not widen the fixture card table');
		$connection->migrateToSchema($widened->getWrappedSchema());
		$identityAfter = (int)scalar($fixture, 'SELECT seq FROM sqlite_sequence WHERE name = ?', [$prefix . 'kanso_cards']);
		check($identityAfter === $survivorId && $identityAfter < $identityBefore, 'Real SQLite widening did not expose the historical identity loss');
		check((int)scalar($fixture, 'SELECT MAX(id) FROM *PREFIX*kanso_cards') === $survivorId
			&& (int)scalar($fixture, 'SELECT MAX(board_seq) FROM *PREFIX*kanso_cards') === 1
			&& (int)scalar($fixture, 'SELECT COUNT(*) FROM *PREFIX*kanso_card_move_aliases') === 0,
			'Purged history unexpectedly survived in a source available to the upgrade');
		echo 'EVIDENCE sqlite published-widening ' . json_encode(['purgedIdentity' => $purgedId, 'identityBefore' => $identityBefore,
			'identityAfter' => $identityAfter, 'survivingSequence' => 1, 'aliasRows' => 0], JSON_THROW_ON_ERROR) . "\n";

		$migration = new Version007600Date20261112000000($fixture);
		$upgradeSchema = new SchemaWrapper($connection);
		$upgraded = $migration->changeSchema(new \OC\Migration\NullOutput(), static fn () => $upgradeSchema, ['tablePrefix' => $prefix]);
		check($upgraded !== null, 'Reference upgrade did not create its fixture schema');
		$connection->migrateToSchema($upgraded->getWrappedSchema());
		$migration->postSchemaChange(new \OC\Migration\NullOutput(), static fn () => null, ['tablePrefix' => $prefix]);
		check(counter($fixture, $boardId) === 1, 'Upgrade claimed to recover an unavailable historical floor');
		$allocator = new CardMapper($fixture, new CardVisibilityScope());
		$firstAfterUpgrade = allocateFixtureCard($fixture, $allocator, $boardId);
		check($firstAfterUpgrade['sequence'] === 2, 'Fixture no longer demonstrates the bounded lost-history residual');
		echo 'RESIDUAL sqlite ' . json_encode(['lostHistoricalReference' => 'LOST-2', 'nextReference' => 'LOST-' . $firstAfterUpgrade['sequence'],
			'reason' => 'published widening erased the purged identity before a durable board counter existed'], JSON_THROW_ON_ERROR) . "\n";

		$afterCutover = allocateFixtureCard($fixture, $allocator, $boardId);
		$fixture->executeStatement('DELETE FROM *PREFIX*kanso_cards WHERE id = ?', [$afterCutover['id']]);
		$recorded = counter($fixture, $boardId);
		$nextSchema = new SchemaWrapper($connection);
		$nextSchema->getTable('kanso_cards')->getColumn('estimate')->setLength(64);
		$connection->migrateToSchema($nextSchema->getWrappedSchema());
		$postIdentity = (int)scalar($fixture, 'SELECT seq FROM sqlite_sequence WHERE name = ?', [$prefix . 'kanso_cards']);
		check($postIdentity < $afterCutover['id'],
			'Post-cutover fixture did not rebuild the card table after purge');
		check(counter($fixture, $boardId) === $recorded, 'Card table widening changed its durable board counter');
		$migration->postSchemaChange(new \OC\Migration\NullOutput(), static fn () => null, ['tablePrefix' => $prefix]);
		check(counter($fixture, $boardId) === $recorded, 'Upgrade rerun reset a post-cutover high-water mark');
		$afterSecondWidening = allocateFixtureCard($fixture, $allocator, $boardId);
		check($afterSecondWidening['sequence'] > $afterCutover['sequence'], 'Post-cutover widening and purge reused a committed human number');
		echo 'EVIDENCE sqlite post-cutover ' . json_encode(['purgedIdentity' => $afterCutover['id'], 'identityAfterWidening' => $postIdentity,
			'counterBeforeWidening' => $recorded, 'counterAfterWidening' => $recorded,
			'nextSequence' => $afterSecondWidening['sequence']], JSON_THROW_ON_ERROR) . "\n";
		echo "PASS sqlite isolated published widening exposes pre-counter loss; post-counter widening preserves allocation\n";
	} finally {
		$connection?->close();
		foreach ([$file, $file . '-wal', $file . '-shm', $file . '-journal'] as $ownedFile) {
			if (is_file($ownedFile)) {
				check(unlink($ownedFile), 'Could not remove isolated fixture ' . $ownedFile);
			}
		}
	}
}

verifySources($nextcloudRoot);
$boards = $container->get(BoardService::class);
$stacks = $container->get(StackService::class);
$cards = $container->get(CardService::class);
$trash = $container->get(TrashService::class);
$purger = $container->get(BoardPurgeService::class);
$migration = new Version007600Date20261112000000($db);
$ownedBoards = [];
$passed = 0;
$fixture = static function (string $name) use ($boards, $stacks, &$ownedBoards): array {
	$board = $boards->create('Reference upgrade ' . $name . ' ' . bin2hex(random_bytes(5)), null, 'admin');
	$ownedBoards[] = $board->getId();
	return [$board, $stacks->create($board->getId(), 'Work', 'admin')];
};
try {
	[$freshBoard, $freshStack] = $fixture('fresh');
	check(counter($db, $freshBoard->getId()) === 0, 'New board inherited an upgrade floor');
	foreach ([false, true] as $emptyBoard) {
		[$legacyBoard, $legacyStack] = $fixture($emptyBoard ? 'empty' : 'surviving');
		if (!$emptyBoard) {
			$cards->create($legacyStack->getId(), 'Retained one', 'admin');
			$cards->create($legacyStack->getId(), 'Retained two', 'admin');
		}
		$highest = $cards->create($legacyStack->getId(), 'Purged highest', 'admin');
		$cards->delete($highest->getId(), 'admin');
		$trash->purge($highest->getId(), 'admin');
		$survivingMaximum = (int)scalar($db, 'SELECT COALESCE(MAX(board_seq), 0) FROM *PREFIX*kanso_cards WHERE board_id = ?', [$legacyBoard->getId()]);
		check($survivingMaximum + 1 === $highest->getBoardSeq(), 'Fixture did not reproduce surviving-MAX number reuse');
		$db->executeStatement('DELETE FROM *PREFIX*kanso_card_sequences WHERE board_id = ?', [$legacyBoard->getId()]);
		$migration->postSchemaChange(new \OC\Migration\NullOutput(), static fn () => null, $options);
		$seeded = counter($db, $legacyBoard->getId());
		$after = $cards->create($legacyStack->getId(), 'After cutover', 'admin');
		echo 'EVIDENCE ' . $provider . ' cutover ' . json_encode(['emptyBoard' => $emptyBoard, 'purgedSequence' => $highest->getBoardSeq(),
			'purgedCardId' => $highest->getId(), 'survivingMaxCandidate' => $survivingMaximum + 1,
			'retainedFloor' => $seeded, 'nextSequence' => $after->getBoardSeq()], JSON_THROW_ON_ERROR) . "\n";
		check($after->getBoardSeq() > $highest->getBoardSeq(), 'Upgrade reused a pre-cutover purged number');
		check($seeded >= $highest->getId(), 'Upgrade floor discarded the retained native identity of the purged highest card');
		$committed = counter($db, $legacyBoard->getId());
		$migration->postSchemaChange(new \OC\Migration\NullOutput(), static fn () => null, $options);
		check(counter($db, $legacyBoard->getId()) === $committed, 'Upgrade rerun changed an existing board counter');
		check(counter($db, $freshBoard->getId()) === 0, 'Upgrade overwrote an existing fresh-board counter');
		echo 'PASS ' . $provider . ' pre-cutover purge ' . json_encode(['emptyBoard' => $emptyBoard, 'purgedCardId' => $highest->getId(),
			'purgedSequence' => $highest->getBoardSeq(), 'survivingMaxCandidate' => $survivingMaximum + 1,
			'retainedFloor' => $seeded, 'nextSequence' => $after->getBoardSeq()], JSON_THROW_ON_ERROR) . "\n";
		$passed++;
	}
	check($cards->create($freshStack->getId(), 'First on new board', 'admin')->getBoardSeq() === 1, 'New board no longer starts at one');
	$passed++;
	echo 'PASS ' . $provider . " existing counters remain unchanged and new boards start at one\n";
	sqliteSchemaHistory($container, $prefix);
	$passed++;
	check(!$db->inTransaction(), 'Upgrade tests left a database transaction open');
} finally {
	foreach ($ownedBoards as $boardId) {
		$boards->delete($boardId, 'admin');
		check($purger->purge($boardId), 'Could not clean owned board ' . $boardId);
	}
}

echo 'OK ' . $provider . ': ' . $passed . " upgrade checks; owned boards and isolated SQLite file removed\n";
