<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\AclMapper;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssignee;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabel;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeDetailMapper;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\CsvImportService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\LabelService;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\SortKeyService;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CsvImportServiceTest extends TestCase {
	private BoardService&MockObject $boardService;
	private StackMapper&MockObject $stackMapper;
	private CardMapper&MockObject $cardMapper;
	private LabelMapper&MockObject $labelMapper;
	private CardLabelMapper&MockObject $cardLabelMapper;
	private CardAssigneeMapper&MockObject $cardAssigneeMapper;
	private ChangeNotifier&MockObject $changeNotifier;
	private PermissionService&MockObject $permissionService;
	private BoardMapper&MockObject $boardMapper;
	private CardService&MockObject $cardService;
	private IUserManager&MockObject $userManager;
	private IDBConnection&MockObject $db;
	private CsvImportService $service;

	private const BOARD_ID = 900;
	private const STACK_ID = 30;

	protected function setUp(): void {
		parent::setUp();
		$this->boardService = $this->createMock(BoardService::class);
		$this->stackMapper = $this->createMock(StackMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->labelMapper = $this->createMock(LabelMapper::class);
		$this->cardLabelMapper = $this->createMock(CardLabelMapper::class);
		$this->cardAssigneeMapper = $this->createMock(CardAssigneeMapper::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->cardService = $this->createMock(CardService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->db = $this->createMock(IDBConnection::class);

		$this->service = $this->serviceWith($this->permissionService);
	}

	/**
	 * The importer wired against $permissions, with a REAL {@see LabelService}
	 * behind it. Real, not mocked, deliberately: label creation is the privileged
	 * write this suite has to pin, so the MANAGE assert and the ENTITY_LABEL
	 * change row must be the production ones - a LabelService mock would let the
	 * importer "create" a label without either and the tests would still pass.
	 */
	private function serviceWith(PermissionService $permissions): CsvImportService {
		$labelService = new LabelService(
			$this->labelMapper,
			$this->cardLabelMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->changeNotifier,
			$permissions,
			$this->db,
			$this->createMock(CardVisibilityGuard::class),
			$this->createMock(ChangeDetailMapper::class),
		);

		return new CsvImportService(
			$this->boardService,
			$this->stackMapper,
			$this->cardMapper,
			$this->labelMapper,
			$this->cardLabelMapper,
			$this->cardAssigneeMapper,
			new SortKeyService(),
			$this->changeNotifier,
			$permissions,
			$labelService,
			$this->cardService,
			$this->userManager,
			$this->db,
		);
	}

	/**
	 * An importer whose permission checks run through the REAL
	 * {@see PermissionService} over $rows, so a test can pin what the effective
	 * mask actually folds to (notably the external-role strip) instead of
	 * asserting against a mock that was told the answer.
	 */
	private function serviceWithRealPermissions(Acl ...$rows): CsvImportService {
		$aclMapper = $this->createMock(AclMapper::class);
		$aclMapper->method('findByBoard')->willReturn($rows);
		// Group rows would need $this->userManager->get() stubbed as well:
		// PermissionService::getUserGroupIds() short-circuits to [] for an unknown
		// uid, so a group-ACL test written against this helper without that stub
		// would silently resolve to no groups. Only TYPE_USER rows are used here.
		$groupManager = $this->createMock(IGroupManager::class);

		return $this->serviceWith(new PermissionService($aclMapper, $groupManager, $this->userManager));
	}

	/** One board ACL row for $uid, on the given board side, carrying $permission. */
	private function acl(string $uid, int $permission, string $role): Acl {
		$acl = new Acl();
		$acl->setBoardId(self::BOARD_ID);
		$acl->setParticipantType(Acl::TYPE_USER);
		$acl->setParticipant($uid);
		$acl->setPermission($permission);
		$acl->setRole($role);
		return $acl;
	}

	private function board(): Board {
		$b = new Board();
		$b->setId(self::BOARD_ID);
		$b->setTitle('Target');
		return $b;
	}

	private function stack(int $role = Stack::ROLE_NONE): Stack {
		$s = new Stack();
		$s->setId(self::STACK_ID);
		$s->setBoardId(self::BOARD_ID);
		$s->setTitle('Todo');
		$s->setRole($role);
		return $s;
	}

	/**
	 * Wires up the common happy-path expectations (board/stack found + the actor's
	 * rights). $mask drives assertPermission() the way the real PermissionService
	 * does - throwing when the mask lacks a required bit - so a test cannot grant
	 * EDIT to the import while silently granting MANAGE to label creation too.
	 *
	 * getPermissions() is deliberately left to the individual test: the label tests
	 * pin one mask for the actor, the assignee test drives it per uid.
	 */
	private function primeTarget(int $mask = PermissionService::PERMISSION_ALL, string $actorUid = 'alice'): void {
		$this->boardService->method('find')->with(self::BOARD_ID, $actorUid)->willReturn($this->board());
		$this->stackMapper->method('find')->with(self::STACK_ID)->willReturn($this->stack());
		$this->permissionService->method('assertPermission')->willReturnCallback(
			static function (Board $board, string $uid, int $permission) use ($mask): void {
				if (($mask & $permission) !== $permission) {
					throw new NotPermittedException('Operation not allowed on this board');
				}
			},
		);
		$this->cardMapper->method('nextBoardSeq')->willReturnCallback(function (): int {
			static $n = 0;
			return ++$n;
		});
		$this->cardMapper->method('findLastInStack')->willReturn(null);
	}

	private function mapping(): array {
		return ['title' => 0, 'description' => 1, 'duedate' => 2, 'labels' => 3, 'assignees' => 4];
	}

	// ── rejection cases ────────────────────────────────────────────────────────

	public function testRejectsOversizedDocument(): void {
		$this->expectException(InvalidInputException::class);
		$huge = str_repeat('x', CsvImportService::MAX_DOCUMENT_BYTES + 1);
		$this->service->import($huge, self::BOARD_ID, self::STACK_ID, ['title' => 0], true, 'alice');
	}

	public function testRejectsMissingTitleMapping(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->import("a,b\n1,2\n", self::BOARD_ID, self::STACK_ID, ['description' => 1], true, 'alice');
	}

	public function testRejectsTooManyRows(): void {
		$this->expectException(InvalidInputException::class);
		$rows = "title\n" . str_repeat("x\n", CsvImportService::MAX_ROWS + 1);
		$this->service->import($rows, self::BOARD_ID, self::STACK_ID, ['title' => 0], true, 'alice');
	}

	public function testDeniesWithoutEdit(): void {
		$this->boardService->method('find')->willReturn($this->board());
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException('nope'));
		$this->expectException(NotPermittedException::class);
		$this->service->import("title\nBuy milk\n", self::BOARD_ID, self::STACK_ID, ['title' => 0], true, 'alice');
	}

	// ── happy path ──────────────────────────────────────────────────────────────

	public function testImportsRowsAsCardsAppendedInOrder(): void {
		$this->primeTarget();
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->changeNotifier->expects(self::once())->method('pushBoardChanged')->with(self::BOARD_ID);
		// One CREATE change row per imported card (2 titled rows).
		$this->changeNotifier->expects(self::exactly(2))->method('recordChange');

		$this->labelMapper->method('findByBoard')->willReturn([]);

		$cards = [];
		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c) use (&$cards): Card {
			$c->setId(100 + count($cards));
			$cards[] = $c;
			return $c;
		});

		$csv = "title,description,due date,labels,assignees\n"
			. "Design login,Wireframe the flow,2026-02-01,,\n"
			. ",no title so skipped,,,\n"
			. "Ship it,,,,\n";

		$result = $this->service->import($csv, self::BOARD_ID, self::STACK_ID, $this->mapping(), true, 'alice');

		self::assertSame(2, $result['cards']);
		self::assertSame(1, $result['skipped']);
		self::assertSame(self::BOARD_ID, $result['boardId']);
		self::assertSame(self::STACK_ID, $result['stackId']);

		self::assertCount(2, $cards);
		self::assertSame('Design login', $cards[0]->getTitle());
		self::assertSame('Wireframe the flow', $cards[0]->getDescription());
		self::assertSame(self::STACK_ID, $cards[0]->getStackId());
		self::assertSame('alice', $cards[0]->getOwner());
		self::assertNotNull($cards[0]->getDuedate());
		// Second titled row keeps file order via a strictly-increasing sort key.
		self::assertSame('Ship it', $cards[1]->getTitle());
		self::assertNull($cards[1]->getDescription());
		self::assertTrue($cards[0]->getSortKey() < $cards[1]->getSortKey());
	}

	public function testImportsFarBeyondOldRowCap(): void {
		// Guards the "import ALL the cards" contract: a spreadsheet with many more
		// rows than the old 2000 hard cap imports every titled row, streamed off the
		// handle rather than capped or held in one big array.
		$this->primeTarget();
		$this->db->method('beginTransaction');
		$this->db->method('commit');
		$this->labelMapper->method('findByBoard')->willReturn([]);

		$count = 0;
		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c) use (&$count): Card {
			$c->setId(1000 + $count);
			$count++;
			return $c;
		});

		$rowCount = 5000;
		$csv = "title\n" . str_repeat("Task\n", $rowCount);
		$result = $this->service->import($csv, self::BOARD_ID, self::STACK_ID, ['title' => 0], true, 'alice');

		self::assertSame($rowCount, $result['cards']);
		self::assertSame($rowCount, $count);
	}

	public function testTruncatesLongTitles(): void {
		$this->primeTarget();
		$this->db->method('beginTransaction');
		$this->db->method('commit');
		$this->labelMapper->method('findByBoard')->willReturn([]);

		$captured = null;
		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c) use (&$captured): Card {
			$c->setId(100);
			$captured = $c;
			return $c;
		});

		$long = str_repeat('x', 250);
		$this->service->import("title\n$long\n", self::BOARD_ID, self::STACK_ID, ['title' => 0], true, 'alice');
		self::assertSame(100, mb_strlen($captured->getTitle()));
	}

	// ── labels: match-or-create, and creating is MANAGE-gated ───────────────────

	/**
	 * The CSV every label test imports. Row 1 names the existing "bug" (in a
	 * different case) plus an unknown "urgent"; row 2 names "urgent" again, so a
	 * name is created - or counted as skipped - exactly ONCE either way.
	 */
	private const LABEL_CSV = "title,labels\n"
		. "A,\"bug, urgent\"\n"
		. "B,urgent\n";

	/** The board already carries a "Bug" label, matched case-insensitively. */
	private function primeExistingBugLabel(): void {
		$existing = new Label();
		$existing->setId(7);
		$existing->setTitle('Bug');
		$existing->setColor('eb5a46');
		$this->labelMapper->method('findByBoard')->willReturn([$existing]);
		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c): Card {
			static $id = 100;
			$c->setId($id++);
			return $c;
		});
	}

	/**
	 * Records every card↔label assignment the import writes into $assignments.
	 *
	 * @param list<array{int, int}> $assignments
	 */
	private function captureAssignments(array &$assignments): void {
		$this->cardLabelMapper->method('insertAssignment')->willReturnCallback(
			function (int $cardId, int $labelId) use (&$assignments): CardLabel {
				$assignments[] = [$cardId, $labelId];
				return new CardLabel();
			},
		);
	}

	public function testAManagerImportCreatesUnknownLabelsWithTheirChangeRow(): void {
		$this->primeTarget();
		$this->db->method('beginTransaction');
		$this->db->method('commit');
		$this->primeExistingBugLabel();
		// alice manages the board, so her import may still define labels.
		$this->permissionService->method('getPermissions')->willReturn(PermissionService::PERMISSION_ALL);
		$this->boardMapper->method('find')->with(self::BOARD_ID)->willReturn($this->board());

		$createdLabels = [];
		$this->labelMapper->method('insert')->willReturnCallback(function (Label $l) use (&$createdLabels): Label {
			$l->setId(50 + count($createdLabels));
			$createdLabels[] = $l;
			return $l;
		});

		$assignments = [];
		$this->captureAssignments($assignments);

		// Every created label carries its OWN ENTITY_LABEL / ACTION_CREATE change
		// row. Without it a delta client patches in cards naming a label id that is
		// absent from its cached `labels` array, instead of the resync an
		// ENTITY_LABEL row forces. push MUST be false: the row is written inside
		// the import transaction, and import() fires ONE board push after commit.
		$labelChanges = [];
		$this->changeNotifier->method('notify')->willReturnCallback(
			function (int $boardId, int $entity, int $entityId, int $action, ?string $actor, bool $push = true, ?int $verb = null) use (&$labelChanges): Change {
				$labelChanges[] = [$boardId, $entity, $entityId, $action, $actor, $push];
				return new Change();
			},
		);

		$result = $this->service->import(
			self::LABEL_CSV,
			self::BOARD_ID,
			self::STACK_ID,
			['title' => 0, 'labels' => 1],
			true,
			'alice',
		);

		self::assertSame(1, $result['labelsCreated']);
		self::assertSame(0, $result['labelsSkipped']);
		self::assertCount(1, $createdLabels);
		self::assertSame('urgent', $createdLabels[0]->getTitle());

		$newLabelId = $createdLabels[0]->getId();
		self::assertSame(
			[[self::BOARD_ID, Change::ENTITY_LABEL, $newLabelId, Change::ACTION_CREATE, 'alice', false]],
			$labelChanges,
		);

		// Card A got both the existing (7) and the new label; card B reused the new
		// label id (never a second create).
		$forA = array_values(array_filter($assignments, fn ($a) => $a[0] === 100));
		$forB = array_values(array_filter($assignments, fn ($a) => $a[0] === 101));
		$aIds = array_map(fn ($a) => $a[1], $forA);
		sort($aIds);
		self::assertSame([7, $newLabelId], $aIds);
		self::assertSame([[101, $newLabelId]], $forB);
	}

	public function testAnEditOnlyImportReusesExistingLabelsButNeverMintsNewOnes(): void {
		// bob holds READ|EDIT and not MANAGE - exactly what the share dialog's "can
		// edit" grants. The import is EDIT-gated and must still run; defining a
		// board-level label is a MANAGE concern, so "urgent" is dropped and counted
		// rather than minted through the importer's back door.
		$editOnly = PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT;
		$this->primeTarget($editOnly, 'bob');
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->db->expects(self::never())->method('rollBack');
		$this->primeExistingBugLabel();
		$this->permissionService->method('getPermissions')->willReturn($editOnly);

		// The escalation, pinned: not one board-level label definition is written.
		$this->labelMapper->expects(self::never())->method('insert');
		$this->changeNotifier->expects(self::never())->method('notify');

		$assignments = [];
		$this->captureAssignments($assignments);

		$result = $this->service->import(
			self::LABEL_CSV,
			self::BOARD_ID,
			self::STACK_ID,
			['title' => 0, 'labels' => 1],
			true,
			'bob',
		);

		// Skipped, NOT rejected: the rows are the point of the import, so both cards
		// land and the response reports the shortfall instead of refusing outright.
		self::assertSame(2, $result['cards']);
		self::assertSame(0, $result['labelsCreated']);
		// "urgent" appears on both rows and is reported ONCE - distinct names, the
		// same unit labelsCreated counts.
		self::assertSame(1, $result['labelsSkipped']);

		// The existing "bug" still attaches (case-insensitively) to card A; card B
		// referenced only the un-creatable name, so it gets no labels at all.
		self::assertSame([[100, 7]], $assignments);
	}

	public function testAnExternalSideMemberCannotMintLabelsEvenHoldingTheManageBit(): void {
		// The role cap, end to end through the REAL PermissionService: 'ext' holds
		// an ACL row that literally stores MANAGE, but every matching row is
		// external, so getPermissions() strips SHARE|MANAGE from the effective mask
		// (PermissionService::INTERNAL_ONLY_PERMISSIONS). EDIT survives that strip -
		// which is why the import itself still runs - and MANAGE does not, so no
		// separate internal-side assert is needed to stop the label creation here.
		$service = $this->serviceWithRealPermissions($this->acl(
			'ext',
			PermissionService::PERMISSION_ALL,
			ViewerContext::ROLE_EXTERNAL,
		));
		$this->boardService->method('find')->with(self::BOARD_ID, 'ext')->willReturn($this->board());
		$this->stackMapper->method('find')->with(self::STACK_ID)->willReturn($this->stack());
		$this->cardMapper->method('nextBoardSeq')->willReturn(1);
		$this->cardMapper->method('findLastInStack')->willReturn(null);
		// Resolvable for LabelService too, so removing the importer's own MANAGE
		// pre-check surfaces the role cap refusing the create - not a mock TypeError.
		$this->boardMapper->method('find')->with(self::BOARD_ID)->willReturn($this->board());
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->db->expects(self::never())->method('rollBack');
		$this->primeExistingBugLabel();

		$this->labelMapper->expects(self::never())->method('insert');
		$this->changeNotifier->expects(self::never())->method('notify');

		$assignments = [];
		$this->captureAssignments($assignments);

		$result = $service->import(
			self::LABEL_CSV,
			self::BOARD_ID,
			self::STACK_ID,
			['title' => 0, 'labels' => 1],
			true,
			'ext',
		);

		self::assertSame(2, $result['cards']);
		self::assertSame(0, $result['labelsCreated']);
		self::assertSame(1, $result['labelsSkipped']);
		self::assertSame([[100, 7]], $assignments);
	}

	// ── assignees: match-or-drop, READ-filtered ─────────────────────────────────

	public function testAssigneesMatchOrDropByReadPermission(): void {
		$this->primeTarget();
		$this->db->method('beginTransaction');
		$this->db->method('commit');
		$this->labelMapper->method('findByBoard')->willReturn([]);
		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c): Card {
			$c->setId(100);
			return $c;
		});

		// alice exists + can READ; bob does not exist; carol exists but cannot READ.
		$this->userManager->method('userExists')->willReturnCallback(
			fn (string $uid): bool => in_array($uid, ['alice', 'carol'], true),
		);
		$this->permissionService->method('getPermissions')->willReturnCallback(
			fn (Board $b, string $uid): int => $uid === 'alice' ? PermissionService::PERMISSION_READ : 0,
		);

		$assigned = [];
		$this->cardAssigneeMapper->method('insertAssignment')->willReturnCallback(function (int $cardId, string $uid) use (&$assigned): CardAssignee {
			$assigned[] = $uid;
			return new CardAssignee();
		});

		$csv = "title,assignees\nTask,\"alice, bob, carol\"\n";
		$this->service->import($csv, self::BOARD_ID, self::STACK_ID, ['title' => 0, 'assignees' => 1], true, 'alice');

		// Only alice survives (bob missing, carol unreadable) - no leak.
		self::assertSame(['alice'], $assigned);
	}

	public function testRollsBackOnFailure(): void {
		$this->primeTarget();
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');
		$this->changeNotifier->expects(self::never())->method('pushBoardChanged');

		$this->labelMapper->method('findByBoard')->willReturn([]);
		$this->cardMapper->method('insert')->willThrowException(new \RuntimeException('boom'));

		$this->expectException(\RuntimeException::class);
		$this->service->import("title\nX\n", self::BOARD_ID, self::STACK_ID, ['title' => 0], true, 'alice');
	}

	// ── sort-key wall: auto-rebalance + retry ───────────────────────────────────

	/**
	 * Wires the board/stack lookups with a tail card whose sort key is read
	 * through $tailKey by reference, so a simulated rebalance can shorten it
	 * mid-test the way the real one would.
	 */
	private function primeStackWithTail(string &$tailKey): void {
		$this->boardService->method('find')->willReturn($this->board());
		$this->stackMapper->method('find')->willReturn($this->stack());
		$this->permissionService->method('assertPermission');
		$this->labelMapper->method('findByBoard')->willReturn([]);
		$this->cardMapper->method('nextBoardSeq')->willReturnCallback(function (): int {
			static $n = 0;
			return ++$n;
		});
		$this->cardMapper->method('findLastInStack')->willReturnCallback(
			function () use (&$tailKey): Card {
				$tail = new Card();
				$tail->setId(1);
				$tail->setStackId(self::STACK_ID);
				$tail->setSortKey($tailKey);
				return $tail;
			},
		);
	}

	/**
	 * Records the transaction lifecycle into $log and refuses a nested
	 * beginTransaction(), so a rebalance issued from INSIDE the import
	 * transaction fails the test instead of silently deadlocking in production.
	 */
	private function traceTransactions(array &$log): void {
		$depth = 0;
		$this->db->method('beginTransaction')->willReturnCallback(function () use (&$log, &$depth): void {
			self::assertSame(0, $depth, 'a transaction was opened while another was still open');
			$depth++;
			$log[] = 'begin';
		});
		$this->db->method('commit')->willReturnCallback(function () use (&$log, &$depth): void {
			$depth--;
			$log[] = 'commit';
		});
		$this->db->method('rollBack')->willReturnCallback(function () use (&$log, &$depth): void {
			$depth--;
			$log[] = 'rollback';
		});
		$this->db->method('inTransaction')->willReturnCallback(static fn (): bool => $depth > 0);
	}

	public function testRebalancesAndRetriesWhenTheTargetStackIsAtTheSortKeyWall(): void {
		// A long-lived stack whose tail key already fills the varchar(64) column:
		// appending a block past it overflows, which used to fail the whole import
		// with a 409 only `occ kanso:rebalance` could clear.
		$tailKey = str_repeat('Z', SortKeyService::MAX_KEY_LENGTH);
		$this->primeStackWithTail($tailKey);

		$log = [];
		$this->traceTransactions($log);

		// The retry must not double-count: two rows imported, exactly two CREATE
		// change rows, no matter how many attempts it took.
		//
		// Two is what the CURRENT code produces for a structural reason, not an
		// accidental one: appendSequence() throws at CsvImportService::import()
		// BEFORE the insert loop is reached, so the first attempt records nothing
		// at all and only the successful second attempt writes. That is precisely
		// why this number is worth pinning - the regression it catches is the
		// overflow check MOVING INTO the loop (per-row key allocation, a
		// partially-written first attempt), which would make the count 4 while
		// every other assertion in this test stayed green, and every importer
		// would ship duplicate deltas to every connected client on any stack that
		// hit the sort-key wall.
		//
		// Note this cannot lean on the rollback: IDBConnection is a mock here, so
		// rollBack() only appends to $log - it could never un-count a recordChange
		// the service had already made. The count has to be right by construction.
		// Same for the board-changed push: exactly one, at the end of the
		// successful attempt.
		$this->changeNotifier->expects(self::exactly(2))->method('recordChange');
		$this->changeNotifier->expects(self::once())->method('pushBoardChanged')->with(self::BOARD_ID);

		// The real rebalanceStack() opens its OWN transaction and locks the stack's
		// rows FOR UPDATE, so it must never run nested inside the import's. Mirror
		// that here: assert nothing is open, then open/close one of its own.
		$this->cardService->expects(self::once())->method('rebalanceStack')
			->with(self::STACK_ID)
			->willReturnCallback(function () use (&$log, &$tailKey): int {
				self::assertFalse(
					$this->db->inTransaction(),
					'rebalanceStack() ran inside the import transaction',
				);
				$log[] = 'rebalance';
				$this->db->beginTransaction();
				$this->db->commit();
				$tailKey = 'MM'; // the stack now carries short, rebalanced keys
				return 3;
			});

		$cards = [];
		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c) use (&$cards): Card {
			$c->setId(200 + count($cards));
			$cards[] = $c;
			return $c;
		});

		$result = $this->service->import(
			"title\nAlpha\nBeta\n",
			self::BOARD_ID,
			self::STACK_ID,
			['title' => 0],
			true,
			'alice',
		);

		// The import succeeds instead of returning `rebalance_required`.
		self::assertSame(2, $result['cards']);
		self::assertCount(2, $cards);
		self::assertSame(['Alpha', 'Beta'], array_map(static fn (Card $c): string => $c->getTitle(), $cards));
		foreach ($cards as $card) {
			self::assertGreaterThan('MM', $card->getSortKey());
			self::assertLessThanOrEqual(SortKeyService::MAX_KEY_LENGTH, strlen($card->getSortKey()));
		}
		self::assertTrue($cards[0]->getSortKey() < $cards[1]->getSortKey());

		// The rebalance sits BETWEEN the two attempts - after the first attempt
		// rolled back, before the second began - never inside either.
		self::assertSame(
			['begin', 'rollback', 'rebalance', 'begin', 'commit', 'begin', 'commit'],
			$log,
		);
	}

	public function testStillSurfacesOverflowWhenTheRebalanceDoesNotHelp(): void {
		// Retry ONCE, not a loop: if the keys still overflow after the rebalance
		// the caller gets the same 409 `rebalance_required` as before, and not a
		// single row was written.
		$tailKey = str_repeat('Z', SortKeyService::MAX_KEY_LENGTH);
		$this->primeStackWithTail($tailKey);

		$this->cardService->expects(self::once())->method('rebalanceStack')
			->with(self::STACK_ID)
			->willReturn(0);

		$this->db->expects(self::exactly(2))->method('beginTransaction');
		$this->db->expects(self::exactly(2))->method('rollBack');
		$this->db->expects(self::never())->method('commit');
		$this->cardMapper->expects(self::never())->method('insert');
		$this->changeNotifier->expects(self::never())->method('pushBoardChanged');

		$this->expectException(\OverflowException::class);
		$this->service->import(
			"title\nAlpha\n",
			self::BOARD_ID,
			self::STACK_ID,
			['title' => 0],
			true,
			'alice',
		);
	}

	public function testDoesNotRebalanceWhenTheImportFailsForAnotherReason(): void {
		// A genuine failure still rolls back completely - no rebalance, no retry,
		// no partial rows.
		$tailKey = 'MM';
		$this->primeStackWithTail($tailKey);
		$this->cardService->expects(self::never())->method('rebalanceStack');
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');
		$this->changeNotifier->expects(self::never())->method('pushBoardChanged');
		$this->cardMapper->expects(self::once())->method('insert')
			->willThrowException(new \RuntimeException('boom'));

		$this->expectException(\RuntimeException::class);
		$this->service->import("title\nX\n", self::BOARD_ID, self::STACK_ID, ['title' => 0], true, 'alice');
	}
}
