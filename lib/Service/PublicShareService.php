<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardFeatures;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\Comment;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;

/**
 * Public / read-only board share links (#3531) - Deck's most-requested feature.
 *
 * A board opts in when a MANAGE user mints a per-board `public_share_token`
 * (64-char ISecureRandom, so ~380 bits of entropy - unguessable, and the only
 * thing that addresses the board publicly). An unauthenticated reader who holds
 * the token gets a STRIPPED, read-only snapshot of the board through
 * {@see self::getPublicBoard()}.
 *
 * Security posture (the app's only unauthenticated read surface for board CONTENT
 * - {@see \OCA\Kanso\Controller\CalendarFeedController} is also a public page, but
 * it emits titles and a link, never free text):
 *  - OFF by default; enabling/rotating/disabling all require MANAGE.
 *  - The token column is UNIQUE, so a token resolves to EXACTLY one board - it
 *    can never be pivoted to another board.
 *  - Disable clears the token and rotate replaces it, so a stale token stops
 *    resolving on the very next request (revocable + rotatable).
 *  - {@see self::getPublicBoard()} builds its OWN payload from a deliberately
 *    narrow field list. It NEVER reuses the authenticated board payload, and it
 *    NEVER touches assignees, watchers, activity/changes, ACL/members, owner
 *    uids, reviews, or the webhook config. Only board title + stacks + per-card
 *    {title, description, labels, dates, cover colour, estimate, checklist
 *    counts, priority, status, human id} are exposed - nothing that identifies a
 *    person or leaks internal metadata. Archived stacks/cards are omitted.
 *  - The ONE opt-in exception (#3949): a MANAGE user may DELIBERATELY widen the
 *    link with the `public_share_comments` toggle (OFF by default). When ON, and
 *    only then, each public card also carries a read-only comment thread - author
 *    DISPLAY NAME only (never a uid), body, timestamps, one-level parent link;
 *    still no reactions, members or activity. OFF keeps comments out entirely.
 *  - The two FREE-TEXT fields are the one place a uid can ride board content
 *    rather than a person field, because a `@mention` has no entity table and is
 *    stored as a literal `@uid` inside the text itself. Both are therefore run
 *    through {@see self::redactMentions()} on the way out, so the "uid never
 *    leaves" invariant covers the description and the comment body too, not just
 *    the author byline. The stored rows are untouched - this is payload-only.
 *    NOTE the deliberate trade this makes: a mentioned person's DISPLAY NAME can now
 *    appear on a description even with the comments opt-in off, where previously the
 *    same text carried their login uid. A name is not nothing - but it is the thing
 *    the person publishes as, whereas a uid is half of a credential pair and the
 *    handle for user enumeration against the login form, so the baseline is
 *    person-LIGHT rather than person-free, on purpose.
 *  - Only those two fields are redacted. A `@name` typed into a card/stack/board
 *    title or a label name is served VERBATIM, as all board content is: those are
 *    not mention surfaces ({@see MentionService} is only wired to descriptions and
 *    comment bodies), the autocomplete never writes a uid there, and the suite pins
 *    titles as byte-exact (the "substring-immune" case in
 *    tests/e2e/public-share.spec.js). Redacting them would mangle ordinary text for
 *    no mention to fix.
 *  - An unknown/disabled/rotated/expired token raises DoesNotExistException,
 *    which the controller maps to a throttled 404 (no oracle beyond the throttle,
 *    and no distinction between "wrong token" and "disabled board").
 */
class PublicShareService {
	private const TOKEN_LENGTH = 64;

	public function __construct(
		private BoardMapper $boardMapper,
		private StackMapper $stackMapper,
		private CardMapper $cardMapper,
		private CardLabelMapper $cardLabelMapper,
		private ChecklistItemMapper $checklistItemMapper,
		private LabelMapper $labelMapper,
		private CommentMapper $commentMapper,
		private PermissionService $permissionService,
		private ISecureRandom $secureRandom,
		private IURLGenerator $urlGenerator,
		private IUserManager $userManager,
		private IL10N $l10n,
	) {
	}

	/**
	 * The board's public-share config for a MANAGE user: whether it's enabled and
	 * the public URL (present only when enabled). The token itself IS included so
	 * the settings UI can render/copy the live link - it is board content the
	 * MANAGE user already controls, unlike the webhook secret. Requires MANAGE.
	 *
	 * @return array{enabled: bool, token: ?string, url: ?string, expiresAt: ?int, commentsEnabled: bool}
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function getConfig(int $boardId, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);
		return $this->configPayload($board);
	}

	/**
	 * Enables the public link (or rotates an existing one) by minting a fresh
	 * token, and returns the new config incl. the token + URL. Requires MANAGE.
	 * Any previously-issued link stops working immediately.
	 *
	 * @return array{enabled: bool, token: ?string, url: ?string, expiresAt: ?int, commentsEnabled: bool}
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function enable(int $boardId, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);

		$board->setPublicShareToken($this->secureRandom->generate(self::TOKEN_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC));
		$this->boardMapper->update($board);

		return $this->configPayload($board);
	}

	/**
	 * Disables the public link by clearing the token. Requires MANAGE. Idempotent
	 * - a board with no link stays that way. The old token 404s from now on.
	 *
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function disable(int $boardId, string $actorUid): void {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);
		if ($board->getPublicShareToken() !== null) {
			$board->setPublicShareToken(null);
			$board->setPublicShareExpiresAt(null);
			$this->boardMapper->update($board);
		}
	}

	/**
	 * Sets the "show comments (read-only)" opt-in for the public link. Requires
	 * MANAGE. Independent of enable/disable: a MANAGE user can pre-set it, and it
	 * persists across rotate. When ON, {@see self::getPublicBoard()} widens the
	 * anonymous payload to include each public card's read-only comment thread
	 * (author display name only). OFF (the default) keeps the person-free baseline.
	 *
	 * @return array{enabled: bool, token: ?string, url: ?string, expiresAt: ?int, commentsEnabled: bool}
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function setComments(int $boardId, bool $enabled, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);

		if (($board->getPublicShareComments() ?? false) !== $enabled) {
			$board->setPublicShareComments($enabled);
			$this->boardMapper->update($board);
		}

		return $this->configPayload($board);
	}

	/**
	 * The STRIPPED, read-only public snapshot of the board a token points at.
	 * This is the ONLY method that runs without a session, so it is deliberately
	 * conservative: it builds its own narrow payload and never reads comments (until
	 * opted in), ACL, members, reviews, activity or webhook data. The one people
	 * lookup it does make is {@see self::resolveDisplayName()}, and it exists to
	 * REMOVE uids from the payload, never to add anything to it.
	 *
	 * When the board's `public_share_comments` opt-in is ON (#3949), each card
	 * also carries a `comments` list - a read-only thread of author DISPLAY NAME
	 * (never a uid), body, timestamps and one-level parent link, scoped to the
	 * public card set via {@see CommentMapper::findByBoardPublicOnly()}. No
	 * reactions, no members, no activity. When OFF (default), no `comments` key
	 * is added and the person-free baseline holds.
	 *
	 * Both free-text fields - every card `description` and, when opted in, every
	 * comment `body` - pass through {@see self::redactMentions()}, which substitutes
	 * display names for the `@uid` mentions stored inside them.
	 *
	 * @return array{
	 *   board: array{title: ?string, color: ?string, prefix: string, commentsEnabled: bool, cardFeatures: array<string, bool>},
	 *   stacks: list<array{id: int, title: ?string, color: ?string}>,
	 *   cards: list<array{id: int, stackId: ?int, title: ?string, description: ?string, labels: list<array{name: ?string, color: ?string}>, duedate: ?string, coverColor: ?string, startDate: ?string, estimate: ?string, allDay: bool, priority: int, type: string, status: string, humanId: ?string, checklist: array{total: int, done: int}, comments?: list<array{id: int, parentCommentId: ?int, author: string, body: ?string, createdAt: int, editedAt: int}>}>
	 * }
	 * @throws DoesNotExistException if the token is unknown, disabled, or expired
	 */
	public function getPublicBoard(string $token): array {
		$board = $this->boardMapper->findByPublicToken($token);

		// Defence in depth: findByPublicToken already excludes deleted boards and
		// empty tokens, but re-assert the token is really set (never serve a board
		// whose token was concurrently cleared) and honour any expiry.
		if (($board->getPublicShareToken() ?? '') === '') {
			throw new DoesNotExistException('Public share is disabled');
		}
		$expiresAt = $board->getPublicShareExpiresAt();
		if ($expiresAt !== null && $expiresAt > 0 && $expiresAt <= time()) {
			throw new DoesNotExistException('Public share has expired');
		}

		$boardId = (int)$board->getId();

		// Labels by their id, so a card can carry {name, color} only (never the
		// label's numeric id or board id - display data only).
		$labelsById = [];
		foreach ($this->labelMapper->findByBoard($boardId) as $label) {
			/** @var Label $label */
			$labelsById[(int)$label->getId()] = $label;
		}
		// Anonymous viewer: label links and checklist counts over PUBLIC cards
		// only (#3743) - never fetch a hidden card's rows just to discard them.
		$labelIdsByCard = $this->cardLabelMapper->findLabelIdsByBoardPublicOnly($boardId);
		$checklistByCard = $this->checklistItemMapper->progressByBoardPublicOnly($boardId);

		// Comments opt-in (#3949): ONLY fetch (and only ever expose) comments when
		// the MANAGE user deliberately enabled the toggle for this share. Also over
		// PUBLIC cards only, so a hidden card's discussion never surfaces.
		$commentsEnabled = $board->getPublicShareComments() ?? false;
		$commentsByCard = $commentsEnabled ? $this->commentMapper->findByBoardPublicOnly($boardId) : [];
		// uid => display name, or NULL when the account no longer resolves. Shared
		// by every uid lookup this request makes - comment authors AND the mentions
		// redacted out of free text - so one uid costs at most one
		// IUserManager::get() no matter how many cards or comments name it. The null
		// is cached too: an unresolvable token must not be re-looked-up per card.
		/** @var array<string, ?string> $displayNames */
		$displayNames = [];

		// Only NON-archived stacks, in display order; drop the internal board id.
		$stacks = [];
		$liveStackIds = [];
		foreach ($this->stackMapper->findByBoard($boardId) as $stack) {
			/** @var Stack $stack */
			if ($stack->getArchived()) {
				continue;
			}
			$liveStackIds[(int)$stack->getId()] = true;
			$stacks[] = [
				'id' => (int)$stack->getId(),
				'title' => $stack->getTitle(),
				'color' => $stack->getColor(),
			];
		}

		$prefix = $board->jsonSerialize()['prefix'];
		$cards = [];
		foreach ($this->cardMapper->findPublicByBoard($boardId) as $card) {
			/** @var Card $card */
			if ($card->getArchived()) {
				continue;
			}
			// A card whose stack is archived is not shown on a public board either.
			if (!isset($liveStackIds[(int)$card->getStackId()])) {
				continue;
			}

			$cardId = (int)$card->getId();
			$labels = [];
			foreach ($labelIdsByCard[$cardId] ?? [] as $labelId) {
				$label = $labelsById[(int)$labelId] ?? null;
				if ($label !== null) {
					$labels[] = ['name' => $label->getTitle(), 'color' => $label->getColor()];
				}
			}

			$seq = $card->getBoardSeq();
			$cardPayload = [
				'id' => $cardId,
				'stackId' => $card->getStackId(),
				'title' => $card->getTitle(),
				// Descriptions ARE part of the public snapshot per the share model -
				// board content, and nothing else is added. But board content is not
				// automatically person-FREE: a `@mention` has no entity table and lives
				// as a literal `@uid` inside the description text, so the raw column
				// would hand an anonymous reader a real login uid. It goes out with
				// those mentions redacted; the stored row is untouched.
				'description' => $this->redactMentions($card->getDescription(), $displayNames),
				'labels' => $labels,
				'duedate' => $card->getDuedate()?->format(\DateTimeInterface::ATOM),
				// Presentational, non-person card attributes (#3951): a cover colour
				// band, the start date, and the estimate. These are board content, not
				// person identifiers - no assignees/comments/members/activity here.
				'coverColor' => $card->getCoverColor(),
				'startDate' => $card->getStartDate()?->format(\DateTimeInterface::ATOM),
				'estimate' => $card->getEstimate(),
				'allDay' => $card->getAllDay() ?? false,
				'priority' => $card->getPriority() ?? 0,
				'type' => $card->getType() ?? '',
				// The card-status vocabulary, spelled exactly as everywhere else:
				// done / in_progress / not_started. It used to say 'open' for the
				// third value, which collides head-on with the filter vocabulary,
				// where 'open' means "not done" and so INCLUDES in-progress
				// ({@see ViewFilter::DONE}) - the same token naming two different
				// sets. 'not_started' is unambiguous and matches the status control.
				'status' => ($card->getDoneAt() ?? 0) > 0 ? 'done' : (($card->getStartedAt() ?? 0) > 0 ? 'in_progress' : 'not_started'),
				'humanId' => $seq !== null ? $prefix . '-' . $seq : null,
				'checklist' => $checklistByCard[$cardId] ?? ['total' => 0, 'done' => 0],
			];

			if ($commentsEnabled) {
				$cardPayload['comments'] = $this->serializeComments($commentsByCard[$cardId] ?? [], $displayNames);
			}

			$cards[] = $cardPayload;
		}

		return [
			'board' => [
				'title' => $board->getTitle(),
				'color' => $board->getColor(),
				'prefix' => $prefix,
				'commentsEnabled' => $commentsEnabled,
				// Built-in card sections (#5894). The public link is ONE board, so a
				// section its manager switched off stays hidden here too. Only cover
				// colour has a public surface today; the map is emitted whole so a
				// future public surface needs no payload change.
				'cardFeatures' => CardFeatures::decode($board->getDisabledCardFeatures()),
			],
			'stacks' => $stacks,
			'cards' => $cards,
		];
	}

	/**
	 * The read-only public serialization of one card's comment thread (#3949).
	 * Emits ONLY {id, parentCommentId, author DISPLAY NAME, body, timestamps} -
	 * deliberately NO uid (the display name is resolved from the uid, like the
	 * authenticated comment endpoint, but the uid itself never leaves), NO
	 * reactions, NO reactor lists. A one-level thread: a reply carries its
	 * top-level parent id and the client nests by it.
	 *
	 * When the author account no longer exists (deleted user), the display-name
	 * lookup returns null; we emit a generic "Former user" label rather than
	 * falling back to the raw uid, so a deleted account's uid never leaks onto an
	 * unauthenticated link (the "uid never leaves" invariant holds even then).
	 *
	 * The BODY is free text and carries mentions as literal `@uid` tokens, so it is
	 * redacted on the way out exactly like a card description
	 * ({@see self::redactMentions()}) - the author byline was never the only place a
	 * uid could ride a public comment.
	 *
	 * @param Comment[] $comments
	 * @param array<string, ?string> $displayNames uid => display name (null = no such account) cache, reused across cards
	 * @return list<array{id: int, parentCommentId: ?int, author: string, body: ?string, createdAt: int, editedAt: int}>
	 */
	private function serializeComments(array $comments, array &$displayNames): array {
		$out = [];
		foreach ($comments as $comment) {
			$uid = (string)$comment->getAuthor();
			// A deleted author resolves to null; never leak the raw uid on the public
			// link - substitute a generic, translatable label instead. Unlike a bare
			// `@token` in free text, this field is KNOWN to be a uid, so the fallback
			// is unambiguous here.
			$author = $this->resolveDisplayName($uid, $displayNames) ?? $this->l10n->t('Former user');
			$out[] = [
				'id' => (int)$comment->getId(),
				'parentCommentId' => $comment->getParentCommentId(),
				'author' => $author,
				'body' => $this->redactMentions($comment->getBody(), $displayNames),
				'createdAt' => $comment->getCreatedAt() ?? 0,
				'editedAt' => $comment->getEditedAt() ?? 0,
			];
		}
		return $out;
	}

	/**
	 * One uid's display name, or NULL when no such account exists (deleted, or
	 * never existed). The ONLY place this class resolves a uid, and it is memoised
	 * in the caller's request-scoped cache: one {@see IUserManager::get()} per
	 * DISTINCT uid per public read, whatever names it in however many places. The
	 * null result is cached too - `array_key_exists`, not `isset`, is what makes
	 * that work.
	 *
	 * @param array<string, ?string> $displayNames uid => display name (null = no such account)
	 */
	private function resolveDisplayName(string $uid, array &$displayNames): ?string {
		if (!array_key_exists($uid, $displayNames)) {
			$user = $this->userManager->get($uid);
			$displayNames[$uid] = $user?->getDisplayName();
		}
		return $displayNames[$uid];
	}

	/**
	 * Free text (a card description or a comment body) with every `@mention` of a
	 * REAL account rewritten to that account's display name. Payload-only: the
	 * stored row is never rewritten, so the mention keeps notifying, keeps
	 * rendering as a chip for authenticated viewers, and no migration ever touches
	 * user content.
	 *
	 * This is the free-text half of the "uid never leaves" invariant. A mention has
	 * no entity table - it IS the literal string `@uid` inside these two fields
	 * ({@see MentionService::MENTION_PATTERN}, reused here rather than restated so
	 * the two cannot drift) - and the anonymous page renders those tokens as chips.
	 * So a description reading "@jsmith please review" used to hand an
	 * unauthenticated token holder a real LOGIN uid for somebody who appears
	 * nowhere else in the payload, in the DEFAULT configuration (descriptions ship
	 * with comments toggled off).
	 *
	 * A token that does NOT resolve to an account is left BYTE-IDENTICAL. `@2pm`,
	 * `@nextcloud`, a price, a social handle and `foo@bar.com` are ordinary board
	 * content, and mangling them would be its own bug - so unlike the comment-author
	 * byline there is no "Former user" fallback here: a bare `@token` is
	 * indistinguishable from prose, and a mention of a DELETED account therefore
	 * keeps its literal text. That residue is the weakest of the three cases (the
	 * account cannot be logged into, so it is not half of a credential pair) and
	 * the alternative - rewriting every unresolvable `@word` - would corrupt real
	 * descriptions on every board.
	 *
	 * The substituted name goes through {@see self::inlineSafeName()}, because it is
	 * spliced into somebody ELSE's markdown: a mentioned user who renames themselves
	 * `[click](https://evil.example)` must not thereby inject a link into another
	 * author's description on an anonymous page.
	 *
	 * Two behaviours are deliberate rather than accidental, and both are shared with
	 * the mention feature itself (same pattern, so the public view cannot disagree
	 * with what the app calls a mention):
	 *  - A resolving uid is substituted even inside a URL path or a code span
	 *    (`https://forge.example/@jsmith`, `` `@jsmith` ``). The token really is that
	 *    person's uid there, so hiding it wins over keeping the link clickable - and
	 *    the authenticated renderer already chips the URL case (src/services/markdown.js).
	 *  - A `@`-token that is not a mention by that pattern is not redacted, so a uid
	 *    shape the pattern cannot express - an email-shaped or space-bearing uid, as
	 *    LDAP/SAML instances issue - survives. Such a uid never notified anyone either
	 *    ({@see MentionService} says so); widening the charset is a change to what a
	 *    mention IS, app-wide, not to this method.
	 *
	 * Note the display name can legitimately BE the uid - Nextcloud falls back to
	 * the uid when an account has no display name set - in which case the
	 * substitution is a no-op and the uid still shows. Not fixable here: on such an
	 * instance the uid IS the person's name everywhere else in the product, and
	 * answering with a generic label instead would blank every mention on the board.
	 *
	 * @param array<string, ?string> $displayNames uid => display name (null = no such account), shared cache
	 */
	private function redactMentions(?string $text, array &$displayNames): ?string {
		if ($text === null) {
			return null;
		}
		// Cheap bail-out for the overwhelmingly common mention-free field, so the
		// regex only ever runs over text that could possibly hold a mention.
		if (!str_contains($text, '@')) {
			return $text;
		}

		// The per-FIELD bound on how many NEW uids one field may resolve. Reusing
		// MentionService::MAX_MENTIONS is not cosmetic: past that count the write path
		// already ignores a mention entirely (no notification, no subscription), so
		// this redacts exactly the tokens the app treats as mentions at all. Per FIELD
		// rather than per request on purpose - a bound shared across the board would
		// let ONE card padded with junk `@tokens` (a pasted log, a CSV) spend the
		// budget and silently de-redact every LATER card's real mentions.
		$newLookups = 0;

		$redacted = preg_replace_callback(
			MentionService::MENTION_PATTERN,
			function (array $match) use (&$displayNames, &$newLookups): string {
				// `.`, `-` and `_` are legal uid characters, so a mention that ENDS a
				// sentence swallows the punctuation: "cc @jsmith." captures the token
				// `jsmith.`, which resolves to nothing and would have shipped the uid
				// verbatim - a plain bypass of this redaction, reachable by writing
				// ordinary English (and the same for a trailing `-` or `_`). So the
				// trailing punctuation run is trimmed and the shorter candidate tried
				// too, with only the resolved part substituted and the punctuation put
				// back. (`_@jsmith_` is a different matter: the pattern's negative
				// lookbehind means a `@` preceded by a word char is not a mention
				// ANYWHERE in the app - no notification, no chip - so widening that
				// belongs to MentionService, not here.)
				//
				// Exactly two candidates, never a scan of every prefix: `@bob.smith`
				// does not END in punctuation, so it is looked up once, as itself, and
				// can never be split into "Bob Builder.smith".
				$candidates = [$match[1] => ''];
				$trimmed = rtrim($match[1], '._-');
				if ($trimmed !== '' && $trimmed !== $match[1]) {
					$candidates[$trimmed] = substr($match[1], strlen($trimmed));
				}

				foreach ($candidates as $uid => $tail) {
					if (!array_key_exists($uid, $displayNames)) {
						// A uid this request has not seen: the one costly step, so it is
						// what the bound counts. An already-cached uid still substitutes
						// below (free), and a comment-author byline never spends the bound
						// because the counter is local to this field.
						if ($newLookups >= MentionService::MAX_MENTIONS) {
							break;
						}
						$newLookups++;
					}
					$name = $this->resolveDisplayName((string)$uid, $displayNames);
					if ($name === null) {
						// No such account: ordinary text, left byte-identical.
						continue;
					}
					return $this->inlineSafeName($name) . $tail;
				}

				return $match[0];
			},
			$text,
		);

		// preg_replace_callback returns null only on a PCRE failure (e.g. the
		// backtrack limit). Serving the RAW text then would silently un-fix the leak,
		// so this fails CLOSED - the null propagates and the field ships empty rather
		// than unredacted. (The pattern has no nested quantifier, so it cannot
		// actually backtrack; this is belt-and-braces for a null the signature allows.)
		return $redacted;
	}

	/**
	 * A resolved display name, in a form that is safe to splice into a markdown
	 * body as LITERAL text.
	 *
	 * Almost every real name comes back untouched: hyphens, apostrophes,
	 * parentheses and sentence dots cannot open a markdown construct, and this
	 * value is served into fields that BOTH render as markdown (the card detail,
	 * via v-html after DOMPurify) and print as raw source (the board tile, which
	 * interpolates the description as plain text - src/views/PublicBoard.vue:51).
	 * Escaping unconditionally would therefore show "Anne\-Marie Dubois" to every
	 * anonymous visitor, so a name that needs no escaping gets none.
	 *
	 * A name that COULD open a construct is escaped instead of dropped, so the
	 * reader still sees who it is. That matters because the name is spliced into
	 * somebody ELSE's text: without this, a mentioned user could rename themselves
	 * to `[click](https://evil.example)` and inject a link into another author's
	 * description on an anonymous page. (No XSS either way - markdown-it runs with
	 * html:false and DOMPurify strips the rest - the exposure is markdown-level
	 * link/formatting injection.)
	 *
	 * A name with nothing displayable falls back to the same generic label the
	 * author byline uses, never to the raw uid.
	 */
	private function inlineSafeName(string $name): string {
		if (trim($name) === '') {
			// Defensive: Nextcloud falls back to the uid for a nameless account, so an
			// empty/blank name should be unreachable. Never answer it with the uid.
			return $this->l10n->t('Former user');
		}
		if (!$this->needsMarkdownEscape($name)) {
			return $name;
		}
		// Escape every ASCII punctuation character - exhaustive by construction, since
		// CommonMark treats exactly that set as escapable and renders each escape as
		// the bare character. A PCRE failure here would hand back the UNescaped name,
		// i.e. precisely the payload this defends against, so it fails closed.
		return preg_replace('/([!-\/:-@\[-`{-~])/', '\\\\$1', $name) ?? $this->l10n->t('Former user');
	}

	/**
	 * Whether a display name has to be escaped before it can be spliced into
	 * markdown. True for anything that can open an inline construct - a backslash,
	 * code span, emphasis, link/image bracket, autolink or raw HTML angle, entity
	 * `&`, strikethrough `~`, table pipe or heading `#` - and for anything
	 * markdown-it's `linkify: true` would turn into a live link on its own
	 * (`www.evil.example`, `x.io`, `name@evil.example`), which is the reason a plain
	 * `.` is not enough on its own but a domain-shaped one is.
	 *
	 * Also true for a newline, which would otherwise break the surrounding text into
	 * new markdown blocks. The check is byte-oriented (no `/u`): every character it
	 * looks for is ASCII, and a multi-byte sequence cannot contain an ASCII byte, so
	 * an accented or CJK name is judged safe exactly as it should be.
	 */
	private function needsMarkdownEscape(string $name): bool {
		// preg_match returns false on failure; !== 0 treats that as "escape it".
		return preg_match('/[\\\\`*_\[\]<>&~|#\r\n]/', $name) !== 0
			|| preg_match('/[a-z0-9-]\.[a-z]{2,}|@/i', $name) !== 0;
	}

	/**
	 * Lightweight token gate for the page renderer: asserts the token resolves to
	 * a live, non-expired share WITHOUT building the whole payload (the client
	 * fetches that separately via {@see self::getPublicBoard()}). Keeps the
	 * unauthenticated page route from doing two full board builds per load.
	 *
	 * @throws DoesNotExistException if the token is unknown, disabled, or expired
	 */
	public function assertTokenValid(string $token): void {
		$board = $this->boardMapper->findByPublicToken($token);
		if (($board->getPublicShareToken() ?? '') === '') {
			throw new DoesNotExistException('Public share is disabled');
		}
		$expiresAt = $board->getPublicShareExpiresAt();
		if ($expiresAt !== null && $expiresAt > 0 && $expiresAt <= time()) {
			throw new DoesNotExistException('Public share has expired');
		}
	}

	/**
	 * @return array{enabled: bool, token: ?string, url: ?string, expiresAt: ?int, commentsEnabled: bool}
	 */
	private function configPayload(Board $board): array {
		$token = $board->getPublicShareToken();
		$enabled = ($token ?? '') !== '';
		return [
			'enabled' => $enabled,
			'token' => $enabled ? $token : null,
			'url' => $enabled ? $this->publicUrl((string)$token) : null,
			'expiresAt' => $board->getPublicShareExpiresAt(),
			// The opt-in state rides the MANAGE config so the settings UI can render
			// the toggle; it persists independent of enable/disable.
			'commentsEnabled' => $board->getPublicShareComments() ?? false,
		];
	}

	private function publicUrl(string $token): string {
		return $this->urlGenerator->linkToRouteAbsolute('kanso.publicShare.show', ['token' => $token]);
	}

	/**
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 */
	private function loadBoard(int $boardId): Board {
		$board = $this->boardMapper->find($boardId);
		if ($board->getDeletedAt() > 0) {
			throw new DoesNotExistException('Board ' . $boardId . ' is deleted');
		}
		return $board;
	}
}
