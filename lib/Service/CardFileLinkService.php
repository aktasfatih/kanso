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

// Listing deliberately skips a per-viewer Files check; Files' own ACL applies on open.
class CardFileLinkService {
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
	 * @return CardFileLink[]
	 * @throws DoesNotExistException
	 * @throws NotPermittedException
	 */
	public function listForCard(int $cardId, string $actorUid): array {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_READ);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		return $this->fileLinkMapper->findByCard($cardId);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws NotPermittedException
	 * @throws InvalidInputException
	 */
	public function addLink(int $cardId, int $fileId, string $actorUid): CardFileLink {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

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
			// Re-linking on a full card is still an idempotent no-op, not a cap error.
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
		// Bare name only, never getPath() (privacy).
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
	 * @throws DoesNotExistException
	 * @throws NotPermittedException
	 */
	public function deleteLink(int $cardId, int $linkId, string $actorUid): void {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		// IDOR guard: a link id from another card is "not found".
		$link = $this->fileLinkMapper->find($linkId);
		if ($link->getCardId() !== $cardId) {
			throw new DoesNotExistException('File link ' . $linkId . ' is not on card ' . $cardId);
		}
		$this->fileLinkMapper->delete($link);

		$this->recordChange($card, $link->getName(), $actorUid, Change::VERB_FILE_UNLINKED);
	}

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
	 * @throws DoesNotExistException
	 */
	private function loadCard(int $id): Card {
		$card = $this->cardMapper->find($id);
		if ($card->getDeletedAt() > 0) {
			throw new DoesNotExistException('Card ' . $id . ' is deleted');
		}
		return $card;
	}

	/**
	 * @throws DoesNotExistException
	 */
	private function loadBoard(int $boardId): Board {
		$board = $this->boardMapper->find($boardId);
		if ($board->getDeletedAt() > 0) {
			throw new DoesNotExistException('Board ' . $boardId . ' is deleted');
		}
		return $board;
	}
}
