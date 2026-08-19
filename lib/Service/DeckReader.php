<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * READ-ONLY access to the Deck app's tables for the one-click importer. We query
 * `deck_*` directly (same database) rather than coupling to Deck's PHP classes -
 * the importer only reads a stable, well-known core schema and never writes to
 * Deck. Rows are returned as plain arrays; all mapping to Kanso lives in
 * {@see DeckImportService}.
 *
 * Reserved-word note: Deck's ordering column is `order`, so every read uses
 * `SELECT *` and sorts in PHP - no unquoted `ORDER BY "order"` in the SQL.
 */
class DeckReader {
	public function __construct(
		private IDBConnection $db,
		private IAppManager $appManager,
	) {
	}

	/** Whether the Deck app is installed and its tables are queryable. */
	public function isAvailable(): bool {
		return $this->appManager->isInstalled('deck') && $this->db->tableExists('deck_boards');
	}

	/**
	 * The non-deleted Deck boards the user may import: ones they own, plus ones
	 * shared to them by a direct user ACL entry. Each carries a card count for
	 * the picker. (Group/circle shares are out of scope for v1.)
	 *
	 * @return list<array{id: int, title: string, color: ?string, archived: bool, cardCount: int}>
	 */
	public function listImportableBoards(string $uid): array {
		$ids = $this->readableBoardIds($uid);
		if ($ids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('deck_boards')
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$rows = $this->fetchAll($qb);

		$counts = $this->cardCountsByBoard($ids);

		$boards = [];
		foreach ($rows as $row) {
			$id = (int)$row['id'];
			$boards[] = [
				'id' => $id,
				'title' => (string)$row['title'],
				'color' => $this->bareColor($row['color'] ?? null),
				'archived' => (bool)$row['archived'],
				'cardCount' => $counts[$id] ?? 0,
			];
		}
		usort($boards, static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));
		return $boards;
	}

	/** @return array{id: int, title: string, color: ?string, owner: string}|null */
	public function readBoard(int $boardId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('deck_boards')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$rows = $this->fetchAll($qb);
		if ($rows === []) {
			return null;
		}
		$row = $rows[0];
		return [
			'id' => (int)$row['id'],
			'title' => (string)$row['title'],
			'color' => $this->bareColor($row['color'] ?? null),
			'owner' => (string)$row['owner'],
		];
	}

	/** Whether the user owns or has a direct user ACL on the board. */
	public function userCanReadBoard(string $uid, int $boardId): bool {
		return in_array($boardId, $this->readableBoardIds($uid), true);
	}

	/**
	 * Non-deleted stacks of a board, ordered by Deck's `order`.
	 *
	 * @return list<array{id: int, title: string}>
	 */
	public function readStacks(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('deck_stacks')
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$rows = $this->sortByOrder($this->fetchAll($qb));
		return array_map(static fn (array $r): array => [
			'id' => (int)$r['id'],
			'title' => (string)$r['title'],
		], $rows);
	}

	/**
	 * Non-deleted cards of a stack, ordered by Deck's `order`.
	 *
	 * @return list<array{id: int, title: string, description: string, archived: bool, duedate: ?int, doneAt: int, createdAt: int}>
	 */
	public function readCards(int $stackId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('deck_cards')
			->where($qb->expr()->eq('stack_id', $qb->createNamedParameter($stackId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$rows = $this->sortByOrder($this->fetchAll($qb));
		return array_map(fn (array $r): array => [
			'id' => (int)$r['id'],
			'title' => (string)$r['title'],
			'description' => (string)($r['description'] ?? ''),
			'archived' => (bool)$r['archived'],
			'duedate' => $this->toTimestamp($r['duedate'] ?? null),
			'doneAt' => $this->toTimestamp($r['done'] ?? null) ?? 0,
			'createdAt' => (int)($r['created_at'] ?? 0),
		], $rows);
	}

	/**
	 * A board's labels.
	 *
	 * @return list<array{id: int, title: string, color: ?string}>
	 */
	public function readLabels(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('deck_labels')
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)));
		return array_map(fn (array $r): array => [
			'id' => (int)$r['id'],
			'title' => (string)$r['title'],
			'color' => $this->bareColor($r['color'] ?? null),
		], $this->fetchAll($qb));
	}

	/**
	 * label-id lists keyed by card id, for the given cards.
	 *
	 * @param int[] $cardIds
	 * @return array<int, int[]>
	 */
	public function readAssignedLabels(array $cardIds): array {
		if ($cardIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('card_id', 'label_id')->from('deck_assigned_labels')
			->where($qb->expr()->in('card_id', $qb->createNamedParameter($cardIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$out = [];
		foreach ($this->fetchAll($qb) as $r) {
			$out[(int)$r['card_id']][] = (int)$r['label_id'];
		}
		return $out;
	}

	/**
	 * assigned user uids keyed by card id (type 0 = user assignments only).
	 *
	 * @param int[] $cardIds
	 * @return array<int, string[]>
	 */
	public function readAssignedUsers(array $cardIds): array {
		if ($cardIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('card_id', 'participant')->from('deck_assigned_users')
			->where($qb->expr()->in('card_id', $qb->createNamedParameter($cardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$out = [];
		foreach ($this->fetchAll($qb) as $r) {
			$out[(int)$r['card_id']][] = (string)$r['participant'];
		}
		return $out;
	}

	/**
	 * A card's comments, oldest first. Deck stores comments in the CORE
	 * `oc_comments` table (object_type `deckCard`, object_id = the deck card id
	 * as a string), not in a `deck_*` table - so this reads the core table
	 * directly, filtered to the given cards.
	 *
	 * @param int[] $cardIds
	 * @return list<array{id: int, cardId: int, author: string, message: string, createdAt: int, parentId: int}>
	 */
	public function readComments(array $cardIds): array {
		if ($cardIds === []) {
			return [];
		}
		// object_id is stored as a string in oc_comments; compare as strings.
		$idStrings = array_map(static fn (int $id): string => (string)$id, $cardIds);

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'object_id', 'actor_id', 'message', 'creation_timestamp', 'parent_id')
			->from('comments')
			->where($qb->expr()->eq('object_type', $qb->createNamedParameter('deckCard')))
			->andWhere($qb->expr()->in('object_id', $qb->createNamedParameter($idStrings, IQueryBuilder::PARAM_STR_ARRAY)));
		$rows = $this->fetchAll($qb);

		$out = [];
		foreach ($rows as $r) {
			$out[] = [
				'id' => (int)$r['id'],
				'cardId' => (int)$r['object_id'],
				'author' => (string)($r['actor_id'] ?? ''),
				'message' => (string)($r['message'] ?? ''),
				'createdAt' => $this->toTimestamp($r['creation_timestamp'] ?? null) ?? 0,
				'parentId' => (int)($r['parent_id'] ?? 0),
			];
		}
		// Oldest-first so a reply's parent is always inserted before it.
		usort($out, static fn (array $a, array $b): int => [$a['createdAt'], $a['id']] <=> [$b['createdAt'], $b['id']]);
		return $out;
	}

	/**
	 * Deck's share types for a card-attached user file. Deck's `file`-kind
	 * attachments are NOT stored in `deck_attachment`; each is an `oc_share` row
	 * whose `share_with` is the card id (see Deck's FilesAppService). The share is
	 * of type {@see \OCP\Share\IShare::TYPE_DECK} (12); Deck also defines
	 * TYPE_DECK_USER (13) for per-user shares, so we accept both to avoid silently
	 * missing an attachment. Mirrored as literals so the importer never depends on
	 * Deck's PHP being loadable.
	 */
	private const SHARE_TYPES_DECK = [12, 13];

	/**
	 * A card's file attachments (`deck_file` kind only), non-deleted. The `data`
	 * column holds the stored FILENAME (not the bytes) - the bytes live in Deck's
	 * app-data, read separately by {@see DeckImportService}. The user-Files
	 * reference kind (`file`) is NOT returned here; those are Deck SHARES, read by
	 * {@see self::readFileReferenceAttachments()}.
	 *
	 * @param int[] $cardIds
	 * @return list<array{id: int, cardId: int, type: string, data: string, createdBy: string, createdAt: int}>
	 */
	public function readAttachments(array $cardIds): array {
		if ($cardIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'card_id', 'type', 'data', 'created_by', 'created_at')
			->from('deck_attachment')
			->where($qb->expr()->in('card_id', $qb->createNamedParameter($cardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('deck_file')))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		return array_map(fn (array $r): array => [
			'id' => (int)$r['id'],
			'cardId' => (int)$r['card_id'],
			'type' => (string)$r['type'],
			'data' => (string)($r['data'] ?? ''),
			'createdBy' => (string)($r['created_by'] ?? ''),
			'createdAt' => (int)($r['created_at'] ?? 0),
		], $this->fetchAll($qb));
	}

	/**
	 * The user-Files reference attachments (`file` kind) on the given cards.
	 *
	 * Deck stores these as SHARES, not as `deck_attachment` rows: each is an
	 * `oc_share` of type {@see self::SHARE_TYPE_DECK} whose `share_with` is the
	 * (stringified) card id, pointing at a node in the sharer's Files. We join
	 * `oc_share` → `oc_filecache` to resolve the source `fileId`/`filename`/owner
	 * so {@see DeckImportService} can copy the bytes into Kanso. A share whose
	 * permissions are 0 (Deck's soft-delete for a file attachment) is skipped.
	 *
	 * @param int[] $cardIds
	 * @return list<array{cardId: int, fileId: int, filename: string, owner: string, createdBy: string, createdAt: int}>
	 */
	public function readFileReferenceAttachments(array $cardIds): array {
		if ($cardIds === []) {
			return [];
		}
		// share_with holds the card id as a STRING; compare as strings.
		$idStrings = array_map(static fn (int $id): string => (string)$id, $cardIds);

		$qb = $this->db->getQueryBuilder();
		$qb->select('s.share_with', 's.file_source', 's.uid_owner', 's.uid_initiator', 's.stime', 'f.name')
			->from('share', 's')
			->leftJoin('s', 'filecache', 'f', $qb->expr()->eq('s.file_source', 'f.fileid'))
			->where($qb->expr()->in('s.share_type', $qb->createNamedParameter(self::SHARE_TYPES_DECK, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->in('s.share_with', $qb->createNamedParameter($idStrings, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->isNull('s.parent'))
			->andWhere($qb->expr()->neq('s.permissions', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$out = [];
		foreach ($this->fetchAll($qb) as $r) {
			$fileId = (int)($r['file_source'] ?? 0);
			if ($fileId <= 0) {
				continue;
			}
			// The file's owner is the authoritative uid for resolving the node
			// (uid_owner); uid_initiator is who created the share. Prefer the
			// owner, falling back to the initiator.
			$owner = (string)($r['uid_owner'] ?? '');
			$initiator = (string)($r['uid_initiator'] ?? '');
			$out[] = [
				'cardId' => (int)$r['share_with'],
				'fileId' => $fileId,
				'filename' => (string)($r['name'] ?? ''),
				'owner' => $owner !== '' ? $owner : $initiator,
				'createdBy' => $initiator !== '' ? $initiator : $owner,
				'createdAt' => (int)($r['stime'] ?? 0),
			];
		}
		return $out;
	}

	// -------------------------------------------------------------------------

	/**
	 * Board ids the user owns or has a direct user ACL on (type 0), non-deleted.
	 *
	 * @return int[]
	 */
	private function readableBoardIds(string $uid): array {
		$owned = $this->db->getQueryBuilder();
		$owned->select('id')->from('deck_boards')
			->where($owned->expr()->eq('owner', $owned->createNamedParameter($uid)))
			->andWhere($owned->expr()->eq('deleted_at', $owned->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$ids = array_map('intval', $this->fetchColumn($owned));

		$acl = $this->db->getQueryBuilder();
		$acl->select('board_id')->from('deck_board_acl')
			->where($acl->expr()->eq('participant', $acl->createNamedParameter($uid)))
			->andWhere($acl->expr()->eq('type', $acl->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		foreach ($this->fetchColumn($acl) as $bid) {
			$ids[] = (int)$bid;
		}

		return array_values(array_unique($ids));
	}

	/**
	 * card counts (non-deleted) keyed by board id, for the given boards.
	 *
	 * @param int[] $boardIds
	 * @return array<int, int>
	 */
	private function cardCountsByBoard(array $boardIds): array {
		if ($boardIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('s.board_id')
			->selectAlias($qb->func()->count('c.id'), 'cnt')
			->from('deck_cards', 'c')
			->innerJoin('c', 'deck_stacks', 's', $qb->expr()->eq('c.stack_id', 's.id'))
			->where($qb->expr()->in('s.board_id', $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('s.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->groupBy('s.board_id');
		$out = [];
		foreach ($this->fetchAll($qb) as $r) {
			$out[(int)$r['board_id']] = (int)$r['cnt'];
		}
		return $out;
	}

	/**
	 * @param IQueryBuilder $qb
	 * @return list<array<string, mixed>>
	 */
	private function fetchAll(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	/**
	 * @param IQueryBuilder $qb
	 * @return list<mixed>
	 */
	private function fetchColumn(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$col = $result->fetchAll(\PDO::FETCH_COLUMN);
		$result->closeCursor();
		return $col;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private function sortByOrder(array $rows): array {
		usort($rows, static fn (array $a, array $b): int => ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0)));
		return $rows;
	}

	/** A Deck timestamp string/int → unix seconds, or null when empty. */
	private function toTimestamp(mixed $value): ?int {
		if ($value === null || $value === '' || $value === 0) {
			return null;
		}
		if (is_numeric($value)) {
			return (int)$value;
		}
		$ts = strtotime((string)$value);
		return $ts === false ? null : $ts;
	}

	/**
	 * Normalizes a Deck colour to a bare 6-hex string, or null. Deck values that
	 * aren't 6-hex (empty, named, malformed) become null rather than reaching
	 * Kanso's strict ColorValidator and aborting the whole import; 3-hex
	 * shorthand is expanded.
	 */
	private function bareColor(mixed $color): ?string {
		if ($color === null || $color === '') {
			return null;
		}
		$hex = ltrim((string)$color, '#');
		if (preg_match('/^[0-9A-Fa-f]{3}$/', $hex) === 1) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return preg_match('/^[0-9A-Fa-f]{6}$/', $hex) === 1 ? $hex : null;
	}
}
