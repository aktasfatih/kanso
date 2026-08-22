<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeDetailMapper;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\LabelService;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LabelServiceTest extends TestCase {
	private LabelMapper&MockObject $labelMapper;
	private CardLabelMapper&MockObject $cardLabelMapper;
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private ChangeNotifier&MockObject $changeNotifier;
	private PermissionService&MockObject $permissionService;
	private IDBConnection&MockObject $db;
	private CardVisibilityGuard&MockObject $visibilityGuard;
	private ChangeDetailMapper&MockObject $changeDetailMapper;
	private LabelService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->labelMapper = $this->createMock(LabelMapper::class);
		$this->cardLabelMapper = $this->createMock(CardLabelMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->visibilityGuard = $this->createMock(CardVisibilityGuard::class);
		$this->visibilityGuard->method('isVisible')->willReturn(true);
		$this->changeDetailMapper = $this->createMock(ChangeDetailMapper::class);
		$this->service = new LabelService(
			$this->labelMapper,
			$this->cardLabelMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->changeNotifier,
			$this->permissionService,
			$this->db,
			$this->visibilityGuard,
			$this->changeDetailMapper,
		);
	}

	private function board(int $id = 1): Board {
		$board = new Board();
		$board->setId($id);
		$board->setOwner('alice');
		$board->setDeletedAt(0);
		return $board;
	}

	private function label(int $id = 7, int $boardId = 1): Label {
		$label = new Label();
		$label->setId($id);
		$label->setBoardId($boardId);
		$label->setTitle('Existing label');
		$label->setColor('AABBCC');
		return $label;
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

	// ---- create -----------------------------------------------------------

	public function testCreateInsertsLabelAndChangeRow(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_MANAGE);
		$this->labelMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (Label $label): Label {
				self::assertSame(1, $label->getBoardId());
				self::assertSame('Urgent', $label->getTitle());
				self::assertSame('FF0000', $label->getColor());
				$label->setId(7);
				return $label;
			});
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_LABEL,
				7,
				Change::ACTION_CREATE,
				'alice'
			)
			->willReturn(new Change());

		$label = $this->service->create(1, 'Urgent', 'FF0000', 'alice');
		self::assertSame(7, $label->getId());
	}

	public function testCreateRejectsEmptyTitle(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->labelMapper->expects(self::never())->method('insert');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(InvalidInputException::class);
		$this->service->create(1, '   ', null, 'alice');
	}

	public function testCreateRejectsOverlongTitle(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->labelMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->create(1, str_repeat('x', 101), null, 'alice');
	}

	public function testCreateRejectsInvalidColor(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->labelMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->create(1, 'Urgent', '#FF0000', 'alice');
	}

	public function testCreateRejectsDeletedBoard(): void {
		$board = $this->board();
		$board->setDeletedAt(1234);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->labelMapper->expects(self::never())->method('insert');

		$this->expectException(DoesNotExistException::class);
		$this->service->create(1, 'Urgent', null, 'alice');
	}

	public function testEditOnlyUserCannotCreateLabels(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		// bob holds EDIT but not MANAGE: only the MANAGE assertion throws -
		// label CRUD is a board-management concern.
		$this->permissionService->method('assertPermission')
			->willReturnCallback(static function (Board $b, string $uid, int $permission): void {
				if (($permission & PermissionService::PERMISSION_MANAGE) !== 0) {
					throw new NotPermittedException();
				}
			});
		$this->labelMapper->expects(self::never())->method('insert');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(NotPermittedException::class);
		$this->service->create(1, 'Urgent', null, 'bob');
	}

	// ---- update -----------------------------------------------------------

	public function testUpdateAppliesFieldsAndWritesChangeRow(): void {
		$this->labelMapper->method('find')->with(7)->willReturn($this->label());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->labelMapper->expects(self::once())
			->method('update')
			->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_LABEL,
				7,
				Change::ACTION_UPDATE,
				'alice'
			)
			->willReturn(new Change());

		$updated = $this->service->update(7, 'Renamed', '112233', 'alice');
		self::assertSame('Renamed', $updated->getTitle());
		self::assertSame('112233', $updated->getColor());
	}

	public function testUpdateLeavesFieldsUnchangedOnNull(): void {
		$this->labelMapper->method('find')->with(7)->willReturn($this->label());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->labelMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$updated = $this->service->update(7, null, null, 'alice');
		self::assertSame('Existing label', $updated->getTitle());
		self::assertSame('AABBCC', $updated->getColor());
	}

	public function testUpdateClearsColorOnEmptyString(): void {
		$this->labelMapper->method('find')->with(7)->willReturn($this->label());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->labelMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$updated = $this->service->update(7, null, '', 'alice');
		self::assertNull($updated->getColor());
	}

	public function testUpdateAssertsManagePermission(): void {
		$board = $this->board();
		$this->labelMapper->method('find')->with(7)->willReturn($this->label());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'bob', PermissionService::PERMISSION_MANAGE)
			->willThrowException(new NotPermittedException());
		$this->labelMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(NotPermittedException::class);
		$this->service->update(7, 'Renamed', null, 'bob');
	}

	// ---- delete -----------------------------------------------------------

	public function testDeleteCascadesAssignmentsAndWritesChangeRow(): void {
		$label = $this->label();
		$this->labelMapper->method('find')->with(7)->willReturn($label);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardLabelMapper->expects(self::once())
			->method('deleteByLabel')
			->with(7)
			->willReturn(3);
		$this->labelMapper->expects(self::once())
			->method('delete')
			->with($label)
			->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_LABEL,
				7,
				Change::ACTION_DELETE,
				'alice'
			)
			->willReturn(new Change());

		$this->service->delete(7, 'alice');
	}

	public function testDeleteAssertsManagePermission(): void {
		$board = $this->board();
		$this->labelMapper->method('find')->with(7)->willReturn($this->label());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'bob', PermissionService::PERMISSION_MANAGE)
			->willThrowException(new NotPermittedException());
		$this->cardLabelMapper->expects(self::never())->method('deleteByLabel');
		$this->labelMapper->expects(self::never())->method('delete');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(NotPermittedException::class);
		$this->service->delete(7, 'bob');
	}

	// ---- assign -----------------------------------------------------------

	public function testAssignInsertsAssignmentAndWritesCardChangeRow(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_EDIT);
		$this->labelMapper->method('find')->with(7)->willReturn($this->label());
		$this->cardLabelMapper->method('exists')->with(9, 7)->willReturn(false);
		$this->cardLabelMapper->expects(self::once())
			->method('insertAssignment')
			->with(9, 7);
		$change = new Change();
		$change->setId(41);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_CARD,
				9,
				Change::ACTION_UPDATE,
				'alice'
			)
			->willReturn($change);
		// The label title is recorded as the detail's `to` side (added).
		$this->changeDetailMapper->expects(self::once())
			->method('insertDetail')
			->with(41, null, 'Existing label');

		$this->service->assign(9, 7, 'alice');
	}

	public function testAssignAssertsEditPermission(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'bob', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->cardLabelMapper->expects(self::never())->method('insertAssignment');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(NotPermittedException::class);
		$this->service->assign(9, 7, 'bob');
	}

	public function testAssignRejectsCrossBoardLabel(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->labelMapper->method('find')->with(7)->willReturn($this->label(7, 2));
		$this->cardLabelMapper->expects(self::never())->method('insertAssignment');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(InvalidInputException::class);
		$this->service->assign(9, 7, 'alice');
	}

	public function testAssignIsIdempotentAndWritesNoChangeRowOnReassign(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->labelMapper->method('find')->with(7)->willReturn($this->label());
		$this->cardLabelMapper->method('exists')->with(9, 7)->willReturn(true);
		$this->cardLabelMapper->expects(self::never())->method('insertAssignment');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->service->assign(9, 7, 'alice');
	}

	public function testAssignRejectsDeletedCard(): void {
		$card = $this->card();
		$card->setDeletedAt(1234);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->cardLabelMapper->expects(self::never())->method('insertAssignment');

		$this->expectException(DoesNotExistException::class);
		$this->service->assign(9, 7, 'alice');
	}

	// ---- unassign ---------------------------------------------------------

	public function testUnassignDeletesAssignmentAndWritesCardChangeRow(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_EDIT);
		// The label title is resolved before the assignment is removed.
		$this->labelMapper->method('find')->with(7)->willReturn($this->label());
		$this->cardLabelMapper->expects(self::once())
			->method('deleteAssignment')
			->with(9, 7)
			->willReturn(1);
		$change = new Change();
		$change->setId(42);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_CARD,
				9,
				Change::ACTION_UPDATE,
				'alice'
			)
			->willReturn($change);
		// The removed label title is recorded as the detail's `from` side.
		$this->changeDetailMapper->expects(self::once())
			->method('insertDetail')
			->with(42, 'Existing label', null);

		$this->service->unassign(9, 7, 'alice');
	}

	public function testUnassignIsIdempotentAndWritesNoChangeRowWhenAbsent(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->labelMapper->method('find')->with(7)->willReturn($this->label());
		$this->cardLabelMapper->method('deleteAssignment')->with(9, 7)->willReturn(0);
		$this->changeNotifier->expects(self::never())->method('notify');
		$this->changeDetailMapper->expects(self::never())->method('insertDetail');

		$this->service->unassign(9, 7, 'alice');
	}

	public function testUnassignSkipsDetailWhenLabelAlreadyDeleted(): void {
		// A stale assignment whose label row is gone: the change row still records
		// the removal, but there is no title to store, so no detail is written.
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->labelMapper->method('find')->with(7)
			->willThrowException(new DoesNotExistException('gone'));
		$this->cardLabelMapper->method('deleteAssignment')->with(9, 7)->willReturn(1);
		$change = new Change();
		$change->setId(43);
		$this->changeNotifier->expects(self::once())->method('notify')->willReturn($change);
		$this->changeDetailMapper->expects(self::never())->method('insertDetail');

		$this->service->unassign(9, 7, 'alice');
	}

	public function testUnassignAssertsEditPermission(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'bob', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->cardLabelMapper->expects(self::never())->method('deleteAssignment');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(NotPermittedException::class);
		$this->service->unassign(9, 7, 'bob');
	}
}
