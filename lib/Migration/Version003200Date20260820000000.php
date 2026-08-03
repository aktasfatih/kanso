<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Card file attachments (`kanso_card_attachments`, #3526). A row is metadata
 * ONLY; the bytes live in Kanso's own app-data (IAppData), keyed by
 * `storage_key` - a SERVER-GENERATED name, never the client filename, so the
 * original filename can never steer the storage path (no traversal).
 *
 * `board_id` is denormalized from the card at insert time (set server-side)
 * purely so board-permission gating and per-board queries don't need a card
 * join on every read; `card_id` remains the scoping key (an attachment always
 * belongs to exactly one card, and its board is the card's board).
 *
 * Index/constraint names are globally unique (kanso_cattach_*) so this step is
 * safe on a fresh install. Guarded by hasTable so the step is idempotent.
 */
class Version003200Date20260820000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
	 *  stubs (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_card_attachments')) {
			return null;
		}

		$table = $schema->createTable('kanso_card_attachments');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('card_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		// Denormalized from the card at insert time (set server-side) for gating
		// and per-board queries without a card join.
		$table->addColumn('board_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		// The ORIGINAL client filename, kept only as a display label (never used
		// as a filesystem path).
		$table->addColumn('filename', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		$table->addColumn('mime', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		$table->addColumn('size', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
			'default' => 0,
		]);
		// The server-generated app-data object name (opaque, random). This is the
		// ONLY thing that addresses the bytes on disk.
		$table->addColumn('storage_key', Types::STRING, [
			'notnull' => true,
			'length' => 128,
		]);
		$table->addColumn('uploaded_by', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
			'default' => 0,
		]);
		$table->setPrimaryKey(['id'], 'kanso_cattach_pk');
		// A card's attachments (list + count) - the hot path.
		$table->addIndex(['card_id'], 'kanso_cattach_card');
		// Board-scoped queries / cascade on board delete.
		$table->addIndex(['board_id'], 'kanso_cattach_board');

		return $schema;
	}
}
