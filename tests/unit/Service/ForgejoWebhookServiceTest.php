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
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\CardLinkService;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\CardVisibilityScope;
use OCA\Kanso\Service\ForgejoWebhookService;
use OCA\Kanso\Service\LabelService;
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
	private LabelService&MockObject $labelService;
	private LabelMapper&MockObject $labelMapper;
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
		$this->labelService = $this->createMock(LabelService::class);
		$this->labelMapper = $this->createMock(LabelMapper::class);
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
			$this->labelService,
			$this->labelMapper,
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

	private function prBody(string $action, string $branch, bool $merged = false, string $title = ''): string {
		return json_encode([
			'action' => $action,
			'pull_request' => [
				'head' => ['ref' => $branch],
				'html_url' => self::BASE . '/pulls/3',
				'state' => $merged ? 'closed' : 'open',
				'merged' => $merged,
				'title' => $title,
			],
		]);
	}

	private function prefixBoard(string $prefix = 'KANSO'): Board {
		$b = $this->board();
		$b->setPrefix($prefix);
		return $b;
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

	// ---- PR title references (#9855), inherited by every forge -------------

	/**
	 * `PREFIX-<seq>` title matching lives in AbstractForgeWebhookService, so
	 * Forgejo gets it for free - the reference rides the PR title, which every
	 * provider's payload carries. Pinned here so a change to the shared matcher
	 * cannot quietly regress the Forgejo half.
	 */
	public function testOpenedPrWithTitleReferenceLinksAndMovesThatCard(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->prefixBoard('KANSO'));
		$this->cardService->expects(self::once())->method('findByRef')
			->with(1, 'KANSO-14', 'alice')->willReturn($this->card(77, 1));
		$this->stackMapper->method('findByBoardAndRole')->with(1, Stack::ROLE_REVIEW)
			->willReturn($this->stack(4, Stack::ROLE_REVIEW));
		$this->cardLinkService->expects(self::once())->method('addLink')
			->with(77, self::BASE . '/pulls/3', 'alice');
		$this->cardService->expects(self::once())->method('move')
			->with(77, 4, null, 'alice')->willReturn($this->card(77, 1));

		$body = $this->prBody('opened', 'my-random-branch', title: 'Fix the crash (KANSO-14)');
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);

		self::assertTrue($result['handled']);
		self::assertTrue($result['moved']);
		self::assertSame(77, $result['cardId']);
	}

	/** The branch and title matches are unioned, deduped, and both are moved. */
	public function testBranchAndTitleMatchesAreBothHandled(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->prefixBoard('KANSO'));
		$this->cardService->method('find')->with(9, 'alice')->willReturn($this->card(9, 1));
		$this->cardService->method('findByRef')->with(1, 'KANSO-14', 'alice')
			->willReturn($this->card(77, 1));
		$this->stackMapper->method('findByBoardAndRole')->with(1, Stack::ROLE_DONE)
			->willReturn($this->stack(5, Stack::ROLE_DONE));

		$moved = [];
		$this->cardService->expects(self::exactly(2))->method('move')
			->willReturnCallback(function (int $cardId) use (&$moved): Card {
				$moved[] = $cardId;
				return $this->card($cardId, 1);
			});

		$body = $this->prBody('closed', 'kanso-9-fix', true, 'Also closes KANSO-14');
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);

		self::assertTrue($result['moved']);
		self::assertSame([9, 77], $moved);
		// The branch id is an echo of what the sender supplied, so it may be named.
		self::assertSame(9, $result['cardId']);
	}

	/**
	 * Case-sensitivity is a correctness rule, not pedantry: a board titled
	 * "Kanso" derives prefix KANSO, which case-insensitively collides with the
	 * lowercase `kanso-<id>` BRANCH spelling a title may quote.
	 */
	public function testQuotedLowercaseBranchInTitleIsNotReadAsAReference(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->prefixBoard('KANSO'));
		$this->cardService->method('find')->with(9, 'alice')->willReturn($this->card(9, 1));
		$this->cardService->expects(self::never())->method('findByRef');
		$this->stackMapper->method('findByBoardAndRole')->willReturn(null);

		$body = $this->prBody('opened', 'kanso-9-fix', false, 'Merge kanso-42 into main');
		self::assertTrue($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	/** An unknown / trashed / hidden reference resolves to null and is no match. */
	public function testUnresolvableTitleReferenceIsAcceptedNoOp(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->prefixBoard('KANSO'));
		$this->cardService->method('findByRef')->willReturn(null);
		$this->cardService->expects(self::never())->method('move');

		$body = $this->prBody('opened', 'no-convention', false, 'Fixes KANSO-99');
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
			$this->labelService,
			$this->labelMapper,
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

	// ---- label mirroring (#10491) ------------------------------------------

	/**
	 * The label mirror lives in the shared base, but Forgejo deliveries never
	 * reach it: Forgejo spells an issue label change `label_updated` /
	 * `label_cleared` and ships no top-level `label` object, only the issue's
	 * full post-change label set - no delta to mirror. So the normalizer leaves
	 * `changedLabel` null and the delivery is the accepted no-op every
	 * unrecognized action already is. Asserted here so the day someone wires
	 * Forgejo up, they do it deliberately rather than by half-matching GitHub's
	 * spelling.
	 */
	public function testForgejoLabelUpdatedDeliveryMirrorsNothing(): void {
		$url = self::BASE . '/issues/12';
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([$this->link(3, 9, $url)]);
		$this->stackMapper->method('findByBoardAndRole')->willReturn(null);
		$this->labelMapper->expects(self::never())->method('findByBoard');
		$this->labelService->expects(self::never())->method('assign');
		$this->labelService->expects(self::never())->method('unassign');
		$this->labelService->expects(self::never())->method('create');

		$body = json_encode([
			'action' => 'label_updated',
			'issue' => ['html_url' => $url, 'state' => 'open', 'title' => 'A bug', 'labels' => [['name' => 'Bug']]],
		]);
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertTrue($result['handled']);
		self::assertSame(ForgejoWebhookService::REASON_UNKNOWN_ACTION, $result['reason']);
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

	// ---- intake retrigger on a later label (#10566) ------------------------

	/**
	 * A Forgejo/Gitea `issues` LABEL delivery, shaped from one captured off a live
	 * Gitea 1.22 instance rather than from GitHub's payload (#10580 exists because
	 * GitHub-shaped Forgejo fixtures once passed green against dead code). What the
	 * real delivery does and does not carry:
	 *
	 *  - `action` is `label_updated` for an add AND for a removal - never GitHub's
	 *    `labeled`/`unlabeled`;
	 *  - there is NO top-level `label` object, so no per-label delta exists;
	 *  - `issue.labels` carries the issue's FULL post-change label set, each entry
	 *    an object with Forgejo's own `exclusive`/`is_archived` fields beside
	 *    `name` - and that set is exactly what the intake filter reads;
	 *  - the payload repeats the issue number at the TOP level and ships a
	 *    `commit_id`, neither of which GitHub sends.
	 *
	 * @param string[] $labelNames the issue's labels AFTER the change
	 */
	private function labelUpdatedBody(
		string $url,
		array $labelNames,
		string $action = 'label_updated',
		string $state = 'open',
	): string {
		$labels = [];
		foreach ($labelNames as $i => $name) {
			$labels[] = [
				'id' => $i + 1,
				'name' => $name,
				'exclusive' => false,
				'is_archived' => false,
				'color' => 'ee0701',
				'description' => '',
				'url' => 'https://git.example.org/api/v1/repos/octo/app/labels/' . ($i + 1),
			];
		}
		return json_encode([
			'action' => $action,
			'number' => 12,
			'commit_id' => '',
			'issue' => [
				'id' => 7,
				'number' => 12,
				'html_url' => $url,
				'title' => 'A bug',
				'body' => 'boom',
				'state' => $state,
				'labels' => $labels,
				'assignee' => null,
				'assignees' => null,
				'milestone' => null,
				'comments' => 0,
				'is_locked' => false,
				'pull_request' => null,
				'user' => ['login' => 'octo', 'id' => 1],
			],
			'repository' => [
				'id' => 1,
				'full_name' => 'octo/app',
				'html_url' => 'https://git.example.org/octo/app',
			],
			'sender' => ['login' => 'octo', 'id' => 1],
		]);
	}

	/**
	 * The Forgejo half of the fix. `label_updated` carries no delta, which is why
	 * the label MIRROR skips it - but it does carry the issue's current label set,
	 * which is all intake's filter needs.
	 */
	public function testIssueLabelUpdatedAfterOpeningIsTakenIn(): void {
		$board = $this->board();
		$board->setForgejoIntakeStackId(7);
		$board->setForgejoIntakeLabel('backlog');
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->stackMapper->method('find')->with(7)->willReturn($this->stack(7, Stack::ROLE_TODO));
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->cardLinkMapper->method('existsByBoardAndUrls')->willReturn(false);
		$this->cardService->expects(self::once())->method('create')
			->with(7, 'A bug', 'alice')->willReturn($this->card(42, 1));
		$this->cardLinkMapper->expects(self::once())->method('insert')
			->willReturnCallback(function (CardLink $l): CardLink {
				self::assertSame(CardLink::PROVIDER_FORGEJO, $l->getProvider());
				self::assertSame(CardLink::KIND_ISSUE, $l->getKind());
				return $l;
			});

		$body = $this->labelUpdatedBody(self::BASE . '/issues/12', ['backlog', 'enhancement']);
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);

		self::assertTrue($result['created']);
		self::assertSame(42, $result['cardId']);
		self::assertSame('label_updated', $result['action']);
	}

	/**
	 * The test that would have caught the failure mode #10580 was filed for: a
	 * retrigger keyed on GitHub's `labeled` can NEVER fire on Forgejo, so the
	 * action set is per-forge. This asserts the dead-code direction directly - a
	 * GitHub-spelled action, with the intake label present, still cards nothing.
	 */
	public function testGithubLabelActionSpellingNeverRetriggersForgejoIntake(): void {
		$board = $this->board();
		$board->setForgejoIntakeStackId(7);
		$board->setForgejoIntakeLabel('backlog');
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->cardService->expects(self::never())->method('create');

		$body = $this->labelUpdatedBody(self::BASE . '/issues/12', ['backlog'], 'labeled');
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);

		self::assertFalse($result['handled']);
		self::assertSame(ForgejoWebhookService::REASON_NO_LINK_MATCH, $result['reason']);
	}

	/**
	 * Forgejo spells a REMOVAL `label_updated` too - verified against a live
	 * delivery - so the retrigger does reach intake for one. The filter then
	 * rejects it off the post-change set, which is the guarantee that matters: an
	 * issue that does not carry the intake label is never carded.
	 */
	public function testLabelUpdatedThatLeavesNoIntakeLabelIsStillNotTakenIn(): void {
		$board = $this->board();
		$board->setForgejoIntakeStackId(7);
		$board->setForgejoIntakeLabel('backlog');
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->cardService->expects(self::never())->method('create');

		$body = $this->labelUpdatedBody(self::BASE . '/issues/12', []);
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);

		self::assertFalse($result['handled']);
		self::assertSame(ForgejoWebhookService::REASON_INTAKE_FILTERED, $result['reason']);
	}

	/** The reused dedup holds on the new path here too: two deliveries, one card. */
	public function testDoubleLabelUpdatedDeliveryForOneIssueCreatesExactlyOneCard(): void {
		$board = $this->board();
		$board->setForgejoIntakeStackId(7);
		$board->setForgejoIntakeLabel('backlog');
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->stackMapper->method('find')->with(7)->willReturn($this->stack(7, Stack::ROLE_TODO));
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);

		$linked = false;
		$this->cardLinkMapper->method('existsByBoardAndUrls')
			->willReturnCallback(function () use (&$linked): bool {
				return $linked;
			});
		$this->cardService->expects(self::once())->method('create')->willReturn($this->card(42, 1));
		$this->cardLinkMapper->expects(self::once())->method('insert')
			->willReturnCallback(function (CardLink $l) use (&$linked): CardLink {
				$linked = true;
				return $l;
			});

		$body = $this->labelUpdatedBody(self::BASE . '/issues/12', ['backlog']);
		$first = $this->service->handleWebhook(1, $this->sign($body), $body);
		$second = $this->service->handleWebhook(1, $this->sign($body), $body);

		self::assertTrue($first['created']);
		self::assertFalse($second['handled']);
		self::assertSame(ForgejoWebhookService::REASON_INTAKE_DUPLICATE, $second['reason']);
	}

	/**
	 * Intake brings WORK onto the board: a label change on a long-closed issue must
	 * not card it, on either forge.
	 */
	public function testLabelUpdatedOnAClosedIssueNeverTakesItIn(): void {
		$board = $this->board();
		$board->setForgejoIntakeStackId(7);
		$board->setForgejoIntakeLabel('backlog');
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->cardService->expects(self::never())->method('create');

		$body = $this->labelUpdatedBody(self::BASE . '/issues/12', ['backlog'], 'label_updated', 'closed');
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);

		self::assertFalse($result['handled']);
		self::assertSame(ForgejoWebhookService::REASON_NO_LINK_MATCH, $result['reason']);
	}

	/** A board taking in ALL issues is untouched by the retrigger. */
	public function testLabelUpdatedOnAFilterlessIntakeBoardCreatesNothing(): void {
		$board = $this->board();
		$board->setForgejoIntakeStackId(7);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->cardService->expects(self::never())->method('create');
		$this->cardLinkMapper->expects(self::never())->method('insert');

		$body = $this->labelUpdatedBody(self::BASE . '/issues/12', ['backlog']);
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);

		self::assertFalse($result['handled']);
		self::assertSame(ForgejoWebhookService::REASON_NO_LINK_MATCH, $result['reason']);
	}

	// ---- intake labels (#10570) --------------------------------------------

	private function label(int $id, string $title, int $boardId = 1): Label {
		$l = new Label();
		$l->setId($id);
		$l->setBoardId($boardId);
		$l->setTitle($title);
		return $l;
	}

	/**
	 * The Forgejo half of #10570, off a Gitea-shaped payload (label objects with
	 * `exclusive`/`is_archived`, no top-level `label`) rather than a GitHub-shaped
	 * one - the mistake #10580 exists for. Intake reads `issue.labels`, which both
	 * forges populate, so this path genuinely is shared.
	 *
	 * Also the one-query proof: one `findByBoard` for three delivered names.
	 */
	public function testIntakeAppliesTheIssuesExistingLabelsToTheNewCard(): void {
		$board = $this->board();
		$board->setForgejoIntakeStackId(7);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->stackMapper->method('find')->with(7)->willReturn($this->stack(7, Stack::ROLE_TODO));
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->cardLinkMapper->method('existsByBoardAndUrls')->willReturn(false);
		$this->cardService->method('create')->willReturn($this->card(42, 1));
		$this->labelMapper->expects(self::once())->method('findByBoard')->with(1)
			->willReturn([$this->label(8, 'Backlog'), $this->label(5, 'bug')]);

		$assigned = [];
		$this->labelService->method('assign')
			->willReturnCallback(function (int $cardId, int $labelId, string $uid) use (&$assigned): void {
				$assigned[] = [$cardId, $labelId, $uid];
			});
		$this->labelService->expects(self::never())->method('create');
		$this->labelService->expects(self::never())->method('unassign');

		// `wontfix` has no counterpart on this board - silently ignored.
		$body = $this->labelUpdatedBody(
			self::BASE . '/issues/12',
			['backlog', 'BUG', 'wontfix'],
			'opened',
		);
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);

		self::assertTrue($result['created']);
		self::assertSame([[42, 8, 'alice'], [42, 5, 'alice']], $assigned);
	}

	/**
	 * The owner's hard rule, asserted on this forge too: an unmatched delivered
	 * label is ignored, and no board label is ever minted for it - label creation
	 * is MANAGE-gated and this endpoint is unauthenticated.
	 */
	public function testIntakeLabelTheBoardDoesNotDefineIsIgnoredAndNeverCreated(): void {
		$board = $this->board();
		$board->setForgejoIntakeStackId(7);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->stackMapper->method('find')->with(7)->willReturn($this->stack(7, Stack::ROLE_TODO));
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->cardLinkMapper->method('existsByBoardAndUrls')->willReturn(false);
		$this->cardService->method('create')->willReturn($this->card(42, 1));
		$this->labelMapper->method('findByBoard')->willReturn([$this->label(8, 'Backlog')]);

		$this->labelService->expects(self::never())->method('create');
		$this->labelService->expects(self::never())->method('assign');

		$body = $this->labelUpdatedBody(self::BASE . '/issues/12', ['kind/bug', 'priority/high'], 'opened');
		self::assertTrue($this->service->handleWebhook(1, $this->sign($body), $body)['created']);
	}

	/**
	 * Forgejo's retrigger (#10566) is where a filtered board's issues actually
	 * arrive, so the labels must ride along there too - including the board's own
	 * intake label, applied like any other.
	 */
	public function testLabelUpdatedIntakeAppliesTheLabelsIncludingTheIntakeOne(): void {
		$board = $this->board();
		$board->setForgejoIntakeStackId(7);
		$board->setForgejoIntakeLabel('backlog');
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->stackMapper->method('find')->with(7)->willReturn($this->stack(7, Stack::ROLE_TODO));
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([]);
		$this->cardLinkMapper->method('existsByBoardAndUrls')->willReturn(false);
		$this->cardService->method('create')->willReturn($this->card(42, 1));
		$this->labelMapper->method('findByBoard')->willReturn([$this->label(8, 'Backlog')]);

		$this->labelService->expects(self::once())->method('assign')->with(42, 8, 'alice');
		$this->labelService->expects(self::never())->method('create');

		$body = $this->labelUpdatedBody(self::BASE . '/issues/12', ['backlog']);
		self::assertTrue($this->service->handleWebhook(1, $this->sign($body), $body)['created']);
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
