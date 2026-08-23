<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\AutomationRule;
use OCA\Kanso\Db\AutomationRuleMapper;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardRunningTimer;
use OCA\Kanso\Db\CardRunningTimerMapper;
use OCA\Kanso\Db\CardTimeEntry;
use OCA\Kanso\Db\CardTimeEntryMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Service\AutomationService;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\ReviewService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AutomationServiceTest extends TestCase {
	private const BOARD_ID = 7;
	private const ACTOR = 'alice';

	private AutomationRuleMapper&MockObject $ruleMapper;
	private BoardMapper&MockObject $boardMapper;
	private PermissionService&MockObject $permissionService;
	private ReviewService&MockObject $reviewService;
	private LabelMapper&MockObject $labelMapper;
	private CardLabelMapper&MockObject $cardLabelMapper;
	private ChangeNotifier&MockObject $changeNotifier;
	private IDBConnection&MockObject $db;
	private CardRunningTimerMapper&MockObject $runningTimerMapper;
	private CardTimeEntryMapper&MockObject $timeEntryMapper;
	private ITimeFactory&MockObject $timeFactory;
	private AutomationService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->ruleMapper = $this->createMock(AutomationRuleMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->reviewService = $this->createMock(ReviewService::class);
		$this->labelMapper = $this->createMock(LabelMapper::class);
		$this->cardLabelMapper = $this->createMock(CardLabelMapper::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->runningTimerMapper = $this->createMock(CardRunningTimerMapper::class);
		$this->timeEntryMapper = $this->createMock(CardTimeEntryMapper::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->service = new AutomationService(
			$this->ruleMapper,
			$this->boardMapper,
			$this->permissionService,
			$this->reviewService,
			$this->labelMapper,
			$this->cardLabelMapper,
			$this->changeNotifier,
			$this->db,
			$this->runningTimerMapper,
			$this->timeEntryMapper,
			$this->timeFactory,
		);
	}

	private function board(int $deletedAt = 0): Board {
		$board = new Board();
		$board->setId(self::BOARD_ID);
		$board->setDeletedAt($deletedAt);
		return $board;
	}

	private function card(): Card {
		$card = new Card();
		$card->setId(99);
		$card->setBoardId(self::BOARD_ID);
		return $card;
	}

	private function rule(string $action, array $params): AutomationRule {
		$rule = new AutomationRule();
		$rule->setId(1);
		$rule->setBoardId(self::BOARD_ID);
		$rule->setTrigger(AutomationRule::TRIGGER_CARD_ENTERED_ROLE);
		$rule->setAction($action);
		$rule->setParams(json_encode($params));
		$rule->setEnabled(true);
		return $rule;
	}

	public function testListRulesRequiresManage(): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$this->permissionService->expects($this->once())
			->method('assertPermission')
			->with($this->anything(), self::ACTOR, PermissionService::PERMISSION_MANAGE)
			->willThrowException(new NotPermittedException('nope'));

		$this->expectException(NotPermittedException::class);
		$this->service->listRules(self::BOARD_ID, self::ACTOR);
	}

	public function testListRulesOnDeletedBoardThrows(): void {
		$this->boardMapper->method('find')->willReturn($this->board(deletedAt: 123));
		$this->expectException(DoesNotExistException::class);
		$this->service->listRules(self::BOARD_ID, self::ACTOR);
	}

	public function testCreateAddLabelRuleNormalizesParams(): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$label = new Label();
		$label->setId(55);
		$label->setBoardId(self::BOARD_ID);
		$this->labelMapper->method('find')->with(55)->willReturn($label);
		$this->ruleMapper->expects($this->once())
			->method('insert')
			->willReturnArgument(0);

		$rule = $this->service->createRule(
			self::BOARD_ID,
			AutomationRule::TRIGGER_CARD_ENTERED_ROLE,
			AutomationRule::ACTION_ADD_LABEL,
			['role' => Stack::ROLE_REVIEW, 'label' => 55, 'ignored' => 'x'],
			self::ACTOR,
		);

		$this->assertSame(['role' => Stack::ROLE_REVIEW, 'label' => 55], $rule->paramsArray());
	}

	public function testCreateRejectsUnknownTrigger(): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$this->expectException(InvalidInputException::class);
		$this->service->createRule(self::BOARD_ID, 'card_left_role', AutomationRule::ACTION_ADD_LABEL, ['role' => 2, 'label' => 1], self::ACTOR);
	}

	public function testCreateRejectsUnknownAction(): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$this->expectException(InvalidInputException::class);
		$this->service->createRule(self::BOARD_ID, AutomationRule::TRIGGER_CARD_ENTERED_ROLE, 'move_card', ['role' => 2], self::ACTOR);
	}

	public function testCreateRequestReviewWithoutReviewerThrows(): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$this->expectException(InvalidInputException::class);
		$this->service->createRule(self::BOARD_ID, AutomationRule::TRIGGER_CARD_ENTERED_ROLE, AutomationRule::ACTION_REQUEST_REVIEW, ['role' => Stack::ROLE_REVIEW], self::ACTOR);
	}

	public function testCreateRejectsInvalidRole(): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$this->expectException(InvalidInputException::class);
		$this->service->createRule(self::BOARD_ID, AutomationRule::TRIGGER_CARD_ENTERED_ROLE, AutomationRule::ACTION_REQUEST_REVIEW, ['role' => 99, 'reviewer' => 'bob'], self::ACTOR);
	}

	public function testCreateRejectsLabelFromAnotherBoard(): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$label = new Label();
		$label->setId(55);
		$label->setBoardId(self::BOARD_ID + 1);
		$this->labelMapper->method('find')->willReturn($label);
		$this->expectException(InvalidInputException::class);
		$this->service->createRule(self::BOARD_ID, AutomationRule::TRIGGER_CARD_ENTERED_ROLE, AutomationRule::ACTION_ADD_LABEL, ['role' => 2, 'label' => 55], self::ACTOR);
	}

	public function testDeleteRuleRequiresManage(): void {
		$rule = $this->rule(AutomationRule::ACTION_ADD_LABEL, ['role' => 2, 'label' => 1]);
		$this->ruleMapper->method('find')->with(1)->willReturn($rule);
		$this->boardMapper->method('find')->willReturn($this->board());
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException('nope'));
		$this->ruleMapper->expects($this->never())->method('delete');

		$this->expectException(NotPermittedException::class);
		$this->service->deleteRule(1, self::ACTOR);
	}

	public function testRunCardEnteredRoleIgnoresRoleNone(): void {
		$this->ruleMapper->expects($this->never())->method('findEnabledByBoardAndTrigger');
		$this->service->runCardEnteredRole($this->card(), Stack::ROLE_NONE, self::ACTOR);
	}

	public function testRunCardEnteredRoleFiresRequestReviewForMatchingRole(): void {
		$rule = $this->rule(AutomationRule::ACTION_REQUEST_REVIEW, ['role' => Stack::ROLE_REVIEW, 'reviewer' => 'bob']);
		$this->ruleMapper->method('findEnabledByBoardAndTrigger')
			->with(self::BOARD_ID, AutomationRule::TRIGGER_CARD_ENTERED_ROLE)
			->willReturn([$rule]);
		$this->reviewService->expects($this->once())
			->method('requestReview')
			->with(99, 'bob', self::ACTOR);

		$this->service->runCardEnteredRole($this->card(), Stack::ROLE_REVIEW, self::ACTOR);
	}

	public function testRunCardEnteredRoleSkipsNonMatchingRole(): void {
		$rule = $this->rule(AutomationRule::ACTION_REQUEST_REVIEW, ['role' => Stack::ROLE_DONE, 'reviewer' => 'bob']);
		$this->ruleMapper->method('findEnabledByBoardAndTrigger')->willReturn([$rule]);
		$this->reviewService->expects($this->never())->method('requestReview');

		$this->service->runCardEnteredRole($this->card(), Stack::ROLE_REVIEW, self::ACTOR);
	}

	public function testRunCardEnteredRoleAddsLabelWhenAbsent(): void {
		$rule = $this->rule(AutomationRule::ACTION_ADD_LABEL, ['role' => Stack::ROLE_IN_PROGRESS, 'label' => 55]);
		$this->ruleMapper->method('findEnabledByBoardAndTrigger')->willReturn([$rule]);
		$this->cardLabelMapper->method('exists')->with(99, 55)->willReturn(false);
		$this->cardLabelMapper->expects($this->once())->method('insertAssignment')->with(99, 55);
		// #3579: the label assignment + change row commit atomically; the push
		// fires after commit.
		$this->changeNotifier->expects($this->once())
			->method('recordChange')
			->with(self::BOARD_ID, Change::ENTITY_CARD, 99, Change::ACTION_UPDATE, self::ACTOR);
		$this->changeNotifier->expects($this->once())->method('pushBoardChanged')->with(self::BOARD_ID);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');

		$this->service->runCardEnteredRole($this->card(), Stack::ROLE_IN_PROGRESS, self::ACTOR);
	}

	public function testRunCardEnteredRoleSkipsLabelWhenAlreadyPresent(): void {
		$rule = $this->rule(AutomationRule::ACTION_ADD_LABEL, ['role' => Stack::ROLE_IN_PROGRESS, 'label' => 55]);
		$this->ruleMapper->method('findEnabledByBoardAndTrigger')->willReturn([$rule]);
		$this->cardLabelMapper->method('exists')->willReturn(true);
		$this->cardLabelMapper->expects($this->never())->method('insertAssignment');
		$this->changeNotifier->expects($this->never())->method('recordChange');

		$this->service->runCardEnteredRole($this->card(), Stack::ROLE_IN_PROGRESS, self::ACTOR);
	}

	public function testRunCardEnteredRoleSwallowsActionErrors(): void {
		$rule = $this->rule(AutomationRule::ACTION_REQUEST_REVIEW, ['role' => Stack::ROLE_REVIEW, 'reviewer' => 'bob']);
		$this->ruleMapper->method('findEnabledByBoardAndTrigger')->willReturn([$rule]);
		$this->reviewService->method('requestReview')
			->willThrowException(new InvalidInputException('reviewer has no access'));

		// A broken rule must not propagate out of the move.
		$this->service->runCardEnteredRole($this->card(), Stack::ROLE_REVIEW, self::ACTOR);
		$this->addToAssertionCount(1);
	}

	public function testCreateStartTimerRuleIsRoleOnly(): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$this->ruleMapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$rule = $this->service->createRule(
			self::BOARD_ID,
			AutomationRule::TRIGGER_CARD_ENTERED_ROLE,
			AutomationRule::ACTION_START_TIMER,
			['role' => Stack::ROLE_IN_PROGRESS, 'label' => 5, 'reviewer' => 'x'],
			self::ACTOR,
		);

		// Only the role is persisted - no reviewer/label params for timer actions.
		$this->assertSame(['role' => Stack::ROLE_IN_PROGRESS], $rule->paramsArray());
	}

	public function testStartTimerCreatesRunningTimerWhenNoneRunning(): void {
		$rule = $this->rule(AutomationRule::ACTION_START_TIMER, ['role' => Stack::ROLE_IN_PROGRESS]);
		$this->ruleMapper->method('findEnabledByBoardAndTrigger')->willReturn([$rule]);
		// No running timer yet.
		$this->runningTimerMapper->method('findByCard')->with(99)
			->willThrowException(new DoesNotExistException('none'));
		$this->timeFactory->method('getTime')->willReturn(1000);

		$this->runningTimerMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (CardRunningTimer $t): bool {
				return $t->getCardId() === 99
					&& $t->getBoardId() === self::BOARD_ID
					&& $t->getStartedBy() === self::ACTOR
					&& $t->getStartedAt() === 1000;
			}))
			->willReturnArgument(0);

		$this->service->runCardEnteredRole($this->card(), Stack::ROLE_IN_PROGRESS, self::ACTOR);
	}

	public function testStartTimerIsNoOpWhenAlreadyRunning(): void {
		$rule = $this->rule(AutomationRule::ACTION_START_TIMER, ['role' => Stack::ROLE_IN_PROGRESS]);
		$this->ruleMapper->method('findEnabledByBoardAndTrigger')->willReturn([$rule]);
		// A timer is already running.
		$this->runningTimerMapper->method('findByCard')->with(99)->willReturn(new CardRunningTimer());
		$this->runningTimerMapper->expects($this->never())->method('insert');

		$this->service->runCardEnteredRole($this->card(), Stack::ROLE_IN_PROGRESS, self::ACTOR);
	}

	public function testStopTimerPersistsElapsedEntryAndDropsRunningRow(): void {
		$rule = $this->rule(AutomationRule::ACTION_STOP_TIMER, ['role' => Stack::ROLE_DONE]);
		$this->ruleMapper->method('findEnabledByBoardAndTrigger')->willReturn([$rule]);

		$timer = new CardRunningTimer();
		$timer->setId(3);
		$timer->setCardId(99);
		$timer->setBoardId(self::BOARD_ID);
		$timer->setStartedAt(1000);
		$this->runningTimerMapper->method('findByCard')->with(99)->willReturn($timer);
		// 1000 -> 1090 = 90 elapsed seconds.
		$this->timeFactory->method('getTime')->willReturn(1090);

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->timeEntryMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (CardTimeEntry $e): bool {
				return $e->getCardId() === 99
					&& $e->getBoardId() === self::BOARD_ID
					&& $e->getSeconds() === 90
					&& $e->getNote() === 'Tracked automatically'
					&& $e->getCreatedBy() === self::ACTOR;
			}))
			->willReturnArgument(0);
		$this->runningTimerMapper->expects($this->once())->method('delete')->with($timer);
		$this->changeNotifier->expects($this->once())
			->method('recordChange')
			->with(self::BOARD_ID, Change::ENTITY_CARD, 99, Change::ACTION_UPDATE, self::ACTOR);
		$this->changeNotifier->expects($this->once())->method('pushBoardChanged')->with(self::BOARD_ID);

		$this->service->runCardEnteredRole($this->card(), Stack::ROLE_DONE, self::ACTOR);
	}

	public function testStopTimerIsNoOpWhenNoneRunning(): void {
		$rule = $this->rule(AutomationRule::ACTION_STOP_TIMER, ['role' => Stack::ROLE_DONE]);
		$this->ruleMapper->method('findEnabledByBoardAndTrigger')->willReturn([$rule]);
		$this->runningTimerMapper->method('findByCard')->with(99)
			->willThrowException(new DoesNotExistException('none'));

		$this->timeEntryMapper->expects($this->never())->method('insert');
		$this->runningTimerMapper->expects($this->never())->method('delete');
		$this->changeNotifier->expects($this->never())->method('recordChange');

		$this->service->runCardEnteredRole($this->card(), Stack::ROLE_DONE, self::ACTOR);
	}

	public function testStopTimerWithZeroElapsedPersistsNoEntry(): void {
		$rule = $this->rule(AutomationRule::ACTION_STOP_TIMER, ['role' => Stack::ROLE_DONE]);
		$this->ruleMapper->method('findEnabledByBoardAndTrigger')->willReturn([$rule]);

		$timer = new CardRunningTimer();
		$timer->setId(3);
		$timer->setCardId(99);
		$timer->setBoardId(self::BOARD_ID);
		$timer->setStartedAt(1000);
		$this->runningTimerMapper->method('findByCard')->with(99)->willReturn($timer);
		// Same instant -> 0 elapsed seconds.
		$this->timeFactory->method('getTime')->willReturn(1000);

		// No finished entry, no change row - but the running row is still dropped.
		$this->timeEntryMapper->expects($this->never())->method('insert');
		$this->changeNotifier->expects($this->never())->method('recordChange');
		$this->runningTimerMapper->expects($this->once())->method('delete')->with($timer);

		$this->service->runCardEnteredRole($this->card(), Stack::ROLE_DONE, self::ACTOR);
	}
}
