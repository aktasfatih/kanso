<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ReviewType;
use OCA\Kanso\Db\ReviewTypeMapper;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\ReviewTypeService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReviewTypeServiceTest extends TestCase {
	private ReviewTypeMapper&MockObject $reviewTypeMapper;
	private CardReviewMapper&MockObject $cardReviewMapper;
	private BoardMapper&MockObject $boardMapper;
	private ChangeNotifier&MockObject $changeNotifier;
	private PermissionService&MockObject $permissionService;
	private IDBConnection&MockObject $db;
	private ReviewTypeService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->reviewTypeMapper = $this->createMock(ReviewTypeMapper::class);
		$this->cardReviewMapper = $this->createMock(CardReviewMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->service = new ReviewTypeService(
			$this->reviewTypeMapper,
			$this->cardReviewMapper,
			$this->boardMapper,
			$this->changeNotifier,
			$this->permissionService,
			$this->db
		);
	}

	private function board(int $id = 1): Board {
		$board = new Board();
		$board->setId($id);
		$board->setOwner('alice');
		$board->setDeletedAt(0);
		return $board;
	}

	private function type(int $id = 3, int $boardId = 1): ReviewType {
		$type = new ReviewType();
		$type->setId($id);
		$type->setBoardId($boardId);
		$type->setTitle('QA');
		$type->setColor('aabbcc');
		return $type;
	}

	// ---- create -----------------------------------------------------------

	public function testCreateInsertsTypeAndWritesChangeRow(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_MANAGE);
		$this->reviewTypeMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (ReviewType $t): ReviewType {
				self::assertSame('QA', $t->getTitle());
				self::assertSame('aabbcc', $t->getColor());
				self::assertSame(1, $t->getBoardId());
				$t->setId(3);
				return $t;
			});
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_REVIEW_TYPE, 3, Change::ACTION_CREATE, 'alice')
			->willReturn(new Change());

		$type = $this->service->create(1, 'QA', 'aabbcc', 'alice');
		self::assertSame(3, $type->getId());
	}

	public function testCreateRejectsEmptyTitle(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->reviewTypeMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->create(1, '   ', null, 'alice');
	}

	public function testCreatePersistsStage(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->reviewTypeMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (ReviewType $t): ReviewType {
				self::assertSame(2, $t->getStage());
				$t->setId(3);
				return $t;
			});
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->create(1, 'QA', null, 'alice', 2);
	}

	public function testCreateDefaultsStageToZeroWhenOmitted(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->reviewTypeMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (ReviewType $t): ReviewType {
				self::assertSame(0, $t->getStage());
				$t->setId(3);
				return $t;
			});
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->create(1, 'QA', null, 'alice');
	}

	public function testCreateRejectsNegativeStage(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->reviewTypeMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->create(1, 'QA', null, 'alice', -1);
	}

	public function testCreateAssertsManagePermission(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_MANAGE)
			->willThrowException(new NotPermittedException());
		$this->reviewTypeMapper->expects(self::never())->method('insert');

		$this->expectException(NotPermittedException::class);
		$this->service->create(1, 'QA', null, 'mallory');
	}

	// ---- update -----------------------------------------------------------

	public function testUpdateAppliesFieldsAndWritesChangeRow(): void {
		$this->reviewTypeMapper->method('find')->with(3)->willReturn($this->type());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with(self::anything(), 'alice', PermissionService::PERMISSION_MANAGE);
		$this->reviewTypeMapper->expects(self::once())
			->method('update')
			->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_REVIEW_TYPE, 3, Change::ACTION_UPDATE, 'alice')
			->willReturn(new Change());

		$updated = $this->service->update(3, 'Code', 'ddeeff', 'alice');
		self::assertSame('Code', $updated->getTitle());
		self::assertSame('ddeeff', $updated->getColor());
	}

	public function testUpdatePersistsStage(): void {
		$this->reviewTypeMapper->method('find')->with(3)->willReturn($this->type());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->reviewTypeMapper->expects(self::once())->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$updated = $this->service->update(3, null, null, 'alice', 5);
		self::assertSame(5, $updated->getStage());
	}

	public function testUpdateRejectsNegativeStage(): void {
		$this->reviewTypeMapper->method('find')->with(3)->willReturn($this->type());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->reviewTypeMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->update(3, null, null, 'alice', -1);
	}

	public function testUpdateAssertsManagePermissionOnStageEdit(): void {
		// Permission denial specifically on a stage-only edit.
		$this->reviewTypeMapper->method('find')->with(3)->willReturn($this->type());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with(self::anything(), 'mallory', PermissionService::PERMISSION_MANAGE)
			->willThrowException(new NotPermittedException());
		$this->reviewTypeMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->update(3, null, null, 'mallory', 2);
	}

	// ---- delete -----------------------------------------------------------

	public function testDeleteClearsTypeFromReviewsAndWritesChangeRow(): void {
		$this->reviewTypeMapper->method('find')->with(3)->willReturn($this->type());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with(self::anything(), 'alice', PermissionService::PERMISSION_MANAGE);
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->db->expects(self::never())->method('rollBack');
		$this->cardReviewMapper->expects(self::once())->method('clearType')->with(3)->willReturn(2);
		$this->reviewTypeMapper->expects(self::once())->method('delete');
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_REVIEW_TYPE, 3, Change::ACTION_DELETE, 'alice')
			->willReturn(new Change());

		$this->service->delete(3, 'alice');
	}

	public function testDeleteAssertsManagePermission(): void {
		$this->reviewTypeMapper->method('find')->with(3)->willReturn($this->type());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->willThrowException(new NotPermittedException());
		$this->db->expects(self::never())->method('beginTransaction');
		$this->cardReviewMapper->expects(self::never())->method('clearType');
		$this->reviewTypeMapper->expects(self::never())->method('delete');

		$this->expectException(NotPermittedException::class);
		$this->service->delete(3, 'mallory');
	}
}
