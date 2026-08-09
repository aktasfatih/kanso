<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\ArchiveRule;
use OCA\Kanso\Db\ArchiveRuleMapper;
use OCA\Kanso\Db\AutomationRule;
use OCA\Kanso\Db\AutomationRuleMapper;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardReview;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\ChecklistItem;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\Comment;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\RecurRule;
use OCA\Kanso\Db\RecurRuleMapper;
use OCA\Kanso\Db\ReviewType;
use OCA\Kanso\Db\ReviewTypeMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\ExportService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ExportServiceTest extends TestCase {
	private StackMapper&MockObject $stackMapper;
	private CardMapper&MockObject $cardMapper;
	private LabelMapper&MockObject $labelMapper;
	private CardLabelMapper&MockObject $cardLabelMapper;
	private CardAssigneeMapper&MockObject $cardAssigneeMapper;
	private ChecklistItemMapper&MockObject $checklistItemMapper;
	private CommentMapper&MockObject $commentMapper;
	private ArchiveRuleMapper&MockObject $archiveRuleMapper;
	private RecurRuleMapper&MockObject $recurRuleMapper;
	private ReviewTypeMapper&MockObject $reviewTypeMapper;
	private CardReviewMapper&MockObject $cardReviewMapper;
	private AutomationRuleMapper&MockObject $automationRuleMapper;
	private ExportService $service;
	private ViewerContext $viewer;

	protected function setUp(): void {
		parent::setUp();
		$this->stackMapper = $this->createMock(StackMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->labelMapper = $this->createMock(LabelMapper::class);
		$this->cardLabelMapper = $this->createMock(CardLabelMapper::class);
		$this->cardAssigneeMapper = $this->createMock(CardAssigneeMapper::class);
		$this->checklistItemMapper = $this->createMock(ChecklistItemMapper::class);
		$this->commentMapper = $this->createMock(CommentMapper::class);
		$this->archiveRuleMapper = $this->createMock(ArchiveRuleMapper::class);
		$this->recurRuleMapper = $this->createMock(RecurRuleMapper::class);
		$this->reviewTypeMapper = $this->createMock(ReviewTypeMapper::class);
		$this->cardReviewMapper = $this->createMock(CardReviewMapper::class);
		$this->automationRuleMapper = $this->createMock(AutomationRuleMapper::class);
		$this->service = new ExportService(
			$this->stackMapper,
			$this->cardMapper,
			$this->labelMapper,
			$this->cardLabelMapper,
			$this->cardAssigneeMapper,
			$this->checklistItemMapper,
			$this->commentMapper,
			$this->archiveRuleMapper,
			$this->recurRuleMapper,
			$this->reviewTypeMapper,
			$this->cardReviewMapper,
			$this->automationRuleMapper,
		);
		// The export is viewer-scoped (#3743): only cards this viewer can see ride along.
		$this->viewer = ViewerContext::forMember('alice', 7, ViewerContext::ROLE_INTERNAL, true);
	}

	private function board(): Board {
		$b = new Board();
		$b->setId(7);
		$b->setTitle('Roadmap');
		$b->setColor('0082c9');
		$b->setOwner('alice');
		$b->setArchived(false);
		$b->setEstimateScale('tshirt');
		$b->setNewCardsOnTop(true);
		return $b;
	}

	public function testExportSerializesEveryEntityType(): void {
		$stack = new Stack();
		$stack->setId(11);
		$stack->setBoardId(7);
		$stack->setTitle('Doing');
		$stack->setSortKey('m');
		$stack->setArchived(false);
		$stack->setRole(Stack::ROLE_IN_PROGRESS);
		$stack->setWipLimit(3);
		$stack->setColor('ff0000');
		$this->stackMapper->method('findByBoard')->with(7)->willReturn([$stack]);

		$label = new Label();
		$label->setId(21);
		$label->setBoardId(7);
		$label->setTitle('Bug');
		$label->setColor('e11');
		$this->labelMapper->method('findByBoard')->with(7)->willReturn([$label]);

		$type = new ReviewType();
		$type->setId(31);
		$type->setBoardId(7);
		$type->setTitle('QA');
		$type->setColor('00f');
		$this->reviewTypeMapper->method('findByBoard')->with(7)->willReturn([$type]);

		$full = new Card();
		$full->setId(41);
		$full->setBoardId(7);
		$full->setStackId(11);
		$full->setTitle('Fix login');
		$full->setDescription('the long description');
		$full->setSortKey('h');
		$full->setDuedate((new \DateTime())->setTimestamp(1000));
		$full->setStartDate(null);
		$full->setDoneAt(0);
		$full->setStartedAt(0);
		$full->setArchived(false);
		$full->setAllDay(false);
		$full->setOwner('bob');
		$full->setCreatedAt(500);
		$full->setLastModified(600);
		$full->setParentCardId(null);
		$full->setPriority(4);
		$full->setEstimate('M');
		// ONE viewer-scoped full-row query replaces the per-stack + per-card reads.
		$this->cardMapper->expects(self::once())
			->method('findExportableByBoard')->with(7, $this->viewer)->willReturn([$full]);

		$this->cardLabelMapper->method('findLabelIdsByCard')->with(41)->willReturn([21]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->with(41)->willReturn(['bob']);

		$item = new ChecklistItem();
		$item->setId(51);
		$item->setCardId(41);
		$item->setTitle('write test');
		$item->setDone(true);
		$item->setSortKey('a');
		$item->setCreatedAt(510);
		$this->checklistItemMapper->method('findByCard')->with(41)->willReturn([$item]);

		$comment = new Comment();
		$comment->setId(61);
		$comment->setCardId(41);
		$comment->setParentCommentId(null);
		$comment->setAuthor('carol');
		$comment->setBody('looks good');
		$comment->setCreatedAt(520);
		$comment->setEditedAt(0);
		$this->commentMapper->method('findByCard')->with(41)->willReturn([$comment]);

		$review = new CardReview();
		$review->setId(71);
		$review->setCardId(41);
		$review->setReviewer('dave');
		$review->setState(CardReview::STATE_PENDING);
		$review->setRequestedBy('bob');
		$review->setCreatedAt(530);
		$review->setReviewTypeId(31);
		$this->cardReviewMapper->method('findByCard')->with(41)->willReturn([$review]);

		$archiveRule = new ArchiveRule();
		$archiveRule->setId(81);
		$archiveRule->setBoardId(7);
		$archiveRule->setStackId(11);
		$archiveRule->setCondition(ArchiveRule::CONDITION_DONE_FOR);
		$archiveRule->setThresholdSeconds(86400);
		$archiveRule->setEnabled(true);
		$archiveRule->setCreatedAt(540);
		$this->archiveRuleMapper->method('findByBoard')->with(7)->willReturn([$archiveRule]);

		$recurRule = new RecurRule();
		$recurRule->setId(91);
		$recurRule->setBoardId(7);
		$recurRule->setTemplateCardId(41);
		$recurRule->setTargetStackId(11);
		$recurRule->setMode(RecurRule::MODE_CLONE);
		$recurRule->setRrule('FREQ=WEEKLY');
		$recurRule->setDuedatePolicy(RecurRule::POLICY_AT_OCCURRENCE);
		$recurRule->setDuedateOffsetSeconds(0);
		$recurRule->setSkipWhileOpen(false);
		$recurRule->setEnabled(true);
		$recurRule->setOwner('alice');
		$recurRule->setLastSpawnedAt(0);
		$recurRule->setNextOccurrenceAt(2000);
		$recurRule->setOccurrencesSpawned(0);
		$recurRule->setCreatedAt(550);
		$recurRule->setTimezone('Europe/Berlin');
		$this->recurRuleMapper->method('findByBoard')->with(7)->willReturn([$recurRule]);

		$autoRule = new AutomationRule();
		$autoRule->setId(101);
		$autoRule->setBoardId(7);
		$autoRule->setTrigger(AutomationRule::TRIGGER_CARD_ENTERED_ROLE);
		$autoRule->setAction(AutomationRule::ACTION_ADD_LABEL);
		$autoRule->setParams(json_encode(['role' => Stack::ROLE_IN_PROGRESS, 'label' => 21]));
		$autoRule->setEnabled(true);
		$autoRule->setCreatedAt(560);
		$this->automationRuleMapper->method('findByBoard')->with(7)->willReturn([$autoRule]);

		$doc = $this->service->export($this->board(), $this->viewer);

		self::assertSame(ExportService::FORMAT_VERSION, $doc['kanso']);
		self::assertIsInt($doc['exportedAt']);

		$board = $doc['board'];
		self::assertSame('Roadmap', $board['title']);
		self::assertSame('0082c9', $board['color']);
		self::assertSame('tshirt', $board['estimateScale']);
		self::assertTrue($board['newCardsOnTop']);

		self::assertCount(1, $board['stacks']);
		self::assertSame(['id' => 11, 'title' => 'Doing', 'sortKey' => 'm', 'archived' => false, 'role' => Stack::ROLE_IN_PROGRESS, 'wipLimit' => 3, 'color' => 'ff0000'], $board['stacks'][0]);

		self::assertSame([['id' => 21, 'title' => 'Bug', 'color' => 'e11']], $board['labels']);
		self::assertSame([['id' => 31, 'title' => 'QA', 'color' => '00f']], $board['reviewTypes']);

		self::assertCount(1, $board['cards']);
		$card = $board['cards'][0];
		self::assertSame(41, $card['id']);
		self::assertSame(11, $card['stackId']);
		self::assertSame('the long description', $card['description']);
		self::assertSame(1000, $card['duedate']);
		self::assertSame(4, $card['priority']);
		self::assertSame([21], $card['labelIds']);
		self::assertSame(['bob'], $card['assignees']);
		// Visibility rides along (#3741/#3743); unset columns read as the defaults.
		self::assertSame('public', $card['visibility']);
		self::assertSame('internal', $card['creatorRole']);
		self::assertSame([['title' => 'write test', 'done' => true, 'sortKey' => 'a', 'createdAt' => 510]], $card['checklist']);
		self::assertSame('looks good', $card['comments'][0]['body']);
		self::assertSame('carol', $card['comments'][0]['author']);
		self::assertSame('dave', $card['reviews'][0]['reviewer']);
		self::assertSame(31, $card['reviews'][0]['reviewTypeId']);

		self::assertSame(11, $board['archiveRules'][0]['stackId']);
		self::assertSame(86400, $board['archiveRules'][0]['thresholdSeconds']);
		self::assertSame('FREQ=WEEKLY', $board['recurRules'][0]['rrule']);
		self::assertSame(41, $board['recurRules'][0]['templateCardId']);
		self::assertSame('Europe/Berlin', $board['recurRules'][0]['timezone']);

		self::assertCount(1, $board['automationRules']);
		self::assertSame(AutomationRule::ACTION_ADD_LABEL, $board['automationRules'][0]['action']);
		self::assertSame(['role' => Stack::ROLE_IN_PROGRESS, 'label' => 21], $board['automationRules'][0]['params']);
		self::assertTrue($board['automationRules'][0]['enabled']);
	}

	public function testExportEmptyBoardIsWellFormed(): void {
		$this->stackMapper->method('findByBoard')->willReturn([]);
		$this->labelMapper->method('findByBoard')->willReturn([]);
		$this->reviewTypeMapper->method('findByBoard')->willReturn([]);
		$this->archiveRuleMapper->method('findByBoard')->willReturn([]);
		$this->recurRuleMapper->method('findByBoard')->willReturn([]);
		$this->automationRuleMapper->method('findByBoard')->willReturn([]);

		$doc = $this->service->export($this->board(), $this->viewer);

		self::assertSame([], $doc['board']['stacks']);
		self::assertSame([], $doc['board']['cards']);
		self::assertSame([], $doc['board']['labels']);
		self::assertSame([], $doc['board']['automationRules']);
	}

	public function testExportCardsCarryVisibilityAndOnlyExportableRows(): void {
		// The card walk is EXACTLY findExportableByBoard's viewer-scoped result -
		// no per-stack or per-card reads that could resurrect a hidden card - and
		// each row round-trips its visibility/creatorRole so an import can never
		// silently widen the card back to 'public'.
		$stack = new Stack();
		$stack->setId(11);
		$stack->setBoardId(7);
		$stack->setTitle('Doing');
		$stack->setSortKey('m');
		$stack->setArchived(false);
		$this->stackMapper->method('findByBoard')->with(7)->willReturn([$stack]);

		$card = new Card();
		$card->setId(41);
		$card->setBoardId(7);
		$card->setStackId(11);
		$card->setTitle('Client-side task');
		$card->setSortKey('h');
		$card->setDoneAt(0);
		$card->setStartedAt(0);
		$card->setArchived(false);
		$card->setCreatedAt(500);
		$card->setLastModified(600);
		$card->setPriority(0);
		$card->setVisibility('private');
		$card->setCreatorRole('external');
		$this->cardMapper->expects(self::once())
			->method('findExportableByBoard')->with(7, $this->viewer)->willReturn([$card]);
		$this->cardMapper->expects(self::never())->method('findByStack');
		$this->cardMapper->expects(self::never())->method('find');

		$doc = $this->service->export($this->board(), $this->viewer);

		self::assertCount(1, $doc['board']['cards']);
		$row = $doc['board']['cards'][0];
		self::assertSame(41, $row['id']);
		self::assertSame('private', $row['visibility']);
		self::assertSame('external', $row['creatorRole']);
	}
}
