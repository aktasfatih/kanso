<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Listener;

use OCA\Kanso\Cron\SettleOrphanedProject;
use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\Project;
use OCA\Kanso\Db\ProjectMapper;
use OCA\Kanso\Db\ProjectPinMapper;
use OCA\Kanso\Db\ProjectShareMapper;
use OCA\Kanso\Listener\ProjectShareCleanupListener;
use OCA\Kanso\Service\ProjectService;
use OCP\BackgroundJob\IJobList;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\IGroup;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProjectShareCleanupListenerTest extends TestCase {
	private function listener(ProjectShareMapper $shares, ProjectPinMapper $pins): ProjectShareCleanupListener {
		return new ProjectShareCleanupListener(
			$shares,
			$pins,
			$this->createMock(ProjectMapper::class),
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
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');

		$this->listener($shares, $pins)->handle(new UserDeletedEvent($user));
	}

	public function testADeletedGroupLosesItsShares(): void {
		$shares = $this->createMock(ProjectShareMapper::class);
		$pins = $this->createMock(ProjectPinMapper::class);
		$shares->expects(self::once())->method('deleteByParticipant')->with(Acl::TYPE_GROUP, 'devs');
		$pins->expects(self::never())->method('deleteByUser');
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('devs');

		$this->listener($shares, $pins)->handle(new GroupDeletedEvent($group));
	}
}
