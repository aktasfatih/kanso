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
 * Review-type stage gating (#3588): review types gain an ordering `stage` and
 * card reviews gain a `notified_at` stamp so downstream reviews can defer their
 * reviewer notification until the blocking lower-stage reviews approve.
 *
 * - `kanso_review_types.stage` (INTEGER, default 0, notnull): the review type's
 *   order in the review chain. Lower stages gate higher ones; a review is
 *   "gated" while the card carries any OTHER unapproved review of a lower stage.
 *   Untyped reviews (review_type_id = 0) are implicitly stage 0. Gating is
 *   DERIVED at read time from these stages - it is never stored on the review.
 * - `kanso_card_reviews.notified_at` (BIGINT, nullable): the unix time the
 *   reviewer was notified, or null when the request is still deferred (gated).
 *   Stamped once when the review un-gates so a re-approval never re-notifies.
 *
 * Both changes are guarded by hasColumn so the step is idempotent.
 */
class Version004300Date20260831000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
	 *  stubs (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_review_types')) {
			$types = $schema->getTable('kanso_review_types');
			if (!$types->hasColumn('stage')) {
				$types->addColumn('stage', Types::INTEGER, [
					'notnull' => true,
					'default' => 0,
				]);
			}
		}

		if ($schema->hasTable('kanso_card_reviews')) {
			$reviews = $schema->getTable('kanso_card_reviews');
			if (!$reviews->hasColumn('notified_at')) {
				$reviews->addColumn('notified_at', Types::BIGINT, [
					'notnull' => false,
					'length' => 8,
				]);
			}
		}

		return $schema;
	}
}
