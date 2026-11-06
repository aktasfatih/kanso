<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Acl;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\ShareeSearchService;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ShareeSearchServiceTest extends TestCase {
	private IUserManager&MockObject $userManager;
	private IGroupManager&MockObject $groupManager;

	/** @var array<string, IUser> */
	private array $extraUsers = [];
	/** @var array<string, array<string, string>> gid => uid => display name */
	private array $groupMembers = [];
	/** @var list<array{string, string, int}> */
	private array $groupQueries = [];

	/** @var array<string, mixed> */
	private array $settings = [
		'shareApiEnabled' => true,
		'sharingDisabledForUser' => false,
		'allowGroupSharing' => true,
		'allowEnumeration' => true,
		'allowEnumerationFullMatch' => true,
		'limitEnumerationToGroups' => false,
		'limitEnumerationToPhone' => false,
		'shareWithGroupMembersOnly' => false,
		'shareWithGroupMembersOnlyExcludeGroupsList' => [],
	];

	protected function setUp(): void {
		parent::setUp();
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('getUserGroupIds')->willReturnCallback(
			static fn (IUser $user): array => match ($user->getUID()) {
				'alice' => ['team'],
				'bob' => ['team'],
				default => ['other'],
			}
		);
		$this->userManager->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => match (strtolower($uid)) {
				'alice', 'bob', 'carol' => $this->user(strtolower($uid)),
				default => $this->extraUsers[$uid] ?? null,
			}
		);
		$this->groupManager->method('displayNamesInGroup')->willReturnCallback(
			function (string $gid, string $search, int $limit): array {
				$this->groupQueries[] = [$gid, $search, $limit];
				return $this->groupMembers[$gid] ?? [];
			}
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @param array<string, string> $config core app config values
	 */
	private function service(array $overrides = [], array $config = []): ShareeSearchService {
		$share = $this->createMock(IShareManager::class);
		foreach ($overrides + $this->settings as $method => $value) {
			$share->method($method)->willReturn($value);
		}
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => $app === 'core' ? ($config[$key] ?? $default) : $default
		);
		return new ShareeSearchService($this->userManager, $this->groupManager, $share, $appConfig);
	}

	private function user(string $uid, string $name = '', bool $enabled = true): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($name === '' ? $uid : $name);
		$user->method('isEnabled')->willReturn($enabled);
		return $user;
	}

	private function group(string $gid, bool $hidden = false): IGroup&MockObject {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($gid);
		$group->method('getDisplayName')->willReturn($gid);
		$group->method('hideFromCollaboration')->willReturn($hidden);
		return $group;
	}

	/** @return list<string> */
	private function ids(array $results): array {
		return array_column($results, 'id');
	}

	public function testResolvesTheCanonicalUid(): void {
		self::assertSame('bob', $this->service()->resolveParticipant(Acl::TYPE_USER, 'BOB', 'alice'));
	}

	public function testResolvesTheCanonicalGid(): void {
		$this->groupManager->method('get')->with('TEAM')->willReturn($this->group('team'));

		self::assertSame('team', $this->service()->resolveParticipant(Acl::TYPE_GROUP, 'TEAM', 'alice'));
	}

	public function testAllowsTheActorsOwnGroupUnderMembersOnly(): void {
		$this->groupManager->method('get')->with('team')->willReturn($this->group('team'));

		self::assertSame('team', $this->service(['shareWithGroupMembersOnly' => true])->resolveParticipant(Acl::TYPE_GROUP, 'team', 'alice'));
	}

	/** @return array<string, array{array<string, mixed>, int, string}> */
	public static function refusedTargets(): array {
		return [
			'sharing off' => [['shareApiEnabled' => false], Acl::TYPE_USER, 'bob'],
			'actor excluded' => [['sharingDisabledForUser' => true], Acl::TYPE_USER, 'bob'],
			'missing user' => [[], Acl::TYPE_USER, 'ghost'],
			'user outside the actor groups' => [['shareWithGroupMembersOnly' => true], Acl::TYPE_USER, 'carol'],
			'shared group is excluded' => [
				['shareWithGroupMembersOnly' => true, 'shareWithGroupMembersOnlyExcludeGroupsList' => ['team']],
				Acl::TYPE_USER,
				'bob',
			],
			'group sharing off' => [['allowGroupSharing' => false], Acl::TYPE_GROUP, 'team'],
			'missing group' => [[], Acl::TYPE_GROUP, 'ghosts'],
			'group the actor is not in' => [['shareWithGroupMembersOnly' => true], Acl::TYPE_GROUP, 'other'],
			'group on the exclude list' => [
				['shareWithGroupMembersOnly' => true, 'shareWithGroupMembersOnlyExcludeGroupsList' => ['team']],
				Acl::TYPE_GROUP,
				'team',
			],
		];
	}

	/**
	 * @dataProvider refusedTargets
	 * @param array<string, mixed> $settings
	 */
	public function testRefusesWithOneMessage(array $settings, int $type, string $participant): void {
		$this->groupManager->method('get')->willReturnCallback(
			fn (string $gid): ?IGroup => in_array($gid, ['team', 'other'], true) ? $this->group($gid) : null
		);

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('Cannot share with this participant');
		$this->service($settings)->resolveParticipant($type, $participant, 'alice');
	}

	public function testAllowsAGroupMateUnderMembersOnly(): void {
		self::assertSame('bob', $this->service(['shareWithGroupMembersOnly' => true])->resolveParticipant(Acl::TYPE_USER, 'bob', 'alice'));
	}

	public function testSearchIsEmptyWhenTheActorMayNotShare(): void {
		$this->userManager->expects(self::never())->method('searchDisplayName');

		self::assertSame([], $this->service(['sharingDisabledForUser' => true])->search('bob', 'alice', [], []));
		self::assertSame([], $this->service(['shareApiEnabled' => false])->search('bob', 'alice', [], []));
	}

	public function testSearchTrimsTheQuery(): void {
		$this->userManager->expects(self::once())->method('searchDisplayName')->with('bob', 25)->willReturn([]);
		$this->groupManager->method('search')->with('bob', 25)->willReturn([]);

		$this->service()->search(' bob ', 'alice', [], []);
	}

	public function testSearchSkipsDisabledUsers(): void {
		$this->userManager->method('searchDisplayName')->willReturn([$this->user('bob', 'Bob', false), $this->user('bobby')]);
		$this->groupManager->method('search')->willReturn([]);

		self::assertSame(['bobby'], $this->ids($this->service()->search('bo', 'alice', [], [])));
	}

	public function testExactHitNeedsFullMatchUnderGroupLimit(): void {
		$this->userManager->method('searchDisplayName')->willReturn([$this->user('carol')]);
		$this->groupManager->method('search')->willReturn([]);

		$limited = ['limitEnumerationToGroups' => true];
		self::assertSame(['carol'], $this->ids($this->service($limited)->search('carol', 'alice', [], [])));
		self::assertSame([], $this->service($limited + ['allowEnumerationFullMatch' => false])->search('carol', 'alice', [], []));
	}

	public function testPhonebookLimitKeepsOnlyKnownUsers(): void {
		$known = $this->user('bobby');
		$this->userManager->method('searchDisplayName')->willReturn([$this->user('bob'), $known]);
		$this->userManager->method('searchKnownUsersByDisplayName')->with('alice', 'bo', 25)->willReturn([$known]);
		$this->groupManager->method('search')->willReturn([]);

		self::assertSame(['bobby'], $this->ids($this->service(['limitEnumerationToPhone' => true])->search('bo', 'alice', [], [])));
	}

	public function testEitherLimitAdmitsAWideHit(): void {
		$this->groupMembers['team'] = ['bob' => 'bob'];
		$this->userManager->method('searchDisplayName')->willReturn([$this->user('erin')]);
		$this->userManager->method('searchKnownUsersByDisplayName')->willReturn([$this->user('dave')]);
		$this->groupManager->method('search')->willReturn([]);

		$results = $this->service(['limitEnumerationToPhone' => true, 'limitEnumerationToGroups' => true])->search('xx', 'alice', [], []);

		self::assertSame(['bob', 'dave'], $this->ids($results));
	}

	/** @return list<IUser> */
	private function strangers(int $count): array {
		return array_map(fn (int $i): IUser => $this->user('stranger' . $i), range(1, $count));
	}

	public function testGroupLimitFindsMatesOutsideTheGlobalSearchWindow(): void {
		$this->extraUsers['zoe'] = $this->user('zoe');
		$this->groupMembers['team'] = ['zoe' => 'zoe'];
		$this->userManager->method('searchDisplayName')->willReturn($this->strangers(25));
		$this->groupManager->method('search')->willReturn([]);

		$results = $this->service(['limitEnumerationToGroups' => true])->search('zo', 'alice', [], []);

		self::assertSame(['zoe'], $this->ids($results));
		self::assertSame([['team', 'zo', 25]], $this->groupQueries);
	}

	public function testPhonebookLimitFindsContactsOutsideTheGlobalSearchWindow(): void {
		$this->userManager->method('searchDisplayName')->willReturn($this->strangers(25));
		$this->userManager->method('searchKnownUsersByDisplayName')->willReturn([$this->user('zoe')]);
		$this->groupManager->method('search')->willReturn([]);

		self::assertSame(['zoe'], $this->ids($this->service(['limitEnumerationToPhone' => true])->search('zo', 'alice', [], [])));
	}

	public function testALimitedSearchReturnsAtMostOnePage(): void {
		foreach (range(1, 30) as $i) {
			$uid = 'mate' . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
			$this->extraUsers[$uid] = $this->user($uid);
			$this->groupMembers['team'][$uid] = $uid;
		}
		$this->userManager->method('searchDisplayName')->willReturn([]);
		$this->groupManager->method('search')->willReturn([]);

		$ids = $this->ids($this->service(['limitEnumerationToGroups' => true])->search('mate', 'alice', [], []));

		self::assertCount(25, $ids);
		self::assertSame('mate01', $ids[0]);
	}

	public function testExactUidIsFoundBeyondTheSearchWindowAndComesFirst(): void {
		$this->userManager->method('searchDisplayName')->willReturn($this->strangers(25));
		$this->groupManager->method('search')->willReturn([]);

		self::assertSame('carol', $this->ids($this->service()->search('CAROL', 'alice', [], []))[0]);
	}

	public function testWideHitsAreInDisplayNameOrder(): void {
		$this->userManager->method('searchDisplayName')->willReturn([$this->user('u1', 'zed'), $this->user('u2', 'Amy')]);
		$this->groupManager->method('search')->willReturn([]);

		self::assertSame(['u2', 'u1'], $this->ids($this->service()->search('xx', 'alice', [], [])));
	}

	public function testExactHitsAreInDisplayNameOrder(): void {
		$this->extraUsers['bo'] = $this->user('bo', 'Aaron');
		$this->userManager->method('searchDisplayName')->willReturn([$this->user('u1', 'Bo')]);
		$this->groupManager->method('search')->willReturn([]);

		self::assertSame(['bo', 'u1'], $this->ids($this->service()->search('bo', 'alice', [], [])));
	}

	public function testExactUidSurvivesWithEnumerationOff(): void {
		$this->userManager->expects(self::once())->method('searchDisplayName')->willReturn([]);
		$this->groupManager->method('search')->willReturn([]);

		self::assertSame(['carol'], $this->ids($this->service(['allowEnumeration' => false])->search('carol', 'alice', [], [])));
	}

	public function testALoginAliasIsNotAUidMatch(): void {
		$this->extraUsers['carol@example.org'] = $this->user('carol');
		$this->userManager->method('searchDisplayName')->willReturn([]);
		$this->groupManager->method('search')->willReturn([]);

		self::assertSame([], $this->service(['allowEnumeration' => false])->search('carol@example.org', 'alice', [], []));
	}

	public function testUidMatchObeysItsSetting(): void {
		$this->userManager->method('searchDisplayName')->willReturn([]);
		$this->groupManager->method('search')->willReturn([]);

		$results = $this->service(
			['allowEnumeration' => false],
			['shareapi_restrict_user_enumeration_full_match_user_id' => 'no'],
		)->search('carol', 'alice', [], []);

		self::assertSame([], $results);
	}

	public function testDisplayNameMatchObeysItsSetting(): void {
		$this->userManager->method('searchDisplayName')->willReturn([$this->user('u1', 'Carol Jones')]);
		$this->groupManager->method('search')->willReturn([]);
		$off = ['shareapi_restrict_user_enumeration_full_match_displayname' => 'no'];

		self::assertSame(['u1'], $this->ids($this->service(['allowEnumeration' => false])->search('carol jones', 'alice', [], [])));
		self::assertSame([], $this->service(['allowEnumeration' => false], $off)->search('carol jones', 'alice', [], []));
	}

	public function testEachFullMatchSettingLeavesTheOtherHalfWorking(): void {
		$this->userManager->method('searchDisplayName')->willReturn([$this->user('u1', 'Carol')]);
		$this->groupManager->method('search')->willReturn([]);

		$byName = $this->service(['allowEnumeration' => false], ['shareapi_restrict_user_enumeration_full_match_user_id' => 'no'])
			->search('carol', 'alice', [], []);
		$byUid = $this->service(['allowEnumeration' => false], ['shareapi_restrict_user_enumeration_full_match_displayname' => 'no'])
			->search('carol', 'alice', [], []);

		self::assertSame(['u1'], $this->ids($byName));
		self::assertSame(['carol'], $this->ids($byUid));
	}

	public function testFullMatchOffDropsExactLookups(): void {
		$this->userManager->expects(self::never())->method('searchDisplayName');
		$this->groupManager->method('search')->willReturn([]);

		$results = $this->service(['limitEnumerationToGroups' => true, 'allowEnumerationFullMatch' => false])->search('carol', 'alice', [], []);

		self::assertSame([], $results);
	}

	public function testAnExactUidStillObeysMembersOnly(): void {
		$this->userManager->method('searchDisplayName')->willReturn([]);
		$this->groupManager->method('search')->willReturn([]);

		self::assertSame([], $this->service(['shareWithGroupMembersOnly' => true])->search('carol', 'alice', [], []));
	}

	public function testAnExactUidStillHonoursTheExcludedUsers(): void {
		$this->userManager->method('searchDisplayName')->willReturn([]);
		$this->groupManager->method('search')->willReturn([]);

		self::assertSame([], $this->service()->search('carol', 'alice', ['carol' => true], []));
	}

	public function testGroupsNeedAnExactMatchWithEnumerationOff(): void {
		$this->userManager->method('searchDisplayName')->willReturn([]);
		$this->groupManager->method('search')->willReturn([$this->group('devs'), $this->group('devops')]);

		self::assertSame(['devs'], $this->ids($this->service(['allowEnumeration' => false])->search('devs', 'alice', [], [])));
	}

	public function testSkipsGroupsHiddenFromCollaboration(): void {
		$this->userManager->method('searchDisplayName')->willReturn([]);
		$this->groupManager->method('search')->willReturn([$this->group('devs', true), $this->group('design')]);

		self::assertSame(['design'], $this->ids($this->service()->search('de', 'alice', [], [])));
	}
}
