<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\SettingsController;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SettingsControllerTest extends TestCase {
	private IConfig&MockObject $config;
	private SettingsController $controller;

	protected function setUp(): void {
		parent::setUp();
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$this->config = $this->createMock(IConfig::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession->method('getUser')->willReturn($user);

		$this->controller = new SettingsController('kanso', $request, $userSession, $this->config);
	}

	public function testIndexReturnsStoredBoardId(): void {
		$this->config->method('getUserValue')
			->with('alice', 'kanso', 'default_board', '')
			->willReturn('42');

		self::assertSame(['defaultBoardId' => 42], $this->controller->index()->getData());
	}

	public function testIndexReturnsNullWhenUnset(): void {
		$this->config->method('getUserValue')->willReturn('');

		self::assertSame(['defaultBoardId' => null], $this->controller->index()->getData());
	}

	public function testUpdatePersistsBoardId(): void {
		$this->config->expects(self::once())
			->method('setUserValue')
			->with('alice', 'kanso', 'default_board', '7');

		self::assertSame(['defaultBoardId' => 7], $this->controller->update(7)->getData());
	}

	public function testUpdateClearsOnNull(): void {
		$this->config->expects(self::once())
			->method('setUserValue')
			->with('alice', 'kanso', 'default_board', '');

		self::assertSame(['defaultBoardId' => null], $this->controller->update(null)->getData());
	}

	public function testUpdateClearsOnZeroOrNegative(): void {
		$this->config->expects(self::once())
			->method('setUserValue')
			->with('alice', 'kanso', 'default_board', '');

		self::assertSame(['defaultBoardId' => null], $this->controller->update(0)->getData());
	}
}
