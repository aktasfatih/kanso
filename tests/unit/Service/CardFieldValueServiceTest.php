<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardField;
use OCA\Kanso\Db\CardFieldMapper;
use OCA\Kanso\Db\CardFieldValue;
use OCA\Kanso\Db\CardFieldValueMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Service\CardFieldValueService;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CardFieldValueServiceTest extends TestCase {
	private CardFieldValueMapper&MockObject $cardFieldValueMapper;
	private CardFieldMapper&MockObject $cardFieldMapper;
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private ChangeNotifier&MockObject $changeNotifier;
	private PermissionService&MockObject $permissionService;
	private CardVisibilityGuard&MockObject $visibilityGuard;
	private CardFieldValueService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->cardFieldValueMapper = $this->createMock(CardFieldValueMapper::class);
		$this->cardFieldMapper = $this->createMock(CardFieldMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->visibilityGuard = $this->createMock(CardVisibilityGuard::class);
		$this->visibilityGuard->method('isVisible')->willReturn(true);
		$this->service = new CardFieldValueService(
			$this->cardFieldValueMapper,
			$this->cardFieldMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->changeNotifier,
			$this->permissionService,
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
		return $card;
	}

	private function field(int $id = 3, int $boardId = 1, string $type = CardField::TYPE_TEXT, ?string $options = null): CardField {
		$field = new CardField();
		$field->setId($id);
		$field->setBoardId($boardId);
		$field->setName('Field');
		$field->setType($type);
		$field->setOptions($options);
		$field->setSortKey('I');
		return $field;
	}

	// ---- set: happy path & upsert ----------------------------------------

	public function testSetInsertsWhenNoExistingValue(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->cardFieldMapper->method('find')->with(3)->willReturn($this->field());
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_EDIT);
		$this->cardFieldValueMapper->method('findByCardAndField')->with(9, 3)->willReturn(null);
		$this->cardFieldValueMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (CardFieldValue $v): CardFieldValue {
				self::assertSame(9, $v->getCardId());
				self::assertSame(3, $v->getFieldId());
				self::assertSame('hello', $v->getValue());
				return $v;
			});
		$this->cardFieldValueMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'alice', true, Change::VERB_UPDATED)
			->willReturn(new Change());

		$this->service->set(9, 3, 'hello', 'alice');
	}

	public function testSetUpdatesWhenValueAlreadyExists(): void {
		// (card_id, field_id) upsert uniqueness: a second write updates the row.
		$existing = new CardFieldValue();
		$existing->setId(50);
		$existing->setCardId(9);
		$existing->setFieldId(3);
		$existing->setValue('old');

		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardFieldMapper->method('find')->with(3)->willReturn($this->field());
		$this->cardFieldValueMapper->method('findByCardAndField')->with(9, 3)->willReturn($existing);
		$this->cardFieldValueMapper->expects(self::never())->method('insert');
		$this->cardFieldValueMapper->expects(self::once())
			->method('update')
			->willReturnCallback(static function (CardFieldValue $v): CardFieldValue {
				self::assertSame(50, $v->getId());
				self::assertSame('new', $v->getValue());
				return $v;
			});
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->set(9, 3, 'new', 'alice');
	}

	public function testSetEmptyValueClears(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardFieldMapper->method('find')->with(3)->willReturn($this->field());
		$this->cardFieldValueMapper->expects(self::never())->method('insert');
		$this->cardFieldValueMapper->expects(self::once())->method('deleteByCardAndField')->with(9, 3);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->set(9, 3, '   ', 'alice');
	}

	// ---- set: permission --------------------------------------------------

	public function testSetAssertsEditPermission(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->cardFieldValueMapper->expects(self::never())->method('insert');
		$this->cardFieldValueMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->set(9, 3, 'x', 'mallory');
	}

	public function testSetRejectsFieldFromAnotherBoard(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card(9, 1));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		// Field belongs to board 2, card to board 1.
		$this->cardFieldMapper->method('find')->with(3)->willReturn($this->field(3, 2));
		$this->cardFieldValueMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->set(9, 3, 'x', 'alice');
	}

	// ---- set: per-type validation ----------------------------------------

	public function testSetRejectsNonNumericNumber(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardFieldMapper->method('find')->with(3)->willReturn($this->field(3, 1, CardField::TYPE_NUMBER));
		$this->cardFieldValueMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->set(9, 3, 'not-a-number', 'alice');
	}

	public function testSetAcceptsNumber(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardFieldMapper->method('find')->with(3)->willReturn($this->field(3, 1, CardField::TYPE_NUMBER));
		$this->cardFieldValueMapper->method('findByCardAndField')->willReturn(null);
		$this->cardFieldValueMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (CardFieldValue $v): CardFieldValue {
				self::assertSame('42.5', $v->getValue());
				return $v;
			});
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->set(9, 3, '42.5', 'alice');
	}

	public function testSetRejectsBadDate(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardFieldMapper->method('find')->with(3)->willReturn($this->field(3, 1, CardField::TYPE_DATE));
		$this->cardFieldValueMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->set(9, 3, '2026-13-99', 'alice');
	}

	public function testSetNormalizesDate(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardFieldMapper->method('find')->with(3)->willReturn($this->field(3, 1, CardField::TYPE_DATE));
		$this->cardFieldValueMapper->method('findByCardAndField')->willReturn(null);
		$this->cardFieldValueMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (CardFieldValue $v): CardFieldValue {
				self::assertSame('2026-09-02', $v->getValue());
				return $v;
			});
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->set(9, 3, '2026-09-02T10:30:00Z', 'alice');
	}

	public function testSetRejectsNonMemberSelectOption(): void {
		$options = json_encode(['low', 'high']);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardFieldMapper->method('find')->with(3)
			->willReturn($this->field(3, 1, CardField::TYPE_SELECT, $options));
		$this->cardFieldValueMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->set(9, 3, 'medium', 'alice');
	}

	public function testSetAcceptsSelectOptionMember(): void {
		$options = json_encode(['low', 'high']);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardFieldMapper->method('find')->with(3)
			->willReturn($this->field(3, 1, CardField::TYPE_SELECT, $options));
		$this->cardFieldValueMapper->method('findByCardAndField')->willReturn(null);
		$this->cardFieldValueMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (CardFieldValue $v): CardFieldValue {
				self::assertSame('high', $v->getValue());
				return $v;
			});
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->set(9, 3, 'high', 'alice');
	}

	// ---- clear ------------------------------------------------------------

	public function testClearDeletesValueAndBumpsCard(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardFieldMapper->method('find')->with(3)->willReturn($this->field());
		$this->cardFieldValueMapper->expects(self::once())->method('deleteByCardAndField')->with(9, 3);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'alice', true, Change::VERB_UPDATED)
			->willReturn(new Change());

		$this->service->clear(9, 3, 'alice');
	}

	public function testClearAssertsEditPermission(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->cardFieldValueMapper->expects(self::never())->method('deleteByCardAndField');

		$this->expectException(NotPermittedException::class);
		$this->service->clear(9, 3, 'mallory');
	}
}
