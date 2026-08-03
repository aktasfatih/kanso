<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\CommentController;
use OCA\Kanso\Db\Comment;
use OCA\Kanso\Db\CommentReactionMapper;
use OCA\Kanso\Service\CommentService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The reaction-aggregation contract of the comment list payload (#3550): each
 * serialized comment carries a `reactions` summary folding the raw reaction rows
 * into per-emoji count + a `mine` flag + reactor display names.
 */
class CommentControllerTest extends TestCase {
	private CommentService&MockObject $commentService;
	private CommentReactionMapper&MockObject $reactionMapper;
	private IUserManager&MockObject $userManager;
	private CommentController $controller;

	protected function setUp(): void {
		parent::setUp();
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->commentService = $this->createMock(CommentService::class);
		$this->reactionMapper = $this->createMock(CommentReactionMapper::class);

		$me = $this->createMock(IUser::class);
		$me->method('getUID')->willReturn('alice');
		$userSession->method('getUser')->willReturn($me);

		// Display names: uid uppercased-ish so we can assert resolution happened.
		$this->userManager->method('get')->willReturnCallback(function (string $uid): ?IUser {
			$u = $this->createMock(IUser::class);
			$u->method('getDisplayName')->willReturn(ucfirst($uid));
			return $u;
		});

		$this->controller = new CommentController(
			'kanso',
			$request,
			$userSession,
			$this->userManager,
			$this->commentService,
			$this->reactionMapper,
		);
	}

	private function comment(int $id, string $author = 'bob'): Comment {
		$c = new Comment();
		$c->setId($id);
		$c->setCardId(9);
		$c->setParentCommentId(null);
		$c->setAuthor($author);
		$c->setBody('hi');
		$c->setCreatedAt(1000);
		$c->setEditedAt(0);
		$c->setDeletedAt(0);
		return $c;
	}

	public function testIndexAggregatesReactionsWithCountAndMineFlag(): void {
		$this->commentService->method('listForCard')->with(9, 'alice')->willReturn([
			$this->comment(50),
			$this->comment(51),
		]);
		// Comment 50: 👍 from bob + alice (alice=me), ❤️ from carol.
		// Comment 51: 👍 from carol only.
		$this->reactionMapper->method('findByComments')->with([50, 51])->willReturn([
			['commentId' => 50, 'uid' => 'bob', 'emoji' => '👍'],
			['commentId' => 50, 'uid' => 'alice', 'emoji' => '👍'],
			['commentId' => 50, 'uid' => 'carol', 'emoji' => '❤️'],
			['commentId' => 51, 'uid' => 'carol', 'emoji' => '👍'],
		]);

		$response = $this->controller->index(9);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertCount(2, $data);

		// Comment 50 reactions.
		$byEmoji = [];
		foreach ($data[0]['reactions'] as $r) {
			$byEmoji[$r['emoji']] = $r;
		}
		self::assertSame(2, $byEmoji['👍']['count']);
		self::assertTrue($byEmoji['👍']['mine'], 'alice reacted 👍, so mine must be true');
		self::assertSame(['Bob', 'Alice'], $byEmoji['👍']['reactors']);
		self::assertSame(1, $byEmoji['❤️']['count']);
		self::assertFalse($byEmoji['❤️']['mine']);
		self::assertSame(['Carol'], $byEmoji['❤️']['reactors']);

		// Comment 51: one 👍 from carol, not mine.
		self::assertCount(1, $data[1]['reactions']);
		self::assertSame('👍', $data[1]['reactions'][0]['emoji']);
		self::assertSame(1, $data[1]['reactions'][0]['count']);
		self::assertFalse($data[1]['reactions'][0]['mine']);
	}

	public function testIndexEmitsEmptyReactionsArrayWhenNone(): void {
		$this->commentService->method('listForCard')->willReturn([$this->comment(50)]);
		$this->reactionMapper->method('findByComments')->willReturn([]);

		$data = $this->controller->index(9)->getData();
		self::assertSame([], $data[0]['reactions']);
	}
}
