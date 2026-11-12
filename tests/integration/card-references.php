<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Integration\References;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\AppInfo\Application;
use OCA\Kanso\Controller\CardController;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardMoveAliasMapper;
use OCA\Kanso\Migration\Version007600Date20261112000000;
use OCA\Kanso\Service\AclService;
use OCA\Kanso\Service\BoardPurgeService;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\CardMoveAliasService;
use OCA\Kanso\Service\CardMutationService;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\CardVisibilityScope;
use OCA\Kanso\Service\CsvImportService;
use OCA\Kanso\Service\ImportService;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\StackService;
use OCA\Kanso\Service\TrashService;
use OCA\Kanso\Service\TrelloImportService;
use OCP\AppFramework\Db\DoesNotExistException;
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
$admin = $container->get(IUserManager::class)->get('admin');
check($admin !== null, 'The disposable installation must have an admin user');
$container->get(IUserSession::class)->setUser($admin);
\OC_Util::setupFS('admin');

function check(bool $condition, string $message): void {
	if (!$condition) {
		throw new \RuntimeException($message);
	}
}

function sources(): array {
	$classes = [BoardService::class, BoardMapper::class, StackService::class, CardService::class, CardMapper::class,
		CardMutationService::class, CardMoveAliasService::class, CardMoveAliasMapper::class,
		TrashService::class, BoardPurgeService::class, ImportService::class, CsvImportService::class, TrelloImportService::class,
		AclService::class, PermissionService::class, BoardAccess::class, CardVisibilityGuard::class,
		CardVisibilityScope::class, CardController::class, Version007600Date20261112000000::class];
	$sources = [];
	foreach ($classes as $class) {
		$expected = dirname(__DIR__, 2) . '/lib/' . str_replace('\\', '/', substr($class, strlen('OCA\\Kanso\\'))) . '.php';
		$loaded = (new \ReflectionClass($class))->getFileName();
		check(realpath($loaded) === realpath($expected), 'Production class loaded outside this checkout: ' . $class);
		$sources[$class] = ['path' => $loaded, 'sha256' => hash_file('sha256', $loaded)];
	}
	return $sources;
}

function send(array $message): void {
	fwrite(STDOUT, json_encode($message, JSON_THROW_ON_ERROR) . "\n");
	fflush(STDOUT);
}

function receive($stream, int $timeout = 15): array {
	$read = [$stream];
	$write = $except = [];
	check(stream_select($read, $write, $except, $timeout) === 1, 'Timed out waiting for a process barrier');
	$line = fgets($stream);
	check($line !== false, 'Worker exited before its process barrier');
	return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
}

class BarrierMutationService extends CardMutationService {
	private int $operationDepth = 0;

	public function __construct(
		IDBConnection $db,
		CardMapper $cards,
		private bool $hold,
	) {
		parent::__construct($db, $cards, new \Psr\Log\NullLogger());
	}

	public function runForBoards(array $boardIds, callable $write, bool $exclusive = false): mixed {
		$outermost = $this->operationDepth++ === 0;
		try {
			return parent::runForBoards($boardIds, function () use ($write, $outermost): mixed {
				$result = $write();
				if ($this->hold && $outermost) {
					send(['event' => 'held', 'cardId' => $result->getId(), 'sequence' => $result->getBoardSeq()]);
					check((receive(STDIN)['event'] ?? '') === 'release', 'Expected the release barrier');
				}
				return $result;
			}, $exclusive);
		} finally {
			$this->operationDepth--;
		}
	}
}

if (($argv[1] ?? '') === 'worker') {
	$spec = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
	$guard = new BarrierMutationService($db, $container->get(CardMapper::class), $spec['hold']);
	$container->registerService(CardMutationService::class, static fn () => $guard);
	$connection = match ($provider) {
		IDBConnection::PLATFORM_POSTGRES => (int)$db->executeQuery('SELECT pg_backend_pid()')->fetchOne(),
		IDBConnection::PLATFORM_MYSQL => (int)$db->executeQuery('SELECT CONNECTION_ID()')->fetchOne(),
		default => 0,
	};
	match ($provider) {
		IDBConnection::PLATFORM_POSTGRES => $db->executeStatement("SET lock_timeout = '5s'"),
		IDBConnection::PLATFORM_MYSQL => $db->executeStatement('SET innodb_lock_wait_timeout = 5'),
		default => $db->executeStatement('PRAGMA busy_timeout = 100'),
	};
	send(['event' => 'ready', 'connection' => $connection, 'sources' => sources()]);
	check((receive(STDIN)['event'] ?? '') === 'start', 'Expected the start barrier');
	send(['event' => 'attempt']);
	try {
		$card = $container->get(CardService::class)->create($spec['stackId'], $spec['title'], 'admin');
		send(['event' => 'result', 'ok' => true, 'cardId' => $card->getId(), 'sequence' => $card->getBoardSeq()]);
	} catch (\Throwable $e) {
		send(['event' => 'result', 'ok' => false, 'class' => $e::class, 'message' => $e->getMessage()]);
	}
	exit(0);
}

function startWorker(int $stackId, string $title, bool $hold): array {
	$spec = ['stackId' => $stackId, 'title' => $title, 'hold' => $hold];
	$process = proc_open([PHP_BINARY, __FILE__, 'worker', json_encode($spec, JSON_THROW_ON_ERROR)], [
		0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
	], $pipes);
	check(is_resource($process), 'Cannot launch an independent PHP process');
	$ready = receive($pipes[1]);
	check(($ready['event'] ?? '') === 'ready' && ($ready['sources'] ?? null) === sources(), 'Worker source paths or hashes differ');
	fwrite($pipes[0], "{\"event\":\"start\"}\n");
	check((receive($pipes[1])['event'] ?? '') === 'attempt', 'Worker did not reach its operation barrier');
	return ['process' => $process, 'pipes' => $pipes, 'connection' => $ready['connection']];
}

function release(array $worker): void {
	fwrite($worker['pipes'][0], "{\"event\":\"release\"}\n");
}

function finish(array $worker, ?array $result = null): array {
	$result ??= receive($worker['pipes'][1]);
	check(($result['event'] ?? '') === 'result', 'Worker did not return its operation result');
	fclose($worker['pipes'][0]);
	fclose($worker['pipes'][1]);
	$errors = stream_get_contents($worker['pipes'][2]);
	fclose($worker['pipes'][2]);
	check(proc_close($worker['process']) === 0, 'Worker process failed: ' . $errors);
	return $result;
}

function allocationWait(IDBConnection $db, array $worker): ?array {
	if ($db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE) {
		$result = receive($worker['pipes'][1]);
		check(($result['ok'] ?? true) === false && str_contains(strtolower($result['message'] ?? ''), 'locked'),
			'SQLite contender did not encounter the actual write-lock conflict: ' . json_encode($result));
		return $result;
	}
	$mariaDb = $db->getDatabaseProvider() === IDBConnection::PLATFORM_MYSQL
		&& stripos($db->executeQuery('SELECT VERSION()')->fetchOne(), 'mariadb') !== false;
	$deadline = microtime(true) + 4;
	do {
		if ($db->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES) {
			$waiting = $db->executeQuery("SELECT COUNT(*) FROM pg_stat_activity WHERE pid = ? AND wait_event_type = 'Lock' AND query LIKE '%kanso_card_sequences%'", [$worker['connection']])->fetchOne();
		} elseif ($mariaDb) {
			$status = $db->executeQuery('SHOW ENGINE INNODB STATUS')->fetch()['Status'];
			$waiting = 0;
			foreach (preg_split('/(?=---TRANSACTION)/', $status) as $transaction) {
				if (str_contains($transaction, 'LOCK WAIT') && str_contains($transaction, 'kanso_card_sequences')
					&& preg_match('/MariaDB thread id ' . $worker['connection'] . '\\b/', $transaction) === 1) {
					$waiting = 1;
					break;
				}
			}
		} else {
			$waiting = $db->executeQuery("SELECT COUNT(*) FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID WHERE t.PROCESSLIST_ID = ? AND l.OBJECT_NAME LIKE '%kanso_card_sequences'", [$worker['connection']])->fetchOne();
		}
		if ((int)$waiting > 0) {
			return null;
		}
		$read = [$worker['pipes'][1]];
		$write = $except = [];
		if (stream_select($read, $write, $except, 0, 0) === 1) {
			throw new \RuntimeException('Contender returned without waiting for the allocation lock: ' . json_encode(receive($worker['pipes'][1])));
		}
		usleep(10000);
	} while (microtime(true) < $deadline);
	throw new \RuntimeException('Database did not report an allocation lock wait; connection=' . $worker['connection']);
}

function expectFailure(callable $operation, string $class): void {
	try {
		$operation();
	} catch (\Throwable $e) {
		check($e instanceof $class, 'Expected ' . $class . ', got ' . $e::class . ': ' . $e->getMessage());
		return;
	}
	throw new \RuntimeException('Expected ' . $class . ' but operation succeeded');
}

function reference(Board $board, Card $card): string {
	return $board->getPrefix() . '-' . $card->getBoardSeq();
}

foreach (sources() as $class => $source) {
	echo 'SOURCE ' . $provider . ' ' . $class . ' ' . $source['path'] . ' sha256=' . $source['sha256'] . "\n";
}
check($container->get(CardMutationService::class)::class === CardMutationService::class, 'Unexpected production mutation registration');

$boards = $container->get(BoardService::class);
$stacks = $container->get(StackService::class);
$cards = $container->get(CardService::class);
$mapper = $container->get(CardMapper::class);
$trash = $container->get(TrashService::class);
$aliases = $container->get(CardMoveAliasService::class);
$purger = $container->get(BoardPurgeService::class);
$ownedBoards = [];
$ownedAliases = [];
$peer = null;
$passed = 0;
$fixture = static function (string $prefix) use ($boards, $stacks, &$ownedBoards): array {
	$board = $boards->create('Reference integration ' . bin2hex(random_bytes(5)), null, 'admin');
	$ownedBoards[$board->getId()] = true;
	$board = $boards->update($board->getId(), null, null, null, 'admin', prefix: $prefix);
	return [$board, $stacks->create($board->getId(), 'Work', 'admin')];
};
$pass = static function (string $message) use (&$passed, $provider): void {
	$passed++;
	echo 'PASS ' . $provider . ' ' . $message . "\n";
};
$move = static function (Card $source, int $targetStack) use ($cards, &$ownedAliases): Card {
	$result = $cards->moveToBoard($source->getId(), $targetStack, 'admin');
	$ownedAliases[] = $source->getId();
	return $result;
};

try {
	$db->beginTransaction();
	try {
		$rolledBack = $boards->create('Reference rollback ' . bin2hex(random_bytes(5)), null, 'admin', push: false);
		check((int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*kanso_card_sequences WHERE board_id = ?', [$rolledBack->getId()])->fetchOne() === 1,
			'Board creation did not initialize its counter in the outer transaction');
	} finally {
		$db->rollBack();
	}
	expectFailure(static fn () => $container->get(BoardMapper::class)->find($rolledBack->getId()), DoesNotExistException::class);
	check((int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*kanso_card_sequences WHERE board_id = ?', [$rolledBack->getId()])->fetchOne() === 0,
		'Rolling back board creation left its counter behind');
	$pass('board and counter creation roll back with an existing outer transaction');

	[$board, $stack] = $fixture('SEQ');
	$first = $cards->create($stack->getId(), 'First', 'admin');
	$highest = $cards->create($stack->getId(), 'Highest', 'admin');
	check($first->getBoardSeq() === 1 && $highest->getBoardSeq() === 2, 'Fresh board numbers must start at one');
	$cards->delete($highest->getId(), 'admin');
	$trash->purge($highest->getId(), 'admin');
	$next = $cards->create($stack->getId(), 'After purge', 'admin');
	check($next->getBoardSeq() > $highest->getBoardSeq(), 'Purging the highest card reused its number');
	check($cards->findByRef($board->getId(), reference($board, $highest), 'admin') === null, 'A purged number resolved to a later card');
	$cards->delete($next->getId(), 'admin');
	check($trash->restore($next->getId(), 'admin')->getBoardSeq() === $next->getBoardSeq(), 'Restore changed an assigned number');
	$pass('purge cannot reuse a reference and restore retains its number');

	$document = ['kanso' => 3, 'exportedAt' => time(), 'board' => [
		'title' => 'Reference bulk import ' . bin2hex(random_bytes(5)),
		'stacks' => [['id' => 1, 'title' => 'Work', 'sortKey' => 'U']],
		'cards' => [
			['id' => 1, 'stackId' => 1, 'title' => 'One', 'sortKey' => 'A'],
			['id' => 2, 'stackId' => 1, 'title' => 'Two', 'sortKey' => 'M'],
			['id' => 3, 'stackId' => 1, 'title' => 'Three', 'sortKey' => 'U'],
		],
	]];
	$importer = $container->get(ImportService::class);
	$imported = $importer->import(json_encode($document, JSON_THROW_ON_ERROR), 'admin');
	$ownedBoards[$imported['boardId']] = true;
	$rebuilt = $boards->find($imported['boardId'], 'admin');
	$duplicate = $importer->duplicate($rebuilt, 'admin', true);
	$ownedBoards[$duplicate['boardId']] = true;
	foreach ([$imported, $duplicate] as $result) {
		$importedCards = array_map(static fn (int $id): Card => $mapper->find($id), $mapper->findAllIdsByBoard($result['boardId']));
		$sequences = array_map(static fn (Card $card): int => $card->getBoardSeq(), $importedCards);
		sort($sequences);
		check($sequences === [1, 2, 3], 'Import or duplication did not reserve a unique sequence range');
		$afterImport = $cards->create($importedCards[0]->getStackId(), 'After import', 'admin');
		check($afterImport->getBoardSeq() === 4, 'Create did not continue past the imported sequence range');
		$cards->delete($afterImport->getId(), 'admin');
		$trash->purge($afterImport->getId(), 'admin');
		check($cards->create($importedCards[0]->getStackId(), 'After imported purge', 'admin')->getBoardSeq() === 5,
			'Imported board reused a purged number');
	}
	$pass('bulk import and duplication reserve complete ranges for subsequent creates');

	$trello = $container->get(TrelloImportService::class)->import(json_encode([
		'name' => 'Reference legacy import ' . bin2hex(random_bytes(5)),
		'lists' => [['id' => 'work', 'name' => 'Work', 'pos' => 1]],
		'cards' => [['id' => 'legacy', 'idList' => 'work', 'name' => 'Legacy imported card', 'pos' => 1]],
	], JSON_THROW_ON_ERROR), 'admin');
	$ownedBoards[$trello['boardId']] = true;
	$trelloIds = $mapper->findAllIdsByBoard($trello['boardId']);
	check(count($trelloIds) === 1 && $mapper->find($trelloIds[0])->getBoardSeq() === null, 'Legacy import changed its existing unnumbered-card behavior');
	check(!$db->inTransaction(), 'Legacy import leaked its outer transaction');
	$afterTrello = $cards->create($mapper->find($trelloIds[0])->getStackId(), 'After legacy import', 'admin');
	check($afterTrello->getBoardSeq() === 1, 'Legacy import did not initialize its board allocator');
	$pass('legacy import retains its outer transaction and initializes allocation for new cards');

	$csv = $container->get(CsvImportService::class)->import("Title\nCSV one\nCSV two\nCSV three\n", $board->getId(), $stack->getId(), ['title' => 0], true, 'admin');
	check($csv['cards'] === 3, 'CSV import did not create all rows');
	$csvCards = array_map(static fn (int $id): Card => $mapper->find($id), $mapper->findAllIdsByBoard($board->getId()));
	$numbers = array_map(static fn (Card $card): int => $card->getBoardSeq(), $csvCards);
	check(count($numbers) === count(array_unique($numbers)), 'CSV allocation duplicated a number');
	check($cards->create($stack->getId(), 'After CSV', 'admin')->getBoardSeq() > max($numbers), 'Create reused a CSV number');
	$pass('CSV import and ordinary create share monotonic allocation');

	foreach ([false, true] as $sameStack) {
		[$raceBoard, $raceStack] = $fixture('RACE');
		$otherStack = $sameStack ? $raceStack : $stacks->create($raceBoard->getId(), 'Other', 'admin');
		$holder = startWorker($raceStack->getId(), 'Held create', true);
		$contender = null;
		try {
			$held = receive($holder['pipes'][1]);
			check(($held['event'] ?? '') === 'held', 'Create did not reach its production transaction barrier: ' . json_encode($held));
			$contender = startWorker($otherStack->getId(), 'Contending create', false);
			try {
				$early = allocationWait($db, $contender);
			} finally {
				release($holder);
			}
			$holderResult = finish($holder);
			$holder = null;
			$contenderResult = finish($contender, $early);
			$contender = null;
			check($holderResult['ok'], 'Held create failed: ' . json_encode($holderResult));
			if (!$contenderResult['ok']) {
				check($provider === IDBConnection::PLATFORM_SQLITE || ($sameStack && $contenderResult['class'] === \OverflowException::class),
					'Contending create failed unexpectedly: ' . json_encode($contenderResult));
				$retry = $cards->create($otherStack->getId(), 'Contending create', 'admin');
				$contenderResult = ['cardId' => $retry->getId(), 'sequence' => $retry->getBoardSeq()];
			}
			check($contenderResult['cardId'] !== $holderResult['cardId'] && $contenderResult['sequence'] > $holderResult['sequence'],
				'Concurrent create did not preserve distinct increasing references');
			check(count($mapper->findAllIdsByBoard($raceBoard->getId())) === 2, 'A rejected create leaked a card or lost a successful create');
			$pass('independent creates contend on allocation and remain unique (' . ($sameStack ? 'same stack' : 'different stacks') . ')');
		} finally {
			foreach ([$holder, $contender] as $worker) {
				if ($worker !== null) {
					proc_terminate($worker['process']);
					foreach ($worker['pipes'] as $pipe) {
						fclose($pipe);
					}
					proc_close($worker['process']);
				}
			}
		}
	}

	[$source, $sourceStack] = $fixture('SAME');
	[$destination, $destinationStack] = $fixture('DEST');
	[$final, $finalStack] = $fixture('FINAL');
	[$duplicatePrefix, $duplicateStack] = $fixture('SAME');
	$original = $cards->create($sourceStack->getId(), 'Moved reference', 'admin');
	$other = $cards->create($duplicateStack->getId(), 'Other board reference', 'admin');
	$cards->create($destinationStack->getId(), 'Occupied destination number', 'admin');
	$oldRef = reference($source, $original);
	$moved = $move($original, $destinationStack->getId());
	check($moved->getBoardSeq() === 2, 'Move failed to allocate a fresh destination number');
	check($cards->findByRef($source->getId(), $oldRef, 'admin')?->getId() === $moved->getId(), 'Old human reference did not follow the move');
	check($cards->findByRef($duplicatePrefix->getId(), $oldRef, 'admin')?->getId() === $other->getId(), 'Duplicate prefix escaped its source board context');
	$trash->purge($original->getId(), 'admin');
	check($cards->findByRef($source->getId(), $oldRef, 'admin')?->getId() === $moved->getId(), 'Source card purge destroyed its reference alias');
	$intermediateRef = reference($destination, $moved);
	$latest = $move($moved, $finalStack->getId());
	$trash->purge($moved->getId(), 'admin');
	check($cards->findByRef($source->getId(), $oldRef, 'admin')?->getId() === $latest->getId(), 'Original reference did not follow a purged move chain');
	check($cards->findByRef($destination->getId(), $intermediateRef, 'admin')?->getId() === $latest->getId(), 'Intermediate reference did not follow the chain');
	check($aliases->resolve($original->getId(), 'admin')->getId() === $latest->getId(), 'Numeric navigation alias regressed');
	$pass('moves, chains and source purge preserve references with duplicate board prefixes');

	$source = $boards->update($source->getId(), null, null, null, 'admin', prefix: 'RENAMED');
	check($cards->findByRef($source->getId(), $oldRef, 'admin')?->getId() === $latest->getId(), 'Prefix rename destroyed the move-time human reference');
	check($cards->findByRef($source->getId(), reference($source, $original), 'admin') === null, 'Current prefix invented an unrecorded alias');
	$restored = $cards->create($sourceStack->getId(), 'Restore original', 'admin');
	$restoreRef = reference($source, $restored);
	$copy = $move($restored, $destinationStack->getId());
	$source = $boards->update($source->getId(), null, null, null, 'admin', prefix: 'CURRENT');
	check($cards->findByRef($source->getId(), $restoreRef, 'admin')?->getId() === $copy->getId(), 'Move-time prefix stopped resolving');
	$trash->restore($restored->getId(), 'admin');
	check($cards->findByRef($source->getId(), $restoreRef, 'admin') === null, 'Restoring the source retained the canceled old-prefix alias');
	check($cards->findByRef($source->getId(), reference($source, $restored), 'admin')?->getId() === $restored->getId(), 'Restored source did not resolve under its current prefix');
	check($aliases->resolve($restored->getId(), 'admin')->getId() === $restored->getId(), 'Restored original lost numeric precedence');
	$cards->delete($restored->getId(), 'admin');
	$trash->purge($restored->getId(), 'admin');
	check($cards->findByRef($source->getId(), $restoreRef, 'admin') === null, 'Purging a restored source revived its canceled alias');
	$pass('move-time prefixes survive renames and restoring the original cancels its alias');

	$peerUid = 'reference-' . bin2hex(random_bytes(5));
	$peer = $container->get(IUserManager::class)->createUser($peerUid, bin2hex(random_bytes(20)));
	check($peer !== false, 'Cannot create disposable reference reader');
	$acl = $container->get(AclService::class);
	$sourceGrant = $acl->create($source->getId(), $peerUid, 'user', PermissionService::PERMISSION_READ, 'admin');
	check($cards->findByRef($source->getId(), $oldRef, $peerUid) === null, 'Source READ leaked a destination without READ');
	expectFailure(static fn () => $aliases->resolve($original->getId(), $peerUid), DoesNotExistException::class);
	$destinationGrant = $acl->create($final->getId(), $peerUid, 'user', PermissionService::PERMISSION_READ, 'admin', ViewerContext::ROLE_EXTERNAL);
	check($cards->findByRef($source->getId(), $oldRef, $peerUid)?->getId() === $latest->getId(), 'Readable destination did not resolve');
	$acl->delete($source->getId(), $sourceGrant->getId(), 'admin');
	expectFailure(static fn () => $cards->findByRef($source->getId(), $oldRef, $peerUid), NotPermittedException::class);
	check($aliases->resolve($original->getId(), $peerUid)->getId() === $latest->getId(), 'Numeric navigation incorrectly required source-board access');
	$acl->create($source->getId(), $peerUid, 'user', PermissionService::PERMISSION_READ, 'admin');
	$cards->update($latest->getId(), null, null, null, null, null, 'admin', visibility: CardVisibilityScope::VISIBILITY_PRIVATE);
	check($cards->findByRef($source->getId(), $oldRef, $peerUid) === null, 'Private destination title or id leaked');
	$container->get(IUserSession::class)->setUser($peer);
	try {
		$response = $container->get(CardController::class)->resolveRef($source->getId(), $oldRef);
		check($response->getStatus() === 404 && $response->getData() === ['error' => 'Not found'], 'Controller exposed a hidden alias target');
	} finally {
		$container->get(IUserSession::class)->setUser($admin);
	}
	$cards->update($latest->getId(), null, null, null, null, null, 'admin', visibility: CardVisibilityScope::VISIBILITY_INTERNAL);
	check($cards->findByRef($source->getId(), $oldRef, $peerUid) === null, 'Internal destination leaked to an external reader');
	$acl->update($final->getId(), $destinationGrant->getId(), PermissionService::PERMISSION_READ, 'admin', ViewerContext::ROLE_INTERNAL);
	check($cards->findByRef($source->getId(), $oldRef, $peerUid)?->getId() === $latest->getId(), 'Internal reader could not resolve its side');
	$cards->update($latest->getId(), null, null, null, null, null, 'admin', visibility: CardVisibilityScope::VISIBILITY_PUBLIC);
	$pass('source READ, destination READ and destination private/internal visibility are enforced');

	[$legacyBoard, $legacyStack] = $fixture('LEGACY');
	$acl->create($legacyBoard->getId(), $peerUid, 'user', PermissionService::PERMISSION_READ, 'admin');
	$legacyOriginal = $cards->create($legacyStack->getId(), 'Legacy moved reference', 'admin');
	$legacyRef = reference($legacyBoard, $legacyOriginal);
	$legacyTarget = $move($legacyOriginal, $finalStack->getId());
	$trash->purge($legacyOriginal->getId(), 'admin');
	$legacyLive = $cards->create($legacyStack->getId(), 'Legacy reused number', 'admin');
	$legacyLive->setBoardSeq($legacyOriginal->getBoardSeq());
	$legacyLive = $mapper->update($legacyLive);
	check($cards->findByRef($legacyBoard->getId(), $legacyRef, 'admin')?->getId() === $legacyLive->getId(), 'Alias stole an existing live reference');
	check($cards->findByRef($legacyBoard->getId(), $legacyRef, $peerUid)?->getId() === $legacyLive->getId(), 'Visible legacy reference redirected to another card');
	$cards->update($legacyLive->getId(), null, null, null, null, null, 'admin', visibility: CardVisibilityScope::VISIBILITY_PRIVATE);
	check($cards->findByRef($legacyBoard->getId(), $legacyRef, $peerUid) === null, 'Hidden live collision exposed an alias target');
	$legacyBoard = $boards->update($legacyBoard->getId(), null, null, null, 'admin', prefix: 'CHANGED');
	check($cards->findByRef($legacyBoard->getId(), $legacyRef, 'admin') === null, 'Old prefix redirected a colliding live number');
	check($cards->findByRef($legacyBoard->getId(), $legacyRef, $peerUid) === null, 'Renamed hidden collision exposed an alias target');
	check($aliases->resolve($legacyOriginal->getId(), $peerUid)->getId() === $legacyTarget->getId(), 'Human collision changed independent numeric navigation');
	$pass('legacy reused numbers retain live precedence and hidden collisions fail closed');

	[$backfillBoard, $backfillStack] = $fixture('BACK');
	$backfillSource = $cards->create($backfillStack->getId(), 'Backfill source', 'admin');
	$backfillTarget = $move($backfillSource, $finalStack->getId());
	$unrecoverableStack = $stacks->create($backfillBoard->getId(), 'Purged source', 'admin');
	$unrecoverableSource = $cards->create($unrecoverableStack->getId(), 'Purged backfill source', 'admin');
	$unrecoverableTarget = $move($unrecoverableSource, $finalStack->getId());
	$trash->purge($unrecoverableSource->getId(), 'admin');
	$highStack = $stacks->create($backfillBoard->getId(), 'High-water mark', 'admin');
	$backfillHighest = $cards->create($highStack->getId(), 'Trashed backfill high-water mark', 'admin');
	$cards->delete($backfillHighest->getId(), 'admin');
	$lostPrefixRef = reference($backfillBoard, $backfillSource);
	$backfillBoard = $boards->update($backfillBoard->getId(), null, null, null, 'admin', prefix: 'BACKNOW');
	$db->executeStatement('UPDATE *PREFIX*kanso_card_move_aliases SET board_id = NULL, prefix = NULL, board_seq = NULL WHERE old_card_id IN (?, ?)',
		[$backfillSource->getId(), $unrecoverableSource->getId()]);
	$db->executeStatement('DELETE FROM *PREFIX*kanso_card_sequences WHERE board_id = ?', [$backfillBoard->getId()]);
	$migration = new Version007600Date20261112000000($db);
	$migration->postSchemaChange(new \OC\Migration\NullOutput(), static fn () => null, []);
	$backfilled = $container->get(CardMoveAliasMapper::class)->findByOldId($backfillSource->getId());
	check($backfilled?->getBoardId() === $backfillBoard->getId() && $backfilled->getPrefix() === $backfillBoard->getPrefix()
		&& $backfilled->getBoardSeq() === $backfillSource->getBoardSeq(), 'Migration did not reconstruct surviving source metadata');
	check($cards->findByRef($backfillBoard->getId(), reference($backfillBoard, $backfillSource), 'admin')?->getId() === $backfillTarget->getId(),
		'Backfilled move reference did not resolve');
	check($cards->findByRef($backfillBoard->getId(), $lostPrefixRef, 'admin') === null, 'Migration invented a lost historical prefix');
	check($container->get(CardMoveAliasMapper::class)->findByOldId($unrecoverableSource->getId())?->getBoardId() === null,
		'Migration invented unavailable purged-source metadata');
	check($aliases->resolve($unrecoverableSource->getId(), 'admin')->getId() === $unrecoverableTarget->getId(), 'Backfill changed a numeric-only legacy alias');
	$afterBackfill = $cards->create($unrecoverableStack->getId(), 'After backfill', 'admin');
	check($afterBackfill->getBoardSeq() > $backfillHighest->getBoardSeq(), 'Counter backfill missed a surviving trashed high-water mark');
	$cards->delete($afterBackfill->getId(), 'admin');
	$trash->purge($afterBackfill->getId(), 'admin');
	$migration->postSchemaChange(new \OC\Migration\NullOutput(), static fn () => null, []);
	check($cards->create($backfillStack->getId(), 'After idempotent backfill', 'admin')->getBoardSeq() > $afterBackfill->getBoardSeq(),
		'Rerunning backfill reset a durable high-water mark');
	$pass('migration backfills surviving source metadata and counters without resetting history or inventing purged context');

	$cards->delete($latest->getId(), 'admin');
	check($cards->findByRef($source->getId(), $oldRef, 'admin') === null, 'Deleted destination still resolved');
	$trash->restore($latest->getId(), 'admin');
	check($cards->findByRef($source->getId(), $oldRef, 'admin')?->getId() === $latest->getId(), 'Restored destination did not resolve again');
	$cards->delete($latest->getId(), 'admin');
	$trash->purge($latest->getId(), 'admin');
	$replacement = $cards->create($finalStack->getId(), 'Replacement', 'admin');
	check($replacement->getBoardSeq() > $latest->getBoardSeq(), 'Destination purge reused the target number');
	check($cards->findByRef($source->getId(), $oldRef, 'admin') === null, 'Purged destination alias resolved to a replacement');
	$pass('destination trash, restore and purge never redirect to a different later card');

	$boardPurgeSource = $cards->create($sourceStack->getId(), 'Survive source board purge', 'admin');
	$purgeRef = reference($source, $boardPurgeSource);
	$survivor = $move($boardPurgeSource, $destinationStack->getId());
	$boards->delete($source->getId(), 'admin');
	expectFailure(static fn () => $cards->findByRef($source->getId(), $purgeRef, 'admin'), DoesNotExistException::class);
	check($purger->purge($source->getId()), 'Source board purge failed');
	unset($ownedBoards[$source->getId()]);
	check($aliases->resolve($boardPurgeSource->getId(), 'admin')->getId() === $survivor->getId(), 'Source board purge destroyed numeric navigation');
	expectFailure(static fn () => $cards->findByRef($source->getId(), $purgeRef, 'admin'), DoesNotExistException::class);
	check($cards->findByRef($duplicatePrefix->getId(), $oldRef, 'admin')?->getId() === $other->getId(), 'Source board purge changed another board with the same prefix');
	$boards->delete($destination->getId(), 'admin');
	expectFailure(static fn () => $aliases->resolve($boardPurgeSource->getId(), 'admin'), DoesNotExistException::class);
	check($purger->purge($destination->getId()), 'Destination board purge failed');
	unset($ownedBoards[$destination->getId()]);
	expectFailure(static fn () => $aliases->resolve($boardPurgeSource->getId(), 'admin'), DoesNotExistException::class);
	$boards->delete($final->getId(), 'admin');
	check($cards->findByRef($backfillBoard->getId(), reference($backfillBoard, $backfillSource), 'admin') === null,
		'Deleted destination board still resolved through its source context');
	check($purger->purge($final->getId()), 'Final destination board purge failed');
	unset($ownedBoards[$final->getId()]);
	check($cards->findByRef($backfillBoard->getId(), reference($backfillBoard, $backfillSource), 'admin') === null,
		'Purged destination board still resolved through its source context');
	$pass('deleted/purged source contexts stay unavailable while numeric aliases retain destination checks');
	check(!$db->inTransaction(), 'Reference operations leaked a transaction');
} finally {
	$container->get(IUserSession::class)->setUser($admin);
	foreach (array_keys($ownedBoards) as $boardId) {
		$boards->delete($boardId, 'admin');
		check($purger->purge($boardId), 'Could not clean owned board ' . $boardId);
	}
	foreach ($ownedAliases as $cardId) {
		$container->get(CardMoveAliasMapper::class)->deleteByOldId($cardId);
	}
	if ($peer !== null && $peer !== false) {
		$peer->delete();
	}
}

echo 'OK ' . $provider . ': ' . $passed . " reference integration checks; owned boards, user and aliases removed\n";
