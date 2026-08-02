<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Migration;

use Closure;
use OCA\Kanso\Db\BoardPrefix;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Human-readable card identifiers (#3544): every card gets a stable
 * `PREFIX-<board_seq>` reference (e.g. "KAN-123"), a DISPLAY/reference id only -
 * ordering stays the fractional sort key and the numeric id stays the route key.
 *
 * Schema:
 *  - `kanso_boards.prefix`   nullable string - the per-board prefix, derived
 *                            from the board title (BoardPrefix::fromTitle) and
 *                            editable in board settings.
 *  - `kanso_cards.board_seq` nullable bigint - the per-board incrementing number.
 *                            A NAMED unique index (board_id, board_seq) makes the
 *                            per-board counter collision-proof: a concurrent
 *                            create that derives the same seq loses the insert and
 *                            {@see \OCA\Kanso\Service\CardService::create} retries.
 *
 * Backfill (postSchemaChange, once the columns exist): every board with no prefix
 * gets one derived from its title; every card with no board_seq is numbered per
 * board deterministically by ascending id (creation order), so existing cards get
 * stable ids and each board's next number continues from its current max. The
 * number is immutable once assigned - a re-run of the backfill only touches rows
 * still lacking a value (idempotent).
 *
 * All indexes/columns are explicitly named and guarded (hasColumn / hasIndex) so
 * the step is idempotent and fresh installs are unaffected.
 */
class Version002900Date20260817000000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_boards')) {
			$table = $schema->getTable('kanso_boards');
			if (!$table->hasColumn('prefix')) {
				$table->addColumn('prefix', Types::STRING, [
					'notnull' => false,
					'length' => BoardPrefix::MAX_LENGTH,
				]);
			}
		}

		if ($schema->hasTable('kanso_cards')) {
			$table = $schema->getTable('kanso_cards');
			if (!$table->hasColumn('board_seq')) {
				$table->addColumn('board_seq', Types::BIGINT, [
					'notnull' => false,
					'length' => 8,
					'unsigned' => true,
				]);
			}
			// Explicitly named + globally unique. Guards the per-board counter
			// against duplicate numbers under concurrent creates.
			if (!$table->hasIndex('kanso_cards_board_seq')) {
				$table->addUniqueIndex(['board_id', 'board_seq'], 'kanso_cards_board_seq');
			}
		}

		return $schema;
	}

	/**
	 * Deterministic backfill of prefixes and per-board sequence numbers. Runs
	 * only over rows that still lack a value, so it is safe to re-run.
	 *
	 * Not serialized against live card creation, but Nextcloud runs migrations
	 * with the instance in upgrade/maintenance mode (no concurrent create()),
	 * so a backfill vs. live-insert race cannot occur here.
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if (!$this->db->tableExists('kanso_boards') || !$this->db->tableExists('kanso_cards')) {
			return;
		}

		$this->backfillPrefixes();
		$this->backfillBoardSeqs($output);
	}

	/**
	 * Give every board still lacking a prefix one derived from its title.
	 */
	private function backfillPrefixes(): void {
		$select = $this->db->getQueryBuilder();
		$select->select('id', 'title')
			->from('kanso_boards')
			->where($select->expr()->isNull('prefix'));
		$result = $select->executeQuery();

		while (($row = $result->fetch()) !== false) {
			$prefix = BoardPrefix::fromTitle((string)($row['title'] ?? ''));

			$update = $this->db->getQueryBuilder();
			$update->update('kanso_boards')
				->set('prefix', $update->createNamedParameter($prefix))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'])))
				->andWhere($update->expr()->isNull('prefix'));
			$update->executeStatement();
		}
		$result->closeCursor();
	}

	/**
	 * Number every card that still lacks a board_seq, per board, in ascending-id
	 * (creation) order. Each board's counter continues from its current max so a
	 * partial prior run (or already-numbered cards) is respected.
	 */
	private function backfillBoardSeqs(IOutput $output): void {
		// Distinct board ids that have at least one unnumbered card.
		$boardsQb = $this->db->getQueryBuilder();
		$boardsQb->selectDistinct('board_id')
			->from('kanso_cards')
			->where($boardsQb->expr()->isNull('board_seq'));
		$boardsResult = $boardsQb->executeQuery();
		$boardIds = array_map(static fn ($r): int => (int)$r['board_id'], $boardsResult->fetchAll());
		$boardsResult->closeCursor();

		foreach ($boardIds as $boardId) {
			// Continue from the board's current max so already-assigned numbers
			// (from a prior partial run) are never reused.
			$maxQb = $this->db->getQueryBuilder();
			$maxQb->select($maxQb->func()->max('board_seq'))
				->from('kanso_cards')
				->where($maxQb->expr()->eq('board_id', $maxQb->createNamedParameter($boardId)));
			$maxResult = $maxQb->executeQuery();
			$next = ((int)$maxResult->fetchOne()) + 1;
			$maxResult->closeCursor();

			// All unnumbered cards on this board, oldest first (stable by id).
			$cardsQb = $this->db->getQueryBuilder();
			$cardsQb->select('id')
				->from('kanso_cards')
				->where($cardsQb->expr()->eq('board_id', $cardsQb->createNamedParameter($boardId)))
				->andWhere($cardsQb->expr()->isNull('board_seq'))
				->orderBy('id', 'ASC');
			$cardsResult = $cardsQb->executeQuery();
			$cardIds = array_map(static fn ($r): int => (int)$r['id'], $cardsResult->fetchAll());
			$cardsResult->closeCursor();

			foreach ($cardIds as $cardId) {
				$update = $this->db->getQueryBuilder();
				$update->update('kanso_cards')
					->set('board_seq', $update->createNamedParameter($next, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
					->where($update->expr()->eq('id', $update->createNamedParameter($cardId)))
					->andWhere($update->expr()->isNull('board_seq'));
				$update->executeStatement();
				$next++;
			}

			if ($cardIds !== []) {
				$output->info(sprintf('Kanso: numbered %d card(s) on board %d', count($cardIds), $boardId));
			}
		}
	}
}
