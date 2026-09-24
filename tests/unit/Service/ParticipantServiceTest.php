<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\AclMapper;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\ParticipantService;
use OCA\Kanso\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ParticipantServiceTest extends TestCase {
	private BoardMapper&MockObject $boardMapper;
	private AclMapper&MockObject $aclMapper;
	private PermissionService&MockObject $permissionService;
	private IUserManager&MockObject $userManager;
	private IGroupManager&MockObject $groupManager;
	private ParticipantService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->aclMapper = $this->createMock(AclMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->service = new ParticipantService(
			$this->boardMapper,
			$this->aclMapper,
			$this->permissionService,
			$this->userManager,
			$this->groupManager
		);
	}

	private function board(int $id = 1): Board {
		$board = new Board();
		$board->setId($id);
		$board->setOwner('alice');
		$board->setDeletedAt(0);
		return $board;
	}

	private function userAcl(string $uid): Acl {
		$acl = new Acl();
		$acl->setBoardId(1);
		$acl->setParticipantType(Acl::TYPE_USER);
		$acl->setParticipant($uid);
		$acl->setPermission(PermissionService::PERMISSION_READ);
		return $acl;
	}

	private function groupAcl(string $gid): Acl {
		$acl = new Acl();
		$acl->setBoardId(1);
		$acl->setParticipantType(Acl::TYPE_GROUP);
		$acl->setParticipant($gid);
		$acl->setPermission(PermissionService::PERMISSION_READ);
		return $acl;
	}

	private function user(string $uid, string $displayName): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($displayName);
		return $user;
	}

	public function testReturnsOwnerUserAclsAndExpandedGroupsSortedAndDeduped(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		// bob is shared with directly AND a member of 'devs' - must appear once.
		$this->aclMapper->method('findByBoard')->with(1)
			->willReturn([$this->userAcl('bob'), $this->groupAcl('devs')]);

		$this->userManager->method('get')->willReturnMap([
			['alice', $this->user('alice', 'Alice Adams')],
			['bob', $this->user('bob', 'Zed Bobson')],
		]);

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([
			$this->user('bob', 'Zed Bobson'),
			$this->user('carol', 'Carol Chen'),
		]);
		$this->groupManager->method('get')->with('devs')->willReturn($group);

		// Directly-reachable members (owner + user ACLs) form the first tier,
		// sorted by display name, ahead of the group-expanded tier - so bob (a
		// direct user ACL, "Zed Bobson") precedes carol (group-only) even though
		// his name sorts later. This tiering is what keeps directly-shared
		// members inside the result cap on large-group boards.
		$participants = $this->service->getParticipants(1, 'alice');
		self::assertSame([
			['uid' => 'alice', 'displayName' => 'Alice Adams'],
			['uid' => 'bob', 'displayName' => 'Zed Bobson'],
			['uid' => 'carol', 'displayName' => 'Carol Chen'],
		], $participants);
	}

	public function testFallsBackToUidWhenUserIsUnresolvable(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		// An ACL row can outlive its user - the ghost stays listed by uid.
		$this->aclMapper->method('findByBoard')->with(1)
			->willReturn([$this->userAcl('deleted-user')]);
		$this->userManager->method('get')->willReturnMap([
			['alice', $this->user('alice', 'Alice Adams')],
			['deleted-user', null],
		]);

		$participants = $this->service->getParticipants(1, 'alice');
		self::assertSame([
			['uid' => 'alice', 'displayName' => 'Alice Adams'],
			['uid' => 'deleted-user', 'displayName' => 'deleted-user'],
		], $participants);
	}

	public function testSkipsUnresolvableGroups(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->aclMapper->method('findByBoard')->with(1)
			->willReturn([$this->groupAcl('vanished-group')]);
		$this->userManager->method('get')
			->with('alice')
			->willReturn($this->user('alice', 'Alice Adams'));
		$this->groupManager->method('get')->with('vanished-group')->willReturn(null);

		$participants = $this->service->getParticipants(1, 'alice');
		self::assertSame([
			['uid' => 'alice', 'displayName' => 'Alice Adams'],
		], $participants);
	}

	public function testAssertsReadPermission(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_READ)
			->willThrowException(new NotPermittedException());
		$this->aclMapper->expects(self::never())->method('findByBoard');

		$this->expectException(NotPermittedException::class);
		$this->service->getParticipants(1, 'mallory');
	}

	public function testRejectsDeletedBoard(): void {
		$board = $this->board();
		$board->setDeletedAt(1234);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->expects(self::never())->method('findByBoard');

		$this->expectException(DoesNotExistException::class);
		$this->service->getParticipants(1, 'alice');
	}

	public function testQueryFiltersByDisplayNameAndUidCaseInsensitively(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->aclMapper->method('findByBoard')->with(1)
			->willReturn([$this->userAcl('bob'), $this->groupAcl('devs')]);

		$this->userManager->method('get')->willReturnMap([
			['alice', $this->user('alice', 'Alice Adams')],
			['bob', $this->user('bob', 'Bob Baker')],
		]);

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([
			$this->user('carol', 'Carol Chen'),
			// uid matches "bob" even though the display name does not.
			$this->user('bobby', 'Robert Roe'),
		]);
		$this->groupManager->method('get')->with('devs')->willReturn($group);

		// "bob" matches Bob Baker (display name) and bobby (uid), not the others.
		$participants = $this->service->getParticipants(1, 'alice', 'BOB');
		self::assertSame([
			['uid' => 'bob', 'displayName' => 'Bob Baker'],
			['uid' => 'bobby', 'displayName' => 'Robert Roe'],
		], $participants);
	}

	public function testEmptyQueryReturnsCappedSet(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->aclMapper->method('findByBoard')->with(1)
			->willReturn([$this->groupAcl('devs')]);
		$this->userManager->method('get')->with('alice')
			->willReturn($this->user('alice', 'Alice Adams'));

		// A 40-member group; with the owner that is 41 candidates, capped to 25.
		$members = [];
		for ($i = 0; $i < 40; $i++) {
			$members[] = $this->user(sprintf('m%02d', $i), sprintf('Member %02d', $i));
		}
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($members);
		$this->groupManager->method('get')->with('devs')->willReturn($group);

		// Empty q (null) still applies the cap.
		$participants = $this->service->getParticipants(1, 'alice', null);
		self::assertCount(25, $participants);
	}

	public function testDirectMembersSurviveTheCapAheadOfLargeGroup(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		// A directly-shared user whose name sorts LAST must still survive the cap
		// - the frontend relies on this list to resolve their display name.
		$this->aclMapper->method('findByBoard')->with(1)
			->willReturn([$this->userAcl('zoe'), $this->groupAcl('devs')]);

		$this->userManager->method('get')->willReturnMap([
			['alice', $this->user('alice', 'Alice Adams')],
			['zoe', $this->user('zoe', 'Zoe Zimmer')],
		]);

		// 100 group members whose names all sort before "Zoe Zimmer".
		$members = [];
		for ($i = 0; $i < 100; $i++) {
			$members[] = $this->user(sprintf('g%03d', $i), sprintf('Group %03d', $i));
		}
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($members);
		$this->groupManager->method('get')->with('devs')->willReturn($group);

		$participants = $this->service->getParticipants(1, 'alice', null);
		self::assertCount(25, $participants);
		$uids = array_column($participants, 'uid');
		// Owner and the directly-shared user are present despite 100 group members.
		self::assertContains('alice', $uids);
		self::assertContains('zoe', $uids);
		self::assertSame(
			['uid' => 'zoe', 'displayName' => 'Zoe Zimmer'],
			$participants[array_search('zoe', $uids, true)]
		);
	}

	public function testQueryMatchingManyGroupMembersIsCapped(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->aclMapper->method('findByBoard')->with(1)
			->willReturn([$this->groupAcl('devs')]);
		$this->userManager->method('get')->with('alice')
			->willReturn($this->user('alice', 'Alice Adams'));

		$members = [];
		for ($i = 0; $i < 50; $i++) {
			$members[] = $this->user(sprintf('dev%02d', $i), sprintf('Developer %02d', $i));
		}
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($members);
		$this->groupManager->method('get')->with('devs')->willReturn($group);

		// All 50 match "developer"; result is still capped at 25.
		$participants = $this->service->getParticipants(1, 'alice', 'developer');
		self::assertCount(25, $participants);
		foreach ($participants as $p) {
			self::assertStringContainsStringIgnoringCase('developer', $p['displayName']);
		}
	}

	// ---- the cap, reported (#10704) ----------------------------------------
	//
	// The cap itself is old; what was missing is any way for a caller to know it
	// bit. Without that the picker showed the first page as if it were the board
	// and everyone past it was unassignable with nothing saying so.

	/**
	 * Board owned by alice, shared with one group of $size members named
	 * "Member NN" (uid mNN).
	 */
	private function boardWithGroupOf(int $size): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->aclMapper->method('findByBoard')->with(1)
			->willReturn([$this->groupAcl('devs')]);
		$this->userManager->method('get')->with('alice')
			->willReturn($this->user('alice', 'Alice Adams'));

		$members = [];
		for ($i = 0; $i < $size; $i++) {
			$members[] = $this->user(sprintf('m%02d', $i), sprintf('Member %02d', $i));
		}
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($members);
		$this->groupManager->method('get')->with('devs')->willReturn($group);
	}

	public function testSearchReportsTheCapWhenItActuallyShedPeople(): void {
		$this->boardWithGroupOf(40);

		$page = $this->service->searchParticipants(1, 'alice', null);
		self::assertCount(ParticipantService::RESULT_LIMIT, $page['participants']);
		self::assertTrue($page['truncated']);
		self::assertSame(ParticipantService::RESULT_LIMIT, $page['limit']);
	}

	public function testSearchReportsAShortBoardAsComplete(): void {
		// Owner + 3 members: everybody fits, so the picker may say so and show
		// no caveat at all. Over-reporting here would nag on every small board.
		$this->boardWithGroupOf(3);

		$page = $this->service->searchParticipants(1, 'alice', null);
		self::assertCount(4, $page['participants']);
		self::assertFalse($page['truncated']);
	}

	public function testSearchReportsAnExactlyFullPageAsComplete(): void {
		// Owner + 24 members = exactly the cap, with nothing held back.
		$this->boardWithGroupOf(ParticipantService::RESULT_LIMIT - 1);

		$page = $this->service->searchParticipants(1, 'alice', null);
		self::assertCount(ParticipantService::RESULT_LIMIT, $page['participants']);
		self::assertFalse($page['truncated']);
	}

	public function testSearchReachesAMemberTheCapShedsAndReportsTheNarrowedListComplete(): void {
		// THE defect: "Member 39" sorts past the cap, so no unfiltered page will
		// ever list them. Searching for them must - and the narrowed result is
		// itself complete, so the picker stops hedging.
		$this->boardWithGroupOf(40);

		$unfiltered = array_column($this->service->getParticipants(1, 'alice'), 'uid');
		self::assertNotContains('m39', $unfiltered);

		$page = $this->service->searchParticipants(1, 'alice', 'member 39');
		self::assertSame([['uid' => 'm39', 'displayName' => 'Member 39']], $page['participants']);
		self::assertFalse($page['truncated']);
	}

	public function testSearchReportsTheCapOnAQueryThatMatchesTooMany(): void {
		// A substring broad enough to match everyone is capped like any other
		// page, and says so rather than silently dropping the tail.
		$this->boardWithGroupOf(40);

		$page = $this->service->searchParticipants(1, 'alice', 'member');
		self::assertCount(ParticipantService::RESULT_LIMIT, $page['participants']);
		self::assertTrue($page['truncated']);
	}

	public function testSearchReportsTheCapWhenDirectMembersAlreadyFillIt(): void {
		// 30 direct user ACLs plus a group share. The group is deliberately NOT
		// expanded (that expansion is the cost the cap exists to avoid), so the
		// answer is the safe one: partial.
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$acls = [];
		$map = [['alice', $this->user('alice', 'Alice Adams')]];
		for ($i = 0; $i < 30; $i++) {
			$uid = sprintf('u%02d', $i);
			$acls[] = $this->userAcl($uid);
			$map[] = [$uid, $this->user($uid, sprintf('User %02d', $i))];
		}
		$acls[] = $this->groupAcl('devs');
		$this->aclMapper->method('findByBoard')->with(1)->willReturn($acls);
		$this->userManager->method('get')->willReturnMap($map);
		// Expanding the group would be the regression this guards against.
		$this->groupManager->expects(self::never())->method('get');

		$page = $this->service->searchParticipants(1, 'alice', null);
		self::assertCount(ParticipantService::RESULT_LIMIT, $page['participants']);
		self::assertTrue($page['truncated']);
	}

	public function testSearchRefusesANonMemberBeforeEnumeratingAnyone(): void {
		// Denial: searching is still a read of the board's membership, so a
		// non-member must not be able to probe who is on it one substring at a
		// time. The check happens BEFORE any ACL or directory lookup.
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_READ)
			->willThrowException(new NotPermittedException());
		$this->aclMapper->expects(self::never())->method('findByBoard');
		$this->groupManager->expects(self::never())->method('get');

		$this->expectException(NotPermittedException::class);
		$this->service->searchParticipants(1, 'mallory', 'alice');
	}

	public function testSearchRejectsADeletedBoard(): void {
		$board = $this->board();
		$board->setDeletedAt(1234);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->aclMapper->expects(self::never())->method('findByBoard');

		$this->expectException(DoesNotExistException::class);
		$this->service->searchParticipants(1, 'alice', 'alice');
	}

	public function testExternalRoleMembersAppearInTheAssigneePicker(): void {
		// #3744: the picker is role-blind by design - an external (client-side)
		// member must be assignable and @mentionable like anyone else.
		$externalAcl = $this->userAcl('client');
		$externalAcl->setRole(ViewerContext::ROLE_EXTERNAL);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([$externalAcl]);
		$this->userManager->method('get')->willReturnMap([
			['alice', $this->user('alice', 'Alice Adams')],
			['client', $this->user('client', 'Cli Ent')],
		]);

		$uids = array_column($this->service->getParticipants(1, 'alice'), 'uid');
		self::assertContains('client', $uids);
	}
}
