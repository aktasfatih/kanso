<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeDetail;
use OCA\Kanso\Db\ChangeDetailMapper;
use OCA\Kanso\Db\ChangeMapper;
use OCA\Kanso\Service\ActivityService;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ActivityServiceTest extends TestCase {
	private ChangeMapper&MockObject $changeMapper;
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private PermissionService&MockObject $permissionService;
	private IUserManager&MockObject $userManager;
	private CardVisibilityGuard&MockObject $visibilityGuard;
	private ChangeDetailMapper&MockObject $changeDetailMapper;
	private ActivityService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->changeMapper = $this->createMock(ChangeMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->visibilityGuard = $this->createMock(CardVisibilityGuard::class);
		$this->visibilityGuard->method('isVisible')->willReturn(true);
		$this->changeDetailMapper = $this->createMock(ChangeDetailMapper::class);
		// Default: no detail rows. Tests exercising the diff override this.
		$this->changeDetailMapper->method('findByChangeIds')->willReturn([]);
		$this->service = new ActivityService(
			$this->changeMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->permissionService,
			$this->userManager,
			$this->visibilityGuard,
			$this->changeDetailMapper,
		);
	}

	private function card(): Card {
		$card = new Card();
		$card->setId(9);
		$card->setBoardId(1);
		$card->setDeletedAt(0);
		return $card;
	}

	private function board(): Board {
		$board = new Board();
		$board->setId(1);
		$board->setDeletedAt(0);
		return $board;
	}

	private function change(int $verb, int $action, string $actor, int $ts, ?int $id = null): Change {
		$c = new Change();
		if ($id !== null) {
			$c->setId($id);
		}
		$c->setEntityType(Change::ENTITY_CARD);
		$c->setEntityId(9);
		$c->setAction($action);
		$c->setActor($actor);
		$c->setVerb($verb);
		$c->setCreatedAt($ts);
		return $c;
	}

	public function testReturnsMappedActivityNewestFirst(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with(self::isInstanceOf(Board::class), 'bob', PermissionService::PERMISSION_READ);
		$this->changeMapper->method('findByEntity')
			->with(1, Change::ENTITY_CARD, 9, 50)
			->willReturn([
				$this->change(Change::VERB_COMMENTED, Change::ACTION_UPDATE, 'alice', 200),
				$this->change(Change::VERB_CREATED, Change::ACTION_CREATE, 'alice', 100),
			]);
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Alice A.');
		$this->userManager->method('get')->with('alice')->willReturn($user);

		$result = $this->service->getCardActivity(9, 'bob');

		self::assertCount(2, $result);
		self::assertSame(Change::VERB_COMMENTED, $result[0]['verb']);
		self::assertSame('Alice A.', $result[0]['actorName']);
		self::assertSame('alice', $result[0]['actor']);
		self::assertSame(200, $result[0]['timestamp']);
		self::assertSame(Change::VERB_CREATED, $result[1]['verb']);
	}

	public function testAttachesDescriptionDiffDetailOnlyToDescriptionItems(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		// A description-update row (id 55) plus an unrelated rename row (id 56).
		$this->changeMapper->method('findByEntity')->willReturn([
			$this->change(Change::VERB_DESCRIPTION_UPDATED, Change::ACTION_UPDATE, 'alice', 200, 55),
			$this->change(Change::VERB_RENAMED, Change::ACTION_UPDATE, 'alice', 100, 56),
		]);
		$this->userManager->method('get')->willReturn(null);

		$detail = new ChangeDetail();
		$detail->setChangeId(55);
		$detail->setFromText('Old body');
		$detail->setToText('New body');
		$this->changeDetailMapper = $this->createMock(ChangeDetailMapper::class);
		// ALL change ids are looked up in one batch; only id 55 has a detail row.
		$this->changeDetailMapper->expects(self::once())
			->method('findByChangeIds')
			->with([55, 56])
			->willReturn([55 => $detail]);
		$this->service = new ActivityService(
			$this->changeMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->permissionService,
			$this->userManager,
			$this->visibilityGuard,
			$this->changeDetailMapper,
		);

		$result = $this->service->getCardActivity(9, 'bob');

		// The description item carries the before/after diff payload.
		self::assertSame(Change::VERB_DESCRIPTION_UPDATED, $result[0]['verb']);
		self::assertSame(['from' => 'Old body', 'to' => 'New body'], $result[0]['detail']);
		// The rename item does not.
		self::assertSame(Change::VERB_RENAMED, $result[1]['verb']);
		self::assertNull($result[1]['detail']);
	}

	public function testAttachesDetailToNonDescriptionVerbs(): void {
		// A move row (id 70) carries source/target column names, a priority row
		// (id 71) carries the from/to labels - both get their detail attached,
		// proving the generalized attachment covers any verb, not just description.
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->changeMapper->method('findByEntity')->willReturn([
			$this->change(Change::VERB_MOVED, Change::ACTION_MOVE, 'alice', 300, 70),
			$this->change(Change::VERB_PRIORITY_CHANGED, Change::ACTION_UPDATE, 'alice', 200, 71),
		]);
		$this->userManager->method('get')->willReturn(null);

		$move = new ChangeDetail();
		$move->setChangeId(70);
		$move->setFromText('To Do');
		$move->setToText('In Progress');
		$prio = new ChangeDetail();
		$prio->setChangeId(71);
		$prio->setFromText('Medium');
		$prio->setToText('Urgent');
		$this->changeDetailMapper = $this->createMock(ChangeDetailMapper::class);
		$this->changeDetailMapper->expects(self::once())
			->method('findByChangeIds')
			->with([70, 71])
			->willReturn([70 => $move, 71 => $prio]);
		$this->service = new ActivityService(
			$this->changeMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->permissionService,
			$this->userManager,
			$this->visibilityGuard,
			$this->changeDetailMapper,
		);

		$result = $this->service->getCardActivity(9, 'bob');

		self::assertSame(Change::VERB_MOVED, $result[0]['verb']);
		self::assertSame(['from' => 'To Do', 'to' => 'In Progress'], $result[0]['detail']);
		self::assertSame(Change::VERB_PRIORITY_CHANGED, $result[1]['verb']);
		self::assertSame(['from' => 'Medium', 'to' => 'Urgent'], $result[1]['detail']);
	}

	/**
	 * #119: the destructive verbs. A removed time entry and a narrowed visibility
	 * have NO other record - the entry's row is gone and the old visibility is
	 * overwritten - so the feed must surface both the verb and its detail.
	 */
	public function testSurfacesTheDestructiveAndVisibilityVerbsWithTheirDetail(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->changeMapper->method('findByEntity')->willReturn([
			$this->change(Change::VERB_TIME_ENTRY_REMOVED, Change::ACTION_UPDATE, 'alice', 400, 80),
			$this->change(Change::VERB_VISIBILITY_CHANGED, Change::ACTION_UPDATE, 'alice', 300, 81),
			$this->change(Change::VERB_LINK_REMOVED, Change::ACTION_UPDATE, 'alice', 200, 82),
			$this->change(Change::VERB_FIELD_CHANGED, Change::ACTION_UPDATE, 'alice', 100, 83),
		]);
		$this->userManager->method('get')->willReturn(null);

		$time = new ChangeDetail();
		$time->setChangeId(80);
		$time->setFromText('1h 30m - Pairing');
		$visibility = new ChangeDetail();
		$visibility->setChangeId(81);
		$visibility->setFromText('Public');
		$visibility->setToText('Private');
		$link = new ChangeDetail();
		$link->setChangeId(82);
		$link->setFromText('Fix login');
		$this->changeDetailMapper = $this->createMock(ChangeDetailMapper::class);
		$this->changeDetailMapper->expects(self::once())
			->method('findByChangeIds')
			->with([80, 81, 82, 83])
			->willReturn([80 => $time, 81 => $visibility, 82 => $link]);
		$this->service = new ActivityService(
			$this->changeMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->permissionService,
			$this->userManager,
			$this->visibilityGuard,
			$this->changeDetailMapper,
		);

		$result = $this->service->getCardActivity(9, 'bob');

		// The deleted time entry names the duration it destroyed.
		self::assertSame(Change::VERB_TIME_ENTRY_REMOVED, $result[0]['verb']);
		self::assertSame(['from' => '1h 30m - Pairing', 'to' => null], $result[0]['detail']);
		// The visibility change names both ends of the move.
		self::assertSame(Change::VERB_VISIBILITY_CHANGED, $result[1]['verb']);
		self::assertSame(['from' => 'Public', 'to' => 'Private'], $result[1]['detail']);
		// A removed link names what went away.
		self::assertSame(Change::VERB_LINK_REMOVED, $result[2]['verb']);
		self::assertSame(['from' => 'Fix login', 'to' => null], $result[2]['detail']);
		// A custom-field edit is verb-only by design - no detail, but no longer
		// the meaningless generic VERB_UPDATED either.
		self::assertSame(Change::VERB_FIELD_CHANGED, $result[3]['verb']);
		self::assertNull($result[3]['detail']);
	}

	public function testDescriptionItemWithoutDetailRowCarriesNullDetail(): void {
		// Legacy description edit recorded before this feature: no side-table row.
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->changeMapper->method('findByEntity')->willReturn([
			$this->change(Change::VERB_DESCRIPTION_UPDATED, Change::ACTION_UPDATE, 'alice', 200, 55),
		]);
		$this->userManager->method('get')->willReturn(null);
		// Default findByChangeIds stub returns [] → no detail attached.

		$result = $this->service->getCardActivity(9, 'bob');
		self::assertSame(Change::VERB_DESCRIPTION_UPDATED, $result[0]['verb']);
		self::assertNull($result[0]['detail']);
	}

	public function testAssertsReadPermission(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException());
		$this->changeMapper->expects(self::never())->method('findByEntity');

		$this->expectException(NotPermittedException::class);
		$this->service->getCardActivity(9, 'stranger');
	}

	public function testFallsBackToUidWhenUserUnknown(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->changeMapper->method('findByEntity')
			->willReturn([$this->change(Change::VERB_MOVED, Change::ACTION_MOVE, 'ghost', 300)]);
		$this->userManager->method('get')->with('ghost')->willReturn(null);

		$result = $this->service->getCardActivity(9, 'bob');
		self::assertSame('ghost', $result[0]['actorName']);
	}
}
