<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\CalDav;

use OCA\Kanso\CalDav\CardCalendarObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAVACL\IACL;

require_once __DIR__ . '/../../stubs/caldav.php';

class CardCalendarObjectTest extends TestCase {
	private function object(): CardCalendarObject {
		return new CardCalendarObject('kanso-card-42.ics', 'calendar-data', '"etag"', 1000, 'principals/users/reader');
	}

	public function testObjectGrantsOnlyProtectedReadToSyncingPrincipal(): void {
		$object = $this->object();
		self::assertInstanceOf(IACL::class, $object);
		self::assertSame('principals/users/reader', $object->getOwner());
		self::assertNull($object->getGroup());
		self::assertSame([['privilege' => '{DAV:}read', 'principal' => 'principals/users/reader', 'protected' => true]], $object->getACL());
		self::assertNull($object->getSupportedPrivilegeSet());
	}

	public function testReadMetadataIsUnchanged(): void {
		$object = $this->object();
		self::assertSame('kanso-card-42.ics', $object->getName());
		self::assertSame('calendar-data', $object->get());
		self::assertSame('"etag"', $object->getETag());
		self::assertSame(1000, $object->getLastModified());
		self::assertSame(13, $object->getSize());
		self::assertSame('text/calendar; charset=utf-8', $object->getContentType());
	}

	public static function writes(): array {
		return [
			'content' => ['put', ['replacement']],
			'rename' => ['setName', ['renamed.ics']],
			'delete' => ['delete', []],
			'grant' => ['setACL', [[['principal' => '{DAV:}authenticated', 'privilege' => '{DAV:}all']]]],
			'clear ACL' => ['setACL', [[]]],
		];
	}

	#[DataProvider('writes')]
	public function testWritesAreForbidden(string $method, array $arguments): void {
		$this->expectException(Forbidden::class);
		$this->object()->$method(...$arguments);
	}
}
