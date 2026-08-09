<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CommentMapper;
use OCP\IDBConnection;

/**
 * In-app search over card titles/descriptions and comment bodies. Portable v1:
 * a case-insensitive LIKE across the readable set (no per-dialect full-text -
 * that stays a measurement-gated bet). Results are ALWAYS constrained to the
 * boards the user can READ (derived from BoardService, never the client) so a
 * hit can never leak a card from an inaccessible board. There is deliberately
 * no query language: a single plain term, implicit substring match.
 *
 * Ranking (highest first): a card whose TITLE matches, then a card matching only
 * on DESCRIPTION, then a COMMENT body match. Each source is capped before the
 * merge so one noisy source cannot crowd out the others.
 */
class SearchService {
	/** Per-source row cap before merge/rank - bounds the LIKE scan. */
	private const SOURCE_CAP = 100;

	private const RANK_CARD_TITLE = 3;
	private const RANK_CARD_DESCRIPTION = 2;
	private const RANK_COMMENT = 1;

	private const SNIPPET_LENGTH = 160;

	public function __construct(
		private BoardService $boardService,
		private CardMapper $cardMapper,
		private CommentMapper $commentMapper,
		private IDBConnection $db,
		private BoardAccess $boardAccess,
	) {
	}

	/**
	 * @param int|null $boardId scope to one board (must be readable), or null for all readable boards
	 * @return array{query: string, total: int, results: list<array<string, mixed>>}
	 */
	public function search(string $query, string $uid, ?int $boardId, int $limit, int $offset): array {
		$term = trim($query);
		if ($term === '' || mb_strlen($term) < 2) {
			return ['query' => $term, 'total' => 0, 'results' => []];
		}

		$boards = $this->boardService->findAll($uid);
		$boardIds = $this->readableBoardIds($boards, $boardId);
		if ($boardIds === []) {
			return ['query' => $term, 'total' => 0, 'results' => []];
		}
		// The viewer's per-board roles scope BOTH sources (#3743): a hidden
		// card can never match - not by its title/description, and not
		// through one of its comments either (comments inherit the card).
		$rolesByBoard = $this->boardAccess->rolesFor($boards, $uid);

		$pattern = '%' . $this->db->escapeLikeParameter($term) . '%';
		$lowerTerm = mb_strtolower($term);

		$results = [];
		foreach ($this->cardMapper->searchInBoards($boardIds, $pattern, self::SOURCE_CAP, $uid, $rolesByBoard) as $card) {
			$results[] = $this->cardResult($card, $lowerTerm);
		}
		foreach ($this->commentMapper->searchInBoards($boardIds, $pattern, self::SOURCE_CAP, $uid, $rolesByBoard) as $row) {
			$results[] = [
				'type' => 'comment',
				'cardId' => $row['cardId'],
				'boardId' => $row['boardId'],
				'commentId' => $row['id'],
				'title' => $row['cardTitle'],
				'snippet' => $this->snippet($row['body']),
				'rank' => self::RANK_COMMENT,
			];
		}

		// Stable rank sort (higher rank first); ties keep source order, which is
		// already most-recent-first.
		usort($results, static fn (array $a, array $b): int => $b['rank'] <=> $a['rank']);

		$total = count($results);
		$page = array_slice($results, $offset, $limit);

		return ['query' => $term, 'total' => $total, 'results' => $page];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function cardResult(Card $card, string $lowerTerm): array {
		$titleMatches = str_contains(mb_strtolower((string)$card->getTitle()), $lowerTerm);
		return [
			'type' => 'card',
			'cardId' => $card->getId(),
			'boardId' => $card->getBoardId(),
			'title' => $card->getTitle(),
			'snippet' => $this->snippet((string)$card->getDescription()),
			'rank' => $titleMatches ? self::RANK_CARD_TITLE : self::RANK_CARD_DESCRIPTION,
		];
	}

	/**
	 * The readable board ids, optionally narrowed to a single requested
	 * board. Requesting a board the user cannot read yields an empty set (no
	 * results) rather than an error - the ACL is enforced by omission.
	 *
	 * @param \OCA\Kanso\Db\Board[] $boards the viewer's readable boards
	 * @return int[]
	 */
	private function readableBoardIds(array $boards, ?int $boardId): array {
		$ids = array_map(
			static fn ($board): int => $board->getId(),
			$boards
		);
		if ($boardId !== null) {
			return in_array($boardId, $ids, true) ? [$boardId] : [];
		}
		return $ids;
	}

	private function snippet(string $text): string {
		$text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
		if (mb_strlen($text) <= self::SNIPPET_LENGTH) {
			return $text;
		}
		return mb_substr($text, 0, self::SNIPPET_LENGTH) . '…';
	}
}
