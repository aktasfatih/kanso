<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\ReviewType;
use OCA\Kanso\Db\ReviewTypeMapper;

class CardMoveReviewService {
	public function __construct(
		private CardReviewMapper $reviews,
		private ReviewTypeMapper $types,
		private PermissionService $permissions,
		private CardVisibilityGuard $visibility,
	) {
	}

	public function transfer(int $sourceCardId, int $sourceBoardId, Card $target, Board $targetBoard): void {
		$reviews = $this->reviews->findByCard($sourceCardId);
		if ($reviews === []) {
			return;
		}
		$sourceTypes = [];
		foreach ($this->types->findByBoard($sourceBoardId) as $type) {
			$sourceTypes[$type->getId()] = $type;
		}
		$targetTypes = $this->types->findByBoard($targetBoard->getId());
		$typeMap = [0 => 0];
		$usedTypes = [];
		foreach ($reviews as $review) {
			$reviewer = $review->getReviewer();
			if (($this->permissions->getPermissions($targetBoard, $reviewer) & PermissionService::PERMISSION_READ) === 0
				|| !$this->visibility->isVisible($targetBoard, $target, $reviewer)) {
				throw new InvalidInputException('A reviewer cannot access the destination card');
			}
			if ($review->getActorName() !== null && $targetBoard->getAllowAiActors() !== true) {
				throw new InvalidInputException('The destination board must allow AI actors to preserve review attribution');
			}
			$typeId = $review->getReviewTypeId();
			if (isset($typeMap[$typeId])) {
				continue;
			}
			$sourceType = $sourceTypes[$typeId] ?? null;
			if ($sourceType === null) {
				throw new InvalidInputException('A review type is missing from the source board');
			}
			$matches = array_values(array_filter($targetTypes, static fn (ReviewType $candidate): bool
				=> $candidate->getTitle() === $sourceType->getTitle()
				&& $candidate->getColor() === $sourceType->getColor()
				&& $candidate->getStage() === $sourceType->getStage()));
			if (count($matches) !== 1 || isset($usedTypes[$matches[0]->getId()])) {
				throw new InvalidInputException('The destination board needs one matching review type for "' . $sourceType->getTitle() . '"');
			}
			$typeMap[$typeId] = $matches[0]->getId();
			$usedTypes[$matches[0]->getId()] = true;
		}

		foreach ($reviews as $review) {
			$review->setCardId($target->getId());
			$review->setReviewTypeId($typeMap[$review->getReviewTypeId()]);
			$this->reviews->update($review);
		}
	}
}
