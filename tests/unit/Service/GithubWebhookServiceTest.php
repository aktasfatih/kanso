<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardLink;
use OCA\Kanso\Db\CardLinkMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\CardLinkService;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\CardVisibilityScope;
use OCA\Kanso\Service\GithubWebhookService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GithubWebhookServiceTest extends TestCase {
	private const SECRET = 'topsecretkey';

	private BoardMapper&MockObject $boardMapper;
	private StackMapper&MockObject $stackMapper;
	private CardService&MockObject $cardService;
	private CardMapper&MockObject $cardMapper;
	private CardLinkService&MockObject $cardLinkService;
	private CardLinkMapper&MockObject $cardLinkMapper;
	private PermissionService&MockObject $permissionService;
	private ISecureRandom&MockObject $secureRandom;
	private IURLGenerator&MockObject $urlGenerator;
	private GithubWebhookService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->stackMapper = $this->createMock(StackMapper::class);
		$this->cardService = $this->createMock(CardService::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		// The egress gate loads linked cards to decide public-ness; default
		// fixture cards carry no visibility (-> public), keeping the response
		// shape of the pre-#3760 tests.
		$this->cardMapper->method('find')->willReturnCallback(fn (int $id): Card => $this->card($id));
		$this->cardLinkService = $this->createMock(CardLinkService::class);
		$this->cardLinkMapper = $this->createMock(CardLinkMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->service = new GithubWebhookService(
			$this->boardMapper,
			$this->stackMapper,
			$this->cardService,
			$this->cardMapper,
			$this->cardLinkService,
			$this->cardLinkMapper,
			$this->permissionService,
			new CardVisibilityScope(),
			$this->secureRandom,
			$this->urlGenerator,
		);
	}

	private function board(int $id = 1, ?string $secret = self::SECRET): Board {
		$b = new Board();
		$b->setId($id);
		$b->setOwner('alice');
		$b->setDeletedAt(0);
		$b->setWebhookSecret($secret);
		return $b;
	}

	private function card(int $id = 9, int $boardId = 1): Card {
		$c = new Card();
		$c->setId($id);
		$c->setBoardId($boardId);
		$c->setDeletedAt(0);
		return $c;
	}

	private function stack(int $id, int $role, int $boardId = 1, int $deletedAt = 0): Stack {
		$s = new Stack();
		$s->setId($id);
		$s->setRole($role);
		$s->setBoardId($boardId);
		$s->setDeletedAt($deletedAt);
		return $s;
	}

	private function sign(string $body): string {
		return 'sha256=' . hash_hmac('sha256', $body, self::SECRET);
	}

	private function prBody(string $action, string $branch, bool $merged = false, string $title = ''): string {
		return json_encode([
			'action' => $action,
			'pull_request' => [
				'head' => ['ref' => $branch],
				'html_url' => 'https://github.com/octo/app/pull/3',
				'merged' => $merged,
				'title' => $title,
			],
		]);
	}

	// ---- signature verification ------------------------------------------

	public function testRejectsInvalidSignature(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$body = $this->prBody('opened', 'kanso-9-x');

		$this->expectException(NotPermittedException::class);
		$this->service->handleWebhook(1, 'sha256=deadbeef', $body);
	}

	public function testRejectsMissingSignature(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());

		$this->expectException(NotPermittedException::class);
		$this->service->handleWebhook(1, '', $this->prBody('opened', 'kanso-9-x'));
	}

	public function testRejectsWhenWebhookDisabled(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board(1, null));
		$body = $this->prBody('opened', 'kanso-9-x');

		$this->expectException(NotPermittedException::class);
		// Even a "correctly signed" body must fail when no secret is configured.
		$this->service->handleWebhook(1, $this->sign($body), $body);
	}

	// ---- no-ops -----------------------------------------------------------

	public function testPingEventIsAcceptedNoop(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$body = json_encode(['zen' => 'Keep it simple', 'hook_id' => 1]);
		$this->cardService->expects(self::never())->method('move');

		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertFalse($result['handled']);
	}

	public function testValidSignatureNonArrayBodyIsNoop(): void {
		// A correctly-signed body that is a bare JSON scalar must not 500 (#3477).
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$body = '"just a string"';
		$this->cardService->expects(self::never())->method('move');

		self::assertFalse($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	public function testFormEncodedBodyIsRejectedNotSilentlyAccepted(): void {
		// A webhook created with GitHub's `application/x-www-form-urlencoded`
		// content type sends `payload=<urlencoded JSON>`, and GitHub signs it - so
		// it PASSES the HMAC check. Swallowing it as a no-op recorded a green 200
		// in the delivery log while nothing happened on the board; it must be
		// reported so the misconfiguration is diagnosable.
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$body = 'payload=' . urlencode($this->prBody('opened', 'kanso-9-x'));
		$this->cardService->expects(self::never())->method('move');

		$this->expectException(InvalidInputException::class);
		$this->service->handleWebhook(1, $this->sign($body), $body);
	}

	public function testUnknownBranchIsNoop(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$body = $this->prBody('opened', 'feature/not-a-kanso-branch');
		$this->cardService->expects(self::never())->method('move');

		self::assertFalse($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	public function testCardOnAnotherBoardIsIgnored(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardService->method('find')->with(9, 'alice')->willReturn($this->card(9, 2)); // board 2!
		$this->cardService->expects(self::never())->method('move');
		$body = $this->prBody('opened', 'kanso-9-x');

		self::assertFalse($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	// ---- auto-move --------------------------------------------------------

	public function testMergedPrMovesCardToDoneStack(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardService->method('find')->with(9, 'alice')->willReturn($this->card(9, 1));
		$this->stackMapper->method('findByBoardAndRole')->with(1, Stack::ROLE_DONE)
			->willReturn($this->stack(5, Stack::ROLE_DONE));
		$this->cardLinkService->expects(self::once())->method('addLink')
			->with(9, 'https://github.com/octo/app/pull/3', 'alice');
		$this->cardService->expects(self::once())->method('move')
			->with(9, 5, null, 'alice')->willReturn($this->card(9, 1));

		$body = $this->prBody('closed', 'kanso-9-fix', true);
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);

		self::assertTrue($result['handled']);
		self::assertTrue($result['moved']);
		self::assertSame(9, $result['cardId']);
	}

	/**
	 * The merge delivery is authoritative: the PR link's state must be stamped
	 * MERGED from the payload itself, with no read-time poll involved. Before
	 * this, addLink() only polled on INSERT, so the second delivery for a PR
	 * (opened -> merged) left the chip on whatever the last poll had seen.
	 */
	public function testMergedPrCachesLinkStateFromThePayload(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardService->method('find')->with(9, 'alice')->willReturn($this->card(9, 1));
		$this->stackMapper->method('findByBoardAndRole')->willReturn(null);

		$link = new CardLink();
		$link->setCardId(9);
		$link->setUrl('https://github.com/octo/app/pull/3');
		$link->setKind(CardLink::KIND_PR);
		$link->setState(CardLink::STATE_OPEN);
		$this->cardLinkService->method('addLink')->willReturn($link);

		$this->cardLinkMapper->expects(self::once())->method('update')
			->willReturnCallback(function (CardLink $l): CardLink {
				self::assertSame(CardLink::STATE_MERGED, $l->getState());
				self::assertSame('Fix the thing', $l->getTitle());
				self::assertGreaterThan(0, $l->getLastPolled());
				return $l;
			});

		$body = json_encode([
			'action' => 'closed',
			'pull_request' => [
				'head' => ['ref' => 'kanso-9-fix'],
				'html_url' => 'https://github.com/octo/app/pull/3',
				'title' => 'Fix the thing',
				'state' => 'closed',
				'merged' => true,
			],
		]);
		self::assertTrue($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	/**
	 * A merge reaches us in three spellings across forge generations - the
	 * `merged` boolean, a non-empty `merged_at`, or a `merged` action. All three
	 * must read as MERGED, or a merge is indistinguishable from a plain close
	 * (both report `state: closed`).
	 *
	 * @param array<string, mixed> $extra
	 */
	#[DataProvider('mergeSpellingProvider')]
	public function testEveryMergeSpellingMovesToDone(string $action, array $extra): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardService->method('find')->with(9, 'alice')->willReturn($this->card(9, 1));
		$this->stackMapper->method('findByBoardAndRole')->with(1, Stack::ROLE_DONE)
			->willReturn($this->stack(5, Stack::ROLE_DONE));
		$this->cardService->expects(self::once())->method('move')
			->with(9, 5, null, 'alice')->willReturn($this->card(9, 1));

		$body = json_encode([
			'action' => $action,
			'pull_request' => array_merge([
				'head' => ['ref' => 'kanso-9-fix'],
				'html_url' => 'https://github.com/octo/app/pull/3',
				'state' => 'closed',
			], $extra),
		]);
		self::assertTrue($this->service->handleWebhook(1, $this->sign($body), $body)['moved']);
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function mergeSpellingProvider(): array {
		return [
			'merged boolean' => ['closed', ['merged' => true]],
			'merged_at timestamp' => ['closed', ['merged_at' => '2026-09-04T10:00:00Z']],
			'merged action' => ['merged', []],
		];
	}

	public function testOpenedPrMovesCardToReviewStack(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardService->method('find')->with(9, 'alice')->willReturn($this->card(9, 1));
		$this->stackMapper->method('findByBoardAndRole')->with(1, Stack::ROLE_REVIEW)
			->willReturn($this->stack(4, Stack::ROLE_REVIEW));
		$this->cardService->expects(self::once())->method('move')->with(9, 4, null, 'alice')
			->willReturn($this->card(9, 1));

		$body = $this->prBody('opened', 'kanso-9-x');
		self::assertTrue($this->service->handleWebhook(1, $this->sign($body), $body)['moved']);
	}

	public function testClosedUnmergedPrDoesNotMove(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardService->method('find')->with(9, 'alice')->willReturn($this->card(9, 1));
		$this->cardService->expects(self::never())->method('move');

		$body = $this->prBody('closed', 'kanso-9-x', false); // closed but not merged
		self::assertFalse($this->service->handleWebhook(1, $this->sign($body), $body)['moved']);
	}

	// ---- diagnostic reason -------------------------------------------------

	/**
	 * A silent `handled: false` is undiagnosable in the forge's delivery log, so
	 * every business-level miss says why. These carry no board content - they
	 * describe the request - which is what keeps them inside the egress rule.
	 */
	public function testPingDeliveryReportsUnsupportedEvent(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$body = json_encode(['zen' => 'Keep it logically awesome.']);

		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertFalse($result['handled']);
		self::assertSame(GithubWebhookService::REASON_UNSUPPORTED_EVENT, $result['reason']);
	}

	public function testUnmatchedBranchReportsNoCardMatch(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$body = $this->prBody('opened', 'feature/unrelated');

		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertFalse($result['handled']);
		self::assertSame(GithubWebhookService::REASON_NO_CARD_MATCH, $result['reason']);
	}

	public function testMissingRoleStackReportsNoTargetStack(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardService->method('find')->with(9, 'alice')->willReturn($this->card(9, 1));
		$this->stackMapper->method('findByBoardAndRole')->willReturn(null);

		$body = $this->prBody('opened', 'kanso-9-x');
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertTrue($result['handled']);
		self::assertFalse($result['moved']);
		self::assertSame(GithubWebhookService::REASON_NO_TARGET_STACK, $result['reason']);
	}

	public function testIntakeOffReportsIntakeOff(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);

		$body = json_encode([
			'action' => 'opened',
			'issue' => ['html_url' => 'https://github.com/octo/app/issues/7', 'state' => 'open', 'title' => 'x'],
		]);
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertFalse($result['handled']);
		self::assertSame(GithubWebhookService::REASON_INTAKE_OFF, $result['reason']);
	}

	public function testNoTargetRoleStackDegradesToLinkOnly(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardService->method('find')->with(9, 'alice')->willReturn($this->card(9, 1));
		$this->stackMapper->method('findByBoardAndRole')->willReturn(null); // no done-role stack
		$this->cardLinkService->expects(self::once())->method('addLink');
		$this->cardService->expects(self::never())->method('move');

		$body = $this->prBody('closed', 'kanso-9-x', true);
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertTrue($result['handled']);
		self::assertFalse($result['moved']);
	}

	public function testMoveFailureIsSwallowed(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardService->method('find')->with(9, 'alice')->willReturn($this->card(9, 1));
		$this->stackMapper->method('findByBoardAndRole')->willReturn($this->stack(5, Stack::ROLE_DONE));
		$this->cardService->method('move')->willThrowException(new NotPermittedException('review gate'));

		$body = $this->prBody('closed', 'kanso-9-x', true);
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertTrue($result['handled']);
		self::assertFalse($result['moved']);
	}

	// ---- issues events ----------------------------------------------------

	private function link(int $id, int $cardId, string $url = 'https://github.com/octo/app/issues/7'): CardLink {
		$l = new CardLink();
		$l->setId($id);
		$l->setCardId($cardId);
		$l->setUrl($url);
		$l->setKind(CardLink::KIND_ISSUE);
		$l->setState(CardLink::STATE_OPEN);
		$l->setLastPolled(0);
		return $l;
	}

	/**
	 * @param array<int, array{name: string}> $labels
	 */
	private function issueBody(string $action, string $url = 'https://github.com/octo/app/issues/7', string $state = 'open', string $title = 'Crash on load', array $labels = []): string {
		return json_encode([
			'action' => $action,
			'issue' => [
				'html_url' => $url,
				'state' => $state,
				'title' => $title,
				'labels' => $labels,
			],
		]);
	}

	public function testIssueClosedMovesLinkedCardToDoneStack(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardLinkMapper->expects(self::once())->method('findByBoardAndUrls')
			->willReturnCallback(function (int $boardId, array $urls): array {
				self::assertSame(1, $boardId);
				self::assertContains('https://github.com/octo/app/issues/7', $urls);
				return [$this->link(11, 9)];
			});
		$this->stackMapper->method('findByBoardAndRole')->with(1, Stack::ROLE_DONE)
			->willReturn($this->stack(5, Stack::ROLE_DONE));
		$this->cardService->expects(self::once())->method('move')
			->with(9, 5, null, 'alice')->willReturn($this->card(9, 1));

		$body = $this->issueBody('closed', state: 'closed');
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);

		self::assertTrue($result['handled']);
		self::assertTrue($result['moved']);
		self::assertSame(9, $result['cardId']);
	}

	public function testIssueReopenedMovesCardBackToInProgressStack(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([$this->link(11, 9)]);
		$this->stackMapper->method('findByBoardAndRole')->with(1, Stack::ROLE_IN_PROGRESS)
			->willReturn($this->stack(3, Stack::ROLE_IN_PROGRESS));
		$this->cardService->expects(self::once())->method('move')
			->with(9, 3, null, 'alice')->willReturn($this->card(9, 1));

		$body = $this->issueBody('reopened');
		self::assertTrue($this->service->handleWebhook(1, $this->sign($body), $body)['moved']);
	}

	public function testIssueReopenedFallsBackToTodoStack(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([$this->link(11, 9)]);
		$this->stackMapper->method('findByBoardAndRole')->willReturnMap([
			[1, Stack::ROLE_IN_PROGRESS, null], // no in-progress stack
			[1, Stack::ROLE_TODO, $this->stack(2, Stack::ROLE_TODO)],
		]);
		$this->cardService->expects(self::once())->method('move')
			->with(9, 2, null, 'alice')->willReturn($this->card(9, 1));

		$body = $this->issueBody('reopened');
		self::assertTrue($this->service->handleWebhook(1, $this->sign($body), $body)['moved']);
	}

	public function testUnlinkedIssueIsNoop(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]); // nothing on this board
		$this->cardService->expects(self::never())->method('move');
		$this->cardLinkMapper->expects(self::never())->method('update');

		$body = $this->issueBody('closed', state: 'closed');
		self::assertFalse($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	public function testIssueClosedWithoutDoneStackUpdatesLinkButDoesNotMove(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([$this->link(11, 9)]);
		$this->stackMapper->method('findByBoardAndRole')->willReturn(null); // no done-role stack
		$this->cardService->expects(self::never())->method('move');
		$this->cardLinkMapper->expects(self::once())->method('update');

		$body = $this->issueBody('closed', state: 'closed');
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertTrue($result['handled']);
		self::assertFalse($result['moved']);
	}

	public function testIssueEventRefreshesCachedLinkStateAndTitle(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$link = $this->link(11, 9);
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([$link]);
		$this->stackMapper->method('findByBoardAndRole')->willReturn(null);
		$this->cardLinkMapper->expects(self::once())->method('update')
			->willReturnCallback(function (CardLink $l): CardLink {
				self::assertSame(CardLink::STATE_CLOSED, $l->getState());
				self::assertSame('Fixed crash', $l->getTitle());
				self::assertGreaterThan(0, $l->getLastPolled());
				return $l;
			});

		$body = $this->issueBody('closed', state: 'closed', title: 'Fixed crash');
		$this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertSame(CardLink::STATE_CLOSED, $link->getState());
	}

	public function testIssueCommentOnPrShapedIssueIsIgnored(): void {
		// An issue_comment delivery on a PR carries an `issue` whose html_url is
		// a /pull/ URL - not a Kanso issue link, so it must be a no-op.
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardLinkMapper->expects(self::never())->method('findByBoardAndUrls');
		$this->cardService->expects(self::never())->method('move');

		$body = $this->issueBody('created', url: 'https://github.com/octo/app/pull/3');
		self::assertFalse($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	public function testIssueEventRejectsInvalidSignature(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardLinkMapper->expects(self::never())->method('findByBoardAndUrls');

		$this->expectException(NotPermittedException::class);
		$this->service->handleWebhook(1, 'sha256=deadbeef', $this->issueBody('closed', state: 'closed'));
	}

	public function testIssueClosedMovesEveryLinkedCard(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardLinkMapper->method('findByBoardAndUrls')
			->willReturn([$this->link(11, 9), $this->link(12, 10)]);
		$this->stackMapper->method('findByBoardAndRole')->with(1, Stack::ROLE_DONE)
			->willReturn($this->stack(5, Stack::ROLE_DONE));
		$moves = [];
		$this->cardService->expects(self::exactly(2))->method('move')
			->willReturnCallback(function (int $cardId, int $stackId) use (&$moves): Card {
				$moves[] = [$cardId, $stackId];
				return $this->card($cardId, 1);
			});

		$body = $this->issueBody('closed', state: 'closed');
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertSame([[9, 5], [10, 5]], $moves);
		self::assertTrue($result['moved']);
		self::assertSame(9, $result['cardId']);
	}

	// ---- issue intake (#3752) ---------------------------------------------

	private function intakeBoard(int $stackId = 7, ?string $label = null): Board {
		$b = $this->board();
		$b->setWebhookIntakeStackId($stackId);
		$b->setWebhookIntakeLabel($label);
		return $b;
	}

	public function testOpenedIssueWithIntakeOffDoesNotCreateACard(): void {
		// No intake stack configured (the default): opened stays a plain no-op.
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->cardService->expects(self::never())->method('create');
		$this->cardLinkMapper->expects(self::never())->method('insert');

		$body = $this->issueBody('opened');
		self::assertFalse($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	public function testOpenedIssueIntakeCreatesLinkedCardInConfiguredStack(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->intakeBoard());
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->stackMapper->method('find')->with(7)->willReturn($this->stack(7, Stack::ROLE_TODO));
		$this->cardLinkMapper->method('existsByBoardAndUrls')->willReturnCallback(
			function (int $boardId, array $urls): bool {
				self::assertSame(1, $boardId);
				self::assertContains('https://github.com/octo/app/issues/7', $urls);
				return false;
			},
		);
		$this->cardService->expects(self::once())->method('create')
			->with(7, 'Crash on load', 'alice')->willReturn($this->card(42, 1));
		$this->cardLinkMapper->expects(self::once())->method('insert')
			->willReturnCallback(function (CardLink $l): CardLink {
				self::assertSame(42, $l->getCardId());
				self::assertSame('https://github.com/octo/app/issues/7', $l->getUrl());
				self::assertSame(CardLink::KIND_ISSUE, $l->getKind());
				// State/title cached straight from the payload - no poll.
				self::assertSame(CardLink::STATE_OPEN, $l->getState());
				self::assertSame('Crash on load', $l->getTitle());
				self::assertGreaterThan(0, $l->getLastPolled());
				return $l;
			});

		$body = $this->issueBody('opened');
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertTrue($result['handled']);
		self::assertTrue($result['created']);
		self::assertSame(42, $result['cardId']);
		self::assertFalse($result['moved']);
	}

	public function testIntakeTruncatesOverlongIssueTitle(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->intakeBoard());
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->stackMapper->method('find')->with(7)->willReturn($this->stack(7, Stack::ROLE_TODO));
		$this->cardLinkMapper->method('existsByBoardAndUrls')->willReturn(false);
		$this->cardService->expects(self::once())->method('create')
			->willReturnCallback(function (int $stackId, string $title): Card {
				self::assertSame(100, mb_strlen($title));
				return $this->card(42, 1);
			});

		$body = $this->issueBody('opened', title: str_repeat('x', 150));
		self::assertTrue($this->service->handleWebhook(1, $this->sign($body), $body)['created']);
	}

	public function testIntakeWhitespacePaddedTitleNeverReachesCreateEmpty(): void {
		// A title that truncates into trailing whitespace must still yield a
		// non-empty title (create() would otherwise reject it and drop the issue).
		$this->boardMapper->method('find')->with(1)->willReturn($this->intakeBoard());
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->stackMapper->method('find')->with(7)->willReturn($this->stack(7, Stack::ROLE_TODO));
		$this->cardLinkMapper->method('existsByBoardAndUrls')->willReturn(false);
		$this->cardService->expects(self::once())->method('create')
			->willReturnCallback(function (int $stackId, string $title): Card {
				self::assertNotSame('', $title);
				self::assertLessThanOrEqual(100, mb_strlen($title));
				self::assertSame($title, trim($title));
				return $this->card(42, 1);
			});

		$body = $this->issueBody('opened', title: 'x' . str_repeat(' ', 120) . 'y');
		self::assertTrue($this->service->handleWebhook(1, $this->sign($body), $body)['created']);
	}

	public function testIntakeLabelFilterMatchesCaseInsensitively(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->intakeBoard(7, 'bug'));
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->stackMapper->method('find')->with(7)->willReturn($this->stack(7, Stack::ROLE_TODO));
		$this->cardLinkMapper->method('existsByBoardAndUrls')->willReturn(false);
		$this->cardService->expects(self::once())->method('create')->willReturn($this->card(42, 1));

		$body = $this->issueBody('opened', labels: [['name' => 'Bug'], ['name' => 'triage']]);
		self::assertTrue($this->service->handleWebhook(1, $this->sign($body), $body)['created']);
	}

	public function testIntakeLabelFilterSkipsNonMatchingIssue(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->intakeBoard(7, 'bug'));
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->cardService->expects(self::never())->method('create');

		$body = $this->issueBody('opened', labels: [['name' => 'feature']]);
		self::assertFalse($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	public function testIntakeRedeliveryDoesNotCreateADuplicate(): void {
		// The alive lookup misses (e.g. the created card was archived or
		// trashed), but the any-state dedup lookup still finds the link -
		// a redelivered `opened` must not create a second card.
		$this->boardMapper->method('find')->with(1)->willReturn($this->intakeBoard());
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->stackMapper->method('find')->with(7)->willReturn($this->stack(7, Stack::ROLE_TODO));
		$this->cardLinkMapper->method('existsByBoardAndUrls')->willReturn(true);
		$this->cardService->expects(self::never())->method('create');

		$body = $this->issueBody('opened');
		self::assertFalse($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	public function testIntakeSkipsWhenIssueAlreadyLinkedOnAliveCard(): void {
		// An alive linked card exists: opened refreshes the cached state (the
		// pre-intake behavior) and never creates a duplicate.
		$this->boardMapper->method('find')->with(1)->willReturn($this->intakeBoard());
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([$this->link(11, 9)]);
		$this->cardService->expects(self::never())->method('create');
		$this->cardLinkMapper->expects(self::once())->method('update');

		$body = $this->issueBody('opened');
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertTrue($result['handled']);
		self::assertArrayNotHasKey('created', $result);
	}

	public function testIntakeStaleStackIsANoop(): void {
		// The configured stack now lives on another board (or was deleted):
		// intake degrades to a no-op instead of creating a card elsewhere.
		$this->boardMapper->method('find')->with(1)->willReturn($this->intakeBoard());
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->stackMapper->method('find')->with(7)->willReturn($this->stack(7, Stack::ROLE_TODO, 2)); // board 2!
		$this->cardService->expects(self::never())->method('create');

		$body = $this->issueBody('opened');
		self::assertFalse($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	public function testIntakeDeletedStackIsANoop(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->intakeBoard());
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->stackMapper->method('find')->with(7)
			->willReturn($this->stack(7, Stack::ROLE_TODO, 1, time())); // soft-deleted
		$this->cardService->expects(self::never())->method('create');

		$body = $this->issueBody('opened');
		self::assertFalse($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	public function testReopenedNeverCreatedIssueDoesNotIntake(): void {
		// Intake is `opened`-only: a reopened issue nobody linked stays a no-op.
		$this->boardMapper->method('find')->with(1)->willReturn($this->intakeBoard());
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->cardService->expects(self::never())->method('create');
		$this->cardService->expects(self::never())->method('move');

		$body = $this->issueBody('reopened');
		self::assertFalse($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	// ---- intake config (MANAGE) -------------------------------------------

	public function testSetIntakeConfigPersistsStackAndLabel(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->stackMapper->method('find')->with(7)->willReturn($this->stack(7, Stack::ROLE_TODO));
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc/webhook');
		$this->boardMapper->expects(self::once())->method('update')
			->willReturnCallback(function (Board $b): Board {
				self::assertSame(7, $b->getWebhookIntakeStackId());
				self::assertSame('bug', $b->getWebhookIntakeLabel());
				return $b;
			});

		$config = $this->service->setIntakeConfig(1, 7, ' bug ', 'alice');
		self::assertSame(7, $config['intakeStackId']);
		self::assertSame('bug', $config['intakeLabel']);
	}

	public function testSetIntakeConfigNullStackDisablesAndClearsLabel(): void {
		$board = $this->intakeBoard(7, 'bug');
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc/webhook');
		$this->boardMapper->expects(self::once())->method('update')
			->willReturnCallback(function (Board $b): Board {
				self::assertNull($b->getWebhookIntakeStackId());
				self::assertNull($b->getWebhookIntakeLabel());
				return $b;
			});

		$config = $this->service->setIntakeConfig(1, null, 'bug', 'alice');
		self::assertNull($config['intakeStackId']);
		self::assertSame('', $config['intakeLabel']);
	}

	public function testSetIntakeConfigRejectsForeignStack(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->with(7)->willReturn($this->stack(7, Stack::ROLE_TODO, 2)); // board 2!
		$this->boardMapper->expects(self::never())->method('update');

		$this->expectException(\OCA\Kanso\Service\InvalidInputException::class);
		$this->service->setIntakeConfig(1, 7, '', 'alice');
	}

	public function testSetIntakeConfigDeniedWithoutManage(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_MANAGE)
			->willThrowException(new NotPermittedException());
		$this->boardMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->setIntakeConfig(1, 7, '', 'mallory');
	}

	// ---- config (MANAGE) --------------------------------------------------

	public function testRotateSecretRequiresManageAndReturnsSecret(): void {
		$board = $this->board(1, null);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_MANAGE);
		$this->secureRandom->method('generate')->willReturn('newsecret123');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc/webhook');
		$this->boardMapper->expects(self::once())->method('update')
			->willReturnCallback(function (Board $b): Board {
				self::assertSame('newsecret123', $b->getWebhookSecret());
				return $b;
			});

		$result = $this->service->rotateSecret(1, 'alice');
		self::assertSame('newsecret123', $result['secret']);
		self::assertSame('https://nc/webhook', $result['payloadUrl']);
	}

	public function testRotateSecretDeniedWithoutManage(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException());
		$this->boardMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->rotateSecret(1, 'mallory');
	}

	public function testGetConfigReportsEnabledState(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc/webhook');

		$config = $this->service->getConfig(1, 'alice');
		self::assertTrue($config['enabled']);
		self::assertSame('https://nc/webhook', $config['payloadUrl']);
		// Intake defaults: off, no filter.
		self::assertNull($config['intakeStackId']);
		self::assertSame('', $config['intakeLabel']);
	}

	public function testGetConfigReportsIntakeSettings(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->intakeBoard(7, 'bug'));
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc/webhook');

		$config = $this->service->getConfig(1, 'alice');
		self::assertSame(7, $config['intakeStackId']);
		self::assertSame('bug', $config['intakeLabel']);
	}

	public function testDisableClearsSecret(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->boardMapper->expects(self::once())->method('update')
			->willReturnCallback(function (Board $b): Board {
				self::assertNull($b->getWebhookSecret());
				return $b;
			});

		$this->service->disable(1, 'alice');
	}

	// ---- egress visibility (#3760) ----------------------------------------

	public function testIssueResponseNeverNamesANonPublicCard(): void {
		// The 200 response goes to an EXTERNAL system (GitHub's delivery log):
		// a hidden (here: private) linked card is still processed - its move
		// runs as the board owner, visibility-gated in CardService - but its
		// id must not be confirmed outward. With no public card linked, the
		// response reports cardId 0.
		$hidden = $this->card(9, 1);
		$hidden->setVisibility(CardVisibilityScope::VISIBILITY_PRIVATE);
		$hidden->setOwner('alice');
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->cardMapper->method('find')->with(9)->willReturn($hidden);
		$service = new GithubWebhookService(
			$this->boardMapper,
			$this->stackMapper,
			$this->cardService,
			$this->cardMapper,
			$this->cardLinkService,
			$this->cardLinkMapper,
			$this->permissionService,
			new CardVisibilityScope(),
			$this->secureRandom,
			$this->urlGenerator,
		);

		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([$this->link(11, 9)]);
		$this->stackMapper->method('findByBoardAndRole')->with(1, Stack::ROLE_DONE)
			->willReturn($this->stack(5, Stack::ROLE_DONE));
		// The move still happens (owner-gated automation is not egress).
		$this->cardService->expects(self::once())->method('move')
			->with(9, 5, null, 'alice')->willReturn($this->card(9, 1));

		$body = $this->issueBody('closed', state: 'closed');
		$result = $service->handleWebhook(1, $this->sign($body), $body);

		self::assertTrue($result['handled']);
		self::assertTrue($result['moved']);
		self::assertSame(0, $result['cardId']);
	}

	public function testIssueResponseNamesTheFirstPublicCardWhenMixed(): void {
		// Two links: card 8 is provider-internal (hidden), card 9 public - the
		// response names only the public one.
		$internal = $this->card(8, 1);
		$internal->setVisibility(CardVisibilityScope::VISIBILITY_INTERNAL);
		$public = $this->card(9, 1);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->cardMapper->method('find')->willReturnCallback(
			static fn (int $id): Card => $id === 8 ? $internal : $public,
		);
		$service = new GithubWebhookService(
			$this->boardMapper,
			$this->stackMapper,
			$this->cardService,
			$this->cardMapper,
			$this->cardLinkService,
			$this->cardLinkMapper,
			$this->permissionService,
			new CardVisibilityScope(),
			$this->secureRandom,
			$this->urlGenerator,
		);

		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([
			$this->link(11, 8),
			$this->link(12, 9),
		]);
		$this->stackMapper->method('findByBoardAndRole')->willReturn(null);

		$body = $this->issueBody('closed', state: 'closed');
		$result = $service->handleWebhook(1, $this->sign($body), $body);

		self::assertSame(9, $result['cardId']);
	}

	// ---- PR title references (#9855) --------------------------------------

	private function prefixBoard(string $prefix = 'KAN'): Board {
		$b = $this->board();
		$b->setPrefix($prefix);
		return $b;
	}

	/** Rebuilds the service around a custom CardMapper (the egress gate's source). */
	private function serviceWithCardMapper(CardMapper $cardMapper): GithubWebhookService {
		return new GithubWebhookService(
			$this->boardMapper,
			$this->stackMapper,
			$this->cardService,
			$cardMapper,
			$this->cardLinkService,
			$this->cardLinkMapper,
			$this->permissionService,
			new CardVisibilityScope(),
			$this->secureRandom,
			$this->urlGenerator,
		);
	}

	public function testOpenedPrWithTitleReferenceLinksAndMovesThatCard(): void {
		// The headline case: a branch following no convention at all, the card
		// named only by the human reference the UI actually shows.
		$this->boardMapper->method('find')->with(1)->willReturn($this->prefixBoard('KANSO'));
		$this->cardService->expects(self::once())->method('findByRef')
			->with(1, 'KANSO-14', 'alice')->willReturn($this->card(77, 1));
		$this->stackMapper->method('findByBoardAndRole')->with(1, Stack::ROLE_REVIEW)
			->willReturn($this->stack(4, Stack::ROLE_REVIEW));
		$this->cardLinkService->expects(self::once())->method('addLink')
			->with(77, 'https://github.com/octo/app/pull/3', 'alice');
		$this->cardService->expects(self::once())->method('move')
			->with(77, 4, null, 'alice')->willReturn($this->card(77, 1));

		$body = $this->prBody('opened', 'my-random-branch', title: 'Fix the crash on load (KANSO-14)');
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);

		self::assertTrue($result['handled']);
		self::assertTrue($result['moved']);
		self::assertSame(77, $result['cardId']);
	}

	public function testMergedPrWithTitleReferenceMovesThatCardToDoneStack(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->prefixBoard('KANSO'));
		$this->cardService->method('findByRef')->with(1, 'KANSO-14', 'alice')
			->willReturn($this->card(77, 1));
		$this->stackMapper->method('findByBoardAndRole')->with(1, Stack::ROLE_DONE)
			->willReturn($this->stack(5, Stack::ROLE_DONE));
		$this->cardService->expects(self::once())->method('move')
			->with(77, 5, null, 'alice')->willReturn($this->card(77, 1));

		$body = $this->prBody('closed', 'my-random-branch', true, 'KANSO-14 fix the crash');
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);

		self::assertTrue($result['moved']);
		self::assertSame(77, $result['cardId']);
	}

	public function testTitleReferenceUsesTheBoardsDefaultPrefixWhenUnset(): void {
		// A board created before the prefix backfill falls back to the shared
		// default, exactly as CardService::findByRef does.
		$this->boardMapper->method('find')->with(1)->willReturn($this->board()); // no prefix
		$this->cardService->expects(self::once())->method('findByRef')
			->with(1, 'KAN-3', 'alice')->willReturn($this->card(31, 1));
		$this->stackMapper->method('findByBoardAndRole')->willReturn(null);

		$body = $this->prBody('opened', 'topic/whatever', title: 'KAN-3 tidy up');
		self::assertSame(31, $this->service->handleWebhook(1, $this->sign($body), $body)['cardId']);
	}

	public function testBranchOnlyPrIsUnchangedByTitleMatching(): void {
		// Regression: the pre-#9855 `kanso-<id>` path behaves exactly as before,
		// and a title with no reference costs no resolver call at all.
		$this->boardMapper->method('find')->with(1)->willReturn($this->prefixBoard('KANSO'));
		$this->cardService->method('find')->with(9, 'alice')->willReturn($this->card(9, 1));
		$this->cardService->expects(self::never())->method('findByRef');
		$this->stackMapper->method('findByBoardAndRole')->with(1, Stack::ROLE_REVIEW)
			->willReturn($this->stack(4, Stack::ROLE_REVIEW));
		$this->cardLinkService->expects(self::once())->method('addLink')->with(9, self::anything(), 'alice');
		$this->cardService->expects(self::once())->method('move')
			->with(9, 4, null, 'alice')->willReturn($this->card(9, 1));

		$body = $this->prBody('opened', 'kanso-9-fix', title: 'Fix the crash on load');
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);

		self::assertTrue($result['handled']);
		self::assertTrue($result['moved']);
		self::assertSame(9, $result['cardId']);
	}

	public function testBranchAndTitleReferenceBothLinkAndMove(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->prefixBoard('KANSO'));
		$this->cardService->method('find')->with(9, 'alice')->willReturn($this->card(9, 1));
		$this->cardService->method('findByRef')->with(1, 'KANSO-14', 'alice')
			->willReturn($this->card(77, 1));
		$this->stackMapper->method('findByBoardAndRole')->with(1, Stack::ROLE_REVIEW)
			->willReturn($this->stack(4, Stack::ROLE_REVIEW));
		$linked = [];
		$this->cardLinkService->expects(self::exactly(2))->method('addLink')
			->willReturnCallback(function (int $cardId) use (&$linked): CardLink {
				$linked[] = $cardId;
				return $this->link(1, $cardId);
			});
		$moves = [];
		$this->cardService->expects(self::exactly(2))->method('move')
			->willReturnCallback(function (int $cardId, int $stackId) use (&$moves): Card {
				$moves[] = [$cardId, $stackId];
				return $this->card($cardId, 1);
			});

		$body = $this->prBody('opened', 'kanso-9-fix', title: 'Also closes KANSO-14');
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);

		// Branch first, then the title match - each card once.
		self::assertSame([9, 77], $linked);
		self::assertSame([[9, 4], [77, 4]], $moves);
		// The branch id is an echo of what the sender supplied, so it is named.
		self::assertSame(9, $result['cardId']);
	}

	public function testBranchAndTitleNamingTheSameCardActOnceEachAndKeepTheEcho(): void {
		// The union dedupes by card id: one link, one move, not two. The card is
		// deliberately NON-public, so the assertion also pins that a later title
		// match cannot DOWNGRADE the branch card's echo flag - with a plain `=`
		// instead of `??=` the id would fall to the public-only gate and vanish.
		$hidden = $this->card(9, 1);
		$hidden->setVisibility(CardVisibilityScope::VISIBILITY_PRIVATE);
		$hidden->setOwner('alice');
		$cardMapper = $this->createMock(CardMapper::class);
		$cardMapper->method('find')->with(9)->willReturn($hidden);
		$service = $this->serviceWithCardMapper($cardMapper);

		$this->boardMapper->method('find')->with(1)->willReturn($this->prefixBoard('KANSO'));
		$this->cardService->method('find')->with(9, 'alice')->willReturn($hidden);
		$this->cardService->method('findByRef')->willReturn($hidden); // same card
		$this->stackMapper->method('findByBoardAndRole')->willReturn($this->stack(4, Stack::ROLE_REVIEW));
		$this->cardLinkService->expects(self::once())->method('addLink');
		$this->cardService->expects(self::once())->method('move')
			->with(9, 4, null, 'alice')->willReturn($this->card(9, 1));

		$body = $this->prBody('opened', 'kanso-9-fix', title: 'KANSO-3 rework');
		self::assertSame(9, $service->handleWebhook(1, $this->sign($body), $body)['cardId']);
	}

	public function testTitleReferencesAreDedupedAndCappedAtFive(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->prefixBoard('KANSO'));
		$refs = [];
		$this->cardService->expects(self::exactly(5))->method('findByRef')
			->willReturnCallback(function (int $boardId, string $ref) use (&$refs): Card {
				$refs[] = $ref;
				return $this->card(100 + (int)substr($ref, 6), 1);
			});
		$this->stackMapper->method('findByBoardAndRole')->willReturn(null);
		$this->cardLinkService->expects(self::exactly(5))->method('addLink');

		$title = 'KANSO-1 KANSO-2 KANSO-1 KANSO-3 KANSO-4 KANSO-5 KANSO-6 KANSO-7';
		$body = $this->prBody('opened', 'topic/whatever', title: $title);
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);

		self::assertSame(['KANSO-1', 'KANSO-2', 'KANSO-3', 'KANSO-4', 'KANSO-5'], $refs);
		self::assertTrue($result['handled']);
		self::assertSame(101, $result['cardId']);
	}

	public function testGenericHyphenTokensInTitleAreNeverProbed(): void {
		// The whole point of matching on THIS board's prefix only: none of these
		// may cost a resolver call.
		$this->boardMapper->method('find')->with(1)->willReturn($this->prefixBoard('KANSO'));
		$this->cardService->expects(self::never())->method('findByRef');
		$this->cardService->expects(self::never())->method('move');

		$title = 'Handle UTF-8, SHA-256 and ISO-8601 in GH-123 under AGPL-3';
		$body = $this->prBody('opened', 'topic/whatever', title: $title);
		self::assertFalse($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	public function testForeignPrefixTitleReferenceIsNeverProbed(): void {
		// Another board's prefix would resolve to null anyway - it must not even
		// reach the resolver.
		$this->boardMapper->method('find')->with(1)->willReturn($this->prefixBoard('KANSO'));
		$this->cardService->expects(self::never())->method('findByRef');

		$body = $this->prBody('opened', 'topic/whatever', title: 'Fix OTHER-14 too');
		self::assertFalse($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	public function testUnknownOrHiddenTitleReferenceIsAnAcceptedNoop(): void {
		// findByRef returns null for an unknown, trashed or viewer-hidden card.
		$this->boardMapper->method('find')->with(1)->willReturn($this->prefixBoard('KANSO'));
		$this->cardService->method('findByRef')->willReturn(null);
		$this->cardService->expects(self::never())->method('move');
		$this->cardLinkService->expects(self::never())->method('addLink');

		$body = $this->prBody('opened', 'topic/whatever', title: 'Fix KANSO-999');
		self::assertFalse($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	public function testTitleReferenceResolverFailureNeverEscapes(): void {
		// findByRef throws (deleted board / permission); the delivery must still
		// be accepted - a 5xx makes GitHub disable the hook.
		$this->boardMapper->method('find')->with(1)->willReturn($this->prefixBoard('KANSO'));
		$this->cardService->method('findByRef')
			->willThrowException(new NotPermittedException('no read'));
		$this->cardService->expects(self::never())->method('move');

		$body = $this->prBody('opened', 'topic/whatever', title: 'Fix KANSO-14');
		self::assertFalse($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	public function testTargetStackLookupFailureIsSwallowed(): void {
		// A title can match several cards, so the role-stack lookup now runs once
		// per match - a DB error there must not escape as a 5xx (GitHub disables
		// a hook that 5xxes) but degrade to link-only.
		$this->boardMapper->method('find')->with(1)->willReturn($this->prefixBoard('KANSO'));
		$this->cardService->method('findByRef')->willReturn($this->card(77, 1));
		$this->stackMapper->method('findByBoardAndRole')
			->willThrowException(new \RuntimeException('db is down'));
		$this->cardLinkService->expects(self::once())->method('addLink');
		$this->cardService->expects(self::never())->method('move');

		$body = $this->prBody('opened', 'topic/whatever', title: 'Fix KANSO-14');
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);

		self::assertTrue($result['handled']);
		self::assertFalse($result['moved']);
	}

	public function testEditedPrLinksTheTitleReferenceButDoesNotMove(): void {
		// Adding the reference after opening is the common case; `edited` also
		// fires on body edits, so it must never re-move a card.
		$this->boardMapper->method('find')->with(1)->willReturn($this->prefixBoard('KANSO'));
		$this->cardService->method('findByRef')->with(1, 'KANSO-14', 'alice')
			->willReturn($this->card(77, 1));
		$this->stackMapper->expects(self::never())->method('findByBoardAndRole');
		$this->cardLinkService->expects(self::once())->method('addLink')
			->with(77, 'https://github.com/octo/app/pull/3', 'alice');
		$this->cardService->expects(self::never())->method('move');

		$body = $this->prBody('edited', 'topic/whatever', title: 'Fix the crash (KANSO-14)');
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);

		self::assertTrue($result['handled']);
		self::assertFalse($result['moved']);
		self::assertSame(77, $result['cardId']);
	}

	/**
	 * A lowercase `kanso-<id>` BRANCH name quoted in a title is not a seq
	 * reference. On a board whose prefix is literally KANSO the two spellings
	 * collide case-insensitively, and reading "kanso-42" as seq 42 would link
	 * AND auto-move whatever unrelated card holds board_seq 42.
	 *
	 * @dataProvider quotedBranchTitleProvider
	 */
	public function testQuotedBranchNameInTitleIsNotReadAsASequenceReference(string $title): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->prefixBoard('KANSO'));
		$this->cardService->expects(self::never())->method('findByRef');
		$this->cardService->expects(self::never())->method('move');
		$this->cardLinkService->expects(self::never())->method('addLink');

		$body = $this->prBody('opened', 'topic/whatever', title: $title);
		self::assertFalse($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function quotedBranchTitleProvider(): array {
		return [
			'bare branch name' => ['Merge kanso-42 into main'],
			'branch name with slug' => ['Merge kanso-9-fix into main'],
			'quoted revert' => ['Revert "kanso-42"'],
			'branch URL' => ['See https://github.com/octo/app/tree/kanso-42'],
			'lowercase reference' => ['fix kanso-42'],
		];
	}

	// ---- PR title egress (#3760 × #9855) ----------------------------------

	public function testPrResponseNeverNamesANonPublicTitleMatchedCard(): void {
		// THE security case: the sender supplied "KANSO-14", NOT an internal id.
		// Resolving it and naming the result would leak the seq -> id mapping and
		// confirm that a non-public card exists. The card is still linked and
		// moved - only the naming is withheld.
		$hidden = $this->card(77, 1);
		$hidden->setVisibility(CardVisibilityScope::VISIBILITY_PRIVATE);
		$hidden->setOwner('alice');
		$cardMapper = $this->createMock(CardMapper::class);
		$cardMapper->method('find')->with(77)->willReturn($hidden);
		$service = $this->serviceWithCardMapper($cardMapper);

		$this->boardMapper->method('find')->with(1)->willReturn($this->prefixBoard('KANSO'));
		$this->cardService->method('findByRef')->with(1, 'KANSO-14', 'alice')->willReturn($hidden);
		$this->stackMapper->method('findByBoardAndRole')->with(1, Stack::ROLE_REVIEW)
			->willReturn($this->stack(4, Stack::ROLE_REVIEW));
		$this->cardLinkService->expects(self::once())->method('addLink')
			->with(77, 'https://github.com/octo/app/pull/3', 'alice');
		$this->cardService->expects(self::once())->method('move')
			->with(77, 4, null, 'alice')->willReturn($this->card(77, 1));

		$body = $this->prBody('opened', 'topic/whatever', title: 'Fix the crash (KANSO-14)');
		$result = $service->handleWebhook(1, $this->sign($body), $body);

		self::assertTrue($result['handled']);
		self::assertTrue($result['moved']);
		self::assertSame(0, $result['cardId']);
	}

	public function testPrResponseNamesTheFirstPublicTitleMatchWhenMixed(): void {
		$internal = $this->card(77, 1);
		$internal->setVisibility(CardVisibilityScope::VISIBILITY_INTERNAL);
		$cardMapper = $this->createMock(CardMapper::class);
		$cardMapper->method('find')->willReturnCallback(
			fn (int $id): Card => $id === 77 ? $internal : $this->card($id, 1),
		);
		$service = $this->serviceWithCardMapper($cardMapper);

		$this->boardMapper->method('find')->with(1)->willReturn($this->prefixBoard('KANSO'));
		$this->cardService->method('findByRef')->willReturnCallback(
			fn (int $boardId, string $ref): Card => $ref === 'KANSO-14' ? $internal : $this->card(78, 1),
		);
		$this->stackMapper->method('findByBoardAndRole')->willReturn(null);

		$body = $this->prBody('opened', 'topic/whatever', title: 'KANSO-14 and KANSO-15');
		$result = $service->handleWebhook(1, $this->sign($body), $body);

		self::assertTrue($result['handled']);
		self::assertSame(78, $result['cardId']);
	}

	public function testBranchCardIdIsStillEchoedWhenNotPublic(): void {
		// Unchanged pre-#9855 behavior: the branch id is a verbatim echo of what
		// the request itself supplied, so the public-only gate does not apply.
		$hidden = $this->card(9, 1);
		$hidden->setVisibility(CardVisibilityScope::VISIBILITY_PRIVATE);
		$hidden->setOwner('alice');
		$cardMapper = $this->createMock(CardMapper::class);
		$cardMapper->method('find')->with(9)->willReturn($hidden);
		$service = $this->serviceWithCardMapper($cardMapper);

		$this->boardMapper->method('find')->with(1)->willReturn($this->prefixBoard('KANSO'));
		$this->cardService->method('find')->with(9, 'alice')->willReturn($hidden);
		$this->stackMapper->method('findByBoardAndRole')->willReturn(null);

		$body = $this->prBody('opened', 'kanso-9-fix');
		self::assertSame(9, $service->handleWebhook(1, $this->sign($body), $body)['cardId']);
	}
}
