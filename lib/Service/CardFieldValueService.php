<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardField;
use OCA\Kanso\Db\CardFieldMapper;
use OCA\Kanso\Db\CardFieldValue;
use OCA\Kanso\Db\CardFieldValueMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Custom-field VALUE writes (#3537): set or clear the value a card carries for
 * one board field definition. Setting/clearing a value is a plain card edit, so
 * it is EDIT-gated (NOT MANAGE - defining the field is the MANAGE concern) and
 * stamps the ordinary card-change path (ENTITY_CARD / ACTION_UPDATE with
 * VERB_UPDATED) so the card's ETag and activity feed update like any other card
 * edit.
 *
 * One value per (card, field) - the unique index makes a set an upsert. The
 * value lives in ONE stringified column; per-type coercion/validation
 * (parseable number, ISO date, option-membership for select) is done here, not
 * by a typed column-per-type.
 */
class CardFieldValueService {
	private const MAX_VALUE_LENGTH = 2000;

	public function __construct(
		private CardFieldValueMapper $cardFieldValueMapper,
		private CardFieldMapper $cardFieldMapper,
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private ChangeNotifier $changeNotifier,
		private PermissionService $permissionService,
		private CardVisibilityGuard $visibilityGuard,
	) {
	}

	/**
	 * Sets (upserts) the card's value for a field. A null / empty value clears
	 * it (see {@see self::clear()}). The value is coerced + validated against
	 * the field's type before storage.
	 *
	 * @throws DoesNotExistException if the card, its board, or the field does not exist
	 * @throws NotPermittedException if the user may not edit the board
	 * @throws InvalidInputException if the field is not on the card's board or the value is invalid for its type
	 */
	public function set(int $cardId, int $fieldId, ?string $value, string $uid): CardFieldValue {
		$card = $this->cardMapper->find($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $uid);

		$field = $this->loadFieldForCard($fieldId, $card);

		$coerced = $this->coerce($field, $value);
		if ($coerced === null) {
			// Empty write == clear (no value row for an empty value).
			return $this->clearInternal($cardId, $fieldId, $card, $board, $uid);
		}

		$existing = $this->cardFieldValueMapper->findByCardAndField($cardId, $fieldId);
		if ($existing !== null) {
			$existing->setValue($coerced);
			$saved = $this->cardFieldValueMapper->update($existing);
		} else {
			$entity = new CardFieldValue();
			$entity->setCardId($cardId);
			$entity->setFieldId($fieldId);
			$entity->setValue($coerced);
			$saved = $this->cardFieldValueMapper->insert($entity);
		}

		$this->notifyCardChanged($board->getId(), $cardId, $uid);

		return $saved;
	}

	/**
	 * Clears the card's value for a field (idempotent - clearing a field with no
	 * value is a no-op that still bumps the card).
	 *
	 * @throws DoesNotExistException if the card, its board, or the field does not exist
	 * @throws NotPermittedException if the user may not edit the board
	 * @throws InvalidInputException if the field is not on the card's board
	 */
	public function clear(int $cardId, int $fieldId, string $uid): void {
		$card = $this->cardMapper->find($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $uid);

		$this->loadFieldForCard($fieldId, $card);
		$this->clearInternal($cardId, $fieldId, $card, $board, $uid);
	}

	private function clearInternal(int $cardId, int $fieldId, Card $card, Board $board, string $uid): CardFieldValue {
		$this->cardFieldValueMapper->deleteByCardAndField($cardId, $fieldId);
		$this->notifyCardChanged($board->getId(), $cardId, $uid);

		// A cleared field has no persisted row; return a transient "empty" value
		// so callers get a uniform shape.
		$empty = new CardFieldValue();
		$empty->setCardId($cardId);
		$empty->setFieldId($fieldId);
		$empty->setValue(null);
		return $empty;
	}

	private function notifyCardChanged(int $boardId, int $cardId, string $uid): void {
		$this->changeNotifier->notify(
			$boardId,
			Change::ENTITY_CARD,
			$cardId,
			Change::ACTION_UPDATE,
			$uid,
			verb: Change::VERB_UPDATED,
		);
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
	 * Loads a field definition and asserts it belongs to the card's board (a
	 * value can only be set for a field defined on the same board).
	 *
	 * @throws DoesNotExistException if the field does not exist
	 * @throws InvalidInputException if the field is not on the card's board
	 */
	private function loadFieldForCard(int $fieldId, Card $card): CardField {
		$field = $this->cardFieldMapper->find($fieldId);
		if ($field->getBoardId() !== $card->getBoardId()) {
			throw new InvalidInputException('Field ' . $fieldId . ' is not defined on this card\'s board');
		}
		return $field;
	}

	/**
	 * Coerces + validates a raw input string against the field's type. Returns
	 * the canonical stored string, or null when the input is empty (a clear).
	 *
	 * @throws InvalidInputException if the value is invalid for the field type
	 */
	private function coerce(CardField $field, ?string $value): ?string {
		if ($value === null) {
			return null;
		}
		$value = trim($value);
		if ($value === '') {
			return null;
		}
		if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
			throw new InvalidInputException(
				'Value must not exceed ' . self::MAX_VALUE_LENGTH . ' characters'
			);
		}

		switch ($field->getType()) {
			case CardField::TYPE_TEXT:
				return $value;
			case CardField::TYPE_NUMBER:
				if (!is_numeric($value)) {
					throw new InvalidInputException('Value "' . $value . '" is not a valid number');
				}
				// Store the numeric string as-is (canonicalised to trimmed form).
				return $value;
			case CardField::TYPE_DATE:
				return $this->normalizeDate($value);
			case CardField::TYPE_SELECT:
				if (!in_array($value, $field->getOptionsArray(), true)) {
					throw new InvalidInputException('Value "' . $value . '" is not one of the field\'s options');
				}
				return $value;
			default:
				// Unreachable: the type was validated on definition create.
				throw new InvalidInputException('Unsupported field type "' . (string)$field->getType() . '"');
		}
	}

	/**
	 * Accepts an ISO date (YYYY-MM-DD) or an ISO datetime and stores the
	 * date part in canonical YYYY-MM-DD form.
	 *
	 * @throws InvalidInputException on an unparseable date
	 */
	private function normalizeDate(string $value): string {
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
		$errors = \DateTimeImmutable::getLastErrors();
		if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
			throw new InvalidInputException('Value "' . $value . '" is not a valid ISO date (YYYY-MM-DD)');
		}
		return $date->format('Y-m-d');
	}
}
