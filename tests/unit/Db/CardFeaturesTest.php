<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\CardFeatures;
use OCA\Kanso\Service\InvalidInputException;
use PHPUnit\Framework\TestCase;

/**
 * Built-in card features (#5894). The critical property here is the DEFAULT:
 * the migration adds a nullable column with no backfill, so every board that
 * existed before it must read back as "everything enabled" and look exactly as
 * it did before the upgrade.
 */
class CardFeaturesTest extends TestCase {
	private const ALL_ENABLED = [
		'contacts' => true,
		'attachments' => true,
		'github' => true,
		'timeTracking' => true,
		'coverColor' => true,
	];

	public function testNullColumnMeansEveryFeatureIsEnabled(): void {
		self::assertSame(self::ALL_ENABLED, CardFeatures::decode(null));
	}

	public function testEmptyStringAndEmptyArrayAlsoMeanEveryFeatureIsEnabled(): void {
		self::assertSame(self::ALL_ENABLED, CardFeatures::decode(''));
		self::assertSame(self::ALL_ENABLED, CardFeatures::decode('[]'));
	}

	/**
	 * A corrupt or unexpected value must never hide a feature nobody switched
	 * off - failing "open" is the safe direction for a presentation flag.
	 */
	public function testGarbageStorageFailsOpen(): void {
		self::assertSame(self::ALL_ENABLED, CardFeatures::decode('not json'));
		self::assertSame(self::ALL_ENABLED, CardFeatures::decode('{"attachments":false}'));
		self::assertSame(self::ALL_ENABLED, CardFeatures::decode('"attachments"'));
	}

	public function testDecodeMarksOnlyTheStoredKeysDisabled(): void {
		self::assertSame(
			['contacts' => true, 'attachments' => false, 'github' => false, 'timeTracking' => true, 'coverColor' => true],
			CardFeatures::decode('["attachments","github"]')
		);
	}

	/** A key from a newer version (after a downgrade) is simply ignored. */
	public function testDecodeIgnoresUnknownStoredKeys(): void {
		self::assertSame(self::ALL_ENABLED, CardFeatures::decode('["telepathy"]'));
	}

	public function testEncodeStoresNullWhenNothingIsDisabled(): void {
		self::assertNull(CardFeatures::encode(self::ALL_ENABLED));
	}

	public function testEncodeStoresOnlyTheDisabledKeysInDeclarationOrder(): void {
		$map = self::ALL_ENABLED;
		$map['coverColor'] = false;
		$map['contacts'] = false;
		self::assertSame('["contacts","coverColor"]', CardFeatures::encode($map));
	}

	public function testEncodeDecodeRoundTrips(): void {
		$map = self::ALL_ENABLED;
		$map['timeTracking'] = false;
		self::assertSame($map, CardFeatures::decode(CardFeatures::encode($map)));
	}

	public function testApplyPatchOnlyTouchesTheKeysItWasGiven(): void {
		$patched = CardFeatures::applyPatch(CardFeatures::decode('["github"]'), ['contacts' => false]);
		self::assertFalse($patched['contacts']);
		self::assertFalse($patched['github']);
		self::assertTrue($patched['attachments']);
	}

	public function testApplyPatchRejectsAnUnknownKey(): void {
		$this->expectException(InvalidInputException::class);
		CardFeatures::applyPatch(self::ALL_ENABLED, ['telepathy' => false]);
	}

	public function testApplyPatchRejectsANonBooleanValue(): void {
		$this->expectException(InvalidInputException::class);
		CardFeatures::applyPatch(self::ALL_ENABLED, ['github' => 'no']);
	}

	/**
	 * The board payload is what the client actually consumes: a board that
	 * predates the column serialises as all-enabled, so nothing disappears on
	 * upgrade.
	 */
	public function testBoardPayloadOfAnUnmigratedBoardIsAllEnabled(): void {
		$board = new Board();
		$board->setId(7);
		self::assertSame(self::ALL_ENABLED, $board->jsonSerialize()['cardFeatures']);
	}
}
