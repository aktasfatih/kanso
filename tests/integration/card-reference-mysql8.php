<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Integration\MySqlReferences;

use OCA\Kanso\AppInfo\Application;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Migration\Version007600Date20261112000000;
use OCA\Kanso\Service\BoardPurgeService;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\CardMutationService;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\StackService;
use OCA\Kanso\Service\TrashService;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
check($db->getDatabaseProvider() === IDBConnection::PLATFORM_MYSQL, 'Select a disposable Oracle MySQL 8 installation');
$version = (string)scalar($db, 'SELECT VERSION()');
check(str_starts_with($version, '8.') && stripos($version, 'MariaDB') === false, 'This harness requires Oracle MySQL 8');

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

function expiry(IDBConnection $db): int {
	$value = scalar($db, 'SELECT @@SESSION.information_schema_stats_expiry');
	check((is_string($value) || is_int($value)) && ctype_digit((string)$value), 'Invalid metadata cache expiry');
	return (int)$value;
}

function setExpiry(IDBConnection $db, int $value): void {
	$db->executeStatement('SET SESSION information_schema_stats_expiry = ?', [$value], [IQueryBuilder::PARAM_INT]);
}

function nextIdentity(IDBConnection $db, string $table): int {
	$value = scalar($db, 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
	check((is_string($value) || is_int($value)) && ctype_digit((string)$value), 'Card identity metadata is unavailable');
	return (int)$value;
}

foreach ([BoardService::class, BoardMapper::class, StackService::class, CardService::class, CardMapper::class,
	CardMutationService::class, TrashService::class, BoardPurgeService::class, Version007600Date20261112000000::class] as $class) {
	$expected = dirname(__DIR__, 2) . '/lib/' . str_replace('\\', '/', substr($class, strlen('OCA\\Kanso\\'))) . '.php';
	$loaded = (new \ReflectionClass($class))->getFileName();
	check(realpath($loaded) === realpath($expected), 'Production class loaded outside this checkout: ' . $class);
	echo 'SOURCE mysql8 ' . $class . ' ' . $loaded . ' sha256=' . hash_file('sha256', $loaded) . "\n";
}
check($container->get(CardMutationService::class)::class === CardMutationService::class, 'Unexpected production mutation registration');

$database = (string)scalar($db, 'SELECT DATABASE()');
$currentUser = (string)scalar($db, 'SELECT CURRENT_USER()');
check($database === 'kanso_reference' && str_starts_with($currentUser, 'kanso_reference@'), 'Use the dedicated disposable application database user');
$result = $db->executeQuery('SHOW GRANTS');
$grants = [];
try {
	while (($grant = $result->fetchOne()) !== false) {
		$grants[] = (string)$grant;
	}
} finally {
	$result->closeCursor();
}
check($grants !== [], 'The application account did not report its grants');
$originalExpiry = expiry($db);
echo 'EVIDENCE mysql8 account ' . json_encode(['version' => $version, 'database' => $database,
	'currentUser' => $currentUser, 'grants' => $grants, 'originalExpiry' => $originalExpiry], JSON_THROW_ON_ERROR) . "\n";
foreach ($grants as $grant) {
	check(preg_match('/^GRANT USAGE ON \*\.\* TO /i', $grant) === 1
		|| preg_match('/^GRANT .+ ON `' . preg_quote(str_replace('_', '\\_', $database), '/') . '`\.\* TO /i', $grant) === 1,
		'The application account has privileges outside its disposable database');
	check(stripos($grant, 'SYSTEM_VARIABLES_ADMIN') === false && stripos($grant, 'WITH GRANT OPTION') === false,
		'The application account must not have administrative grants');
}
check((int)scalar($db, 'SELECT COUNT(*) FROM *PREFIX*kanso_boards b LEFT JOIN *PREFIX*kanso_card_sequences s ON s.board_id = b.id WHERE s.board_id IS NULL') === 0,
	'An unowned board is missing its counter');
check((int)scalar($db, 'SELECT COUNT(*) FROM *PREFIX*kanso_card_move_aliases WHERE board_id IS NULL') === 0,
	'An unowned legacy alias would be backfilled');

$session = $container->get(IUserSession::class);
$previousUser = $session->getUser();
$admin = $container->get(IUserManager::class)->get('admin');
check($admin !== null, 'The disposable installation must have an admin user');
$boards = $container->get(BoardService::class);
$stacks = $container->get(StackService::class);
$cards = $container->get(CardService::class);
$trash = $container->get(TrashService::class);
$purger = $container->get(BoardPurgeService::class);
$ownedBoardId = null;
$passed = 0;

try {
	$session->setUser($admin);
	\OC_Util::setupFS('admin');
	setExpiry($db, 86400);
	$board = $boards->create('MySQL reference metadata ' . bin2hex(random_bytes(5)), null, 'admin', push: false);
	$ownedBoardId = $board->getId();
	$stack = $stacks->create($ownedBoardId, 'Work', 'admin');
	$first = $cards->create($stack->getId(), 'Surviving card', 'admin');
	$prefix = $container->get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
	$table = $prefix . 'kanso_cards';
	$warmed = nextIdentity($db, $table);
	$highest = $cards->create($stack->getId(), 'Purged highest card', 'admin');
	$cards->delete($highest->getId(), 'admin');
	$trash->purge($highest->getId(), 'admin');
	$cached = nextIdentity($db, $table);
	$positiveExpiry = expiry($db);
	check($positiveExpiry > 0, 'The metadata cache was not warmed with positive expiry');
	setExpiry($db, 0);
	try {
		$fresh = nextIdentity($db, $table);
	} finally {
		setExpiry($db, $positiveExpiry);
	}
	check($fresh > $highest->getId(), 'Fresh metadata discarded the purged native identity');
	echo 'EVIDENCE mysql8 metadata-cache ' . json_encode(['warmedNextIdentity' => $warmed, 'cachedNextIdentity' => $cached,
		'freshNextIdentity' => $fresh, 'cacheWasStale' => $cached < $fresh, 'expiry' => $positiveExpiry,
		'survivingIdentity' => $first->getId(), 'purgedIdentity' => $highest->getId(), 'purgedSequence' => $highest->getBoardSeq()], JSON_THROW_ON_ERROR) . "\n";
	$passed++;
	echo "PASS mysql8 cached and fresh identity metadata are measured after a real card purge\n";

	check($db->executeStatement('DELETE FROM *PREFIX*kanso_card_sequences WHERE board_id = ?', [$ownedBoardId]) === 1,
		'Could not remove the owned board counter for cutover');
	$missingPrefix = 'reference_missing_' . bin2hex(random_bytes(5)) . '_';
	check((int)scalar($db, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
		[$missingPrefix . 'kanso_cards']) === 0, 'Missing metadata fixture unexpectedly exists');
	$migration = new Version007600Date20261112000000($db);
	$failure = null;
	try {
		$migration->postSchemaChange(new \OC\Migration\NullOutput(), static fn () => null, ['tablePrefix' => $missingPrefix]);
	} catch (\RuntimeException $e) {
		$failure = $e;
	}
	check($failure !== null && $failure->getMessage() === 'Invalid card number history', 'Missing metadata did not fail through the production integer guard');
	check(expiry($db) === $positiveExpiry, 'Migration failure did not restore the metadata cache expiry');
	check((int)scalar($db, 'SELECT COUNT(*) FROM *PREFIX*kanso_card_sequences WHERE board_id = ?', [$ownedBoardId]) === 0,
		'Failed metadata lookup wrote the owned counter');
	echo 'EVIDENCE mysql8 failed-metadata ' . json_encode(['exception' => $failure->getMessage(),
		'restoredExpiry' => expiry($db), 'counterRows' => 0], JSON_THROW_ON_ERROR) . "\n";
	$passed++;
	echo "PASS mysql8 production metadata failure restores session expiry without writing a counter\n";

	$migration->postSchemaChange(new \OC\Migration\NullOutput(), static fn () => null, ['tablePrefix' => $prefix]);
	check(expiry($db) === $positiveExpiry, 'Successful migration did not restore the metadata cache expiry');
	$floor = (int)scalar($db, 'SELECT last_seq FROM *PREFIX*kanso_card_sequences WHERE board_id = ?', [$ownedBoardId]);
	check($floor >= $highest->getId() && $floor >= $fresh - 1, 'Migration seeded a floor below the retained purged identity');
	$next = $cards->create($stack->getId(), 'After upgrade', 'admin');
	check($next->getBoardSeq() > $highest->getBoardSeq() && $next->getBoardSeq() === $floor + 1,
		'The real create path reused the purged number after cutover');
	check($cards->findByRef($ownedBoardId, $board->getPrefix() . '-' . $highest->getBoardSeq(), 'admin') === null,
		'A purged human reference resolved to a later card');
	check(expiry($db) === $positiveExpiry, 'The application create path changed the metadata cache expiry');
	check(!$db->inTransaction(), 'Metadata integration leaked a transaction');
	echo 'EVIDENCE mysql8 migration ' . json_encode(['floor' => $floor, 'nextSequence' => $next->getBoardSeq(),
		'restoredExpiry' => expiry($db), 'purgedIdentity' => $highest->getId()], JSON_THROW_ON_ERROR) . "\n";
	$passed++;
	echo "PASS mysql8 ordinary application privileges preserve the purged number at migration cutover\n";
} finally {
	try {
		setExpiry($db, $originalExpiry);
		if ($ownedBoardId !== null) {
			$boards->delete($ownedBoardId, 'admin');
			check($purger->purge($ownedBoardId), 'Could not purge the owned metadata board');
			check((int)scalar($db, 'SELECT COUNT(*) FROM *PREFIX*kanso_boards WHERE id = ?', [$ownedBoardId]) === 0,
				'The owned metadata board survived cleanup');
			check((int)scalar($db, 'SELECT COUNT(*) FROM *PREFIX*kanso_card_sequences WHERE board_id = ?', [$ownedBoardId]) === 0,
				'The owned metadata counter survived cleanup');
		}
		check(expiry($db) === $originalExpiry, 'Cleanup did not restore the original metadata cache expiry');
	} finally {
		\OC_Util::tearDownFS();
		$session->setUser($previousUser);
		if ($previousUser !== null) {
			\OC_Util::setupFS($previousUser->getUID());
		}
	}
}

echo 'OK mysql8: ' . $passed . " metadata integration checks; owned board removed and session expiry restored\n";
