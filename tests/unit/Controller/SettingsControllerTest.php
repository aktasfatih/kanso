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

	/**
	 * @param array<string, string> $values key => stored value
	 */
	private function stubGetUserValue(array $values): void {
		$this->config->method('getUserValue')
			->willReturnCallback(static function (string $uid, string $app, string $key, string $default) use ($values): string {
				return $values[$key] ?? $default;
			});
	}

	public function testIndexReturnsStoredBoardId(): void {
		$this->stubGetUserValue(['default_board' => '42']);

		self::assertSame(
			['defaultBoardId' => 42, 'collapsedBoardGroups' => [], 'dismissedHints' => [], 'hiddenNavSections' => [], 'editorToolbarHidden' => false],
			$this->controller->index()->getData()
		);
	}

	public function testIndexReturnsNullWhenUnset(): void {
		$this->stubGetUserValue([]);

		self::assertSame(
			['defaultBoardId' => null, 'collapsedBoardGroups' => [], 'dismissedHints' => [], 'hiddenNavSections' => [], 'editorToolbarHidden' => false],
			$this->controller->index()->getData()
		);
	}

	public function testIndexReturnsCollapsedGroups(): void {
		$this->stubGetUserValue([
			'default_board' => '',
			'collapsed_board_groups' => '[3,7,7]',
		]);

		// Deduped and int-cast.
		self::assertSame(
			['defaultBoardId' => null, 'collapsedBoardGroups' => [3, 7], 'dismissedHints' => [], 'hiddenNavSections' => [], 'editorToolbarHidden' => false],
			$this->controller->index()->getData()
		);
	}

	public function testUpdatePersistsBoardId(): void {
		$this->stubGetUserValue([]);
		$this->config->expects(self::once())
			->method('setUserValue')
			->with('alice', 'kanso', 'default_board', '7');

		self::assertSame(
			['defaultBoardId' => 7, 'collapsedBoardGroups' => [], 'dismissedHints' => [], 'hiddenNavSections' => [], 'editorToolbarHidden' => false],
			$this->controller->update(7)->getData()
		);
	}

	public function testUpdateClearsOnNull(): void {
		$this->stubGetUserValue([]);
		$this->config->expects(self::once())
			->method('setUserValue')
			->with('alice', 'kanso', 'default_board', '');

		self::assertSame(
			['defaultBoardId' => null, 'collapsedBoardGroups' => [], 'dismissedHints' => [], 'hiddenNavSections' => [], 'editorToolbarHidden' => false],
			$this->controller->update(null)->getData()
		);
	}

	public function testUpdateClearsOnZeroOrNegative(): void {
		$this->stubGetUserValue([]);
		$this->config->expects(self::once())
			->method('setUserValue')
			->with('alice', 'kanso', 'default_board', '');

		self::assertSame(
			['defaultBoardId' => null, 'collapsedBoardGroups' => [], 'dismissedHints' => [], 'hiddenNavSections' => [], 'editorToolbarHidden' => false],
			$this->controller->update(0)->getData()
		);
	}

	public function testUpdatePersistsCollapsedGroups(): void {
		$stored = [];
		$this->config->method('getUserValue')
			->willReturnCallback(static function (string $uid, string $app, string $key, string $default) use (&$stored): string {
				return $stored[$key] ?? $default;
			});
		$this->config->method('setUserValue')
			->willReturnCallback(static function (string $uid, string $app, string $key, string $value) use (&$stored): void {
				$stored[$key] = $value;
			});

		// Dupes are collapsed; the round-trip surfaces the cleaned list.
		$result = $this->controller->update(null, [5, 5, 9])->getData();
		self::assertSame([5, 9], $result['collapsedBoardGroups']);
		self::assertSame('[5,9]', $stored['collapsed_board_groups']);
	}

	public function testUpdateLeavesCollapsedGroupsUntouchedWhenOmitted(): void {
		$this->stubGetUserValue(['collapsed_board_groups' => '[1,2]']);
		// Only the default_board key is written; collapsed groups are not touched.
		$this->config->expects(self::once())
			->method('setUserValue')
			->with('alice', 'kanso', 'default_board', '3');

		$result = $this->controller->update(3)->getData();
		self::assertSame([1, 2], $result['collapsedBoardGroups']);
	}

	public function testUpdatePersistsDismissedHints(): void {
		$stored = [];
		$this->config->method('getUserValue')
			->willReturnCallback(static function (string $uid, string $app, string $key, string $default) use (&$stored): string {
				return $stored[$key] ?? $default;
			});
		$this->config->method('setUserValue')
			->willReturnCallback(static function (string $uid, string $app, string $key, string $value) use (&$stored): void {
				$stored[$key] = $value;
			});

		// Dupes are collapsed and malformed/invalid ids are dropped by the shape guard.
		$result = $this->controller->update(null, null, ['shortcuts', 'shortcuts', 'BAD ID', 'starter-board'])->getData();
		self::assertSame(['shortcuts', 'starter-board'], $result['dismissedHints']);
		self::assertSame('["shortcuts","starter-board"]', $stored['dismissed_hints']);
	}

	public function testUpdateLeavesDismissedHintsUntouchedWhenOmitted(): void {
		$this->stubGetUserValue(['dismissed_hints' => '["shortcuts"]']);
		// Only the default_board key is written; dismissed hints are not touched.
		$this->config->expects(self::once())
			->method('setUserValue')
			->with('alice', 'kanso', 'default_board', '3');

		$result = $this->controller->update(3)->getData();
		self::assertSame(['shortcuts'], $result['dismissedHints']);
	}

	public function testUpdatePersistsHiddenNavSectionsFilteredToAllowList(): void {
		$stored = [];
		$this->config->method('getUserValue')
			->willReturnCallback(static function (string $uid, string $app, string $key, string $default) use (&$stored): string {
				return $stored[$key] ?? $default;
			});
		$this->config->method('setUserValue')
			->willReturnCallback(static function (string $uid, string $app, string $key, string $value) use (&$stored): void {
				$stored[$key] = $value;
			});

		// 'boards' (always-shown) and 'bogus' (unknown) are dropped by the
		// allow-list guard; only the valid 'my-tasks' key survives the round-trip.
		$result = $this->controller->update(null, null, null, ['boards', 'bogus', 'my-tasks'])->getData();
		self::assertSame(['my-tasks'], $result['hiddenNavSections']);
		self::assertSame('["my-tasks"]', $stored['hidden_nav_sections']);
	}

	public function testUpdateLeavesHiddenNavSectionsUntouchedWhenOmitted(): void {
		$this->stubGetUserValue(['hidden_nav_sections' => '["inbox","views"]']);
		// Only the default_board key is written; hidden nav sections are not touched.
		$this->config->expects(self::once())
			->method('setUserValue')
			->with('alice', 'kanso', 'default_board', '3');

		$result = $this->controller->update(3)->getData();
		self::assertSame(['inbox', 'views'], $result['hiddenNavSections']);
	}

	public function testEditorToolbarHiddenRoundTrip(): void {
		$stored = [];
		$this->config->method('getUserValue')
			->willReturnCallback(static function (string $uid, string $app, string $key, string $default) use (&$stored): string {
				return $stored[$key] ?? $default;
			});
		$this->config->method('setUserValue')
			->willReturnCallback(static function (string $uid, string $app, string $key, string $value) use (&$stored): void {
				$stored[$key] = $value;
			});

		// Default: shown (false).
		self::assertFalse($this->controller->index()->getData()['editorToolbarHidden']);

		// Hide toolbar.
		$result = $this->controller->update(null, null, null, null, true)->getData();
		self::assertTrue($result['editorToolbarHidden']);
		self::assertSame('1', $stored['editor_toolbar_hidden']);

		// Show toolbar again.
		$result = $this->controller->update(null, null, null, null, false)->getData();
		self::assertFalse($result['editorToolbarHidden']);
		self::assertSame('0', $stored['editor_toolbar_hidden']);
	}

	public function testEditorToolbarHiddenUntouchedWhenOmitted(): void {
		$this->stubGetUserValue(['editor_toolbar_hidden' => '1']);
		// Only the default_board key is written; editor toolbar pref is not touched.
		$this->config->expects(self::once())
			->method('setUserValue')
			->with('alice', 'kanso', 'default_board', '5');

		$result = $this->controller->update(5)->getData();
		self::assertTrue($result['editorToolbarHidden']);
	}
}
