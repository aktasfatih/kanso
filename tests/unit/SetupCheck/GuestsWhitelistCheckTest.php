<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\SetupCheck;

use OCA\Kanso\SetupCheck\GuestsWhitelistCheck;
use OCP\IConfig;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GuestsWhitelistCheckTest extends TestCase {
	private IConfig&MockObject $config;
	private GuestsWhitelistCheck $check;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		$this->check = new GuestsWhitelistCheck($this->config, $l10n);
	}

	/**
	 * @param array<array{0: string, 1: string, 2: string}> $values [app, key, value] rows
	 */
	private function configure(array $values): void {
		$map = [];
		foreach ($values as [$app, $key, $value]) {
			$map[$app . '/' . $key] = $value;
		}
		$this->config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $map[$app . '/' . $key] ?? $default
		);
	}

	public function testNameAndCategory(): void {
		self::assertSame('kanso', $this->check->getCategory());
		self::assertNotSame('', $this->check->getName());
	}

	public function testSilentSuccessWhenGuestsAppIsAbsent(): void {
		// No guests appconfig at all → the defaults read as disabled.
		$this->configure([]);

		$result = $this->check->run();

		self::assertSame(SetupResult::SUCCESS, $result->getSeverity());
	}

	public function testSilentSuccessWhenGuestsAppIsDisabled(): void {
		$this->configure([['guests', 'enabled', 'no']]);

		self::assertSame(SetupResult::SUCCESS, $this->check->run()->getSeverity());
	}

	public function testWarnsWhenGuestsEnabledAndKansoMissingFromWhitelist(): void {
		$this->configure([
			['guests', 'enabled', 'yes'],
			['guests', 'whitelist', 'core,files,viewer'],
		]);

		$result = $this->check->run();

		self::assertSame(SetupResult::WARNING, $result->getSeverity());
		$description = (string)$result->getDescription();
		self::assertStringContainsString('kanso', $description);
		// viewer IS listed here, so only kanso is reported missing.
		self::assertStringNotContainsString('viewer', $description);
		self::assertNotNull($result->getLinkToDoc());
	}

	public function testWarnsListingViewerTooWhenBothAreMissing(): void {
		$this->configure([
			['guests', 'enabled', 'yes'],
			['guests', 'whitelist', 'core,files'],
		]);

		$result = $this->check->run();

		self::assertSame(SetupResult::WARNING, $result->getSeverity());
		self::assertStringContainsString('kanso, viewer', (string)$result->getDescription());
	}

	public function testSuccessWhenGuestsEnabledAndBothAppsWhitelisted(): void {
		$this->configure([
			['guests', 'enabled', 'yes'],
			['guests', 'whitelist', 'core, files, kanso, viewer'],
		]);

		self::assertSame(SetupResult::SUCCESS, $this->check->run()->getSeverity());
	}

	public function testSuccessWhenWhitelistIsDisabledEntirely(): void {
		// The Guests app stores the flag as an appconfig BOOL ('0'/'1' via
		// setAppValueBool); older writes may hold 'false'. Both disabled
		// shapes must read as "no restriction".
		foreach (['0', 'false'] as $stored) {
			$this->setUp();
			$this->configure([
				['guests', 'enabled', 'yes'],
				['guests', 'usewhitelist', $stored],
				['guests', 'whitelist', 'core,files'],
			]);

			self::assertSame(SetupResult::SUCCESS, $this->check->run()->getSeverity(), "usewhitelist=$stored");
		}
	}

	public function testStoredBoolOneStillWarnsWhenAppsAreMissing(): void {
		// Regression guard: setAppValueBool persists '1' (not 'true') - the
		// check must still treat that as "whitelist in use" and warn.
		$this->configure([
			['guests', 'enabled', 'yes'],
			['guests', 'usewhitelist', '1'],
			['guests', 'whitelist', 'core,files'],
		]);

		self::assertSame(SetupResult::WARNING, $this->check->run()->getSeverity());
	}

	public function testGroupLimitedEnableStillCountsAsEnabled(): void {
		// An app enabled for specific groups stores a JSON group list, not
		// 'yes' - that still means guests exist and the whitelist matters.
		$this->configure([
			['guests', 'enabled', '["staff"]'],
			['guests', 'whitelist', 'core,files'],
		]);

		self::assertSame(SetupResult::WARNING, $this->check->run()->getSeverity());
	}
}
