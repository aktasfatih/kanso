<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Listener;

use OCA\Kanso\Cron\SettleOrphanedProject;
use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\BoardGroupMapper;
use OCA\Kanso\Db\BoardGroupMemberMapper;
use OCA\Kanso\Db\BoardPinMapper;
use OCA\Kanso\Db\Project;
use OCA\Kanso\Db\ProjectMapper;
use OCA\Kanso\Db\ProjectPinMapper;
use OCA\Kanso\Db\ProjectShareMapper;
use OCA\Kanso\Listener\ProjectShareCleanupListener;
use OCA\Kanso\Service\ProjectService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\IGroup;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProjectShareCleanupListenerTest extends TestCase {
	private BoardPinMapper&MockObject $boardPins;
	private BoardGroupMemberMapper&MockObject $members;
	private BoardGroupMapper&MockObject $groups;

	protected function setUp(): void {
		parent::setUp();
		$this->boardPins = $this->createMock(BoardPinMapper::class);
		$this->members = $this->createMock(BoardGroupMemberMapper::class);
		$this->groups = $this->createMock(BoardGroupMapper::class);
	}

	private function listener(ProjectShareMapper $shares, ProjectPinMapper $pins, ?ProjectMapper $projects = null): ProjectShareCleanupListener {
		return new ProjectShareCleanupListener(
			$shares,
			$pins,
			$this->boardPins,
			$this->members,
			$this->groups,
			$projects ?? $this->createMock(ProjectMapper::class),
			$this->createMock(ProjectService::class),
			$this->createMock(IJobList::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function deletedBob(): UserDeletedEvent {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		return new UserDeletedEvent($user);
	}

	private function project(int $id): Project {
		$project = new Project();
		$project->setId($id);
		return $project;
	}

	public function testAFailedSettlementIsQueuedForRetry(): void {
		$projects = $this->createMock(ProjectMapper::class);
		$projects->method('findOwnedBy')->willReturn([$this->project(1), $this->project(2)]);
		$service = $this->createMock(ProjectService::class);
		$service->method('handOver')->willReturnCallback(static function (Project $project): void {
			if ($project->getId() === 1) {
				throw new \RuntimeException('boom');
			}
		});
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects(self::once())->method('add')->with(
			SettleOrphanedProject::class,
			['projectId' => 1, 'formerOwner' => 'bob', 'attempt' => 0],
		);

		(new ProjectShareCleanupListener(
			$this->createMock(ProjectShareMapper::class),
			$this->createMock(ProjectPinMapper::class),
			$this->boardPins,
			$this->members,
			$this->groups,
			$projects,
			$service,
			$jobs,
			$this->createMock(LoggerInterface::class),
		))->handle($this->deletedBob());
	}

	public function testAFailedEnqueueDoesNotAbortTheLoop(): void {
		$projects = $this->createMock(ProjectMapper::class);
		$projects->method('findOwnedBy')->willReturn([$this->project(1), $this->project(2)]);
		$service = $this->createMock(ProjectService::class);
		$service->expects(self::exactly(2))->method('handOver')->willThrowException(new \RuntimeException('boom'));
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects(self::exactly(2))->method('add')->willThrowException(new \RuntimeException('no job table'));

		(new ProjectShareCleanupListener(
			$this->createMock(ProjectShareMapper::class),
			$this->createMock(ProjectPinMapper::class),
			$this->boardPins,
			$this->members,
			$this->groups,
			$projects,
			$service,
			$jobs,
			$this->createMock(LoggerInterface::class),
		))->handle($this->deletedBob());
	}

	public function testOwnedProjectsAreSettledWhenTheRowCleanupFails(): void {
		$shares = $this->createMock(ProjectShareMapper::class);
		$shares->method('deleteByParticipant')->willThrowException(new \RuntimeException('boom'));
		$pins = $this->createMock(ProjectPinMapper::class);
		$pins->expects(self::once())->method('deleteByUser')->willThrowException(new \RuntimeException('boom'));
		$projects = $this->createMock(ProjectMapper::class);
		$projects->method('findOwnedBy')->willReturn([$this->project(1)]);
		$service = $this->createMock(ProjectService::class);
		$service->expects(self::once())->method('handOver');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::exactly(2))->method('error');

		(new ProjectShareCleanupListener(
			$shares,
			$pins,
			$this->boardPins,
			$this->members,
			$this->groups,
			$projects,
			$service,
			$this->createMock(IJobList::class),
			$logger,
		))->handle($this->deletedBob());
	}

	public function testEveryOwnedProjectIsSettledEvenIfOneFails(): void {
		$first = new Project();
		$first->setId(1);
		$second = new Project();
		$second->setId(2);
		$projects = $this->createMock(ProjectMapper::class);
		$projects->method('findOwnedBy')->with('bob')->willReturn([$first, $second]);
		$service = $this->createMock(ProjectService::class);
		$service->expects(self::exactly(2))->method('handOver')->willReturnCallback(
			static function (Project $project): void {
				if ($project->getId() === 1) {
					throw new \RuntimeException('boom');
				}
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');

		(new ProjectShareCleanupListener(
			$this->createMock(ProjectShareMapper::class),
			$this->createMock(ProjectPinMapper::class),
			$this->boardPins,
			$this->members,
			$this->groups,
			$projects,
			$service,
			$this->createMock(IJobList::class),
			$logger,
		))->handle(new UserDeletedEvent($user));
	}

	public function testADeletedUserLosesSharesAndPins(): void {
		$shares = $this->createMock(ProjectShareMapper::class);
		$pins = $this->createMock(ProjectPinMapper::class);
		$shares->expects(self::once())->method('deleteByParticipant')->with(Acl::TYPE_USER, 'bob');
		$pins->expects(self::once())->method('deleteByUser')->with('bob');
		$this->boardPins->expects(self::once())->method('deleteByUser')->with('bob');
		$this->members->expects(self::once())->method('deleteByUser')->with('bob');
		$this->groups->expects(self::once())->method('deleteByUser')->with('bob');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');

		$this->listener($shares, $pins)->handle(new UserDeletedEvent($user));
	}

	public function testADeletedGroupLosesItsShares(): void {
		$shares = $this->createMock(ProjectShareMapper::class);
		$pins = $this->createMock(ProjectPinMapper::class);
		$shares->expects(self::once())->method('deleteByParticipant')->with(Acl::TYPE_GROUP, 'devs');
		$pins->expects(self::never())->method('deleteByUser');
		$this->boardPins->expects(self::never())->method('deleteByUser');
		$this->members->expects(self::never())->method('deleteByUser');
		$this->groups->expects(self::never())->method('deleteByUser');
		$projects = $this->createMock(ProjectMapper::class);
		$projects->expects(self::never())->method('clearLeadByUser');
		$projects->expects(self::never())->method('findOwnedBy');
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('devs');

		$this->listener($shares, $pins, $projects)->handle(new GroupDeletedEvent($group));
	}

	public function testLeadsAreClearedBeforeOwnedProjectsAreLoaded(): void {
		$cleared = false;
		$projects = $this->createMock(ProjectMapper::class);
		$projects->expects(self::once())->method('clearLeadByUser')->with('bob')->willReturnCallback(
			static function () use (&$cleared): int {
				$cleared = true;
				return 2;
			}
		);
		$projects->expects(self::once())->method('findOwnedBy')->with('bob')->willReturnCallback(
			static function () use (&$cleared): array {
				self::assertTrue($cleared);
				return [];
			}
		);

		$this->listener(
			$this->createMock(ProjectShareMapper::class),
			$this->createMock(ProjectPinMapper::class),
			$projects,
		)->handle($this->deletedBob());
	}

	/** @return array<string, array{string}> */
	public static function cleanupFailures(): array {
		return [
			'shares' => ['shares'],
			'project pins' => ['pins'],
			'board pins' => ['boardPins'],
			'folder members' => ['members'],
			'folders' => ['groups'],
			'project leads' => ['leads'],
		];
	}

	#[DataProvider('cleanupFailures')]
	public function testOneCleanupFailureDoesNotSkipOtherCleanupOrSettlement(string $failure): void {
		$shares = $this->createMock(ProjectShareMapper::class);
		$pins = $this->createMock(ProjectPinMapper::class);
		$projects = $this->createMock(ProjectMapper::class);
		$operations = [
			'shares' => [$shares, 'deleteByParticipant', [Acl::TYPE_USER, 'bob']],
			'pins' => [$pins, 'deleteByUser', ['bob']],
			'boardPins' => [$this->boardPins, 'deleteByUser', ['bob']],
			'members' => [$this->members, 'deleteByUser', ['bob']],
			'groups' => [$this->groups, 'deleteByUser', ['bob']],
			'leads' => [$projects, 'clearLeadByUser', ['bob']],
		];
		foreach ($operations as $name => [$mapper, $method, $arguments]) {
			$expectation = $mapper->expects(self::once())->method($method)->with(...$arguments);
			if ($name === $failure) {
				$expectation->willThrowException(new \RuntimeException('Synthetic cleanup failure'));
			}
		}
		$project = $this->project(1);
		$projects->expects(self::once())->method('findOwnedBy')->with('bob')->willReturn([$project]);
		$service = $this->createMock(ProjectService::class);
		$service->expects(self::once())->method('handOver')->with($project, 'bob');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error');

		(new ProjectShareCleanupListener(
			$shares,
			$pins,
			$this->boardPins,
			$this->members,
			$this->groups,
			$projects,
			$service,
			$this->createMock(IJobList::class),
			$logger,
		))->handle($this->deletedBob());
	}

	public function testUnrelatedEventsDoNotCleanAnything(): void {
		$shares = $this->createMock(ProjectShareMapper::class);
		$pins = $this->createMock(ProjectPinMapper::class);
		$projects = $this->createMock(ProjectMapper::class);
		$shares->expects(self::never())->method('deleteByParticipant');
		$pins->expects(self::never())->method('deleteByUser');
		$this->boardPins->expects(self::never())->method('deleteByUser');
		$this->members->expects(self::never())->method('deleteByUser');
		$this->groups->expects(self::never())->method('deleteByUser');
		$projects->expects(self::never())->method('clearLeadByUser');
		$projects->expects(self::never())->method('findOwnedBy');

		$this->listener($shares, $pins, $projects)->handle(new Event());
	}
}
