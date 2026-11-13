<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Integration\SingleAssignee;

use OCA\Kanso\AppInfo\Application;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Service\AclService;
use OCA\Kanso\Service\AssigneeService;
use OCA\Kanso\Service\BoardPurgeService;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\CardMutationService;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\CsvImportService;
use OCA\Kanso\Service\RecurrenceService;
use OCA\Kanso\Service\StackService;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\NullLogger;

if (PHP_SAPI !== 'cli' || getenv('KANSO_INTEGRATION_DISPOSABLE') !== '1') {
	throw new \RuntimeException('A disposable CLI installation is required');
}
$nextcloudRoot = getenv('NEXTCLOUD_ROOT');
if ($nextcloudRoot === false || !is_file($nextcloudRoot . '/lib/base.php')) {
	throw new \RuntimeException('NEXTCLOUD_ROOT must point to a disposable installation');
}
define('OC_CONSOLE', true);
require_once $nextcloudRoot . '/lib/base.php';
set_exception_handler(static function (\Throwable $error): void {
	fwrite(STDERR, (string)$error . "\n");
	exit(1);
});
\OC::$composerAutoloader->addPsr4('OCA\\Kanso\\', dirname(__DIR__, 2) . '/lib/', true);
\OC_App::loadApps();
$expectedData = getenv('KANSO_SINGLE_EXPECTED_DATA_DIR');
$config = \OCP\Server::get(\OCP\IConfig::class);
if ($expectedData === false || $expectedData === '' || $config->getSystemValue('datadirectory') !== $expectedData) {
	throw new \RuntimeException('The disposable data directory must match KANSO_SINGLE_EXPECTED_DATA_DIR');
}
$container = (new Application())->getContainer();
$db = $container->get(IDBConnection::class);
$userManager = $container->get(IUserManager::class);
$admin = $userManager->get('admin');
if ($admin === null) {
	throw new \RuntimeException('The disposable installation requires admin');
}
$container->get(IUserSession::class)->setUser($admin);
\OC_Util::setupFS('admin');
$mode = $argv[1] ?? '';
$spec = json_decode($argv[2] ?? '{}', true, 512, JSON_THROW_ON_ERROR);

function emit(array $value): void {
	echo json_encode($value, JSON_THROW_ON_ERROR) . "\n";
	flush();
}

class BarrierMutation extends CardMutationService {
	private int $nesting = 0;

	public function __construct(
		IDBConnection $db,
		CardMapper $cards,
		private bool $hold,
	) {
		parent::__construct($db, $cards, new NullLogger());
	}

	public function runForBoards(array $boardIds, callable $write, bool $exclusive = false): mixed {
		$outermost = $this->nesting++ === 0;
		try {
			return parent::runForBoards($boardIds, function () use ($write, $outermost): mixed {
				$result = $write();
				if ($outermost && $this->hold) {
					emit(['event' => 'held']);
					if (trim(fgets(STDIN)) !== 'release') {
						throw new \RuntimeException('Expected release');
					}
				}
				return $result;
			}, $exclusive);
		} finally {
			$this->nesting--;
		}
	}
}

if ($mode === 'act') {
	$container->registerService(CardMutationService::class, static fn () => new BarrierMutation($db, $container->get(CardMapper::class), $spec['hold'] ?? false));
	if (isset($spec['mutant'])) {
		$mutant = $spec['mutant'];
		require $mutant['path'];
		$container->registerService($mutant['service'], static fn () => $container->get($mutant['class']));
	}
}
$boards = $container->get(BoardService::class);
$cards = $container->get(CardService::class);
$assignments = $container->get(CardAssigneeMapper::class);

if ($mode === 'setup') {
	$owned = [];
	$people = [];
	try {
		for ($i = 0; $i < 2; $i++) {
			$uid = 'single_race_' . bin2hex(random_bytes(5));
			$userManager->createUser($uid, bin2hex(random_bytes(18)));
			$people[] = $uid;
		}
		$fixture = ['people' => $people];
		foreach (['source', 'target'] as $name) {
			$board = $boards->create('Single-assignee race ' . $name, null, 'admin');
			$owned[] = $board->getId();
			foreach ($people as $uid) {
				$container->get(AclService::class)->create($board->getId(), $uid, 'user', 1, 'admin');
			}
			$stack = $container->get(StackService::class)->create($board->getId(), 'To do', 'admin');
			$fixture[$name] = $board->getId();
			$fixture[$name . 'Stack'] = $stack->getId();
		}
		$fixture['card'] = $cards->create($fixture['sourceStack'], 'Race card', 'admin')->getId();
		$fixture['other'] = $cards->create($fixture['sourceStack'], 'Independent card', 'admin')->getId();
		foreach (array_slice(['admin', ...$people], 0, $spec['assignees'] ?? 0) as $uid) {
			$container->get(AssigneeService::class)->assign($fixture['card'], $uid, 'admin');
		}
		if ($spec['single'] ?? false) {
			$boards->update($fixture['source'], null, null, null, 'admin', singleAssignee: true);
		}
		if ($spec['recurring'] ?? false) {
			$fixture['rule'] = $container->get(RecurrenceService::class)->create($fixture['source'], $fixture['card'], $fixture['sourceStack'], \OCA\Kanso\Db\RecurRule::MODE_CLONE, 'FREQ=DAILY', \OCA\Kanso\Db\RecurRule::POLICY_AT_OCCURRENCE, 0, false, 'admin')->getId();
		}
		emit($fixture);
	} catch (\Throwable $error) {
		foreach ($owned as $id) {
			$boards->delete($id, 'admin');
			$container->get(BoardPurgeService::class)->purge($id);
		}
		foreach ($people as $uid) {
			$userManager->get($uid)?->delete();
		}
		throw $error;
	}
} elseif ($mode === 'inspect') {
	$source = $container->get(CardMapper::class)->find($spec['card']);
	$rows = $db->executeQuery('SELECT id, board_id, title FROM *PREFIX*kanso_cards WHERE board_id IN (?, ?) AND deleted_at = 0 ORDER BY id', [$spec['source'], $spec['target']])->fetchAll();
	$sets = [];
	foreach ($rows as $row) {
		$sets[(int)$row['id']] = $assignments->findUserIdsByCard((int)$row['id']);
	}
	emit(['sourceDeleted' => $source->getDeletedAt(), 'single' => $container->get(BoardMapper::class)->find($spec['source'])->getSingleAssignee(), 'targetSingle' => $container->get(BoardMapper::class)->find($spec['target'])->getSingleAssignee(), 'rows' => $rows, 'sets' => $sets]);
} elseif ($mode === 'cleanup') {
	foreach (['source', 'target'] as $key) {
		$boards->delete($spec[$key], 'admin');
		$container->get(BoardPurgeService::class)->purge($spec[$key]);
	}
	foreach ($spec['people'] as $uid) {
		$userManager->get($uid)?->delete();
	}
	emit(['cleaned' => true]);
} elseif ($mode === 'wait-lock') {
	$deadline = microtime(true) + 4;
	do {
		if ($db->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES) {
			$waiting = $db->executeQuery("SELECT COUNT(*) FROM pg_stat_activity WHERE pid = ? AND wait_event_type = 'Lock'", [$spec['connection']])->fetchOne();
		} else {
			$status = $db->executeQuery('SHOW ENGINE INNODB STATUS')->fetch()['Status'];
			$waiting = 0;
			foreach (preg_split('/(?=---TRANSACTION)/', $status) as $transaction) {
				if (str_contains($transaction, 'LOCK WAIT') && preg_match('/MariaDB thread id ' . $spec['connection'] . '\\b/', $transaction) === 1) {
					$waiting = 1;
				}
			}
		}
		if ((int)$waiting > 0) {
			emit(['waiting' => true]);
			exit;
		}
		usleep(10000);
	} while (microtime(true) < $deadline);
	throw new \RuntimeException('Contender never reached a database lock');
} elseif ($mode === 'act') {
	if ($db->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES) {
		$db->executeStatement("SET lock_timeout = '8s'");
		$connection = $db->executeQuery('SELECT pg_backend_pid()')->fetchOne();
	} else {
		$db->executeStatement('SET innodb_lock_wait_timeout = 8');
		$connection = $db->executeQuery('SELECT CONNECTION_ID()')->fetchOne();
	}
	$hashes = [];
	foreach ([BoardService::class, AssigneeService::class, CsvImportService::class, CardService::class, RecurrenceService::class] as $service) {
		$path = (new \ReflectionClass($service))->getFileName();
		if (realpath($path) !== realpath(dirname(__DIR__, 2) . '/lib/Service/' . basename($path))) {
			throw new \RuntimeException('Participating service loaded outside this checkout');
		}
		$hashes[$service] = hash_file('sha256', $path);
	}
	emit(['event' => 'ready', 'connection' => (int)$connection, 'sources' => $hashes]);
	if (trim(fgets(STDIN)) !== 'start') {
		throw new \RuntimeException('Expected start');
	}
	try {
		$result = match ($spec['operation']) {
			'assign' => $container->get(AssigneeService::class)->assign($spec['card'], $spec['person'], 'admin'),
			'mode' => $boards->update($spec['board'], null, null, null, 'admin', singleAssignee: true),
			'csv' => $container->get(CsvImportService::class)->import("title,people\nImported,\"admin," . $spec['person'] . '"', $spec['board'], $spec['stack'], ['title' => 0, 'assignees' => 1], true, 'admin'),
			'move' => $cards->moveToBoard($spec['card'], $spec['stack'], 'admin'),
			'clone' => $container->get(RecurrenceService::class)->spawn($container->get(\OCA\Kanso\Db\RecurRuleMapper::class)->find($spec['rule']), true),
			default => throw new \RuntimeException('Unknown operation'),
		};
		emit(['event' => 'result', 'ok' => true, 'id' => is_object($result) && method_exists($result, 'getId') ? $result->getId() : null]);
	} catch (\Throwable $error) {
		emit(['event' => 'result', 'ok' => false, 'class' => $error::class, 'message' => $error->getMessage()]);
	}
} else {
	throw new \RuntimeException('Unknown mode');
}
