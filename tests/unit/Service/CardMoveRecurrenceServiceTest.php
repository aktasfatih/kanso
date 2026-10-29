<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\RecurRule;
use OCA\Kanso\Db\RecurRuleMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\CardMoveRecurrenceService;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CardMoveRecurrenceServiceTest extends TestCase {
	private RecurRuleMapper&MockObject $rules;
	private StackMapper&MockObject $stacks;
	private PermissionService&MockObject $permissions;
	private CardVisibilityGuard&MockObject $visibility;
	private CardMoveRecurrenceService $service;
	private Board $sourceBoard;
	private Board $targetBoard;

	protected function setUp(): void {
		$this->rules = $this->createMock(RecurRuleMapper::class);
		$this->stacks = $this->createMock(StackMapper::class);
		$this->permissions = $this->createMock(PermissionService::class);
		$this->visibility = $this->createMock(CardVisibilityGuard::class);
		$this->permissions->method('getPermissions')->willReturn(PermissionService::PERMISSION_ALL);
		$this->visibility->method('isVisible')->willReturn(true);
		$this->sourceBoard = new Board();
		$this->sourceBoard->setId(1);
		$this->targetBoard = new Board();
		$this->targetBoard->setId(2);
		$this->service = new CardMoveRecurrenceService($this->rules, $this->stacks, $this->permissions, $this->visibility);
	}

	private function card(int $id, int $stackId): Card {
		$card = new Card();
		$card->setId($id);
		$card->setStackId($stackId);
		return $card;
	}

	private function rule(int $templateId = 10, int $stackId = 4, int $lastId = 0): RecurRule {
		$rule = new RecurRule();
		$rule->setId(7);
		$rule->setBoardId(1);
		$rule->setTemplateCardId($templateId);
		$rule->setTargetStackId($stackId);
		$rule->setOwner('owner');
		$rule->setLastSpawnedAt($lastId);
		$rule->setNextOccurrenceAt(1700000123);
		$rule->setOccurrencesSpawned(5);
		$rule->setRrule('FREQ=DAILY');
		$rule->setTimezone('America/Toronto');
		$rule->setEnabled(true);
		return $rule;
	}

	private function stack(int $id, int $boardId, string $name, int $role = 0): Stack {
		$stack = new Stack();
		$stack->setId($id);
		$stack->setBoardId($boardId);
		$stack->setTitle($name);
		$stack->setRole($role);
		$stack->setDeletedAt(0);
		return $stack;
	}

	public function testTransfersRuleAndRemapsPreviousOccurrenceWithoutResettingSchedule(): void {
		$rule = $this->rule(lastId: 11);
		$this->rules->method('findByBoard')->with(1)->willReturn([$rule]);
		$this->permissions->expects(self::exactly(2))->method('assertPermission');
		$this->rules->expects(self::once())->method('update')->with(self::callback(static function (RecurRule $updated): bool {
			self::assertSame(2, $updated->getBoardId());
			self::assertSame(20, $updated->getTemplateCardId());
			self::assertSame(30, $updated->getTargetStackId());
			self::assertSame(21, $updated->getLastSpawnedAt());
			self::assertSame(1700000123, $updated->getNextOccurrenceAt());
			self::assertSame(5, $updated->getOccurrencesSpawned());
			self::assertSame('FREQ=DAILY', $updated->getRrule());
			self::assertSame('America/Toronto', $updated->getTimezone());
			self::assertSame('owner', $updated->getOwner());
			return true;
		}))->willReturn($rule);
		$this->service->transfer([$this->card(10, 4), $this->card(11, 4)], [10 => $this->card(20, 30), 11 => $this->card(21, 30)], $this->sourceBoard, $this->targetBoard, 30, 'mover');
	}

	public function testTransferMapsSeparateTargetColumnByNameAndRole(): void {
		$rule = $this->rule(stackId: 8);
		$this->rules->method('findByBoard')->willReturn([$rule]);
		$this->stacks->method('find')->with(8)->willReturn($this->stack(8, 1, 'Upcoming', 2));
		$this->stacks->method('findByBoard')->with(2)->willReturn([$this->stack(31, 2, 'Upcoming', 2)]);
		$this->rules->expects(self::once())->method('update')->willReturn($rule);
		$this->service->transfer([$this->card(10, 4)], [10 => $this->card(20, 30)], $this->sourceBoard, $this->targetBoard, 30, 'mover');
		self::assertSame(31, $rule->getTargetStackId());
	}

	public function testRejectsSeparateTargetColumnWithoutUniqueMatch(): void {
		$rule = $this->rule(stackId: 8);
		$this->rules->method('findByBoard')->willReturn([$rule]);
		$this->stacks->method('find')->willReturn($this->stack(8, 1, 'Upcoming', 2));
		$this->stacks->method('findByBoard')->willReturn([$this->stack(31, 2, 'Upcoming', 2), $this->stack(32, 2, 'Upcoming', 2)]);
		$this->rules->expects(self::never())->method('update');
		$this->expectException(InvalidInputException::class);
		$this->service->transfer([$this->card(10, 4)], [10 => $this->card(20, 30)], $this->sourceBoard, $this->targetBoard, 30, 'mover');
	}

	public function testRejectsLeavingLastOccurrenceBehind(): void {
		$this->rules->method('findByBoard')->willReturn([$this->rule(lastId: 11)]);
		$this->rules->expects(self::never())->method('update');
		$this->expectException(InvalidInputException::class);
		$this->service->transfer([$this->card(10, 4)], [10 => $this->card(20, 30)], $this->sourceBoard, $this->targetBoard, 30, 'mover');
	}

	public function testRejectsMovingLastOccurrenceWithoutItsTemplate(): void {
		$this->rules->method('findByBoard')->willReturn([$this->rule(lastId: 11)]);
		$this->rules->expects(self::never())->method('update');
		$this->expectException(InvalidInputException::class);
		$this->service->transfer([$this->card(11, 4)], [11 => $this->card(21, 30)], $this->sourceBoard, $this->targetBoard, 30, 'mover');
	}

	public function testRejectsDestinationInaccessibleToRuleOwner(): void {
		$this->rules->method('findByBoard')->willReturn([$this->rule()]);
		$this->visibility = $this->createMock(CardVisibilityGuard::class);
		$this->visibility->method('isVisible')->willReturn(false);
		$this->service = new CardMoveRecurrenceService($this->rules, $this->stacks, $this->permissions, $this->visibility);
		$this->rules->expects(self::never())->method('update');
		$this->expectException(InvalidInputException::class);
		$this->service->transfer([$this->card(10, 4)], [10 => $this->card(20, 30)], $this->sourceBoard, $this->targetBoard, 30, 'mover');
	}

	public function testRejectsLastOccurrenceHiddenFromRuleOwner(): void {
		$this->rules->method('findByBoard')->willReturn([$this->rule(lastId: 11)]);
		$this->visibility = $this->createMock(CardVisibilityGuard::class);
		$this->visibility->method('isVisible')->willReturnCallback(static fn (Board $board, Card $card): bool => $card->getId() === 20);
		$this->service = new CardMoveRecurrenceService($this->rules, $this->stacks, $this->permissions, $this->visibility);
		$this->rules->expects(self::never())->method('update');
		$this->expectException(InvalidInputException::class);
		$this->service->transfer([$this->card(10, 4), $this->card(11, 4)], [10 => $this->card(20, 30), 11 => $this->card(21, 30)], $this->sourceBoard, $this->targetBoard, 30, 'mover');
	}

	public function testRequiresManagePermissionToMoveRule(): void {
		$this->rules->method('findByBoard')->willReturn([$this->rule()]);
		$this->permissions->method('assertPermission')->willThrowException(new NotPermittedException());
		$this->rules->expects(self::never())->method('update');
		$this->expectException(NotPermittedException::class);
		$this->service->transfer([$this->card(10, 4)], [10 => $this->card(20, 30)], $this->sourceBoard, $this->targetBoard, 30, 'mover');
	}

	public function testUnrelatedRuleDoesNotRequireManageOrChange(): void {
		$this->rules->method('findByBoard')->willReturn([$this->rule(templateId: 55)]);
		$this->permissions->expects(self::never())->method('assertPermission');
		$this->rules->expects(self::never())->method('update');
		$this->service->transfer([$this->card(10, 4)], [10 => $this->card(20, 30)], $this->sourceBoard, $this->targetBoard, 30, 'mover');
	}
}
