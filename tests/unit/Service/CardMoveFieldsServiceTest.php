<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\CardField;
use OCA\Kanso\Db\CardFieldMapper;
use OCA\Kanso\Db\CardFieldValue;
use OCA\Kanso\Db\CardFieldValueMapper;
use OCA\Kanso\Service\CardMoveFieldsService;
use OCA\Kanso\Service\InvalidInputException;
use PHPUnit\Framework\TestCase;

class CardMoveFieldsServiceTest extends TestCase {
	public function testCopiesValuesToMatchingTargetFields(): void {
		$fields = $this->createMock(CardFieldMapper::class);
		$values = $this->createMock(CardFieldValueMapper::class);
		$fields->method('findByBoard')->willReturnMap([
			[1, [$this->field(10, 'Priority', 'select', ['High', 'Low']), $this->field(11, 'Notes', 'text')]],
			[2, [$this->field(20, 'Notes', 'text'), $this->field(21, 'Priority', 'select', ['High', 'Low'])]],
		]);
		$values->method('findByCard')->with(5)->willReturn([
			$this->value(10, 'High'), $this->value(11, 'Hello'),
		]);
		$copies = [];
		$values->expects(self::exactly(2))->method('insert')->willReturnCallback(function (CardFieldValue $value) use (&$copies): CardFieldValue {
			$copies[] = [$value->getCardId(), $value->getFieldId(), $value->getValue()];
			return $value;
		});

		(new CardMoveFieldsService($fields, $values))->copy(5, 6, 1, 2);
		self::assertSame([[6, 21, 'High'], [6, 20, 'Hello']], $copies);
	}

	public function testNoValuesNeedsNoBoardFieldLookup(): void {
		$fields = $this->createMock(CardFieldMapper::class);
		$values = $this->createMock(CardFieldValueMapper::class);
		$values->method('findByCard')->willReturn([]);
		$fields->expects(self::never())->method('findByBoard');
		$values->expects(self::never())->method('insert');
		(new CardMoveFieldsService($fields, $values))->copy(5, 6, 1, 2);
	}

	/** @return iterable<string, array{list<CardField>}> */
	public static function incompatibleTargets(): iterable {
		yield 'no matching field' => [[]];
		yield 'type differs' => [[self::field(20, 'Priority', 'text')]];
		yield 'options differ' => [[self::field(20, 'Priority', 'select', ['High', 'Medium'])]];
		yield 'name differs' => [[self::field(20, 'priority', 'select', ['High', 'Low'])]];
		yield 'ambiguous' => [[self::field(20, 'Priority', 'select', ['High', 'Low']), self::field(21, 'Priority', 'select', ['High', 'Low'])]];
	}

	/** @dataProvider incompatibleTargets */
	public function testRejectsMissingIncompatibleOrAmbiguousTarget(array $target): void {
		$fields = $this->createMock(CardFieldMapper::class);
		$values = $this->createMock(CardFieldValueMapper::class);
		$fields->method('findByBoard')->willReturnMap([
			[1, [self::field(10, 'Priority', 'select', ['High', 'Low'])]],
			[2, $target],
		]);
		$values->method('findByCard')->willReturn([$this->value(10, 'High')]);
		$values->expects(self::never())->method('insert');
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('matching custom field for "Priority"');
		(new CardMoveFieldsService($fields, $values))->copy(5, 6, 1, 2);
	}

	public function testRejectsTwoSourceFieldsMappedToTheSameTargetBeforeInserting(): void {
		$fields = $this->createMock(CardFieldMapper::class);
		$values = $this->createMock(CardFieldValueMapper::class);
		$fields->method('findByBoard')->willReturnMap([
			[1, [self::field(10, 'Notes', 'text'), self::field(11, 'Notes', 'text')]],
			[2, [self::field(20, 'Notes', 'text')]],
		]);
		$values->method('findByCard')->willReturn([$this->value(10, 'A'), $this->value(11, 'B')]);
		$values->expects(self::never())->method('insert');
		$this->expectException(InvalidInputException::class);
		(new CardMoveFieldsService($fields, $values))->copy(5, 6, 1, 2);
	}

	public function testRejectsAnOrphanedSourceValue(): void {
		$fields = $this->createMock(CardFieldMapper::class);
		$values = $this->createMock(CardFieldValueMapper::class);
		$fields->method('findByBoard')->willReturn([]);
		$values->method('findByCard')->willReturn([$this->value(10, 'A')]);
		$values->expects(self::never())->method('insert');
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('missing from the source board');
		(new CardMoveFieldsService($fields, $values))->copy(5, 6, 1, 2);
	}

	/** @param list<string> $options */
	private static function field(int $id, string $name, string $type, array $options = []): CardField {
		$field = new CardField();
		$field->setId($id);
		$field->setName($name);
		$field->setType($type);
		$field->setOptions(json_encode($options, JSON_THROW_ON_ERROR));
		return $field;
	}

	private function value(int $fieldId, string $value): CardFieldValue {
		$fieldValue = new CardFieldValue();
		$fieldValue->setCardId(5);
		$fieldValue->setFieldId($fieldId);
		$fieldValue->setValue($value);
		return $fieldValue;
	}
}
