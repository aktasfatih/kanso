<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardLink;
use OCA\Kanso\Db\CardLinkMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeDetailMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Http\Client\IClientService;

/**
 * Forge PR/issue links attached to a card. A link is manual (paste a URL); how
 * its state (open/closed/merged) stays fresh depends on the provider:
 *
 *  - `github`: refreshed best-effort by an UNAUTHENTICATED GitHub API poll,
 *    throttled per link. No credentials are stored or sent.
 *  - `forgejo`: NEVER polled. The instance is self-hosted, so there is no host
 *    to pin and no stored token (a private repo would 404 anyway); state comes
 *    from the board's webhook deliveries instead. A link pasted for something
 *    that never changes again therefore stays `unknown`.
 *
 * A non-github.com URL is accepted only when the board has its Forgejo webhook
 * enabled - that opt-in is the sole signal that the board talks to a forge at
 * all, since Kanso stores no instance URL.
 *
 * SSRF posture: the only host ever contacted is api.github.com, and the poll
 * always hits a URL RECONSTRUCTED from validated path segments against that
 * fixed host - user input never selects the request host, and the forgejo
 * provider makes no request at all. Private or rate-limited repos simply stay
 * `unknown`; the chip still renders.
 *
 * A link add/remove reuses the card's ENTITY_CARD / ACTION_UPDATE change row so
 * the existing realtime/delta-sync path reflects it with no new Change type.
 * Since #119 that row also carries a verb (VERB_LINK_ATTACHED /
 * VERB_LINK_REMOVED) plus the link's title (or its URL) in
 * `kanso_change_details`, so the Activity feed can name WHICH link was attached
 * or removed - a removal has no other trace, the row is gone.
 */
class CardLinkService {
	/** Re-poll a link's state at most this often (seconds). */
	private const POLL_THROTTLE = 300;
	/** Per-request timeout for the GitHub poll (seconds). */
	private const POLL_TIMEOUT = 4;

	/**
	 * Cap on a change-detail string, matching the other services that write the
	 * side table (CardService, CardAttachmentService, LabelService).
	 */
	private const MAX_DETAIL_LENGTH = 10000;

	public function __construct(
		private CardLinkMapper $cardLinkMapper,
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private PermissionService $permissionService,
		private ChangeNotifier $changeNotifier,
		private IClientService $clientService,
		private CardVisibilityGuard $visibilityGuard,
		private ChangeDetailMapper $changeDetailMapper,
	) {
	}

	/**
	 * A card's links, refreshing any stale PR/issue states first. Requires READ.
	 * The refresh loop is blocking, so it is bounded twice over: each link is
	 * re-polled at most once per {@see self::POLL_THROTTLE} seconds, and the
	 * mapper returns at most {@see CardLinkMapper::MAX_PER_CARD} links.
	 *
	 * @return CardLink[]
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not read the board
	 */
	public function listForCard(int $cardId, string $actorUid): array {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_READ);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		$links = $this->cardLinkMapper->findByCard($cardId);
		$now = time();
		foreach ($links as $link) {
			if ($this->shouldPoll($link, $now)) {
				$this->refreshState($link, $now);
			}
		}
		return $links;
	}

	/**
	 * Attaches a forge URL to the card, polling its state once when the provider
	 * supports it. Idempotent on (card, url). Requires EDIT.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 * @throws InvalidInputException if the URL is not acceptable for this board, or the card is already at its link cap
	 */
	public function addLink(int $cardId, string $url, string $actorUid): CardLink {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		[$kind, $provider] = $this->resolveLink($url, $board);

		// Cap the links per card BEFORE inserting. Reading a card refreshes each
		// of its stale links with a blocking outbound GET, so an uncapped card
		// would let one editor make every later reader of that card wait.
		if ($this->cardLinkMapper->countByCard($cardId) >= CardLinkMapper::MAX_PER_CARD) {
			throw new InvalidInputException(
				'A card may have at most ' . CardLinkMapper::MAX_PER_CARD . ' links'
			);
		}

		$now = time();
		$link = new CardLink();
		$link->setCardId($cardId);
		$link->setProvider($provider);
		$link->setUrl($url);
		$link->setKind($kind);
		$link->setState(CardLink::STATE_UNKNOWN);
		$link->setTitle(null);
		$link->setLastPolled(0);
		$link->setCreatedAt($now);

		try {
			$link = $this->cardLinkMapper->insert($link);
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			// Same URL already attached - return the existing row (idempotent).
			foreach ($this->cardLinkMapper->findByCard($cardId) as $existing) {
				if ($existing->getUrl() === $url) {
					return $existing;
				}
			}
			throw $e;
		}

		// Best-effort immediate poll so the chip shows a real state right away.
		// Only github.com is ever polled - see shouldPoll().
		if ($this->shouldPoll($link, $now, true)) {
			$this->refreshState($link, $now);
		}

		$this->recordLinkChange($card->getBoardId(), $cardId, $link, $actorUid, Change::VERB_LINK_ATTACHED);

		return $link;
	}

	/**
	 * Removes a link from the card. Requires EDIT.
	 *
	 * @throws DoesNotExistException if the card/board/link does not exist, is deleted, or the link is on another card
	 * @throws NotPermittedException if the actor may not edit the board
	 */
	public function deleteLink(int $cardId, int $linkId, string $actorUid): void {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		$link = $this->cardLinkMapper->find($linkId);
		if ($link->getCardId() !== $cardId) {
			throw new DoesNotExistException('Link ' . $linkId . ' is not on card ' . $cardId);
		}
		// Read the label BEFORE the row is dropped - after the delete the link's
		// title/URL exists nowhere else, and the change row is the only trace.
		$label = $this->linkLabel($link);
		$this->cardLinkMapper->delete($link);

		$this->recordLinkChange($card->getBoardId(), $cardId, $link, $actorUid, Change::VERB_LINK_REMOVED, $label);
	}

	/**
	 * Appends the card's change row for a link add/remove (#119) and records the
	 * link's label in the `kanso_change_details` side table, so the Activity feed
	 * names the link rather than rendering a bare "updated this card". The label
	 * rides the same side as the equivalent label change: `to` when something
	 * appeared, `from` when something went away.
	 *
	 * Still an ENTITY_CARD / ACTION_UPDATE row - delta sync and the ETag key on
	 * (entity_type, action), never on the verb, so the realtime path is unchanged.
	 */
	private function recordLinkChange(int $boardId, int $cardId, CardLink $link, string $actorUid, int $verb, ?string $label = null): void {
		$change = $this->changeNotifier->notify(
			$boardId,
			Change::ENTITY_CARD,
			$cardId,
			Change::ACTION_UPDATE,
			$actorUid,
			verb: $verb,
		);

		$label ??= $this->linkLabel($link);
		$added = $verb === Change::VERB_LINK_ATTACHED;
		$this->changeDetailMapper->insertDetail(
			$change->getId(),
			$added ? null : $label,
			$added ? $label : null,
		);
	}

	/**
	 * How a link reads in the Activity feed: its polled title when we have one,
	 * otherwise the raw URL (a Forgejo link is never polled, and a GitHub poll
	 * can fail, so the URL is the dependable fallback). Capped multibyte-safe.
	 */
	private function linkLabel(CardLink $link): string {
		$title = $link->getTitle();
		$label = ($title !== null && $title !== '') ? $title : $link->getUrl();
		return mb_substr($label, 0, self::MAX_DETAIL_LENGTH);
	}

	/**
	 * The deterministic git branch name for a card: `kanso-{id}-{slug}` (slug of
	 * the title). Pure - no persistence. Exposed so the client and any server
	 * caller derive the identical name.
	 */
	public static function branchName(int $cardId, string $title): string {
		$slug = strtolower($title);
		$slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
		$slug = trim($slug, '-');
		if (strlen($slug) > 50) {
			$slug = rtrim(substr($slug, 0, 50), '-');
		}
		return $slug === '' ? 'kanso-' . $cardId : 'kanso-' . $cardId . '-' . $slug;
	}

	/**
	 * Parses and validates a GitHub URL, returning [kind, owner, repo, number].
	 * Only github.com is accepted; a pull/issue URL yields its number, any other
	 * github.com URL is KIND_OTHER (number 0, not polled). Pure - static so any
	 * server caller (e.g. the webhook ingest) parses identically.
	 *
	 * @return array{0: string, 1: string, 2: string, 3: int}
	 * @throws InvalidInputException if the URL is not an https github.com URL
	 */
	public static function parseGitHubUrl(string $url): array {
		$url = trim($url);
		$parts = parse_url($url);
		if ($parts === false
			|| ($parts['scheme'] ?? '') !== 'https'
			|| !in_array(strtolower($parts['host'] ?? ''), ['github.com', 'www.github.com'], true)) {
			throw new InvalidInputException('Only https://github.com links are supported');
		}

		$path = $parts['path'] ?? '';
		if (preg_match('#^/([A-Za-z0-9._-]+)/([A-Za-z0-9._-]+)/(pull|issues)/(\d+)/?$#', $path, $m) === 1) {
			$kind = $m[3] === 'pull' ? CardLink::KIND_PR : CardLink::KIND_ISSUE;
			return [$kind, $m[1], $m[2], (int)$m[4]];
		}
		return [CardLink::KIND_OTHER, '', '', 0];
	}

	/**
	 * Whether this link's state may be refreshed by an outbound poll.
	 *
	 * Only github.com is ever polled: its host is fixed, so the API base can be
	 * pinned. A Forgejo link is never polled - the instance is self-hosted (no
	 * host to pin, and no stored token, so a private repo would 404 anyway), and
	 * its state arrives with the webhook deliveries instead.
	 *
	 * @param bool $ignoreThrottle true right after an insert, where the throttle
	 *                             has nothing to throttle yet
	 */
	private function shouldPoll(CardLink $link, int $now, bool $ignoreThrottle = false): bool {
		if (($link->getProvider() ?? CardLink::PROVIDER_GITHUB) !== CardLink::PROVIDER_GITHUB) {
			return false;
		}
		if ($link->getKind() === CardLink::KIND_OTHER) {
			return false;
		}
		return $ignoreThrottle || ($now - $link->getLastPolled()) > self::POLL_THROTTLE;
	}

	/**
	 * Resolves a pasted URL to [kind, provider].
	 *
	 * github.com is always accepted. Any other host is accepted ONLY when the
	 * board has its Forgejo webhook enabled - that opt-in is what tells us the
	 * board actually talks to a forge at that address, and it keeps the strict
	 * github.com-only rejection for every board that has not opted in. Kanso
	 * stores no instance URL, so the board's own webhook is the only signal
	 * available, and a Forgejo link would be stuck on `unknown` without the
	 * deliveries anyway.
	 *
	 * @return array{0: string, 1: string} [kind, provider]
	 * @throws InvalidInputException if the URL is not acceptable for this board
	 */
	private function resolveLink(string $url, Board $board): array {
		try {
			[$kind] = self::parseGitHubUrl($url);
			return [$kind, CardLink::PROVIDER_GITHUB];
		} catch (InvalidInputException $e) {
			if (($board->getForgejoWebhookSecret() ?? '') === '') {
				throw $e;
			}
		}
		return [self::forgeKindFromUrl($url), CardLink::PROVIDER_FORGEJO];
	}

	/**
	 * The kind of a self-hosted forge URL, read purely from its path shape - no
	 * host trust, no network call. Forgejo spells a pull request `/pulls/{n}`.
	 *
	 * @throws InvalidInputException if the URL is not an https URL
	 */
	public static function forgeKindFromUrl(string $url): string {
		$url = trim($url);
		$parts = parse_url($url);
		if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
			throw new InvalidInputException('Only https links are supported');
		}
		$path = $parts['path'] ?? '';
		if (preg_match('#/pulls?/(\d+)/?$#', $path) === 1) {
			return CardLink::KIND_PR;
		}
		if (preg_match('#/issues/(\d+)/?$#', $path) === 1) {
			return CardLink::KIND_ISSUE;
		}
		return CardLink::KIND_OTHER;
	}

	/**
	 * Best-effort refresh of a PR/issue link's state + title from the GitHub
	 * API. Never throws - a failure leaves the state as-is (or unknown) and
	 * stamps last_polled so we don't hammer the API.
	 */
	private function refreshState(CardLink $link, int $now): void {
		$link->setLastPolled($now);
		try {
			[$kind, $owner, $repo, $number] = self::parseGitHubUrl($link->getUrl());
			if ($kind === CardLink::KIND_OTHER) {
				$this->cardLinkMapper->update($link);
				return;
			}
			$endpoint = $kind === CardLink::KIND_PR ? 'pulls' : 'issues';
			$apiUrl = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo)
				. '/' . $endpoint . '/' . $number;

			$response = $this->clientService->newClient()->get($apiUrl, [
				'timeout' => self::POLL_TIMEOUT,
				// Defence in depth: never let an upstream response steer this
				// server-side fetch off the pinned api.github.com host. Keeps the
				// SSRF guarantee end-to-end even if GitHub ever 3xx'd.
				'allow_redirects' => false,
				'headers' => [
					'Accept' => 'application/vnd.github+json',
					'User-Agent' => 'Kanso',
				],
			]);
			/** @var array<string, mixed> $data */
			$data = json_decode((string)$response->getBody(), true) ?? [];

			if (isset($data['title']) && is_string($data['title'])) {
				$link->setTitle($data['title']);
			}
			$link->setState($this->deriveState($kind, $data));
		} catch (\Throwable) {
			// Private/rate-limited/offline - leave state as-is (unknown).
			if ($link->getState() === '') {
				$link->setState(CardLink::STATE_UNKNOWN);
			}
		}
		$this->cardLinkMapper->update($link);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function deriveState(string $kind, array $data): string {
		if ($kind === CardLink::KIND_PR && !empty($data['merged_at'])) {
			return CardLink::STATE_MERGED;
		}
		$state = $data['state'] ?? '';
		if ($state === 'closed') {
			return CardLink::STATE_CLOSED;
		}
		if ($state === 'open') {
			return CardLink::STATE_OPEN;
		}
		return CardLink::STATE_UNKNOWN;
	}

	/**
	 * @throws DoesNotExistException if the card does not exist or is deleted
	 */
	private function loadCard(int $id): Card {
		$card = $this->cardMapper->find($id);
		if ($card->getDeletedAt() > 0) {
			throw new DoesNotExistException('Card ' . $id . ' is deleted');
		}
		return $card;
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
