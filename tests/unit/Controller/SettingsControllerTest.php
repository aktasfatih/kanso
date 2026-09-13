<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\SettingsController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SettingsControllerTest extends TestCase {
	/**
	 * The preference key each request-body key is stored under. Also the full
	 * list of keys the endpoint knows about - the per-key isolation test walks
	 * it, so a new setting added without its own guard shows up as a failure.
	 */
	private const STORAGE_KEYS = [
		'defaultBoardId' => 'default_board',
		'collapsedBoardGroups' => 'collapsed_board_groups',
		'dismissedHints' => 'dismissed_hints',
		'hiddenNavSections' => 'hidden_nav_sections',
		'editorToolbarHidden' => 'editor_toolbar_hidden',
		'cardDiscussionPosition' => 'card_discussion_position',
	];

	/** Every preference set to a non-default value, so any stray write shows. */
	private const POPULATED = [
		'default_board' => '440',
		'collapsed_board_groups' => '[1,2]',
		'dismissed_hints' => '["shortcuts"]',
		'hidden_nav_sections' => '["inbox"]',
		'editor_toolbar_hidden' => '1',
		'card_discussion_position' => 'bottom',
	];

	private IConfig&MockObject $config;
	private SettingsController $controller;
	/** @var array<string, string> the backing user-config row, when useStore() is in play */
	private array $stored = [];

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->controller = new SettingsController('kanso', $this->createMock(IRequest::class), $this->sessionFor('alice'), $this->config);
	}

	/**
	 * Read-only stub of the user config.
	 *
	 * @param array<string, string> $values key => stored value
	 */
	private function stubGetUserValue(array $values): void {
		$this->config->method('getUserValue')
			->willReturnCallback(static function (string $uid, string $app, string $key, string $default) use ($values): string {
				return $values[$key] ?? $default;
			});
	}

	/**
	 * Read/write stub of the user config, backed by $this->stored so a test can
	 * assert on what actually landed in the row.
	 *
	 * @param array<string, string> $initial
	 */
	private function useStore(array $initial = []): void {
		$this->stored = $initial;
		$this->config->method('getUserValue')
			->willReturnCallback(function (string $uid, string $app, string $key, string $default): string {
				return $this->stored[$key] ?? $default;
			});
		$this->config->method('setUserValue')
			->willReturnCallback(function (string $uid, string $app, string $key, string $value): void {
				$this->stored[$key] = $value;
			});
	}

	/**
	 * PUT /api/settings the way a real client does: $body IS the request body, so
	 * a key it omits is genuinely absent rather than a defaulted null. That is the
	 * distinction the endpoint's contract rests on, so the tests have to make it
	 * too - calling update() with positional nulls would prove nothing.
	 *
	 * @param array<string, mixed> $body
	 */
	private function put(array $body, ?string $uid = 'alice'): JSONResponse {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($body);
		$controller = new SettingsController('kanso', $request, $this->sessionFor($uid), $this->config);
		return $controller->update(
			$body['defaultBoardId'] ?? null,
			$body['collapsedBoardGroups'] ?? null,
			$body['dismissedHints'] ?? null,
			$body['hiddenNavSections'] ?? null,
			$body['editorToolbarHidden'] ?? null,
			$body['cardDiscussionPosition'] ?? null,
		);
	}

	private function sessionFor(?string $uid): IUserSession&MockObject {
		$userSession = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$userSession->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$userSession->method('getUser')->willReturn($user);
		}
		return $userSession;
	}

	// ── Reads ────────────────────────────────────────────────────────────────

	public function testIndexReturnsStoredBoardId(): void {
		$this->stubGetUserValue(['default_board' => '42']);

		self::assertSame(
			['defaultBoardId' => 42, 'collapsedBoardGroups' => [], 'dismissedHints' => [], 'hiddenNavSections' => [], 'editorToolbarHidden' => false, 'cardDiscussionPosition' => 'side'],
			$this->controller->index()->getData()
		);
	}

	public function testIndexReturnsNullWhenUnset(): void {
		$this->stubGetUserValue([]);

		self::assertSame(
			['defaultBoardId' => null, 'collapsedBoardGroups' => [], 'dismissedHints' => [], 'hiddenNavSections' => [], 'editorToolbarHidden' => false, 'cardDiscussionPosition' => 'side'],
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
			['defaultBoardId' => null, 'collapsedBoardGroups' => [3, 7], 'dismissedHints' => [], 'hiddenNavSections' => [], 'editorToolbarHidden' => false, 'cardDiscussionPosition' => 'side'],
			$this->controller->index()->getData()
		);
	}

	/**
	 * The read path must not invent values for absent keys in a way a client
	 * could round-trip back as a write - a fetch reports "no default board" as
	 * null, which is also how a write clears it, and every other key reads back
	 * as its own default.
	 */
	public function testIndexReportsAbsentKeysAsTheirDefaults(): void {
		$this->stubGetUserValue([]);

		$data = $this->controller->index()->getData();
		self::assertNull($data['defaultBoardId']);
		self::assertSame([], $data['collapsedBoardGroups']);
		self::assertSame([], $data['dismissedHints']);
		self::assertSame([], $data['hiddenNavSections']);
		self::assertFalse($data['editorToolbarHidden']);
		self::assertSame('side', $data['cardDiscussionPosition']);
	}

	// ── Omitted keys are never touched (per key) ─────────────────────────────

	/**
	 * @return iterable<string, array{string, mixed}> body key => a value that changes it
	 */
	public static function sentKeyProvider(): iterable {
		yield 'defaultBoardId' => ['defaultBoardId', 7];
		yield 'collapsedBoardGroups' => ['collapsedBoardGroups', [9]];
		yield 'dismissedHints' => ['dismissedHints', ['starter-board']];
		yield 'hiddenNavSections' => ['hiddenNavSections', ['views']];
		yield 'editorToolbarHidden' => ['editorToolbarHidden', false];
		yield 'cardDiscussionPosition' => ['cardDiscussionPosition', 'side'];
	}

	/**
	 * The core contract, checked for EVERY key rather than just the one that was
	 * reported: saving one setting must leave the other five byte-identical in
	 * the user-config row. Every control in the settings dialog sends only its
	 * own key, so a key that writes unconditionally silently wipes whatever the
	 * user had set elsewhere.
	 *
	 */
	#[DataProvider('sentKeyProvider')]
	public function testSendingOneKeyLeavesEveryOtherKeyUntouched(string $bodyKey, mixed $value): void {
		$this->useStore(self::POPULATED);

		$this->put([$bodyKey => $value]);

		foreach (self::STORAGE_KEYS as $otherBodyKey => $storageKey) {
			if ($otherBodyKey === $bodyKey) {
				continue;
			}
			self::assertSame(
				self::POPULATED[$storageKey],
				$this->stored[$storageKey],
				"sending $bodyKey changed the unrelated $storageKey row"
			);
		}
	}

	/**
	 * The same guarantee seen from the client's side: the response to a one-key
	 * save reports the other preferences unchanged, so a UI that reseeds itself
	 * from the response doesn't lose them either.
	 *
	 */
	#[DataProvider('sentKeyProvider')]
	public function testResponseToAOneKeySaveStillReportsTheOtherPreferences(string $bodyKey, mixed $value): void {
		$this->useStore(self::POPULATED);

		$data = $this->put([$bodyKey => $value])->getData();

		$expected = [
			'defaultBoardId' => 440,
			'collapsedBoardGroups' => [1, 2],
			'dismissedHints' => ['shortcuts'],
			'hiddenNavSections' => ['inbox'],
			'editorToolbarHidden' => true,
			'cardDiscussionPosition' => 'bottom',
		];
		unset($expected[$bodyKey]);
		foreach ($expected as $key => $want) {
			self::assertSame($want, $data[$key], "sending $bodyKey changed the reported $key");
		}
	}

	/**
	 * The regression this whole contract exists for, stated in the user's terms:
	 * a user sets their default board, then flips an unrelated toggle in the same
	 * dialog. The default board must survive.
	 */
	public function testTogglingAnUnrelatedSettingKeepsTheDefaultBoard(): void {
		$this->useStore();

		self::assertSame(440, $this->put(['defaultBoardId' => 440])->getData()['defaultBoardId']);

		// A different control in the same dialog saves only its own key.
		$this->put(['cardDiscussionPosition' => 'bottom']);
		$this->put(['editorToolbarHidden' => true]);
		$this->put(['hiddenNavSections' => ['inbox']]);
		$this->put(['collapsedBoardGroups' => [3]]);
		$this->put(['dismissedHints' => ['shortcuts']]);

		self::assertSame('440', $this->stored['default_board']);
		self::assertSame(440, $this->controller->index()->getData()['defaultBoardId']);
	}

	/**
	 * An unknown body key is not a write instruction: it must not reach the
	 * config at all (and in particular must not be taken as "clear everything").
	 */
	public function testUnknownBodyKeyWritesNothing(): void {
		$this->stubGetUserValue(self::POPULATED);
		$this->config->expects(self::never())->method('setUserValue');

		$data = $this->put(['somethingElse' => 'x', '_route' => 'kanso.settings.update'])->getData();
		self::assertSame(440, $data['defaultBoardId']);
	}

	// ── Explicit clears still work, and are distinct from omission ───────────

	public function testExplicitNullClearsTheDefaultBoard(): void {
		$this->useStore(self::POPULATED);

		self::assertNull($this->put(['defaultBoardId' => null])->getData()['defaultBoardId']);
		self::assertSame('', $this->stored['default_board']);
	}

	public function testUpdateClearsOnZeroOrNegative(): void {
		$this->useStore(self::POPULATED);

		self::assertNull($this->put(['defaultBoardId' => 0])->getData()['defaultBoardId']);
		self::assertSame('', $this->stored['default_board']);

		$this->stored = self::POPULATED;
		self::assertNull($this->put(['defaultBoardId' => -3])->getData()['defaultBoardId']);
		self::assertSame('', $this->stored['default_board']);
	}

	/**
	 * Clearing is expressible on every key, not just the board - explicit null
	 * resets the preference to its default, which is exactly what omitting it
	 * must NOT do.
	 *
	 */
	#[DataProvider('sentKeyProvider')]
	public function testExplicitNullResetsThatKeyToItsDefault(string $bodyKey, mixed $unusedValue): void {
		$this->useStore(self::POPULATED);

		$data = $this->put([$bodyKey => null])->getData();

		$defaults = [
			'defaultBoardId' => null,
			'collapsedBoardGroups' => [],
			'dismissedHints' => [],
			'hiddenNavSections' => [],
			'editorToolbarHidden' => false,
			'cardDiscussionPosition' => 'side',
		];
		self::assertSame($defaults[$bodyKey], $data[$bodyKey]);
		// ...and the row really changed, so this isn't the omit path in disguise.
		self::assertNotSame(self::POPULATED[self::STORAGE_KEYS[$bodyKey]], $this->stored[self::STORAGE_KEYS[$bodyKey]]);
	}

	// ── Per-key persistence + validation guards ──────────────────────────────

	public function testUpdatePersistsBoardId(): void {
		$this->useStore();

		self::assertSame(7, $this->put(['defaultBoardId' => 7])->getData()['defaultBoardId']);
		self::assertSame('7', $this->stored['default_board']);
	}

	public function testUpdatePersistsCollapsedGroups(): void {
		$this->useStore();

		// Dupes are collapsed; the round-trip surfaces the cleaned list.
		$result = $this->put(['collapsedBoardGroups' => [5, 5, 9]])->getData();
		self::assertSame([5, 9], $result['collapsedBoardGroups']);
		self::assertSame('[5,9]', $this->stored['collapsed_board_groups']);
	}

	public function testUpdatePersistsDismissedHints(): void {
		$this->useStore();

		// Dupes are collapsed and malformed/invalid ids are dropped by the shape guard.
		$result = $this->put(['dismissedHints' => ['shortcuts', 'shortcuts', 'BAD ID', 'starter-board']])->getData();
		self::assertSame(['shortcuts', 'starter-board'], $result['dismissedHints']);
		self::assertSame('["shortcuts","starter-board"]', $this->stored['dismissed_hints']);
	}

	public function testUpdatePersistsHiddenNavSectionsFilteredToAllowList(): void {
		$this->useStore();

		// 'boards' (always-shown) and 'bogus' (unknown) are dropped by the
		// allow-list guard; only the valid 'my-tasks' key survives the round-trip.
		$result = $this->put(['hiddenNavSections' => ['boards', 'bogus', 'my-tasks']])->getData();
		self::assertSame(['my-tasks'], $result['hiddenNavSections']);
		self::assertSame('["my-tasks"]', $this->stored['hidden_nav_sections']);
	}

	public function testEditorToolbarHiddenRoundTrip(): void {
		$this->useStore();

		// Default: shown (false).
		self::assertFalse($this->controller->index()->getData()['editorToolbarHidden']);

		// Hide toolbar.
		self::assertTrue($this->put(['editorToolbarHidden' => true])->getData()['editorToolbarHidden']);
		self::assertSame('1', $this->stored['editor_toolbar_hidden']);

		// Show toolbar again.
		self::assertFalse($this->put(['editorToolbarHidden' => false])->getData()['editorToolbarHidden']);
		self::assertSame('0', $this->stored['editor_toolbar_hidden']);
	}

	// ── Card discussion placement (#10408) ───────────────────────────────────

	public function testDiscussionPositionDefaultsToSide(): void {
		$this->stubGetUserValue([]);

		self::assertSame('side', $this->controller->index()->getData()['cardDiscussionPosition']);
	}

	public function testDiscussionPositionRoundTrip(): void {
		$this->useStore();

		// Default: beside the card.
		self::assertSame('side', $this->controller->index()->getData()['cardDiscussionPosition']);

		// Move it below the card.
		$result = $this->put(['cardDiscussionPosition' => 'bottom'])->getData();
		self::assertSame('bottom', $result['cardDiscussionPosition']);
		self::assertSame('bottom', $this->stored['card_discussion_position']);
		// And it reads back on the next request - the whole point of storing it
		// server-side instead of in localStorage.
		self::assertSame('bottom', $this->controller->index()->getData()['cardDiscussionPosition']);

		// Move it back beside the card.
		$result = $this->put(['cardDiscussionPosition' => 'side'])->getData();
		self::assertSame('side', $result['cardDiscussionPosition']);
		self::assertSame('side', $this->stored['card_discussion_position']);
	}

	public function testDiscussionPositionRejectsUnknownValue(): void {
		$this->useStore();

		// The allow-list guard: an off-list value can't be used as free per-user
		// storage, it just resets the preference to the default.
		$result = $this->put(['cardDiscussionPosition' => 'sidebar-left; DROP'])->getData();
		self::assertSame('side', $result['cardDiscussionPosition']);
		self::assertSame('side', $this->stored['card_discussion_position']);
	}

	public function testDiscussionPositionFallsBackToSideOnCorruptStoredValue(): void {
		$this->stubGetUserValue(['card_discussion_position' => 'top']);

		// A corrupt row degrades to today's layout, never to a broken card view.
		self::assertSame('side', $this->controller->index()->getData()['cardDiscussionPosition']);
	}

	// ── Permission / ownership ───────────────────────────────────────────────

	/**
	 * The preference is addressed by the session's own uid and nothing else -
	 * there is no uid parameter on the route to tamper with - so one user can
	 * never read or write another's. Prove it: bob's session sees bob's value
	 * while alice's row says otherwise, and bob's write lands on bob's row.
	 */
	public function testPreferenceIsScopedToTheSessionUser(): void {
		$store = [
			'alice' => ['card_discussion_position' => 'bottom'],
			'bob' => [],
		];
		$this->config->method('getUserValue')
			->willReturnCallback(static function (string $uid, string $app, string $key, string $default) use (&$store): string {
				return $store[$uid][$key] ?? $default;
			});
		$this->config->method('setUserValue')
			->willReturnCallback(static function (string $uid, string $app, string $key, string $value) use (&$store): void {
				$store[$uid][$key] = $value;
			});

		// Bob does NOT inherit alice's 'bottom'.
		self::assertSame('side', $this->put([], 'bob')->getData()['cardDiscussionPosition']);

		// Bob's write lands on bob's row and leaves alice's alone.
		$this->put(['cardDiscussionPosition' => 'bottom'], 'bob');
		self::assertSame('bottom', $store['bob']['card_discussion_position']);
		self::assertSame('bottom', $store['alice']['card_discussion_position']);

		// And the reverse direction: alice flipping back doesn't move bob.
		$this->put(['cardDiscussionPosition' => 'side'], 'alice');
		self::assertSame('side', $store['alice']['card_discussion_position']);
		self::assertSame('bottom', $store['bob']['card_discussion_position']);
	}

	public function testReadIsDeniedWithoutASession(): void {
		$anonymous = new SettingsController('kanso', $this->createMock(IRequest::class), $this->sessionFor(null), $this->config);
		$this->config->expects(self::never())->method('getUserValue');

		$response = $anonymous->index();
		self::assertSame(403, $response->getStatus());
		self::assertSame(['error' => 'Access denied'], $response->getData());
	}

	public function testWriteIsDeniedWithoutASession(): void {
		$this->config->expects(self::never())->method('setUserValue');

		$response = $this->put(['cardDiscussionPosition' => 'bottom'], null);
		self::assertSame(403, $response->getStatus());
		self::assertSame(['error' => 'Access denied'], $response->getData());
	}
}
