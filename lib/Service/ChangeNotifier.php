<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\AclMapper;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeMapper;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The one write path into the `kanso_changes` log. Every service mutation
 * goes through notify(), which appends the change row (the ETag / delta-sync
 * source of truth) and then best-effort broadcasts a notify_push custom
 * event so open clients refetch immediately instead of waiting for their
 * next poll.
 *
 * The push emit is strictly best-effort: notify_push may not be installed
 * (its IQueue class then does not exist), Redis may be down, a recipient
 * lookup may fail - none of that may ever break the mutation that already
 * committed. Clients fall back to polling either way.
 */
class ChangeNotifier {
	public const PUSH_MESSAGE = 'kanso_board_changed';

	private const QUEUE_CLASS = 'OCA\NotifyPush\Queue\IQueue';

	/** Lazily resolved notify_push queue; null when unavailable. */
	private ?object $queue = null;
	private bool $queueResolved = false;

	public function __construct(
		private ChangeMapper $changeMapper,
		private BoardMapper $boardMapper,
		private AclMapper $aclMapper,
		private IGroupManager $groupManager,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Appends a change row for the mutation and broadcasts it to everyone
	 * with access to the board.
	 *
	 * Convenience wrapper over {@see self::recordChange()} + {@see self::pushBoardChanged()}
	 * for the simple case: a single mutation that is NOT inside a caller-managed
	 * transaction and wants one push right away. Callers that write the change row
	 * inside a DB transaction (so the push must be deferred until after commit), or
	 * that write many change rows in one batch (so only one push should fire),
	 * should instead call {@see self::recordChange()} and {@see self::pushBoardChanged()}
	 * separately.
	 *
	 * @param int $entityType one of the Change::ENTITY_* constants
	 * @param int $action one of the Change::ACTION_* constants
	 * @param string|null $actor uid of the acting user, null for system actions
	 * @param bool $push whether to broadcast the push now. Pass false when the
	 *                   change row is written INSIDE a transaction, and call
	 *                   {@see self::pushBoardChanged()} yourself after commit - otherwise a
	 *                   client could refetch pre-commit state, or (on rollback) get an event
	 *                   for a change that never landed.
	 * @param int|null $verb one of the Change::VERB_* constants for the per-card
	 *                       Activity feed, or null (renders as a generic "updated"). Additive -
	 *                       delta-sync keys on (entity_type, action), not the verb.
	 * @return Change the inserted entry with its id set
	 * @throws \OCP\DB\Exception if inserting the change row fails
	 */
	public function notify(int $boardId, int $entityType, int $entityId, int $action, ?string $actor, bool $push = true, ?int $verb = null): Change {
		$change = $this->recordChange($boardId, $entityType, $entityId, $action, $actor, $verb);

		if ($push) {
			$this->pushBoardChanged($boardId);
		}

		return $change;
	}

	/**
	 * Appends the `kanso_changes` row for a mutation - the delta-sync / ETag
	 * source of truth - and NOTHING else. No realtime push is emitted. Use this
	 * (paired with {@see self::pushBoardChanged()}) when the change row is written
	 * inside a caller-managed transaction, or when many rows are written in one
	 * batch and only one push should fire for the board.
	 *
	 * @param int $entityType one of the Change::ENTITY_* constants
	 * @param int $action one of the Change::ACTION_* constants
	 * @param string|null $actor uid of the acting user, null for system actions
	 * @param int|null $verb one of the Change::VERB_* constants, or null
	 * @return Change the inserted entry with its id set
	 * @throws \OCP\DB\Exception if inserting the change row fails
	 */
	public function recordChange(int $boardId, int $entityType, int $entityId, int $action, ?string $actor, ?int $verb = null): Change {
		return $this->changeMapper->insertChange(
			$boardId,
			$entityType,
			$entityId,
			$action,
			$actor,
			time(),
			$verb
		);
	}

	/**
	 * Best-effort notify_push broadcast for a board - never throws. The
	 * per-participant fan-out: one `kanso_board_changed` event per board recipient.
	 * Public so a caller that deferred the push (change row written inside a
	 * transaction - see {@see \OCA\Kanso\Service\CardService::persistMove}) or that
	 * coalesced a batch (many change rows, one push - see
	 * {@see \OCA\Kanso\Service\ArchiveService::sweep}) can emit it explicitly.
	 */
	public function pushBoardChanged(int $boardId): void {
		try {
			$queue = $this->getQueue();
			if ($queue === null) {
				return;
			}
			foreach ($this->resolveRecipients($boardId) as $uid) {
				$queue->push('notify_custom', [
					'user' => $uid,
					'message' => self::PUSH_MESSAGE,
					'body' => ['boardId' => $boardId],
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->debug(
				'kanso: failed to emit notify_push event for board ' . $boardId,
				['exception' => $e]
			);
		}
	}

	/**
	 * Resolves the notify_push queue once per request. The class is
	 * referenced by name only - when the notify_push app is not installed
	 * the container lookup throws and the result is cached as unavailable.
	 */
	private function getQueue(): ?object {
		if (!$this->queueResolved) {
			// Cached for this instance's lifetime - per-request under FPM.
			// Under a persistent runtime this memo would go stale (notify_push
			// enabled mid-life stays unnoticed) and must become time-bounded.
			$this->queueResolved = true;
			try {
				$queue = $this->container->get(self::QUEUE_CLASS);
				if (is_object($queue) && method_exists($queue, 'push')) {
					$this->queue = $queue;
				}
			} catch (\Throwable) {
				// notify_push not installed - polling fallback covers this.
			}
		}
		return $this->queue;
	}

	/**
	 * Uids of everyone with access to the board: owner, user ACLs, members
	 * of group ACLs - deduplicated. Mirrors ParticipantService, but returns
	 * bare uid strings and skips display-name resolution.
	 *
	 * @return list<string>
	 */
	private function resolveRecipients(int $boardId): array {
		$board = $this->boardMapper->find($boardId);

		// Keyed by uid for deduplication.
		$uids = [$board->getOwner() => true];
		foreach ($this->aclMapper->findByBoard($boardId) as $acl) {
			if ($acl->getParticipantType() === Acl::TYPE_USER) {
				$uids[$acl->getParticipant()] = true;
			} elseif ($acl->getParticipantType() === Acl::TYPE_GROUP) {
				foreach ($this->groupManager->get($acl->getParticipant())?->getUsers() ?? [] as $user) {
					$uids[$user->getUID()] = true;
				}
			}
		}
		return array_map('strval', array_keys($uids));
	}
}
