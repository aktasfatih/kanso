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
		self::assertArrayNotHasKey('description', $out[0]);

		// A card with no signal reads defaults (present, not absent).
		self::assertSame([], $out[1]['labelIds']);
		self::assertSame([], $out[1]['assigneeIds']);
		self::assertSame(['total' => 0, 'done' => 0], $out[1]['checklist']);
		self::assertFalse($out[1]['waitingOnExternal']);
		self::assertNull($out[1]['waitingSince']);
		self::assertFalse($out[1]['blocked']);
		self::assertFalse($out[1]['recurring']);
	}
}
