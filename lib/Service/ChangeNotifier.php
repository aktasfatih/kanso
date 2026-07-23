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
 * lookup may fail — none of that may ever break the mutation that already
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
	 * @param int $entityType one of the Change::ENTITY_* constants
	 * @param int $action one of the Change::ACTION_* constants
	 * @param string|null $actor uid of the acting user, null for system actions
	 * @return Change the inserted entry with its id set
	 * @throws \OCP\DB\Exception if inserting the change row fails
	 */
	public function notify(int $boardId, int $entityType, int $entityId, int $action, ?string $actor): Change {
		$change = $this->changeMapper->insertChange(
			$boardId,
			$entityType,
			$entityId,
			$action,
			$actor,
			time()
		);

		$this->emitPush($boardId);

		return $change;
	}

	/**
	 * Best-effort notify_push broadcast — never throws.
	 */
	private function emitPush(int $boardId): void {
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
	 * referenced by name only — when the notify_push app is not installed
	 * the container lookup throws and the result is cached as unavailable.
	 */
	private function getQueue(): ?object {
		if (!$this->queueResolved) {
			// Cached for this instance's lifetime — per-request under FPM.
			// Under a persistent runtime this memo would go stale (notify_push
			// enabled mid-life stays unnoticed) and must become time-bounded.
			$this->queueResolved = true;
			try {
				$queue = $this->container->get(self::QUEUE_CLASS);
				if (is_object($queue) && method_exists($queue, 'push')) {
					$this->queue = $queue;
				}
			} catch (\Throwable) {
				// notify_push not installed — polling fallback covers this.
			}
		}
		return $this->queue;
	}

	/**
	 * Uids of everyone with access to the board: owner, user ACLs, members
	 * of group ACLs — deduplicated. Mirrors ParticipantService, but returns
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
