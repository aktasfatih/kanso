<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Acl;
use OCA\Kanso\Service\PermissionService;
use PHPUnit\Framework\TestCase;

class AclTest extends TestCase {
	private function acl(): Acl {
		$acl = new Acl();
		$acl->setId(40);
		$acl->setBoardId(1);
		$acl->setParticipantType(Acl::TYPE_USER);
		$acl->setParticipant('bob');
		$acl->setPermission(PermissionService::PERMISSION_READ);
		return $acl;
	}

	public function testSerializesRole(): void {
		$acl = $this->acl();
		$acl->setRole(ViewerContext::ROLE_EXTERNAL);

		$json = $acl->jsonSerialize();

		self::assertSame(ViewerContext::ROLE_EXTERNAL, $json['role']);
		self::assertSame('user', $json['participantType']);
	}

	public function testNullRoleSerializesAsInternal(): void {
		// A row hydrated before the role column existed must read as the
		// migration backfill value, never null.
		self::assertSame(ViewerContext::ROLE_INTERNAL, $this->acl()->jsonSerialize()['role']);
	}
}
