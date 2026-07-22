<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use InvalidArgumentException;
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
		$this->expectException(InvalidArgumentException::class);
		$this->service->between('J', 'I');
	}

	public function testBetweenThrowsWhenKeysAreEqual(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->between('I', 'I');
	}

	public function testBetweenThrowsOnTrailingZeroKey(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->between('I0', 'J');
	}

	public function testBetweenThrowsOnLowercaseKey(): void {
		$this->expectException(InvalidArgumentException::class);
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
}
