<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardFileLink;
use OCA\Kanso\Db\CardFileLinkMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeDetailMapper;
use OCA\Kanso\Service\CardFileLinkService;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CardFileLinkServiceTest extends TestCase {
	private CardFileLinkMapper&MockObject $fileLinkMapper;
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private PermissionService&MockObject $permissionService;
	private CardVisibilityGuard&MockObject $visibilityGuard;
	private ChangeNotifier&MockObject $changeNotifier;
	private ChangeDetailMapper&MockObject $changeDetailMapper;
	private IRootFolder&MockObject $rootFolder;
	private CardFileLinkService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->fileLinkMapper = $this->createMock(CardFileLinkMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->visibilityGuard = $this->createMock(CardVisibilityGuard::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->changeDetailMapper = $this->createMock(ChangeDetailMapper::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);

		$change = new Change();
		$change->setId(77);
		$this->changeNotifier->method('notify')->willReturn($change);
		$this->fileLinkMapper->method('insert')->willReturnCallback(static function (CardFileLink $l): CardFileLink {
			$l->setId(5);
			return $l;
		});

		$this->service = new CardFileLinkService(
			$this->fileLinkMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->permissionService,
			$this->visibilityGuard,
			$this->changeNotifier,
			$this->changeDetailMapper,
			$this->rootFolder,
		);
	}

	private function expectCardLoaded(int $deletedAt = 0): Board {
		$card = new Card();
		$card->setId(9);
		$card->setBoardId(1);
		$card->setDeletedAt($deletedAt);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$board = new Board();
		$board->setId(1);
		$board->setDeletedAt(0);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		return $board;
	}

	/** @param array<int, \OCP\Files\Node> $nodes */
	private function expectUserFolderById(int $fileId, array $nodes, string $uid = 'bob'): void {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with($fileId)->willReturn($nodes);
		$this->rootFolder->method('getUserFolder')->with($uid)->willReturn($userFolder);
	}

	private function fileNode(string $name = 'report.pdf', string $mime = 'application/pdf'): File&MockObject {
		$node = $this->createMock(File::class);
		$node->method('getName')->willReturn($name);
		$node->method('getMimetype')->willReturn($mime);
		// The snapshot is the bare name - the linker's path must never be read.
		$node->expects(self::never())->method('getPath');
		return $node;
	}

	private function link(int $id, int $cardId, int $fileId = 42, string $name = 'report.pdf'): CardFileLink {
		$l = new CardFileLink();
		$l->setId($id);
		$l->setCardId($cardId);
		$l->setFileId($fileId);
		$l->setName($name);
		$l->setMime('application/pdf');
		$l->setLinkedBy('bob');
		$l->setCreatedAt(1700000000);
		return $l;
	}

	public function testAddLinkSnapshotsTheNameAndRecordsAFileLinkedChange(): void {
		$this->expectCardLoaded();
		$this->expectUserFolderById(42, [$this->fileNode()]);

		$this->fileLinkMapper->expects(self::once())->method('insert')
			->with(self::callback(static fn (CardFileLink $l): bool => $l->getCardId() === 9
				&& $l->getFileId() === 42
				&& $l->getName() === 'report.pdf'
				&& $l->getMime() === 'application/pdf'
				&& $l->getLinkedBy() === 'bob'
				&& $l->getCreatedAt() > 0));
		$this->changeNotifier->expects(self::once())->method('notify')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'bob', true, Change::VERB_FILE_LINKED);
		$this->changeDetailMapper->expects(self::once())->method('insertDetail')
			->with(77, null, 'report.pdf');

		$link = $this->service->addLink(9, 42, 'bob');

		self::assertSame(5, $link->getId());
	}

	public function testAddLinkRequiresEditAndInsertsNothing(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())->method('assertPermission')
			->with($board, 'viewer', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->rootFolder->expects(self::never())->method('getUserFolder');
		$this->fileLinkMapper->expects(self::never())->method('insert');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(NotPermittedException::class);
		$this->service->addLink(9, 42, 'viewer');
	}

	public function testAddLinkRejectsAFileTheLinkerCannotRead(): void {
		$this->expectCardLoaded();
		$this->expectUserFolderById(42, []);
		$this->fileLinkMapper->expects(self::never())->method('insert');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('File not found');
		$this->service->addLink(9, 42, 'bob');
	}

	public function testAddLinkRejectsBeyondThePerCardCap(): void {
		$this->expectCardLoaded();
		$this->expectUserFolderById(42, [$this->fileNode()]);
		$this->fileLinkMapper->method('countByCard')->with(9)->willReturn(CardFileLinkMapper::MAX_PER_CARD);
		$this->fileLinkMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->addLink(9, 42, 'bob');
	}

	public function testAddLinkReturnsTheExistingRowWhenRelinkingOnAFullCard(): void {
		$this->expectCardLoaded();
		$this->expectUserFolderById(42, [$this->fileNode()]);
		$this->fileLinkMapper->method('countByCard')->with(9)->willReturn(CardFileLinkMapper::MAX_PER_CARD);
		$existing = $this->link(3, 9, 42);
		$this->fileLinkMapper->method('findByCard')->with(9)->willReturn([$this->link(2, 9, 41), $existing]);
		$this->fileLinkMapper->expects(self::never())->method('insert');
		$this->changeNotifier->expects(self::never())->method('notify');

		self::assertSame($existing, $this->service->addLink(9, 42, 'bob'));
	}

	public function testAddLinkRefusesAFolder(): void {
		$this->expectCardLoaded();
		$this->expectUserFolderById(42, [$this->createMock(Folder::class)]);
		$this->fileLinkMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('File not found');
		$this->service->addLink(9, 42, 'bob');
	}

	public function testAddLinkReturnsTheExistingRowOnAUniqueViolation(): void {
		$this->expectCardLoaded();
		$this->expectUserFolderById(42, [$this->fileNode()]);
		$uniqueViolation = $this->createMock(\OCP\DB\Exception::class);
		$uniqueViolation->method('getReason')
			->willReturn(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		$fileLinkMapper = $this->createMock(CardFileLinkMapper::class);
		$fileLinkMapper->method('insert')->willThrowException($uniqueViolation);
		$fileLinkMapper->method('findByCard')->with(9)->willReturn([$this->link(3, 9, 7), $this->link(4, 9, 42)]);
		$this->changeNotifier->expects(self::never())->method('notify');

		$service = new CardFileLinkService(
			$fileLinkMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->permissionService,
			$this->visibilityGuard,
			$this->changeNotifier,
			$this->changeDetailMapper,
			$this->rootFolder,
		);

		self::assertSame(4, $service->addLink(9, 42, 'bob')->getId());
	}

	public function testAddLinkRefusesADeletedCard(): void {
		$this->expectCardLoaded(1700000000);
		$this->fileLinkMapper->expects(self::never())->method('insert');

		$this->expectException(DoesNotExistException::class);
		$this->service->addLink(9, 42, 'bob');
	}

	public function testListForCardReturnsRowsWithoutTouchingFiles(): void {
		$this->expectCardLoaded();
		$this->fileLinkMapper->method('findByCard')->with(9)->willReturn([$this->link(1, 9)]);
		$this->rootFolder->expects(self::never())->method('getUserFolder');
		$this->rootFolder->expects(self::never())->method('getById');

		$result = $this->service->listForCard(9, 'reader');

		self::assertCount(1, $result);
		self::assertSame('report.pdf', $result[0]->getName());
	}

	public function testListForCardRequiresRead(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())->method('assertPermission')
			->with($board, 'stranger', PermissionService::PERMISSION_READ)
			->willThrowException(new NotPermittedException());
		$this->fileLinkMapper->expects(self::never())->method('findByCard');

		$this->expectException(NotPermittedException::class);
		$this->service->listForCard(9, 'stranger');
	}

	public function testListForCardHonoursCardVisibility(): void {
		$board = $this->expectCardLoaded();
		$this->visibilityGuard->expects(self::once())->method('assertVisible')
			->with($board, self::isInstanceOf(Card::class), 'client')
			->willThrowException(new DoesNotExistException('hidden'));
		$this->fileLinkMapper->expects(self::never())->method('findByCard');

		$this->expectException(DoesNotExistException::class);
		$this->service->listForCard(9, 'client');
	}

	public function testListForCardRefusesADeletedCard(): void {
		$this->expectCardLoaded(1700000000);
		$this->fileLinkMapper->expects(self::never())->method('findByCard');

		$this->expectException(DoesNotExistException::class);
		$this->service->listForCard(9, 'bob');
	}

	public function testDeleteLinkRemovesAndRecordsAFileUnlinkedChange(): void {
		$this->expectCardLoaded();
		$link = $this->link(5, 9);
		$this->fileLinkMapper->method('find')->with(5)->willReturn($link);
		$this->fileLinkMapper->expects(self::once())->method('delete')->with($link);
		// Never consults Files: a link to a deleted/unshared node must stay removable.
		$this->rootFolder->expects(self::never())->method('getUserFolder');
		$this->changeNotifier->expects(self::once())->method('notify')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'bob', true, Change::VERB_FILE_UNLINKED);
		$this->changeDetailMapper->expects(self::once())->method('insertDetail')
			->with(77, 'report.pdf', null);

		$this->service->deleteLink(9, 5, 'bob');
	}

	public function testDeleteLinkRequiresEdit(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())->method('assertPermission')
			->with($board, 'viewer', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->fileLinkMapper->expects(self::never())->method('delete');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(NotPermittedException::class);
		$this->service->deleteLink(9, 5, 'viewer');
	}

	public function testDeleteLinkRejectsALinkOnAnotherCard(): void {
		$this->expectCardLoaded();
		$this->fileLinkMapper->method('find')->with(5)->willReturn($this->link(5, 99));
		$this->fileLinkMapper->expects(self::never())->method('delete');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(DoesNotExistException::class);
		$this->service->deleteLink(9, 5, 'bob');
	}
}
