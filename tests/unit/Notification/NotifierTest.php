<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Notification;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Notification\Notifier;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NotifierTest extends TestCase {
	private IFactory&MockObject $l10nFactory;
	private IURLGenerator&MockObject $urlGenerator;
	private IUserManager&MockObject $userManager;
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private CardVisibilityGuard&MockObject $visibilityGuard;
	private Notifier $notifier;

	protected function setUp(): void {
		parent::setUp();
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$board = new Board();
		$board->setId(3);
		$board->setOwner('board-owner');
		$this->boardMapper->method('find')->with(3)->willReturn($board);
		// Visible by default; the #3760 render-gate test flips this off.
		$this->visibilityGuard = $this->createMock(CardVisibilityGuard::class);
		$this->visibilityGuard->method('isVisible')->willReturn(true);
		$this->notifier = new Notifier(
			$this->l10nFactory,
			$this->urlGenerator,
			$this->userManager,
			$this->cardMapper,
			$this->boardMapper,
			$this->visibilityGuard,
		);

		// IL10N is not in the OCP dev stub; a tiny stand-in with t() suffices
		// (IFactory::get has no declared return type, so this is accepted).
		$l = new class {
			public function t(string $text, array $parameters = []): string {
				return $text;
			}
		};
		$this->l10nFactory->method('get')->willReturn($l);
		$this->urlGenerator->method('imagePath')->willReturn('/img/app.svg');
		$this->urlGenerator->method('getAbsoluteURL')->willReturnArgument(0);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc.example/apps/kanso/');
	}

	public function testGetIdAndName(): void {
		self::assertSame('kanso', $this->notifier->getID());
		self::assertSame('Kanso', $this->notifier->getName());
	}

	private function notification(string $app, string $subject, array $params = ['actor' => 'alice'], string $objectId = '9'): INotification&MockObject {
		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn($app);
		$n->method('getSubject')->willReturn($subject);
		$n->method('getSubjectParameters')->willReturn($params);
		$n->method('getObjectId')->willReturn($objectId);
		// setLink / setRichSubject are asserted per-test, so leave them for the
		// test to configure; stub the rest of the fluent chain to return self.
		$n->method('setIcon')->willReturnSelf();
		$n->method('setParsedSubject')->willReturnSelf();
		return $n;
	}

	private function card(int $id = 9, int $boardId = 3, string $title = 'Fix the bug'): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId($boardId);
		$card->setTitle($title);
		return $card;
	}

	public function testPrepareCardAssignedSetsLinkedRichSubject(): void {
		$n = $this->notification('kanso', 'card_assigned');
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());

		$actor = $this->createMock(IUser::class);
		$actor->method('getDisplayName')->willReturn('Alice A.');
		$this->userManager->method('get')->with('alice')->willReturn($actor);

		$n->expects(self::once())->method('setLink')
			->with('https://nc.example/apps/kanso/#/board/3/card/9')->willReturnSelf();
		$n->expects(self::once())->method('setRichSubject')
			->with(
				'{actor} assigned you to {card}',
				self::callback(static fn (array $p): bool
					=> $p['actor']['id'] === 'alice'
					&& $p['actor']['name'] === 'Alice A.'
					&& $p['card']['id'] === '9'
					&& $p['card']['name'] === 'Fix the bug')
			)->willReturnSelf();

		self::assertSame($n, $this->notifier->prepare($n, 'en'));
	}

	public function testPrepareRejectsForeignApp(): void {
		$n = $this->notification('files', 'card_assigned');
		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($n, 'en');
	}

	public function testPrepareRejectsUnknownSubject(): void {
		$n = $this->notification('kanso', 'card_exploded');
		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($n, 'en');
	}

	public function testPrepareRejectsWhenCardIsGone(): void {
		$n = $this->notification('kanso', 'card_assigned');
		$this->cardMapper->method('find')->with(9)->willThrowException(new DoesNotExistException('gone'));
		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($n, 'en');
	}

	public function testPrepareRejectsWhenCardIsHiddenFromTheRecipient(): void {
		// Render-time audience gate (#3760): the card's visibility narrowed
		// past the recipient AFTER the notification was queued - the bell
		// entry (title + link) must behave exactly like a purged card.
		$n = $this->notification('kanso', 'card_assigned');
		$n->method('getUser')->willReturn('bob');
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());

		$guard = $this->createMock(CardVisibilityGuard::class);
		$guard->method('isVisible')->willReturn(false);
		$notifier = new Notifier(
			$this->l10nFactory,
			$this->urlGenerator,
			$this->userManager,
			$this->cardMapper,
			$this->boardMapper,
			$guard,
		);

		$n->expects(self::never())->method('setRichSubject');
		$this->expectException(UnknownNotificationException::class);
		$notifier->prepare($n, 'en');
	}

	public function testPrepareChecksVisibilityForTheNotificationsRecipient(): void {
		// The gate must ask about the RECIPIENT (the notification's user),
		// not the actor or anyone else.
		$n = $this->notification('kanso', 'card_assigned');
		$n->method('getUser')->willReturn('bob');
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$actor = $this->createMock(IUser::class);
		$actor->method('getDisplayName')->willReturn('Alice A.');
		$this->userManager->method('get')->willReturn($actor);
		$n->method('setLink')->willReturnSelf();
		$n->method('setRichSubject')->willReturnSelf();

		$this->visibilityGuard->expects(self::once())
			->method('isVisible')
			->with(self::isInstanceOf(Board::class), self::isInstanceOf(Card::class), 'bob')
			->willReturn(true);

		$this->notifier->prepare($n, 'en');
	}
}
