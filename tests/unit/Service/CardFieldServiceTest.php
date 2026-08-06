<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\CardField;
use OCA\Kanso\Db\CardFieldMapper;
use OCA\Kanso\Db\CardFieldValueMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Service\CardFieldService;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\SortKeyService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CardFieldServiceTest extends TestCase {
	private CardFieldMapper&MockObject $cardFieldMapper;
	private CardFieldValueMapper&MockObject $cardFieldValueMapper;
	private BoardMapper&MockObject $boardMapper;
	private ChangeNotifier&MockObject $changeNotifier;
	private PermissionService&MockObject $permissionService;
	private IDBConnection&MockObject $db;
	private CardFieldService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->cardFieldMapper = $this->createMock(CardFieldMapper::class);
		$this->cardFieldValueMapper = $this->createMock(CardFieldValueMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->service = new CardFieldService(
			$this->cardFieldMapper,
			$this->cardFieldValueMapper,
			$this->boardMapper,
			$this->changeNotifier,
			$this->permissionService,
			new SortKeyService(),
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

	private function field(int $id = 3, int $boardId = 1, string $type = CardField::TYPE_TEXT): CardField {
		$field = new CardField();
		$field->setId($id);
		$field->setBoardId($boardId);
		$field->setName('Severity');
		$field->setType($type);
		$field->setSortKey('I');
		return $field;
	}

	// ---- create -----------------------------------------------------------

	public function testCreateInsertsFieldAndWritesChangeRow(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->cardFieldMapper->method('lastSortKey')->with(1)->willReturn(null);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_MANAGE);
		$this->cardFieldMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (CardField $f): CardField {
				self::assertSame('Priority', $f->getName());
				self::assertSame(CardField::TYPE_TEXT, $f->getType());
				self::assertSame(1, $f->getBoardId());
				self::assertNotSame('', $f->getSortKey());
				$f->setId(3);
				return $f;
			});
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD_FIELD, 3, Change::ACTION_CREATE, 'alice')
			->willReturn(new Change());

		$field = $this->service->create(1, 'Priority', CardField::TYPE_TEXT, null, 'alice');
		self::assertSame(3, $field->getId());
	}

	public function testCreateAppendsSortKeyAfterLast(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardFieldMapper->method('lastSortKey')->with(1)->willReturn('I');
		$this->cardFieldMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (CardField $f): CardField {
				// A key strictly after 'I'.
				self::assertGreaterThan(0, strcmp($f->getSortKey(), 'I'));
				$f->setId(4);
				return $f;
			});
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->create(1, 'Team', CardField::TYPE_TEXT, null, 'alice');
	}

	public function testCreateRejectsEmptyName(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardFieldMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->create(1, '   ', CardField::TYPE_TEXT, null, 'alice');
	}

	public function testCreateRejectsUnknownType(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardFieldMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->create(1, 'Field', 'currency', null, 'alice');
	}

	public function testCreateRejectsOptionsOnNonSelectType(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardFieldMapper->method('lastSortKey')->willReturn(null);
		$this->cardFieldMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->create(1, 'Field', CardField::TYPE_TEXT, ['a', 'b'], 'alice');
	}

	public function testCreateStoresSelectOptionsAsJson(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardFieldMapper->method('lastSortKey')->willReturn(null);
		$this->cardFieldMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (CardField $f): CardField {
				self::assertSame(['low', 'high'], $f->getOptionsArray());
				$f->setId(5);
				return $f;
			});
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->create(1, 'Sev', CardField::TYPE_SELECT, [' low ', 'high'], 'alice');
	}

	public function testCreateAssertsManagePermission(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_MANAGE)
			->willThrowException(new NotPermittedException());
		$this->cardFieldMapper->expects(self::never())->method('insert');

		$this->expectException(NotPermittedException::class);
		$this->service->create(1, 'Field', CardField::TYPE_TEXT, null, 'mallory');
	}

	// ---- update -----------------------------------------------------------

	public function testUpdateAppliesNameAndWritesChangeRow(): void {
		$this->cardFieldMapper->method('find')->with(3)->willReturn($this->field());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with(self::anything(), 'alice', PermissionService::PERMISSION_MANAGE);
		$this->cardFieldMapper->expects(self::once())->method('update')->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD_FIELD, 3, Change::ACTION_UPDATE, 'alice')
			->willReturn(new Change());

		$updated = $this->service->update(3, 'Impact', null, null, 'alice');
		self::assertSame('Impact', $updated->getName());
	}

	public function testUpdateRejectsOptionsOnNonSelectField(): void {
		$this->cardFieldMapper->method('find')->with(3)->willReturn($this->field(3, 1, CardField::TYPE_TEXT));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardFieldMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->update(3, null, ['x'], null, 'alice');
	}

	public function testUpdateAssertsManagePermission(): void {
		$this->cardFieldMapper->method('find')->with(3)->willReturn($this->field());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->willThrowException(new NotPermittedException());
		$this->cardFieldMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->update(3, 'Impact', null, null, 'mallory');
	}

	// ---- delete -----------------------------------------------------------

	public function testDeleteCascadesValuesTransactionallyAndWritesChangeRow(): void {
		$this->cardFieldMapper->method('find')->with(3)->willReturn($this->field());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with(self::anything(), 'alice', PermissionService::PERMISSION_MANAGE);
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->db->expects(self::never())->method('rollBack');
		$this->cardFieldValueMapper->expects(self::once())->method('deleteByField')->with(3)->willReturn(2);
		$this->cardFieldMapper->expects(self::once())->method('delete');
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD_FIELD, 3, Change::ACTION_DELETE, 'alice')
			->willReturn(new Change());

		$this->service->delete(3, 'alice');
	}

	public function testDeleteRollsBackOnThrow(): void {
		$this->cardFieldMapper->method('find')->with(3)->willReturn($this->field());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::never())->method('commit');
		$this->db->expects(self::once())->method('rollBack');
		$this->cardFieldValueMapper->method('deleteByField')->with(3)
			->willThrowException(new \RuntimeException('boom'));
		$this->cardFieldMapper->expects(self::never())->method('delete');

		$this->expectException(\RuntimeException::class);
		$this->service->delete(3, 'alice');
	}

	public function testDeleteAssertsManagePermission(): void {
		$this->cardFieldMapper->method('find')->with(3)->willReturn($this->field());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->willThrowException(new NotPermittedException());
		$this->db->expects(self::never())->method('beginTransaction');
		$this->cardFieldValueMapper->expects(self::never())->method('deleteByField');
		$this->cardFieldMapper->expects(self::never())->method('delete');

		$this->expectException(NotPermittedException::class);
		$this->service->delete(3, 'mallory');
	}
}
