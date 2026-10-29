<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardRelation;
use OCA\Kanso\Db\CardRelationMapper;
use OCA\Kanso\Db\RecurRule;
use OCA\Kanso\Db\RecurRuleMapper;
use OCA\Kanso\Service\CardMoveGraphService;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CardMoveGraphServiceTest extends TestCase {
	private CardMapper&MockObject $cards;
	private CardRelationMapper&MockObject $relations;
	private CardVisibilityGuard&MockObject $visibility;
	private RecurRuleMapper&MockObject $rules;
	private PermissionService&MockObject $permissions;
	private CardMoveGraphService $service;

	protected function setUp(): void {
		$this->cards = $this->createMock(CardMapper::class);
		$this->relations = $this->createMock(CardRelationMapper::class);
		$this->visibility = $this->createMock(CardVisibilityGuard::class);
		$this->rules = $this->createMock(RecurRuleMapper::class);
		$this->permissions = $this->createMock(PermissionService::class);
		$this->service = new CardMoveGraphService($this->cards, $this->relations, $this->visibility, $this->rules, $this->permissions);
	}

	private function board(): Board {
		$board = new Board();
		$board->setId(1);
		return $board;
	}

	private function card(int $id, ?int $parent = null, int $boardId = 1, int $deletedAt = 0): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId($boardId);
		$card->setParentCardId($parent);
		$card->setDeletedAt($deletedAt);
		return $card;
	}

	private function relation(int $id, int $from, int $to, string $type): CardRelation {
		$relation = new CardRelation();
		$relation->setId($id);
		$relation->setBoardId(1);
		$relation->setCardId($from);
		$relation->setOtherCardId($to);
		$relation->setType($type);
		$relation->setCreatedAt(123);
		return $relation;
	}

	private function recurrence(int $templateId, int $lastId): RecurRule {
		$rule = new RecurRule();
		$rule->setTemplateCardId($templateId);
		$rule->setLastSpawnedAt($lastId);
		return $rule;
	}

	public function testDiscoversRecurrenceTemplateAndLatestOccurrenceInEitherDirection(): void {
		$template = $this->card(10);
		$last = $this->card(11);
		$this->rules->method('findByBoard')->with(1)->willReturn([$this->recurrence(10, 11)]);
		$this->cards->method('find')->willReturnMap([[10, $template], [11, $last]]);
		$this->visibility->method('isVisible')->willReturn(true);
		$this->permissions->expects(self::exactly(2))->method('assertPermission')->with(self::isInstanceOf(Board::class), 'alice', PermissionService::PERMISSION_MANAGE);
		$fromTemplate = $this->service->discover($template, $this->board(), 'alice');
		$fromOccurrence = $this->service->discover($last, $this->board(), 'alice');
		self::assertSame([10, 11], array_map(static fn (Card $card): int => $card->getId(), $fromTemplate['cards']));
		self::assertSame([11, 10], array_map(static fn (Card $card): int => $card->getId(), $fromOccurrence['cards']));
	}

	public function testRejectsUnavailableLatestOccurrenceWithoutExposingItsIdentity(): void {
		$this->rules->method('findByBoard')->willReturn([$this->recurrence(10, 11)]);
		$this->cards->method('find')->with(11)->willThrowException(new DoesNotExistException('missing'));
		$this->visibility->method('isVisible')->willReturn(true);
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('A connected card is unavailable and cannot be moved');
		$this->service->discover($this->card(10), $this->board(), 'alice');
	}

	public function testRequiresManageBeforeFollowingRecurrenceEdge(): void {
		$this->rules->method('findByBoard')->willReturn([$this->recurrence(10, 11)]);
		$this->visibility->method('isVisible')->willReturn(true);
		$this->permissions->method('assertPermission')->willThrowException(new NotPermittedException());
		$this->cards->expects(self::never())->method('find');
		$this->expectException(NotPermittedException::class);
		$this->service->discover($this->card(10), $this->board(), 'alice');
	}

	public function testDiscoversAllParentsSiblingsAndRelationsOnlyOnce(): void {
		$parent = $this->card(1);
		$source = $this->card(2, 1);
		$sibling = $this->card(3, 1);
		$related = $this->card(4);
		$related->setArchived(true);
		$parent->setDescription('full parent');
		$sibling->setDescription('full sibling');
		$summary = $this->card(3, 1);
		$edge = $this->relation(42, 3, 4, CardRelation::TYPE_BLOCKS);
		$this->cards->method('find')->willReturnMap([[1, $parent], [3, $sibling], [4, $related]]);
		$this->cards->method('findChildren')->willReturnMap([[1, [$source, $summary]], [2, []], [3, []], [4, []]]);
		$this->relations->method('findTouching')->willReturnMap([[1, []], [2, []], [3, [$edge]], [4, [$edge]]]);
		$this->visibility->method('isVisible')->willReturn(true);

		$graph = $this->service->discover($source, $this->board(), 'alice');
		self::assertSame([2, 1, 3, 4], array_map(static fn (Card $card): int => $card->getId(), $graph['cards']));
		self::assertSame('full sibling', $graph['cards'][2]->getDescription());
		self::assertSame([$edge], $graph['relations']);
	}

	public function testRejectsHiddenConnectedCardsWithoutExposingTheirIdentity(): void {
		$source = $this->card(10);
		$hidden = $this->card(11);
		$edge = $this->relation(4, 10, 11, CardRelation::TYPE_RELATES);
		$this->cards->method('find')->with(11)->willReturn($hidden);
		$this->relations->method('findTouching')->willReturnMap([[10, [$edge]], [11, [$edge]]]);
		$this->visibility->method('isVisible')->willReturnCallback(static fn (Board $board, Card $card): bool => $card->getId() === 10);
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('A connected card is unavailable and cannot be moved');
		$this->service->discover($source, $this->board(), 'alice');
	}

	public function testRejectsMissingRelationEndpoints(): void {
		$source = $this->card(10);
		$edge = $this->relation(4, 10, 11, CardRelation::TYPE_BLOCKS);
		$this->relations->method('findTouching')->with(10)->willReturn([$edge]);
		$this->visibility->method('isVisible')->willReturn(true);
		$this->cards->method('find')->with(11)->willThrowException(new DoesNotExistException('missing'));
		$this->expectException(InvalidInputException::class);
		$this->service->discover($source, $this->board(), 'alice');
	}

	public function testRejectsDeletedRelationEndpoints(): void {
		$source = $this->card(10);
		$edge = $this->relation(4, 10, 11, CardRelation::TYPE_BLOCKS);
		$this->relations->method('findTouching')->with(10)->willReturn([$edge]);
		$this->visibility->method('isVisible')->willReturn(true);
		$this->cards->method('find')->with(11)->willReturn($this->card(11, null, 1, 123));
		$this->expectException(InvalidInputException::class);
		$this->service->discover($source, $this->board(), 'alice');
	}

	public function testRejectsConnectedCardsOnOtherBoards(): void {
		$source = $this->card(10);
		$edge = $this->relation(4, 10, 11, CardRelation::TYPE_BLOCKS);
		$this->relations->method('findTouching')->with(10)->willReturn([$edge]);
		$this->visibility->method('isVisible')->willReturn(true);
		$this->cards->method('find')->with(11)->willReturn($this->card(11, null, 2));
		$this->expectException(InvalidInputException::class);
		$this->service->discover($source, $this->board(), 'alice');
	}

	public function testRecreatesParentsAndRelationsWithNewIds(): void {
		$parent = $this->card(1);
		$child = $this->card(2, 1);
		$third = $this->card(3);
		$sourceEdges = [
			$this->relation(10, 1, 3, CardRelation::TYPE_BLOCKS),
			$this->relation(11, 2, 3, CardRelation::TYPE_RELATES),
		];
		$moved = [1 => $this->card(40), 2 => $this->card(90), 3 => $this->card(50)];
		$this->cards->expects(self::once())->method('update')->with(self::callback(static fn (Card $card): bool => $card->getId() === 90 && $card->getParentCardId() === 40))->willReturn($moved[2]);
		$inserted = [];
		$this->relations->method('insert')->willReturnCallback(function (CardRelation $relation) use (&$inserted): CardRelation {
			$inserted[] = [$relation->getBoardId(), $relation->getCardId(), $relation->getOtherCardId(), $relation->getType(), $relation->getCreatedAt()];
			return $relation;
		});

		$this->service->recreate([$parent, $child, $third], $sourceEdges, $moved, 2);
		self::assertSame([
			[2, 40, 50, CardRelation::TYPE_BLOCKS, 123],
			[2, 50, 90, CardRelation::TYPE_RELATES, 123],
		], $inserted);
	}

	public function testRejectsRecreationWhenAnyRelatedCardWasNotCopied(): void {
		$this->relations->expects(self::never())->method('insert');
		$this->expectException(InvalidInputException::class);
		$this->service->recreate([], [$this->relation(1, 1, 2, CardRelation::TYPE_BLOCKS)], [1 => $this->card(10)], 2);
	}
}
