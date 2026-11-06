<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Access;

use OCA\Kanso\Access\ProjectAccess;
use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\Project;
use OCA\Kanso\Db\ProjectShare;
use OCA\Kanso\Db\ProjectShareMapper;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProjectAccessTest extends TestCase {
	private const READ = PermissionService::PERMISSION_READ;
	private const EDIT = PermissionService::PERMISSION_EDIT;

	private ProjectShareMapper&MockObject $shareMapper;
	private PermissionService&MockObject $permissionService;
	private IDBConnection&MockObject $db;
	private ProjectAccess $access;

	protected function setUp(): void {
		parent::setUp();
		$this->shareMapper = $this->createMock(ProjectShareMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->access = new ProjectAccess(
			$this->db,
			$this->shareMapper,
			$this->permissionService,
		);
	}

	private function project(): Project {
		$project = new Project();
		$project->setId(5);
		$project->setOwner('alice');
		return $project;
	}

	private function share(int $type, string $participant, int $permission): ProjectShare {
		$share = new ProjectShare();
		$share->setParticipantType($type);
		$share->setParticipant($participant);
		$share->setPermission($permission);
		return $share;
	}

	public function testTheOwnerHoldsEveryBitWithoutAShare(): void {
		$this->shareMapper->expects(self::never())->method('findByProject');

		self::assertSame(PermissionService::PERMISSION_ALL, $this->access->getPermissions($this->project(), 'alice'));
	}

	public function testAGroupShareSkipsSomeoneOutsideTheGroup(): void {
		$this->permissionService->method('getUserGroupIds')->with('bob')->willReturn(['staff']);
		$shares = [$this->share(Acl::TYPE_GROUP, 'devs', self::READ)];

		self::assertSame(0, $this->access->foldPermissions($shares, 'bob'));
	}

	public function testAUserShareSkipsEveryoneElse(): void {
		$shares = [$this->share(Acl::TYPE_USER, 'carol', self::READ)];

		self::assertSame(0, $this->access->foldPermissions($shares, 'bob'));
	}

	public function testAGroupNamedLikeTheViewerGrantsNothingToANonMember(): void {
		$this->permissionService->method('getUserGroupIds')->with('bob')->willReturn(['staff']);
		$shares = [$this->share(Acl::TYPE_GROUP, 'bob', self::READ)];

		self::assertSame(0, $this->access->foldPermissions($shares, 'bob'));
	}

	public function testAUserNamedLikeTheViewersGroupGrantsNothing(): void {
		$this->permissionService->method('getUserGroupIds')->with('bob')->willReturn(['devs']);
		$shares = [$this->share(Acl::TYPE_USER, 'devs', self::READ)];

		self::assertSame(0, $this->access->foldPermissions($shares, 'bob'));
	}

	/**
	 * @param list<string> $groupIds
	 * @return array{string, array<string, array{mixed, mixed}>, string} share predicate, bound parameters, outer predicate
	 */
	private function restrictFor(array $groupIds): array {
		$this->permissionService->method('getUserGroupIds')->with('bob')->willReturn($groupIds);
		$render = new class {
			public function __call(string $name, array $args): string {
				return $name . '(' . implode(',', array_map(strval(...), $args)) . ')';
			}
		};

		$shareWhere = '';
		$sub = $this->createMock(IQueryBuilder::class);
		$sub->method('expr')->willReturn($render);
		$sub->method('select')->willReturnSelf();
		$sub->method('from')->willReturnSelf();
		$sub->method('where')->willReturnCallback(function (string $predicate) use ($sub, &$shareWhere): IQueryBuilder {
			$shareWhere = $predicate;
			return $sub;
		});
		$sub->method('getSQL')->willReturn('SHARES');
		$this->db->method('getQueryBuilder')->willReturn($sub);

		$params = [];
		$outerWhere = '';
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($render);
		$qb->method('createFunction')->willReturnArgument(0);
		$qb->method('createNamedParameter')->willReturnCallback(function (mixed $value, mixed $type = IQueryBuilder::PARAM_STR) use (&$params): string {
			$token = ':p' . count($params);
			$params[$token] = [$value, $type];
			return $token;
		});
		$qb->method('andWhere')->willReturnCallback(function (string $predicate) use ($qb, &$outerWhere): IQueryBuilder {
			$outerWhere = $predicate;
			return $qb;
		});

		$this->access->restrict($qb, 'p', 'bob');

		return [$shareWhere, $params, $outerWhere];
	}

	public function testRestrictMatchesUserRowsOnlyAsUsersAndGroupRowsOnlyAsGroups(): void {
		[$shares, $params, $outer] = $this->restrictFor(['staff']);

		self::assertSame(
			'orX(andX(eq(s.participant_type,:p0),eq(s.participant,:p1)),andX(eq(s.participant_type,:p2),in(s.participant,:p3)))',
			$shares,
		);
		self::assertSame([Acl::TYPE_USER, IQueryBuilder::PARAM_INT], $params[':p0']);
		self::assertSame('bob', $params[':p1'][0]);
		self::assertSame([Acl::TYPE_GROUP, IQueryBuilder::PARAM_INT], $params[':p2']);
		self::assertSame([['staff'], IQueryBuilder::PARAM_STR_ARRAY], $params[':p3']);
		self::assertSame('orX(eq(p.owner,:p4),in(p.id,SHARES))', $outer);
		self::assertSame('bob', $params[':p4'][0]);
	}

	public function testRestrictSkipsTheGroupBranchForAViewerWithoutGroups(): void {
		[$shares] = $this->restrictFor([]);

		self::assertSame('orX(andX(eq(s.participant_type,:p0),eq(s.participant,:p1)))', $shares);
	}

	public function testDirectAndGroupSharesCombine(): void {
		$this->permissionService->method('getUserGroupIds')->willReturn(['devs']);
		$shares = [
			$this->share(Acl::TYPE_USER, 'bob', self::READ),
			$this->share(Acl::TYPE_GROUP, 'devs', self::READ | self::EDIT),
		];

		self::assertSame(self::READ | self::EDIT, $this->access->foldPermissions($shares, 'bob'));
	}

	public function testAssertPermissionNeedsEveryRequestedBit(): void {
		$this->shareMapper->method('findByProject')->willReturn([$this->share(Acl::TYPE_USER, 'bob', self::READ)]);

		$this->expectException(NotPermittedException::class);
		$this->access->assertPermission($this->project(), 'bob', self::READ | self::EDIT);
	}

	public function testARecipientFailsAssertOwner(): void {
		$this->access->assertOwner($this->project(), 'alice');
		$this->shareMapper->method('findByProject')->willReturn([$this->share(Acl::TYPE_USER, 'bob', self::READ)]);

		$this->expectException(NotPermittedException::class);
		$this->access->assertOwner($this->project(), 'bob');
	}

	public function testAssertOwnerHidesTheProjectFromStrangers(): void {
		$this->shareMapper->method('findByProject')->willReturn([]);

		$this->expectException(DoesNotExistException::class);
		$this->access->assertOwner($this->project(), 'mallory');
	}

	public function testAssertPermissionHidesTheProjectFromStrangers(): void {
		$this->shareMapper->method('findByProject')->willReturn([$this->share(Acl::TYPE_USER, 'bob', self::READ)]);

		$this->expectException(DoesNotExistException::class);
		$this->access->assertPermission($this->project(), 'mallory', self::READ);
	}
}
