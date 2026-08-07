<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\CardField;
use OCA\Kanso\Db\CardFieldMapper;
use OCA\Kanso\Db\CardFieldValueMapper;
use OCA\Kanso\Db\Change;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

/**
 * Custom-field DEFINITION CRUD (#3537) - per-board, a small FIXED set of typed
 * fields (text / number / date / select). Mirrors {@see ReviewTypeService}:
 * managing the definition list is a board-structure concern (MANAGE), and every
 * mutation appends a `kanso_changes` row (ENTITY_CARD_FIELD) so the board ETag
 * bumps and clients refetch the definitions. Deleting a field cascades its
 * values in one transaction.
 *
 * `type` is validated against {@see CardField::TYPES} (an app-level enum, not a
 * DB enum). `options` is only allowed / parsed for type `select`.
 */
class CardFieldService {
	private const MAX_NAME_LENGTH = 100;
	private const MAX_OPTIONS = 50;
	private const MAX_OPTION_LENGTH = 100;

	public function __construct(
		private CardFieldMapper $cardFieldMapper,
		private CardFieldValueMapper $cardFieldValueMapper,
		private BoardMapper $boardMapper,
		private ChangeNotifier $changeNotifier,
		private PermissionService $permissionService,
		private SortKeyService $sortKeyService,
		private IDBConnection $db,
	) {
	}

	/**
	 * @param string[]|null $options choices, only meaningful (and only allowed) for type `select`
	 *
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 * @throws InvalidInputException on invalid name / type / options
	 */
	public function create(int $boardId, string $name, string $type, ?array $options, string $uid): CardField {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);

		$type = $this->validateType($type);

		$field = new CardField();
		$field->setBoardId($boardId);
		$field->setName($this->validateName($name));
		$field->setType($type);
		$field->setOptions($this->encodeOptions($type, $options));
		$field->setSortKey($this->nextSortKey($boardId));
		$field = $this->cardFieldMapper->insert($field);

		$this->changeNotifier->notify(
			$boardId,
			Change::ENTITY_CARD_FIELD,
			$field->getId(),
			Change::ACTION_CREATE,
			$uid
		);

		return $field;
	}

	/**
	 * Updates a field definition. `type` is immutable once set (changing it
	 * would strand existing values in an incompatible representation) - name,
	 * options and sort key are the editable surface.
	 *
	 * @param string[]|null $options new choices (only for a `select` field)
	 *
	 * @throws DoesNotExistException if the field or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 * @throws InvalidInputException on invalid name / options / sort key
	 */
	public function update(int $id, ?string $name, ?array $options, ?string $sortKey, string $uid): CardField {
		$field = $this->cardFieldMapper->find($id);
		$board = $this->loadBoard($field->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);

		if ($name !== null) {
			$field->setName($this->validateName($name));
		}
		if ($options !== null) {
			// Options only mean anything for a select field; reject on others.
			$field->setOptions($this->encodeOptions($field->getType(), $options));
		}
		if ($sortKey !== null) {
			$field->setSortKey($this->validateSortKey($sortKey));
		}

		$field = $this->cardFieldMapper->update($field);

		$this->changeNotifier->notify(
			$field->getBoardId(),
			Change::ENTITY_CARD_FIELD,
			$id,
			Change::ACTION_UPDATE,
			$uid
		);

		return $field;
	}

	/**
	 * Deletes the field definition and every value that referenced it. The
	 * cascade + delete + change row are ONE transaction (mirror
	 * ReviewTypeService::delete): rolled back on any throw so a half-applied
	 * delete can never leave orphaned values.
	 *
	 * @throws DoesNotExistException if the field or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 */
	public function delete(int $id, string $uid): void {
		$field = $this->cardFieldMapper->find($id);
		$board = $this->loadBoard($field->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);

		$this->db->beginTransaction();
		try {
			$this->cardFieldValueMapper->deleteByField($id);
			$this->cardFieldMapper->delete($field);
			$this->changeNotifier->notify(
				$field->getBoardId(),
				Change::ENTITY_CARD_FIELD,
				$id,
				Change::ACTION_DELETE,
				$uid
			);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
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

	/**
	 * A fractional sort key appended after the board's current last field, or
	 * the initial key when the board has no fields yet.
	 */
	private function nextSortKey(int $boardId): string {
		$last = $this->cardFieldMapper->lastSortKey($boardId);
		return $last === null ? $this->sortKeyService->initial() : $this->sortKeyService->after($last);
	}

	/**
	 * @throws InvalidInputException
	 */
	private function validateName(string $name): string {
		$name = trim($name);
		if ($name === '') {
			throw new InvalidInputException('Name must not be empty');
		}
		if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
			throw new InvalidInputException(
				'Name must not exceed ' . self::MAX_NAME_LENGTH . ' characters'
			);
		}
		return $name;
	}

	/**
	 * @throws InvalidInputException on an unknown field type
	 */
	private function validateType(string $type): string {
		if (!in_array($type, CardField::TYPES, true)) {
			throw new InvalidInputException(
				'Unknown field type "' . $type . '"; must be one of: ' . implode(', ', CardField::TYPES)
			);
		}
		return $type;
	}

	/**
	 * @throws InvalidInputException
	 */
	private function validateSortKey(string $sortKey): string {
		$sortKey = trim($sortKey);
		if ($sortKey === '' || preg_match('/^[0-9A-Z]+$/', $sortKey) !== 1) {
			throw new InvalidInputException('Invalid sort key "' . $sortKey . '"');
		}
		return $sortKey;
	}

	/**
	 * Validates + JSON-encodes the option list for storage. Options are ONLY
	 * meaningful for a `select` field: any options passed for another type are
	 * rejected. For a select field an empty/absent list stores null. Option
	 * count and per-option length are bounded.
	 *
	 * @param string[]|null $options
	 *
	 * @throws InvalidInputException on options for a non-select field, too many
	 *                               options, or an over-long / empty option
	 */
	private function encodeOptions(string $type, ?array $options): ?string {
		if ($type !== CardField::TYPE_SELECT) {
			if ($options !== null && $options !== []) {
				throw new InvalidInputException('Options are only allowed for a select field');
			}
			return null;
		}

		if ($options === null || $options === []) {
			return null;
		}

		if (count($options) > self::MAX_OPTIONS) {
			throw new InvalidInputException(
				'A select field may have at most ' . self::MAX_OPTIONS . ' options'
			);
		}

		$clean = [];
		foreach ($options as $option) {
			$option = trim((string)$option);
			if ($option === '') {
				throw new InvalidInputException('Options must not be empty');
			}
			if (mb_strlen($option) > self::MAX_OPTION_LENGTH) {
				throw new InvalidInputException(
					'An option must not exceed ' . self::MAX_OPTION_LENGTH . ' characters'
				);
			}
			$clean[] = $option;
		}

		return json_encode($clean, JSON_THROW_ON_ERROR);
	}
}
