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
 * Card review requests (`kanso_card_reviews`): a FLAT approval model — one row
 * per (card, reviewer). A reviewer is asked to sign off; their `state` moves
 * pending → approved | changes_requested. Deliberately flat: NO review round /
 * stage columns (multi-stage review chains are an explicit non-goal). The
 * optional done-gate reads these rows to block a REVIEW-role → DONE-role move
 * until every requested review is approved.
 *
 * Review mutations append a card-targeted row to `kanso_changes` (via
 * {@see \OCA\Kanso\Service\ReviewService}) so the tile chip updates over the
 * existing realtime/ETag path, and the board payload carries a per-card
 * aggregate review state.
 *
 * Guarded by hasTable so the step is idempotent.
 */
class Version001100Date20260730000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
	 *  stubs (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_card_reviews')) {
			return null;
		}

		$table = $schema->createTable('kanso_card_reviews');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('card_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('reviewer', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('state', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('requested_by', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
			'default' => 0,
		]);
		$table->setPrimaryKey(['id']);
		// One active review request per reviewer per card (mirrors the assignee
		// uniqueness). The board-wide aggregate joins through kanso_cards on
		// card_id, so this unique index serves the card lookup too.
		$table->addUniqueIndex(['card_id', 'reviewer'], 'kanso_reviews_card_reviewer');

		return $schema;
	}
}
