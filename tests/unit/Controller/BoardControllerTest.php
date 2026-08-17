<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Controller\BoardController;
use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\AclMapper;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardContactMapper;
use OCA\Kanso\Db\CardFieldMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardRelationMapper;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeMapper;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\ReviewTypeMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\CardSummaryService;
use OCA\Kanso\Service\ContactService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\ParticipantService;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\SubscriptionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BoardControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private IUserSession&MockObject $userSession;
	private BoardService&MockObject $boardService;
	private ParticipantService&MockObject $participantService;
	private ContactService&MockObject $contactService;
	private ChangeMapper&MockObject $changeMapper;
	private StackMapper&MockObject $stackMapper;
	private CardMapper&MockObject $cardMapper;
	private LabelMapper&MockObject $labelMapper;
	private CardLabelMapper&MockObject $cardLabelMapper;
	private CardAssigneeMapper&MockObject $cardAssigneeMapper;
	private CardContactMapper&MockObject $cardContactMapper;
	private CardReviewMapper&MockObject $cardReviewMapper;
	private ReviewTypeMapper&MockObject $reviewTypeMapper;
	private CardFieldMapper&MockObject $cardFieldMapper;
	private ChecklistItemMapper&MockObject $checklistItemMapper;
	private CommentMapper&MockObject $commentMapper;
	private AclMapper&MockObject $aclMapper;
	private PermissionService&MockObject $permissionService;
	private SubscriptionService&MockObject $subscriptionService;
	private CardRelationMapper&MockObject $cardRelationMapper;
	private BoardAccess&MockObject $boardAccess;
	private BoardController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->boardService = $this->createMock(BoardService::class);
		$this->participantService = $this->createMock(ParticipantService::class);
		$this->contactService = $this->createMock(ContactService::class);
		$this->changeMapper = $this->createMock(ChangeMapper::class);
		$this->stackMapper = $this->createMock(StackMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->labelMapper = $this->createMock(LabelMapper::class);
		$this->cardLabelMapper = $this->createMock(CardLabelMapper::class);
		$this->cardAssigneeMapper = $this->createMock(CardAssigneeMapper::class);
		$this->cardContactMapper = $this->createMock(CardContactMapper::class);
		$this->cardReviewMapper = $this->createMock(CardReviewMapper::class);
		$this->reviewTypeMapper = $this->createMock(ReviewTypeMapper::class);
		$this->cardFieldMapper = $this->createMock(CardFieldMapper::class);
		$this->checklistItemMapper = $this->createMock(ChecklistItemMapper::class);
		$this->commentMapper = $this->createMock(CommentMapper::class);
		$this->aclMapper = $this->createMock(AclMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->subscriptionService = $this->createMock(SubscriptionService::class);
		$this->cardRelationMapper = $this->createMock(CardRelationMapper::class);
		// show()/changes() resolve the viewer context once, after the READ gate
		// (#3743); the mapper mocks just receive it.
		$this->boardAccess = $this->createMock(BoardAccess::class);
		$this->boardAccess->method('contextFor')->willReturnCallback(
			static fn (Board $board, string $uid): ViewerContext => ViewerContext::forMember($uid, (int)$board->getId(), ViewerContext::ROLE_INTERNAL, true),
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		// The per-card summary enrichment now lives in a shared CardSummaryService
		// (#3815). Inject a REAL service built from the same mapper mocks the tests
		// stub, so every existing show()/changes() assertion still exercises the
		// same enrichment fold through the controller.
		$cardSummaryService = new CardSummaryService(
			$this->cardLabelMapper,
			$this->cardAssigneeMapper,
			$this->cardContactMapper,
			$this->checklistItemMapper,
			$this->cardMapper,
			$this->commentMapper,
			$this->cardReviewMapper,
			$this->cardRelationMapper,
		);

		$this->controller = new BoardController(
			'kanso',
			$this->request,
			$this->userSession,
			$this->boardService,
			$this->participantService,
			$this->contactService,
			$this->changeMapper,
			$this->stackMapper,
			$this->cardMapper,
			$this->labelMapper,
			$this->reviewTypeMapper,
			$this->cardFieldMapper,
			$this->aclMapper,
			$this->permissionService,
			$this->subscriptionService,
			$this->boardAccess,
			$cardSummaryService
		);
	}

	private function board(int $id = 1): Board {
		$board = new Board();
		$board->setId($id);
		$board->setTitle('Board');
		$board->setOwner('alice');
		$board->setDeletedAt(0);
		return $board;
	}

	public function testShowReturns304WithoutTouchingStacksCardsAndLabels(): void {
		$this->boardService->method('find')->with(1, 'alice')->willReturn($this->board());
		$this->changeMapper->method('getLatestChangeId')->with(1)->willReturn(7);
		$this->request->method('getHeader')->with('If-None-Match')->willReturn('"7"');

		$this->stackMapper->expects(self::never())->method('findByBoard');
		$this->cardMapper->expects(self::never())->method('findSummariesByBoard');
		$this->labelMapper->expects(self::never())->method('findByBoard');
		$this->cardLabelMapper->expects(self::never())->method('findLabelIdsByBoard');
		$this->cardAssigneeMapper->expects(self::never())->method('findUserIdsByBoard');
		$this->aclMapper->expects(self::never())->method('findByBoard');
		$this->permissionService->expects(self::never())->method('getPermissions');

		$response = $this->controller->show(1);
		self::assertSame(Http::STATUS_NOT_MODIFIED, $response->getStatus());
		self::assertSame('7', $response->getETag());
	}

	public function testShowReturns304ForWeakIfNoneMatch(): void {
		$this->boardService->method('find')->with(1, 'alice')->willReturn($this->board());
		$this->changeMapper->method('getLatestChangeId')->with(1)->willReturn(7);
		$this->request->method('getHeader')->with('If-None-Match')->willReturn('W/"7"');

		$this->cardMapper->expects(self::never())->method('findSummariesByBoard');

		$response = $this->controller->show(1);
		self::assertSame(Http::STATUS_NOT_MODIFIED, $response->getStatus());
	}

	public function testShowReturnsFullPayloadWithETagOnMiss(): void {
		$board = $this->board();
		$this->boardService->method('find')->with(1, 'alice')->willReturn($board);
		$this->changeMapper->method('getLatestChangeId')->with(1)->willReturn(7);
		$this->request->method('getHeader')->with('If-None-Match')->willReturn('"3"');

		$stack = new Stack();
		$stack->setId(2);
		$stack->setBoardId(1);
		$this->stackMapper->method('findByBoard')->with(1)->willReturn([$stack]);

		$card = new Card();
		$card->setId(3);
		$card->setBoardId(1);
		$card->setStackId(2);
		$card->setTitle('A card');
		$card->setDescription('must not leak into summaries');
		$unlabeled = new Card();
		$unlabeled->setId(4);
		$unlabeled->setBoardId(1);
		$unlabeled->setStackId(2);
		$unlabeled->setTitle('No labels');
		$this->cardMapper->method('findSummariesByBoard')->with(1)->willReturn([$card, $unlabeled]);

		$label = new Label();
		$label->setId(7);
		$label->setBoardId(1);
		$label->setTitle('Urgent');
		$this->labelMapper->method('findByBoard')->with(1)->willReturn([$label]);
		$this->cardLabelMapper->method('findLabelIdsByBoard')->with(1)->willReturn([3 => [7]]);
		$this->cardAssigneeMapper->method('findUserIdsByBoard')->with(1)->willReturn([3 => ['bob']]);
		$this->checklistItemMapper->method('progressByBoard')->with(1)->willReturn([3 => ['total' => 4, 'done' => 1]]);
		// Card 3 has an open external step (waiting on client since 1700000000);
		// card 4 has none - it must read false/null, not be absent (#3746).
		$this->checklistItemMapper->method('waitingByBoard')->with(1)->willReturn([3 => 1700000000]);
		$this->cardMapper->method('childProgressByBoard')->with(1)->willReturn([3 => ['total' => 2, 'done' => 1]]);
		$this->commentMapper->method('countsByBoard')->with(1)->willReturn([3 => 5]);

		$acl = new Acl();
		$acl->setId(40);
		$acl->setBoardId(1);
		$acl->setParticipantType(Acl::TYPE_USER);
		$acl->setParticipant('bob');
		$acl->setPermission(PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT);
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([$acl]);
		$this->permissionService->method('getPermissions')
			->with($board, 'alice')
			->willReturn(PermissionService::PERMISSION_ALL);

		$response = $this->controller->show(1);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('7', $response->getETag());

		$data = $response->getData();
		self::assertSame($board, $data['board']);
		self::assertSame([$stack], $data['stacks']);
		self::assertSame([$label], $data['labels']);
		self::assertSame([$acl], $data['acl']);
		self::assertSame('user', $acl->jsonSerialize()['participantType']);
		self::assertSame(PermissionService::PERMISSION_ALL, $data['permissions']);
		self::assertCount(2, $data['cards']);
		self::assertSame(3, $data['cards'][0]['id']);
		self::assertSame([7], $data['cards'][0]['labelIds']);
		self::assertSame(['bob'], $data['cards'][0]['assigneeIds']);
		self::assertSame([], $data['cards'][1]['labelIds']);
		self::assertSame([], $data['cards'][1]['assigneeIds']);
		self::assertSame(['total' => 4, 'done' => 1], $data['cards'][0]['checklist']);
		self::assertSame(['total' => 0, 'done' => 0], $data['cards'][1]['checklist']);
		// Derived waiting-on-client fields (#3746): from the waitingByBoard map,
		// never stored on the card.
		self::assertTrue($data['cards'][0]['waitingOnExternal']);
		self::assertSame(1700000000, $data['cards'][0]['waitingSince']);
		self::assertFalse($data['cards'][1]['waitingOnExternal']);
		self::assertNull($data['cards'][1]['waitingSince']);
		self::assertSame(['total' => 2, 'done' => 1], $data['cards'][0]['childProgress']);
		self::assertSame(['total' => 0, 'done' => 0], $data['cards'][1]['childProgress']);
		self::assertSame(5, $data['cards'][0]['commentCount']);
		self::assertSame(0, $data['cards'][1]['commentCount']);
		self::assertNull($data['cards'][0]['parentCardId']);
		self::assertArrayNotHasKey('description', $data['cards'][0]);
	}

	public function testShowMapsNotPermittedTo403(): void {
		$this->boardService->method('find')->willThrowException(new NotPermittedException());

		$response = $this->controller->show(1);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testShowMapsDoesNotExistTo404(): void {
		$this->boardService->method('find')
			->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->show(1);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testIndexReturnsBoardsWithStats(): void {
		$payload = [$this->board()->jsonSerialize() + ['stats' => [
			'cardCount' => 3,
			'doneCount' => 1,
			'progress' => 33,
			'needsReview' => 0,
			'overdue' => 2,
		]]];
		$this->boardService->method('findAllWithStats')->with('alice')->willReturn($payload);

		$response = $this->controller->index();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($payload, $response->getData());
	}

	public function testCreateMapsInvalidInputTo400(): void {
		$this->boardService->method('create')
			->willThrowException(new InvalidInputException('Title must not be empty'));

		$response = $this->controller->create('');
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('Title must not be empty', $response->getData()['error']);
	}

	public function testDestroyMapsNotPermittedTo403(): void {
		$this->boardService->method('delete')->willThrowException(new NotPermittedException());

		$response = $this->controller->destroy(1);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testParticipantsReturnsList(): void {
		$participants = [
			['uid' => 'alice', 'displayName' => 'Alice Adams'],
			['uid' => 'bob', 'displayName' => 'Bob Baker'],
		];
		$this->participantService->method('getParticipants')
			->with(1, 'alice', null)
			->willReturn($participants);

		$response = $this->controller->participants(1);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($participants, $response->getData());
	}

	public function testParticipantsPassesQueryThrough(): void {
		$participants = [['uid' => 'bob', 'displayName' => 'Bob Baker']];
		$this->participantService->expects(self::once())
			->method('getParticipants')
			->with(1, 'alice', 'bob')
			->willReturn($participants);

		$response = $this->controller->participants(1, 'bob');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($participants, $response->getData());
	}

	public function testParticipantsMapsNotPermittedTo403(): void {
		$this->participantService->method('getParticipants')
			->willThrowException(new NotPermittedException());

		$response = $this->controller->participants(1);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testParticipantsMapsDoesNotExistTo404(): void {
		$this->participantService->method('getParticipants')
			->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->participants(1);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ---- changes() delta-sync (#3675) --------------------------------------

	private function change(int $id, int $entityType, int $entityId, int $action): Change {
		$change = new Change();
		$change->setId($id);
		$change->setBoardId(1);
		$change->setEntityType($entityType);
		$change->setEntityId($entityId);
		$change->setAction($action);
		return $change;
	}

	/**
	 * Stubs the board-wide enrichment maps serializeCardSummaries() reads, so a
	 * changes() test that expects an upsert gets the full (board-shaped) card.
	 */
	private function stubEnrichmentEmpty(): void {
		$this->cardLabelMapper->method('findLabelIdsByBoard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByBoard')->willReturn([]);
		$this->cardContactMapper->method('findContactsByBoard')->willReturn([]);
		$this->checklistItemMapper->method('progressByBoard')->willReturn([]);
		$this->checklistItemMapper->method('waitingByBoard')->willReturn([]);
		$this->cardMapper->method('childProgressByBoard')->willReturn([]);
		$this->commentMapper->method('countsByBoard')->willReturn([]);
		$this->cardReviewMapper->method('reviewStatesByBoard')->willReturn([]);
		$this->cardRelationMapper->method('blockedCardIdsByBoard')->willReturn([]);
	}

	public function testChangesResyncsWhenCursorIsZero(): void {
		$this->boardService->method('find')->with(1, 'alice')->willReturn($this->board());
		$this->changeMapper->method('getLatestChangeId')->with(1)->willReturn(9);
		// No window read at all for a cursorless client.
		$this->changeMapper->expects(self::never())->method('findSince');

		$response = $this->controller->changes(1, 0);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($response->getData()['resync']);
		self::assertSame(9, $response->getData()['cursor']);
	}

	public function testChangesResyncsWhenCursorBelowRetainedTail(): void {
		$this->boardService->method('find')->with(1, 'alice')->willReturn($this->board());
		$this->changeMapper->method('getLatestChangeId')->with(1)->willReturn(50);
		// Oldest retained change is 20; a cursor of 5 has fallen off the pruned tail.
		$this->changeMapper->method('getOldestChangeId')->with(1)->willReturn(20);
		$this->changeMapper->expects(self::never())->method('findSince');

		$response = $this->controller->changes(1, 5);
		self::assertTrue($response->getData()['resync']);
		self::assertSame(50, $response->getData()['cursor']);
	}

	public function testChangesResyncsWhenWindowIsSaturated(): void {
		$this->boardService->method('find')->with(1, 'alice')->willReturn($this->board());
		$this->changeMapper->method('getLatestChangeId')->with(1)->willReturn(600);
		$this->changeMapper->method('getOldestChangeId')->with(1)->willReturn(1);
		// A full page (limit=500) of rows means the client is more than one page
		// behind - a truncated delta, so force a resync.
		$rows = [];
		for ($i = 1; $i <= 500; $i++) {
			$rows[] = $this->change($i, Change::ENTITY_CARD, $i, Change::ACTION_UPDATE);
		}
		$this->changeMapper->method('findSince')->with(1, 5, 500)->willReturn($rows);

		$response = $this->controller->changes(1, 5);
		self::assertTrue($response->getData()['resync']);
	}

	public function testChangesResyncsOnOutOfScopeEntity(): void {
		// The MVP scope cut: a label edit in the window → resync rather than
		// replicate board-wide label/acl enrichment in the delta path.
		$this->boardService->method('find')->with(1, 'alice')->willReturn($this->board());
		$this->changeMapper->method('getLatestChangeId')->with(1)->willReturn(9);
		$this->changeMapper->method('getOldestChangeId')->with(1)->willReturn(1);
		$this->changeMapper->method('findSince')->willReturn([
			$this->change(8, Change::ENTITY_CARD, 42, Change::ACTION_UPDATE),
			$this->change(9, Change::ENTITY_LABEL, 7, Change::ACTION_UPDATE),
		]);
		// A resync must not even attempt a per-id card re-serialize.
		$this->cardMapper->expects(self::never())->method('findSummariesByIds');

		$response = $this->controller->changes(1, 5);
		self::assertTrue($response->getData()['resync']);
		self::assertSame(9, $response->getData()['cursor']);
	}

	public function testChangesUpsertsOnlyTheEditedCardWithFullShape(): void {
		$this->boardService->method('find')->with(1, 'alice')->willReturn($this->board());
		$this->changeMapper->method('getLatestChangeId')->with(1)->willReturn(8);
		$this->changeMapper->method('getOldestChangeId')->with(1)->willReturn(1);
		$this->changeMapper->method('findSince')->willReturn([
			$this->change(8, Change::ENTITY_CARD, 42, Change::ACTION_UPDATE),
		]);
		$this->stubEnrichmentEmpty();

		$card = new Card();
		$card->setId(42);
		$card->setBoardId(1);
		$card->setStackId(3);
		$card->setTitle('Edited elsewhere');
		$this->cardMapper->expects(self::once())
			->method('findSummariesByIds')->with(1, [42])->willReturn([$card]);
		// Only the touched cards are re-read - never the whole board.
		$this->cardMapper->expects(self::never())->method('findSummariesByBoard');
		$this->stackMapper->method('findByIds')->with(1, [])->willReturn([]);

		$response = $this->controller->changes(1, 5);
		$data = $response->getData();
		self::assertFalse($data['resync']);
		self::assertSame(8, $data['cursor']);
		self::assertCount(1, $data['cards']['upsert']);
		self::assertSame(42, $data['cards']['upsert'][0]['id']);
		// Full board-card shape (enrichment keys present, description absent).
		self::assertSame([], $data['cards']['upsert'][0]['labelIds']);
		self::assertSame(['total' => 0, 'done' => 0], $data['cards']['upsert'][0]['checklist']);
		self::assertFalse($data['cards']['upsert'][0]['blocked']);
		self::assertArrayNotHasKey('description', $data['cards']['upsert'][0]);
		self::assertSame([], $data['cards']['remove']);
	}

	public function testChangesCarriesNewPlacementOnMove(): void {
		$this->boardService->method('find')->with(1, 'alice')->willReturn($this->board());
		$this->changeMapper->method('getLatestChangeId')->with(1)->willReturn(8);
		$this->changeMapper->method('getOldestChangeId')->with(1)->willReturn(1);
		$this->changeMapper->method('findSince')->willReturn([
			$this->change(8, Change::ENTITY_CARD, 42, Change::ACTION_MOVE),
		]);
		$this->stubEnrichmentEmpty();

		$card = new Card();
		$card->setId(42);
		$card->setBoardId(1);
		$card->setStackId(99); // moved to a new stack
		$card->setSortKey('mm');
		$card->setTitle('Moved card');
		$this->cardMapper->method('findSummariesByIds')->with(1, [42])->willReturn([$card]);
		$this->stackMapper->method('findByIds')->willReturn([]);

		$data = $this->controller->changes(1, 5)->getData();
		self::assertSame(99, $data['cards']['upsert'][0]['stackId']);
		self::assertSame('mm', $data['cards']['upsert'][0]['sortKey']);
	}

	public function testChangesEmitsRemoveForDeletedCard(): void {
		$this->boardService->method('find')->with(1, 'alice')->willReturn($this->board());
		$this->changeMapper->method('getLatestChangeId')->with(1)->willReturn(8);
		$this->changeMapper->method('getOldestChangeId')->with(1)->willReturn(1);
		$this->changeMapper->method('findSince')->willReturn([
			$this->change(8, Change::ENTITY_CARD, 42, Change::ACTION_DELETE),
		]);
		$this->stubEnrichmentEmpty();

		// The deleted card is absent from the live summary query → it is a remove.
		$this->cardMapper->method('findSummariesByIds')->with(1, [42])->willReturn([]);
		$this->stackMapper->method('findByIds')->willReturn([]);

		$data = $this->controller->changes(1, 5)->getData();
		self::assertFalse($data['resync']);
		self::assertSame([], $data['cards']['upsert']);
		self::assertSame([42], $data['cards']['remove']);
	}

	public function testChangesEmptyWindowStillAdvancesCursor(): void {
		$this->boardService->method('find')->with(1, 'alice')->willReturn($this->board());
		$this->changeMapper->method('getLatestChangeId')->with(1)->willReturn(5);
		$this->changeMapper->method('getOldestChangeId')->with(1)->willReturn(1);
		// Client is already caught up: no rows newer than its cursor.
		$this->changeMapper->method('findSince')->willReturn([]);
		$this->stubEnrichmentEmpty();
		$this->cardMapper->method('findSummariesByIds')->willReturn([]);
		$this->stackMapper->method('findByIds')->willReturn([]);

		$data = $this->controller->changes(1, 5)->getData();
		self::assertFalse($data['resync']);
		self::assertSame(5, $data['cursor']);
		self::assertSame([], $data['cards']['upsert']);
		self::assertSame([], $data['cards']['remove']);
	}

	public function testChangesCardUpsertIsByteIdenticalToShow(): void {
		// The delta-sync invariant (#3675): a changes() upsert must be
		// indistinguishable from the same card in a full show() payload -
		// including the derived waiting-on-client fields (#3746), which both
		// paths must source from the same enrichment fold.
		$board = $this->board();
		$this->boardService->method('find')->with(1, 'alice')->willReturn($board);
		$this->changeMapper->method('getLatestChangeId')->with(1)->willReturn(8);
		$this->changeMapper->method('getOldestChangeId')->with(1)->willReturn(1);
		$this->changeMapper->method('findSince')->willReturn([
			$this->change(8, Change::ENTITY_CARD, 42, Change::ACTION_UPDATE),
		]);
		$this->request->method('getHeader')->with('If-None-Match')->willReturn('');
		$this->stackMapper->method('findByBoard')->willReturn([]);
		$this->stackMapper->method('findByIds')->willReturn([]);
		$this->labelMapper->method('findByBoard')->willReturn([]);
		$this->aclMapper->method('findByBoard')->willReturn([]);

		$card = new Card();
		$card->setId(42);
		$card->setBoardId(1);
		$card->setStackId(3);
		$card->setTitle('Waiting card');
		$this->cardMapper->method('findSummariesByBoard')->with(1)->willReturn([$card]);
		$this->cardMapper->method('findSummariesByIds')->with(1, [42])->willReturn([$card]);

		// Non-empty waiting map so the NEW fields are exercised through both paths.
		$this->cardLabelMapper->method('findLabelIdsByBoard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByBoard')->willReturn([]);
		$this->cardContactMapper->method('findContactsByBoard')->willReturn([]);
		$this->checklistItemMapper->method('progressByBoard')->willReturn([42 => ['total' => 2, 'done' => 1]]);
		$this->checklistItemMapper->method('waitingByBoard')->willReturn([42 => 1700000000]);
		$this->cardMapper->method('childProgressByBoard')->willReturn([]);
		$this->commentMapper->method('countsByBoard')->willReturn([]);
		$this->cardReviewMapper->method('reviewStatesByBoard')->willReturn([]);
		$this->cardRelationMapper->method('blockedCardIdsByBoard')->willReturn([]);

		$shown = $this->controller->show(1)->getData()['cards'][0];
		$delta = $this->controller->changes(1, 5)->getData()['cards']['upsert'][0];

		self::assertSame(json_encode($shown), json_encode($delta));
		self::assertTrue($delta['waitingOnExternal']);
		self::assertSame(1700000000, $delta['waitingSince']);
	}

	public function testChangesMapsNotPermittedTo403(): void {
		$this->boardService->method('find')->willThrowException(new NotPermittedException());

		$response = $this->controller->changes(1, 5);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testChangesMapsDoesNotExistTo404(): void {
		$this->boardService->method('find')
			->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->changes(1, 5);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testUpdateReturnsUpdatedBoard(): void {
		$board = $this->board();
		$this->boardService->method('update')
			->with(1, 'New', null, null, 'alice')
			->willReturn($board);

		$response = $this->controller->update(1, 'New');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($board, $response->getData());
	}
}
