<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\ChecklistItem;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCP\IDBConnection;

/**
 * Imports a Trello board's JSON export into a FRESH Kanso board owned by the
 * importer - a copy, never a link. Mirrors {@see DeckImportService} /
 * {@see ImportService} (create board via {@see BoardService::create} so the
 * change log + ETag start clean; everything in ONE transaction) but the source
 * is an uploaded/pasted Trello document rather than Deck DB tables.
 *
 * What v1 maps:
 *   - board name → board title,
 *   - lists → stacks (Trello `pos` order preserved; a `closed` list is imported
 *     ARCHIVED rather than dropped, so nothing is silently lost),
 *   - cards → cards (name→title, desc→description, due→duedate,
 *     `dueComplete`→done, `closed`→archived, `pos`→sort order),
 *   - labels → labels (Trello name+colour → Kanso label; Trello's named colours
 *     map to hex, unknown/blank falls back to a default),
 *   - checklists+checkItems → the card's flat checklist (`state:complete`→done),
 *   - card↔label links.
 *
 * Deliberately OUT of scope for v1 (documented): attachments, members/assignees
 * (Trello member ids don't map to Nextcloud uids, so assignees are dropped),
 * comments and activity. One board per import.
 */
class TrelloImportService {
	/**
	 * Hard ceiling on the accepted document size, in bytes. Trello exports are
	 * plain JSON decoded as a whole (json_decode) before the rows are inserted one
	 * at a time, so the cap bounds the decode's memory rather than a card count.
	 * 32 MiB comfortably fits very large boards while staying within a normal PHP
	 * memory limit (matches {@see ImportService::MAX_DOCUMENT_BYTES}).
	 */
	public const MAX_DOCUMENT_BYTES = 32 * 1024 * 1024;

	/** Fallback label colour when Trello's colour is blank/unknown. */
	private const DEFAULT_LABEL_COLOR = 'b3b3b3';

	/**
	 * Kanso's board/stack/card/label/checklist title columns are VARCHAR(100).
	 * Trello names can be far longer, so titles are truncated to fit rather than
	 * letting an over-length value fail the whole (transactional) import.
	 */
	private const MAX_TITLE_LENGTH = 100;

	/**
	 * Trello's named label/board colours → bare 6-hex. Values chosen to visually
	 * echo Trello. A colour not in this map (or null) falls back to the default.
	 */
	private const TRELLO_COLORS = [
		'green' => '61bd4f',
		'yellow' => 'f2d600',
		'orange' => 'ff9f1a',
		'red' => 'eb5a46',
		'purple' => 'c377e0',
		'blue' => '0079bf',
		'sky' => '00c2e0',
		'lime' => '51e898',
		'pink' => 'ff78cb',
		'black' => '344563',
		'green_dark' => '519839',
		'yellow_dark' => 'd9b51c',
		'orange_dark' => 'cd8313',
		'red_dark' => 'b04632',
		'purple_dark' => '89609e',
		'blue_dark' => '055a8c',
		'sky_dark' => '0098b7',
		'lime_dark' => '4bce97',
		'pink_dark' => 'c75ba7',
		'black_dark' => '091e42',
		'green_light' => '7bc86c',
		'yellow_light' => 'f5dd29',
		'orange_light' => 'faa53d',
		'red_light' => 'ef7564',
		'purple_light' => 'cd8de5',
		'blue_light' => '5ba4cf',
		'sky_light' => '29cce5',
		'lime_light' => '6deca9',
		'pink_light' => 'ff8ed4',
		'black_light' => '8993a4',
	];

	public function __construct(
		private BoardService $boardService,
		private StackMapper $stackMapper,
		private CardMapper $cardMapper,
		private LabelMapper $labelMapper,
		private CardLabelMapper $cardLabelMapper,
		private ChecklistItemMapper $checklistItemMapper,
		private SortKeyService $sortKeyService,
		private IDBConnection $db,
	) {
	}

	/**
	 * Imports a pasted/uploaded Trello board export into a new Kanso board owned
	 * by the actor.
	 *
	 * @param string $rawDocument the raw uploaded/pasted Trello JSON export
	 * @return array{boardId: int, title: string, stacks: int, cards: int, labels: int}
	 * @throws InvalidInputException on an oversized, malformed or non-Trello document
	 */
	public function import(string $rawDocument, string $actorUid): array {
		if (strlen($rawDocument) > self::MAX_DOCUMENT_BYTES) {
			throw new InvalidInputException('The Trello export file is too large to import');
		}

		try {
			$doc = json_decode($rawDocument, true, 64, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new InvalidInputException('The file is not valid JSON');
		}
		// A Trello board export is an object carrying `lists` and `cards` arrays.
		if (!is_array($doc) || !isset($doc['lists']) || !is_array($doc['lists'])
			|| !isset($doc['cards']) || !is_array($doc['cards'])) {
			throw new InvalidInputException('The file is not a Trello board export');
		}

		$this->db->beginTransaction();
		try {
			$result = $this->rebuild($doc, $actorUid);
			$this->db->commit();
			return $result;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * @param array<string, mixed> $doc
	 * @return array{boardId: int, title: string, stacks: int, cards: int, labels: int}
	 */
	private function rebuild(array $doc, string $actorUid): array {
		$title = isset($doc['name']) && is_string($doc['name']) && $doc['name'] !== ''
			? $this->title($doc['name']) : 'Imported Trello board';
		$board = $this->boardService->create($title, null, $actorUid);
		$boardId = $board->getId();
		$now = time();

		$labelIdMap = $this->importLabels($doc, $boardId);
		$stackIdMap = $this->importLists($doc, $boardId);
		$cardCount = $this->importCards($doc, $boardId, $stackIdMap, $labelIdMap, $actorUid, $now);

		return [
			'boardId' => $boardId,
			'title' => $board->getTitle(),
			'stacks' => count($stackIdMap),
			'cards' => $cardCount,
			'labels' => count($labelIdMap),
		];
	}

	/**
	 * Trello labels live under the board's `labels` array. A label with a blank
	 * name is given a readable title from its colour so it is not anonymous.
	 *
	 * @param array<string, mixed> $doc
	 * @return array<string, int> trello label id → new kanso label id
	 */
	private function importLabels(array $doc, int $boardId): array {
		$map = [];
		foreach ($this->rows($doc, 'labels') as $row) {
			$trelloId = isset($row['id']) ? (string)$row['id'] : null;
			if ($trelloId === null) {
				continue;
			}
			$colorName = isset($row['color']) && is_string($row['color']) ? $row['color'] : null;
			$name = isset($row['name']) && is_string($row['name']) ? trim($row['name']) : '';
			if ($name === '') {
				$name = $colorName !== null && $colorName !== '' ? ucfirst($colorName) : 'Label';
			}
			$label = new Label();
			$label->setBoardId($boardId);
			$label->setTitle($this->title($name));
			$label->setColor($this->labelColor($colorName));
			$map[$trelloId] = $this->labelMapper->insert($label)->getId();
		}
		return $map;
	}

	/**
	 * Trello lists → stacks, ordered by Trello's numeric `pos`. A `closed` list
	 * is imported archived (documented choice) rather than dropped.
	 *
	 * @param array<string, mixed> $doc
	 * @return array<string, int> trello list id → new kanso stack id
	 */
	private function importLists(array $doc, int $boardId): array {
		$lists = $this->rows($doc, 'lists');
		usort($lists, static fn (array $a, array $b): int => ((float)($a['pos'] ?? 0)) <=> ((float)($b['pos'] ?? 0)));

		$map = [];
		$stackKey = null;
		foreach ($lists as $row) {
			$trelloId = isset($row['id']) ? (string)$row['id'] : null;
			if ($trelloId === null) {
				continue;
			}
			$stack = new Stack();
			$stack->setBoardId($boardId);
			$stack->setTitle($this->title(isset($row['name']) && is_string($row['name']) ? $row['name'] : ''));
			$stackKey = $stackKey === null ? $this->sortKeyService->initial() : $this->sortKeyService->after($stackKey);
			$stack->setSortKey($stackKey);
			$stack->setArchived((bool)($row['closed'] ?? false));
			$stack->setRole(Stack::ROLE_NONE);
			$stack->setWipLimit(null);
			$stack->setDeletedAt(0);
			$map[$trelloId] = $this->stackMapper->insert($stack)->getId();
		}
		return $map;
	}

	/**
	 * Trello cards → cards, grouped per list and ordered by Trello's `pos`. A
	 * card whose list is unknown is skipped (it cannot live on the board).
	 *
	 * @param array<string, mixed> $doc
	 * @param array<string, int> $stackIdMap
	 * @param array<string, int> $labelIdMap
	 * @return int number of cards imported
	 */
	private function importCards(
		array $doc,
		int $boardId,
		array $stackIdMap,
		array $labelIdMap,
		string $actorUid,
		int $now,
	): int {
		// Group cards by their list, preserving Trello `pos` order within each.
		$byList = [];
		foreach ($this->rows($doc, 'cards') as $row) {
			$listId = isset($row['idList']) ? (string)$row['idList'] : null;
			if ($listId === null || !isset($stackIdMap[$listId])) {
				continue;
			}
			$byList[$listId][] = $row;
		}

		$checklistsByCard = $this->indexChecklistsByCard($doc);
		$count = 0;

		foreach ($stackIdMap as $trelloListId => $newStackId) {
			$cards = $byList[$trelloListId] ?? [];
			usort($cards, static fn (array $a, array $b): int => ((float)($a['pos'] ?? 0)) <=> ((float)($b['pos'] ?? 0)));

			$cardKey = null;
			foreach ($cards as $row) {
				$card = new Card();
				$card->setBoardId($boardId);
				$card->setStackId($newStackId);
				$card->setTitle($this->title(isset($row['name']) && is_string($row['name']) ? $row['name'] : ''));
				$card->setDescription(isset($row['desc']) && is_string($row['desc']) && $row['desc'] !== '' ? $row['desc'] : null);
				$cardKey = $cardKey === null ? $this->sortKeyService->initial() : $this->sortKeyService->after($cardKey);
				$card->setSortKey($cardKey);
				$due = $this->toTimestamp($row['due'] ?? null);
				$card->setDuedate($due !== null ? (new \DateTime())->setTimestamp($due) : null);
				// Trello marks a due date complete with `dueComplete`; treat that as
				// the card being done (stamped at the due time when known, else now).
				$card->setDoneAt(($row['dueComplete'] ?? false) === true ? ($due ?? $now) : 0);
				$card->setArchived((bool)($row['closed'] ?? false));
				$card->setOwner($actorUid);
				$card->setCreatedAt($now);
				$card->setLastModified($now);
				$card->setDeletedAt(0);
				$card->setParentCardId(null);
				$card->setPriority(0);
				$newCardId = $this->cardMapper->insert($card)->getId();
				$count++;

				$this->attachLabels($row, $newCardId, $labelIdMap);
				$trelloCardId = isset($row['id']) ? (string)$row['id'] : null;
				if ($trelloCardId !== null) {
					$this->attachChecklists($checklistsByCard[$trelloCardId] ?? [], $newCardId, $now);
				}
			}
		}

		return $count;
	}

	/**
	 * A card's Trello label links. Newer exports carry `idLabels` (ids into the
	 * board `labels`); we resolve those to the new Kanso label ids.
	 *
	 * @param array<string, mixed> $row
	 * @param array<string, int> $labelIdMap
	 */
	private function attachLabels(array $row, int $newCardId, array $labelIdMap): void {
		$seen = [];
		foreach ((array)($row['idLabels'] ?? []) as $trelloLabelId) {
			$newLabelId = $labelIdMap[(string)$trelloLabelId] ?? null;
			if ($newLabelId !== null && !isset($seen[$newLabelId])) {
				$this->cardLabelMapper->insertAssignment($newCardId, $newLabelId);
				$seen[$newLabelId] = true;
			}
		}
	}

	/**
	 * Trello's checklists live in a top-level `checklists` array, each keyed to a
	 * card via `idCard`, ordered by `pos`, with `checkItems` (also `pos`-ordered).
	 * Kanso's checklist is flat, so every checkItem across a card's checklists is
	 * appended in order.
	 *
	 * @param array<string, mixed> $doc
	 * @return array<string, list<array<string, mixed>>> trello card id → its checklists (pos-ordered)
	 */
	private function indexChecklistsByCard(array $doc): array {
		$byCard = [];
		foreach ($this->rows($doc, 'checklists') as $checklist) {
			$cardId = isset($checklist['idCard']) ? (string)$checklist['idCard'] : null;
			if ($cardId === null) {
				continue;
			}
			$byCard[$cardId][] = $checklist;
		}
		foreach ($byCard as &$checklists) {
			usort($checklists, static fn (array $a, array $b): int => ((float)($a['pos'] ?? 0)) <=> ((float)($b['pos'] ?? 0)));
		}
		unset($checklists);
		return $byCard;
	}

	/**
	 * Flattens a card's Trello checklists into Kanso checklist items in order,
	 * `state:complete` → done. Clone-path policy for rich steps (#3745): a
	 * checkItem's `due` (ISO 8601) is KEPT as the step due date; its
	 * `idMember` is deliberately DROPPED - Trello member ids do not map to
	 * Nextcloud uids, so imported steps arrive unassigned.
	 *
	 * @param list<array<string, mixed>> $checklists
	 */
	private function attachChecklists(array $checklists, int $newCardId, int $now): void {
		$itemKey = null;
		foreach ($checklists as $checklist) {
			$items = [];
			foreach ((array)($checklist['checkItems'] ?? []) as $item) {
				if (is_array($item)) {
					$items[] = $item;
				}
			}
			usort($items, static fn (array $a, array $b): int => ((float)($a['pos'] ?? 0)) <=> ((float)($b['pos'] ?? 0)));

			foreach ($items as $item) {
				$entity = new ChecklistItem();
				$entity->setCardId($newCardId);
				$entity->setTitle($this->title(isset($item['name']) && is_string($item['name']) ? $item['name'] : ''));
				$entity->setDone(($item['state'] ?? null) === 'complete');
				$itemKey = $itemKey === null ? $this->sortKeyService->initial() : $this->sortKeyService->after($itemKey);
				$entity->setSortKey($itemKey);
				$entity->setCreatedAt($now);
				$due = $this->parseCheckItemDue($item['due'] ?? null);
				if ($due !== null) {
					$entity->setDueDate($due);
				}
				$this->checklistItemMapper->insert($entity);
			}
		}
	}

	/**
	 * A Trello checkItem `due` (ISO 8601, e.g. 2026-07-22T12:00:00.000Z),
	 * normalized to UTC. Anything unparsable is dropped - a lost due date must
	 * never fail a whole board import.
	 */
	private function parseCheckItemDue(mixed $due): ?\DateTime {
		if (!is_string($due) || $due === '') {
			return null;
		}
		try {
			return (new \DateTime($due))->setTimezone(new \DateTimeZone('UTC'));
		} catch (\Exception) {
			return null;
		}
	}

	// ── helpers ────────────────────────────────────────────────────────────────

	/**
	 * The list under $doc[$key] as an array of associative rows, ignoring
	 * anything that is not itself an array.
	 *
	 * @param array<string, mixed> $doc
	 * @return list<array<string, mixed>>
	 */
	private function rows(array $doc, string $key): array {
		$out = [];
		foreach ((array)($doc[$key] ?? []) as $row) {
			if (is_array($row)) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Fits an externally-sourced Trello name into Kanso's VARCHAR(100) title
	 * columns so a long name truncates instead of failing the whole import.
	 */
	private function title(string $value): string {
		return mb_substr($value, 0, self::MAX_TITLE_LENGTH);
	}

	/** A Trello named colour → bare 6-hex, falling back to the default. */
	private function labelColor(?string $colorName): string {
		if ($colorName === null || $colorName === '') {
			return self::DEFAULT_LABEL_COLOR;
		}
		return self::TRELLO_COLORS[$colorName] ?? self::DEFAULT_LABEL_COLOR;
	}

	/** A Trello ISO-8601 date string / unix number → unix seconds, or null. */
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
}
