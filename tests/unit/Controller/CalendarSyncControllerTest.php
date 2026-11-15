<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Access\NotAMemberException;
use OCA\Kanso\Controller\CalendarSyncController;
use OCA\Kanso\Service\CardCalendarService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CalendarSyncControllerTest extends TestCase {
	private IUserSession&MockObject $session;
	private CardCalendarService&MockObject $service;
	private CalendarSyncController $controller;

	protected function setUp(): void {
		$this->session = $this->createMock(IUserSession::class);
		$this->service = $this->createMock(CardCalendarService::class);
		$this->controller = new CalendarSyncController('kanso', $this->createMock(IRequest::class), $this->session, $this->service);
	}

	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);
	}

	public function testShowReturnsTheAuthenticatedUsersPreferences(): void {
		$this->signIn();
		$this->service->expects(self::once())->method('preferencesForUser')->with('alice', 7)
			->willReturn(['enabled' => true, 'assignedOnly' => false]);
		self::assertSame(['enabled' => true, 'assignedOnly' => false], $this->controller->show(7)->getData());
	}

	public function testEnabledOnlyRequestPreservesTheAssignmentPreference(): void {
		$this->signIn();
		$this->service->expects(self::once())->method('setPreferencesForUser')->with('alice', 7, false, null);
		$this->service->method('preferencesForUser')->willReturn(['enabled' => false, 'assignedOnly' => true]);
		self::assertSame(['enabled' => false, 'assignedOnly' => true], $this->controller->update(7, false)->getData());
	}

	public function testAssignmentOnlyRequestPreservesBoardVisibility(): void {
		$this->signIn();
		$this->service->expects(self::once())->method('setPreferencesForUser')->with('alice', 7, null, false);
		$this->service->method('preferencesForUser')->willReturn(['enabled' => false, 'assignedOnly' => false]);
		self::assertSame(['enabled' => false, 'assignedOnly' => false], $this->controller->update(7, assignedOnly: false)->getData());
	}

	public function testNoSessionCannotReadOrUpdatePreferences(): void {
		$this->service->expects(self::never())->method('preferencesForUser');
		$this->service->expects(self::never())->method('setPreferencesForUser');
		self::assertSame(403, $this->controller->show(7)->getStatus());
		self::assertSame(403, $this->controller->update(7, assignedOnly: true)->getStatus());
	}

	public static function deniedBoards(): array {
		return [[new NotAMemberException('nope'), 403], [new DoesNotExistException('gone'), 404]];
	}

	#[DataProvider('deniedBoards')]
	public function testDeniedBoardCannotReadOrUpdatePreferences(\Exception $exception, int $status): void {
		$this->signIn();
		$this->service->method('preferencesForUser')->willThrowException($exception);
		$this->service->method('setPreferencesForUser')->willThrowException($exception);
		self::assertSame($status, $this->controller->show(7)->getStatus());
		self::assertSame($status, $this->controller->update(7, assignedOnly: true)->getStatus());
	}
}
