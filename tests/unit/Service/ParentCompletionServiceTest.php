<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChecklistItem;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\ParentCompletionService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ParentCompletionServiceTest extends TestCase {
	private CardMapper&MockObject $cards;
	private BoardMapper&MockObject $boards;
	private ChecklistItemMapper&MockObject $items;
	private CardReviewMapper&MockObject $reviews;
	private ChangeNotifier&MockObject $notifier;
	private ParentCompletionService $service;

	protected function setUp(): void {
		$this->cards = $this->createMock(CardMapper::class);
		$this->boards = $this->createMock(BoardMapper::class);
		$this->items = $this->createMock(ChecklistItemMapper::class);
		$this->reviews = $this->createMock(CardReviewMapper::class);
		$this->notifier = $this->createMock(ChangeNotifier::class);
		$this->service = new ParentCompletionService($this->cards, $this->boards, $this->items, $this->reviews, $this->notifier);
	}

	private function board(bool $enabled = true): Board {
		$board = new Board();
		$board->setId(1);
		$board->setAutoCompleteParent($enabled);
		return $board;
	}

	private function card(int $id = 9, int $doneAt = 0, bool $archived = false): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId(1);
		$card->setDoneAt($doneAt);
		$card->setArchived($archived);
		return $card;
	}

	public function testLocksBeforeGatesAndCompletesWithDoneAndArchivedChildren(): void {
		$parent = $this->card();
		$events = [];
		$this->boards->method('find')->with(1)->willReturn($this->board());
		$this->cards->expects(self::once())->method('findForMutation')->with(9)
			->willReturnCallback(static function () use ($parent, &$events): Card {
				$events[] = 'lock';
				return $parent;
			});
		$children = [$this->card(20, 100), $this->card(21, 0, true)];
		$this->cards->method('findChildren')->with(9)->willReturnCallback(static function () use ($children, &$events): array {
			self::assertSame(['lock'], $events);
			$events[] = 'gates';
			return $children;
		});
		$item = new ChecklistItem();
		$item->setDone(true);
		$this->items->method('findByCard')->with(9)->willReturn([$item]);
		$this->cards->expects(self::once())->method('update')->with($parent)->willReturnArgument(0);
		$this->notifier->expects(self::once())->method('recordChange')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'alice', Change::VERB_UPDATED)->willReturn(new Change());
		$this->notifier->expects(self::once())->method('pushBoardChanged')->with(1);

		$this->service->maybeComplete(9, 1, 'alice');
		self::assertGreaterThan(0, $parent->getDoneAt());
		self::assertSame($parent->getDoneAt(), $parent->getLastModified());
		self::assertSame(0, $children[1]->getDoneAt());
		self::assertTrue($children[1]->getArchived());
	}

	public static function blockedCases(): array {
		return array_map(static fn (string $case): array => [$case], [
			'default-off', 'deleted-board', 'deleted-parent', 'completed-parent', 'other-board',
			'no-children', 'open-child', 'open-checklist', 'unapproved-review',
		]);
	}

	#[DataProvider('blockedCases')]
	public function testBlockedParentStaysUntouched(string $case): void {
		$board = $this->board($case !== 'default-off');
		$parent = $this->card();
		$children = [$this->card(20, 100)];
		if ($case === 'deleted-board') {
			$board->setDeletedAt(100);
		} elseif ($case === 'deleted-parent') {
			$parent->setDeletedAt(100);
		} elseif ($case === 'completed-parent') {
			$parent->setDoneAt(500);
		} elseif ($case === 'other-board') {
			$parent->setBoardId(2);
		} elseif ($case === 'no-children') {
			$children = [];
		} elseif ($case === 'open-child') {
			$children[] = $this->card(21);
		}
		$this->boards->method('find')->willReturn($board);
		if (in_array($case, ['default-off', 'deleted-board'], true)) {
			$this->cards->expects(self::never())->method('findForMutation');
			$this->cards->expects(self::never())->method('findChildren');
			$this->items->expects(self::never())->method('findByCard');
			$this->reviews->expects(self::never())->method('hasUnapprovedReviews');
		} else {
			$this->cards->method('findForMutation')->with(9)->willReturn($parent);
			$this->cards->method('findChildren')->with(9)->willReturn($children);
			$item = new ChecklistItem();
			$item->setDone($case !== 'open-checklist');
			$this->items->method('findByCard')->with(9)->willReturn([$item]);
			$this->reviews->method('hasUnapprovedReviews')->with(9)->willReturn($case === 'unapproved-review');
		}
		$this->cards->expects(self::never())->method('update');
		$this->notifier->expects(self::never())->method('recordChange');
		$this->notifier->expects(self::never())->method('pushBoardChanged');

		$this->service->maybeComplete(9, 1, 'alice');
		self::assertSame($case === 'completed-parent' ? 500 : 0, $parent->getDoneAt());
	}

	public function testMissingBoardIsIgnoredBeforeParentLock(): void {
		$this->boards->method('find')->willThrowException(new DoesNotExistException('Gone'));
		$this->cards->expects(self::never())->method('findForMutation');
		$this->notifier->expects(self::never())->method('recordChange');
		$this->service->maybeComplete(9, 1, 'alice');
	}

	public function testMissingParentIsIgnored(): void {
		$this->boards->method('find')->willReturn($this->board());
		$this->cards->method('findForMutation')->willThrowException(new DoesNotExistException('Gone'));
		$this->notifier->expects(self::never())->method('recordChange');
		$this->service->maybeComplete(9, 1, 'alice');
	}

	public function testSystemCompletionCanSuppressTheSeparatePush(): void {
		$this->boards->method('find')->willReturn($this->board());
		$this->cards->method('findForMutation')->willReturn($this->card());
		$this->cards->method('findChildren')->willReturn([$this->card(20, 100)]);
		$this->cards->method('update')->willReturnArgument(0);
		$this->notifier->expects(self::once())->method('recordChange')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, null, Change::VERB_UPDATED)->willReturn(new Change());
		$this->notifier->expects(self::never())->method('pushBoardChanged');
		$this->service->maybeComplete(9, 1, null, false);
	}
}
