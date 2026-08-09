<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardContactMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\ContactService;
use OCA\Kanso\Service\ContactsUnavailableException;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ContactServiceTest extends TestCase {
	private CardContactMapper&MockObject $cardContactMapper;
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private ChangeNotifier&MockObject $changeNotifier;
	private PermissionService&MockObject $permissionService;
	private IAppManager&MockObject $appManager;
	private IContactsManager&MockObject $contactsManager;
	private IUserManager&MockObject $userManager;
	private CardVisibilityGuard&MockObject $visibilityGuard;
	private ContactService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->cardContactMapper = $this->createMock(CardContactMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->contactsManager = $this->createMock(IContactsManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->visibilityGuard = $this->createMock(CardVisibilityGuard::class);
		$this->visibilityGuard->method('isVisible')->willReturn(true);
		$this->service = new ContactService(
			$this->cardContactMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->changeNotifier,
			$this->permissionService,
			$this->appManager,
			$this->contactsManager,
			$this->userManager,
			$this->visibilityGuard,
		);
	}

	private function board(int $id = 1): Board {
		$board = new Board();
		$board->setId($id);
		$board->setOwner('alice');
		$board->setDeletedAt(0);
		return $board;
	}

	private function card(int $id = 9, int $boardId = 1): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId($boardId);
		$card->setStackId(5);
		$card->setTitle('Existing card');
		$card->setSortKey('I');
		$card->setDeletedAt(0);
		return $card;
	}

	/** Makes the optional Contacts app appear enabled for a uid. */
	private function contactsEnabled(string $uid = 'alice'): void {
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with($uid)->willReturn($user);
		$this->appManager->method('isEnabledForUser')->with('contacts', $user)->willReturn(true);
		$this->contactsManager->method('isEnabled')->willReturn(true);
	}

	// ---- link -------------------------------------------------------------

	public function testLinkPersistsAndWritesCardChangeRow(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_EDIT);
		$this->contactsEnabled('alice');
		// Server-side validation resolves the submitted UID to a real contact.
		$this->contactsManager->method('search')
			->with('urn:uuid:abc', ['UID'], self::anything())
			->willReturn([['UID' => 'urn:uuid:abc', 'FN' => 'Jane Doe']]);
		$this->cardContactMapper->method('exists')->with(9, 'urn:uuid:abc')->willReturn(false);
		$this->cardContactMapper->expects(self::once())
			->method('insertLink')
			->with(9, 'urn:uuid:abc', 'Jane Doe');
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'alice', true, Change::VERB_CONTACT_LINKED)
			->willReturn(new Change());

		$result = $this->service->link(9, 'urn:uuid:abc', 'Jane (stale)', 'alice');

		// The stored/returned name is the server-resolved snapshot, not the
		// client-submitted (possibly stale) one.
		self::assertSame(['contactUri' => 'urn:uuid:abc', 'displayName' => 'Jane Doe'], $result);
	}

	public function testLinkIsIdempotentAndWritesNoChangeRowWhenAlreadyLinked(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->contactsEnabled('alice');
		$this->contactsManager->method('search')
			->willReturn([['UID' => 'urn:uuid:abc', 'FN' => 'Jane Doe']]);
		$this->cardContactMapper->method('exists')->with(9, 'urn:uuid:abc')->willReturn(true);
		$this->cardContactMapper->expects(self::never())->method('insertLink');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->service->link(9, 'urn:uuid:abc', 'Jane Doe', 'alice');
	}

	public function testLinkAssertsActorEditPermission(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->cardContactMapper->expects(self::never())->method('insertLink');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(NotPermittedException::class);
		$this->service->link(9, 'urn:uuid:abc', 'Jane Doe', 'mallory');
	}

	public function testLinkRejectsWhenContactsAppDisabled(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		// EDIT passes, but the optional Contacts app is not enabled.
		$this->userManager->method('get')->with('alice')->willReturn($this->createMock(IUser::class));
		$this->appManager->method('isEnabledForUser')->willReturn(false);
		$this->cardContactMapper->expects(self::never())->method('insertLink');
		$this->changeNotifier->expects(self::never())->method('notify');

		// A clean 4xx (ContactsUnavailableException extends InvalidInputException),
		// never a 500.
		$this->expectException(ContactsUnavailableException::class);
		$this->service->link(9, 'urn:uuid:abc', 'Jane Doe', 'alice');
	}

	public function testLinkRejectsUnresolvableContact(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->contactsEnabled('alice');
		// The submitted UID matches nothing in the address books.
		$this->contactsManager->method('search')->willReturn([]);
		$this->cardContactMapper->expects(self::never())->method('insertLink');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(InvalidInputException::class);
		$this->service->link(9, 'urn:uuid:ghost', 'Ghost', 'alice');
	}

	public function testLinkRejectsSystemAddressBookContact(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->contactsEnabled('alice');
		// A system-address-book hit (an NC user) is not a real contact - reject it.
		$this->contactsManager->method('search')->willReturn([
			['UID' => 'bob', 'FN' => 'Bob', 'isLocalSystemBook' => true],
		]);
		$this->cardContactMapper->expects(self::never())->method('insertLink');

		$this->expectException(InvalidInputException::class);
		$this->service->link(9, 'bob', 'Bob', 'alice');
	}

	public function testLinkRejectsDeletedCard(): void {
		$card = $this->card();
		$card->setDeletedAt(1234);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->cardContactMapper->expects(self::never())->method('insertLink');

		$this->expectException(DoesNotExistException::class);
		$this->service->link(9, 'urn:uuid:abc', 'Jane Doe', 'alice');
	}

	// ---- unlink -----------------------------------------------------------

	public function testUnlinkDeletesAndWritesCardChangeRow(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_EDIT);
		$this->cardContactMapper->expects(self::once())
			->method('deleteLink')
			->with(9, 'urn:uuid:abc')
			->willReturn(1);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'alice', true, Change::VERB_CONTACT_UNLINKED)
			->willReturn(new Change());

		$this->service->unlink(9, 'urn:uuid:abc', 'alice');
	}

	public function testUnlinkIsIdempotentWhenAbsent(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardContactMapper->method('deleteLink')->with(9, 'urn:uuid:abc')->willReturn(0);
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->service->unlink(9, 'urn:uuid:abc', 'alice');
	}

	public function testUnlinkWorksEvenWhenContactsAppDisabled(): void {
		// A dangling link must always be removable, so unlink never checks
		// Contacts availability.
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->appManager->expects(self::never())->method('isEnabledForUser');
		$this->cardContactMapper->method('deleteLink')->with(9, 'urn:uuid:abc')->willReturn(1);
		$this->changeNotifier->expects(self::once())->method('notify')->willReturn(new Change());

		$this->service->unlink(9, 'urn:uuid:abc', 'alice');
	}

	public function testUnlinkAssertsActorEditPermission(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->cardContactMapper->expects(self::never())->method('deleteLink');

		$this->expectException(NotPermittedException::class);
		$this->service->unlink(9, 'urn:uuid:abc', 'mallory');
	}

	// ---- search (picker) --------------------------------------------------

	public function testSearchReturnsEmptyWhenContactsAppDisabled(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_READ);
		$this->userManager->method('get')->with('alice')->willReturn($this->createMock(IUser::class));
		$this->appManager->method('isEnabledForUser')->willReturn(false);
		$this->contactsManager->expects(self::never())->method('search');

		self::assertSame([], $this->service->search(1, 'alice', 'jane'));
	}

	public function testSearchNormalizesAndFiltersSystemBook(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->contactsEnabled('alice');
		$this->contactsManager->method('search')->willReturn([
			['UID' => 'urn:uuid:abc', 'FN' => 'Jane Doe', 'EMAIL' => 'jane@example.com'],
			// System-address-book entry (an NC user) - excluded from the picker.
			['UID' => 'bob', 'FN' => 'Bob', 'isLocalSystemBook' => true],
			// No UID - cannot be keyed, excluded.
			['FN' => 'No Id'],
		]);

		$out = $this->service->search(1, 'alice', 'ja');

		self::assertSame([
			['contactUri' => 'urn:uuid:abc', 'displayName' => 'Jane Doe', 'email' => 'jane@example.com'],
		], $out);
	}

	public function testSearchAssertsReadPermission(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'stranger', PermissionService::PERMISSION_READ)
			->willThrowException(new NotPermittedException());

		$this->expectException(NotPermittedException::class);
		$this->service->search(1, 'stranger', 'jane');
	}
}
