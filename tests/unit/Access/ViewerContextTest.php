<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Access;

use OCA\Kanso\Access\ViewerContext;
use PHPUnit\Framework\TestCase;

class ViewerContextTest extends TestCase {
	public function testInternalMemberKeepsManagerFlag(): void {
		$ctx = ViewerContext::forMember('alice', 7, ViewerContext::ROLE_INTERNAL, true);

		self::assertSame('alice', $ctx->userId);
		self::assertSame(7, $ctx->boardId);
		self::assertSame(ViewerContext::ROLE_INTERNAL, $ctx->role);
		self::assertTrue($ctx->isManager);
		self::assertTrue($ctx->isInternal());
	}

	public function testExternalMemberHasManagerFlagStripped(): void {
		// MANAGE is an internal-side concept (mirrors the symmetric model's
		// "externals are never managers"): even if the ACL bits carry MANAGE,
		// an external member's context must not.
		$ctx = ViewerContext::forMember('bob', 7, ViewerContext::ROLE_EXTERNAL, true);

		self::assertSame(ViewerContext::ROLE_EXTERNAL, $ctx->role);
		self::assertFalse($ctx->isManager);
		self::assertFalse($ctx->isInternal());
	}

	public function testNonManagerStaysNonManager(): void {
		self::assertFalse(ViewerContext::forMember('alice', 7, ViewerContext::ROLE_INTERNAL, false)->isManager);
	}

	public function testUnknownRoleThrows(): void {
		// A typo must never silently read as "anything" - the value object
		// refuses to exist with a role outside the two known sides.
		$this->expectException(\InvalidArgumentException::class);
		ViewerContext::forMember('alice', 7, 'admin', true);
	}
}
