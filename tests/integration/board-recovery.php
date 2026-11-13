<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Integration\BoardRecovery;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\AppInfo\Application;
use OCA\Kanso\Cron\PurgeDeletedBoards;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Service\AclService;
use OCA\Kanso\Service\AssigneeService;
use OCA\Kanso\Service\BoardPurgeService;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\CardAttachmentService;
use OCA\Kanso\Service\CardMutationService;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\ChecklistService;
use OCA\Kanso\Service\CommentService;
use OCA\Kanso\Service\LabelService;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\PublicShareService;
use OCA\Kanso\Service\StackService;
use OCA\Kanso\Service\TrashService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

if (PHP_SAPI !== 'cli' || getenv('KANSO_INTEGRATION_DISPOSABLE') !== '1') {
	throw new \RuntimeException('This harness requires an explicitly authorized disposable installation');
}
$root = getenv('NEXTCLOUD_ROOT');
if ($root === false || !is_file($root . '/lib/base.php')) {
	throw new \RuntimeException('NEXTCLOUD_ROOT must point to an installed Nextcloud');
}
define('OC_CONSOLE', true);
require_once $root . '/lib/base.php';
set_exception_handler(static function (\Throwable $e): void {
	fwrite(STDERR, (string)$e . "\n");
	exit(1);
});
\OC::$composerAutoloader->addPsr4('OCA\\Kanso\\', dirname(__DIR__, 2) . '/lib/', true);
\OC_App::loadApps();
$container = (new Application())->getContainer();
$config = $container->get(IConfig::class);
check(getenv('KANSO_RECOVERY_EXPECTED_DATA_DIR') === $config->getSystemValueString('datadirectory'), 'Disposable data directory does not match');
$db = $container->get(IDBConnection::class);
$prefix = $config->getSystemValueString('dbtableprefix', 'oc_');
$users = $container->get(IUserManager::class);
$groups = $container->get(IGroupManager::class);
$admin = $users->get('admin');
check($admin !== null, 'A disposable admin account is required');
$container->get(IUserSession::class)->setUser($admin);
\OC_Util::setupFS('admin');
foreach ([BoardService::class, BoardMapper::class, PermissionService::class, CardMutationService::class, BoardPurgeService::class] as $class) {
	$source = (new \ReflectionClass($class))->getFileName();
	$expected = dirname(__DIR__, 2) . '/lib/' . str_replace('\\', '/', substr($class, strlen('OCA\\Kanso\\'))) . '.php';
	check(realpath($source) === realpath($expected), 'Class loaded outside this checkout: ' . $class);
	echo 'SOURCE ' . $class . ' sha256=' . hash_file('sha256', $source) . "\n";
}

function check(bool $condition, string $message): void {
	if (!$condition) {
		throw new \RuntimeException($message);
	}
}

function fails(callable $operation, string $class): void {
	try {
		$operation();
	} catch (\Throwable $e) {
		check($e instanceof $class, 'Expected ' . $class . ', got ' . $e::class . ': ' . $e->getMessage());
		return;
	}
	throw new \RuntimeException('Expected ' . $class . ' but operation succeeded');
}

function ids(IDBConnection $db, string $table, string $column, int $id): array {
	$qb = $db->getQueryBuilder();
	$qb->select('id')->from($table)->where($qb->expr()->eq($column, $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
	$result = $qb->executeQuery();
	try {
		return array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
	} finally {
		$result->closeCursor();
	}
}

function snapshot(IDBConnection $db, string $prefix, int $boardId, array $links): array {
	$out = [];
	foreach ($db->createSchema()->getTables() as $table) {
		if (!str_starts_with($table->getName(), $prefix . 'kanso_')) {
			continue;
		}
		$name = substr($table->getName(), strlen($prefix));
		$qb = $db->getQueryBuilder();
		$match = $qb->expr()->orX();
		if ($name === 'kanso_boards') {
			$match->add($qb->expr()->eq('id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)));
		} else {
			foreach ($links as $column => $values) {
				if ($values !== [] && $table->hasColumn($column)) {
					$match->add($qb->expr()->in($column, $qb->createNamedParameter($values, IQueryBuilder::PARAM_INT_ARRAY)));
				}
			}
		}
		if (count($match) === 0) {
			continue;
		}
		$qb->select('*')->from($name)->where($match);
		$result = $qb->executeQuery();
		try {
			$rows = $result->fetchAll();
		} finally {
			$result->closeCursor();
		}
		if ($name === 'kanso_changes') {
			$rows = array_values(array_filter($rows, static fn (array $row): bool => in_array((int)$row['id'], $links['change_id'], true)));
		}
		foreach ($rows as &$row) {
			if ($name === 'kanso_boards') {
				unset($row['deleted_at'], $row['last_modified']);
			}
			ksort($row);
		}
		unset($row);
		usort($rows, static fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));
		$out[$name] = $rows;
	}
	ksort($out);
	return $out;
}

$boards = $container->get(BoardService::class);
$boardMapper = $container->get(BoardMapper::class);
$stacks = $container->get(StackService::class);
$cards = $container->get(CardService::class);
$attachments = $container->get(CardAttachmentService::class);
$trash = $container->get(TrashService::class);
$shares = $container->get(PublicShareService::class);
$purger = $container->get(BoardPurgeService::class);
$ownedBoards = [];
$ownedUsers = [];
$group = null;
$file = null;
$stamp = 'recovery_' . bin2hex(random_bytes(5));
$listIds = static fn (string $uid): array => array_column($boards->findDeleted($uid), 'id');
$fixture = static function (string $owner = 'admin') use ($boards, $stacks, &$ownedBoards, $stamp): array {
	$board = $boards->create($stamp . ' ' . count($ownedBoards), null, $owner);
	$ownedBoards[] = $board->getId();
	return [$board, $stacks->create($board->getId(), 'Work', $owner)];
};

try {
	foreach (['manager', 'group', 'reader', 'editor', 'external', 'stranger'] as $role) {
		$user = $users->createUser($stamp . '_' . $role, bin2hex(random_bytes(16)));
		check($user !== false, 'Could not create fixture user');
		$ownedUsers[$role] = $user;
	}
	$group = $groups->createGroup($stamp);
	check($group !== null, 'Could not create fixture group');
	$group->addUser($ownedUsers['group']);
	[$board, $stack] = $fixture();
	$boardId = $board->getId();
	$acl = $container->get(AclService::class);
	foreach (['manager' => 9, 'reader' => 1, 'editor' => 3, 'external' => 9] as $role => $mask) {
		$acl->create($boardId, $ownedUsers[$role]->getUID(), 'user', $mask, 'admin', $role === 'external' ? ViewerContext::ROLE_EXTERNAL : ViewerContext::ROLE_INTERNAL);
	}
	$acl->create($boardId, $group->getGID(), 'group', 9, 'admin');
	$card = $cards->create($stack->getId(), 'Public card', 'admin');
	$private = $cards->create($stack->getId(), 'Private card', 'admin', visibility: 'private');
	$internal = $cards->create($stack->getId(), 'Internal card', 'admin', visibility: 'internal');
	$trashed = $cards->create($stack->getId(), 'Trashed card', 'admin');
	$cards->delete($trashed->getId(), 'admin');
	$comment = $container->get(CommentService::class)->addComment($card->getId(), 'Keep discussion', null, 'admin');
	$container->get(ChecklistService::class)->addItem($card->getId(), 'Keep step', 'admin');
	$container->get(AssigneeService::class)->assign($card->getId(), 'admin', 'admin');
	$container->get(LabelService::class)->create($boardId, 'Keep label', '0082C9', 'admin');
	$token = $shares->enable($boardId, 'admin')['token'];
	check(is_string($token), 'Public token missing');
	$file = tempnam(sys_get_temp_dir(), 'kanso-recovery-');
	check($file !== false, 'Could not create upload fixture');
	$bytes = "Recovery attachment\x00\x01\n";
	file_put_contents($file, $bytes);
	$attachment = $attachments->upload($card->getId(), ['error' => UPLOAD_ERR_OK, 'tmp_name' => $file, 'name' => 'keep.bin', 'size' => strlen($bytes)], 'admin');
	$attachmentFolder = $config->getSystemValueString('datadirectory') . '/appdata_' . $config->getSystemValueString('instanceid') . '/kanso/card-' . $card->getId();
	check(is_file($attachmentFolder . '/' . $attachment->getStorageKey()), 'Harness requires local attachment storage');
	$cardIds = [$card->getId(), $private->getId(), $internal->getId(), $trashed->getId()];
	$links = ['board_id' => [$boardId], 'card_id' => $cardIds, 'other_card_id' => $cardIds, 'template_card_id' => $cardIds,
		'comment_id' => [$comment->getId()], 'change_id' => ids($db, 'kanso_changes', 'board_id', $boardId), 'intake_id' => []];
	$before = snapshot($db, $prefix, $boardId, $links);
	check(count(array_filter($before)) >= 10, 'Snapshot fixture is too sparse');
	$trashBefore = array_map(static fn ($row): array => $row->jsonSerialize(), $trash->listTrash($boardId, 'admin'));
	$publicBefore = $shares->getPublicBoard($token);
	$boards->delete($boardId, 'admin');
	fails(static fn () => $boards->find($boardId, 'admin'), DoesNotExistException::class);
	fails(static fn () => $shares->getPublicBoard($token), DoesNotExistException::class);
	fails(static fn () => $trash->listTrash($boardId, 'admin'), DoesNotExistException::class);
	foreach (['admin', $ownedUsers['manager']->getUID(), $ownedUsers['group']->getUID()] as $uid) {
		check(in_array($boardId, $listIds($uid), true), 'Owner/manager cannot list deleted board');
	}
	foreach (['reader', 'editor', 'external', 'stranger'] as $role) {
		$uid = $ownedUsers[$role]->getUID();
		check(!in_array($boardId, $listIds($uid), true), 'Unauthorized user can list deleted board: ' . $role);
		fails(static fn () => $boards->restore($boardId, $uid), NotPermittedException::class);
	}
	$group->removeUser($ownedUsers['group']);
	check(!in_array($boardId, $listIds($ownedUsers['group']->getUID()), true), 'Revoked group membership can list board');
	fails(static fn () => $boards->restore($boardId, $ownedUsers['group']->getUID()), NotPermittedException::class);
	$group->addUser($ownedUsers['group']);
	$boards->restore($boardId, $ownedUsers['group']->getUID());
	check(snapshot($db, $prefix, $boardId, $links) === $before, 'Restoration changed retained board data');
	check($attachments->download($card->getId(), $attachment->getId(), 'admin')[1] === $bytes, 'Attachment bytes changed');
	check(array_map(static fn ($row): array => $row->jsonSerialize(), $trash->listTrash($boardId, 'admin')) === $trashBefore, 'Board restore changed card trash');
	$publicAfter = $shares->getPublicBoard($token);
	unset($publicBefore['board']['lastModified'], $publicAfter['board']['lastModified']);
	check($publicAfter === $publicBefore, 'Public view or token changed');
	fails(static fn () => $cards->find($private->getId(), $ownedUsers['manager']->getUID()), DoesNotExistException::class);
	fails(static fn () => $cards->find($internal->getId(), $ownedUsers['external']->getUID()), DoesNotExistException::class);
	fails(static fn () => $boards->restore($boardId, 'admin'), DoesNotExistException::class);
	echo "PASS full retained-row/attachment snapshot, shares, card trash and privacy\n";

	$boards->delete($boardId, 'admin');
	$boards->restore($boardId, $ownedUsers['manager']->getUID());
	$boards->delete($boardId, 'admin');
	$group->addUser($ownedUsers['external']);
	check(in_array($boardId, $listIds($ownedUsers['external']->getUID()), true), 'Mixed internal/external grants did not fold normally');
	$boards->restore($boardId, $ownedUsers['external']->getUID());
	$boards->delete($boardId, 'admin');
	$boards->restore($boardId, 'admin');
	[$unshared] = $fixture($ownedUsers['stranger']->getUID());
	$boards->delete($unshared->getId(), $ownedUsers['stranger']->getUID());
	check(!in_array($unshared->getId(), $listIds('admin'), true), 'Instance admin gained a recovery dashboard');
	fails(static fn () => $boards->restore($unshared->getId(), 'admin'), NotPermittedException::class);
	echo "PASS owner/direct/group manager recovery, grant caps and current membership\n";

	[$neighbor, $neighborStack] = $fixture();
	$neighborCard = $cards->create($neighborStack->getId(), 'Keep neighbor', 'admin');
	$neighborAttachment = $attachments->upload($neighborCard->getId(), ['error' => UPLOAD_ERR_OK, 'tmp_name' => $file, 'name' => 'keep.bin', 'size' => strlen($bytes)], 'admin');
	$boards->delete($boardId, 'admin');
	check(!in_array($boardId, $purger->findPurgeable(time() - PurgeDeletedBoards::RETENTION_SECONDS, 100000), true), 'Fresh tombstone became purgeable');
	$expiredAt = time() - PurgeDeletedBoards::RETENTION_SECONDS - 10;
	$qb = $db->getQueryBuilder();
	$qb->update('kanso_boards')->set('deleted_at', $qb->createNamedParameter($expiredAt, IQueryBuilder::PARAM_INT))
		->where($qb->expr()->eq('id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))->executeStatement();
	check(in_array($boardId, array_map(static fn ($row): int => $row->getId(), $boardMapper->findDeletedForUser('admin', [], $expiredAt)), true), 'Exact recovery cutoff was excluded');
	check(!in_array($boardId, array_map(static fn ($row): int => $row->getId(), $boardMapper->findDeletedForUser('admin', [], $expiredAt + 1)), true), 'Past recovery cutoff was included');
	check(!in_array($boardId, $listIds('admin'), true), 'Expired board remains recoverable');
	fails(static fn () => $boards->restore($boardId, 'admin'), DoesNotExistException::class);
	check(in_array($boardId, $purger->findPurgeable(time() - PurgeDeletedBoards::RETENTION_SECONDS, 100000), true), 'Expired board not purgeable');
	$links['change_id'] = ids($db, 'kanso_changes', 'board_id', $boardId);
	check($purger->purge($boardId), 'Expired board purge deferred');
	fails(static fn () => $boardMapper->find($boardId), DoesNotExistException::class);
	fails(static fn () => $boards->restore($boardId, 'admin'), DoesNotExistException::class);
	$afterPurge = snapshot($db, $prefix, $boardId, $links);
	check(array_filter($afterPurge) === [], 'Purge left board-scoped rows');
	clearstatcache(true, $attachmentFolder);
	check(!is_dir($attachmentFolder), 'Purge left attachment bytes on disk');
	check($cards->find($neighborCard->getId(), 'admin')->getTitle() === 'Keep neighbor', 'Purge changed neighbor data');
	check($attachments->download($neighborCard->getId(), $neighborAttachment->getId(), 'admin')[1] === $bytes, 'Purge changed neighbor bytes');
	echo 'OK ' . $db->getDatabaseProvider() . ": board recovery, expiry and purge\n";
} finally {
	foreach ($ownedBoards as $id) {
		try {
			$boardMapper->find($id);
			check($purger->purge($id), 'Fixture purge deferred');
		} catch (DoesNotExistException) {
		}
	}
	$group?->delete();
	foreach ($ownedUsers as $user) {
		$user->delete();
	}
	if (is_string($file)) {
		unlink($file);
	}
	echo "CLEANUP owned boards, users, group and upload removed\n";
}
