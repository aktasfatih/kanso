<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardContactMapper;
use OCA\Kanso\Db\CardFieldValueMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardLinkMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardRelationMapper;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\CommentReactionMapper;
use OCA\Kanso\Db\ProjectCardMapper;
use OCA\Kanso\Db\ReminderMapper;
use OCA\Kanso\Db\SubscriptionMapper;
use OCA\Kanso\Service\CardAttachmentService;
use OCA\Kanso\Service\CardTimeEntryService;
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
	private CardLabelMapper&MockObject $cardLabelMapper;
	private CardAssigneeMapper&MockObject $cardAssigneeMapper;
	private CardContactMapper&MockObject $cardContactMapper;
	private CardReviewMapper&MockObject $cardReviewMapper;
	private ChecklistItemMapper&MockObject $checklistItemMapper;
	private CommentMapper&MockObject $commentMapper;
	private CommentReactionMapper&MockObject $commentReactionMapper;
	private SubscriptionMapper&MockObject $subscriptionMapper;
	private CardLinkMapper&MockObject $cardLinkMapper;
	private CardRelationMapper&MockObject $cardRelationMapper;
	private ProjectCardMapper&MockObject $projectCardMapper;
	private CardAttachmentService&MockObject $cardAttachmentService;
	private CardTimeEntryService&MockObject $cardTimeEntryService;
	private CardFieldValueMapper&MockObject $cardFieldValueMapper;
	private ReminderMapper&MockObject $reminderMapper;
	private BoardAccess&MockObject $boardAccess;
	private CardVisibilityGuard&MockObject $visibilityGuard;
	private TrashService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->cardLabelMapper = $this->createMock(CardLabelMapper::class);
		$this->cardAssigneeMapper = $this->createMock(CardAssigneeMapper::class);
		$this->cardContactMapper = $this->createMock(CardContactMapper::class);
		$this->cardReviewMapper = $this->createMock(CardReviewMapper::class);
		$this->checklistItemMapper = $this->createMock(ChecklistItemMapper::class);
		$this->commentMapper = $this->createMock(CommentMapper::class);
		$this->commentReactionMapper = $this->createMock(CommentReactionMapper::class);
		$this->subscriptionMapper = $this->createMock(SubscriptionMapper::class);
		$this->cardLinkMapper = $this->createMock(CardLinkMapper::class);
		$this->cardRelationMapper = $this->createMock(CardRelationMapper::class);
		$this->projectCardMapper = $this->createMock(ProjectCardMapper::class);
		$this->cardAttachmentService = $this->createMock(CardAttachmentService::class);
		$this->cardTimeEntryService = $this->createMock(CardTimeEntryService::class);
		$this->cardFieldValueMapper = $this->createMock(CardFieldValueMapper::class);
		$this->reminderMapper = $this->createMock(ReminderMapper::class);
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
			$this->cardLabelMapper,
			$this->cardAssigneeMapper,
			$this->cardContactMapper,
			$this->cardReviewMapper,
			$this->checklistItemMapper,
			$this->commentMapper,
			$this->commentReactionMapper,
			$this->subscriptionMapper,
			$this->cardLinkMapper,
			$this->cardRelationMapper,
			$this->projectCardMapper,
			$this->cardAttachmentService,
			$this->cardTimeEntryService,
			$this->cardFieldValueMapper,
			$this->reminderMapper,
			$this->boardAccess,
			$this->visibilityGuard,
		);
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
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->restore(9, 'alice');
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

		$this->cardLabelMapper->expects(self::once())->method('deleteByCard')->with(9);
		$this->cardAssigneeMapper->expects(self::once())->method('deleteByCard')->with(9);
		$this->cardContactMapper->expects(self::once())->method('deleteByCard')->with(9);
		$this->checklistItemMapper->expects(self::once())->method('deleteByCard')->with(9);
		// Reactions are dropped by comment id BEFORE the comments themselves (#3550).
		$this->commentMapper->expects(self::once())->method('idsByCard')->with(9)->willReturn([50, 51]);
		$this->commentReactionMapper->expects(self::once())->method('deleteByComments')->with([50, 51]);
		$this->commentMapper->expects(self::once())->method('deleteByCard')->with(9);
		$this->subscriptionMapper->expects(self::once())->method('deleteByCard')->with(9);
		// Attachments cascade through the service (objects + rows), not a mapper.
		$this->cardAttachmentService->expects(self::once())->method('deleteAllForCard')->with(9);
		// Manual time-tracking entries are cascaded too (#3536).
		$this->cardTimeEntryService->expects(self::once())->method('deleteAllForCard')->with(9);
		// Custom-field values are cascaded too (#3537).
		$this->cardFieldValueMapper->expects(self::once())->method('deleteByCard')->with(9);
		// Personal reminders are cascaded too (#3816).
		$this->reminderMapper->expects(self::once())->method('deleteByCard')->with(9);
		$this->cardMapper->expects(self::once())->method('delete')->with($card);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_DELETE, 'alice')
			->willReturn(new Change());

		$this->service->purge(9, 'alice');
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

		$this->expectException(DoesNotExistException::class);
		$this->service->purge(9, 'mallory');
	}

	public function testPurgeRejectsCardNotInTrash(): void {
		$card = $this->trashedCard(9);
		$card->setDeletedAt(0);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->cardMapper->expects(self::never())->method('delete');

		$this->expectException(InvalidInputException::class);
		$this->service->purge(9, 'alice');
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
		$this->commentMapper->expects(self::never())->method('deleteByCard');
		$this->cardAttachmentService->expects(self::never())->method('deleteAllForCard');

		$this->expectException(NotPermittedException::class);
		$this->service->purge(9, 'editor');
	}
}
