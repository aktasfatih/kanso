<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Access;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\NotAMemberException;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\AclMapper;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Service\PermissionService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The effective-role resolver (#3742): user + group entries fold to exactly
 * ONE (role, isManager) per board (internal-wins), the owner resolves to an
 * implicit internal manager membership WITHOUT special visibility (the
 * no-backdoor property), non-members are rejected before any card query
 * could run, and the cross-board map is backed by one batched ACL fetch.
 */
class BoardAccessTest extends TestCase {
	private AclMapper&MockObject $aclMapper;
	private IGroupManager&MockObject $groupManager;
	private IUserManager&MockObject $userManager;
	private BoardAccess $access;

	protected function setUp(): void {
		parent::setUp();
		$this->aclMapper = $this->createMock(AclMapper::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->access = new BoardAccess(
			$this->aclMapper,
			new PermissionService($this->aclMapper, $this->groupManager, $this->userManager),
		);
	}

	private function board(string $owner = 'alice', int $id = 1): Board {
		$board = new Board();
		$board->setId($id);
		$board->setOwner($owner);
		return $board;
	}

	private function acl(int $participantType, string $participant, int $permission, string $role, ?int $boardId = null): Acl {
		$acl = new Acl();
		$acl->setParticipantType($participantType);
		$acl->setParticipant($participant);
		$acl->setPermission($permission);
		$acl->setRole($role);
		if ($boardId !== null) {
			$acl->setBoardId($boardId);
		}
		return $acl;
	}

	private function userInGroups(string $uid, array $groupIds): void {
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with($uid)->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->with($user)->willReturn($groupIds);
	}

	// ── contextFor: single-board resolution ───────────────────────────────────

	public function testOwnerResolvesToImplicitInternalManager(): void {
		// The owner has no ACL row - they short-circuit to an implicit
		// (internal, manager) membership without touching the ACL table.
		$this->aclMapper->expects(self::never())->method('findByBoard');

		$ctx = $this->access->contextFor($this->board('alice'), 'alice');

		self::assertSame(ViewerContext::ROLE_INTERNAL, $ctx->role);
		self::assertTrue($ctx->isManager);
	}

	public function testOwnerGetsNoRoleBeyondInternal(): void {
		// The no-backdoor property, restated for Kanso's owner model: the
		// owner's context is a PLAIN internal membership - the same role
		// value any internal member gets, nothing more. Visibility rules
		// downstream treat it identically (no "sees everything" marker
		// exists on the context at all).
		$owner = $this->access->contextFor($this->board('alice'), 'alice');

		$this->aclMapper->method('findByBoard')->willReturn([
			$this->acl(Acl::TYPE_USER, 'bob', PermissionService::PERMISSION_READ | PermissionService::PERMISSION_MANAGE, ViewerContext::ROLE_INTERNAL),
		]);
		$member = $this->access->contextFor($this->board('alice'), 'bob');

		self::assertSame($member->role, $owner->role);
		self::assertSame($member->isManager, $owner->isManager);
	}

	public function testDirectExternalEntryResolvesExternal(): void {
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([
			$this->acl(Acl::TYPE_USER, 'bob', PermissionService::PERMISSION_READ, ViewerContext::ROLE_EXTERNAL),
		]);

		$ctx = $this->access->contextFor($this->board('alice'), 'bob');

		self::assertSame(ViewerContext::ROLE_EXTERNAL, $ctx->role);
		self::assertFalse($ctx->isManager);
	}

	public function testGroupEntryResolvesForMember(): void {
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([
			$this->acl(Acl::TYPE_GROUP, 'clients', PermissionService::PERMISSION_READ, ViewerContext::ROLE_EXTERNAL),
		]);
		$this->userInGroups('bob', ['clients']);

		self::assertSame(
			ViewerContext::ROLE_EXTERNAL,
			$this->access->contextFor($this->board('alice'), 'bob')->role,
		);
	}

	public function testMultiEntryFoldIsInternalWins(): void {
		// bob matches an external direct entry AND an internal group entry:
		// the fold resolves internal (the wider side), and the permission
		// mask is the UNION of both rows.
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([
			$this->acl(Acl::TYPE_USER, 'bob', PermissionService::PERMISSION_READ, ViewerContext::ROLE_EXTERNAL),
			$this->acl(Acl::TYPE_GROUP, 'devs', PermissionService::PERMISSION_READ | PermissionService::PERMISSION_MANAGE, ViewerContext::ROLE_INTERNAL),
		]);
		$this->userInGroups('bob', ['devs']);

		$ctx = $this->access->contextFor($this->board('alice'), 'bob');

		self::assertSame(ViewerContext::ROLE_INTERNAL, $ctx->role);
		self::assertTrue($ctx->isManager);
	}

	public function testExternalManagerBitsAreStripped(): void {
		// An external member whose ACL mask carries MANAGE still resolves to
		// a non-manager context (their MANAGE bits keep working for board
		// admin endpoints via PermissionService - but the visibility-side
		// manager flag is internal-only).
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([
			$this->acl(Acl::TYPE_USER, 'bob', PermissionService::PERMISSION_ALL, ViewerContext::ROLE_EXTERNAL),
		]);

		$ctx = $this->access->contextFor($this->board('alice'), 'bob');

		self::assertSame(ViewerContext::ROLE_EXTERNAL, $ctx->role);
		self::assertFalse($ctx->isManager);
	}

	public function testNonMemberThrowsBeforeAnyCardQuery(): void {
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([
			$this->acl(Acl::TYPE_USER, 'carol', PermissionService::PERMISSION_ALL, ViewerContext::ROLE_INTERNAL),
			$this->acl(Acl::TYPE_GROUP, 'devs', PermissionService::PERMISSION_ALL, ViewerContext::ROLE_INTERNAL),
		]);
		$this->userInGroups('eve', ['guests']);

		$this->expectException(NotAMemberException::class);
		$this->access->contextFor($this->board('alice'), 'eve');
	}

	public function testLegacyNullRoleReadsAsInternal(): void {
		// Rows hydrated without the role column (pre-migration snapshots)
		// fold as 'internal', matching the migration backfill.
		$acl = new Acl();
		$acl->setParticipantType(Acl::TYPE_USER);
		$acl->setParticipant('bob');
		$acl->setPermission(PermissionService::PERMISSION_READ);
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([$acl]);

		self::assertSame(
			ViewerContext::ROLE_INTERNAL,
			$this->access->contextFor($this->board('alice'), 'bob')->role,
		);
	}

	// ── rolesFor: batched cross-board map ─────────────────────────────────────

	public function testRolesForBatchesIntoOneAclFetch(): void {
		$owned = $this->board('bob', 1);
		$internalDirect = $this->board('alice', 2);
		$externalViaGroup = $this->board('alice', 3);
		$mixedEntries = $this->board('alice', 4);
		$noAccess = $this->board('alice', 5);

		// ONE batched fetch over exactly the non-owned board ids - never a
		// per-board findByBoard() loop (mirrors getPermissionsForBoards).
		$this->aclMapper->expects(self::once())
			->method('findByBoards')
			->with([2, 3, 4, 5])
			->willReturn([
				$this->acl(Acl::TYPE_USER, 'bob', PermissionService::PERMISSION_READ, ViewerContext::ROLE_INTERNAL, 2),
				$this->acl(Acl::TYPE_GROUP, 'clients', PermissionService::PERMISSION_READ, ViewerContext::ROLE_EXTERNAL, 3),
				// Board 4: external direct + internal group → internal-wins.
				$this->acl(Acl::TYPE_USER, 'bob', PermissionService::PERMISSION_READ, ViewerContext::ROLE_EXTERNAL, 4),
				$this->acl(Acl::TYPE_GROUP, 'clients', PermissionService::PERMISSION_READ, ViewerContext::ROLE_INTERNAL, 4),
				// Board 5: rows addressing someone else grant no membership.
				$this->acl(Acl::TYPE_USER, 'carol', PermissionService::PERMISSION_ALL, ViewerContext::ROLE_INTERNAL, 5),
			]);
		$this->userInGroups('bob', ['clients']);

		$map = $this->access->rolesFor(
			[$owned, $internalDirect, $externalViaGroup, $mixedEntries, $noAccess],
			'bob',
		);

		self::assertSame([
			1 => ViewerContext::ROLE_INTERNAL,
			2 => ViewerContext::ROLE_INTERNAL,
			3 => ViewerContext::ROLE_EXTERNAL,
			4 => ViewerContext::ROLE_INTERNAL,
			// Board 5 absent: no membership, no role.
		], $map);
	}

	public function testRolesForAllOwnedSkipsTheAclFetch(): void {
		$this->aclMapper->expects(self::never())->method('findByBoards');

		self::assertSame(
			[1 => ViewerContext::ROLE_INTERNAL, 2 => ViewerContext::ROLE_INTERNAL],
			$this->access->rolesFor([$this->board('bob', 1), $this->board('bob', 2)], 'bob'),
		);
	}

	public function testRolesForEmptySetIsEmpty(): void {
		$this->aclMapper->expects(self::never())->method('findByBoards');

		self::assertSame([], $this->access->rolesFor([], 'bob'));
	}
}
