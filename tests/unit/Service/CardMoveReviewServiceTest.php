<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardReview;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\ReviewType;
use OCA\Kanso\Db\ReviewTypeMapper;
use OCA\Kanso\Service\CardMoveReviewService;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\PermissionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CardMoveReviewServiceTest extends TestCase {
	private CardReviewMapper&MockObject $reviews;
	private ReviewTypeMapper&MockObject $types;
	private PermissionService&MockObject $permissions;
	private CardVisibilityGuard&MockObject $visibility;
	private CardMoveReviewService $service;
	private Card $target;
	private Board $board;

	protected function setUp(): void {
		$this->reviews = $this->createMock(CardReviewMapper::class);
		$this->types = $this->createMock(ReviewTypeMapper::class);
		$this->permissions = $this->createMock(PermissionService::class);
		$this->visibility = $this->createMock(CardVisibilityGuard::class);
		$this->permissions->method('getPermissions')->willReturn(PermissionService::PERMISSION_READ);
		$this->visibility->method('isVisible')->willReturn(true);
		$this->target = new Card();
		$this->target->setId(42);
		$this->board = new Board();
		$this->board->setId(2);
		$this->board->setAllowAiActors(true);
		$this->service = new CardMoveReviewService($this->reviews, $this->types, $this->permissions, $this->visibility);
	}

	private function review(int $typeId, ?string $actorName = null): CardReview {
		$review = new CardReview();
		$review->setId(5);
		$review->setCardId(9);
		$review->setReviewer('bob');
		$review->setRequestedBy('alice');
		$review->setState(CardReview::STATE_CHANGES_REQUESTED);
		$review->setReviewTypeId($typeId);
		$review->setActorName($actorName);
		$review->setCreatedAt(123);
		$review->setNotifiedAt(456);
		return $review;
	}

	private function type(int $id, int $boardId, int $stage = 1): ReviewType {
		$type = new ReviewType();
		$type->setId($id);
		$type->setBoardId($boardId);
		$type->setTitle('QA');
		$type->setColor('e01e01');
		$type->setStage($stage);
		return $type;
	}

	public function testTransfersReviewAndPreservesItsStateAndAttribution(): void {
		$review = $this->review(3, 'Agent');
		$this->reviews->method('findByCard')->with(9)->willReturn([$review]);
		$this->types->method('findByBoard')->willReturnMap([[1, [$this->type(3, 1)]], [2, [$this->type(8, 2)]]]);
		$this->reviews->expects(self::once())->method('update')->with(self::callback(static function (CardReview $moved): bool {
			self::assertSame(42, $moved->getCardId());
			self::assertSame(8, $moved->getReviewTypeId());
			self::assertSame('bob', $moved->getReviewer());
			self::assertSame('alice', $moved->getRequestedBy());
			self::assertSame('Agent', $moved->getActorName());
			self::assertSame(CardReview::STATE_CHANGES_REQUESTED, $moved->getState());
			self::assertSame(123, $moved->getCreatedAt());
			self::assertSame(456, $moved->getNotifiedAt());
			return true;
		}))->willReturn($review);
		$this->service->transfer(9, 1, $this->target, $this->board);
	}

	public function testUntypedReviewRetainsTypeZero(): void {
		$review = $this->review(0);
		$this->reviews->method('findByCard')->willReturn([$review]);
		$this->reviews->expects(self::once())->method('update')->willReturn($review);
		$this->service->transfer(9, 1, $this->target, $this->board);
		self::assertSame(0, $review->getReviewTypeId());
	}

	public function testRejectsMissingOrIncompatibleReviewTypeWithoutMutatingRows(): void {
		$this->reviews->method('findByCard')->willReturn([$this->review(3)]);
		$this->types->method('findByBoard')->willReturnMap([[1, [$this->type(3, 1)]], [2, [$this->type(8, 2, 2)]]]);
		$this->reviews->expects(self::never())->method('update');
		$this->expectException(InvalidInputException::class);
		$this->service->transfer(9, 1, $this->target, $this->board);
	}

	public function testRejectsAmbiguousReviewTypes(): void {
		$this->reviews->method('findByCard')->willReturn([$this->review(3)]);
		$this->types->method('findByBoard')->willReturnMap([[1, [$this->type(3, 1)]], [2, [$this->type(8, 2), $this->type(9, 2)]]]);
		$this->reviews->expects(self::never())->method('update');
		$this->expectException(InvalidInputException::class);
		$this->service->transfer(9, 1, $this->target, $this->board);
	}

	public function testRejectsHiddenCardForReviewer(): void {
		$this->reviews->method('findByCard')->willReturn([$this->review(0)]);
		$this->visibility = $this->createMock(CardVisibilityGuard::class);
		$this->visibility->method('isVisible')->willReturn(false);
		$this->service = new CardMoveReviewService($this->reviews, $this->types, $this->permissions, $this->visibility);
		$this->reviews->expects(self::never())->method('update');
		$this->expectException(InvalidInputException::class);
		$this->service->transfer(9, 1, $this->target, $this->board);
	}

	public function testRejectsAiAttributionWhenTargetBoardForbidsIt(): void {
		$this->board->setAllowAiActors(false);
		$this->reviews->method('findByCard')->willReturn([$this->review(0, 'Agent')]);
		$this->reviews->expects(self::never())->method('update');
		$this->expectException(InvalidInputException::class);
		$this->service->transfer(9, 1, $this->target, $this->board);
	}
}
