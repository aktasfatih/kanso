<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Service\AssigneeService;
use OCA\Kanso\Service\BulkCardService;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\LabelService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BulkCardServiceTest extends TestCase {
	private CardService&MockObject $cardService;
	private LabelService&MockObject $labelService;
	private AssigneeService&MockObject $assigneeService;
	private CardMapper&MockObject $cardMapper;
	private BulkCardService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->cardService = $this->createMock(CardService::class);
		$this->labelService = $this->createMock(LabelService::class);
		$this->assigneeService = $this->createMock(AssigneeService::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->service = new BulkCardService(
			$this->cardService,
			$this->labelService,
			$this->assigneeService,
			$this->cardMapper,
		);
	}

	private function card(int $id): Card {
		$card = new Card();
		$card->setId($id);
		return $card;
	}

	// ── Happy paths (one per action) ────────────────────────────────────────────

	public function testBulkMoveAppendsEachCardToEndOfTargetStack(): void {
		// The target stack's tail is card 99, so every moved card lands after it.
		$this->cardMapper->method('findLastInStack')->with(6)->willReturn($this->card(99));

		$moved = [];
		$this->cardService->expects(self::exactly(2))
			->method('move')
			->willReturnCallback(function (int $id, int $stack, ?int $after, string $uid) use (&$moved): Card {
				self::assertSame(6, $stack);
				self::assertSame(99, $after); // appended to the end (after the tail)
				self::assertSame('alice', $uid);
				$moved[] = $id;
				return $this->card($id);
			});

		$result = $this->service->apply([11, 12], BulkCardService::ACTION_MOVE, ['targetStackId' => 6], 'alice');

		self::assertSame([11, 12], $result['ok']);
		self::assertSame([], $result['skipped']);
		self::assertSame([11, 12], $moved);
	}

	public function testBulkMoveIntoEmptyStackUsesNullAnchor(): void {
		$this->cardMapper->method('findLastInStack')->with(6)->willReturn(null);
		$this->cardService->expects(self::once())
			->method('move')
			->with(11, 6, null, 'alice')
			->willReturn($this->card(11));

		$result = $this->service->apply([11], BulkCardService::ACTION_MOVE, ['targetStackId' => 6], 'alice');
		self::assertSame([11], $result['ok']);
	}

	public function testBulkAddLabelCallsLabelServiceAssignPerCard(): void {
		$assigned = [];
		$this->labelService->expects(self::exactly(2))
			->method('assign')
			->willReturnCallback(function (int $cardId, int $labelId, string $uid) use (&$assigned): void {
				self::assertSame(7, $labelId);
				self::assertSame('alice', $uid);
				$assigned[] = $cardId;
			});

		$result = $this->service->apply([11, 12], BulkCardService::ACTION_ADD_LABEL, ['labelId' => 7], 'alice');
		self::assertSame([11, 12], $result['ok']);
		self::assertSame([11, 12], $assigned);
	}

	public function testBulkRemoveLabelCallsLabelServiceUnassignPerCard(): void {
		$this->labelService->expects(self::exactly(2))->method('unassign')
			->willReturnCallback(fn (int $c, int $l, string $u) => self::assertSame(7, $l));

		$result = $this->service->apply([11, 12], BulkCardService::ACTION_REMOVE_LABEL, ['labelId' => 7], 'alice');
		self::assertSame([11, 12], $result['ok']);
	}

	public function testBulkAssignUserCallsAssigneeServicePerCard(): void {
		$this->assigneeService->expects(self::exactly(2))->method('assign')
			->willReturnCallback(function (int $cardId, string $participant, string $actor): void {
				self::assertSame('bob', $participant);
				self::assertSame('alice', $actor);
			});

		$result = $this->service->apply([11, 12], BulkCardService::ACTION_ASSIGN_USER, ['userId' => 'bob'], 'alice');
		self::assertSame([11, 12], $result['ok']);
	}

	public function testBulkSetDueDatePassesDuedateThroughUpdate(): void {
		$this->cardService->expects(self::exactly(2))->method('update')
			->willReturnCallback(function (int $id, $t, $d, $due, $done, $arch, string $uid) {
				self::assertNull($t);
				self::assertNull($d);
				self::assertSame('2026-08-05T18:00:00.000Z', $due);
				self::assertNull($done);
				self::assertNull($arch);
				self::assertSame('alice', $uid);
				return $this->card($id);
			});

		$result = $this->service->apply(
			[11, 12],
			BulkCardService::ACTION_SET_DUE_DATE,
			['duedate' => '2026-08-05T18:00:00.000Z'],
			'alice',
		);
		self::assertSame([11, 12], $result['ok']);
	}

	public function testBulkSetDueDateWithEmptyStringClearsDueDate(): void {
		$this->cardService->expects(self::once())->method('update')
			->willReturnCallback(function (int $id, $t, $d, $due, $done, $arch, string $uid) {
				self::assertSame('', $due); // '' clears the due date
				return $this->card($id);
			});

		$result = $this->service->apply([11], BulkCardService::ACTION_SET_DUE_DATE, ['duedate' => ''], 'alice');
		self::assertSame([11], $result['ok']);
	}

	public function testBulkArchiveCallsUpdateWithArchivedTrue(): void {
		$this->cardService->expects(self::exactly(2))->method('update')
			->willReturnCallback(function (int $id, $t, $d, $due, $done, $arch, string $uid) {
				self::assertTrue($arch);
				self::assertSame('alice', $uid);
				return $this->card($id);
			});

		$result = $this->service->apply([11, 12], BulkCardService::ACTION_ARCHIVE, [], 'alice');
		self::assertSame([11, 12], $result['ok']);
	}

	public function testBulkDeleteCallsCardServiceDeletePerCard(): void {
		$deleted = [];
		$this->cardService->expects(self::exactly(2))->method('delete')
			->willReturnCallback(function (int $id, string $uid) use (&$deleted): void {
				self::assertSame('alice', $uid);
				$deleted[] = $id;
			});

		$result = $this->service->apply([11, 12], BulkCardService::ACTION_DELETE, [], 'alice');
		self::assertSame([11, 12], $result['ok']);
		self::assertSame([11, 12], $deleted);
	}

	// ── Permission denial: a card on a board the caller can't edit is skipped ────

	public function testForbiddenCardIsSkippedNotFatalForTheWholeRequest(): void {
		// Card 12 lives on a board alice cannot edit → its service throws
		// NotPermittedException. The other two still apply.
		$this->cardService->method('delete')
			->willReturnCallback(function (int $id, string $uid): void {
				if ($id === 12) {
					throw new NotPermittedException('nope');
				}
			});

		$result = $this->service->apply([11, 12, 13], BulkCardService::ACTION_DELETE, [], 'alice');

		self::assertSame([11, 13], $result['ok']);
		self::assertSame([['id' => 12, 'reason' => 'forbidden']], $result['skipped']);
	}

	public function testMissingCardIsSkippedAsNotFound(): void {
		$this->cardService->method('delete')
			->willReturnCallback(function (int $id, string $uid): void {
				if ($id === 12) {
					throw new DoesNotExistException('gone');
				}
			});

		$result = $this->service->apply([11, 12], BulkCardService::ACTION_DELETE, [], 'alice');

		self::assertSame([11], $result['ok']);
		self::assertSame([['id' => 12, 'reason' => 'not_found']], $result['skipped']);
	}

	public function testPerCardInvalidInputIsSkippedWithItsMessage(): void {
		// e.g. a label from another board: LabelService::assign throws
		// InvalidInputException for that one card only.
		$this->labelService->method('assign')
			->willReturnCallback(function (int $cardId, int $labelId, string $uid): void {
				if ($cardId === 12) {
					throw new InvalidInputException('Cannot assign a label from another board');
				}
			});

		$result = $this->service->apply([11, 12], BulkCardService::ACTION_ADD_LABEL, ['labelId' => 7], 'alice');

		self::assertSame([11], $result['ok']);
		self::assertSame(
			[['id' => 12, 'reason' => 'Cannot assign a label from another board']],
			$result['skipped'],
		);
	}

	public function testMoveOverflowIsSkippedAsRebalanceRequired(): void {
		$this->cardMapper->method('findLastInStack')->willReturn(null);
		$this->cardService->method('move')
			->willReturnCallback(function (int $id, int $stack, ?int $after, string $uid): Card {
				if ($id === 12) {
					throw new \OverflowException('rebalance');
				}
				return $this->card($id);
			});

		$result = $this->service->apply([11, 12], BulkCardService::ACTION_MOVE, ['targetStackId' => 6], 'alice');

		self::assertSame([11], $result['ok']);
		self::assertSame([['id' => 12, 'reason' => 'rebalance_required']], $result['skipped']);
	}

	// ── Whole-request validation (empty / oversized / bad action / bad params) ──

	public function testEmptyListThrowsInvalidInput(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->apply([], BulkCardService::ACTION_ARCHIVE, [], 'alice');
	}

	public function testListOfOnlyInvalidIdsThrowsInvalidInput(): void {
		// Zero / negative ids are filtered out, leaving an empty selection → 400.
		$this->expectException(InvalidInputException::class);
		$this->service->apply([0, -3], BulkCardService::ACTION_ARCHIVE, [], 'alice');
	}

	public function testOversizedListThrowsInvalidInput(): void {
		$ids = range(1, BulkCardService::MAX_CARDS + 1);
		$this->expectException(InvalidInputException::class);
		$this->service->apply($ids, BulkCardService::ACTION_ARCHIVE, [], 'alice');
	}

	public function testUnknownActionThrowsInvalidInput(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->apply([11], 'nuke_everything', [], 'alice');
	}

	public function testMoveWithoutTargetStackThrowsInvalidInput(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->apply([11], BulkCardService::ACTION_MOVE, [], 'alice');
	}

	public function testAddLabelWithoutLabelThrowsInvalidInput(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->apply([11], BulkCardService::ACTION_ADD_LABEL, [], 'alice');
	}

	public function testAssignUserWithoutUserThrowsInvalidInput(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->apply([11], BulkCardService::ACTION_ASSIGN_USER, ['userId' => '  '], 'alice');
	}

	public function testDuplicateIdsAreAppliedOnce(): void {
		$this->cardService->expects(self::once())->method('delete')->with(11, 'alice');

		$result = $this->service->apply([11, 11, 11], BulkCardService::ACTION_DELETE, [], 'alice');
		self::assertSame([11], $result['ok']);
	}
}
