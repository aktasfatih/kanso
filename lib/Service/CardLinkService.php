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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Http\Client\IClientService;

/**
 * GitHub PR/issue links attached to a card. A link is manual (paste a URL) and
 * its state (open/closed/merged) is refreshed best-effort by an UNAUTHENTICATED
 * GitHub API poll, throttled per link. No credentials are stored or sent.
 *
 * SSRF posture: the only accepted host is github.com, and the poll always hits
 * a URL RECONSTRUCTED from validated path segments against the fixed
 * api.github.com host - user input never selects the request host. Private or
 * rate-limited repos simply stay `unknown`; the chip still renders.
 *
 * A link add/remove reuses the card's ENTITY_CARD / ACTION_UPDATE change row so
 * the existing realtime/delta-sync path reflects it with no new Change type.
 */
class CardLinkService {
	/** Re-poll a link's state at most this often (seconds). */
	private const POLL_THROTTLE = 300;
	/** Per-request timeout for the GitHub poll (seconds). */
	private const POLL_TIMEOUT = 4;

	public function __construct(
		private CardLinkMapper $cardLinkMapper,
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private PermissionService $permissionService,
		private ChangeNotifier $changeNotifier,
		private IClientService $clientService,
	) {
	}

	/**
	 * A card's links, refreshing any stale PR/issue states first. Requires READ.
	 *
	 * @return CardLink[]
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not read the board
	 */
	public function listForCard(int $cardId, string $actorUid): array {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_READ);

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
	 * Attaches a GitHub URL to the card and polls its state once. Idempotent on
	 * (card, url). Requires EDIT.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 * @throws InvalidInputException if the URL is not an acceptable GitHub URL
	 */
	public function addLink(int $cardId, string $url, string $actorUid): CardLink {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);

		[$kind] = self::parseGitHubUrl($url);

		$now = time();
		$link = new CardLink();
		$link->setCardId($cardId);
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
		if ($kind !== CardLink::KIND_OTHER) {
			$this->refreshState($link, $now);
		}

		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$cardId,
			Change::ACTION_UPDATE,
			$actorUid
		);

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

		$link = $this->cardLinkMapper->find($linkId);
		if ($link->getCardId() !== $cardId) {
			throw new DoesNotExistException('Link ' . $linkId . ' is not on card ' . $cardId);
		}
		$this->cardLinkMapper->delete($link);

		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$cardId,
			Change::ACTION_UPDATE,
			$actorUid
		);
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

	private function shouldPoll(CardLink $link, int $now): bool {
		return $link->getKind() !== CardLink::KIND_OTHER
			&& ($now - $link->getLastPolled()) > self::POLL_THROTTLE;
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
