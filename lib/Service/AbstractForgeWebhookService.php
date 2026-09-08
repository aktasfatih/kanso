<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\BoardPrefix;
use OCA\Kanso\Db\CardLink;
use OCA\Kanso\Db\CardLinkMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\Forge\ForgeEvent;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

/**
 * Everything an inbound forge webhook does that is NOT specific to one forge:
 * the per-board secret lifecycle, the intake config, and the whole automation
 * (card matching, auto-move, issue intake, link-state caching, egress gating).
 *
 * A concrete subclass supplies only the four things that genuinely differ per
 * forge - how a delivery is signed, how its payload spells things
 * ({@see ForgeEvent}), which board columns hold its config, and which route is
 * its payload URL. See {@see GithubWebhookService} and {@see ForgejoWebhookService}.
 *
 * A PR names its cards two ways, and both are honored on the same delivery:
 * a `kanso-<id>` head branch (the internal card id), and any `PREFIX-<seq>`
 * human reference in the PR TITLE - the identifier the UI actually shows, so
 * nobody has to name a branch after an id they cannot see. Title matching is a
 * single anchored, case-SENSITIVE pass for THIS board's own prefix, resolved
 * through {@see CardService::findByRef} - viewer-scoped, so an unknown,
 * trashed, hidden or foreign-prefix reference is simply not a match. The branch
 * and title matches are unioned (branch first), deduped by card id and capped,
 * then each card is linked and auto-moved. Both work for every forge: the
 * reference lives in the PR title, which every provider's payload carries.
 *
 * Auto-move reuses stack ROLES, not a config surface: a PR opened moves its
 * matched cards to the board's ROLE_REVIEW stack; a PR merged moves them to the
 * ROLE_DONE stack (which stamps them done via the existing move automation).
 * An issue has no branch, so its cards are matched in reverse:
 * alive cards on this board with that issue URL attached as a link. An issue
 * closed moves them to ROLE_DONE; reopened moves them back to ROLE_IN_PROGRESS
 * (or ROLE_TODO if the board has no in-progress stack). A board with no matching
 * role-stack simply doesn't move - the link/state is still recorded. Every move
 * goes through CardService::move so sort keys, the transaction and the change
 * row all fire.
 *
 * Issue intake (#3752, opt-in): when a board configures an intake stack, an
 * `issues`/`opened` delivery for an issue not yet linked anywhere on the board
 * auto-creates a LINK-ONLY card there (title = issue title, the issue attached
 * as a KIND_ISSUE link with state/title cached from the payload - no body copy,
 * no content sync). An optional free-text label filter narrows intake to issues
 * carrying that label. Creation goes through CardService::create as the board
 * owner, so the sort key, change row and realtime all fire.
 *
 * Egress / visibility rule (#3760): the 200 response body is this service's
 * ONLY outbound payload, and it goes to an EXTERNAL system (the forge's delivery
 * log, readable by repo admins who need not be board members). It therefore
 * carries ids and booleans only - NEVER card titles or content - and a card id
 * it reports is either an echo of the request's own `kanso-<id>` branch name
 * (PR branch path) or restricted to PUBLIC cards. A TITLE-derived id is NOT an
 * echo: the sender supplied `PREFIX-<seq>` and we resolved it to an internal id
 * they never had, so naming it would leak the seq -> id mapping and confirm that
 * a non-public card exists. It therefore passes the same public-only gate as the
 * issue path (a non-public matched card is still linked and moved, just never
 * named). What the gate withholds is the card ID; a `handled: true` with
 * `cardId: 0` still tells the sender that the seq THEY supplied resolved to
 * something - the same residual the issue path has always had, and the reason
 * the endpoint is HMAC-gated per board rather than open.
 * Every card mutation runs as the board owner through CardService, whose
 * visibility gates (#3743) apply - a card hidden from the owner is untouched
 * (the no-op response every business-level miss produces).
 */
abstract class AbstractForgeWebhookService {
	/**
	 * How many distinct `PREFIX-<seq>` references a single PR title is read for
	 * (#9855). A PR closing a handful of cards is normal; a title carrying more
	 * than this is noise, and the cap bounds the per-delivery lookup cost.
	 */
	protected const MAX_TITLE_REFS = 5;

	/**
	 * Why a delivery did not move (or create) anything. A silent `handled: false`
	 * is undiagnosable, and the forge's own delivery log - already where a repo
	 * admin looks - is the natural place for the answer. These stay inside the
	 * egress rule above because they describe the REQUEST, never board content:
	 * no titles, no stack names, no card ids beyond what the rule already allows.
	 */
	public const REASON_MALFORMED_PAYLOAD = 'malformed_payload';
	public const REASON_UNSUPPORTED_EVENT = 'unsupported_event';
	/** Neither the head branch nor any title reference named a card on this board. */
	public const REASON_NO_CARD_MATCH = 'no_card_match';
	public const REASON_NO_LINK_MATCH = 'no_link_match';
	public const REASON_UNKNOWN_ACTION = 'unknown_action';
	public const REASON_NO_TARGET_STACK = 'no_target_stack';
	public const REASON_MOVE_BLOCKED = 'move_blocked';
	public const REASON_INTAKE_OFF = 'intake_off';
	public const REASON_INTAKE_FILTERED = 'intake_filtered';
	public const REASON_INTAKE_STALE_STACK = 'intake_stale_stack';
	public const REASON_INTAKE_DUPLICATE = 'intake_duplicate';
	public const REASON_INTAKE_CREATE_FAILED = 'intake_create_failed';

	public function __construct(
		protected BoardMapper $boardMapper,
		protected StackMapper $stackMapper,
		protected CardService $cardService,
		protected CardMapper $cardMapper,
		protected CardLinkService $cardLinkService,
		protected CardLinkMapper $cardLinkMapper,
		protected PermissionService $permissionService,
		protected CardVisibilityScope $visibilityScope,
		protected ISecureRandom $secureRandom,
		protected IURLGenerator $urlGenerator,
	) {
	}

	// ---- provider hooks ----------------------------------------------------

	/**
	 * Verifies the delivery's signature against the board's secret.
	 *
	 * @throws NotPermittedException if the webhook is disabled or the signature is absent/invalid
	 */
	abstract protected function verifySignature(Board $board, string $signatureHeader, string $rawBody): void;

	/**
	 * Normalizes a decoded payload into the shape the automation acts on.
	 *
	 * @param array<string, mixed> $payload
	 */
	abstract protected function normalize(array $payload): ForgeEvent;

	/** The route name of this provider's ingest endpoint. */
	abstract protected function routeName(): string;

	/** The CardLink provider tag links created by this service carry. */
	abstract protected function provider(): string;

	abstract protected function readSecret(Board $board): ?string;

	abstract protected function writeSecret(Board $board, ?string $secret): void;

	abstract protected function readIntakeStackId(Board $board): ?int;

	abstract protected function writeIntakeStackId(Board $board, ?int $stackId): void;

	abstract protected function readIntakeLabel(Board $board): ?string;

	abstract protected function writeIntakeLabel(Board $board, ?string $label): void;

	// ---- config (MANAGE) ---------------------------------------------------

	/**
	 * The board's webhook config for a MANAGE user: whether it's enabled, the
	 * payload URL to paste into the forge and the issue-intake settings. The
	 * secret itself is NOT returned here (it is shown once, on rotate).
	 *
	 * @return array{enabled: bool, payloadUrl: string, intakeStackId: int|null, intakeLabel: string}
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function getConfig(int $boardId, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);
		return $this->configArray($board);
	}

	/**
	 * Configures issue intake (#3752): the stack an opened issue's card is
	 * created in, plus an optional label filter. A null stack turns intake OFF
	 * (and drops the filter). Requires MANAGE.
	 *
	 * @return array{enabled: bool, payloadUrl: string, intakeStackId: int|null, intakeLabel: string}
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 * @throws InvalidInputException if the stack is not an alive stack of this board, or the label is too long
	 */
	public function setIntakeConfig(int $boardId, ?int $stackId, string $label, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);

		if ($stackId !== null && $this->findAliveStack($boardId, $stackId) === null) {
			throw new InvalidInputException('Stack does not belong to this board');
		}
		$label = trim($label);
		if (mb_strlen($label) > 100) {
			throw new InvalidInputException('Label filter must not exceed 100 characters');
		}

		$this->writeIntakeStackId($board, $stackId);
		// No stack = intake off; a stale filter must not linger behind it.
		$this->writeIntakeLabel($board, $stackId === null || $label === '' ? null : $label);
		$this->boardMapper->update($board);

		return $this->configArray($board);
	}

	/**
	 * Generates (or replaces) the board's webhook secret and returns it ONCE,
	 * alongside the payload URL. Requires MANAGE.
	 *
	 * @return array{secret: string, payloadUrl: string}
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function rotateSecret(int $boardId, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);

		$secret = $this->secureRandom->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->writeSecret($board, $secret);
		$this->boardMapper->update($board);

		return ['secret' => $secret, 'payloadUrl' => $this->payloadUrl($boardId)];
	}

	/**
	 * Disables the webhook by clearing the secret. Requires MANAGE. Idempotent.
	 *
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function disable(int $boardId, string $actorUid): void {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);
		if ($this->readSecret($board) !== null) {
			$this->writeSecret($board, null);
			$this->boardMapper->update($board);
		}
	}

	// ---- ingest ------------------------------------------------------------

	/**
	 * Verifies and processes an inbound delivery. Returns a small summary of what
	 * happened (for the 200 response body). NEVER throws for business-level
	 * no-ops (unknown branch, no target stack) - only for a failed signature
	 * check, which the controller maps to 401.
	 *
	 * @param string $signatureHeader the raw signature header value
	 * @param string $rawBody the exact request body bytes (HMAC is over these)
	 * @return array{handled: bool, action?: string, cardId?: int, moved?: bool, created?: bool, reason?: string}
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the signature is missing or invalid
	 */
	public function handleWebhook(int $boardId, string $signatureHeader, string $rawBody): array {
		$board = $this->loadBoard($boardId);
		$this->verifySignature($board, $signatureHeader, $rawBody);

		$payload = json_decode($rawBody, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			// Not JSON at all - in practice the webhook was created with the forge's
			// `application/x-www-form-urlencoded` content type, which sends the JSON
			// wrapped as `payload=<urlencoded>`. The forge signs whatever it sends,
			// so that body PASSES the HMAC check above and would otherwise be
			// recorded as a green 200 while nothing happens on the board -
			// undiagnosable from the delivery log. Say so instead: the controller
			// maps this to a 400.
			throw new NonJsonWebhookBodyException('Webhook body is not JSON - set the webhook Content type to application/json');
		}
		if (!is_array($payload)) {
			// Valid JSON that is a bare scalar / null - accepted, nothing to do (#3477).
			return $this->noop(self::REASON_MALFORMED_PAYLOAD);
		}

		$event = $this->normalize($payload);
		return match ($event->kind) {
			ForgeEvent::KIND_PR => $this->handlePullRequestEvent($board, $event),
			ForgeEvent::KIND_ISSUE => $this->handleIssueEvent($board, $event),
			default => $this->noop(self::REASON_UNSUPPORTED_EVENT),
		};
	}

	/**
	 * The accepted no-op every business-level miss returns, carrying why.
	 *
	 * @return array{handled: bool, reason: string}
	 */
	protected function noop(string $reason): array {
		return ['handled' => false, 'reason' => $reason];
	}

	/**
	 * A pull-request event: the card is named by the PR's `kanso-<id>` head
	 * branch; the PR is recorded as a link and the card auto-moved per action.
	 *
	 * @return array{handled: bool, action?: string, cardId?: int, moved?: bool, reason?: string}
	 */
	protected function handlePullRequestEvent(Board $board, ForgeEvent $event): array {
		$boardId = $board->getId();

		// cardId => whether the id is an echo of the request's own branch name
		// (and so may be named outward without the public-only gate).
		/** @var array<int, bool> $targets */
		$targets = [];
		$branchCardId = $this->cardIdFromBranch($event->branch);
		// The branch names a card - it must live on THIS board and be alive.
		if ($branchCardId !== null && $this->cardIsOnBoard($branchCardId, $board)) {
			$targets[$branchCardId] = true;
		}
		foreach ($this->cardIdsFromTitle($board, $event->title ?? '') as $titleCardId) {
			// Never downgrade the branch card's echo flag on a title that also
			// references it - the union dedupes to one card, one link, one move.
			$targets[$titleCardId] ??= false;
		}
		if ($targets === []) {
			return $this->noop(self::REASON_NO_CARD_MATCH);
		}

		$moved = false;
		$namedCardId = 0;
		$lastReason = '';
		foreach ($targets as $cardId => $isEcho) {
			// Record the PR as a link on the card (best-effort, idempotent) and
			// cache its state/title straight from the delivery. The payload is
			// authoritative and free, so this must not be left to the read-time
			// poll: addLink() only polls on INSERT, so the second delivery for a
			// PR (opened -> merged) hits its unique-constraint branch and would
			// otherwise never refresh the state.
			if ($event->htmlUrl !== '') {
				try {
					$link = $this->cardLinkService->addLink($cardId, $event->htmlUrl, $board->getOwner());
					$this->cacheLinkState($link, $event);
				} catch (\Throwable) {
					// Non-critical - a bad/duplicate URL must not fail the webhook.
				}
			}
			[$cardMoved, $reason] = $this->applyPrAutoMove($boardId, $cardId, $event, $board->getOwner());
			if ($cardMoved) {
				$moved = true;
			} else {
				$lastReason = $reason;
			}
			if ($namedCardId === 0 && ($isEcho || $this->isPublicCard($cardId))) {
				$namedCardId = $cardId;
			}
		}

		$result = ['handled' => true, 'action' => $event->action, 'cardId' => $namedCardId, 'moved' => $moved];
		// Only when NOTHING moved is a reason unambiguous - with a mixed batch the
		// per-card detail would need per-card ids, which the egress rule forbids.
		if (!$moved && $lastReason !== '') {
			$result['reason'] = $lastReason;
		}
		return $result;
	}

	/**
	 * Whether the internal card id resolves to an alive card of THIS board, read
	 * as the board owner. Swallows the lookup's exceptions: a webhook delivery
	 * must never 5xx on a business-level miss (a forge disables a hook that does).
	 */
	protected function cardIsOnBoard(int $cardId, Board $board): bool {
		try {
			return $this->cardService->find($cardId, $board->getOwner())->getBoardId() === $board->getId();
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * The cards a PR title references as `PREFIX-<seq>` (#9855), in title order.
	 *
	 * Matching is ONE anchored pass built from the board's own prefix rather than
	 * a generic `[A-Z][A-Z0-9]*-\d+` scan: a generic scan hits `UTF-8`,
	 * `SHA-256`, `ISO-8601`, `AGPL-3` and `GH-123`, and each hit would cost a
	 * findByRef that reloads the board and re-runs the permission check only to
	 * fail the prefix comparison.
	 *
	 * The pass is deliberately CASE-SENSITIVE, and that is a correctness rule,
	 * not pedantry: a stored prefix is always uppercase (BoardPrefix), the UI
	 * renders references uppercase - and a board titled "Kanso" derives the
	 * prefix `KANSO`, which case-insensitively collides with the lowercase
	 * `kanso-<id>` BRANCH spelling. A title quoting that branch ("Merge kanso-42
	 * into main") would otherwise be read as seq 42 and link + auto-move whatever
	 * unrelated card holds that board_seq. Excluding a trailing `[\w-]` covers
	 * the `kanso-<id>-slug` form for the same reason.
	 *
	 * Resolution goes through {@see CardService::findByRef} as the board owner,
	 * which is board-scoped, prefix-checked and viewer-scoped - so an unknown,
	 * trashed or hidden card is simply not a match. It THROWS for a missing board
	 * or a permission failure, which this service's contract forbids surfacing,
	 * so every call is wrapped.
	 *
	 * @return int[] distinct card ids, from at most self::MAX_TITLE_REFS references
	 */
	protected function cardIdsFromTitle(Board $board, string $title): array {
		if (trim($title) === '') {
			return [];
		}
		$prefix = $board->getPrefix() ?? BoardPrefix::DEFAULT;
		if ($prefix === '') {
			return [];
		}

		// Bounded digits: a longer run cannot be a real board_seq, and the bound
		// keeps `(int)` from silently rewriting it to PHP_INT_MAX.
		$pattern = '/(?<![A-Za-z0-9])' . preg_quote($prefix, '/') . '-(\d{1,9})(?![\w-])/';
		if (preg_match_all($pattern, $title, $matches) < 1) {
			return [];
		}

		$seqs = [];
		foreach ($matches[1] as $digits) {
			$seq = (int)$digits;
			if ($seq > 0) {
				$seqs[$seq] = true;
			}
		}
		$cardIds = [];
		foreach (array_slice(array_keys($seqs), 0, self::MAX_TITLE_REFS) as $seq) {
			try {
				$card = $this->cardService->findByRef($board->getId(), $prefix . '-' . $seq, $board->getOwner());
			} catch (\Throwable) {
				continue;
			}
			if ($card !== null) {
				$cardIds[] = $card->getId();
			}
		}
		return $cardIds;
	}

	/**
	 * An issue event: an issue has no branch, so its cards are matched in
	 * reverse - alive cards on this board with the issue's URL attached as a
	 * link. The link's cached state/title is refreshed from the payload (fresher
	 * and cheaper than a read-time poll), then each card is auto-moved per
	 * action.
	 *
	 * An `opened` issue nobody has linked yet is the intake case (#3752): when
	 * the board configured an intake stack (and the issue passes the optional
	 * label filter), a link-only card is auto-created for it.
	 *
	 * @return array{handled: bool, action?: string, cardId?: int, moved?: bool, created?: bool, reason?: string}
	 */
	protected function handleIssueEvent(Board $board, ForgeEvent $event): array {
		$links = $this->cardLinkMapper->findByBoardAndUrls($board->getId(), $event->urlCandidates);
		if ($links === []) {
			if ($event->action === 'opened') {
				return $this->intakeIssue($board, $event);
			}
			return $this->noop(self::REASON_NO_LINK_MATCH);
		}

		foreach ($links as $link) {
			$this->cacheLinkState($link, $event);
		}

		$moved = false;
		$firstCardId = 0;
		$lastReason = '';
		foreach ($links as $link) {
			$cardId = $link->getCardId();
			// Egress rule (#3760): the response goes to an external system, so
			// only a PUBLIC card's id may be named in it. Non-public cards are
			// still processed (their moves run as the board owner, gated by the
			// owner's visibility) - they are just never confirmed outward.
			if ($firstCardId === 0 && $this->isPublicCard($cardId)) {
				$firstCardId = $cardId;
			}
			[$cardMoved, $reason] = $this->applyIssueAutoMove($board->getId(), $cardId, $event->action, $board->getOwner());
			if ($cardMoved) {
				$moved = true;
			} else {
				$lastReason = $reason;
			}
		}

		$result = ['handled' => true, 'action' => $event->action, 'cardId' => $firstCardId, 'moved' => $moved];
		if (!$moved && $lastReason !== '') {
			$result['reason'] = $lastReason;
		}
		return $result;
	}

	/**
	 * Issue intake (#3752): auto-creates a LINK-ONLY card for a just-opened
	 * issue in the board's configured intake stack. Opt-in and defensive - any
	 * failed precondition degrades to the accepted no-op the webhook always
	 * returns for business-level misses:
	 *
	 *  - no intake stack configured (the default) → off;
	 *  - a configured label filter the issue doesn't carry → filtered out;
	 *  - a stale stack (deleted / moved off this board) → off until re-configured;
	 *  - the issue already linked ANYWHERE on the board - alive, archived or
	 *    trashed card - → dedup, a redelivery never creates a duplicate.
	 *
	 * The dedup is check-then-act (no unique index spans a link URL and the
	 * card's board), so two CONCURRENT deliveries of the same opened event
	 * could in principle both pass it. Accepted residual race: a forge delivers
	 * redeliveries sequentially in practice, and the worst case is one extra
	 * card a human deletes - not worth a cross-table constraint.
	 *
	 * @return array{handled: bool, action?: string, cardId?: int, moved?: bool, created?: bool, reason?: string}
	 */
	protected function intakeIssue(Board $board, ForgeEvent $event): array {
		$stackId = $this->readIntakeStackId($board);
		if ($stackId === null || $stackId <= 0) {
			return $this->noop(self::REASON_INTAKE_OFF);
		}
		$labelFilter = trim($this->readIntakeLabel($board) ?? '');
		if ($labelFilter !== '' && !$event->hasLabel($labelFilter)) {
			return $this->noop(self::REASON_INTAKE_FILTERED);
		}
		$stack = $this->findAliveStack($board->getId(), $stackId);
		if ($stack === null) {
			return $this->noop(self::REASON_INTAKE_STALE_STACK);
		}
		if ($this->cardLinkMapper->existsByBoardAndUrls($board->getId(), $event->urlCandidates)) {
			return $this->noop(self::REASON_INTAKE_DUPLICATE);
		}

		$title = trim($event->title ?? '');
		if (mb_strlen($title) > CardService::MAX_TITLE_LENGTH) {
			$title = trim(mb_substr($title, 0, CardService::MAX_TITLE_LENGTH));
		}
		// AFTER truncation, so a degenerate title can never reach create() empty.
		if ($title === '') {
			$title = 'Issue ' . $this->issueLabelFromUrl($event->htmlUrl);
		}

		try {
			$card = $this->cardService->create($stack->getId(), $title, $board->getOwner());
		} catch (\Throwable) {
			// e.g. a concurrent create storm exhausting the sort-key retry - the
			// delivery is still accepted; the forge must not see a 5xx and disable
			// the hook.
			return $this->noop(self::REASON_INTAKE_CREATE_FAILED);
		}

		// Attach the issue as a link with its state/title cached from the payload
		// (authoritative - no poll needed). Best-effort: a failed link insert
		// leaves a plain card rather than failing the delivery.
		$link = new CardLink();
		$link->setCardId($card->getId());
		$link->setProvider($this->provider());
		$link->setUrl($event->htmlUrl);
		$link->setKind(CardLink::KIND_ISSUE);
		$link->setState($event->state);
		$link->setTitle($event->title);
		$now = time();
		$link->setLastPolled($now);
		$link->setCreatedAt($now);
		try {
			$this->cardLinkMapper->insert($link);
		} catch (\Throwable) {
			// Non-critical - the card exists; the link can be added manually.
		}

		return [
			'handled' => true,
			'action' => 'opened',
			'cardId' => $card->getId(),
			'moved' => false,
			'created' => true,
		];
	}

	// ---- shared helpers ----------------------------------------------------

	/**
	 * Caches a link's state/title straight from the delivery and stamps
	 * last_polled so the read-time throttle doesn't immediately re-poll.
	 * Best-effort: a failed row update must not fail the delivery.
	 */
	protected function cacheLinkState(CardLink $link, ForgeEvent $event): void {
		$link->setState($event->state);
		if ($event->title !== null) {
			$link->setTitle($event->title);
		}
		$link->setLastPolled(time());
		try {
			$this->cardLinkMapper->update($link);
		} catch (\Throwable) {
			// Non-critical - the next read-time poll will catch up.
		}
	}

	/**
	 * Whether the card may be named in the response body - the external-egress
	 * gate (#3760): PUBLIC cards only, per {@see CardVisibilityScope::isPublic()}.
	 * A purged card is simply not public.
	 */
	protected function isPublicCard(int $cardId): bool {
		try {
			return $this->visibilityScope->isPublic($this->cardMapper->find($cardId));
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * The configured stack, when it still is an alive stack of this board -
	 * null otherwise (deleted, moved, or never valid).
	 */
	protected function findAliveStack(int $boardId, int $stackId): ?Stack {
		try {
			$stack = $this->stackMapper->find($stackId);
		} catch (\Throwable) {
			return null;
		}
		if ($stack->getBoardId() !== $boardId || $stack->getDeletedAt() > 0) {
			return null;
		}
		return $stack;
	}

	/**
	 * Moves the card per the PR action, if the board has a matching role-stack.
	 * A merged PR → ROLE_DONE (stamps done); an opened/reopened/ready PR →
	 * ROLE_REVIEW. Returns whether a move actually happened.
	 */
	protected function applyPrAutoMove(int $boardId, int $cardId, ForgeEvent $event, string $actorUid): array {
		$targetRole = null;
		if ($event->merged) {
			$targetRole = Stack::ROLE_DONE;
		} elseif (in_array($event->action, ['opened', 'reopened', 'ready_for_review'], true)) {
			$targetRole = Stack::ROLE_REVIEW;
		}
		if ($targetRole === null) {
			return [false, self::REASON_UNKNOWN_ACTION];
		}

		try {
			$target = $this->stackMapper->findByBoardAndRole($boardId, $targetRole);
			if ($target === null) {
				return [false, self::REASON_NO_TARGET_STACK];
			}
			$this->cardService->move($cardId, $target->getId(), null, $actorUid);
			return [true, ''];
		} catch (\Throwable) {
			// e.g. the review gate blocks a merge while reviews are unapproved -
			// the link is still recorded; the move is simply skipped. The stack
			// lookup is inside the try too: a title can match several cards, so a
			// DB error here would otherwise escape as a 5xx and disable the hook.
			return [false, self::REASON_MOVE_BLOCKED];
		}
	}

	/**
	 * Moves a card per the issue action, if the board has a matching role-stack.
	 * Closed → ROLE_DONE (stamps done); reopened → ROLE_IN_PROGRESS, falling
	 * back to ROLE_TODO when the board has no in-progress stack. Purely
	 * role-based - a board without the role stack simply doesn't move, exactly
	 * like the PR path.
	 *
	 * @return array{0: bool, 1: string} [moved, reason]; reason is '' when moved
	 */
	protected function applyIssueAutoMove(int $boardId, int $cardId, string $action, string $actorUid): array {
		$roles = [];
		if ($action === 'closed') {
			$roles = [Stack::ROLE_DONE];
		} elseif ($action === 'reopened') {
			$roles = [Stack::ROLE_IN_PROGRESS, Stack::ROLE_TODO];
		}
		if ($roles === []) {
			return [false, self::REASON_UNKNOWN_ACTION];
		}

		$target = null;
		foreach ($roles as $role) {
			$target = $this->stackMapper->findByBoardAndRole($boardId, $role);
			if ($target !== null) {
				break;
			}
		}
		if ($target === null) {
			return [false, self::REASON_NO_TARGET_STACK];
		}

		try {
			$this->cardService->move($cardId, $target->getId(), null, $actorUid);
			return [true, ''];
		} catch (\Throwable) {
			// Best-effort, like the PR path - the state refresh already happened.
			return [false, self::REASON_MOVE_BLOCKED];
		}
	}

	protected function cardIdFromBranch(string $branch): ?int {
		if (preg_match('/^kanso-(\d+)(?:-.*)?$/', $branch, $m) === 1) {
			return (int)$m[1];
		}
		return null;
	}

	/**
	 * The `#<number>` suffix for a fallback intake title, read back off the
	 * issue URL so the automation needs no separate number field.
	 */
	protected function issueLabelFromUrl(string $url): string {
		if (preg_match('#/(\d+)/?$#', $url, $m) === 1) {
			return '#' . $m[1];
		}
		return 'from ' . $url;
	}

	protected function payloadUrl(int $boardId): string {
		return $this->urlGenerator->linkToRouteAbsolute($this->routeName(), ['id' => $boardId]);
	}

	/**
	 * The MANAGE-facing config shape shared by getConfig and setIntakeConfig.
	 *
	 * @return array{enabled: bool, payloadUrl: string, intakeStackId: int|null, intakeLabel: string}
	 */
	protected function configArray(Board $board): array {
		return [
			'enabled' => ($this->readSecret($board) ?? '') !== '',
			'payloadUrl' => $this->payloadUrl($board->getId()),
			'intakeStackId' => $this->readIntakeStackId($board),
			'intakeLabel' => $this->readIntakeLabel($board) ?? '',
		];
	}

	/**
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 */
	protected function loadBoard(int $boardId): Board {
		$board = $this->boardMapper->find($boardId);
		if ($board->getDeletedAt() > 0) {
			throw new DoesNotExistException('Board ' . $boardId . ' is deleted');
		}
		return $board;
	}
}
