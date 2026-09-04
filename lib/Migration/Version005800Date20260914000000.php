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
 * Resolved discussion threads (`kanso_comments.resolved_at`).
 *
 * A unix timestamp stamped when a top-level comment's thread is marked
 * resolved, back to 0 when it is reopened. 0 means "unresolved", so every
 * existing row is already in the right state and no backfill is needed.
 *
 * The flag lives on the comment - not in a per-user preference - because
 * "resolved" is a signal to the whole team, and it reaches other clients for
 * free through the card-targeted `kanso_changes` row the resolve already
 * writes. The collapsed rendering is derived from it client-side; nothing about
 * the collapse itself is stored.
 *
 * Only a top-level comment ever carries a non-zero value (the service rejects
 * resolving a reply) - the thread, not the individual message, is the unit.
 *
 * Guarded (hasTable/hasColumn) so the step is idempotent.
 */
class Version005800Date20260914000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_comments')) {
			$table = $schema->getTable('kanso_comments');
			if (!$table->hasColumn('resolved_at')) {
				$table->addColumn('resolved_at', Types::INTEGER, [
					'notnull' => true,
					'default' => 0,
				]);
			}
		}

		return $schema;
	}
}
