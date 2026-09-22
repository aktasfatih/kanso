<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardContactMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardRelationMapper;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\CardRunningTimerMapper;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\RecurRuleMapper;
use OCA\Kanso\Service\CardSummaryService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CardSummaryServiceTest extends TestCase {
	private CardLabelMapper&MockObject $cardLabelMapper;
	private CardAssigneeMapper&MockObject $cardAssigneeMapper;
	private CardContactMapper&MockObject $cardContactMapper;
	private ChecklistItemMapper&MockObject $checklistItemMapper;
	private CardMapper&MockObject $cardMapper;
	private CommentMapper&MockObject $commentMapper;
	private CardReviewMapper&MockObject $cardReviewMapper;
	private CardRelationMapper&MockObject $cardRelationMapper;
	private RecurRuleMapper&MockObject $recurRuleMapper;
	private CardRunningTimerMapper&MockObject $runningTimerMapper;
	private CardSummaryService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->cardLabelMapper = $this->createMock(CardLabelMapper::class);
		$this->cardAssigneeMapper = $this->createMock(CardAssigneeMapper::class);
		$this->cardContactMapper = $this->createMock(CardContactMapper::class);
		$this->checklistItemMapper = $this->createMock(ChecklistItemMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->commentMapper = $this->createMock(CommentMapper::class);
		$this->cardReviewMapper = $this->createMock(CardReviewMapper::class);
		$this->cardRelationMapper = $this->createMock(CardRelationMapper::class);
		$this->recurRuleMapper = $this->createMock(RecurRuleMapper::class);
		$this->runningTimerMapper = $this->createMock(CardRunningTimerMapper::class);
		$this->service = new CardSummaryService(
			$this->cardLabelMapper,
			$this->cardAssigneeMapper,
			$this->cardContactMapper,
			$this->checklistItemMapper,
			$this->cardMapper,
			$this->commentMapper,
			$this->cardReviewMapper,
			$this->cardRelationMapper,
			$this->recurRuleMapper,
			$this->runningTimerMapper,
		);
	}

	public function testSerializeEnrichesSummariesAndNeverLeaksDescription(): void {
		$card = new Card();
		$card->setId(3);
		$card->setBoardId(1);
		$card->setStackId(2);
		$card->setTitle('A card');
		$card->setDescription('must not leak into summaries');
		$bare = new Card();
		$bare->setId(4);
		$bare->setBoardId(1);
		$bare->setStackId(2);
		$bare->setTitle('No signal');

		$this->cardLabelMapper->method('findLabelIdsByBoard')->with(1)->willReturn([3 => [7]]);
		$this->cardAssigneeMapper->method('findUserIdsByBoard')->with(1)->willReturn([3 => ['bob']]);
		$this->checklistItemMapper->method('progressByBoard')->with(1)->willReturn([3 => ['total' => 4, 'done' => 1]]);
		$this->checklistItemMapper->method('waitingByBoard')->with(1)->willReturn([3 => 1700000000]);
		$this->cardMapper->method('childProgressByBoard')->with(1)->willReturn([3 => ['total' => 2, 'done' => 1]]);
		$this->commentMapper->method('countsByBoard')->with(1)->willReturn([3 => 5]);
		$this->cardRelationMapper->method('blockedCardIdsByBoard')->with(1)->willReturn([3]);
		// Card 3 has an enabled recurrence rule; card 4 does not.
		$this->recurRuleMapper->method('findTemplateCardIdsByBoard')->with(1)->willReturn([3]);
		// Card 3 has a running timer; card 4 does not.
		$this->runningTimerMapper->method('findCardIdsByBoard')->with(1)->willReturn([3]);

		$viewer = ViewerContext::forMember('alice', 1, ViewerContext::ROLE_INTERNAL, true);
		$out = $this->service->serialize(1, [$card, $bare], $viewer);

		self::assertCount(2, $out);
		self::assertSame(3, $out[0]['id']);
		self::assertSame([7], $out[0]['labelIds']);
		self::assertSame(['bob'], $out[0]['assigneeIds']);
		self::assertSame(['total' => 4, 'done' => 1], $out[0]['checklist']);
		self::assertTrue($out[0]['waitingOnExternal']);
		self::assertSame(1700000000, $out[0]['waitingSince']);
		self::assertTrue($out[0]['blocked']);
		self::assertTrue($out[0]['recurring']);
		self::assertTrue($out[0]['timerRunning']);
		self::assertArrayNotHasKey('description', $out[0]);

		// A card with no signal reads defaults (present, not absent).
		self::assertSame([], $out[1]['labelIds']);
		self::assertSame([], $out[1]['assigneeIds']);
		self::assertSame(['total' => 0, 'done' => 0], $out[1]['checklist']);
		self::assertFalse($out[1]['waitingOnExternal']);
		self::assertNull($out[1]['waitingSince']);
		self::assertFalse($out[1]['blocked']);
		self::assertFalse($out[1]['recurring']);
		self::assertFalse($out[1]['timerRunning']);
	}

	/**
	 * A card with SEVERAL assignees serializes all of them, in mapper order
	 * (#10603). The summary is what every board surface renders its avatar
	 * stack from, so a truncation here - `[0]`, a slice, a scalar - would hide
	 * the 2nd assignee everywhere at once while the card itself still had them.
	 */
	public function testSerializeCarriesEveryAssigneeOnACard(): void {
		$card = new Card();
		$card->setId(3);
		$card->setBoardId(1);
		$card->setStackId(2);
		$card->setTitle('Shared task');

		$this->cardAssigneeMapper->method('findUserIdsByBoard')
			->with(1)
			->willReturn([3 => ['bob', 'carol', 'dave']]);

		$viewer = ViewerContext::forMember('alice', 1, ViewerContext::ROLE_INTERNAL, true);
		$out = $this->service->serialize(1, [$card], $viewer);

		self::assertSame(['bob', 'carol', 'dave'], $out[0]['assigneeIds']);
	}

	/**
	 * The board-set path (#10298) must produce the SAME row shape as the
	 * board-scoped one - the cross-board Views feed and the board payload are
	 * required to be byte-identical per card, so a second assembler would be a
	 * silent drift. Both entry points are fed the identical enrichment data and
	 * the two outputs are compared whole, not field by field.
	 */
	public function testSerializeForBoardsProducesTheSameRowShapeAsTheBoardScopedPath(): void {
		$card = new Card();
		$card->setId(3);
		$card->setBoardId(1);
		$card->setStackId(2);
		$card->setTitle('A card');
		$card->setDescription('must not leak into summaries');

		$this->cardLabelMapper->method('findLabelIdsByBoard')->willReturn([3 => [7]]);
		$this->cardLabelMapper->method('findLabelIdsByBoards')->willReturn([3 => [7]]);
		$this->cardAssigneeMapper->method('findUserIdsByBoard')->willReturn([3 => ['bob']]);
		$this->cardAssigneeMapper->method('findUserIdsByBoards')->willReturn([3 => ['bob']]);
		$this->cardContactMapper->method('findContactsByBoard')->willReturn([]);
		$this->cardContactMapper->method('findContactsByBoards')->willReturn([]);
		$this->checklistItemMapper->method('progressByBoard')->willReturn([3 => ['total' => 4, 'done' => 1]]);
		$this->checklistItemMapper->method('progressByBoards')->willReturn([3 => ['total' => 4, 'done' => 1]]);
		$this->checklistItemMapper->method('waitingByBoard')->willReturn([3 => 1700000000]);
		$this->checklistItemMapper->method('waitingByBoards')->willReturn([3 => 1700000000]);
		$this->cardMapper->method('childProgressByBoard')->willReturn([3 => ['total' => 2, 'done' => 1]]);
		$this->cardMapper->method('childProgressByBoards')->willReturn([3 => ['total' => 2, 'done' => 1]]);
		$this->commentMapper->method('countsByBoard')->willReturn([3 => 5]);
		$this->commentMapper->method('countsByBoards')->willReturn([3 => 5]);
		$this->cardReviewMapper->method('reviewStatesByBoard')->willReturn([3 => 'pending']);
		$this->cardReviewMapper->method('reviewStatesByBoards')->willReturn([3 => 'pending']);
		$this->cardRelationMapper->method('blockedCardIdsByBoard')->willReturn([3]);
		$this->cardRelationMapper->method('blockedCardIdsByBoards')->willReturn([3]);
		$this->recurRuleMapper->method('findTemplateCardIdsByBoard')->willReturn([3]);
		$this->recurRuleMapper->method('findTemplateCardIdsByBoards')->willReturn([3]);
		$this->runningTimerMapper->method('findCardIdsByBoard')->willReturn([3]);
		$this->runningTimerMapper->method('findCardIdsByBoards')->willReturn([3]);

		$viewer = ViewerContext::forMember('alice', 1, ViewerContext::ROLE_INTERNAL, true);
		$boardScoped = $this->service->serialize(1, [$card], $viewer);
		$boardSet = $this->service->serializeForBoards([1], [$card], 'alice', [1 => ViewerContext::ROLE_INTERNAL]);

		self::assertSame($boardScoped, $boardSet);
		self::assertArrayNotHasKey('description', $boardSet[0]);
	}

	/**
	 * The batching contract itself (#10298): the enrichment is ONE query per
	 * map for the WHOLE board set, never one per board. The mapper calls are
	 * counted with a 3-board set - re-introducing a per-board loop would call
	 * each of these three times and fail here.
	 */
	public function testSerializeForBoardsIssuesOneEnrichmentQueryPerMapForTheWholeBoardSet(): void {
		$boardIds = [1, 2, 3];
		$roles = [
			1 => ViewerContext::ROLE_INTERNAL,
			2 => ViewerContext::ROLE_EXTERNAL,
			3 => ViewerContext::ROLE_INTERNAL,
		];
		$cards = [];
		foreach ($boardIds as $boardId) {
			$card = new Card();
			$card->setId(10 + $boardId);
			$card->setBoardId($boardId);
			$card->setStackId($boardId);
			$card->setTitle('card on ' . $boardId);
			$cards[] = $card;
		}

		// Each enrichment map is fetched EXACTLY once, and for the whole set.
		$this->cardLabelMapper->expects(self::once())->method('findLabelIdsByBoards')->with($boardIds)->willReturn([]);
		$this->cardAssigneeMapper->expects(self::once())->method('findUserIdsByBoards')->with($boardIds)->willReturn([]);
		$this->cardContactMapper->expects(self::once())->method('findContactsByBoards')->with($boardIds)->willReturn([]);
		$this->checklistItemMapper->expects(self::once())->method('progressByBoards')->with($boardIds, 'alice', $roles)->willReturn([]);
		$this->checklistItemMapper->expects(self::once())->method('waitingByBoards')->with($boardIds, 'alice', $roles)->willReturn([]);
		$this->cardMapper->expects(self::once())->method('childProgressByBoards')->with($boardIds, 'alice', $roles)->willReturn([]);
		$this->commentMapper->expects(self::once())->method('countsByBoards')->with($boardIds)->willReturn([]);
		$this->cardReviewMapper->expects(self::once())->method('reviewStatesByBoards')->with($boardIds)->willReturn([]);
		$this->cardRelationMapper->expects(self::once())->method('blockedCardIdsByBoards')->with($boardIds)->willReturn([]);
		$this->recurRuleMapper->expects(self::once())->method('findTemplateCardIdsByBoards')->with($boardIds)->willReturn([]);
		$this->runningTimerMapper->expects(self::once())->method('findCardIdsByBoards')->with($boardIds)->willReturn([]);
		// …and the board-SCOPED twins are never reached from this path.
		$this->cardLabelMapper->expects(self::never())->method('findLabelIdsByBoard');
		$this->checklistItemMapper->expects(self::never())->method('progressByBoard');
		$this->cardMapper->expects(self::never())->method('childProgressByBoard');

		$out = $this->service->serializeForBoards($boardIds, $cards, 'alice', $roles);

		self::assertSame([11, 12, 13], array_column($out, 'id'));
		self::assertSame([1, 2, 3], array_column($out, 'boardId'));
	}
}
