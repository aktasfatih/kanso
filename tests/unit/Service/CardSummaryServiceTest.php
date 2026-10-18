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
use OCA\Kanso\Service\CardVisibilityScope;
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
			new CardVisibilityScope(),
		);
	}

	/**
	 * One live blocks edge in the mapper's shape - public on both ends unless
	 * a test narrows one end to exercise the masking.
	 *
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private static function edge(int $from, int $to, array $overrides = []): array {
		return $overrides + [
			'boardId' => 1,
			'from' => $from,
			'to' => $to,
			'toDone' => false,
			'toArchived' => false,
			'fromVisibility' => 'public',
			'fromCreatorRole' => 'internal',
			'fromOwner' => 'carol',
			'toVisibility' => 'public',
			'toCreatorRole' => 'internal',
			'toOwner' => 'carol',
		];
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
		$this->checklistItemMapper->method('overdueByBoard')->willReturn([3 => 2]);
		$this->checklistItemMapper->method('progressByBoard')->with(1)->willReturn([3 => ['total' => 4, 'done' => 1]]);
		$this->checklistItemMapper->method('waitingByBoard')->with(1)->willReturn([3 => 1700000000]);
		$this->cardMapper->method('childProgressByBoard')->with(1)->willReturn([3 => ['total' => 2, 'done' => 1]]);
		$this->commentMapper->method('countsByBoard')->with(1)->willReturn([3 => 5]);
		// Card 9 (open, off-list) blocks card 3.
		$this->cardRelationMapper->method('liveBlocksEdgesByBoard')->with(1)->willReturn([self::edge(9, 3)]);
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
		// The overdue-step count (#10696) rides INSIDE the checklist shape, so the
		// tile tints the badge it already draws instead of growing a second one.
		self::assertSame(['total' => 4, 'done' => 1, 'overdue' => 2], $out[0]['checklist']);
		self::assertTrue($out[0]['waitingOnExternal']);
		self::assertSame(1700000000, $out[0]['waitingSince']);
		self::assertTrue($out[0]['blocked']);
		self::assertFalse($out[0]['blocking']);
		self::assertTrue($out[0]['recurring']);
		self::assertTrue($out[0]['timerRunning']);
		self::assertArrayNotHasKey('description', $out[0]);

		// A card with no signal reads defaults (present, not absent).
		self::assertSame([], $out[1]['labelIds']);
		self::assertSame([], $out[1]['assigneeIds']);
		self::assertSame(['total' => 0, 'done' => 0, 'overdue' => 0], $out[1]['checklist']);
		self::assertFalse($out[1]['waitingOnExternal']);
		self::assertNull($out[1]['waitingSince']);
		self::assertFalse($out[1]['blocked']);
		self::assertFalse($out[1]['blocking']);
		self::assertFalse($out[1]['recurring']);
		self::assertFalse($out[1]['timerRunning']);
	}

	/**
	 * `blocking` is the mirror of `blocked` off the SAME edge list (KANSO-330):
	 * the source of a live blocks edge is blocking while its target is open,
	 * the target is blocked either way (unchanged `blocked` semantics). A
	 * blocker whose target is already done holds nothing up, so it must NOT
	 * read blocking - that is the one case where the two badges are not a
	 * plain pair.
	 */
	public function testBlockingMirrorsBlockedAndIgnoresDoneTargets(): void {
		$cards = [];
		foreach ([3, 4, 5, 6, 7, 10] as $id) {
			$card = new Card();
			$card->setId($id);
			$card->setBoardId(1);
			$card->setStackId(2);
			$card->setTitle('card ' . $id);
			$cards[] = $card;
		}
		$this->cardRelationMapper->expects(self::once())->method('liveBlocksEdgesByBoard')->with(1)->willReturn([
			self::edge(3, 4),
			self::edge(5, 6, ['toDone' => true]),
			// An ARCHIVED (not done) target is off the board: nothing the viewer
			// can act on is held up, so 10 must not read blocking either.
			self::edge(10, 11, ['toArchived' => true]),
		]);

		$viewer = ViewerContext::forMember('alice', 1, ViewerContext::ROLE_INTERNAL, true);
		$out = array_column($this->service->serialize(1, $cards, $viewer), null, 'id');

		self::assertTrue($out[3]['blocking']);
		self::assertFalse($out[3]['blocked']);
		self::assertTrue($out[4]['blocked']);
		self::assertFalse($out[4]['blocking']);
		self::assertFalse($out[5]['blocking'], 'a blocker of a done card holds nothing up');
		self::assertFalse($out[5]['blocked']);
		self::assertTrue($out[6]['blocked']);
		self::assertFalse($out[6]['blocking']);
		// No edges at all: both present, both false.
		self::assertFalse($out[7]['blocked']);
		self::assertFalse($out[7]['blocking']);
		self::assertFalse($out[10]['blocking'], 'a blocker of an archived card holds nothing on the board up');
	}

	/**
	 * Whole-edge masking (#3743, KANSO-330 review): a badge counted off an edge
	 * to a card the viewer can not see is an existence oracle - "Blocking"
	 * would announce a restricted card, and flip when it is completed. So an
	 * edge counts only when BOTH ends are visible to the viewer.
	 *
	 * The viewer is an EXTERNAL member: 3 blocks an internal-only card 4
	 * (hidden), a hidden internal card 5 blocks 6, someone else's PRIVATE card
	 * 7 blocks 8, and the control pair 9 -> 10 is public on both ends.
	 */
	public function testBlocksBadgesIgnoreEdgesToCardsTheViewerCannotSee(): void {
		$cards = [];
		foreach ([3, 6, 8, 9, 10] as $id) {
			$card = new Card();
			$card->setId($id);
			$card->setBoardId(1);
			$card->setStackId(2);
			$card->setTitle('card ' . $id);
			$cards[] = $card;
		}
		$this->cardRelationMapper->method('liveBlocksEdgesByBoard')->willReturn([
			self::edge(3, 4, ['toVisibility' => 'internal', 'toCreatorRole' => 'internal']),
			self::edge(5, 6, ['fromVisibility' => 'internal', 'fromCreatorRole' => 'internal']),
			self::edge(7, 8, ['fromVisibility' => 'private', 'fromOwner' => 'carol']),
			self::edge(9, 10),
		]);

		$viewer = ViewerContext::forMember('erin', 1, ViewerContext::ROLE_EXTERNAL, false);
		$out = array_column($this->service->serialize(1, $cards, $viewer), null, 'id');

		self::assertFalse($out[3]['blocking'], 'hidden target: the blocker must not reveal it');
		self::assertFalse($out[6]['blocked'], 'hidden blocker: the blocked card must not reveal it');
		self::assertFalse($out[8]['blocked'], 'another user\'s private blocker stays invisible');
		// Visible on both ends: unchanged.
		self::assertTrue($out[9]['blocking']);
		self::assertTrue($out[10]['blocked']);
	}

	/**
	 * The board-SET path masks each edge under the viewer's role on THAT
	 * edge's board: the same internal-only edge is counted on the board where
	 * the viewer is internal and dropped where they are external.
	 */
	public function testSerializeForBoardsMasksBlocksEdgesPerBoardRole(): void {
		$cards = [];
		foreach ([[3, 1], [4, 1], [13, 2], [14, 2]] as [$id, $boardId]) {
			$card = new Card();
			$card->setId($id);
			$card->setBoardId($boardId);
			$card->setStackId($boardId);
			$card->setTitle('card ' . $id);
			$cards[] = $card;
		}
		$this->cardRelationMapper->method('liveBlocksEdgesByBoards')->willReturn([
			self::edge(3, 4, ['toVisibility' => 'internal', 'toCreatorRole' => 'internal']),
			self::edge(13, 14, ['boardId' => 2, 'toVisibility' => 'internal', 'toCreatorRole' => 'internal']),
		]);

		$out = array_column($this->service->serializeForBoards(
			[1, 2],
			$cards,
			'alice',
			[1 => ViewerContext::ROLE_INTERNAL, 2 => ViewerContext::ROLE_EXTERNAL],
		), null, 'id');

		self::assertTrue($out[3]['blocking']);
		self::assertTrue($out[4]['blocked']);
		self::assertFalse($out[13]['blocking'], 'external on board 2: the internal target is hidden');
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
		$this->checklistItemMapper->method('overdueByBoard')->willReturn([3 => 2]);
		$this->checklistItemMapper->method('overdueByBoards')->willReturn([3 => 2]);
		$this->checklistItemMapper->method('waitingByBoard')->willReturn([3 => 1700000000]);
		$this->checklistItemMapper->method('waitingByBoards')->willReturn([3 => 1700000000]);
		$this->cardMapper->method('childProgressByBoard')->willReturn([3 => ['total' => 2, 'done' => 1]]);
		$this->cardMapper->method('childProgressByBoards')->willReturn([3 => ['total' => 2, 'done' => 1]]);
		$this->commentMapper->method('countsByBoard')->willReturn([3 => 5]);
		$this->commentMapper->method('countsByBoards')->willReturn([3 => 5]);
		$this->cardReviewMapper->method('reviewStatesByBoard')->willReturn([3 => 'pending']);
		$this->cardReviewMapper->method('reviewStatesByBoards')->willReturn([3 => 'pending']);
		$edges = [self::edge(3, 8), self::edge(9, 3)];
		$this->cardRelationMapper->method('liveBlocksEdgesByBoard')->willReturn($edges);
		$this->cardRelationMapper->method('liveBlocksEdgesByBoards')->willReturn($edges);
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
		$this->checklistItemMapper->expects(self::once())->method('overdueByBoards')->willReturn([]);
		$this->checklistItemMapper->expects(self::once())->method('waitingByBoards')->with($boardIds, 'alice', $roles)->willReturn([]);
		$this->cardMapper->expects(self::once())->method('childProgressByBoards')->with($boardIds, 'alice', $roles)->willReturn([]);
		$this->commentMapper->expects(self::once())->method('countsByBoards')->with($boardIds)->willReturn([]);
		$this->cardReviewMapper->expects(self::once())->method('reviewStatesByBoards')->with($boardIds)->willReturn([]);
		$this->cardRelationMapper->expects(self::once())->method('liveBlocksEdgesByBoards')->with($boardIds)->willReturn([]);
		$this->recurRuleMapper->expects(self::once())->method('findTemplateCardIdsByBoards')->with($boardIds)->willReturn([]);
		$this->runningTimerMapper->expects(self::once())->method('findCardIdsByBoards')->with($boardIds)->willReturn([]);
		// …and the board-SCOPED twins are never reached from this path.
		$this->cardLabelMapper->expects(self::never())->method('findLabelIdsByBoard');
		$this->checklistItemMapper->expects(self::never())->method('progressByBoard');
		$this->checklistItemMapper->expects(self::never())->method('overdueByBoard');
		$this->cardMapper->expects(self::never())->method('childProgressByBoard');

		$out = $this->service->serializeForBoards($boardIds, $cards, 'alice', $roles);

		self::assertSame([11, 12, 13], array_column($out, 'id'));
		self::assertSame([1, 2, 3], array_column($out, 'boardId'));
	}

	/**
	 * THE perf bet, measured instead of assumed: the board payload's enrichment
	 * is a FIXED number of SQL statements, independent of how many cards, labels,
	 * steps or comments the board holds. The mappers here are REAL (only the
	 * connection is a mock), so every executeQuery() they issue is counted - an
	 * N+1 re-introduced anywhere under serialize() moves this number and fails.
	 *
	 * 14 as of #10696: 13 before, plus the one grouped overdue-step query the
	 * tile's new tint is derived from. Bump this deliberately, never to make a
	 * red test green.
	 */
	public function testSerializeIssuesAFixedNumberOfQueriesRegardlessOfCardCount(): void {
		$one = $this->countSerializeQueries(1);
		$many = $this->countSerializeQueries(50);

		self::assertSame(14, $one, 'the board summary must cost exactly 14 statements');
		self::assertSame(
			$one,
			$many,
			'and the SAME number for 50 cards as for one - a per-card lookup would show up here'
		);
	}

	/**
	 * Builds the service over REAL mappers on a counting connection, serializes
	 * $cardCount cards and returns how many statements were executed.
	 */
	private function countSerializeQueries(int $cardCount): int {
		$queries = 0;

		$db = $this->createMock(\OCP\IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$queries): \OCP\DB\QueryBuilder\IQueryBuilder {
			$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
			foreach ([
				'select', 'selectAlias', 'selectDistinct', 'addSelect', 'from', 'innerJoin',
				'leftJoin', 'join', 'where', 'andWhere', 'orWhere', 'groupBy', 'addGroupBy',
				'having', 'orderBy', 'addOrderBy', 'setMaxResults', 'setFirstResult',
			] as $method) {
				$qb->method($method)->willReturnSelf();
			}
			$sink = new class {
				public function __call(string $name, array $args): string {
					return '';
				}
			};
			$qb->method('expr')->willReturn($sink);
			$qb->method('func')->willReturn($sink);
			$qb->method('createNamedParameter')->willReturn('?');
			$qb->method('createFunction')->willReturn('fn');

			$result = $this->createMock(\OCP\DB\IResult::class);
			$result->method('fetch')->willReturn(false);
			$result->method('fetchAll')->willReturn([]);
			$result->method('fetchOne')->willReturn(false);
			$qb->method('executeQuery')->willReturnCallback(function () use (&$queries, $result): \OCP\DB\IResult {
				$queries++;
				return $result;
			});

			return $qb;
		});

		$scope = new \OCA\Kanso\Service\CardVisibilityScope();
		$service = new CardSummaryService(
			new CardLabelMapper($db, $scope),
			new CardAssigneeMapper($db, $scope),
			new CardContactMapper($db),
			new ChecklistItemMapper($db, $scope),
			new CardMapper($db, $scope),
			new CommentMapper($db, $scope),
			new CardReviewMapper($db, $scope),
			new CardRelationMapper($db),
			new RecurRuleMapper($db),
			new CardRunningTimerMapper($db),
			$scope,
		);

		$cards = [];
		for ($i = 0; $i < $cardCount; $i++) {
			$card = new Card();
			$card->setId(100 + $i);
			$card->setBoardId(1);
			$card->setStackId(2);
			$card->setTitle('card ' . $i);
			$cards[] = $card;
		}

		$service->serialize(1, $cards, ViewerContext::forMember('alice', 1, ViewerContext::ROLE_INTERNAL, true));

		return $queries;
	}
}
