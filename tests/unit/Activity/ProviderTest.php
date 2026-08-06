<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Activity;

use OCA\Kanso\Activity\Provider;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProviderTest extends TestCase {
	private IFactory&MockObject $l10nFactory;
	private IURLGenerator&MockObject $urlGenerator;
	private IUserManager&MockObject $userManager;
	private Provider $provider;

	protected function setUp(): void {
		parent::setUp();
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->provider = new Provider(
			$this->l10nFactory,
			$this->urlGenerator,
			$this->userManager,
		);

		// IL10N is not in the OCP dev stub; a tiny stand-in with a printf-style
		// t() suffices (IFactory::get has no declared return type).
		$l = new class {
			public function t(string $text, array $parameters = []): string {
				return $parameters === [] ? $text : vsprintf($text, $parameters);
			}
		};
		$this->l10nFactory->method('get')->willReturn($l);
		$this->urlGenerator->method('imagePath')->willReturn('/img/app.svg');
		$this->urlGenerator->method('getAbsoluteURL')->willReturnArgument(0);
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function event(string $app, string $subject, array $params, string $objectId = '9'): IEvent&MockObject {
		$e = $this->createMock(IEvent::class);
		$e->method('getApp')->willReturn($app);
		$e->method('getSubject')->willReturn($subject);
		$e->method('getSubjectParameters')->willReturn($params);
		$e->method('getObjectId')->willReturn($objectId);
		$e->method('getLink')->willReturn('https://nc.example/apps/kanso/#/board/3/card/9');
		$e->method('setIcon')->willReturnSelf();
		$e->method('setParsedSubject')->willReturnSelf();
		return $e;
	}

	private function stubActor(?string $displayName): void {
		if ($displayName === null) {
			$this->userManager->method('get')->willReturn(null);
			return;
		}
		$actor = $this->createMock(IUser::class);
		$actor->method('getDisplayName')->willReturn($displayName);
		$this->userManager->method('get')->willReturn($actor);
	}

	public function testParseRejectsForeignApp(): void {
		$e = $this->event('files', Provider::SUBJECT_CARD_CREATED, ['actor' => 'alice', 'name' => 'Card']);
		$this->expectException(UnknownActivityException::class);
		// The card requires the short-circuit to be an \InvalidArgumentException;
		// UnknownActivityException extends it.
		$this->expectException(\InvalidArgumentException::class);
		$this->provider->parse('en', $e);
	}

	public function testParseRejectsUnknownSubject(): void {
		$e = $this->event('kanso', 'card_exploded', ['actor' => 'alice', 'name' => 'Card']);
		$this->expectException(UnknownActivityException::class);
		$this->provider->parse('en', $e);
	}

	public function testParseCardCreatedBuildsRichSubject(): void {
		$this->stubActor('Alice A.');
		$e = $this->event('kanso', Provider::SUBJECT_CARD_CREATED, ['actor' => 'alice', 'name' => 'Fix the bug']);

		$e->expects(self::once())->method('setParsedSubject')->with('Alice A. created Fix the bug')->willReturnSelf();
		$e->expects(self::once())->method('setRichSubject')
			->with(
				'{actor} created {object}',
				self::callback(static fn (array $p): bool
					=> $p['actor']['type'] === 'user'
					&& $p['actor']['id'] === 'alice'
					&& $p['actor']['name'] === 'Alice A.'
					&& $p['object']['type'] === 'highlight'
					&& $p['object']['id'] === '9'
					&& $p['object']['name'] === 'Fix the bug')
			)->willReturnSelf();

		self::assertSame($e, $this->provider->parse('en', $e));
	}

	public function testParseCardDoneUsesDoneWording(): void {
		$this->stubActor('Alice A.');
		$e = $this->event('kanso', Provider::SUBJECT_CARD_DONE, ['actor' => 'alice', 'name' => 'Ship it']);

		$e->expects(self::once())->method('setParsedSubject')->with('Alice A. marked Ship it as done')->willReturnSelf();
		$e->expects(self::once())->method('setRichSubject')
			->with('{actor} marked {object} as done', self::anything())->willReturnSelf();

		$this->provider->parse('en', $e);
	}

	public function testParseBoardSharedUsesShareWording(): void {
		$this->stubActor('Alice A.');
		$e = $this->event('kanso', Provider::SUBJECT_BOARD_SHARED, ['actor' => 'alice', 'name' => 'Roadmap'], '3');

		$e->expects(self::once())->method('setParsedSubject')->with('Alice A. shared Roadmap with you')->willReturnSelf();
		$e->expects(self::once())->method('setRichSubject')
			->with('{actor} shared {object} with you', self::anything())->willReturnSelf();

		$this->provider->parse('en', $e);
	}

	public function testParseFallsBackToUidWhenActorMissing(): void {
		// An actor with no user account renders as the raw uid, not a crash.
		$this->stubActor(null);
		$e = $this->event('kanso', Provider::SUBJECT_CARD_MOVED, ['actor' => 'ghost', 'name' => 'Card']);

		$e->expects(self::once())->method('setParsedSubject')->with('ghost moved Card')->willReturnSelf();
		$e->method('setRichSubject')->willReturnSelf();

		$this->provider->parse('en', $e);
	}
}
