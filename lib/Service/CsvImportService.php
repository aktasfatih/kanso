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
 * whole import back), the document is byte-capped BEFORE parsing, and a title
 * too long for the VARCHAR(100) column is truncated rather than failing the
 * import. Rows are STREAMED out of the CSV lexer one at a time (never
 * materialised into one big in-memory array), so peak memory stays proportional
 * to the document size no matter how many cards it holds; the row count is
 * capped only as a generous backstop against a pathological upload. Unlike the
 * whole-board importers this writes
 * into an existing board, so it follows {@see CardService::create}'s conventions
 * for that board: cards APPEND to the stack via a single bounded
 * {@see SortKeyService::appendSequence} block (so a big import never grows the
 * sort key per card), each gets a per-board sequence number, an
 * {@see Change::ACTION_CREATE} row is
 * appended to the board's change log, and a single realtime push fires after the
 * commit so the board updates live.
 *
 * Because the block is anchored past the stack's CURRENT tail key, a long-lived
 * stack whose keys have already grown to the varchar(64) wall would otherwise
 * fail the whole import with a 409 `rebalance_required` that only an `occ`
 * command could clear. Instead the import self-heals: the overflow is caught,
 * the target stack is rebalanced to short keys ({@see CardService::rebalanceStack})
 * and the import is replayed ONCE - see {@see self::import}.
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
	 * bound memory. Because rows are streamed (not held all at once) this is the
	 * dominant memory cost, so it can be generous: 64 MiB fits a very large task
	 * spreadsheet while still stopping a pathological upload.
	 */
	public const MAX_DOCUMENT_BYTES = 64 * 1024 * 1024;

	/**
	 * Backstop on the number of DATA rows (header excluded) turned into cards. The
	 * point is to import ALL of a real spreadsheet, so this sits far above any
	 * hand-maintained board; a file past it is rejected up-front (before any DB
	 * write) rather than silently truncated.
	 */
	public const MAX_ROWS = 200000;

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
		// Only for rebalanceStack(): the recovery when the target stack's tail
		// sort key is already at the varchar(64) wall (see import()).
		private CardService $cardService,
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
	 * If the target stack's existing keys are already at the sort-key wall the
	 * first attempt overflows before writing anything; the stack is then
	 * rebalanced and the import replayed once (see below).
	 *
	 * @param array{title: int, description?: ?int, duedate?: ?int, labels?: ?int, assignees?: ?int} $mapping
	 * @return array{boardId: int, stackId: int, cards: int, skipped: int, labelsCreated: int}
	 * @throws InvalidInputException on an oversized/malformed CSV, a missing title mapping, or too many rows
	 * @throws NotPermittedException if the actor lacks EDIT on the board
	 * @throws \OverflowException if the sort keys still overflow after the rebalance
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

		// The document is lexed into a temp stream once and read row-by-row from it
		// (twice: a cheap counting pass, then the insert pass), so no full array of
		// rows is ever held - peak memory tracks the document, not the card count.
		$handle = $this->openCsv($rawDocument);
		try {
			$dataRows = $this->countDataRows($handle, $hasHeader);
			if ($dataRows > self::MAX_ROWS) {
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

			rewind($handle);
			try {
				$result = $this->attempt($handle, $hasHeader, $board, $stack, $mapping, $actorUid, $dataRows);
			} catch (\OverflowException) {
				// The stack's tail key is already at MAX_KEY_LENGTH, so no block of
				// keys fits past it: appendSequence() threw BEFORE the first insert
				// and attempt() has already rolled its transaction back - nothing was
				// written and, critically, NO transaction is open at this point.
				// That is what makes the recovery safe: rebalanceStack() opens its
				// OWN transaction (and, where the server and database offer one,
				// takes SELECT ... FOR UPDATE locks on the stack's rows), so it
				// must never be called from inside the import's transaction. On
				// SQLite that matters even more: it admits a single writer, so
				// nesting would deadlock rather than merely nest.
				// Reset the stack to short keys here, between attempts,
				// then replay the import ONCE (still all-or-nothing). A second
				// overflow is genuine and propagates as the 409 it always was.
				$this->cardService->rebalanceStack($stackId);
				rewind($handle);
				$result = $this->attempt($handle, $hasHeader, $board, $stack, $mapping, $actorUid, $dataRows);
			}
		} finally {
			fclose($handle);
		}

		// Commit succeeded - broadcast the board change exactly once for the whole
		// batch (not per card), mirroring the importers' single push.
		if ($result['cards'] > 0) {
			$this->changeNotifier->pushBoardChanged($boardId);
		}
		return $result;
	}

	/**
	 * ONE all-or-nothing import attempt: {@see rebuild} wrapped in a single
	 * transaction that is rolled back completely on any failure.
	 *
	 * It always returns with NO transaction open - committed on success, rolled
	 * back on failure - which is precisely what lets {@see import} rebalance the
	 * target stack between attempts without nesting inside this transaction.
	 *
	 * @param resource $handle a rewound CSV stream positioned at the first record
	 * @param array{title: int, description?: ?int, duedate?: ?int, labels?: ?int, assignees?: ?int} $mapping
	 * @return array{boardId: int, stackId: int, cards: int, skipped: int, labelsCreated: int}
	 */
	private function attempt($handle, bool $hasHeader, Board $board, Stack $stack, array $mapping, string $actorUid, int $dataRows): array {
		$this->db->beginTransaction();
		try {
			$result = $this->rebuild($handle, $hasHeader, $board, $stack, $mapping, $actorUid, $dataRows);
			$this->db->commit();
			return $result;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Streams the data rows off $handle (skipping the header when present) and
	 * inserts one card per titled row. Reads a row at a time via {@see readRow} so
	 * the whole file is never held in memory at once.
	 *
	 * @param resource $handle a rewound CSV stream positioned at the first record
	 * @param array{title: int, description?: ?int, duedate?: ?int, labels?: ?int, assignees?: ?int} $mapping
	 * @param int $dataRows the number of data rows counted in the pre-pass; sizes
	 *                      the sort-key block so it never runs short
	 * @return array{boardId: int, stackId: int, cards: int, skipped: int, labelsCreated: int}
	 */
	private function rebuild($handle, bool $hasHeader, Board $board, Stack $stack, array $mapping, string $actorUid, int $dataRows): array {
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

		// Append after the current tail of the stack. The whole block's sort keys
		// are laid out in one shot as a bounded, evenly-spaced sequence past the
		// tail, so the imported cards preserve file order at the bottom WITHOUT the
		// key length growing per card (chaining after() would overflow a big
		// import). Keys are handed out in order to the titled rows that survive.
		$last = $this->cardMapper->findLastInStack($stackId);
		$sortKeys = $this->sortKeyService->appendSequence($dataRows, $last?->getSortKey());
		$keyIndex = 0;

		$titleIdx = $mapping['title'];
		$descIdx = $mapping['description'] ?? null;
		$dueIdx = $mapping['duedate'] ?? null;
		$labelsIdx = $mapping['labels'] ?? null;
		$assigneesIdx = $mapping['assignees'] ?? null;

		// The header row (if any) is consumed and discarded before the data loop.
		if ($hasHeader) {
			$this->readRow($handle);
		}

		$cards = 0;
		$skipped = 0;
		while (($row = $this->readRow($handle)) !== null) {
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
			$card->setSortKey($sortKeys[$keyIndex]);
			$keyIndex++;
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
	 * Loads the raw CSV into a rewound temp stream ready to be read record by
	 * record. Using PHP's own CSV lexer (via the stream) means quoted fields,
	 * embedded newlines and escaped quotes are handled correctly rather than by a
	 * naive split. The caller is responsible for fclose()-ing the handle.
	 *
	 * @return resource
	 * @throws InvalidInputException if the document cannot be buffered
	 */
	private function openCsv(string $raw) {
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
		return $handle;
	}

	/**
	 * The next CSV record off $handle as normalised string cells, or null at EOF.
	 * fgetcsv can yield a non-array on a read error (skipped) or a `[null]` row for
	 * a blank line; both null cells and that blank row are normalised to '' so
	 * later mapping never hits a null (a blank row later title-skips itself).
	 *
	 * @param resource $handle
	 * @return list<string>|null
	 */
	private function readRow($handle): ?array {
		while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
			if (!is_array($cells)) {
				continue;
			}
			$normalised = [];
			foreach ($cells as $cell) {
				$normalised[] = $cell ?? '';
			}
			return $normalised;
		}
		return null;
	}

	/**
	 * Counts the data records on $handle (excluding the header when present) by
	 * streaming through it, then rewinds it for the caller. Holds no rows in
	 * memory - it exists so an over-limit file is rejected BEFORE any board load
	 * or DB write, without materialising the document.
	 *
	 * @param resource $handle
	 */
	private function countDataRows($handle, bool $hasHeader): int {
		rewind($handle);
		if ($hasHeader) {
			$this->readRow($handle);
		}
		$count = 0;
		while ($this->readRow($handle) !== null) {
			$count++;
		}
		rewind($handle);
		return $count;
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
