<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardCascade;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Service\CardAttachmentService;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\TrashService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TrashServiceTest extends TestCase {
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private ChangeNotifier&MockObject $changeNotifier;
	private PermissionService&MockObject $permissionService;
	private BoardCascade&MockObject $cascade;
	private CardAttachmentService&MockObject $cardAttachmentService;
	private BoardAccess&MockObject $boardAccess;
	private CardVisibilityGuard&MockObject $visibilityGuard;
	private TrashService $service;

	/** @var list<string> ordered log of the destructive steps taken */
	private array $steps = [];

	protected function setUp(): void {
		parent::setUp();
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->cascade = $this->createMock(BoardCascade::class);
		$this->cardAttachmentService = $this->createMock(CardAttachmentService::class);
		$this->boardAccess = $this->createMock(BoardAccess::class);
		$this->boardAccess->method('contextFor')->willReturnCallback(
			static fn (Board $board, string $uid): ViewerContext => ViewerContext::forMember($uid, (int)$board->getId(), ViewerContext::ROLE_INTERNAL, true),
		);
		// Default: every card is visible to the actor (assertVisible passes).
		$this->visibilityGuard = $this->createMock(CardVisibilityGuard::class);
		$this->service = new TrashService(
			$this->cardMapper,
			$this->boardMapper,
			$this->changeNotifier,
			$this->permissionService,
			$this->cascade,
			$this->cardAttachmentService,
			$this->boardAccess,
			$this->visibilityGuard,
		);
	}

	/**
	 * Wires every destructive collaborator to append to {@see $steps}, so the
	 * ORDER of the purge - grandchildren before the parents that locate them,
	 * bytes before the rows naming them, the card row last - is assertable
	 * rather than assumed.
	 */
	private function recordSteps(): void {
		$this->cascade->method('idsIn')
			->willReturnCallback(static fn (string $table): array => $table === 'kanso_comments'
				? [50, 51]
				: []);
		$this->cascade->method('deleteIn')
			->willReturnCallback(function (string $table, string $column, array $ids): int {
				$this->steps[] = 'deleteIn:' . $table . ':' . $column . ':' . implode(',', $ids);
				return count($ids);
			});
		$this->cardAttachmentService->method('deleteAllForCard')
			->willReturnCallback(function (int $cardId): void {
				$this->steps[] = 'storage:' . $cardId;
			});
		$this->cascade->method('deleteByCardIds')
			->willReturnCallback(function (array $ids): int {
				$this->steps[] = 'card-scoped:' . implode(',', $ids);
				return 0;
			});
		$this->cardMapper->method('delete')
			->willReturnCallback(function (Card $card): Card {
				$this->steps[] = 'card:' . $card->getId();
				return $card;
			});
	}

	private function board(int $id = 1): Board {
		$board = new Board();
		$board->setId($id);
		$board->setOwner('alice');
		$board->setDeletedAt(0);
		return $board;
	}

	private function trashedCard(int $id = 9, int $boardId = 1): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId($boardId);
		$card->setStackId(5);
		$card->setTitle('Trashed card');
		$card->setSortKey('I');
		$card->setDeletedAt(12345);
		return $card;
	}

	// ---- listTrash --------------------------------------------------------

	public function testListTrashAssertsReadAndReturnsDeletedCards(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'reader', PermissionService::PERMISSION_READ);
		$trashed = [$this->trashedCard(9), $this->trashedCard(10)];
		// The query is scoped by the resolved viewer context (#3743).
		$this->cardMapper->method('findDeletedByBoard')
			->with(1, self::isInstanceOf(ViewerContext::class))
			->willReturn($trashed);

		self::assertSame($trashed, $this->service->listTrash(1, 'reader'));
	}

	// ---- restore ----------------------------------------------------------

	public function testRestoreClearsDeletedAtAndWritesChangeRow(): void {
		$card = $this->trashedCard(9);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with(self::anything(), 'alice', PermissionService::PERMISSION_EDIT);
		$this->cardMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (Card $c): Card {
				self::assertSame(0, $c->getDeletedAt());
				return $c;
			});
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_CREATE, 'alice')
			->willReturn(new Change());

		$restored = $this->service->restore(9, 'alice');
		self::assertSame(0, $restored->getDeletedAt());
	}

	public function testRestoreRejectsCardNotInTrash(): void {
		$card = $this->trashedCard(9);
		$card->setDeletedAt(0); // a live card
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->restore(9, 'alice');
	}

	public function testRestoreChecksAccessBeforeRevealingTrashState(): void {
		// #10307: "not in the trash" is a 400 that a non-member would otherwise
		// get for ANY live card id on ANY board - an existence oracle. The board
		// permission (403) must be settled BEFORE that input check speaks.
		$card = $this->trashedCard(9);
		$card->setDeletedAt(0); // a live card - the 400 case
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with(self::anything(), 'mallory', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());

		$this->expectException(NotPermittedException::class);
		$this->service->restore(9, 'mallory');
	}

	public function testRestoreOfALiveHiddenCardReadsAsMissing(): void {
		// Same ordering one step in: a member who cannot SEE the card gets the
		// visibility 404, never the 400 that would confirm the card is live.
		$card = $this->trashedCard(9);
		$card->setDeletedAt(0);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->visibilityGuard->method('assertVisible')
			->willThrowException(new DoesNotExistException('Card 9 does not exist'));

		$this->expectException(DoesNotExistException::class);
		$this->service->restore(9, 'mallory');
	}

	public function testRestoreAssertsActorEditPermission(): void {
		$card = $this->trashedCard(9);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with(self::anything(), 'mallory', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->restore(9, 'mallory');
	}

	public function testRestoreHiddenCardReadsAsMissing(): void {
		// Visibility (#3743): a trashed card the actor may not see restores like
		// a card that does not exist - a 404, never a confirmation it is there.
		$card = $this->trashedCard(9);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->visibilityGuard->method('assertVisible')
			->willThrowException(new DoesNotExistException('Card 9 does not exist'));
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(DoesNotExistException::class);
		$this->service->restore(9, 'mallory');
	}

	public function testRestoreRejectsMissingCard(): void {
		$this->cardMapper->method('find')->with(9)->willThrowException(new DoesNotExistException('gone'));
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(DoesNotExistException::class);
		$this->service->restore(9, 'alice');
	}

	// ---- purge ------------------------------------------------------------

	public function testPurgeCascadesAllChildRowsAndHardDeletesCard(): void {
		$card = $this->trashedCard(9);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with(self::anything(), 'alice', PermissionService::PERMISSION_MANAGE);
		$this->recordSteps();
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_DELETE, 'alice')
			->willReturn(new Change());

		$this->service->purge(9, 'alice');

		self::assertSame(
			[
				// Reactions are dropped by comment id BEFORE the comments that
				// located them are hard-deleted (#3550).
				'deleteIn:kanso_comment_reactions:comment_id:50,51',
				// Then the bytes, while the rows naming the storage keys are
				// still there (#3526).
				'storage:9',
				// Then every card-scoped table in one registry-driven sweep.
				'card-scoped:9',
				'card:9',
			],
			$this->steps,
		);
	}

	public function testPurgeSweepsEveryCardScopedTableThroughTheSharedRegistry(): void {
		// The whole point of the collapse (#10456): the per-card purge no longer
		// carries its own copy of the card-scoped table list, so it cannot drift
		// from the board cascade's. What it sweeps is exactly what
		// BoardCascade::BY_CARD_ID declares - the list the migration-scanning
		// guard (BoardCascadeCompletenessTest) keeps complete.
		$card = $this->trashedCard(9);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cascade->expects(self::once())
			->method('deleteByCardIds')
			->with([9])
			->willReturn(0);

		$this->service->purge(9, 'alice');

		// The tables the hand-written cascade had grown one bug report at a time
		// must all still be reachable from the registry, or the collapse dropped
		// a sweep: running timers (#73), time entries (#3536), field values
		// (#3537), reminders (#3816) and template-anchored recurrence rules
		// (#4123) - the last keyed by template_card_id, not card_id, so an
		// orphan schedule cannot keep failing to spawn every cron pass.
		foreach ([
			'kanso_card_running_timers' => 'card_id',
			'kanso_card_time_entries' => 'card_id',
			'kanso_card_field_values' => 'card_id',
			'kanso_reminders' => 'card_id',
			'kanso_recur_rules' => 'template_card_id',
		] as $table => $column) {
			self::assertContains(
				$column,
				BoardCascade::BY_CARD_ID[$table] ?? [],
				$table . ' is no longer swept by card id, so purging a card strands its rows.',
			);
		}
	}

	public function testPurgeCleansUpAttachmentsBeforeHardDeletingCard(): void {
		$card = $this->trashedCard(9);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());

		// The attachment cascade must fire (it removes both the app-data objects
		// and the rows) so a purge never leaks stored bytes (#3526).
		$this->cardAttachmentService->expects(self::once())
			->method('deleteAllForCard')
			->with(9);

		$this->service->purge(9, 'alice');
	}

	public function testPurgeHiddenCardReadsAsMissing(): void {
		// Same 404 semantics on the hard delete: no cascade may fire for a card
		// the actor cannot see.
		$card = $this->trashedCard(9);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->visibilityGuard->method('assertVisible')
			->willThrowException(new DoesNotExistException('Card 9 does not exist'));
		$this->cardMapper->expects(self::never())->method('delete');
		$this->cardAttachmentService->expects(self::never())->method('deleteAllForCard');
		$this->cascade->expects(self::never())->method('deleteByCardIds');

		$this->expectException(DoesNotExistException::class);
		$this->service->purge(9, 'mallory');
	}

	public function testPurgeRejectsCardNotInTrash(): void {
		$card = $this->trashedCard(9);
		$card->setDeletedAt(0);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('delete');

		$this->expectException(InvalidInputException::class);
		$this->service->purge(9, 'alice');
	}

	public function testPurgeChecksAccessBeforeRevealingTrashState(): void {
		// #10307, the destructive twin of the restore case: a live card must
		// answer the caller's ACCESS verdict (403), not the input-level 400 that
		// would confirm the id exists and is not trashed.
		$card = $this->trashedCard(9);
		$card->setDeletedAt(0);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with(self::anything(), 'mallory', PermissionService::PERMISSION_MANAGE)
			->willThrowException(new NotPermittedException());
		$this->cardMapper->expects(self::never())->method('delete');

		$this->expectException(NotPermittedException::class);
		$this->service->purge(9, 'mallory');
	}

	public function testPurgeOfALiveHiddenCardReadsAsMissing(): void {
		$card = $this->trashedCard(9);
		$card->setDeletedAt(0);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->visibilityGuard->method('assertVisible')
			->willThrowException(new DoesNotExistException('Card 9 does not exist'));
		$this->cardMapper->expects(self::never())->method('delete');

		$this->expectException(DoesNotExistException::class);
		$this->service->purge(9, 'mallory');
	}

	public function testPurgeAssertsActorManagePermission(): void {
		$card = $this->trashedCard(9);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with(self::anything(), 'editor', PermissionService::PERMISSION_MANAGE)
			->willThrowException(new NotPermittedException());
		$this->cardMapper->expects(self::never())->method('delete');
		$this->cascade->expects(self::never())->method('deleteByCardIds');
		$this->cascade->expects(self::never())->method('deleteIn');
		$this->cardAttachmentService->expects(self::never())->method('deleteAllForCard');

		$this->expectException(NotPermittedException::class);
		$this->service->purge(9, 'editor');
	}
}
