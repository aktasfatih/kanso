<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\CardField;
use OCA\Kanso\Db\CardFieldMapper;
use OCA\Kanso\Db\CardFieldValue;
use OCA\Kanso\Db\CardFieldValueMapper;

class CardMoveFieldsService {
	public function __construct(
		private CardFieldMapper $fields,
		private CardFieldValueMapper $values,
	) {
	}

	public function copy(int $sourceCardId, int $targetCardId, int $sourceBoardId, int $targetBoardId): void {
		$values = $this->values->findByCard($sourceCardId);
		if ($values === []) {
			return;
		}

		$sourceFields = [];
		foreach ($this->fields->findByBoard($sourceBoardId) as $field) {
			$sourceFields[$field->getId()] = $field;
		}
		$targetFields = $this->fields->findByBoard($targetBoardId);
		$mapped = [];
		$usedTargetIds = [];
		foreach ($values as $value) {
			$source = $sourceFields[$value->getFieldId()] ?? null;
			if ($source === null) {
				throw new InvalidInputException('A custom field on this card is missing from the source board');
			}
			$matches = array_values(array_filter($targetFields, static fn (CardField $target): bool
				=> $target->getName() === $source->getName()
				&& $target->getType() === $source->getType()
				&& $target->getOptionsArray() === $source->getOptionsArray()));
			if (count($matches) !== 1 || isset($usedTargetIds[$matches[0]->getId()])) {
				throw new InvalidInputException('The destination board needs one matching custom field for "' . $source->getName() . '"');
			}
			$targetId = $matches[0]->getId();
			$usedTargetIds[$targetId] = true;
			$mapped[] = [$value, $targetId];
		}

		foreach ($mapped as [$value, $targetId]) {
			$copy = new CardFieldValue();
			$copy->setCardId($targetCardId);
			$copy->setFieldId($targetId);
			$copy->setValue($value->getValue());
			$this->values->insert($copy);
		}
	}
}
