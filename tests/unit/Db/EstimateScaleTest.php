<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Db\EstimateScale;
use OCA\Kanso\Service\InvalidInputException;
use PHPUnit\Framework\TestCase;

class EstimateScaleTest extends TestCase {
	private const CUSTOM = [
		['label' => 'Tiny', 'value' => 1.0],
		['label' => 'Epic', 'value' => 13.0],
	];

	public function testPresetTokensCarryTheirValues(): void {
		self::assertSame(3.0, EstimateScale::value('roman', 'III'));
		self::assertSame(5.0, EstimateScale::value('tshirt', 'L'));
		self::assertSame(0.5, EstimateScale::value('fibonacci_mod', '0.5'));
		self::assertSame(32.0, EstimateScale::value('powers2', '32'));
		self::assertNull(EstimateScale::value('roman', 'XI'));
		self::assertNull(EstimateScale::value(EstimateScale::NONE, '1'));
	}

	public function testNumberScaleAcceptsNonNegativeNumbersWithTwoDecimals(): void {
		self::assertTrue(EstimateScale::allows(EstimateScale::NUMBER, '0'));
		self::assertTrue(EstimateScale::allows(EstimateScale::NUMBER, '12.75'));
		self::assertFalse(EstimateScale::allows(EstimateScale::NUMBER, '-1'));
		self::assertFalse(EstimateScale::allows(EstimateScale::NUMBER, '1.234'));
		self::assertFalse(EstimateScale::allows(EstimateScale::NUMBER, '1e3'));
		self::assertFalse(EstimateScale::allows(EstimateScale::NUMBER, '100001'));
		self::assertSame('3.5', EstimateScale::normalizeNumber('3.50'));
		self::assertSame('7', EstimateScale::normalizeNumber('007'));
		self::assertSame([], EstimateScale::tokens(EstimateScale::NUMBER));
	}

	public function testCustomScaleUsesTheBoardsLabels(): void {
		self::assertSame(13.0, EstimateScale::value(EstimateScale::CUSTOM, 'Epic', self::CUSTOM));
		self::assertFalse(EstimateScale::allows(EstimateScale::CUSTOM, 'Huge', self::CUSTOM));
		self::assertFalse(EstimateScale::allows(EstimateScale::CUSTOM, 'Tiny'));
		self::assertSame(['Tiny' => 1.0, 'Epic' => 13.0], EstimateScale::tokens(EstimateScale::CUSTOM, self::CUSTOM));
	}

	public function testEncodeCustomRoundTripsCleanedEntries(): void {
		$stored = EstimateScale::encodeCustom([['label' => ' Tiny ', 'value' => '1'], ['label' => 'Epic', 'value' => 13.333]]);

		self::assertSame([['label' => 'Tiny', 'value' => 1.0], ['label' => 'Epic', 'value' => 13.33]], EstimateScale::decodeCustom($stored));
	}

	/**
	 * @return array<string, array{0: array<mixed>}>
	 */
	public static function invalidCustomScales(): array {
		return [
			'empty' => [[]],
			'blank label' => [[['label' => ' ', 'value' => 1]]],
			'long label' => [[['label' => str_repeat('x', 25), 'value' => 1]]],
			'duplicate label' => [[['label' => 'Big', 'value' => 1], ['label' => 'big', 'value' => 2]]],
			'negative value' => [[['label' => 'Big', 'value' => -1]]],
			'non-numeric value' => [[['label' => 'Big', 'value' => 'lots']]],
			'not an entry' => [['Big']],
		];
	}

	/**
	 * @dataProvider invalidCustomScales
	 * @param array<mixed> $entries
	 */
	public function testEncodeCustomRejectsInvalidScales(array $entries): void {
		$this->expectException(InvalidInputException::class);
		EstimateScale::encodeCustom($entries);
	}

	public function testDecodeCustomIgnoresMalformedStorage(): void {
		self::assertSame([], EstimateScale::decodeCustom(null));
		self::assertSame([], EstimateScale::decodeCustom('not json'));
		self::assertSame([['label' => 'Ok', 'value' => 2.0]], EstimateScale::decodeCustom('[{"label":"Ok","value":2},{"label":5},"x"]'));
	}

	public function testEveryPresetKeyFitsTheScaleColumn(): void {
		foreach (array_keys(EstimateScale::PRESETS) as $key) {
			self::assertLessThanOrEqual(16, strlen($key), $key);
		}
	}
}
