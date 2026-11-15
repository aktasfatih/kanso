<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Integration\SqliteContention;

use Doctrine\DBAL\Logging\SQLLogger;
use OCA\Kanso\AppInfo\Application;
use OCA\Kanso\Controller\CardController;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Service\AclService;
use OCA\Kanso\Service\AssigneeService;
use OCA\Kanso\Service\BoardPurgeService;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\CardMutationService;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\CardVisibilityScope;
use OCA\Kanso\Service\MutationConflictException;
use OCA\Kanso\Service\StackService;
use OCP\DB\Exception as DbException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\NullLogger;

if (PHP_SAPI !== 'cli' || getenv('KANSO_INTEGRATION_DISPOSABLE') !== '1') {
	throw new \RuntimeException('An explicitly authorized disposable CLI installation is required');
}
$root = getenv('NEXTCLOUD_ROOT');
if ($root === false || !is_file($root . '/lib/base.php')) {
	throw new \RuntimeException('NEXTCLOUD_ROOT must point to an installed Nextcloud');
}
define('OC_CONSOLE', true);
require_once $root . '/lib/base.php';
set_exception_handler(static function (\Throwable $error): void {
	fwrite(STDERR, (string)$error . "\n");
	exit(1);
});
\OC::$composerAutoloader->addPsr4('OCA\\Kanso\\', dirname(__DIR__, 2) . '/lib/', true);
\OC_App::loadApps();
$container = (new Application())->getContainer();
$db = $container->get(IDBConnection::class);
$config = $container->get(IConfig::class);
check($db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE, 'This harness requires SQLite');
check(getenv('KANSO_SQLITE_EXPECTED_DATA_DIR') === $config->getSystemValueString('datadirectory'), 'Disposable data-directory mismatch');
$users = $container->get(IUserManager::class);
$admin = $users->get('admin');
check($admin !== null, 'The disposable installation requires admin');
$container->get(IUserSession::class)->setUser($admin);
\OC_Util::setupFS('admin');

function check(bool $condition, string $message): void {
	if (!$condition) {
		throw new \RuntimeException($message);
	}
}

function emit(array $value): void {
	fwrite(STDOUT, json_encode($value, JSON_THROW_ON_ERROR) . "\n");
	fflush(STDOUT);
}

function receive($pipe): array {
	$read = [$pipe];
	$write = $except = [];
	check(stream_select($read, $write, $except, 15) === 1, 'Worker barrier timed out');
	$line = fgets($pipe);
	check($line !== false, 'Worker exited before its barrier');
	return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
}

function sources(): array {
	$hashes = [];
	foreach ([CardMutationService::class, CardMapper::class, AssigneeService::class, CardController::class] as $class) {
		$path = (new \ReflectionClass($class))->getFileName();
		$expected = dirname(__DIR__, 2) . '/lib/' . str_replace('\\', '/', substr($class, strlen('OCA\\Kanso\\'))) . '.php';
		check(realpath($path) === realpath($expected), 'Service loaded outside this checkout: ' . $class);
		$hashes[$class] = hash_file('sha256', $path);
	}
	return $hashes;
}

class SnapshotCards extends CardMapper {
	private bool $held = false;

	public function findForMutation(int $id): Card {
		$card = parent::findForMutation($id);
		if (!$this->held) {
			$this->held = true;
			emit(['event' => 'snapshot']);
			check((receive(STDIN)['event'] ?? '') === 'release', 'Snapshot requires release');
		}
		return $card;
	}
}

class WriteTrace implements SQLLogger {
	private bool $emitted = false;

	public function startQuery($sql, ?array $params = null, ?array $types = null): void {
		if (!$this->emitted && preg_match('/^(UPDATE|INSERT|DELETE)\b/i', $sql) === 1
			&& (str_contains($sql, 'kanso_boards') || str_contains($sql, 'kanso_card_assignees'))) {
			$this->emitted = true;
			emit(['event' => 'write']);
		}
	}

	public function stopQuery(): void {
	}
}

if (($argv[1] ?? '') === 'worker') {
	$spec = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
	if ($spec['snapshot'] ?? false) {
		$mapper = new SnapshotCards($db, $container->get(CardVisibilityScope::class));
		$container->registerService(CardMapper::class, static fn () => $mapper);
		$scope = new CardMutationService($db, $mapper, new NullLogger());
		$container->registerService(CardMutationService::class, static fn () => $scope);
	}
	$db->executeStatement('PRAGMA busy_timeout = ' . (int)($spec['timeout'] ?? 150));
	$db->getInner()->getConfiguration()->setSQLLogger(new WriteTrace());
	emit(['event' => 'ready', 'sources' => sources(), 'process' => getmypid()]);
	check((receive(STDIN)['event'] ?? '') === 'start', 'Expected start');
	try {
		if ($spec['operation'] === 'assign') {
			if ($spec['controller'] ?? false) {
				$response = $container->get(CardController::class)->assignUser($spec['card'], $spec['person']);
				$result = ['status' => $response->getStatus(), 'body' => $response->getData()];
			} else {
				$container->get(AssigneeService::class)->assign($spec['card'], $spec['person'], 'admin');
				$result = [];
			}
		} else {
			if ($spec['operation'] === 'hold') {
				$db->beginTransaction();
			}
			$statement = $spec['operation'] === 'hold'
				? 'UPDATE *PREFIX*kanso_boards SET last_modified = last_modified WHERE id = ?'
				: "UPDATE *PREFIX*kanso_boards SET title = title || ' writer' WHERE id = ?";
			$db->executeStatement($statement, [$spec['board']]);
			if ($spec['operation'] === 'hold') {
				emit(['event' => 'held']);
				check((receive(STDIN)['event'] ?? '') === 'release', 'Writer requires release');
				$db->commit();
			}
			$result = [];
		}
		emit(['event' => 'result', 'ok' => true] + $result);
	} catch (\Throwable $error) {
		emit(['event' => 'result', 'ok' => false, 'class' => $error::class, 'message' => $error->getMessage(),
			'reason' => $error instanceof DbException ? $error->getReason() : null]);
	} finally {
		if ($db->inTransaction()) {
			$db->rollBack();
		}
	}
	exit;
}

function worker(array $spec): array {
	$process = proc_open([PHP_BINARY, __FILE__, 'worker', json_encode($spec, JSON_THROW_ON_ERROR)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	check(is_resource($process), 'Cannot launch independent worker');
	try {
		$ready = receive($pipes[1]);
		check(($ready['event'] ?? '') === 'ready' && $ready['sources'] === sources(), 'Worker source mismatch');
	} catch (\Throwable $error) {
		fclose($pipes[0]);
		proc_terminate($process);
		fclose($pipes[1]);
		$details = stream_get_contents($pipes[2]);
		fclose($pipes[2]);
		proc_close($process);
		throw new \RuntimeException('Worker initialization failed: ' . $details, 0, $error);
	}
	return ['process' => $process, 'pipes' => $pipes, 'pid' => $ready['process']];
}

function signal(array $worker, string $event): void {
	fwrite($worker['pipes'][0], json_encode(['event' => $event], JSON_THROW_ON_ERROR) . "\n");
	fflush($worker['pipes'][0]);
}

function event(array $worker, string $expected): array {
	do {
		$value = receive($worker['pipes'][1]);
		check(($value['event'] ?? '') !== 'result' || $expected === 'result', 'Worker finished before ' . $expected . ': ' . json_encode($value));
	} while (($value['event'] ?? '') !== $expected);
	return $value;
}

function finish(array $worker): array {
	$result = event($worker, 'result');
	fclose($worker['pipes'][0]);
	fclose($worker['pipes'][1]);
	$error = stream_get_contents($worker['pipes'][2]);
	fclose($worker['pipes'][2]);
	check(proc_close($worker['process']) === 0, 'Worker failed: ' . $error);
	return $result;
}

function state(IDBConnection $db, int $board, int $card): array {
	return [
		'assignees' => $db->executeQuery('SELECT * FROM *PREFIX*kanso_card_assignees WHERE card_id = ? ORDER BY id', [$card])->fetchAll(),
		'changes' => $db->executeQuery('SELECT * FROM *PREFIX*kanso_changes WHERE board_id = ? ORDER BY id', [$board])->fetchAll(),
		'subscriptions' => $db->executeQuery('SELECT * FROM *PREFIX*kanso_subscriptions WHERE card_id = ? ORDER BY id', [$card])->fetchAll(),
	];
}

$boards = $container->get(BoardService::class);
$assignments = $container->get(CardAssigneeMapper::class);
$board = null;
$person = null;
$workers = [];
$results = [];
try {
	$candidate = 'sqlite_contention_' . bin2hex(random_bytes(5));
	check($users->get($candidate) === null, 'Temporary user already exists');
	$created = $users->createUser($candidate, bin2hex(random_bytes(20)));
	check($created !== false && $created !== null, 'Temporary user was not created');
	$person = $created->getUID();
	$board = $boards->create('SQLite contention ' . bin2hex(random_bytes(5)), null, 'admin')->getId();
	$container->get(AclService::class)->create($board, $person, 'user', 1, 'admin');
	$stack = $container->get(StackService::class)->create($board, 'To do', 'admin')->getId();
	$cards = [];
	for ($i = 0; $i < 4; $i++) {
		$cards[] = $container->get(CardService::class)->create($stack, 'Contention ' . $i, 'admin')->getId();
	}
	emit(['sources' => sources(), 'journal' => $db->executeQuery('PRAGMA journal_mode')->fetchOne(), 'ownedBoard' => $board]);

	$a = $workers[] = worker(['operation' => 'assign', 'card' => $cards[0], 'person' => $person, 'snapshot' => true]);
	$b = $workers[] = worker(['operation' => 'write', 'board' => $board]);
	signal($a, 'start');
	event($a, 'snapshot');
	signal($b, 'start');
	$competing = finish($b);
	array_pop($workers);
	signal($a, 'release');
	$assignment = finish($a);
	array_pop($workers);
	emit(['scenario' => 'snapshot', 'competingWriter' => $competing, 'assignment' => $assignment]);
	check(!$competing['ok'] && $competing['reason'] === DbException::REASON_LOCK_WAIT_TIMEOUT && $assignment['ok'], 'Writer reservation must precede the assignment snapshot');
	check($assignments->findUserIdsByCard($cards[0]) === [$person], 'Assignment must commit once');
	$results[] = 'writer reserved before snapshot';

	$a = $workers[] = worker(['operation' => 'assign', 'card' => $cards[1], 'person' => $person, 'timeout' => 2000]);
	$b = $workers[] = worker(['operation' => 'hold', 'board' => $board]);
	signal($b, 'start');
	event($b, 'held');
	signal($a, 'start');
	event($a, 'write');
	$read = [$a['pipes'][1]];
	$write = $except = [];
	check(stream_select($read, $write, $except, 0, 150000) === 0, 'Assignment must wait for the held writer');
	signal($b, 'release');
	check(finish($b)['ok'], 'Held writer failed');
	array_pop($workers);
	check(finish($a)['ok'], 'Assignment must complete after the writer releases');
	array_pop($workers);
	check($assignments->findUserIdsByCard($cards[1]) === [$person], 'Waiting assignment was lost');
	$results[] = 'transient contention completes';

	$before = state($db, $board, $cards[2]);
	$a = $workers[] = worker(['operation' => 'assign', 'card' => $cards[2], 'person' => $person, 'controller' => true]);
	$b = $workers[] = worker(['operation' => 'hold', 'board' => $board]);
	signal($b, 'start');
	event($b, 'held');
	signal($a, 'start');
	$response = finish($a);
	array_splice($workers, -2, 1);
	signal($b, 'release');
	check(finish($b)['ok'], 'Held writer failed');
	array_pop($workers);
	check($response['ok'] && $response['status'] === 409 && str_contains($response['body']['error'], 'try again'), 'Exhausted contention must return an actionable conflict: ' . json_encode($response));
	check(state($db, $board, $cards[2]) === $before, 'Rejected assignment changed data or side effects');
	$results[] = 'exhaustion returns409 without side effects';

	$container->get(AssigneeService::class)->assign($cards[3], 'admin', 'admin');
	$boards->update($board, null, null, null, 'admin', singleAssignee: true);
	$before = state($db, $board, $cards[3]);
	$scope = $container->get(CardMutationService::class);
	$notified = false;
	try {
		$scope->run($cards[3], function () use ($container, $cards, $person, $scope, &$notified): void {
			$container->get(AssigneeService::class)->assign($cards[3], $person, 'admin');
			$scope->afterCommit(static function () use (&$notified): void {
				$notified = true;
			});
			throw new class('synthetic lock rejection') extends DbException {
				public function getReason(): ?int {
					return self::REASON_LOCK_WAIT_TIMEOUT;
				}
			};
		});
		throw new \RuntimeException('Expected replacement rollback');
	} catch (MutationConflictException) {
	}
	check(!$notified && state($db, $board, $cards[3]) === $before, 'Replacement rollback lost the original or emitted side effects');
	$results[] = 'replacement rollback preserves original and callbacks';
	emit(['ok' => true, 'checks' => $results]);
} finally {
	foreach ($workers as $worker) {
		if (is_resource($worker['process'])) {
			proc_terminate($worker['process']);
		}
		foreach ($worker['pipes'] as $pipe) {
			if (is_resource($pipe)) {
				fclose($pipe);
			}
		}
		if (is_resource($worker['process'])) {
			proc_close($worker['process']);
		}
	}
	if ($board !== null) {
		$boards->delete($board, 'admin');
		check($container->get(BoardPurgeService::class)->purge($board), 'Owned board was not purged');
	}
	if ($person !== null) {
		$ownedUser = $users->get($person);
		check($ownedUser === null || $ownedUser->delete(), 'Owned user was not deleted');
		check($users->get($person) === null, 'Owned user remains after cleanup');
	}
	emit(['cleanedBoard' => $board, 'cleanedPerson' => $person]);
}
