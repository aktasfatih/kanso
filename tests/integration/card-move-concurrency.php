<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Integration;

use OCA\Kanso\AppInfo\Application;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardAttachmentMapper;
use OCA\Kanso\Db\CardFieldValueMapper;
use OCA\Kanso\Db\CardFileLinkMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardRelationMapper;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\CardTimeEntryMapper;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\CommentReactionMapper;
use OCA\Kanso\Db\ProjectCardMapper;
use OCA\Kanso\Db\RecurRuleMapper;
use OCA\Kanso\Db\ReminderMapper;
use OCA\Kanso\Db\SubscriptionMapper;
use OCA\Kanso\Service\AclService;
use OCA\Kanso\Service\AssigneeService;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\CardAttachmentService;
use OCA\Kanso\Service\CardFieldService;
use OCA\Kanso\Service\CardFieldValueService;
use OCA\Kanso\Service\CardFileLinkService;
use OCA\Kanso\Service\CardMutationService;
use OCA\Kanso\Service\CardRelationService;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\CardTimeEntryService;
use OCA\Kanso\Service\ChecklistService;
use OCA\Kanso\Service\CommentReactionService;
use OCA\Kanso\Service\CommentService;
use OCA\Kanso\Service\ImportService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\LabelService;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\ProjectService;
use OCA\Kanso\Service\RecurrenceService;
use OCA\Kanso\Service\ReminderService;
use OCA\Kanso\Service\ReviewService;
use OCA\Kanso\Service\StackService;
use OCA\Kanso\Service\SubscriptionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use OCP\Files\IRootFolder;
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
$app = new Application();
$container = $app->getContainer();
$db = $container->get(IDBConnection::class);
$provider = $db->getDatabaseProvider();
$user = $container->get(IUserManager::class)->get('admin');
if ($user === null) {
	throw new \RuntimeException('The disposable installation must have an admin user');
}
$container->get(IUserSession::class)->setUser($user);
\OC_Util::setupFS('admin');

function check(bool $condition, string $message): void {
	if (!$condition) {
		throw new \RuntimeException($message);
	}
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

function wireValue(mixed $value): mixed {
	return is_object($value) && method_exists($value, 'getId') ? ['id' => $value->getId()] : $value;
}

function productionSources(): array {
	$sourceFiles = array_merge(glob(dirname(__DIR__, 2) . '/lib/Service/*.php'), [
		dirname(__DIR__, 2) . '/lib/Db/CardMapper.php', dirname(__DIR__, 2) . '/lib/Db/RecurRuleMapper.php',
	]);
	$sources = [];
	foreach ($sourceFiles as $sourceFilePath) {
		if (str_contains(file_get_contents($sourceFilePath), 'CardMutationService') || str_contains($sourceFilePath, '/lib/Db/')) {
			$class = 'OCA\\Kanso\\' . basename(dirname($sourceFilePath)) . '\\' . basename($sourceFilePath, '.php');
			$loaded = (new \ReflectionClass($class))->getFileName();
			check(realpath($loaded) === realpath($sourceFilePath), 'A participating writer loaded outside this checkout: ' . $class . ' from ' . $loaded);
			$sources[$class] = ['path' => $loaded, 'sha256' => hash_file('sha256', $loaded)];
		}
	}
	return $sources;
}

class BarrierMutationService extends CardMutationService {
	private int $operationDepth = 0;
	private bool $held = false;

	public function __construct(
		IDBConnection $db,
		CardMapper $cards,
		private bool $hold,
	) {
		parent::__construct($db, $cards, new \Psr\Log\NullLogger());
	}

	public function run(int $cardId, callable $write): mixed {
		$outermost = $this->operationDepth++ === 0;
		try {
			return parent::run($cardId, function () use ($write, $outermost): mixed {
				$result = $write();
				$this->barrier($outermost, $result);
				return $result;
			});
		} finally {
			$this->operationDepth--;
		}
	}

	public function runForBoards(array $boardIds, callable $write, bool $exclusive = false): mixed {
		$outermost = $this->operationDepth++ === 0;
		try {
			return parent::runForBoards($boardIds, function () use ($write, $outermost): mixed {
				$result = $write();
				$this->barrier($outermost, $result);
				return $result;
			}, $exclusive);
		} finally {
			$this->operationDepth--;
		}
	}

	private function barrier(bool $outermost, mixed $result): void {
		if (!$this->hold || !$outermost || $this->held) {
			return;
		}
		$this->held = true;
		send(['event' => 'held', 'value' => wireValue($result)]);
		check((receive(STDIN)['event'] ?? '') === 'release', 'Expected the release barrier');
	}
}

function upload(object $container, int $cardId): mixed {
	$file = tempnam(sys_get_temp_dir(), 'kanso-concurrency-');
	check($file !== false, 'Cannot create the upload fixture');
	file_put_contents($file, 'concurrent attachment bytes');
	try {
		return $container->get(CardAttachmentService::class)->upload($cardId, [
			'name' => 'concurrent.txt', 'type' => 'text/plain', 'size' => filesize($file),
			'tmp_name' => $file, 'error' => UPLOAD_ERR_OK,
		], 'admin');
	} finally {
		unlink($file);
	}
}

function operate(object $container, array $spec): mixed {
	return match ($spec['operation']) {
		'move' => $container->get(CardService::class)->moveToBoard($spec['cardId'], $spec['targetStack'], 'admin'),
		'comment_add' => $container->get(CommentService::class)->addComment($spec['cardId'], 'concurrent comment', null, 'admin'),
		'comment_edit' => $container->get(CommentService::class)->editComment($spec['commentId'], 'concurrent edit', 'admin'),
		'comment_delete' => $container->get(CommentService::class)->deleteComment($spec['commentId'], 'admin'),
		'comment_resolve' => $container->get(CommentService::class)->setResolved($spec['commentId'], true, 'admin'),
		'reaction_add' => $container->get(CommentReactionService::class)->react($spec['commentId'], '👍', 'admin'),
		'reaction_remove' => $container->get(CommentReactionService::class)->unreact($spec['commentId'], '👍', 'admin'),
		'file_add' => $container->get(CardFileLinkService::class)->addLink($spec['cardId'], $spec['fileId'], 'admin'),
		'file_delete' => $container->get(CardFileLinkService::class)->deleteLink($spec['cardId'], $spec['linkId'], 'admin'),
		'attachment_upload' => upload($container, $spec['cardId']),
		'attachment_from_file' => $container->get(CardAttachmentService::class)->attachFromFileNode($spec['cardId'], $spec['fileId'], 'admin'),
		'attachment_delete' => $container->get(CardAttachmentService::class)->delete($spec['cardId'], $spec['attachmentId'], 'admin'),
		'title_update' => $container->get(CardService::class)->update($spec['cardId'], 'concurrent title', null, null, null, null, 'admin'),
		'label_assign' => $container->get(LabelService::class)->assign($spec['cardId'], $spec['labelId'], 'admin'),
		'assignee_assign' => $container->get(AssigneeService::class)->assign($spec['cardId'], 'admin', 'admin'),
		'checklist_add' => $container->get(ChecklistService::class)->addItem($spec['cardId'], 'concurrent step', 'admin'),
		'field_set' => $container->get(CardFieldValueService::class)->set($spec['cardId'], $spec['fieldId'], 'concurrent value', 'admin'),
		'time_add' => $container->get(CardTimeEntryService::class)->add($spec['cardId'], 60, 'concurrent time', 'admin'),
		'subscription_unsubscribe' => $container->get(SubscriptionService::class)->unsubscribe($spec['cardId'], 'admin'),
		'reminder_schedule' => $container->get(ReminderService::class)->schedule($spec['cardId'], 'admin', time() + 86400, $spec['commentId']),
		'review_request' => $container->get(ReviewService::class)->requestReview($spec['cardId'], 'admin', 'admin'),
		'project_add' => $container->get(ProjectService::class)->addCard($spec['projectId'], $spec['cardId'], 'admin'),
		'relation_add' => $container->get(CardRelationService::class)->addRelation($spec['cardId'], $spec['otherCardId'], 'relates', 'admin'),
		'parent_set' => $container->get(CardService::class)->setParent($spec['cardId'], $spec['otherCardId'], 'admin'),
		'recurrence_update' => $container->get(RecurrenceService::class)->update($spec['ruleId'], null, null, null, null, null, null, null, false, 'admin'),
		'field_delete' => $container->get(CardFieldService::class)->delete($spec['fieldId'], 'admin'),
		default => throw new \RuntimeException('Unknown test operation'),
	};
}

if (($argv[1] ?? '') === 'worker') {
	$spec = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
	$guard = new BarrierMutationService($db, $container->get(CardMapper::class), $spec['hold']);
	$container->registerService(CardMutationService::class, static fn () => $guard);
	$connectionId = match ($provider) {
		IDBConnection::PLATFORM_POSTGRES => (int)$db->executeQuery('SELECT pg_backend_pid()')->fetchOne(),
		IDBConnection::PLATFORM_MYSQL => (int)$db->executeQuery('SELECT CONNECTION_ID()')->fetchOne(),
		default => 0,
	};
	match ($provider) {
		IDBConnection::PLATFORM_POSTGRES => $db->executeStatement("SET lock_timeout = '5s'"),
		IDBConnection::PLATFORM_MYSQL => $db->executeStatement('SET innodb_lock_wait_timeout = 5'),
		default => $db->executeStatement('PRAGMA busy_timeout = 100'),
	};
	send(['event' => 'ready', 'connection' => $connectionId, 'sources' => productionSources()]);
	check((receive(STDIN)['event'] ?? '') === 'start', 'Expected the start barrier');
	send(['event' => 'attempt']);
	try {
		$value = operate($container, $spec);
		send(['event' => 'result', 'ok' => true, 'value' => wireValue($value)]);
	} catch (\Throwable $e) {
		send(['event' => 'result', 'ok' => false, 'class' => $e::class, 'message' => $e->getMessage(),
			'reason' => $e instanceof DbException ? $e->getReason() : null]);
	}
	exit(0);
}

function startWorker(array $spec): array {
	$process = proc_open([PHP_BINARY, __FILE__, 'worker', json_encode($spec, JSON_THROW_ON_ERROR)], [
		0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
	], $pipes);
	check(is_resource($process), 'Cannot launch a separate PHP process');
	$ready = receive($pipes[1]);
	check(($ready['event'] ?? '') === 'ready', 'Worker did not initialize its independent connection');
	check(($ready['sources'] ?? null) === productionSources(), 'Worker production paths or hashes differ from the main process');
	fwrite($pipes[0], "{\"event\":\"start\"}\n");
	check((receive($pipes[1])['event'] ?? '') === 'attempt', 'Worker did not reach the operation barrier');
	return ['process' => $process, 'pipes' => $pipes, 'connection' => $ready['connection']];
}

function release(array $worker): void {
	fwrite($worker['pipes'][0], "{\"event\":\"release\"}\n");
}

function finish(array $worker, ?array $result = null): array {
	$result ??= receive($worker['pipes'][1]);
	check(($result['event'] ?? '') === 'result', 'Worker never returned its operation result');
	fclose($worker['pipes'][0]);
	fclose($worker['pipes'][1]);
	$errors = stream_get_contents($worker['pipes'][2]);
	fclose($worker['pipes'][2]);
	check(proc_close($worker['process']) === 0, 'Worker process failed: ' . $errors);
	return $result;
}

function waitForLock(IDBConnection $db, array $worker): ?array {
	if ($db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE) {
		$result = receive($worker['pipes'][1]);
		check(($result['ok'] ?? true) === false && str_contains(strtolower($result['message'] ?? ''), 'locked'),
			'SQLite contender must report its actual write-lock conflict: ' . json_encode($result));
		return $result;
	}
	$mariaDb = $db->getDatabaseProvider() === IDBConnection::PLATFORM_MYSQL
		&& stripos($db->executeQuery('SELECT VERSION()')->fetchOne(), 'mariadb') !== false;
	$deadline = microtime(true) + 4;
	do {
		if ($db->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES) {
			$waiting = $db->executeQuery("SELECT COUNT(*) FROM pg_stat_activity WHERE pid = ? AND wait_event_type = 'Lock'", [$worker['connection']])->fetchOne();
		} elseif ($mariaDb) {
			$status = $db->executeQuery('SHOW ENGINE INNODB STATUS')->fetch()['Status'];
			$waiting = 0;
			foreach (preg_split('/(?=---TRANSACTION)/', $status) as $transaction) {
				if (str_contains($transaction, 'LOCK WAIT')
					&& preg_match('/MariaDB thread id ' . $worker['connection'] . '\\b/', $transaction) === 1) {
					$waiting = 1;
					break;
				}
			}
		} else {
			$waiting = $db->executeQuery('SELECT COUNT(*) FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID = ?', [$worker['connection']])->fetchOne();
		}
		if ((int)$waiting > 0) {
			return null;
		}
		$read = [$worker['pipes'][1]];
		$write = $except = [];
		if (stream_select($read, $write, $except, 0, 0) === 1) {
			throw new \RuntimeException('Contender returned without waiting for the held transaction: ' . json_encode(receive($worker['pipes'][1])));
		}
		usleep(10000);
	} while (microtime(true) < $deadline);
	throw new \RuntimeException('Database did not report the contender waiting on the held lock; connection=' . $worker['connection']);
}

function storageObjects(): array {
	$config = \OCP\Server::get(\OCP\IConfig::class);
	$base = $config->getSystemValue('datadirectory') . '/appdata_' . $config->getSystemValue('instanceid') . '/kanso';
	$paths = [];
	if (is_dir($base)) {
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if ($file->isFile()) {
				$paths[] = substr($file->getPathname(), strlen($base));
			}
		}
	}
	sort($paths);
	return $paths;
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

function assertDestination(object $container, array $spec, int $destinationId, bool $edited): void {
	$card = $container->get(CardMapper::class)->find($destinationId);
	check($card->getDeletedAt() === 0, 'Destination must be live');
	$comments = $container->get(CommentMapper::class)->findAllByCardForMove($destinationId);
	$root = $comments[0] ?? null;
	$operation = $spec['operation'];
	if ($operation === 'comment_add') {
		check(count($comments) === ($edited ? 2 : 1), 'Concurrent comment missing or unexpectedly copied');
	}
	if ($operation === 'comment_edit') {
		check($root?->getBody() === ($edited ? 'concurrent edit' : 'seed root'), 'Concurrent comment edit missing or unexpectedly copied');
	}
	if ($operation === 'comment_delete') {
		check(($root?->getDeletedAt() > 0) === $edited, 'Concurrent comment deletion missing or unexpectedly copied');
	}
	if ($operation === 'comment_resolve') {
		check(($root?->getResolvedAt() > 0) === $edited, 'Concurrent thread resolution missing or unexpectedly copied');
	}
	if (str_starts_with($operation, 'reaction_')) {
		$reactions = $container->get(CommentReactionMapper::class)->findEntitiesByComments(array_map(static fn ($comment) => $comment->getId(), $comments));
		$expected = ($operation === 'reaction_add') === $edited ? 1 : 0;
		check(count($reactions) === $expected, 'Concurrent reaction mutation missing or unexpectedly copied');
	}
	if (str_starts_with($operation, 'file_')) {
		$links = $container->get(CardFileLinkMapper::class)->findAllByCardForMove($destinationId);
		$expected = ($operation === 'file_add') === $edited ? 1 : 0;
		check(count($links) === $expected, 'Concurrent Files-link mutation missing or unexpectedly copied');
	}
	if (str_starts_with($operation, 'attachment_')) {
		$attachments = $container->get(CardAttachmentMapper::class)->findByCard($destinationId);
		$expected = ($operation !== 'attachment_delete') === $edited ? 1 : 0;
		check(count($attachments) === $expected, 'Concurrent attachment mutation missing or unexpectedly copied');
		foreach ($attachments as $attachment) {
			$stream = $container->get(CardAttachmentService::class)->openStoredObject($destinationId, $attachment->getStorageKey());
			check(is_resource($stream), 'Moved attachment bytes missing');
			check(stream_get_contents($stream) === 'concurrent attachment bytes', 'Moved attachment bytes changed');
			fclose($stream);
		}
	}
	if ($operation === 'title_update') {
		check($card->getTitle() === ($edited ? 'concurrent title' : 'before'), 'Concurrent title mutation missing or unexpectedly copied');
	}
	$mapper = match ($operation) {
		'label_assign' => CardLabelMapper::class,
		'assignee_assign' => CardAssigneeMapper::class,
		'checklist_add' => ChecklistItemMapper::class,
		'field_set' => CardFieldValueMapper::class,
		'time_add' => CardTimeEntryMapper::class,
		'reminder_schedule' => ReminderMapper::class,
		'review_request' => CardReviewMapper::class,
		'project_add' => ProjectCardMapper::class,
		'relation_add' => CardRelationMapper::class,
		default => null,
	};
	if ($mapper !== null) {
		$method = match ($operation) {
			'label_assign' => 'findLabelIdsByCard',
			'assignee_assign' => 'findUserIdsByCard',
			'reminder_schedule', 'project_add' => 'findByCardForMove',
			'relation_add' => 'findTouching',
			default => 'findByCard',
		};
		check(count($container->get($mapper)->$method($destinationId)) === ($edited ? 1 : 0), 'Concurrent ' . $operation . ' missing or unexpectedly copied');
	}
	if ($operation === 'subscription_unsubscribe') {
		check(in_array('admin', $container->get(SubscriptionMapper::class)->findCardSubscriberUids($destinationId), true) !== $edited,
			'Concurrent subscription update missing or unexpectedly copied');
	}
	if ($operation === 'parent_set') {
		check(($card->getParentCardId() !== null) === $edited, 'Concurrent parent update missing or unexpectedly copied');
	}
	if ($operation === 'recurrence_update') {
		$rule = $container->get(RecurRuleMapper::class)->find($spec['ruleId']);
		check($rule->getTemplateCardId() === $destinationId && $rule->getEnabled() !== $edited,
			'Concurrent recurrence update missing or unexpectedly copied');
	}
}

foreach (productionSources() as $class => $identity) {
	echo 'SOURCE ' . $provider . ' ' . $class . ' ' . $identity['path'] . ' sha256=' . $identity['sha256'] . "\n";
}
check($container->get(CardMutationService::class)::class === CardMutationService::class, 'Unexpected production guard registration');

$passed = 0;
$document = ['kanso' => 3, 'exportedAt' => time(), 'board' => [
	'title' => 'Import transaction settings', 'color' => '0082c9', 'description' => 'Preserve imported settings',
	'estimateScale' => 'fibonacci', 'newCardsOnTop' => true,
	'stacks' => [['id' => 1, 'title' => 'Todo', 'sortKey' => 'U']],
	'cards' => [['id' => 1, 'stackId' => 1, 'title' => 'Imported card', 'sortKey' => 'U']],
]];
$importer = $container->get(ImportService::class);
$imported = $importer->import(json_encode($document, JSON_THROW_ON_ERROR), 'admin');
$importedBoard = $container->get(BoardMapper::class)->find($imported['boardId']);
$duplicated = $importer->duplicate($importedBoard, 'admin', true);
foreach ([$imported, $duplicated] as $rebuilt) {
	$rebuiltBoard = $container->get(BoardMapper::class)->find($rebuilt['boardId']);
	check($rebuiltBoard->getDescription() === 'Preserve imported settings'
		&& $rebuiltBoard->getEstimateScale() === 'fibonacci' && $rebuiltBoard->getNewCardsOnTop() === true,
		'Import or duplication lost board settings');
	check(count($container->get(CardMapper::class)->findAllIdsByBoard($rebuilt['boardId'])) === 1, 'Import or duplication lost its card graph');
	check(!$db->inTransaction(), 'Import or duplication left an outer transaction open');
	$passed++;
}
echo 'PASS ' . $provider . " import and duplication preserve settings and graph\n";

$boards = $container->get(BoardService::class);
$stacks = $container->get(StackService::class);
$cards = $container->get(CardService::class);
$source = $boards->create('Concurrency source ' . bin2hex(random_bytes(4)), null, 'admin');
$destination = $boards->create('Concurrency destination ' . bin2hex(random_bytes(4)), null, 'admin');
$sourceStack = $stacks->create($source->getId(), 'Source', 'admin');
$destinationStack = $stacks->create($destination->getId(), 'Destination', 'admin');
$sourceFile = $container->get(IRootFolder::class)->getUserFolder('admin')->newFile('kanso-concurrency-' . bin2hex(random_bytes(6)) . '.txt', 'concurrent attachment bytes');
$label = $container->get(LabelService::class)->create($source->getId(), 'Concurrent label', '0082c9', 'admin');
$container->get(LabelService::class)->create($destination->getId(), 'Concurrent label', '0082c9', 'admin');
$field = $container->get(CardFieldService::class)->create($source->getId(), 'Concurrent field', 'text', null, 'admin');
$container->get(CardFieldService::class)->create($destination->getId(), 'Concurrent field', 'text', null, 'admin');
$project = $container->get(ProjectService::class)->create('admin', 'Concurrent project', null, null);
$operations = ['comment_add', 'comment_edit', 'comment_delete', 'comment_resolve', 'reaction_add', 'reaction_remove',
	'file_add', 'file_delete', 'attachment_upload', 'attachment_from_file', 'attachment_delete', 'title_update',
	'label_assign', 'assignee_assign', 'checklist_add', 'field_set', 'time_add', 'subscription_unsubscribe',
	'reminder_schedule', 'review_request', 'project_add', 'relation_add', 'parent_set', 'recurrence_update'];
$fixtures = [];
foreach (range(1, count($operations) * 4) as $index) {
	$fixtures[] = $cards->create($sourceStack->getId(), 'before', 'admin');
}
foreach ($operations as $operation) {
	foreach ([true, false] as $writerFirst) {
		$card = array_shift($fixtures);
		$otherCard = array_shift($fixtures);
		$comment = $container->get(CommentService::class)->addComment($card->getId(), 'seed root', null, 'admin');
		$spec = ['cardId' => $card->getId(), 'commentId' => $comment->getId(), 'fileId' => $sourceFile->getId(),
			'operation' => $operation, 'targetStack' => $destinationStack->getId(), 'otherCardId' => $otherCard->getId(),
			'labelId' => $label->getId(), 'fieldId' => $field->getId(), 'projectId' => $project->getId()];
		if ($operation === 'reaction_remove') {
			$container->get(CommentReactionService::class)->react($comment->getId(), '👍', 'admin');
		}
		if ($operation === 'file_delete') {
			$spec['linkId'] = $container->get(CardFileLinkService::class)->addLink($card->getId(), $sourceFile->getId(), 'admin')->getId();
		}
		if ($operation === 'attachment_delete') {
			$spec['attachmentId'] = upload($container, $card->getId())->getId();
		}
		if ($operation === 'recurrence_update') {
			$spec['ruleId'] = $container->get(RecurrenceService::class)->create($source->getId(), $card->getId(), $sourceStack->getId(), 0, 'FREQ=DAILY', 0, 0, true, 'admin')->getId();
		}
		$move = $spec;
		$move['operation'] = 'move';
		$first = startWorker(($writerFirst ? $spec : $move) + ['hold' => true]);
		try {
			$held = receive($first['pipes'][1]);
			check(($held['event'] ?? '') === 'held', 'The actual service did not enter its shared mutation guard: ' . json_encode($held));
			$second = startWorker(($writerFirst ? $move : $spec) + ['hold' => false]);
			try {
				$early = waitForLock($db, $second);
			} finally {
				release($first);
			}
			$firstResult = finish($first);
			$first = null;
			$secondResult = finish($second, $early);
			check($firstResult['ok'], 'First operation failed: ' . json_encode($firstResult));
			if ($writerFirst) {
				if ($provider === IDBConnection::PLATFORM_SQLITE) {
					$moved = $cards->moveToBoard($card->getId(), $destinationStack->getId(), 'admin');
					$destinationId = $moved->getId();
				} else {
					check($secondResult['ok'], 'Move after writer failed: ' . json_encode($secondResult));
					$destinationId = $secondResult['value']['id'];
				}
			} else {
				$destinationId = $firstResult['value']['id'];
				check(!$secondResult['ok'], 'Concurrent writer reported success after source moved');
				if ($provider !== IDBConnection::PLATFORM_SQLITE) {
					check($secondResult['class'] === DoesNotExistException::class, 'Writer did not clearly reject the moved card: ' . json_encode($secondResult));
				}
				if ($operation !== 'recurrence_update') {
					expectFailure(static fn () => operate($container, $spec), DoesNotExistException::class);
				}
			}
			check($container->get(CardMapper::class)->find($card->getId())->getDeletedAt() > 0, 'Source was not deleted');
			assertDestination($container, $spec, $destinationId, $writerFirst);
			$passed++;
			echo 'PASS ' . $provider . ' ' . $operation . ' ' . ($writerFirst ? 'writer-first' : 'move-first') . "\n";
		} finally {
			if ($first !== null) {
				release($first);
				proc_terminate($first['process']);
			}
		}
	}
}

foreach ([true, false] as $deleteFirst) {
	$name = 'Deleted destination field ' . bin2hex(random_bytes(5));
	$sourceField = $container->get(CardFieldService::class)->create($source->getId(), $name, 'text', null, 'admin');
	$targetField = $container->get(CardFieldService::class)->create($destination->getId(), $name, 'text', null, 'admin');
	$card = $cards->create($sourceStack->getId(), 'Destination field race', 'admin');
	$container->get(CardFieldValueService::class)->set($card->getId(), $sourceField->getId(), 'keep', 'admin');
	$move = ['operation' => 'move', 'cardId' => $card->getId(), 'targetStack' => $destinationStack->getId()];
	$delete = ['operation' => 'field_delete', 'fieldId' => $targetField->getId()];
	$first = startWorker(($deleteFirst ? $delete : $move) + ['hold' => true]);
	try {
		$held = receive($first['pipes'][1]);
		check(($held['event'] ?? '') === 'held', 'Destination field race did not enter the real service guard');
		$second = startWorker(($deleteFirst ? $move : $delete) + ['hold' => false]);
		try {
			$early = waitForLock($db, $second);
		} finally {
			release($first);
		}
		$firstResult = finish($first);
		$first = null;
		$secondResult = finish($second, $early);
		check($firstResult['ok'], 'Destination field race first operation failed');
		if ($deleteFirst) {
			check(!$secondResult['ok'], 'Move succeeded after its required destination field was removed');
			if ($provider !== IDBConnection::PLATFORM_SQLITE) {
				check($secondResult['class'] === InvalidInputException::class, 'Move did not report the missing destination field');
			}
			check($container->get(CardMapper::class)->find($card->getId())->getDeletedAt() === 0, 'Missing destination field deleted the source');
		} else {
			$destinationId = $firstResult['value']['id'];
			if ($provider === IDBConnection::PLATFORM_SQLITE) {
				$container->get(CardFieldService::class)->delete($targetField->getId(), 'admin');
			} else {
				check($secondResult['ok'], 'Destination field deletion failed after move committed');
			}
			check($container->get(CardMapper::class)->find($destinationId)->getDeletedAt() === 0, 'Serialized field deletion removed the moved card');
			check($container->get(CardFieldValueMapper::class)->findByCard($destinationId) === [], 'Deleted destination field retained a dangling moved value');
		}
		$passed++;
		echo 'PASS ' . $provider . ' destination field deletion ' . ($deleteFirst ? 'delete-first' : 'move-first') . "\n";
	} finally {
		if ($first !== null) {
			release($first);
			proc_terminate($first['process']);
		}
	}
}

if ($provider !== IDBConnection::PLATFORM_SQLITE) {
	$one = $cards->create($sourceStack->getId(), 'before', 'admin');
	$two = $cards->create($sourceStack->getId(), 'before', 'admin');
	$first = startWorker(['operation' => 'title_update', 'cardId' => $one->getId(), 'hold' => true]);
	try {
		check((receive($first['pipes'][1])['event'] ?? '') === 'held', 'First unrelated writer did not hold its lock');
		$second = startWorker(['operation' => 'title_update', 'cardId' => $two->getId(), 'hold' => false]);
		check(finish($second)['ok'], 'An unrelated card write was blocked by a shared board lock');
	} finally {
		release($first);
	}
	check(finish($first)['ok'], 'First unrelated writer failed');
	$passed++;
	echo 'PASS ' . $provider . " unrelated writes share board fence\n";
}

$peerUid = 'concurrency-' . bin2hex(random_bytes(5));
$peer = $container->get(IUserManager::class)->createUser($peerUid, bin2hex(random_bytes(16)));
check($peer !== false, 'Cannot create the permission-test user');
try {
	$container->get(AclService::class)->create($source->getId(), $peerUid, 'user', 3, 'admin');
	$card = $cards->create($sourceStack->getId(), 'Permission target denied', 'admin');
	$objects = storageObjects();
	expectFailure(static fn () => $cards->moveToBoard($card->getId(), $destinationStack->getId(), $peerUid), NotPermittedException::class);
	check($container->get(CardMapper::class)->find($card->getId())->getDeletedAt() === 0, 'Target denial deleted source');
	$reverse = $cards->create($destinationStack->getId(), 'Permission source denied', 'admin');
	expectFailure(static fn () => $cards->moveToBoard($reverse->getId(), $sourceStack->getId(), $peerUid), NotPermittedException::class);
	check($container->get(CardMapper::class)->find($reverse->getId())->getDeletedAt() === 0, 'Source denial deleted source');
	check(storageObjects() === $objects, 'Permission denial changed stored objects');
	$passed += 2;
	echo 'PASS ' . $provider . " source and destination permission gates\n";
} finally {
	$peer->delete();
}

$failureCard = $cards->create($sourceStack->getId(), 'Rollback source', 'admin');
$attachment = upload($container, $failureCard->getId());
$field = $container->get(CardFieldService::class)->create($source->getId(), 'Unmatched ' . bin2hex(random_bytes(5)), 'text', null, 'admin');
$container->get(CardFieldValueService::class)->set($failureCard->getId(), $field->getId(), 'keep', 'admin');
$objects = storageObjects();
expectFailure(static fn () => $cards->moveToBoard($failureCard->getId(), $destinationStack->getId(), 'admin'), InvalidInputException::class);
check(storageObjects() === $objects, 'Failed move left copied attachment objects');
check($container->get(CardMapper::class)->find($failureCard->getId())->getDeletedAt() === 0, 'Failed move deleted source');
check(count($container->get(CardAttachmentMapper::class)->findByCard($failureCard->getId())) === 1, 'Failed move lost source attachment');
$passed++;
echo 'PASS ' . $provider . " failed move cleans copied storage and retains source\n";

$guard = $container->get(CardMutationService::class);
$rollbackCard = $cards->create($sourceStack->getId(), 'Nested rollback', 'admin');
$objects = storageObjects();
expectFailure(static fn () => $guard->run($rollbackCard->getId(), static function () use ($container, $rollbackCard): void {
	upload($container, $rollbackCard->getId());
	throw new \LogicException('Rollback fixture');
}), \LogicException::class);
check(storageObjects() === $objects, 'Rolled-back upload left stored bytes');
check($container->get(CardAttachmentMapper::class)->findByCard($rollbackCard->getId()) === [], 'Rolled-back upload left a database row');
$retained = upload($container, $rollbackCard->getId());
$objects = storageObjects();
expectFailure(static fn () => $guard->run($rollbackCard->getId(), static function () use ($container, $rollbackCard, $retained): void {
	$container->get(CardAttachmentService::class)->delete($rollbackCard->getId(), $retained->getId(), 'admin');
	throw new \LogicException('Rollback fixture');
}), \LogicException::class);
check(storageObjects() === $objects, 'Rolled-back attachment deletion removed stored bytes');
check(count($container->get(CardAttachmentMapper::class)->findByCard($rollbackCard->getId())) === 1, 'Rolled-back deletion lost its database row');
$passed += 2;
echo 'PASS ' . $provider . " nested upload/delete rollback preserves storage consistency\n";

$events = [];
$guard->run($rollbackCard->getId(), static function () use ($guard, $db, &$events): void {
	$guard->afterCommit(static function () use ($db, &$events): void {
		check(!$db->inTransaction(), 'Commit callback ran inside a database transaction');
		$events[] = 'committed';
	});
	check($events === [], 'Commit callback ran before the outer scope committed');
});
check($events === ['committed'], 'Commit callback did not run once');
expectFailure(static fn () => $guard->run($rollbackCard->getId(), static function () use ($guard, &$events): void {
	$guard->afterCommit(static function () use (&$events): void {
		$events[] = 'unexpected';
	});
	throw new \LogicException('Rollback fixture');
}), \LogicException::class);
check($events === ['committed'], 'Rolled-back transaction emitted its commit callback');
$passed++;
echo 'PASS ' . $provider . " post-commit callbacks follow outer transaction outcome\n";
$sourceFile->delete();
echo 'OK ' . $provider . ': ' . $passed . " integration checks\n";
