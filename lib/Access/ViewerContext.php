<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Access;

/**
 * Proof that a user's board membership was resolved: (uid, boardId,
 * effective role, manager flag) - the ONLY input the card-visibility scope
 * accepts for its role-match branch (#3741/#3742).
 *
 * The constructor is private and {@see self::forMember()} is the single
 * factory; an architecture test forbids calling forMember() anywhere in
 * lib/ except {@see BoardAccess} - so there is exactly one door, and
 * holding a ViewerContext always means the ACL was actually read.
 *
 * The role model is deliberately symmetric and backdoor-free: 'internal'
 * is the provider side, 'external' the client side, and NO caller (owner
 * and admins included) gets visibility beyond what their resolved role
 * grants. `isManager` (the MANAGE bit) is meaningful for internal members
 * only - forMember() strips it for externals.
 */
final readonly class ViewerContext {
	public const ROLE_INTERNAL = 'internal';
	public const ROLE_EXTERNAL = 'external';

	public const ROLES = [self::ROLE_INTERNAL, self::ROLE_EXTERNAL];

	private function __construct(
		public string $userId,
		public int $boardId,
		public string $role,
		public bool $isManager,
	) {
	}

	/**
	 * The single factory - only {@see BoardAccess} may call it (enforced by
	 * an architecture test). A role outside the two known values throws:
	 * a typo must never silently read as "sees everything" (or anything).
	 *
	 * @param string $role one of the ROLE_* constants
	 * @throws \InvalidArgumentException on an unknown role
	 */
	public static function forMember(string $uid, int $boardId, string $role, bool $isManager): self {
		if (!in_array($role, self::ROLES, true)) {
			throw new \InvalidArgumentException('Unknown board role: ' . $role);
		}
		// MANAGE is an internal-side concept; an external member never
		// carries the manager flag no matter what their ACL bits say.
		return new self($uid, $boardId, $role, $isManager && $role === self::ROLE_INTERNAL);
	}

	public function isInternal(): bool {
		return $this->role === self::ROLE_INTERNAL;
	}
}
