<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCP\IGroupManager;
use OCP\Notification\IManager;

/**
 * Targeted (single-user) Nextcloud notifications - the delivery leg that the
 * board-scoped {@see ChangeNotifier} (realtime fan-out to everyone) cannot
 * provide. Thin wrapper over the NC notification manager, keyed by a small set
 * of string subjects parsed back by {@see \OCA\Kanso\Notification\Notifier}.
 *
 * v1 subject: `card_assigned`. A user is never notified of their own action.
 * The reverse action dismisses the matching notification so a stale "assigned
 * to you" doesn't linger after an unassign. The `card_due` / `card_due_soon`
 * reminder subjects are actor-less system events (no self-suppression).
 */
class NotificationService {
	public const SUBJECT_CARD_ASSIGNED = 'card_assigned';
	public const SUBJECT_CARD_COMMENT = 'card_comment';
	public const SUBJECT_CARD_MENTIONED = 'card_mentioned';
	public const SUBJECT_CARD_REVIEW_REQUESTED = 'card_review_requested';
	public const SUBJECT_BOARD_ACTIVITY = 'board_activity';
	public const SUBJECT_CARD_DUE = 'card_due';
	public const SUBJECT_CARD_DUE_SOON = 'card_due_soon';
	public const SUBJECT_STEP_ASSIGNED = 'step_assigned';
	public const SUBJECT_CARD_REMINDER = 'card_reminder';
	public const SUBJECT_BACKUP_OK = 'backup_ok';
	public const SUBJECT_BACKUP_FAILED = 'backup_failed';
	public const OBJECT_CARD = 'card';
	public const OBJECT_CHECKLIST_ITEM = 'checklist_item';
	public const OBJECT_BACKUP = 'backup';

	/**
	 * The single object id every backup-run notification is filed under. It is a
	 * constant, not a per-run id, and that is the whole superseding mechanism:
	 * app + user + this object identifies "the backup notification", so the next
	 * run can mark the previous one processed before posting its own. A nightly
	 * failing backup therefore leaves ONE unread bell entry, not 365.
	 */
	public const OBJECT_BACKUP_RUN = 'run';

	public function __construct(
		private IManager $manager,
		private IGroupManager $groupManager,
	) {
	}

	/**
	 * Notifies $targetUid that $actorUid assigned them to the card. No-op when
	 * the actor assigns themselves.
	 */
	public function notifyCardAssigned(int $cardId, string $targetUid, string $actorUid): void {
		if ($targetUid === $actorUid) {
			return;
		}

		$notification = $this->manager->createNotification();
		$notification->setApp('kanso')
			->setUser($targetUid)
			->setDateTime((new \DateTime())->setTimestamp(time()))
			->setObject(self::OBJECT_CARD, (string)$cardId)
			->setSubject(self::SUBJECT_CARD_ASSIGNED, ['actor' => $actorUid, 'cardId' => $cardId]);

		$this->manager->notify($notification);
	}

	/**
	 * Notifies $targetUid that $actorUid commented on a card they watch. No-op
	 * when the commenter is the target (you don't get notified of your own
	 * comment even though commenting auto-subscribes you).
	 */
	public function notifyCardComment(int $cardId, string $targetUid, string $actorUid): void {
		if ($targetUid === $actorUid) {
			return;
		}

		$notification = $this->manager->createNotification();
		$notification->setApp('kanso')
			->setUser($targetUid)
			->setDateTime((new \DateTime())->setTimestamp(time()))
			->setObject(self::OBJECT_CARD, (string)$cardId)
			->setSubject(self::SUBJECT_CARD_COMMENT, ['actor' => $actorUid, 'cardId' => $cardId]);

		$this->manager->notify($notification);
	}

	/**
	 * Notifies $targetUid that $actorUid mentioned them in a card comment or
	 * description. A targeted bell distinct from the comment fan-out, so a
	 * mentioned user is pinged even when they weren't already watching the card.
	 * No-op when a user mentions themselves.
	 */
	public function notifyCardMentioned(int $cardId, string $targetUid, string $actorUid): void {
		if ($targetUid === $actorUid) {
			return;
		}

		$notification = $this->manager->createNotification();
		$notification->setApp('kanso')
			->setUser($targetUid)
			->setDateTime((new \DateTime())->setTimestamp(time()))
			->setObject(self::OBJECT_CARD, (string)$cardId)
			->setSubject(self::SUBJECT_CARD_MENTIONED, ['actor' => $actorUid, 'cardId' => $cardId]);

		$this->manager->notify($notification);
	}

	/**
	 * Notifies $targetUid that $actorUid requested a review from them. No-op
	 * when the actor requests a review from themselves.
	 */
	public function notifyReviewRequested(int $cardId, string $targetUid, string $actorUid): void {
		if ($targetUid === $actorUid) {
			return;
		}

		$notification = $this->manager->createNotification();
		$notification->setApp('kanso')
			->setUser($targetUid)
			->setDateTime((new \DateTime())->setTimestamp(time()))
			->setObject(self::OBJECT_CARD, (string)$cardId)
			->setSubject(self::SUBJECT_CARD_REVIEW_REQUESTED, ['actor' => $actorUid, 'cardId' => $cardId]);

		$this->manager->notify($notification);
	}

	/**
	 * Notifies $targetUid that $actorUid created a card on a board they watch.
	 * No-op when the creator is the target (you don't get notified of your own
	 * card even though board activity fans out to the other watchers). The
	 * notification links to the new card.
	 */
	public function notifyBoardActivity(int $cardId, string $targetUid, string $actorUid): void {
		if ($targetUid === $actorUid) {
			return;
		}

		$notification = $this->manager->createNotification();
		$notification->setApp('kanso')
			->setUser($targetUid)
			->setDateTime((new \DateTime())->setTimestamp(time()))
			->setObject(self::OBJECT_CARD, (string)$cardId)
			->setSubject(self::SUBJECT_BOARD_ACTIVITY, ['actor' => $actorUid, 'cardId' => $cardId]);

		$this->manager->notify($notification);
	}

	/**
	 * Notifies $targetUid that a card they assign/watch is due. Actor-less: the
	 * reminder is a system event fired by the due-reminder cron
	 * ({@see \OCA\Kanso\Service\DueReminderService}), so there is no actor to skip
	 * and no "you did this" self-suppression. $daysBefore selects the fixed
	 * reminder: 0 = at due time, 1 = one day before. The two use distinct
	 * subjects so the "1 day before" notification is not dismissed/overwritten by
	 * the later at-due one (both can land for the same card).
	 */
	public function notifyCardDue(int $cardId, string $targetUid, int $daysBefore): void {
		$subject = $daysBefore >= 1
			? self::SUBJECT_CARD_DUE_SOON
			: self::SUBJECT_CARD_DUE;

		$notification = $this->manager->createNotification();
		$notification->setApp('kanso')
			->setUser($targetUid)
			->setDateTime((new \DateTime())->setTimestamp(time()))
			->setObject(self::OBJECT_CARD, (string)$cardId)
			->setSubject($subject, ['cardId' => $cardId]);

		$this->manager->notify($notification);
	}

	/**
	 * Fires a personal, one-shot "remind me" (#3816) for $targetUid - the user
	 * who set the reminder IS the recipient (self-notification is the whole
	 * point, so there is no self-suppression). Actor-less system event, delivered
	 * by the personal-reminder cron ({@see \OCA\Kanso\Service\ReminderService}).
	 * The optional $commentId rides in the subject parameters so the deep link
	 * can point at the specific comment the reminder was about.
	 */
	public function notifyCardReminder(int $cardId, string $targetUid, ?int $commentId = null): void {
		$notification = $this->manager->createNotification();
		$notification->setApp('kanso')
			->setUser($targetUid)
			->setDateTime((new \DateTime())->setTimestamp(time()))
			->setObject(self::OBJECT_CARD, (string)$cardId)
			->setSubject(self::SUBJECT_CARD_REMINDER, [
				'cardId' => $cardId,
				'commentId' => $commentId,
			]);

		$this->manager->notify($notification);
	}

	/**
	 * Notifies $targetUid that $actorUid assigned them a checklist step
	 * (#3745). Keyed by the ITEM id (not the card) so several steps of one
	 * card notify - and dismiss - independently; the card id rides in the
	 * subject parameters for render-time resolution and the deep link. No-op
	 * when the actor assigns themselves.
	 */
	public function notifyStepAssigned(int $itemId, int $cardId, string $targetUid, string $actorUid): void {
		if ($targetUid === $actorUid) {
			return;
		}

		$notification = $this->manager->createNotification();
		$notification->setApp('kanso')
			->setUser($targetUid)
			->setDateTime((new \DateTime())->setTimestamp(time()))
			->setObject(self::OBJECT_CHECKLIST_ITEM, (string)$itemId)
			->setSubject(self::SUBJECT_STEP_ASSIGNED, ['actor' => $actorUid, 'cardId' => $cardId]);

		$this->manager->notify($notification);
	}

	/**
	 * Tells the instance's admins how a scheduled backup run went (#161).
	 *
	 * RECIPIENTS are resolved here, deliberately, rather than taken from an
	 * ambient current user: a backup runs on cron, where there IS no logged-in
	 * user (the same empty-actor condition that makes its file-activity rows
	 * render as a deleted account). So the audience is stated explicitly - every
	 * member of the `admin` group, i.e. exactly the people who can open the
	 * backup settings and fix a broken target path. An instance whose admin
	 * group is somehow empty simply gets no notification; there is no fallback
	 * to "some user", because guessing a recipient for an instance-wide event is
	 * how a private failure message ends up in a stranger's bell.
	 *
	 * The event is ACTOR-LESS for the same reason. {@see \OCA\Kanso\Notification\Notifier}
	 * renders these two subjects without an {actor} placeholder and without
	 * touching a card, which is why it must branch on them before its card
	 * lookup.
	 *
	 * Each recipient's previous backup notification is marked processed first,
	 * so a backup that fails every night supersedes itself instead of stacking
	 * unbounded unread entries. {@see OBJECT_BACKUP_RUN} explains the keying.
	 *
	 * @param bool $ok whether the run succeeded
	 * @param string $message the run's own summary line, as shown in the panel
	 */
	public function notifyBackupResult(bool $ok, string $message): void {
		$admins = $this->groupManager->get('admin')?->getUsers() ?? [];
		if ($admins === []) {
			return;
		}

		$subject = $ok ? self::SUBJECT_BACKUP_OK : self::SUBJECT_BACKUP_FAILED;
		foreach ($admins as $admin) {
			$uid = $admin->getUID();

			// Supersede: drop this recipient's previous backup notification
			// (either subject) before posting the new one.
			$previous = $this->manager->createNotification();
			$previous->setApp('kanso')
				->setUser($uid)
				->setObject(self::OBJECT_BACKUP, self::OBJECT_BACKUP_RUN);
			$this->manager->markProcessed($previous);

			$notification = $this->manager->createNotification();
			$notification->setApp('kanso')
				->setUser($uid)
				->setDateTime((new \DateTime())->setTimestamp(time()))
				->setObject(self::OBJECT_BACKUP, self::OBJECT_BACKUP_RUN)
				->setSubject($subject, ['message' => $message]);

			$this->manager->notify($notification);
		}
	}

	/**
	 * Dismisses a previously-sent "step assigned to you" notification (e.g.
	 * after the step is unassigned or handed to someone else). Idempotent -
	 * dismissing an absent notification is a no-op at the manager level.
	 */
	public function dismissStepAssigned(int $itemId, string $targetUid): void {
		$notification = $this->manager->createNotification();
		$notification->setApp('kanso')
			->setUser($targetUid)
			->setObject(self::OBJECT_CHECKLIST_ITEM, (string)$itemId)
			->setSubject(self::SUBJECT_STEP_ASSIGNED);

		$this->manager->markProcessed($notification);
	}

	/**
	 * Dismisses a previously-sent "assigned to you" notification for the card
	 * (e.g. after the user is unassigned). Idempotent - dismissing an absent
	 * notification is a no-op at the manager level.
	 */
	public function dismissCardAssigned(int $cardId, string $targetUid): void {
		$notification = $this->manager->createNotification();
		$notification->setApp('kanso')
			->setUser($targetUid)
			->setObject(self::OBJECT_CARD, (string)$cardId)
			->setSubject(self::SUBJECT_CARD_ASSIGNED);

		$this->manager->markProcessed($notification);
	}

	/**
	 * Dismisses a previously-sent "review requested" notification for the card
	 * (e.g. after the request is withdrawn or the reviewer has acted).
	 * Idempotent - dismissing an absent notification is a no-op.
	 */
	public function dismissReviewRequested(int $cardId, string $targetUid): void {
		$notification = $this->manager->createNotification();
		$notification->setApp('kanso')
			->setUser($targetUid)
			->setObject(self::OBJECT_CARD, (string)$cardId)
			->setSubject(self::SUBJECT_CARD_REVIEW_REQUESTED);

		$this->manager->markProcessed($notification);
	}

	/**
	 * Removes EVERY Kanso notification still pointing at the given objects, for
	 * every recipient and every subject. This is the notification half of a
	 * board purge ({@see BoardPurgeService}): the objects are about to stop
	 * existing, so no user may be left holding a bell entry for them.
	 *
	 * Unlike the targeted dismissals above, this deliberately sets NO user and
	 * NO subject. The notification backend builds its predicate from the fields
	 * that ARE set and leaves the rest out of the WHERE clause, so app + object
	 * type + object id is the whole scope - which is also the safety property:
	 * the app is pinned to `kanso` and each id comes from the purged board's own
	 * object set, so no other app's notification and no surviving board's
	 * notification can match. Widening this (dropping the app, or passing ids
	 * that were not read from the board being purged) would delete other
	 * people's notifications, so both are non-negotiable.
	 *
	 * One call per object, because {@see IManager::markProcessed()} takes one
	 * notification at a time and there is no bulk form. A purge is a background
	 * job, so the round-trips are affordable - and the alternative, a DELETE
	 * written by hand against `oc_notifications`, is an unsupported write into
	 * another app's table that would rot at the next Nextcloud upgrade.
	 *
	 * Idempotent: an object with no notifications matches nothing.
	 *
	 * @param string $objectType one of the `OBJECT_*` constants
	 * @param list<int> $ids
	 */
	public function dismissAllForObjects(string $objectType, array $ids): void {
		foreach ($ids as $id) {
			$notification = $this->manager->createNotification();
			$notification->setApp('kanso')
				->setObject($objectType, (string)$id);

			$this->manager->markProcessed($notification);
		}
	}
}
