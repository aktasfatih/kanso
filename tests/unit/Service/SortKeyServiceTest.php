<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\SortKeyService;
use OverflowException;
use PHPUnit\Framework\TestCase;

class SortKeyServiceTest extends TestCase {
	private SortKeyService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new SortKeyService();
	}

	public function testInitialIsSane(): void {
		$key = $this->service->initial();
		self::assertSame('I', $key);
		self::assertMatchesRegularExpression('/^[0-9A-Z]+$/', $key);
		self::assertStringEndsNotWith('0', $key);
		self::assertLessThanOrEqual(SortKeyService::MAX_KEY_LENGTH, strlen($key));
	}

	public function testBetweenReturnsMidpoint(): void {
		$key = $this->service->between('A', 'Z');
		self::assertLessThan(0, strcmp('A', $key));
		self::assertLessThan(0, strcmp($key, 'Z'));
		self::assertSame(1, strlen($key));
		self::assertStringEndsNotWith('0', $key);
	}

	public function testBetweenAdjacentKeysExtendsByAtMostOneChar(): void {
		$key = $this->service->between('I', 'J');
		self::assertLessThan(0, strcmp('I', $key));
		self::assertLessThan(0, strcmp($key, 'J'));
		self::assertLessThanOrEqual(2, strlen($key));
		self::assertStringEndsNotWith('0', $key);
	}

	public function testBetweenPrefixKeys(): void {
		$key = $this->service->between('I', 'I1');
		self::assertLessThan(0, strcmp('I', $key));
		self::assertLessThan(0, strcmp($key, 'I1'));
		self::assertStringEndsNotWith('0', $key);
	}

	public function testRepeatedBisectionStaysBounded(): void {
		$left = 'I';
		$right = 'J';
		for ($i = 0; $i < 50; $i++) {
			$key = $this->service->between($left, $right);
			self::assertLessThan(0, strcmp($left, $key), 'iteration ' . $i);
			self::assertLessThan(0, strcmp($key, $right), 'iteration ' . $i);
			self::assertLessThan(64, strlen($key), 'iteration ' . $i);
			self::assertStringEndsNotWith('0', $key);
			$right = $key;
		}
	}

	public function testSequentialAfterStaysShort(): void {
		$key = $this->service->initial();
		for ($i = 0; $i < 100; $i++) {
			$next = $this->service->after($key);
			self::assertLessThan(0, strcmp($key, $next), 'iteration ' . $i);
			self::assertLessThanOrEqual(8, strlen($next), 'iteration ' . $i);
			self::assertStringEndsNotWith('0', $next);
			$key = $next;
		}
	}

	public function testSequentialBeforeStrictlyDecreasing(): void {
		$key = $this->service->initial();
		for ($i = 0; $i < 100; $i++) {
			$previous = $this->service->before($key);
			self::assertLessThan(0, strcmp($previous, $key), 'iteration ' . $i);
			self::assertLessThan(64, strlen($previous), 'iteration ' . $i);
			self::assertStringEndsNotWith('0', $previous);
			$key = $previous;
		}
	}

	public function testBetweenThrowsWhenFirstKeyIsGreater(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->between('J', 'I');
	}

	public function testBetweenThrowsWhenKeysAreEqual(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->between('I', 'I');
	}

	public function testBetweenThrowsOnTrailingZeroKey(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->between('I0', 'J');
	}

	public function testBetweenThrowsOnLowercaseKey(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->between('a', 'b');
	}

	public function testAfterThrowsOverflowWhenKeyWouldExceedMaxLength(): void {
		$this->expectException(OverflowException::class);
		$this->service->after(str_repeat('Z', SortKeyService::MAX_KEY_LENGTH));
	}

	public function testRandomInsertionsStaySorted(): void {
		mt_srand(42);
		$list = [$this->service->initial()];
		for ($i = 0; $i < 500; $i++) {
			$count = count($list);
			$position = mt_rand(0, $count);
			if ($position === 0) {
				$key = $this->service->before($list[0]);
			} elseif ($position === $count) {
				$key = $this->service->after($list[$count - 1]);
			} else {
				$key = $this->service->between($list[$position - 1], $list[$position]);
			}
			self::assertStringEndsNotWith('0', $key);
			self::assertLessThanOrEqual(SortKeyService::MAX_KEY_LENGTH, strlen($key));
			array_splice($list, $position, 0, [$key]);
		}
		self::assertCount(501, $list);
		for ($i = 1; $i < 501; $i++) {
			self::assertLessThan(
				0,
				strcmp($list[$i - 1], $list[$i]),
				'list not strictly sorted at index ' . $i
			);
		}
	}

	// ---- evenlySpaced (rebalance key set) ---------------------------------

	public function testEvenlySpacedZeroReturnsEmpty(): void {
		self::assertSame([], $this->service->evenlySpaced(0));
	}

	public function testEvenlySpacedRejectsNegative(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->evenlySpaced(-1);
	}

	public function testEvenlySpacedSingleKeyIsCentred(): void {
		$keys = $this->service->evenlySpaced(1);
		self::assertCount(1, $keys);
		self::assertMatchesRegularExpression('/^[0-9A-Z]+$/', $keys[0]);
		self::assertStringEndsNotWith('0', $keys[0]);
		self::assertLessThanOrEqual(SortKeyService::MAX_KEY_LENGTH, strlen($keys[0]));
	}

	public function testEvenlySpacedProducesShortStrictlyIncreasingKeys(): void {
		foreach ([2, 3, 10, 50, 200, 1000] as $n) {
			$keys = $this->service->evenlySpaced($n);
			self::assertCount($n, $keys);
			for ($i = 0; $i < $n; $i++) {
				self::assertMatchesRegularExpression('/^[0-9A-Z]+$/', $keys[$i]);
				self::assertStringEndsNotWith('0', $keys[$i]);
				// Short: two-character keys for realistic stack sizes.
				self::assertLessThanOrEqual(SortKeyService::MAX_KEY_LENGTH, strlen($keys[$i]));
				if ($i > 0) {
					self::assertLessThan(
						0,
						strcmp($keys[$i - 1], $keys[$i]),
						"evenlySpaced($n) not strictly increasing at index $i"
					);
				}
			}
		}
	}

	/**
	 * The whole point of a rebalance: after resetting a stack to evenly-spaced
	 * keys, a between() at ANY gap must fit without overflowing. This is the
	 * recovery invariant for the 409 rebalance_required path.
	 */
	public function testBetweenAtEveryGapOfEvenlySpacedNeverOverflows(): void {
		$keys = $this->service->evenlySpaced(100);
		for ($i = 1; $i < count($keys); $i++) {
			$mid = $this->service->between($keys[$i - 1], $keys[$i]);
			self::assertLessThan(0, strcmp($keys[$i - 1], $mid));
			self::assertLessThan(0, strcmp($mid, $keys[$i]));
			self::assertLessThanOrEqual(SortKeyService::MAX_KEY_LENGTH, strlen($mid));
		}
		// ...and appending after the last key also fits.
		$tail = $this->service->after($keys[count($keys) - 1]);
		self::assertLessThan(0, strcmp($keys[count($keys) - 1], $tail));
	}

	// ---- appendSequence (bulk tail append, e.g. CSV import) ---------------

	public function testAppendSequenceZeroReturnsEmpty(): void {
		self::assertSame([], $this->service->appendSequence(0, null));
		self::assertSame([], $this->service->appendSequence(0, 'I'));
	}

	public function testAppendSequenceRejectsNegative(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->appendSequence(-1, null);
	}

	/**
	 * The core scaling guarantee: even far past the ~2000-append wall where
	 * chaining after() overflows a 64-char key, a block stays short, strictly
	 * increasing, valid, and (when anchored) entirely above the existing tail.
	 */
	public function testAppendSequenceScalesToLargeBlocks(): void {
		foreach ([1, 2, 50, 2000, 50000] as $n) {
			foreach ([null, 'I', 'ZZ'] as $after) {
				$keys = $this->service->appendSequence($n, $after);
				self::assertCount($n, $keys);
				for ($i = 0; $i < $n; $i++) {
					self::assertMatchesRegularExpression('/^[0-9A-Z]+$/', $keys[$i]);
					self::assertStringEndsNotWith('0', $keys[$i]);
					self::assertLessThanOrEqual(SortKeyService::MAX_KEY_LENGTH, strlen($keys[$i]));
					if ($after !== null) {
						self::assertLessThan(
							0,
							strcmp($after, $keys[$i]),
							"appendSequence($n, $after) key #$i must sort after the tail"
						);
					}
					if ($i > 0) {
						self::assertLessThan(
							0,
							strcmp($keys[$i - 1], $keys[$i]),
							"appendSequence($n, " . var_export($after, true) . ") not strictly increasing at $i"
						);
					}
				}
			}
		}
	}

	/**
	 * A block appended after an existing tail must slot in AFTER it and still
	 * leave room to insert between the fresh keys.
	 */
	public function testAppendSequenceAnchorsAboveTailAndLeavesGaps(): void {
		$tail = $this->service->initial();
		$keys = $this->service->appendSequence(200, $tail);
		self::assertLessThan(0, strcmp($tail, $keys[0]));
		for ($i = 1; $i < count($keys); $i++) {
			$mid = $this->service->between($keys[$i - 1], $keys[$i]);
			self::assertLessThan(0, strcmp($keys[$i - 1], $mid));
			self::assertLessThan(0, strcmp($mid, $keys[$i]));
			self::assertLessThanOrEqual(SortKeyService::MAX_KEY_LENGTH, strlen($mid));
		}
	}

	/**
	 * A stack that has hit the overflow wall (colliding/at-capacity long keys)
	 * is rescued: the rewritten keys are short and a subsequent between() no
	 * longer throws where the original pair did.
	 */
	public function testEvenlySpacedRescuesAnAtCapacityPair(): void {
		// Two adjacent keys so long that between() overflows (the 409 case).
		$a = str_repeat('I', SortKeyService::MAX_KEY_LENGTH);
		$b = $a . 'I';
		// Sanity: the original pair genuinely overflows.
		$overflowed = false;
		try {
			$this->service->between($a, $b);
		} catch (OverflowException) {
			$overflowed = true;
		}
		self::assertTrue($overflowed, 'precondition: the long pair must overflow');

		// Rebalance the (2-card) stack; the fresh keys are short and insertable.
		$keys = $this->service->evenlySpaced(2);
		self::assertLessThanOrEqual(2, strlen($keys[0]));
		self::assertLessThanOrEqual(2, strlen($keys[1]));
		$mid = $this->service->between($keys[0], $keys[1]);
		self::assertLessThan(0, strcmp($keys[0], $mid));
		self::assertLessThan(0, strcmp($mid, $keys[1]));
	}
}
