<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A board-scoped custom-field definition (table `kanso_card_fields`) - one of a
 * small FIXED set of typed fields a board defines, whose values are set per
 * card (see {@see CardFieldValue}). Modelled exactly on {@see ReviewType}: a
 * per-board typed definition, MANAGE-gated, riding the board payload with no
 * index endpoint. Ordered by a fractional `sortKey` (never by id) so a reorder
 * stays a single-row UPDATE.
 *
 * The `type` is an app-level enum ({@see CardField::TYPES}) stored as a plain
 * string and validated in the service, NOT a DB enum. `options` is a JSON array
 * of choices, only meaningful for type `select`.
 *
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getType()
 * @method void setType(string $type)
 * @method string|null getOptions()
 * @method void setOptions(?string $options)
 * @method string getSortKey()
 * @method void setSortKey(string $sortKey)
 */
class CardField extends Entity implements \JsonSerializable {
	public const TYPE_TEXT = 'text';
	public const TYPE_NUMBER = 'number';
	public const TYPE_DATE = 'date';
	public const TYPE_SELECT = 'select';

	/** The fixed set of supported field types (app-validated, not a DB enum). */
	public const TYPES = [
		self::TYPE_TEXT,
		self::TYPE_NUMBER,
		self::TYPE_DATE,
		self::TYPE_SELECT,
	];

	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?int $boardId = null;
	protected ?string $name = null;
	protected ?string $type = null;
	protected ?string $options = null;
	protected ?string $sortKey = null;

	public function __construct() {
		$this->addType('boardId', Types::INTEGER);
		$this->addType('name', Types::STRING);
		$this->addType('type', Types::STRING);
		$this->addType('options', Types::TEXT);
		$this->addType('sortKey', Types::STRING);
	}

	/**
	 * The choices for a `select` field as a plain array (empty for other types
	 * or an unset/invalid options blob).
	 *
	 * @return list<string>
	 */
	public function getOptionsArray(): array {
		if ($this->options === null || $this->options === '') {
			return [];
		}
		$decoded = json_decode($this->options, true);
		if (!is_array($decoded)) {
			return [];
		}
		return array_values(array_map('strval', $decoded));
	}

	/**
	 * @return array{id: int, boardId: ?int, name: ?string, type: ?string, options: list<string>, sortKey: ?string}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'boardId' => $this->boardId,
			'name' => $this->name,
			'type' => $this->type,
			'options' => $this->getOptionsArray(),
			'sortKey' => $this->sortKey,
		];
	}
}
