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
use OCA\Kanso\Service\ForgejoWebhookService;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Forgejo/Gitea webhook ingest. Mirrors the GitHub suite, plus the two things
 * that genuinely differ: the raw-hex signature (and the EMPTY digest a
 * secret-less hook sends) and the shared issue/PR number sequence, which makes
 * `/issues/5` and `/pulls/5` the same object.
 */
class ForgejoWebhookServiceTest extends TestCase {
	private const SECRET = 'forgejosecretkey';
	private const BASE = 'https://git.example.org/octo/app';

	private BoardMapper&MockObject $boardMapper;
	private StackMapper&MockObject $stackMapper;
	private CardService&MockObject $cardService;
	private CardMapper&MockObject $cardMapper;
	private CardLinkService&MockObject $cardLinkService;
	private CardLinkMapper&MockObject $cardLinkMapper;
	private PermissionService&MockObject $permissionService;
	private ISecureRandom&MockObject $secureRandom;
	private IURLGenerator&MockObject $urlGenerator;
	private ForgejoWebhookService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->stackMapper = $this->createMock(StackMapper::class);
		$this->cardService = $this->createMock(CardService::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->cardMapper->method('find')->willReturnCallback(fn (int $id): Card => $this->card($id));
		$this->cardLinkService = $this->createMock(CardLinkService::class);
		$this->cardLinkMapper = $this->createMock(CardLinkMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->service = new ForgejoWebhookService(
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
		$b->setForgejoWebhookSecret($secret);
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

	/** Forgejo's own header spelling: a bare lowercase hex digest, no prefix. */
	private function sign(string $body): string {
		return hash_hmac('sha256', $body, self::SECRET);
	}

	private function prBody(string $action, string $branch, bool $merged = false): string {
		return json_encode([
			'action' => $action,
			'pull_request' => [
				'head' => ['ref' => $branch],
				'html_url' => self::BASE . '/pulls/3',
				'state' => $merged ? 'closed' : 'open',
				'merged' => $merged,
			],
		]);
	}

	private function issueBody(string $action, string $url, string $state = 'closed', string $title = 'A bug'): string {
		return json_encode([
			'action' => $action,
			'issue' => ['html_url' => $url, 'state' => $state, 'title' => $title],
		]);
	}

	private function link(int $id, int $cardId, string $url): CardLink {
		$l = new CardLink();
		$l->setId($id);
		$l->setCardId($cardId);
		$l->setUrl($url);
		$l->setKind(CardLink::KIND_ISSUE);
		$l->setProvider(CardLink::PROVIDER_FORGEJO);
		$l->setState(CardLink::STATE_OPEN);
		return $l;
	}

	// ---- signature verification ------------------------------------------

	public function testAcceptsRawHexSignature(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardService->method('find')->with(9, 'alice')->willReturn($this->card(9, 1));
		$body = $this->prBody('opened', 'kanso-9-x');

		self::assertTrue($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	/** Forgejo also emits the GitHub-compatible header; both must work. */
	public function testAcceptsGithubCompatibleSignature(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardService->method('find')->with(9, 'alice')->willReturn($this->card(9, 1));
		$body = $this->prBody('opened', 'kanso-9-x');

		self::assertTrue($this->service->handleWebhook(1, 'sha256=' . $this->sign($body), $body)['handled']);
	}

	/**
	 * A hook saved WITHOUT a secret still sends the signature headers - empty.
	 * That must read as a rejection, not as "no signature offered".
	 *
	 * @param string $signature
	 */
	#[DataProvider('badSignatureProvider')]
	public function testRejectsBadSignature(string $signature): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$body = $this->prBody('opened', 'kanso-9-x');
		$this->cardService->expects(self::never())->method('move');

		$this->expectException(NotPermittedException::class);
		$this->service->handleWebhook(1, $signature, $body);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function badSignatureProvider(): array {
		return [
			'absent' => [''],
			'empty after trim' => ['   '],
			'whitespace-only' => ["\t\n"],
			'too short' => ['deadbeef'],
			'not hex' => [str_repeat('z', 64)],
			'well-formed but wrong' => [str_repeat('a', 64)],
			'prefixed but wrong' => ['sha256=' . str_repeat('b', 64)],
		];
	}

	public function testRejectsWhenForgejoWebhookDisabled(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board(1, null));
		$body = $this->prBody('opened', 'kanso-9-x');

		$this->expectException(NotPermittedException::class);
		$this->service->handleWebhook(1, $this->sign($body), $body);
	}

	/** The GitHub secret must not authorize a Forgejo delivery. */
	public function testGithubSecretDoesNotAuthorizeForgejo(): void {
		$board = $this->board(1, null);
		$board->setWebhookSecret('the-github-one');
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$body = $this->prBody('opened', 'kanso-9-x');

		$this->expectException(NotPermittedException::class);
		$this->service->handleWebhook(1, hash_hmac('sha256', $body, 'the-github-one'), $body);
	}

	// ---- pull requests ----------------------------------------------------

	/**
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
				'html_url' => self::BASE . '/pulls/3',
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

	public function testPrLinkStateIsCachedFromThePayload(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardService->method('find')->with(9, 'alice')->willReturn($this->card(9, 1));
		$this->stackMapper->method('findByBoardAndRole')->willReturn(null);

		$link = $this->link(3, 9, self::BASE . '/pulls/3');
		$this->cardLinkService->method('addLink')->willReturn($link);
		$this->cardLinkMapper->expects(self::once())->method('update')
			->willReturnCallback(function (CardLink $l): CardLink {
				self::assertSame(CardLink::STATE_MERGED, $l->getState());
				return $l;
			});

		$body = $this->prBody('closed', 'kanso-9-fix', true);
		self::assertTrue($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	public function testUnmatchedBranchIsAcceptedNoOp(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardService->expects(self::never())->method('move');

		$body = $this->prBody('opened', 'feature/unrelated');
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertFalse($result['handled']);
		self::assertSame(ForgejoWebhookService::REASON_NO_CARD_MATCH, $result['reason']);
	}

	// ---- issues -----------------------------------------------------------

	public function testClosedIssueMovesLinkedCardToDone(): void {
		$url = self::BASE . '/issues/12';
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([$this->link(3, 9, $url)]);
		$this->stackMapper->method('findByBoardAndRole')->with(1, Stack::ROLE_DONE)
			->willReturn($this->stack(5, Stack::ROLE_DONE));
		$this->cardService->expects(self::once())->method('move')
			->with(9, 5, null, 'alice')->willReturn($this->card(9, 1));

		$body = $this->issueBody('closed', $url);
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertTrue($result['moved']);
		self::assertSame(9, $result['cardId']);
	}

	public function testReopenedIssueFallsBackToTodoStack(): void {
		$url = self::BASE . '/issues/12';
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([$this->link(3, 9, $url)]);
		$this->stackMapper->method('findByBoardAndRole')->willReturnCallback(
			fn (int $b, int $role): ?Stack => $role === Stack::ROLE_TODO ? $this->stack(2, Stack::ROLE_TODO) : null
		);
		$this->cardService->expects(self::once())->method('move')
			->with(9, 2, null, 'alice')->willReturn($this->card(9, 1));

		$body = $this->issueBody('reopened', $url, 'open');
		self::assertTrue($this->service->handleWebhook(1, $this->sign($body), $body)['moved']);
	}

	/**
	 * Forgejo shares ONE number sequence between issues and PRs and redirects
	 * each spelling to the other, so a link pasted as `/issues/5` must still be
	 * matched by a delivery whose html_url is `/pulls/5` - otherwise that chip
	 * would sit on `unknown` forever.
	 */
	public function testIssueAndPullSpellingsAreBothMatchCandidates(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('findByBoardAndRole')->willReturn(null);

		$seen = [];
		$this->cardLinkMapper->method('findByBoardAndUrls')
			->willReturnCallback(function (int $boardId, array $urls) use (&$seen): array {
				$seen = $urls;
				return [];
			});

		$body = $this->issueBody('closed', self::BASE . '/issues/5');
		$this->service->handleWebhook(1, $this->sign($body), $body);

		self::assertContains(self::BASE . '/issues/5', $seen);
		self::assertContains(self::BASE . '/pulls/5', $seen);
		self::assertContains(self::BASE . '/issues/5/', $seen);
		self::assertContains(self::BASE . '/pulls/5/', $seen);
	}

	public function testUnlinkedClosedIssueIsAcceptedNoOp(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->cardService->expects(self::never())->method('move');

		$body = $this->issueBody('closed', self::BASE . '/issues/404');
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertFalse($result['handled']);
		self::assertSame(ForgejoWebhookService::REASON_NO_LINK_MATCH, $result['reason']);
	}

	public function testUnparseableIssueUrlIsAcceptedNoOp(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$body = $this->issueBody('closed', 'not-a-url');

		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertFalse($result['handled']);
		self::assertSame(ForgejoWebhookService::REASON_UNSUPPORTED_EVENT, $result['reason']);
	}

	public function testPingDeliveryIsAcceptedNoOp(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$body = json_encode(['zen' => 'hello']);

		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertFalse($result['handled']);
		self::assertSame(ForgejoWebhookService::REASON_UNSUPPORTED_EVENT, $result['reason']);
	}

	/**
	 * Egress rule (#3760): a non-public card is still processed, but its id is
	 * never confirmed outward.
	 */
	public function testNonPublicLinkedCardIdIsNotNamedInTheResponse(): void {
		// The 200 body goes to an EXTERNAL system (the forge's delivery log): a
		// hidden linked card is still processed - its move runs as the board
		// owner, visibility-gated in CardService - but its id must not be
		// confirmed outward, so the response reports cardId 0.
		$hidden = $this->card(9, 1);
		$hidden->setVisibility(CardVisibilityScope::VISIBILITY_PRIVATE);
		$hidden->setOwner('alice');
		$cardMapper = $this->createMock(CardMapper::class);
		$cardMapper->method('find')->with(9)->willReturn($hidden);
		$service = new ForgejoWebhookService(
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

		$url = self::BASE . '/issues/12';
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([$this->link(3, 9, $url)]);
		$this->stackMapper->method('findByBoardAndRole')->with(1, Stack::ROLE_DONE)
			->willReturn($this->stack(5, Stack::ROLE_DONE));
		// The move still happens (owner-gated automation is not egress).
		$this->cardService->expects(self::once())->method('move')
			->with(9, 5, null, 'alice')->willReturn($this->card(9, 1));

		$body = $this->issueBody('closed', $url);
		$result = $service->handleWebhook(1, $this->sign($body), $body);

		self::assertTrue($result['moved']);
		self::assertSame(0, $result['cardId']);
	}

	// ---- issue intake -----------------------------------------------------

	public function testIntakeOffIsAcceptedNoOp(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->cardService->expects(self::never())->method('create');

		$body = $this->issueBody('opened', self::BASE . '/issues/12', 'open');
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertFalse($result['handled']);
		self::assertSame(ForgejoWebhookService::REASON_INTAKE_OFF, $result['reason']);
	}

	public function testOpenedIssueCreatesLinkedCardInIntakeStack(): void {
		$board = $this->board();
		$board->setForgejoIntakeStackId(7);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->stackMapper->method('find')->with(7)->willReturn($this->stack(7, Stack::ROLE_TODO));
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->cardLinkMapper->method('existsByBoardAndUrls')->willReturn(false);
		$this->cardService->expects(self::once())->method('create')
			->with(7, 'A bug', 'alice')->willReturn($this->card(42, 1));

		// The created link must be tagged forgejo, or the read-time poll would
		// later try to reach a self-hosted instance.
		$this->cardLinkMapper->expects(self::once())->method('insert')
			->willReturnCallback(function (CardLink $l): CardLink {
				self::assertSame(CardLink::PROVIDER_FORGEJO, $l->getProvider());
				self::assertSame(CardLink::KIND_ISSUE, $l->getKind());
				self::assertSame(CardLink::STATE_OPEN, $l->getState());
				return $l;
			});

		$body = $this->issueBody('opened', self::BASE . '/issues/12', 'open');
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertTrue($result['created']);
		self::assertSame(42, $result['cardId']);
	}

	public function testIntakeLabelFilterExcludesNonMatchingIssue(): void {
		$board = $this->board();
		$board->setForgejoIntakeStackId(7);
		$board->setForgejoIntakeLabel('bug');
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->cardService->expects(self::never())->method('create');

		$body = json_encode([
			'action' => 'opened',
			'issue' => [
				'html_url' => self::BASE . '/issues/12',
				'state' => 'open',
				'title' => 'A feature',
				'labels' => [['name' => 'enhancement']],
			],
		]);
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertFalse($result['handled']);
		self::assertSame(ForgejoWebhookService::REASON_INTAKE_FILTERED, $result['reason']);
	}

	public function testIntakeLabelFilterIsCaseInsensitive(): void {
		$board = $this->board();
		$board->setForgejoIntakeStackId(7);
		$board->setForgejoIntakeLabel('bug');
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->stackMapper->method('find')->with(7)->willReturn($this->stack(7, Stack::ROLE_TODO));
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->cardLinkMapper->method('existsByBoardAndUrls')->willReturn(false);
		$this->cardService->expects(self::once())->method('create')->willReturn($this->card(42, 1));

		$body = json_encode([
			'action' => 'opened',
			'issue' => [
				'html_url' => self::BASE . '/issues/12',
				'state' => 'open',
				'title' => 'A bug',
				'labels' => [['name' => 'Bug']],
			],
		]);
		self::assertTrue($this->service->handleWebhook(1, $this->sign($body), $body)['created']);
	}

	public function testIntakeDedupesAlreadyLinkedIssue(): void {
		$board = $this->board();
		$board->setForgejoIntakeStackId(7);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->stackMapper->method('find')->with(7)->willReturn($this->stack(7, Stack::ROLE_TODO));
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->cardLinkMapper->method('existsByBoardAndUrls')->willReturn(true);
		$this->cardService->expects(self::never())->method('create');

		$body = $this->issueBody('opened', self::BASE . '/issues/12', 'open');
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertFalse($result['handled']);
		self::assertSame(ForgejoWebhookService::REASON_INTAKE_DUPLICATE, $result['reason']);
	}

	public function testIntakeStaleStackIsAcceptedNoOp(): void {
		$board = $this->board();
		$board->setForgejoIntakeStackId(7);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		// Stack now lives on another board.
		$this->stackMapper->method('find')->with(7)->willReturn($this->stack(7, Stack::ROLE_TODO, 99));
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->cardService->expects(self::never())->method('create');

		$body = $this->issueBody('opened', self::BASE . '/issues/12', 'open');
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertSame(ForgejoWebhookService::REASON_INTAKE_STALE_STACK, $result['reason']);
	}

	public function testIntakeFallsBackToNumberedTitleWhenTitleIsBlank(): void {
		$board = $this->board();
		$board->setForgejoIntakeStackId(7);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->stackMapper->method('find')->with(7)->willReturn($this->stack(7, Stack::ROLE_TODO));
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->cardLinkMapper->method('existsByBoardAndUrls')->willReturn(false);
		$this->cardService->expects(self::once())->method('create')
			->with(7, 'Issue #12', 'alice')->willReturn($this->card(42, 1));

		$body = $this->issueBody('opened', self::BASE . '/issues/12', 'open', '   ');
		self::assertTrue($this->service->handleWebhook(1, $this->sign($body), $body)['created']);
	}

	// ---- config -----------------------------------------------------------

	public function testRotateSecretWritesTheForgejoColumnOnly(): void {
		$board = $this->board(1, null);
		$board->setWebhookSecret('github-untouched');
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->secureRandom->method('generate')->willReturn('a-new-secret');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc/forgejo');
		$this->boardMapper->expects(self::once())->method('update')->willReturn($board);

		$result = $this->service->rotateSecret(1, 'alice');

		self::assertSame('a-new-secret', $result['secret']);
		self::assertSame('a-new-secret', $board->getForgejoWebhookSecret());
		self::assertSame('github-untouched', $board->getWebhookSecret());
	}

	public function testDisableClearsOnlyTheForgejoSecret(): void {
		$board = $this->board();
		$board->setWebhookSecret('github-untouched');
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->boardMapper->expects(self::once())->method('update')->willReturn($board);

		$this->service->disable(1, 'alice');

		self::assertNull($board->getForgejoWebhookSecret());
		self::assertSame('github-untouched', $board->getWebhookSecret());
	}
}
