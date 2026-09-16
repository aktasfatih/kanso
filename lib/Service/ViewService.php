<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Db\BoardPrefix;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\LabelMapper;

/**
 * The cross-board feed behind saved "Views" (#3815) - every non-deleted,
 * non-archived card across all the boards the current user can read, each
 * carrying its board id + title. Archived cards are opt-in through the
 * `archived` filter facet, exactly as they are on a board.
 * There is still exactly ONE readable-set path and no query language -
 * identical to how {@see MyCardsService::findMine()} and
 * {@see InboxService::findMine()} enforce ACL.
 *
 * The View's filter is applied on BOTH sides (#9862). The client keeps running
 * its own predicate over whatever rows arrive - that is what keeps chip editing
 * instant - and the server runs the mirrored {@see ViewFilter} as a SUPERSET
 * GUARD so the hard cap below slices the MATCHING set rather than the whole
 * readable set. Without it, an account with more readable cards than the cap
 * searched only the first N rows of the sorted order and silently missed every
 * match past them. The filter still consumes only already-serialized summary
 * fields - no new query, no SQL shortcut.
 *
 * ACL is enforced by restricting to the boards
 * {@see BoardService::findAllActive()} returns (the readable, non-archived set)
 * and running the summary query under the viewer's OWN ROLE ON EACH of those
 * boards ({@see \OCA\Kanso\Access\BoardAccess::rolesFor()}) - so a card on a
 * board the user cannot read, or a card hidden from the viewer's board side
 * (#3743), is never returned. A View run by user A can never surface a card from
 * a board A cannot read (covered by ViewServiceTest's leak-denial test).
 *
 * The whole feed is a FIXED number of queries, independent of how many boards
 * the user can read (#10298). It used to resolve one ViewerContext and run a
 * full per-board enrichment inside a loop over every readable board - ~15
 * queries per board, re-issued every minute by the client's poll - which made a
 * 30-board account cost ~450 queries a minute. It now batches exactly like every
 * sibling cross-board feed (My Cards, Inbox, Search, My Steps, Reviews): ONE
 * `rolesFor()` ACL fetch for the whole board set, and board-set-scoped
 * enrichment queries that carry each board's own role. That is batching, NOT
 * caching - the roles are resolved fresh on every request, so a revoked
 * membership never lingers (see the BoardAccess docblock).
 */
class ViewService {
	/**
	 * Hard cap on the cross-board feed payload. The readable-set + #3743 masking
	 * still gate every row BEFORE this slice (leak-safety unchanged); the cap only
	 * bounds how many summaries a single unbounded feed can ship, no matter how
	 * many boards/cards the user can read. The View's filter also runs before it
	 * (#9862), so what the cap slices is the MATCHING set - a narrow filter reaches
	 * matches anywhere in the readable set, not just within the first N sorted
	 * rows. When the matching set still exceeds the cap the envelope's `capped`
	 * flag is set so the client surfaces an honest "showing the first N of M
	 * matching cards" hint rather than silently truncating.
	 */
	public const MAX_CARDS = 5000;

	public function __construct(
		private BoardService $boardService,
		private CardMapper $cardMapper,
		private CardSummaryService $cardSummaryService,
		private BoardAccess $boardAccess,
		private LabelMapper $labelMapper,
	) {
	}

	/**
	 * All readable-board card summaries for the user, enriched to the SAME shape
	 * the board payload carries (labelIds / assigneeIds / type / estimate /
	 * duedate / doneAt / startedAt / startDate / waitingOnExternal / checklist /
	 * childProgress / commentCount / priority / boardSeq …) plus boardId +
	 * boardTitle - so the client's reused board filter predicate and group-by can
	 * run over them with no extra request.
	 *
	 * Returned as an envelope so the client can honestly report truncation:
	 *   ['cards' => …, 'labels' => …, 'participants' => …, 'capped' => bool, 'total' => int, 'limit' => int]
	 * where `total` is the pre-cap count of MATCHING rows (#9862 - the filter runs
	 * before the cap) and `cards` is capped to at most {@see self::MAX_CARDS} rows.
	 * The cap is applied AFTER the ACL + #3743 masking, so every row is still
	 * gated before the slice.
	 *
	 * `participants` is the union of assignee uids + card owners across the readable
	 * boards, accumulated in the assembly pass BEFORE the filter and BEFORE the cap.
	 * It ships SEPARATELY from the rows on purpose: the client's assignee/owner facets
	 * are built from it, so filtering to one person must not collapse the facet to the
	 * survivors (which would make the facet vanish at zero matches and leave no way to
	 * add a second person). Accumulating it in the assembly pass costs no extra query.
	 *
	 * Each card additionally carries `boardPrefix` (its board's human-id prefix) and
	 * `labels` is the union of each readable board's serialized labels (the label's
	 * own shape: id/boardId/title/color) across the same readable boards - all
	 * already-readable board metadata, no leak beyond the readable set the card rows
	 * come from - so the client can render card tiles with the real human ref
	 * (e.g. "KAN-123") and label COLOURS — matching the board tiles (#3950) — from
	 * this one feed, with no extra per-board request.
	 *
	 * The feed is ordered by the View's saved sort (`$sortMode` / `$sortDir`) BEFORE
	 * the cap, so a sorted View starts at the true first row rather than the first
	 * row of an arbitrary window. `default` keeps the historical stable
	 * (boardId, id) order, so a View saved before the sort control looks unchanged.
	 *
	 * @return array{cards: list<array<string, mixed>>, labels: list<array<string, mixed>>, participants: list<string>, capped: bool, total: int, limit: int}
	 */
	public function findMine(string $uid, string $sortMode = 'default', string $sortDir = 'asc', ?ViewFilter $filter = null): array {
		// ARCHIVED BOARDS are out of the feed entirely (#10126) - an archived
		// board is shelved, so even its live cards must not surface here. This
		// is unconditional and deliberately NOT wired to the `archived` facet
		// below: that facet is about archived CARDS on boards that are still
		// active, so "include"/"only" can never bring an archived board back.
		$boards = $this->boardService->findAllActive($uid);

		// Archived cards are excluded from the feed by DEFAULT, and only the
		// `archived` facet ('include' / 'only') opts them back in. The exclusion
		// happens here rather than in CardMapper::findSummariesByBoards() because
		// that query is the board-set twin of the one BoardController::show() runs,
		// which DELIBERATELY ships archived rows - the board drops them
		// client-side, and the archived-cards page plus its counter are built on
		// them. Filtering at the mapper would fork the two and break that page.
		// It also happens here rather than inside ViewFilter::matches(), which
		// can only ever narrow: an empty filter must stay empty (see
		// ViewFilter::isEmpty()), so "no archived cards" cannot be a filter value.
		//
		// Doing it server-side matters on its own, independently of the client's
		// mirrored guard in ViewPage.vue: archived rows would otherwise consume the
		// MAX_CARDS budget and inflate `total` / `capped` in the envelope below.
		$includeArchived = $filter !== null && $filter->includesArchived();

		if ($boards === []) {
			// Nothing readable, so nothing to query - returning here keeps the
			// "never touch a mapper outside the readable set" boundary literal
			// rather than relying on each batched mapper's empty-set guard.
			return [
				'cards' => [],
				'labels' => [],
				'participants' => [],
				'capped' => false,
				'total' => 0,
				'limit' => self::MAX_CARDS,
			];
		}

		$boardIds = [];
		$boardsById = [];
		foreach ($boards as $board) {
			$boardId = (int)$board->getId();
			$boardIds[] = $boardId;
			$boardsById[$boardId] = $board;
		}

		// ONE batched ACL fetch for the WHOLE readable set (#10298), the way
		// every other cross-board feed resolves its viewer - see
		// MyCardsService / SearchService / InboxService. Batching is NOT
		// caching: the map is built per request and never memoized, so a
		// revoked membership is gone on the very next read (BoardAccess's
		// docblock spells out why that matters).
		//
		// rolesFor() folds exactly what contextFor() folded (owner => internal,
		// internal-wins across group rows) over the SAME ACL rows, so no board
		// changes side. It only reports differently for a board with no
		// matching row at all: contextFor() threw NotAMemberException, this
		// simply omits the board from the map - which drops its internal branch
		// and leaves that board contributing public + the viewer's own private
		// cards. findAllForUser() builds the readable set from owner + those
		// same ACL rows, so the case is unreachable; if it ever were reached it
		// now degrades closed instead of 500-ing the whole feed.
		$rolesByBoard = $this->boardAccess->rolesFor($boards, $uid);
		// The viewer's resolved side on EACH board scopes every card row
		// (#3743) - the per-board role travels with the row set instead of
		// with a per-board query, so a user internal on one board and external
		// on another gets each board's own masking, in one query.
		$cards = $this->cardSummaryService->serializeForBoards(
			$boardIds,
			$this->cardMapper->findSummariesByBoards($boardIds, $uid, $rolesByBoard),
			$uid,
			$rolesByBoard,
		);

		$out = [];
		$labels = [];
		$participants = [];
		foreach ($cards as $card) {
			// Archived rows never reach the feed unless the facet asked for them
			// - before the participant vocabulary too, so a board's archive does
			// not repopulate the assignee/owner facets with people who no longer
			// appear in any visible row.
			if (!$includeArchived && !empty($card['archived'])) {
				continue;
			}
			$board = $boardsById[(int)($card['boardId'] ?? 0)] ?? null;
			if ($board === null) {
				// Unreachable - the query is restricted to $boardIds - but a row
				// whose board is not in the readable set is DROPPED rather than
				// shipped with a blank board identity. Fail closed.
				continue;
			}
			// Carry the board identity so the client can group by board and
			// deep-link back without a per-card board lookup.
			$card['boardId'] = (int)$board->getId();
			$card['boardTitle'] = (string)$board->getTitle();
			// The board's human-id prefix, so a card tile can render its real
			// reference (prefix + '-' + boardSeq), same as the board tiles.
			$card['boardPrefix'] = (string)($board->getPrefix() ?? BoardPrefix::DEFAULT);
			// Facet VOCABULARY, accumulated before the filter and before the cap
			// so the assignee/owner facets keep offering everyone even when the
			// filter narrows the rows to one person - or to none (#9862).
			foreach ($card['assigneeIds'] ?? [] as $assignee) {
				if (is_string($assignee) && $assignee !== '') {
					$participants[$assignee] = true;
				}
			}
			$owner = $card['owner'] ?? null;
			if (is_string($owner) && $owner !== '') {
				$participants[$owner] = true;
			}
			$out[] = $card;
		}

		// Union the readable boards' labels so the client can colour the card
		// label chips - ONE query for the whole set, walked in board order so the
		// payload keeps the exact sequence the old per-board loop emitted. Labels
		// are board-scoped and ids are unique per board's creation table; across
		// boards ids can collide, but a View's tiles only reference a card's own
		// labelIds against a single lookup — collisions are acceptable for chip
		// colouring (the same trade-off the board makes with its per-board
		// labelsById). Keyed by id keeps the payload deduplicated.
		$labelsByBoard = $this->labelMapper->findByBoards($boardIds);
		foreach ($boardIds as $boardId) {
			foreach ($labelsByBoard[$boardId] ?? [] as $label) {
				$labels[(int)$label->getId()] = $label->jsonSerialize();
			}
		}

		// Apply the View's filter (#9862) strictly AFTER the ACL / #3743 masking
		// above and strictly BEFORE the sort + cap. After, because filtering
		// must never become a shortcut around the permission masking - it only drops
		// rows the viewer may already see, it can never add one. Before, because the
		// whole point is that the cap slices the MATCHING set: otherwise a narrow
		// filter over a >MAX_CARDS readable set searches only the first window of
		// the sorted order and silently misses every match past it.
		if ($filter !== null && !$filter->isEmpty()) {
			// One `now` for the whole pass, so the relative due / start-date windows
			// can't shift mid-scan.
			$now = (int)round(microtime(true) * 1000.0);
			$out = array_values(array_filter(
				$out,
				static fn (array $card): bool => $filter->matches($card, $now),
			));
		}

		// Order the WHOLE matching set before the cap slices it, so the cap always
		// takes the true first N rows of the requested order (never the first N of
		// an arbitrary window) and repeats the same window across requests. Runs
		// strictly AFTER the ACL / #3743 masking above, so it moves
		// no leak boundary - it only reorders rows the viewer may already see.
		$out = self::sortRows($out, $sortMode, $sortDir);

		$total = count($out);
		$capped = $total > self::MAX_CARDS;
		if ($capped) {
			$out = array_slice($out, 0, self::MAX_CARDS);
		}

		ksort($participants, SORT_STRING);

		return [
			'cards' => $out,
			'labels' => array_values($labels),
			// strval() because PHP silently coerces a canonical decimal string array
			// KEY to int - so a numeric uid (routine with LDAP employee-number
			// provisioning) would come back as int(12345) and ship as a JSON number,
			// which the client drops as "not a uid". That would resurrect the very
			// facet self-narrowing this vocabulary exists to prevent: the numeric
			// account would vanish from the assignee/owner facet with no way to
			// re-add them.
			'participants' => array_map('strval', array_keys($participants)),
			'capped' => $capped,
			'total' => $total,
			'limit' => self::MAX_CARDS,
		];
	}

	/**
	 * Order the feed rows by a View's saved sort. Semantics mirror the board's
	 * display sort (BoardView.sortCards) so a card orders the same way wherever it
	 * is read:
	 *  - each mode maps a row to a comparable key, or null when the field is
	 *    MISSING (no due date / never modified). 0 priority is a real low value,
	 *    not "missing" - same as the board;
	 *  - missing values always sort LAST, in both directions;
	 *  - ties fall back to the stable (boardId, id) key the cap has always sliced on.
	 *
	 * Sorted decorate-sort-undecorate: this runs over the WHOLE uncapped readable
	 * set (which the cap exists precisely because it can be large), so each row's
	 * key is computed exactly once instead of O(n log n) times - a per-comparison
	 * key would re-parse every due date on every comparison.
	 *
	 * An unknown mode (an older/newer client, a hand-edited config value) leaves the
	 * rows in that stable key order - ignored and defaulted, never an error, so a
	 * saved record can't hard-fail the feed.
	 *
	 * `manual` and `estimate` are deliberately absent: manual is the per-stack
	 * fractional sort key (meaningless compared across boards) and estimate ranks
	 * against a single board's estimate scale (a cross-board View can span two
	 * different scales).
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private static function sortRows(array $rows, string $mode, string $dir): array {
		$keyOf = self::keyExtractor($mode);
		$mult = $dir === 'desc' ? -1 : 1;

		// Decorate: [sort key or null, boardId, id, original index].
		$decorated = [];
		foreach ($rows as $i => $row) {
			$decorated[] = [
				$keyOf === null ? null : $keyOf($row),
				(int)($row['boardId'] ?? 0),
				(int)($row['id'] ?? 0),
				$i,
			];
		}

		usort($decorated, static function (array $a, array $b) use ($mult): int {
			$ka = $a[0];
			$kb = $b[0];
			if ($ka !== null && $kb !== null) {
				$cmp = $ka <=> $kb;
				if ($cmp !== 0) {
					return $cmp * $mult;
				}
			} elseif ($ka !== $kb) {
				// Missing always last, regardless of direction.
				return $ka === null ? 1 : -1;
			}
			// Equal keys (and two missing values) fall back to the stable
			// (boardId, id) key the cap has always sliced on.
			return [$a[1], $a[2]] <=> [$b[1], $b[2]];
		});

		return array_map(static fn (array $d): array => $rows[$d[3]], $decorated);
	}

	/**
	 * The per-mode sort key, or null for the default/unknown mode (which leaves the
	 * rows in stable (boardId, id) order).
	 *
	 * Text keys are case-folded, so "apple" and "Apple" sort together and compare by
	 * BYTE order thereafter. That is deterministic and reads as A→Z for Latin text,
	 * but - unlike the client's localeCompare - it does not interleave accented or
	 * non-Latin titles with the plain-ASCII range; they trail it. Collation-accurate
	 * ordering would mean depending on ext-intl, which this app does not require.
	 *
	 * @return (callable(array<string, mixed>): (int|string|null))|null
	 */
	private static function keyExtractor(string $mode): ?callable {
		return match ($mode) {
			'due' => static function (array $c): ?int {
				$raw = $c['duedate'] ?? null;
				if (!is_string($raw) || $raw === '') {
					return null;
				}
				$ts = strtotime($raw);
				return $ts === false ? null : $ts;
			},
			'priority' => static fn (array $c): int => (int)($c['priority'] ?? 0),
			'title' => static fn (array $c): string => mb_strtolower((string)($c['title'] ?? '')),
			'board' => static fn (array $c): string => mb_strtolower((string)($c['boardTitle'] ?? '')),
			'created' => static fn (array $c): ?int => ((int)($c['createdAt'] ?? 0)) ?: null,
			'modified' => static fn (array $c): ?int => ((int)($c['lastModified'] ?? 0)) ?: null,
			default => null,
		};
	}
}
