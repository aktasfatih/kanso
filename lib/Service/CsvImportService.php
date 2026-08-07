<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Imports rows of a CSV / spreadsheet as cards into an EXISTING board's chosen
 * stack - the "I have a spreadsheet of tasks, add them to my board" case. This
 * is deliberately NOT a whole-board importer (that lane is covered by
 * {@see DeckImportService} / {@see TrelloImportService}); it only appends cards
 * to a stack the caller can EDIT.
 *
 * The transactional, all-or-nothing convention mirrors the other importers:
 * every row's card is inserted in ONE DB transaction (any failure rolls the
 * whole import back), the document is byte-capped BEFORE parsing, the row count
 * is capped, and a title too long for the VARCHAR(100) column is truncated
 * rather than failing the import. Unlike the whole-board importers this writes
 * into an existing board, so it follows {@see CardService::create}'s conventions
 * for that board: cards APPEND to the stack via {@see SortKeyService::after},
 * each gets a per-board sequence number, an {@see Change::ACTION_CREATE} row is
 * appended to the board's change log, and a single realtime push fires after the
 * commit so the board updates live.
 *
 * Column mapping (0-based source column indexes chosen by the caller):
 *   - title       REQUIRED. A row with a blank title is skipped, not fatal.
 *   - description optional.
 *   - duedate     optional; a spreadsheet date/datetime → the card due date.
 *   - labels      optional; comma-separated names, match-or-CREATE on the board
 *                 (mirrors the whole-board importers, which create the labels
 *                 they reference rather than dropping them).
 *   - assignees   optional; comma-separated uids, match-or-DROP filtered by READ
 *                 on the target board (mirrors the Deck importer's assignee rule
 *                 and never leaks a uid onto a board they cannot see).
 */
class CsvImportService {
	/**
	 * Hard ceiling on the accepted CSV size, in bytes, enforced BEFORE parsing to
	 * bound memory. 5 MiB comfortably fits a large task spreadsheet while stopping
	 * a pathological upload.
	 */
	public const MAX_DOCUMENT_BYTES = 5 * 1024 * 1024;

	/**
	 * Hard ceiling on the number of DATA rows (header excluded) turned into cards.
	 * A file with more rows is rejected up-front rather than silently truncated.
	 */
	public const MAX_ROWS = 2000;

	/** Card/label title columns are VARCHAR(100); long values truncate to fit. */
	private const MAX_TITLE_LENGTH = 100;

	/** Colour given to a label auto-created during import (neutral grey). */
	private const DEFAULT_LABEL_COLOR = 'b3b3b3';

	public function __construct(
		private BoardService $boardService,
		private StackMapper $stackMapper,
		private CardMapper $cardMapper,
		private LabelMapper $labelMapper,
		private CardLabelMapper $cardLabelMapper,
		private CardAssigneeMapper $cardAssigneeMapper,
		private SortKeyService $sortKeyService,
		private ChangeNotifier $changeNotifier,
		private PermissionService $permissionService,
		private IUserManager $userManager,
		private IDBConnection $db,
	) {
	}

	/**
	 * Imports the CSV rows as cards appended to $stackId (which must belong to
	 * $boardId). EDIT on the board is required.
	 *
	 * $mapping maps a field name to the 0-based source column index (or null when
	 * that field is not mapped); `title` MUST be mapped. When $hasHeader is true
	 * the first parsed row is treated as headers and skipped.
	 *
	 * @param array{title: int, description?: ?int, duedate?: ?int, labels?: ?int, assignees?: ?int} $mapping
	 * @return array{boardId: int, stackId: int, cards: int, skipped: int, labelsCreated: int}
	 * @throws InvalidInputException on an oversized/malformed CSV, a missing title mapping, or too many rows
	 * @throws NotPermittedException if the actor lacks EDIT on the board
	 */
	public function import(
		string $rawDocument,
		int $boardId,
		int $stackId,
		array $mapping,
		bool $hasHeader,
		string $actorUid,
	): array {
		if (strlen($rawDocument) > self::MAX_DOCUMENT_BYTES) {
			throw new InvalidInputException('The CSV file is too large to import');
		}
		if (!isset($mapping['title'])) {
			throw new InvalidInputException('A title column must be mapped');
		}

		$rows = $this->parseCsv($rawDocument);
		if ($hasHeader) {
			array_shift($rows);
		}
		if (count($rows) > self::MAX_ROWS) {
			throw new InvalidInputException('The CSV has too many rows to import (limit ' . self::MAX_ROWS . ')');
		}

		// Load + EDIT-gate the target board and stack up-front, before any write,
		// so a permission denial never leaves a partial import.
		$board = $this->boardService->find($boardId, $actorUid);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$stack = $this->stackMapper->find($stackId);
		if ($stack->getBoardId() !== $boardId) {
			throw new InvalidInputException('That stack does not belong to the chosen board');
		}

		$this->db->beginTransaction();
		try {
			$result = $this->rebuild($rows, $board, $stack, $mapping, $actorUid);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Commit succeeded - broadcast the board change exactly once for the whole
		// batch (not per card), mirroring the importers' single push.
		if ($result['cards'] > 0) {
			$this->changeNotifier->pushBoardChanged($boardId);
		}
		return $result;
	}

	/**
	 * @param list<list<string>> $rows
	 * @param array{title: int, description?: ?int, duedate?: ?int, labels?: ?int, assignees?: ?int} $mapping
	 * @return array{boardId: int, stackId: int, cards: int, skipped: int, labelsCreated: int}
	 */
	private function rebuild(array $rows, Board $board, Stack $stack, array $mapping, string $actorUid): array {
		$boardId = $board->getId();
		$stackId = $stack->getId();
		$now = time();

		// Existing labels on the board, indexed case-insensitively by title, so a
		// name that already exists is REUSED rather than duplicated.
		$labelIdByName = [];
		foreach ($this->labelMapper->findByBoard($boardId) as $label) {
			$labelIdByName[$this->labelKey((string)$label->getTitle())] = $label->getId();
		}
		$labelsCreated = 0;

		// Append after the current tail of the stack; each new card advances the
		// sort key so the imported block preserves file order at the bottom.
		$last = $this->cardMapper->findLastInStack($stackId);
		$cardKey = $last === null ? null : $last->getSortKey();

		$titleIdx = $mapping['title'];
		$descIdx = $mapping['description'] ?? null;
		$dueIdx = $mapping['duedate'] ?? null;
		$labelsIdx = $mapping['labels'] ?? null;
		$assigneesIdx = $mapping['assignees'] ?? null;

		$cards = 0;
		$skipped = 0;
		foreach ($rows as $row) {
			$title = $this->title($this->cell($row, $titleIdx));
			if ($title === '') {
				// A row with no title cannot become a card - skipped, never fatal.
				$skipped++;
				continue;
			}

			$card = new Card();
			$card->setBoardId($boardId);
			$card->setStackId($stackId);
			$card->setTitle($title);
			$description = $descIdx === null ? '' : trim($this->cell($row, $descIdx));
			$card->setDescription($description !== '' ? $description : null);
			$cardKey = $cardKey === null ? $this->sortKeyService->initial() : $this->sortKeyService->after($cardKey);
			$card->setSortKey($cardKey);
			$card->setBoardSeq($this->cardMapper->nextBoardSeq($boardId));
			$due = $dueIdx === null ? null : $this->toDate($this->cell($row, $dueIdx));
			$card->setDuedate($due);
			// A date-only value (no time component) is an all-day due date, so the
			// pill hides the midnight time - matching the composer's date tokens.
			if ($due !== null) {
				$card->setAllDay($this->isDateOnly($this->cell($row, $dueIdx)));
			}
			$card->setDoneAt($stack->getRole() === Stack::ROLE_DONE ? $now : 0);
			$card->setStartedAt(
				in_array($stack->getRole(), [Stack::ROLE_IN_PROGRESS, Stack::ROLE_REVIEW], true) ? $now : 0,
			);
			$card->setArchived(false);
			$card->setOwner($actorUid);
			$card->setCreatedAt($now);
			$card->setLastModified($now);
			$card->setDeletedAt(0);
			$card->setParentCardId(null);
			$card->setPriority(0);
			$card->setIsTemplate(false);
			$newCardId = $this->cardMapper->insert($card)->getId();

			// The card and its CREATE change row are written inside the SAME
			// transaction as the rest of the import (all-or-nothing), so delta
			// sync / ETag stay correct for every imported card.
			$this->changeNotifier->recordChange(
				$boardId,
				Change::ENTITY_CARD,
				$newCardId,
				Change::ACTION_CREATE,
				$actorUid,
				Change::VERB_CREATED,
			);

			if ($labelsIdx !== null) {
				$labelsCreated += $this->attachLabels(
					$this->splitList($this->cell($row, $labelsIdx)),
					$newCardId,
					$boardId,
					$labelIdByName,
				);
			}
			if ($assigneesIdx !== null) {
				$this->attachAssignees(
					$this->splitList($this->cell($row, $assigneesIdx)),
					$newCardId,
					$board,
				);
			}

			$cards++;
		}

		return [
			'boardId' => $boardId,
			'stackId' => $stackId,
			'cards' => $cards,
			'skipped' => $skipped,
			'labelsCreated' => $labelsCreated,
		];
	}

	/**
	 * Match-or-create each label name on the board: a name that already exists
	 * (case-insensitively) is reused, an unseen one is created once and cached so
	 * repeated names across rows share one label. Mirrors the whole-board
	 * importers, which create the labels they reference.
	 *
	 * @param string[] $names
	 * @param array<string, int> $labelIdByName label key → id, updated by reference
	 * @return int the number of labels newly created
	 */
	private function attachLabels(array $names, int $cardId, int $boardId, array &$labelIdByName): int {
		$created = 0;
		$seen = [];
		foreach ($names as $name) {
			$name = $this->title($name);
			if ($name === '') {
				continue;
			}
			$key = $this->labelKey($name);
			$labelId = $labelIdByName[$key] ?? null;
			if ($labelId === null) {
				$label = new Label();
				$label->setBoardId($boardId);
				$label->setTitle($name);
				$label->setColor(self::DEFAULT_LABEL_COLOR);
				$labelId = $this->labelMapper->insert($label)->getId();
				$labelIdByName[$key] = $labelId;
				$created++;
			}
			if (!isset($seen[$labelId])) {
				$this->cardLabelMapper->insertAssignment($cardId, $labelId);
				$seen[$labelId] = true;
			}
		}
		return $created;
	}

	/**
	 * Assign each uid that (a) exists and (b) can READ the target board. A uid
	 * that fails either check is DROPPED, never assigned - the leak guard that
	 * stops an imported card referencing someone who cannot see the board.
	 *
	 * @param string[] $uids
	 */
	private function attachAssignees(array $uids, int $cardId, Board $board): void {
		$seen = [];
		foreach ($uids as $uid) {
			$uid = trim($uid);
			if ($uid === '' || isset($seen[$uid])) {
				continue;
			}
			$seen[$uid] = true;
			if (!$this->userManager->userExists($uid)) {
				continue;
			}
			if (($this->permissionService->getPermissions($board, $uid) & PermissionService::PERMISSION_READ) === 0) {
				continue;
			}
			$this->cardAssigneeMapper->insertAssignment($cardId, $uid);
		}
	}

	// ── parsing / helpers ────────────────────────────────────────────────────────

	/**
	 * Parses the raw CSV into rows of string cells. Uses PHP's own CSV lexer
	 * (via a memory stream) so quoted fields, embedded newlines and escaped
	 * quotes are handled correctly rather than a naive split. A blank line
	 * produces an empty row that later title-skipping drops.
	 *
	 * @return list<list<string>>
	 * @throws InvalidInputException if the document is not decodable text
	 */
	private function parseCsv(string $raw): array {
		// Strip a UTF-8 BOM so the first header cell is not prefixed with it.
		if (str_starts_with($raw, "\xEF\xBB\xBF")) {
			$raw = substr($raw, 3);
		}
		$handle = fopen('php://temp', 'r+');
		if ($handle === false) {
			throw new InvalidInputException('The CSV file could not be read');
		}
		fwrite($handle, $raw);
		rewind($handle);

		$rows = [];
		while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
			// fgetcsv can yield null (on read error) or a `[null]` row for a blank
			// line; normalise both to string cells so later mapping never hits a
			// null.
			if (!is_array($cells)) {
				continue;
			}
			$normalised = [];
			foreach ($cells as $cell) {
				$normalised[] = $cell ?? '';
			}
			$rows[] = $normalised;
		}
		fclose($handle);
		return $rows;
	}

	/**
	 * A cell at $index, or '' when the row is shorter than the mapped column (a
	 * ragged row never throws).
	 *
	 * @param list<string> $row
	 */
	private function cell(array $row, ?int $index): string {
		if ($index === null || $index < 0 || !isset($row[$index])) {
			return '';
		}
		return $row[$index];
	}

	/**
	 * A comma-separated cell → its trimmed, non-empty values.
	 *
	 * @return string[]
	 */
	private function splitList(string $cell): array {
		$out = [];
		foreach (explode(',', $cell) as $part) {
			$part = trim($part);
			if ($part !== '') {
				$out[] = $part;
			}
		}
		return $out;
	}

	/** Fits an external value into the VARCHAR(100) title columns (trimmed first). */
	private function title(string $value): string {
		return mb_substr(trim($value), 0, self::MAX_TITLE_LENGTH);
	}

	/** Case-insensitive key for matching an existing label by title. */
	private function labelKey(string $title): string {
		return mb_strtolower(trim($title));
	}

	/**
	 * A spreadsheet date/datetime cell → a UTC \DateTime, or null when blank or
	 * unparseable (an unrecognised date is dropped, never fatal to the import).
	 */
	private function toDate(string $value): ?\DateTime {
		$value = trim($value);
		if ($value === '') {
			return null;
		}
		$ts = strtotime($value);
		if ($ts === false) {
			return null;
		}
		return (new \DateTime('@' . $ts))->setTimezone(new \DateTimeZone('UTC'));
	}

	/** Whether a due-date cell carries no time component (a date-only value). */
	private function isDateOnly(string $value): bool {
		return preg_match('/\d[:h]\d|\d\s*(am|pm)/i', $value) !== 1;
	}
}
