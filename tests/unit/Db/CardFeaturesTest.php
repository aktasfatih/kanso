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
		'checklist' => true,
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
			['contacts' => true, 'attachments' => false, 'github' => false, 'timeTracking' => true, 'coverColor' => true, 'checklist' => true],
			CardFeatures::decode('["attachments","github"]')
		);
	}

	/**
	 * Checklists are switchable like the five sections that shipped with the
	 * column, and they cost no migration: `disabled_card_features` stores only the
	 * DISABLED keys, so an existing board simply reads back `checklist => true`.
	 */
	public function testChecklistRoundTripsThroughStorage(): void {
		// A board that predates the key reads back as "checklist shown".
		self::assertTrue(CardFeatures::decode(null)['checklist']);

		$disabled = self::ALL_ENABLED;
		$disabled['checklist'] = false;
		self::assertSame('["checklist"]', CardFeatures::encode($disabled));
		self::assertSame($disabled, CardFeatures::decode('["checklist"]'));
	}

	/**
	 * The one assertion that pins the SERVER allowlist rather than the client
	 * mirror: applyPatch() throws InvalidInputException for any key missing from
	 * CardFeatures::ALL, so a checklist-only patch being ACCEPTED is what proves
	 * the settings switch won't 400. Deliberately its own test with nothing before
	 * it, so dropping the key from ::ALL surfaces as that exception and not as an
	 * earlier assertion failure.
	 */
	public function testApplyPatchAcceptsTheChecklistKey(): void {
		$disabled = self::ALL_ENABLED;
		$disabled['checklist'] = false;
		self::assertSame($disabled, CardFeatures::applyPatch(self::ALL_ENABLED, ['checklist' => false]));
		self::assertSame(self::ALL_ENABLED, CardFeatures::applyPatch($disabled, ['checklist' => true]));
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
