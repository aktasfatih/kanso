<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\AclMapper;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeMapper;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\NotifyPush\Queue\IQueue;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class ChangeNotifierTest extends TestCase {
	private ChangeMapper&MockObject $changeMapper;
	private BoardMapper&MockObject $boardMapper;
	private AclMapper&MockObject $aclMapper;
	private IGroupManager&MockObject $groupManager;
	private ContainerInterface&MockObject $container;
	private LoggerInterface&MockObject $logger;
	private ChangeNotifier $notifier;

	protected function setUp(): void {
		parent::setUp();
		$this->changeMapper = $this->createMock(ChangeMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->aclMapper = $this->createMock(AclMapper::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->notifier = new ChangeNotifier(
			$this->changeMapper,
			$this->boardMapper,
			$this->aclMapper,
			$this->groupManager,
			$this->container,
			$this->logger
		);
	}

	private function board(int $id = 1, string $owner = 'alice'): Board {
		$board = new Board();
		$board->setId($id);
		$board->setOwner($owner);
		$board->setDeletedAt(0);
		return $board;
	}

	private function userAcl(string $uid): Acl {
		$acl = new Acl();
		$acl->setParticipantType(Acl::TYPE_USER);
		$acl->setParticipant($uid);
		return $acl;
	}

	private function groupAcl(string $gid): Acl {
		$acl = new Acl();
		$acl->setParticipantType(Acl::TYPE_GROUP);
		$acl->setParticipant($gid);
		return $acl;
	}

	private function user(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	public function testNotifyInsertsChangeRow(): void {
		$change = new Change();
		$this->changeMapper->expects(self::once())
			->method('insertChange')
			->with(
				1,
				Change::ENTITY_CARD,
				7,
				Change::ACTION_UPDATE,
				'alice',
				self::greaterThan(0)
			)
			->willReturn($change);
		// notify_push unavailable - the change row must land regardless.
		$this->container->method('get')->willThrowException(new \Exception('no such service'));

		self::assertSame($change, $this->notifier->notify(1, Change::ENTITY_CARD, 7, Change::ACTION_UPDATE, 'alice'));
	}

	public function testRecordChangeInsertsRowAndEmitsNoPush(): void {
		$change = new Change();
		$this->changeMapper->expects(self::once())
			->method('insertChange')
			->with(
				1,
				Change::ENTITY_CARD,
				7,
				Change::ACTION_UPDATE,
				'alice',
				self::greaterThan(0),
				Change::VERB_UPDATED
			)
			->willReturn($change);
		// recordChange must NOT touch the push path at all - no queue lookup, no
		// recipient resolution (that is pushBoardChanged's job).
		$this->container->expects(self::never())->method('get');
		$this->boardMapper->expects(self::never())->method('find');

		self::assertSame(
			$change,
			$this->notifier->recordChange(1, Change::ENTITY_CARD, 7, Change::ACTION_UPDATE, 'alice', Change::VERB_UPDATED)
		);
	}

	public function testPushBoardChangedFansOutWithoutRecordingAChangeRow(): void {
		// pushBoardChanged is push-only: it never writes a change row.
		$this->changeMapper->expects(self::never())->method('insertChange');
		$this->boardMapper->method('find')->with(1)->willReturn($this->board(1, 'alice'));
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([$this->userAcl('bob')]);

		$pushed = [];
		$queue = $this->createMock(IQueue::class);
		$queue->expects(self::exactly(2))
			->method('push')
			->willReturnCallback(static function (string $channel, $event) use (&$pushed): void {
				$pushed[] = $event['user'];
			});
		$this->container->method('get')->willReturn($queue);

		$this->notifier->pushBoardChanged(1);

		self::assertSame(['alice', 'bob'], $pushed);
	}

	public function testEmitsOnePushPerRecipientIncludingExpandedGroups(): void {
		$this->changeMapper->method('insertChange')->willReturn(new Change());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board(1, 'alice'));
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([
			$this->userAcl('bob'),
			$this->groupAcl('devs'),
		]);
		// 'bob' is reachable both directly and via the group - deduplicated.
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$this->user('bob'), $this->user('carol')]);
		$this->groupManager->method('get')->with('devs')->willReturn($group);

		$queue = $this->createMock(IQueue::class);
		$pushed = [];
		$queue->expects(self::exactly(3))
			->method('push')
			->willReturnCallback(static function (string $channel, $event) use (&$pushed): void {
				self::assertSame('notify_custom', $channel);
				$pushed[] = $event;
			});
		$this->container->expects(self::once())
			->method('get')
			->with('OCA\NotifyPush\Queue\IQueue')
			->willReturn($queue);

		$this->notifier->notify(1, Change::ENTITY_CARD, 7, Change::ACTION_UPDATE, 'alice');

		self::assertSame([
			['user' => 'alice', 'message' => 'kanso_board_changed', 'body' => ['boardId' => 1]],
			['user' => 'bob', 'message' => 'kanso_board_changed', 'body' => ['boardId' => 1]],
			['user' => 'carol', 'message' => 'kanso_board_changed', 'body' => ['boardId' => 1]],
		], $pushed);
	}

	public function testUnavailableQueueIsCachedPerRequest(): void {
		$this->changeMapper->method('insertChange')->willReturn(new Change());
		$this->container->expects(self::once())
			->method('get')
			->willThrowException(new \Exception('notify_push is not installed'));
		$this->boardMapper->expects(self::never())->method('find');

		$this->notifier->notify(1, Change::ENTITY_BOARD, 1, Change::ACTION_CREATE, 'alice');
		$this->notifier->notify(1, Change::ENTITY_BOARD, 1, Change::ACTION_UPDATE, 'alice');
	}

	public function testPushFailureNeverPropagates(): void {
		$change = new Change();
		$this->changeMapper->method('insertChange')->willReturn($change);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->aclMapper->method('findByBoard')->with(1)->willReturn([]);

		$queue = $this->createMock(IQueue::class);
		$queue->method('push')->willThrowException(new \RuntimeException('redis down'));
		$this->container->method('get')->willReturn($queue);
		$this->logger->expects(self::once())->method('debug');

		self::assertSame($change, $this->notifier->notify(1, Change::ENTITY_BOARD, 1, Change::ACTION_UPDATE, 'alice'));
	}

	public function testRecipientLookupFailureNeverPropagates(): void {
		$change = new Change();
		$this->changeMapper->method('insertChange')->willReturn($change);
		$this->container->method('get')->willReturn($this->createMock(IQueue::class));
		$this->boardMapper->method('find')->willThrowException(new \RuntimeException('db hiccup'));
		$this->logger->expects(self::once())->method('debug');

		self::assertSame($change, $this->notifier->notify(1, Change::ENTITY_BOARD, 1, Change::ACTION_UPDATE, 'alice'));
	}

	public function testQueueWithoutPushMethodIsTreatedAsUnavailable(): void {
		$this->changeMapper->method('insertChange')->willReturn(new Change());
		$this->container->expects(self::once())
			->method('get')
			->willReturn(new \stdClass());
		$this->boardMapper->expects(self::never())->method('find');

		$this->notifier->notify(1, Change::ENTITY_BOARD, 1, Change::ACTION_CREATE, 'alice');
	}
}
