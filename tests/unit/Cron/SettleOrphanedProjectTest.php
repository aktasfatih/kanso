<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Cron;

use OCA\Kanso\Cron\SettleOrphanedProject;
use OCA\Kanso\Db\Project;
use OCA\Kanso\Db\ProjectMapper;
use OCA\Kanso\Service\ProjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SettleOrphanedProjectTest extends TestCase {
	private ProjectMapper&MockObject $projects;
	private ProjectService&MockObject $service;
	private IUserManager&MockObject $users;
	private IJobList&MockObject $jobs;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->projects = $this->createMock(ProjectMapper::class);
		$this->service = $this->createMock(ProjectService::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->jobs = $this->createMock(IJobList::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function project(string $owner = 'gone'): Project {
		$project = new Project();
		$project->setId(5);
		$project->setOwner($owner);
		return $project;
	}

	private function runJob(mixed $argument): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000);
		$job = new SettleOrphanedProject($time, $this->projects, $this->service, $this->users, $this->jobs, $this->logger);
		(new \ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}

	/** @return array{projectId: int, formerOwner: string, attempt: int} */
	private function args(int $attempt = 0): array {
		return ['projectId' => 5, 'formerOwner' => 'gone', 'attempt' => $attempt];
	}

	public function testSettlesTheProject(): void {
		$project = $this->project();
		$this->projects->method('find')->with(5)->willReturn($project);
		$this->service->expects(self::once())->method('handOver')->with($project, 'gone');
		$this->jobs->expects(self::never())->method('scheduleAfter');

		$this->runJob($this->args());
	}

	public function testAProjectThatIsGoneNeedsNothing(): void {
		$this->projects->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->service->expects(self::never())->method('handOver');
		$this->jobs->expects(self::never())->method('scheduleAfter');

		$this->runJob($this->args());
	}

	public function testAProjectWithANewOwnerIsLeftAlone(): void {
		$this->projects->method('find')->willReturn($this->project('bob'));
		$this->service->expects(self::never())->method('handOver');

		$this->runJob($this->args());
	}

	public function testAReturnedOwnerKeepsTheProject(): void {
		$this->projects->method('find')->willReturn($this->project());
		$this->users->method('userExists')->with('gone')->willReturn(true);
		$this->service->expects(self::never())->method('handOver');

		$this->runJob($this->args());
	}

	public function testAFailureIsRetriedLater(): void {
		$this->projects->method('find')->willReturn($this->project());
		$this->service->method('handOver')->willThrowException(new \RuntimeException('boom'));
		$this->jobs->expects(self::once())->method('scheduleAfter')->with(SettleOrphanedProject::class, 4600, $this->args(3));
		$this->logger->expects(self::never())->method('error');

		$this->runJob($this->args(2));
	}

	public function testOnlyTheFinalAttemptTrustsAnEmptyGroup(): void {
		$project = $this->project();
		$this->projects->method('find')->willReturn($project);
		$seen = [];
		$this->service->method('handOver')->willReturnCallback(
			static function (Project $project, string $formerOwner, bool $trustEmptyGroups) use (&$seen): void {
				$seen[] = $trustEmptyGroups;
			}
		);

		$this->runJob($this->args(SettleOrphanedProject::MAX_ATTEMPTS - 2));
		$this->runJob($this->args(SettleOrphanedProject::MAX_ATTEMPTS - 1));

		self::assertSame([false, true], $seen);
	}

	public function testALoadFailureIsRetriedToo(): void {
		$this->projects->method('find')->willThrowException(new \RuntimeException('db away'));
		$this->jobs->expects(self::once())->method('scheduleAfter')->with(SettleOrphanedProject::class, 4600, $this->args(1));

		$this->runJob($this->args());
	}

	public function testGivesUpLoudlyAtTheCap(): void {
		$this->projects->method('find')->willReturn($this->project());
		$this->service->method('handOver')->willThrowException(new \RuntimeException('boom'));
		$this->jobs->expects(self::never())->method('scheduleAfter');
		$this->logger->expects(self::once())->method('error');

		$this->runJob($this->args(SettleOrphanedProject::MAX_ATTEMPTS - 1));
	}

	public function testTheLastAllowedAttemptStillRetriesBeforeTheCap(): void {
		$this->projects->method('find')->willReturn($this->project());
		$this->service->method('handOver')->willThrowException(new \RuntimeException('boom'));
		$this->jobs->expects(self::once())->method('scheduleAfter');
		$this->logger->expects(self::never())->method('error');

		$this->runJob($this->args(SettleOrphanedProject::MAX_ATTEMPTS - 2));
	}

	/** @return array<string, array{mixed}> */
	public static function malformedArguments(): array {
		return [
			'not an array' => [null],
			'no project' => [['formerOwner' => 'gone', 'attempt' => 0]],
			'no former owner' => [['projectId' => 5, 'attempt' => 0]],
		];
	}

	/** @dataProvider malformedArguments */
	public function testIgnoresMalformedArguments(mixed $argument): void {
		$this->projects->expects(self::never())->method('find');
		$this->service->expects(self::never())->method('handOver');

		$this->runJob($argument);
	}
}
