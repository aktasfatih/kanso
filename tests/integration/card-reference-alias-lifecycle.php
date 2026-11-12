<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Integration\AliasLifecycle;

use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\Kanso\AppInfo\Application;
use OCA\Kanso\Controller\CardController;
use OCA\Kanso\Controller\CardMoveAliasController;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardMoveAliasMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Migration\Version007600Date20261112000000;
use OCA\Kanso\Service\AclService;
use OCA\Kanso\Service\BoardPurgeService;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\CardMoveAliasService;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\StackService;
use OCA\Kanso\Service\TrashService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;

function check(bool $ok, string $message): void {
	if (!$ok) {
		throw new \RuntimeException($message);
	}
}
function scalar(IDBConnection $db, string $sql, array $params = []): mixed {
	$result = $db->executeQuery($sql, $params);
	try {
		return $result->fetchOne();
	} finally {
		$result->closeCursor();
	}
}
check(PHP_SAPI === 'cli' && getenv('KANSO_INTEGRATION_DISPOSABLE') === '1', 'Set KANSO_INTEGRATION_DISPOSABLE=1 only for a disposable installation');
$root = getenv('KANSO_ALIAS_LIFECYCLE_ROOT');
check(is_string($root) && str_starts_with($root, '/tmp/kanso-reference-alias-lifecycle-'), 'KANSO_ALIAS_LIFECYCLE_ROOT must name a new isolated installation');
check(is_dir($root . '/config') && is_file($root . '/data/nextcloud.db'), 'The isolated installation must already exist');
$root = realpath($root);
check(is_string($root) && str_starts_with($root, '/tmp/kanso-reference-alias-lifecycle-'), 'The isolated installation resolved outside its owned directory');
check(realpath((string)getenv('NEXTCLOUD_CONFIG_DIR')) === realpath($root . '/config'), 'Wrong config directory');
$nextcloudRoot = getenv('NEXTCLOUD_ROOT');
check(is_string($nextcloudRoot) && is_file($nextcloudRoot . '/lib/base.php'), 'NEXTCLOUD_ROOT must name the installed Nextcloud checkout');
define('OC_CONSOLE', true);
require $nextcloudRoot . '/lib/base.php';
set_exception_handler(static function (\Throwable $e): void {
	fwrite(STDERR, (string)$e . "\n");
	exit(1);
});
$sourceRoot = dirname(__DIR__, 2);
\OC::$composerAutoloader->addPsr4('OCA\\Kanso\\', $sourceRoot . '/lib/', true);
\OC_App::loadApps();
$container = (new Application())->getContainer();
$db = $container->get(IDBConnection::class);
$connection = $container->get(Connection::class);
check($db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE, 'SQLite only');
$databases = $db->executeQuery('PRAGMA database_list');
$main = null;
while (($row = $databases->fetch()) !== false) {
	if ($row['name'] === 'main') {
		$main = $row['file'];
	}
}
$databases->closeCursor();
check(is_string($main) && realpath($main) === realpath($root . '/data/nextcloud.db'), 'Primary database is not the new owned SQLite file');
check(realpath($connection->getParams()['path']) === realpath($main), 'Schema connection selected another database');
echo 'ISOLATED main=' . $main . "\n";
foreach ([CardService::class, CardMoveAliasService::class, CardController::class, CardMoveAliasController::class, CardMoveAliasMapper::class,
	TrashService::class, BoardPurgeService::class, Version007600Date20261112000000::class] as $class) {
	$loaded = (new \ReflectionClass($class))->getFileName();
	$expected = $sourceRoot . '/lib/' . str_replace('\\', '/', substr($class, strlen('OCA\\Kanso\\'))) . '.php';
	check(realpath($loaded) === realpath($expected), 'Wrong production source ' . $class);
	echo 'SOURCE ' . $class . ' sha256=' . hash_file('sha256', $loaded) . "\n";
}
$admin = $container->get(IUserManager::class)->get('admin');
check($admin !== null, 'Missing synthetic admin');
$container->get(IUserSession::class)->setUser($admin);
\OC_Util::setupFS('admin');
$prefix = $container->get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
$boards = $container->get(BoardService::class);
$stacks = $container->get(StackService::class);
$cards = $container->get(CardService::class);
$mapper = $container->get(CardMapper::class);
$aliases = $container->get(CardMoveAliasMapper::class);
$trash = $container->get(TrashService::class);
$purger = $container->get(BoardPurgeService::class);
$ownedBoards = [];
$ownedAliases = [];
$ownedPeers = [];
$evidence = [];
$failures = [];
check((int)scalar($db, 'SELECT COUNT(*) FROM *PREFIX*kanso_boards') === 0, 'Fixture installation is not fresh');

function fixture(string $prefix): array {
	global $boards, $stacks, $ownedBoards;
	$board = $boards->create('Alias lifecycle ' . $prefix, null, 'admin');
	$ownedBoards[] = $board->getId();
	$board = $boards->update($board->getId(), null, null, null, 'admin', prefix: $prefix);
	return [$board, $stacks->create($board->getId(), 'Work', 'admin')];
}
function ref(Board $board, Card $card): string {
	return $board->getPrefix() . '-' . $card->getBoardSeq();
}
function moveCard(Card $card, Stack $stack): Card {
	global $cards, $ownedAliases;
	$result = $cards->moveToBoard($card->getId(), $stack->getId(), 'admin');
	$ownedAliases[] = $card->getId();
	return $result;
}
function purgeCard(Card $card): void {
	global $cards, $trash, $mapper;
	if ($mapper->find($card->getId())->getDeletedAt() === 0) {
		$cards->delete($card->getId(), 'admin');
	}
	$trash->purge($card->getId(), 'admin');
}
function rebuild(): array {
	global $db, $connection, $prefix;
	$before = (int)scalar($db, 'SELECT seq FROM sqlite_sequence WHERE name = ?', [$prefix . 'kanso_cards']);
	$schema = new SchemaWrapper($connection);
	$estimate = $schema->getTable('kanso_cards')->getColumn('estimate');
	$estimate->setLength($estimate->getLength() === 32 ? 64 : 32);
	$connection->migrateToSchema($schema->getWrappedSchema());
	$after = (int)scalar($db, 'SELECT seq FROM sqlite_sequence WHERE name = ?', [$prefix . 'kanso_cards']);
	return ['identityBefore' => $before, 'identityAfter' => $after, 'survivingMaxId' => (int)scalar($db, 'SELECT COALESCE(MAX(id), 0) FROM *PREFIX*kanso_cards')];
}
function lookups(int $boardId, string $ref, int $oldId, string $uid = 'admin'): array {
	global $cards, $container, $aliases;
	$session = $container->get(IUserSession::class);
	$saved = $session->getUser();
	$session->setUser($container->get(IUserManager::class)->get($uid));
	try {
		try {
			$resolved = $cards->findByRef($boardId, $ref, $uid);
			$service = $resolved?->getId();
		} catch (\Throwable $e) {
			$service = $e::class;
		}
		$human = $container->get(CardController::class)->resolveRef($boardId, $ref);
		$numeric = $container->get(CardMoveAliasController::class)->resolve($oldId);
		$alias = $aliases->findByOldId($oldId);
		return ['humanService' => $service, 'humanStatus' => $human->getStatus(), 'humanBody' => $human->getData(),
			'numericStatus' => $numeric->getStatus(), 'numericBody' => $numeric->getData(), 'aliasStillStored' => $alias !== null,
			'storedTarget' => $alias?->getTargetCardId()];
	} finally {
		$session->setUser($saved);
	}
}
function cleanup(): void {
	global $boards, $ownedBoards, $purger, $aliases, $ownedAliases, $ownedPeers, $db;
	foreach ($ownedBoards as $id) {
		try {
			$boards->delete($id, 'admin');
		} catch (DoesNotExistException) {
		}
		check($purger->purge($id), 'Owned board cleanup failed');
	}
	foreach ($ownedAliases as $id) {
		$aliases->deleteByOldId($id);
	}
	foreach ($ownedPeers as $peer) {
		$peer->delete();
	}
	$ownedBoards = $ownedAliases = $ownedPeers = [];
	check((int)scalar($db, 'SELECT COUNT(*) FROM *PREFIX*kanso_cards') === 0, 'Owned card cleanup incomplete');
}
function report(string $name, array $result): void {
	global $evidence;
	$evidence[$name] = $result;
	echo 'CASE ' . $name . ' ' . json_encode($result, JSON_THROW_ON_ERROR) . "\n";
}
function expectHuman(array $result, ?int $expectedId, string $name, bool $missingBoard = false): void {
	global $failures;
	$expectedService = $missingBoard ? DoesNotExistException::class : $expectedId;
	$expectedStatus = $expectedId === null ? 404 : 200;
	if ($result['humanService'] !== $expectedService || $result['humanStatus'] !== $expectedStatus
	 || ($expectedId !== null && ($result['humanBody']['cardId'] ?? null) !== $expectedId)
	 || ($expectedId === null && $result['humanBody'] !== ['error' => 'Not found'])) {
		$failures[] = $name . ': unexpected human reference result ' . json_encode($result, JSON_THROW_ON_ERROR);
	}
}
function peer(): string {
	global $container, $ownedPeers;
	$uid = 'alias-peer-' . bin2hex(random_bytes(5));
	$peer = $container->get(IUserManager::class)->createUser($uid, bin2hex(random_bytes(20)));
	check($peer !== false, 'Peer creation failed');
	$ownedPeers[] = $peer;
	return $uid;
}
function legacyTargetContext(int $oldId): void {
	global $db, $aliases, $prefix;
	check($db->executeStatement('UPDATE *PREFIX*kanso_card_move_aliases SET target_board_id = NULL, target_board_seq = NULL WHERE old_card_id = ?', [$oldId]) === 1,
		'Could not clear owned fixture target context');
	(new Version007600Date20261112000000($db))->postSchemaChange(new \OC\Migration\NullOutput(), static fn () => null, ['tablePrefix' => $prefix]);
	$alias = $aliases->findByOldId($oldId);
	check($alias !== null && $alias->getTargetBoardId() === null && $alias->getTargetBoardSeq() === null,
		'Upgrade inferred unknown legacy target identity from a current numeric row');
}

try {
	rebuild();
	[$s, $ss] = fixture('SURV');
	[$t, $ts] = fixture('TGT');
	[$u, $us] = fixture('OTHER');
	$source = $cards->create($ss->getId(), 'Original', 'admin');
	$target = moveCard($source, $ts);
	purgeCard($source);
	$shape = rebuild();
	$replacement = $cards->create($us->getId(), 'Unrelated', 'admin');
	$observed = lookups($s->getId(), ref($s, $source), $source->getId());
	check($replacement->getId() > $target->getId() && $target->getId() > $source->getId(), 'A surviving target did not preserve the native identity floor');
	check($observed['numericStatus'] === 200 && $observed['numericBody']['cardId'] === $target->getId(), 'Surviving target lost numeric navigation');
	report('surviving-target', $shape + ['oldId' => $source->getId(), 'targetId' => $target->getId(), 'newId' => $replacement->getId()] + $observed);
	expectHuman($observed, $target->getId(), 'surviving-target');
	$uid = peer();
	$acl = $container->get(AclService::class);
	$acl->create($s->getId(), $uid, 'user', 1, 'admin');
	expectHuman(lookups($s->getId(), ref($s, $source), $source->getId(), $uid), null, 'unreadable-target');
	$grant = $acl->create($t->getId(), $uid, 'user', 1, 'admin', 'external');
	expectHuman(lookups($s->getId(), ref($s, $source), $source->getId(), $uid), $target->getId(), 'readable-target');
	$cards->update($target->getId(), null, null, null, null, null, 'admin', visibility: 'private');
	expectHuman(lookups($s->getId(), ref($s, $source), $source->getId(), $uid), null, 'private-target');
	$cards->update($target->getId(), null, null, null, null, null, 'admin', visibility: 'internal');
	expectHuman(lookups($s->getId(), ref($s, $source), $source->getId(), $uid), null, 'opposite-side-target');
	$acl->update($t->getId(), $grant->getId(), 1, 'admin', 'internal');
	expectHuman(lookups($s->getId(), ref($s, $source), $source->getId(), $uid), $target->getId(), 'same-side-target');
	cleanup();

	rebuild();
	[$s, $ss] = fixture('CHAIN');
	[$t, $ts] = fixture('MID');
	[$u, $us] = fixture('LAST');
	$source = $cards->create($ss->getId(), 'Original', 'admin');
	$mid = moveCard($source, $ts);
	$target = moveCard($mid, $us);
	purgeCard($source);
	purgeCard($mid);
	$shape = rebuild();
	$new = $cards->create($ss->getId(), 'Unrelated', 'admin');
	$observed = lookups($s->getId(), ref($s, $source), $source->getId());
	$middle = lookups($t->getId(), ref($t, $mid), $mid->getId());
	check($new->getId() > $target->getId(), 'Surviving chained target did not preserve the native identity floor');
	check($observed['numericStatus'] === 200 && $observed['numericBody']['cardId'] === $target->getId(), 'Chained numeric navigation regressed');
	report('purged-intermediate-surviving-target', $shape + ['oldId' => $source->getId(), 'intermediateId' => $mid->getId(),
		'targetId' => $target->getId(), 'newId' => $new->getId(), 'intermediateHuman' => $middle] + $observed);
	expectHuman($observed, $target->getId(), 'purged-intermediate-original');
	expectHuman($middle, $target->getId(), 'purged-intermediate-reference');
	cleanup();

	rebuild();
	[$s, $ss] = fixture('PURGED');
	[$t, $ts] = fixture('TGT');
	[$u, $us] = fixture('OTHER');
	$source = $cards->create($ss->getId(), 'Original', 'admin');
	$target = moveCard($source, $ts);
	purgeCard($target);
	$shape = rebuild();
	$replacement = $cards->create($us->getId(), 'Unrelated reused target id', 'admin');
	$observed = lookups($s->getId(), ref($s, $source), $source->getId());
	check($shape['identityAfter'] < $shape['identityBefore'] && $replacement->getId() === $target->getId(), 'Target-purge fixture did not reproduce native id reuse');
	report('purged-target-id-reused', $shape + ['oldId' => $source->getId(), 'purgedTargetId' => $target->getId(), 'newId' => $replacement->getId()] + $observed);
	expectHuman($observed, null, 'purged-target-id-reused');
	cleanup();

	rebuild();
	[$s, $ss] = fixture('SAME');
	[$t, $ts] = fixture('TGT');
	$source = $cards->create($ss->getId(), 'Original', 'admin');
	$target = moveCard($source, $ts);
	purgeCard($target);
	$shape = rebuild();
	$replacement = $cards->create($ts->getId(), 'Unrelated same-board reused target id', 'admin');
	$observed = lookups($s->getId(), ref($s, $source), $source->getId());
	check($replacement->getId() === $target->getId() && $replacement->getBoardSeq() > $target->getBoardSeq(), 'Same-board target fixture did not combine native-id reuse with a fresh human number');
	report('same-board-purged-target-id-reused', $shape + ['purgedTargetId' => $target->getId(), 'newId' => $replacement->getId(),
		'purgedSequence' => $target->getBoardSeq(), 'newSequence' => $replacement->getBoardSeq()] + $observed);
	expectHuman($observed, null, 'same-board-purged-target-id-reused');
	cleanup();

	rebuild();
	[$s, $ss] = fixture('PAST');
	[$t, $ts] = fixture('TGT');
	[$u, $us] = fixture('OTHER');
	$source = $cards->create($ss->getId(), 'Legacy original', 'admin');
	$target = moveCard($source, $ts);
	purgeCard($target);
	$shape = rebuild();
	$replacement = $cards->create($us->getId(), 'Unrelated id reused before backfill', 'admin');
	check($replacement->getId() === $target->getId(), 'Legacy backfill fixture did not reuse its target id');
	legacyTargetContext($source->getId());
	$observed = lookups($s->getId(), ref($s, $source), $source->getId());
	report('unknown-legacy-target-already-reused', $shape + ['purgedTargetId' => $target->getId(), 'newId' => $replacement->getId(),
		'targetContextAfterBackfill' => null] + $observed);
	expectHuman($observed, null, 'unknown-legacy-target-already-reused');
	cleanup();

	rebuild();
	[$s, $ss] = fixture('PAST');
	[$t, $ts] = fixture('TGT');
	$source = $cards->create($ss->getId(), 'Legacy original', 'admin');
	$oldRef = ref($s, $source);
	$target = moveCard($source, $ts);
	legacyTargetContext($source->getId());
	$unknown = lookups($s->getId(), $oldRef, $source->getId());
	expectHuman($unknown, null, 'unknown-legacy-target-still-present');
	check($unknown['numericStatus'] === 200 && $unknown['numericBody']['cardId'] === $target->getId(), 'Unknown legacy context changed existing numeric navigation');
	$s = $boards->update($s->getId(), null, null, null, 'admin', prefix: 'NOW');
	$trash->restore($source->getId(), 'admin');
	$restored = lookups($s->getId(), ref($s, $source), $source->getId());
	expectHuman($restored, $source->getId(), 'restored-legacy-original-current-prefix');
	expectHuman(lookups($s->getId(), $oldRef, $source->getId()), null, 'restored-legacy-original-old-prefix');
	report('unknown-legacy-target-still-present', ['targetContextAfterBackfill' => null, 'unknown' => $unknown, 'restored' => $restored]);
	cleanup();

	rebuild();
	[$s, $ss] = fixture('EMPTY');
	[$t, $ts] = fixture('TGT');
	[$u, $us] = fixture('OTHER');
	$source = $cards->create($ss->getId(), 'Original', 'admin');
	$target = moveCard($source, $ts);
	purgeCard($source);
	purgeCard($target);
	$shape = rebuild();
	$replacement = $cards->create($us->getId(), 'Unrelated reused original id', 'admin');
	$observed = lookups($s->getId(), ref($s, $source), $source->getId());
	check($shape['identityAfter'] === 0 && $replacement->getId() === $source->getId(), 'Original-purge fixture did not reproduce native id reuse');
	report('purged-original-id-reused', $shape + ['oldId' => $source->getId(), 'purgedTargetId' => $target->getId(), 'newId' => $replacement->getId()] + $observed);
	expectHuman($observed, null, 'purged-original-id-reused');
	cleanup();

	rebuild();
	[$s, $ss] = fixture('CHAIN');
	[$t, $ts] = fixture('MID');
	[$u, $us] = fixture('LAST');
	[$other, $otherStack] = fixture('OTHER');
	$source = $cards->create($ss->getId(), 'Original', 'admin');
	$mid = moveCard($source, $ts);
	$target = moveCard($mid, $us);
	purgeCard($source);
	purgeCard($mid);
	purgeCard($target);
	$shape = rebuild();
	check($shape['identityAfter'] === 0, 'Purged chain did not lose its native identity floor');
	$reused = [];
	foreach ([$source, $mid, $target] as $oldCard) {
		$replacement = $cards->create($otherStack->getId(), 'Unrelated reused chain id', 'admin');
		check($replacement->getId() === $oldCard->getId(), 'Purged chain fixture did not reuse the next expected identity');
		$originalLookup = lookups($s->getId(), ref($s, $source), $source->getId());
		$middleLookup = lookups($t->getId(), ref($t, $mid), $mid->getId());
		$reused[] = ['newId' => $replacement->getId(), 'originalHuman' => $originalLookup, 'intermediateHuman' => $middleLookup];
		expectHuman($originalLookup, null, 'purged-chain-original-after-reuse-' . $replacement->getId());
		expectHuman($middleLookup, null, 'purged-chain-middle-after-reuse-' . $replacement->getId());
	}
	report('purged-terminal-chain-ids-reused', $shape + ['steps' => $reused]);
	cleanup();

	rebuild();
	[$s, $ss] = fixture('CHAIN');
	[$t, $ts] = fixture('MID');
	[$u, $us] = fixture('LAST');
	[$other, $otherStack] = fixture('OTHER');
	[$onward, $onwardStack] = fixture('NEW');
	$source = $cards->create($ss->getId(), 'Original', 'admin');
	$mid = moveCard($source, $ts);
	$target = moveCard($mid, $us);
	purgeCard($source);
	purgeCard($mid);
	purgeCard($target);
	$shape = rebuild();
	$cards->create($otherStack->getId(), 'Unrelated reused original id', 'admin');
	$replacement = $cards->create($otherStack->getId(), 'Unrelated reused intermediate id', 'admin');
	check($replacement->getId() === $mid->getId(), 'Onward-move fixture did not reuse its intermediate id');
	$unrelatedTarget = moveCard($replacement, $onwardStack);
	check($unrelatedTarget->getId() === $target->getId(), 'Onward-move fixture did not reuse its terminal id');
	$observed = lookups($s->getId(), ref($s, $source), $source->getId());
	report('reused-intermediate-moved-onward', $shape + ['reusedIntermediateId' => $replacement->getId(),
		'unrelatedTargetId' => $unrelatedTarget->getId()] + $observed);
	expectHuman($observed, null, 'reused-intermediate-moved-onward');
	cleanup();

	rebuild();
	[$s, $ss] = fixture('OLD');
	[$t, $ts] = fixture('TGT');
	[$u, $us] = fixture('OTHER');
	$source = $cards->create($ss->getId(), 'Restored original', 'admin');
	$oldRef = ref($s, $source);
	$target = moveCard($source, $ts);
	$s = $boards->update($s->getId(), null, null, null, 'admin', prefix: 'NOW');
	$trash->restore($source->getId(), 'admin');
	$restored = lookups($s->getId(), ref($s, $source), $source->getId());
	$old = lookups($s->getId(), $oldRef, $source->getId());
	expectHuman($restored, $source->getId(), 'restored-original-current-prefix');
	expectHuman($old, null, 'restored-original-canceled-alias');
	check($aliases->findByOldId($source->getId()) === null, 'Restoring the original retained its move alias');
	purgeCard($source);
	purgeCard($target);
	$shape = rebuild();
	$replacement = $cards->create($us->getId(), 'Unrelated after canceled alias', 'admin');
	$afterPurge = lookups($s->getId(), $oldRef, $source->getId());
	expectHuman($afterPurge, null, 'canceled-alias-after-original-id-reuse');
	report('restored-original-cancels-alias', $shape + ['restored' => $restored, 'oldPrefix' => $old, 'newId' => $replacement->getId(), 'afterPurge' => $afterPurge]);
	cleanup();

	rebuild();
	[$s, $ss] = fixture('DELETED');
	[$t, $ts] = fixture('TGT');
	[$u, $us] = fixture('OTHER');
	$source = $cards->create($ss->getId(), 'Original', 'admin');
	$target = moveCard($source, $ts);
	$boards->delete($s->getId(), 'admin');
	check($purger->purge($s->getId()), 'Source board purge failed');
	$shape = rebuild();
	$replacement = $cards->create($us->getId(), 'Unrelated', 'admin');
	$observed = lookups($s->getId(), ref($s, $source), $source->getId());
	report('deleted-source-context', $shape + ['targetId' => $target->getId(), 'newId' => $replacement->getId()] + $observed);
	expectHuman($observed, null, 'deleted-source-context', true);
	cleanup();

	rebuild();
	[$s, $ss] = fixture('COLLIDE');
	[$t, $ts] = fixture('TGT');
	$source = $cards->create($ss->getId(), 'Original', 'admin');
	$target = moveCard($source, $ts);
	purgeCard($source);
	$live = $cards->create($ss->getId(), 'Legacy collision', 'admin');
	$live->setBoardSeq($source->getBoardSeq());
	$live = $mapper->update($live);
	$visible = lookups($s->getId(), ref($s, $source), $source->getId());
	$uid = peer();
	$container->get(AclService::class)->create($s->getId(), $uid, 'user', 1, 'admin');
	$container->get(AclService::class)->create($t->getId(), $uid, 'user', 1, 'admin');
	$cards->update($live->getId(), null, null, null, null, null, 'admin', visibility: 'private');
	$hidden = lookups($s->getId(), ref($s, $source), $source->getId(), $uid);
	report('live-source-collision', ['targetId' => $target->getId(), 'liveId' => $live->getId(), 'visible' => $visible, 'hidden' => $hidden]);
	expectHuman($visible, $live->getId(), 'visible-source-collision');
	expectHuman($hidden, null, 'hidden-source-collision');
	cleanup();
} finally {
	cleanup();
}
check($failures === [], implode("\n", $failures));
echo 'OK SQLite: ' . count($evidence) . " alias lifecycle cases; owned board, card, alias and peer fixtures removed\n";
