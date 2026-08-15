<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\CardSummaryService;
use OCA\Kanso\Service\ViewService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ViewServiceTest extends TestCase {
	private BoardService&MockObject $boardService;
	private CardMapper&MockObject $cardMapper;
	private BoardAccess&MockObject $boardAccess;
	private CardSummaryService&MockObject $cardSummaryService;
	private LabelMapper&MockObject $labelMapper;
	private IUserManager&MockObject $userManager;
	private ViewService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->boardService = $this->createMock(BoardService::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardAccess = $this->createMock(BoardAccess::class);
		$this->cardSummaryService = $this->createMock(CardSummaryService::class);
		$this->labelMapper = $this->createMock(LabelMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->service = new ViewService(
			$this->boardService,
			$this->cardMapper,
			$this->boardAccess,
			$this->cardSummaryService,
			$this->labelMapper,
			$this->userManager,
		);
	}

	private function board(int $id, string $owner = 'alice'): Board {
		$board = new Board();
		$board->setId($id);
		$board->setOwner($owner);
		$board->setTitle('Board ' . $id);
		return $board;
	}

	private function label(int $id, int $boardId, string $title): Label {
		$label = new Label();
		$label->setId($id);
		$label->setBoardId($boardId);
		$label->setTitle($title);
		$label->setColor('ff0000');
		return $label;
	}

	public function testFindMineSpansOnlyTheReadableBoardSet(): void {
		// The ACL boundary: only the boards findAll() returns (the readable set)
		// are ever queried, each with its OWN viewer context. A board the user
		// cannot read is never in findAll()'s result, so neither its cards nor its
		// labels can ever leak into the cross-board View feed.
		$b3 = $this->board(3);
		$b9 = $this->board(9);
		$this->boardService->expects($this->once())
			->method('findAll')
			->with('alice')
			->willReturn([$b3, $b9]);

		$ctx3 = ViewerContext::forMember('alice', 3, ViewerContext::ROLE_INTERNAL, true);
		$ctx9 = ViewerContext::forMember('alice', 9, ViewerContext::ROLE_EXTERNAL, false);
		$this->boardAccess->expects($this->exactly(2))
			->method('contextFor')
			->willReturnMap([
				[$b3, 'alice', $ctx3],
				[$b9, 'alice', $ctx9],
			]);

		$card3 = new Card();
		$card3->setId(31);
		$card9 = new Card();
		$card9->setId(91);

		// Each board is queried with its own board id AND its own viewer context -
		// the visibility scope inside findSummariesByBoard uses that context.
		$this->cardMapper->expects($this->exactly(2))
			->method('findSummariesByBoard')
			->willReturnCallback(function (int $boardId, ViewerContext $viewer) use ($ctx3, $ctx9, $card3, $card9): array {
				if ($boardId === 3) {
					$this->assertSame($ctx3, $viewer);
					return [$card3];
				}
				$this->assertSame($ctx9, $viewer);
				return [$card9];
			});

		$this->cardSummaryService->expects($this->exactly(2))
			->method('serialize')
			->willReturnCallback(function (int $boardId, array $cards, ViewerContext $viewer): array {
				// The board-3 card carries an assignee; board-9 none. Only 'bob' is
				// ever seen, so only 'bob' can enter the participant catalog.
				$assignees = $boardId === 3 ? ['bob'] : [];
				return array_map(
					static fn (Card $c): array => ['id' => $c->getId(), 'boardId' => $boardId, 'assigneeIds' => $assignees],
					$cards,
				);
			});

		$this->labelMapper->expects($this->exactly(2))
			->method('findByBoard')
			->willReturnCallback(fn (int $boardId): array => $boardId === 3
				? [$this->label(7, 3, 'Urgent')]
				: [$this->label(8, 9, 'Later')]);

		$bob = $this->createMock(IUser::class);
		$bob->method('getDisplayName')->willReturn('Bob Baker');
		$this->userManager->method('get')->with('bob')->willReturn($bob);

		$result = $this->service->findMine('alice');

		// Each summary carries the board title for the cross-board grouping header.
		$this->assertSame(
			[
				['id' => 31, 'boardId' => 3, 'assigneeIds' => ['bob'], 'boardTitle' => 'Board 3'],
				['id' => 91, 'boardId' => 9, 'assigneeIds' => [], 'boardTitle' => 'Board 9'],
			],
			$result['cards'],
		);
		// Labels aggregated across the readable set, deduped by id.
		$this->assertSame(
			[
				['id' => 7, 'title' => 'Urgent', 'color' => 'ff0000'],
				['id' => 8, 'title' => 'Later', 'color' => 'ff0000'],
			],
			$result['labels'],
		);
		// Only assignees actually present on the returned cards are resolved.
		$this->assertSame(
			[['uid' => 'bob', 'displayName' => 'Bob Baker']],
			$result['participants'],
		);
	}

	public function testFindMineWithNoReadableBoardsReturnsEmptyAndNeverQueries(): void {
		$this->boardService->method('findAll')->willReturn([]);
		// No readable board => no per-board query, no enrichment, no leak surface.
		$this->boardAccess->expects($this->never())->method('contextFor');
		$this->cardMapper->expects($this->never())->method('findSummariesByBoard');
		$this->cardSummaryService->expects($this->never())->method('serialize');
		$this->labelMapper->expects($this->never())->method('findByBoard');

		$this->assertSame(
			['cards' => [], 'labels' => [], 'participants' => []],
			$this->service->findMine('bob'),
		);
	}
}
