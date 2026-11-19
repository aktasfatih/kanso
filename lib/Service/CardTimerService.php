<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardRunningTimer;
use OCA\Kanso\Db\CardRunningTimerMapper;
use OCA\Kanso\Db\CardTimeEntry;
use OCA\Kanso\Db\CardTimeEntryMapper;
use OCA\Kanso\Db\Change;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

class CardTimerService {
	public function __construct(
		private CardRunningTimerMapper $runningTimerMapper,
		private CardTimeEntryMapper $timeEntryMapper,
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private PermissionService $permissionService,
		private CardVisibilityGuard $visibilityGuard,
		private ChangeNotifier $changeNotifier,
		private CardMutationService $cardMutation,
		private ITimeFactory $timeFactory,
	) {
	}

	public function start(int $cardId, string $actorUid, ?string $actorName = null): CardRunningTimer {
		return $this->cardMutation->run($cardId, function () use ($cardId, $actorUid, $actorName): CardRunningTimer {
			[$card, $board] = $this->editableCard($cardId, $actorUid);
			return $this->startTimer($card, $actorUid, AiActorName::normalize($board, $actorName));
		});
	}

	public function stop(int $cardId, string $actorUid): ?CardTimeEntry {
		return $this->cardMutation->run($cardId, function () use ($cardId, $actorUid): ?CardTimeEntry {
			[$card] = $this->editableCard($cardId, $actorUid);
			return $this->stopTimer($card, $actorUid, 'Tracked with timer');
		});
	}

	/** The caller has already checked the card's edit permission. */
	public function startForCard(Card $card, string $actorUid, ?string $actorName = null): CardRunningTimer {
		return $this->cardMutation->run($card->getId(), fn (): CardRunningTimer => $this->startTimer($card, $actorUid, $actorName));
	}

	/** The caller has already checked the card's edit permission. */
	public function stopForCard(Card $card, string $actorUid, ?string $note = null): ?CardTimeEntry {
		return $this->cardMutation->run($card->getId(), fn (): ?CardTimeEntry => $this->stopTimer($card, $actorUid, $note));
	}

	private function startTimer(Card $card, string $actorUid, ?string $actorName): CardRunningTimer {
		try {
			return $this->runningTimerMapper->findByCard($card->getId());
		} catch (DoesNotExistException) {
		}

		$timer = new CardRunningTimer();
		$timer->setCardId($card->getId());
		$timer->setBoardId($card->getBoardId());
		$timer->setStartedBy($actorUid);
		$timer->setStartedAt($this->timeFactory->getTime());
		$timer->setActorName($actorName);

		$timer = $this->runningTimerMapper->insert($timer);
		$this->recordChange($card, $actorUid);
		return $timer;
	}

	private function stopTimer(Card $card, string $actorUid, ?string $note): ?CardTimeEntry {
		try {
			$timer = $this->runningTimerMapper->findByCard($card->getId());
		} catch (DoesNotExistException) {
			return null;
		}

		$now = $this->timeFactory->getTime();
		$seconds = max(0, $now - $timer->getStartedAt());
		$entry = null;
		if ($this->runningTimerMapper->deleteIfCurrent($timer->getId(), $card->getId()) === 0) {
			return null;
		}
		if ($seconds > 0) {
			$entry = new CardTimeEntry();
			$entry->setCardId($card->getId());
			$entry->setBoardId($card->getBoardId());
			$entry->setSeconds($seconds);
			$entry->setNote($note);
			$entry->setCreatedBy($timer->getStartedBy());
			$entry->setActorName($timer->getActorName());
			$entry->setCreatedAt($now);
			$entry = $this->timeEntryMapper->insert($entry);
		}
		$this->recordChange($card, $actorUid);
		return $entry;
	}

	private function recordChange(Card $card, string $actorUid): void {
		$this->changeNotifier->recordChange(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$card->getId(),
			Change::ACTION_UPDATE,
			$actorUid,
		);
		$this->cardMutation->afterCommit(fn () => $this->changeNotifier->pushBoardChanged($card->getBoardId()));
	}

	/** @return array{Card, Board} */
	private function editableCard(int $cardId, string $actorUid): array {
		$card = $this->cardMapper->find($cardId);
		if ($card->getDeletedAt() > 0) {
			throw new DoesNotExistException('Card is deleted');
		}
		$board = $this->boardMapper->find($card->getBoardId());
		if ($board->getDeletedAt() > 0) {
			throw new DoesNotExistException('Board is deleted');
		}
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);
		return [$card, $board];
	}
}
