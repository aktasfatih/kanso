<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardFileLink;
use OCA\Kanso\Db\CardFileLinkMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeDetailMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\IRootFolder;

/**
 * Live links from a card to Nextcloud Files nodes (KANSO-307). The sibling of
 * {@see CardAttachmentService::attachFromFileNode()}, which COPIES the bytes;
 * a link copies nothing and keeps pointing at the node, so later edits to the
 * file show up and there is one source of truth for it.
 *
 * Access model (owner ruling on the card):
 *  - LINKING requires board EDIT and that the linker can read the node through
 *    their OWN userfolder ({@see IRootFolder::getUserFolder()} +
 *    `getById()`), exactly like the copy flow - a client-supplied id the actor
 *    cannot reach is "not found". Only the bare filename is snapshotted, never
 *    the linker's path, so the board learns nothing about their folder layout.
 *  - LISTING is gated by board READ + card visibility only. There is
 *    deliberately NO per-viewer read check against Files: a member who cannot
 *    read the node still sees the filename chip, and opening it goes to NC's
 *    `/f/<fileid>` permalink, where Files' own ACL denies. That keeps the list
 *    a plain DB read (no IRootFolder call per render) and the board payload
 *    untouched - links load only when a card is opened.
 *  - UNLINKING requires board EDIT; the node is not consulted (a link to a
 *    deleted or unshared file must still be removable).
 *
 * Add/remove reuse the card's ENTITY_CARD / ACTION_UPDATE change row (so delta
 * sync and realtime pick it up unchanged) with VERB_FILE_LINKED /
 * VERB_FILE_UNLINKED and the filename in `kanso_change_details`, mirroring the
 * attachment verbs, so the Activity feed can say who linked or unlinked what.
 *
 * Card copy and move-to-board do not carry links, consistent with attachments.
 */
class CardFileLinkService {
	/**
	 * Cap on a change-detail string, matching the other services that write the
	 * side table.
	 */
	private const MAX_DETAIL_LENGTH = 10000;

	public function __construct(
		private CardFileLinkMapper $fileLinkMapper,
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private PermissionService $permissionService,
		private CardVisibilityGuard $visibilityGuard,
		private ChangeNotifier $changeNotifier,
		private ChangeDetailMapper $changeDetailMapper,
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * A card's file links (DB rows only - see the class doc for why there is no
	 * per-viewer Files lookup). Requires READ.
	 *
	 * @return CardFileLink[]
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not read the board or see the card
	 */
	public function listForCard(int $cardId, string $actorUid): array {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_READ);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		return $this->fileLinkMapper->findByCard($cardId);
	}

	/**
	 * Links a node the actor can read to the card. Idempotent on (card, file).
	 * Requires EDIT.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board or see the card
	 * @throws InvalidInputException if the actor cannot read the node, or the card is at its link cap
	 */
	public function addLink(int $cardId, int $fileId, string $actorUid): CardFileLink {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		// Resolve from the ACTOR'S OWN userfolder (same as the copy flow): the
		// actor can only link a node they can already read. First match wins (a
		// node can appear under several mount points for one user). Files only,
		// matching the picker - a folder id is refused like an unreadable one.
		try {
			$userFolder = $this->rootFolder->getUserFolder($actorUid);
		} catch (\Throwable $e) {
			throw new InvalidInputException('File not found');
		}
		$node = $userFolder->getById($fileId)[0] ?? null;
		if (!$node instanceof File) {
			throw new InvalidInputException('File not found');
		}

		if ($this->fileLinkMapper->countByCard($cardId) >= CardFileLinkMapper::MAX_PER_CARD) {
			// Re-linking a file that is already on a full card is still the
			// documented idempotent no-op, not a cap error.
			$existing = $this->findExistingLink($cardId, $fileId);
			if ($existing !== null) {
				return $existing;
			}
			throw new InvalidInputException(
				'A card may have at most ' . CardFileLinkMapper::MAX_PER_CARD . ' file links'
			);
		}

		$link = new CardFileLink();
		$link->setCardId($cardId);
		$link->setFileId($fileId);
		// Bare name only (basename + control-char strip) - never getPath(), which
		// would expose the linker's own folder structure to every board member.
		$link->setName(AttachmentSanitizer::filename($node->getName()));
		$link->setMime(mb_substr($node->getMimetype(), 0, 255));
		$link->setLinkedBy($actorUid);
		$link->setCreatedAt(time());

		try {
			$link = $this->fileLinkMapper->insert($link);
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			// Same file already linked (double click / concurrent add) - return
			// the existing row, and record no second change for it.
			return $this->findExistingLink($cardId, $fileId) ?? throw $e;
		}

		$this->recordChange($card, $link->getName(), $actorUid, Change::VERB_FILE_LINKED);

		return $link;
	}

	private function findExistingLink(int $cardId, int $fileId): ?CardFileLink {
		foreach ($this->fileLinkMapper->findByCard($cardId) as $link) {
			if ($link->getFileId() === $fileId) {
				return $link;
			}
		}
		return null;
	}

	/**
	 * Removes a file link from the card. Requires EDIT. The node itself is not
	 * consulted - a link to a deleted/unshared file must still be removable.
	 *
	 * @throws DoesNotExistException if the card/board/link does not exist, is deleted, or the link is on another card
	 * @throws NotPermittedException if the actor may not edit the board or see the card
	 */
	public function deleteLink(int $cardId, int $linkId, string $actorUid): void {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		// IDOR guard: a link id from another card is "not found on THIS card".
		$link = $this->fileLinkMapper->find($linkId);
		if ($link->getCardId() !== $cardId) {
			throw new DoesNotExistException('File link ' . $linkId . ' is not on card ' . $cardId);
		}
		$this->fileLinkMapper->delete($link);

		$this->recordChange($card, $link->getName(), $actorUid, Change::VERB_FILE_UNLINKED);
	}

	/**
	 * Appends the card's change row for a link/unlink and records the filename
	 * in `kanso_change_details` - `to` when linked, `from` when unlinked, the
	 * same sides the attachment verbs use. Still ENTITY_CARD / ACTION_UPDATE, so
	 * delta sync and the ETag (which key on entity + action) are unchanged.
	 */
	private function recordChange(Card $card, string $name, string $actorUid, int $verb): void {
		$change = $this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			(int)$card->getId(),
			Change::ACTION_UPDATE,
			$actorUid,
			verb: $verb,
		);

		$label = mb_substr($name, 0, self::MAX_DETAIL_LENGTH);
		$linked = $verb === Change::VERB_FILE_LINKED;
		$this->changeDetailMapper->insertDetail(
			$change->getId(),
			$linked ? null : $label,
			$linked ? $label : null,
		);
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
