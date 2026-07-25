<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Service\DeckReader;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class DeckReaderTest extends TestCase {
	private function bareColor(mixed $in): ?string {
		$reader = new DeckReader(
			$this->createMock(IDBConnection::class),
			$this->createMock(IAppManager::class),
		);
		$m = new \ReflectionMethod($reader, 'bareColor');
		$m->setAccessible(true);
		return $m->invoke($reader, $in);
	}

	/**
	 * A Deck colour must be normalised to bare 6-hex or null so a malformed
	 * value never reaches Kanso's strict ColorValidator and aborts the import
	 * (#3475).
	 */
	public function testNormalisesColours(): void {
		self::assertSame('0082c9', $this->bareColor('0082c9'));
		self::assertSame('0082c9', $this->bareColor('#0082c9'));
		self::assertSame('aabbcc', $this->bareColor('#abc'), '3-hex shorthand expands');
		self::assertNull($this->bareColor(''));
		self::assertNull($this->bareColor(null));
		self::assertNull($this->bareColor('red'), 'named colour → null');
		self::assertNull($this->bareColor('12345'), 'wrong length → null');
		self::assertNull($this->bareColor('gggggg'), 'non-hex → null');
	}
}
