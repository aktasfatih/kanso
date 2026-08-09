<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\SetupCheck;

use OCA\Kanso\SetupCheck\InstanceConfigCheck;
use OCP\IConfig;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class InstanceConfigCheckTest extends TestCase {
	private IConfig&MockObject $config;
	private InstanceConfigCheck $check;

	protected function setUp(): void {
		$this->config = $this->createMock(IConfig::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->check = new InstanceConfigCheck($this->config, $l10n);
	}

	private function configure(string $cronMode, string $cliUrl): void {
		$this->config->method('getAppValue')
			->with('core', 'backgroundjobs_mode', 'ajax')
			->willReturn($cronMode);
		$this->config->method('getSystemValueString')
			->with('overwrite.cli.url', '')
			->willReturn($cliUrl);
	}

	public function testNameAndCategory(): void {
		self::assertSame('kanso', $this->check->getCategory());
		self::assertNotSame('', $this->check->getName());
	}

	public function testWarnsOnAjaxCronAndMissingCliUrl(): void {
		$this->configure('ajax', '');

		$result = $this->check->run();

		self::assertSame(SetupResult::WARNING, $result->getSeverity());
		$description = (string)$result->getDescription();
		self::assertStringContainsString('AJAX mode', $description);
		self::assertStringContainsString('due reminders', $description);
		self::assertStringContainsString('system cron', $description);
		self::assertStringContainsString('overwrite.cli.url', $description);
		self::assertNotNull($result->getLinkToDoc());
	}

	public function testWarnsOnAjaxCronOnly(): void {
		$this->configure('ajax', 'https://cloud.example.com');

		$result = $this->check->run();

		self::assertSame(SetupResult::WARNING, $result->getSeverity());
		$description = (string)$result->getDescription();
		self::assertStringContainsString('AJAX mode', $description);
		self::assertStringNotContainsString('overwrite.cli.url', $description);
	}

	public function testWarnsOnMissingCliUrlOnly(): void {
		$this->configure('cron', '');

		$result = $this->check->run();

		self::assertSame(SetupResult::WARNING, $result->getSeverity());
		$description = (string)$result->getDescription();
		self::assertStringContainsString('overwrite.cli.url', $description);
		self::assertStringContainsString('config.php', $description);
		self::assertStringNotContainsString('AJAX mode', $description);
	}

	public function testWhitespaceOnlyCliUrlCountsAsUnset(): void {
		$this->configure('cron', '   ');

		$result = $this->check->run();

		self::assertSame(SetupResult::WARNING, $result->getSeverity());
		self::assertStringContainsString('overwrite.cli.url', (string)$result->getDescription());
	}

	public function testSuccessWhenConfigured(): void {
		$this->configure('cron', 'https://cloud.example.com');

		$result = $this->check->run();

		self::assertSame(SetupResult::SUCCESS, $result->getSeverity());
	}

	public function testWebcronDoesNotWarnAboutBackgroundJobs(): void {
		// Only "ajax" is flagged: webcron still runs server-side without a
		// browser session, so reminders are delivered (if perhaps less often).
		$this->configure('webcron', 'https://cloud.example.com');

		$result = $this->check->run();

		self::assertSame(SetupResult::SUCCESS, $result->getSeverity());
	}
}
