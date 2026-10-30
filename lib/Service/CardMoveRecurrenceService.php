<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\RecurRuleMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class CardMoveRecurrenceService {
	public function __construct(
		private RecurRuleMapper $rules,
		private StackMapper $stacks,
		private PermissionService $permissions,
		private CardVisibilityGuard $visibility,
	) {
	}

	/**
	 * @param list<Card> $sourceCards
	 * @param array<int, Card> $movedCards keyed by original card id
	 */
	public function transfer(array $sourceCards, array $movedCards, Board $sourceBoard, Board $targetBoard, int $targetStackId, string $actorUid): void {
		$sourceById = [];
		foreach ($sourceCards as $card) {
			$sourceById[$card->getId()] = $card;
		}
		if ($sourceById === []) {
			return;
		}

		$affected = [];
		foreach ($this->rules->findByBoard($sourceBoard->getId()) as $rule) {
			$templateMoved = isset($sourceById[$rule->getTemplateCardId()]);
			$lastId = $rule->getLastSpawnedAt();
			$lastMoved = $lastId !== 0 && isset($sourceById[$lastId]);
			if (!$templateMoved && !$lastMoved) {
				continue;
			}
			$affected[] = $rule;
		}
		if ($affected === []) {
			return;
		}

		$this->permissions->assertPermission($sourceBoard, $actorUid, PermissionService::PERMISSION_MANAGE);
		$this->permissions->assertPermission($targetBoard, $actorUid, PermissionService::PERMISSION_MANAGE);
		$destinationStacks = null;
		$updates = [];
		foreach ($affected as $rule) {
			$oldTemplate = $sourceById[$rule->getTemplateCardId()] ?? null;
			$lastId = $rule->getLastSpawnedAt();
			if ($oldTemplate === null || ($lastId !== 0 && !isset($sourceById[$lastId]))) {
				throw new InvalidInputException('The recurring card and its latest occurrence must move together');
			}
			$newTemplate = $movedCards[$oldTemplate->getId()] ?? null;
			if ($newTemplate === null || ($lastId !== 0 && !isset($movedCards[$lastId]))) {
				throw new InvalidInputException('The recurring card and its latest occurrence must move together');
			}
			$owner = $rule->getOwner();
			$needed = PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT;
			if (($this->permissions->getPermissions($targetBoard, $owner) & $needed) !== $needed
				|| !$this->visibility->isVisible($targetBoard, $newTemplate, $owner)
				|| ($lastId !== 0 && !$this->visibility->isVisible($targetBoard, $movedCards[$lastId], $owner))) {
				throw new InvalidInputException('The recurring rule owner cannot access the destination card');
			}

			$newStackId = $targetStackId;
			if ($rule->getTargetStackId() !== $oldTemplate->getStackId()) {
				try {
					$oldStack = $this->stacks->find($rule->getTargetStackId());
				} catch (DoesNotExistException $e) {
					throw new InvalidInputException('The recurring rule target column is unavailable');
				}
				if ($oldStack->getBoardId() !== $sourceBoard->getId() || $oldStack->getDeletedAt() !== 0) {
					throw new InvalidInputException('The recurring rule target column is unavailable');
				}
				$destinationStacks ??= $this->stacks->findByBoard($targetBoard->getId());
				$matches = array_values(array_filter($destinationStacks, static fn (Stack $candidate): bool
					=> $candidate->getTitle() === $oldStack->getTitle() && $candidate->getRole() === $oldStack->getRole()));
				if (count($matches) !== 1) {
					throw new InvalidInputException('The destination board needs one matching column for the recurring rule');
				}
				$newStackId = $matches[0]->getId();
			}
			$updates[] = [$rule, $newTemplate->getId(), $newStackId, $lastId === 0 ? 0 : $movedCards[$lastId]->getId()];
		}

		foreach ($updates as [$rule, $newTemplateId, $newStackId, $newLastId]) {
			$rule->setBoardId($targetBoard->getId());
			$rule->setTemplateCardId($newTemplateId);
			$rule->setTargetStackId($newStackId);
			$rule->setLastSpawnedAt($newLastId);
			$this->rules->update($rule);
		}
	}
}
