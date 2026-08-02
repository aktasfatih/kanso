<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Project;
use OCA\Kanso\Db\ProjectComment;
use OCA\Kanso\Db\ProjectCommentMapper;
use OCA\Kanso\Db\ProjectMapper;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\ProjectCommentService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProjectCommentServiceTest extends TestCase {
	private ProjectCommentMapper&MockObject $commentMapper;
	private ProjectMapper&MockObject $projectMapper;
	private ProjectCommentService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->commentMapper = $this->createMock(ProjectCommentMapper::class);
		$this->projectMapper = $this->createMock(ProjectMapper::class);
		$this->service = new ProjectCommentService(
			$this->commentMapper,
			$this->projectMapper,
		);
	}

	private function project(string $owner = 'alice'): Project {
		$p = new Project();
		$p->setId(5);
		$p->setTitle('Q3 initiative');
		$p->setOwner($owner);
		$p->setCreatedAt(100);
		return $p;
	}

	private function comment(int $id, int $projectId = 5, ?int $parentId = null, string $author = 'alice', string $body = 'hello'): ProjectComment {
		$c = new ProjectComment();
		$c->setId($id);
		$c->setProjectId($projectId);
		$c->setParentCommentId($parentId);
		$c->setAuthor($author);
		$c->setBody($body);
		$c->setCreatedAt(1000);
		$c->setEditedAt(0);
		$c->setDeletedAt(0);
		return $c;
	}

	// ---- listForProject ----------------------------------------------------

	public function testListForProjectReturnsThreadForOwner(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$thread = [$this->comment(1), $this->comment(2)];
		$this->commentMapper->method('findByProject')->with(5)->willReturn($thread);

		self::assertSame($thread, $this->service->listForProject(5, 'alice'));
	}

	public function testListForProjectRejectsNonOwner(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->commentMapper->expects(self::never())->method('findByProject');

		$this->expectException(NotPermittedException::class);
		$this->service->listForProject(5, 'mallory');
	}

	// ---- addComment --------------------------------------------------------

	public function testAddTopLevelCommentInsertsForOwner(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->commentMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(function (ProjectComment $c): ProjectComment {
				self::assertSame('Hello world', $c->getBody());
				self::assertNull($c->getParentCommentId());
				self::assertSame('alice', $c->getAuthor());
				self::assertSame(5, $c->getProjectId());
				return $c;
			});

		$this->service->addComment(5, '  Hello world  ', null, 'alice');
	}

	public function testAddCommentRejectsNonOwner(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->commentMapper->expects(self::never())->method('insert');

		$this->expectException(NotPermittedException::class);
		$this->service->addComment(5, 'nope', null, 'mallory');
	}

	public function testAddReplyToTopLevelCommentSucceeds(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->commentMapper->method('find')->with(50)->willReturn($this->comment(50, 5, null));
		$this->commentMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(function (ProjectComment $c): ProjectComment {
				self::assertSame(50, $c->getParentCommentId());
				return $c;
			});

		$this->service->addComment(5, 'a reply', 50, 'alice');
	}

	public function testAddCommentRejectsEmptyBody(): void {
		$this->commentMapper->expects(self::never())->method('insert');
		$this->expectException(InvalidInputException::class);
		$this->service->addComment(5, '   ', null, 'alice');
	}

	public function testAddCommentRejectsOverlongBody(): void {
		$this->commentMapper->expects(self::never())->method('insert');
		$this->expectException(InvalidInputException::class);
		$this->service->addComment(5, str_repeat('x', 10001), null, 'alice');
	}

	public function testAddReplyToAReplyIsRejected(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		// The chosen parent is itself a reply (has a parent) - one level only.
		$this->commentMapper->method('find')->with(50)->willReturn($this->comment(50, 5, 40));
		$this->commentMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('one level');
		$this->service->addComment(5, 'nested', 50, 'alice');
	}

	public function testAddReplyToCommentOnAnotherProjectIsRejected(): void {
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->commentMapper->method('find')->with(50)->willReturn($this->comment(50, 77, null));
		$this->commentMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('different project');
		$this->service->addComment(5, 'reply', 50, 'alice');
	}

	// ---- editComment -------------------------------------------------------

	public function testEditCommentByOwnerUpdatesBodyAndStampsEditedAt(): void {
		$comment = $this->comment(50, 5, null, 'alice', 'old');
		$this->commentMapper->method('find')->with(50)->willReturn($comment);
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->commentMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (ProjectComment $c): ProjectComment {
				self::assertSame('new body', $c->getBody());
				self::assertGreaterThan(0, $c->getEditedAt());
				return $c;
			});

		$this->service->editComment(50, 'new body', 'alice');
	}

	public function testEditCommentRejectsNonOwner(): void {
		$comment = $this->comment(50, 5, null, 'alice', 'old');
		$this->commentMapper->method('find')->with(50)->willReturn($comment);
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->commentMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->editComment(50, 'hijack', 'mallory');
	}

	public function testEditCommentNoOpWritesNothing(): void {
		$comment = $this->comment(50, 5, null, 'alice', 'same');
		$this->commentMapper->method('find')->with(50)->willReturn($comment);
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->commentMapper->expects(self::never())->method('update');

		$this->service->editComment(50, 'same', 'alice');
	}

	// ---- deleteComment -----------------------------------------------------

	public function testDeleteTopLevelCommentByOwnerCascadesToReplies(): void {
		$comment = $this->comment(50, 5, null, 'alice');
		$this->commentMapper->method('find')->with(50)->willReturn($comment);
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));

		$this->commentMapper->expects(self::once())
			->method('softDeleteRepliesOf')
			->with(50, self::greaterThan(0));
		$this->commentMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (ProjectComment $c): ProjectComment {
				self::assertSame(50, $c->getId());
				self::assertGreaterThan(0, $c->getDeletedAt());
				return $c;
			});

		$this->service->deleteComment(50, 'alice');
	}

	public function testDeleteReplyDoesNotCascade(): void {
		$comment = $this->comment(60, 5, 50, 'alice');
		$this->commentMapper->method('find')->with(60)->willReturn($comment);
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));

		$this->commentMapper->expects(self::never())->method('softDeleteRepliesOf');
		$this->commentMapper->expects(self::once())->method('update')->willReturnArgument(0);

		$this->service->deleteComment(60, 'alice');
	}

	public function testDeleteCommentRejectsNonOwner(): void {
		$comment = $this->comment(50, 5, null, 'alice');
		$this->commentMapper->method('find')->with(50)->willReturn($comment);
		$this->projectMapper->method('find')->with(5)->willReturn($this->project('alice'));
		$this->commentMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->deleteComment(50, 'mallory');
	}

	public function testEditRejectsDeletedComment(): void {
		$comment = $this->comment(50, 5, null, 'alice');
		$comment->setDeletedAt(1234);
		$this->commentMapper->method('find')->with(50)->willReturn($comment);
		$this->commentMapper->expects(self::never())->method('update');

		$this->expectException(DoesNotExistException::class);
		$this->service->editComment(50, 'x', 'alice');
	}
}
