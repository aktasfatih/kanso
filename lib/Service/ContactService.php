<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardContactMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IUserManager;

/**
 * Card contact links (#3530): attach a Nextcloud Contacts entry to a card as a
 * READ-ONLY reference for non-user stakeholders. Mirrors {@see AssigneeService}
 * - linking needs EDIT on the card's board, mutations append a card-targeted
 * row to the `kanso_changes` log, and both operations are idempotent (no-op
 * writes no change row).
 *
 * The Contacts app is an OPTIONAL dependency (no hard requirement in info.xml,
 * precedent {@see DeckReader}). It is feature-detected via
 * IAppManager::isEnabledForUser('contacts'); when absent, search returns an
 * empty list and mutations throw ContactsUnavailableException (mapped to a
 * clean 4xx, never a 500). The submitted contact is VALIDATED against the
 * server-side Contacts API before insert, and its display name is stored
 * DENORMALIZED (a snapshot - no editing, no sync).
 */
class ContactService {
	private const APP_CONTACTS = 'contacts';

	/** Result cap for the picker payload, mirroring ParticipantService. */
	private const SEARCH_LIMIT = 25;

	public function __construct(
		private CardContactMapper $cardContactMapper,
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private ChangeNotifier $changeNotifier,
		private PermissionService $permissionService,
		private IAppManager $appManager,
		private IContactsManager $contactsManager,
		private IUserManager $userManager,
		private CardVisibilityGuard $visibilityGuard,
	) {
	}

	/**
	 * Whether the Contacts app is enabled for the user. When false the picker
	 * must hide and the endpoints degrade gracefully (empty / 4xx, never 500).
	 */
	public function isAvailable(string $uid): bool {
		return $this->appManager->isEnabledForUser(self::APP_CONTACTS, $this->userManager->get($uid))
			&& $this->contactsManager->isEnabled();
	}

	/**
	 * Searches the user's address books for the picker. Requires READ on the
	 * board (the picker is board-scoped, same gate as the assignee picker).
	 * Returns [] when Contacts is unavailable so the picker degrades quietly.
	 *
	 * Only real contacts are returned: the system address book (which mirrors
	 * NC users - those belong in the assignee picker, not here) is filtered out,
	 * as are entries without a stable UID. The result is capped.
	 *
	 * @return list<array{contactUri: string, displayName: string, email: ?string}>
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the user may not read the board
	 */
	public function search(int $boardId, string $uid, ?string $query): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_READ);

		if (!$this->isAvailable($uid)) {
			return [];
		}

		$pattern = $query !== null ? trim($query) : '';
		$results = $this->contactsManager->search(
			$pattern,
			['FN', 'EMAIL'],
			['limit' => self::SEARCH_LIMIT * 2],
		);

		$out = [];
		foreach ($results as $entry) {
			$contact = $this->normalize($entry);
			if ($contact === null) {
				continue;
			}
			$out[] = $contact;
			if (count($out) >= self::SEARCH_LIMIT) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Links a contact to the card. Idempotent: re-linking an already linked
	 * contact is a no-op and writes no change row. The submitted URI + display
	 * name are validated against the server-side Contacts API; an unresolvable
	 * or mismatched contact is rejected (never blindly trusted from the client).
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 * @throws ContactsUnavailableException if the Contacts app is disabled
	 * @throws InvalidInputException if the contact cannot be resolved
	 */
	public function link(int $cardId, string $contactUri, string $displayName, string $actorUid): array {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		if (!$this->isAvailable($actorUid)) {
			throw new ContactsUnavailableException('Contacts app is not available');
		}

		$resolved = $this->resolveContact($contactUri);
		if ($resolved === null) {
			throw new InvalidInputException('Contact could not be resolved');
		}
		// Trust the server-resolved display name over the client-submitted one -
		// the snapshot must reflect what the address book actually holds.
		$name = $resolved['displayName'] !== '' ? $resolved['displayName'] : $displayName;

		if ($this->cardContactMapper->exists($cardId, $contactUri)) {
			return ['contactUri' => $contactUri, 'displayName' => $name];
		}

		try {
			$this->cardContactMapper->insertLink($cardId, $contactUri, $name);
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				// Concurrent PUT lost the check-then-insert race - the link
				// exists, which is the idempotent success case.
				return ['contactUri' => $contactUri, 'displayName' => $name];
			}
			throw $e;
		}

		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$cardId,
			Change::ACTION_UPDATE,
			$actorUid,
			verb: Change::VERB_CONTACT_LINKED,
		);

		return ['contactUri' => $contactUri, 'displayName' => $name];
	}

	/**
	 * Unlinks a contact from the card. Idempotent: unlinking an absent link is
	 * a no-op and writes no change row. Unlinking works even when the Contacts
	 * app is later disabled - a dangling link must always be removable.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 */
	public function unlink(int $cardId, string $contactUri, string $actorUid): void {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		if ($this->cardContactMapper->deleteLink($cardId, $contactUri) === 0) {
			return;
		}

		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$cardId,
			Change::ACTION_UPDATE,
			$actorUid,
			verb: Change::VERB_CONTACT_UNLINKED,
		);
	}

	/**
	 * Validates a submitted contact URI against the address books, returning its
	 * canonical display name, or null if it resolves to nothing. Matches on the
	 * contact's UID (the stable identifier we store as `contact_uri`).
	 *
	 * @return array{displayName: string}|null
	 */
	private function resolveContact(string $contactUri): ?array {
		if ($contactUri === '') {
			return null;
		}
		// Search the UID property for the submitted URI, then confirm an EXACT
		// match (search is a substring match). System-address-book entries (NC
		// users) are rejected - those belong in the assignee picker.
		$results = $this->contactsManager->search($contactUri, ['UID'], ['strict_search' => true]);
		foreach ($results as $entry) {
			if ((string)($entry['UID'] ?? '') !== $contactUri) {
				continue;
			}
			if (!empty($entry['isLocalSystemBook'])) {
				return null;
			}
			return ['displayName' => $this->displayName($entry)];
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $entry a Contacts search result
	 * @return array{contactUri: string, displayName: string, email: ?string}|null
	 */
	private function normalize(array $entry): ?array {
		// Skip the system address book (NC users - use the assignee picker) and
		// entries without a stable UID we could key a link on.
		if (!empty($entry['isLocalSystemBook'])) {
			return null;
		}
		$uid = (string)($entry['UID'] ?? '');
		if ($uid === '') {
			return null;
		}

		$email = null;
		if (isset($entry['EMAIL'])) {
			$email = is_array($entry['EMAIL']) ? (string)reset($entry['EMAIL']) : (string)$entry['EMAIL'];
			if ($email === '') {
				$email = null;
			}
		}

		return [
			'contactUri' => $uid,
			'displayName' => $this->displayName($entry),
			'email' => $email,
		];
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private function displayName(array $entry): string {
		$fn = trim((string)($entry['FN'] ?? ''));
		if ($fn !== '') {
			return $fn;
		}
		if (isset($entry['EMAIL'])) {
			return is_array($entry['EMAIL']) ? (string)reset($entry['EMAIL']) : (string)$entry['EMAIL'];
		}
		return (string)($entry['UID'] ?? '');
	}

	/**
	 * @throws DoesNotExistException if the card does not exist or is deleted
	 */
	private function loadCard(int $id): Card {
		$card = $this->cardMapper->find($id);
		if ($card->getDeletedAt() > 0) {
			throw new DoesNotExistException('Card ' . $id . ' is deleted');
		}
		return $card;
	}

	/**
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 */
	private function loadBoard(int $boardId): Board {
		$board = $this->boardMapper->find($boardId);
		if ($board->getDeletedAt() > 0) {
			throw new DoesNotExistException('Board ' . $boardId . ' is deleted');
		}
		return $board;
	}
}
