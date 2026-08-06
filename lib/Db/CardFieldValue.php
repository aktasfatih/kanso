<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * The value a card carries for a board custom-field definition (table
 * `kanso_card_field_values`). One value per (card_id, field_id) - a unique
 * index enforces it, so a set is an upsert. The value is a single stringified
 * column: an ISO date, a number as a string, raw text, or the selected option.
 * Per-type coercion/validation is the service's job, not the schema's.
 *
 * @method int getCardId()
 * @method void setCardId(int $cardId)
 * @method int getFieldId()
 * @method void setFieldId(int $fieldId)
 * @method string|null getValue()
 * @method void setValue(?string $value)
 */
class CardFieldValue extends Entity implements \JsonSerializable {
	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?int $cardId = null;
	protected ?int $fieldId = null;
	protected ?string $value = null;

	public function __construct() {
		$this->addType('cardId', Types::INTEGER);
		$this->addType('fieldId', Types::INTEGER);
		$this->addType('value', Types::TEXT);
	}

	/**
	 * @return array{fieldId: ?int, value: ?string}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'fieldId' => $this->fieldId,
			'value' => $this->value,
		];
	}
}
