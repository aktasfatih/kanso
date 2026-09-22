<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Migration;

use Closure;
use OCA\Kanso\Service\BackupService;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Keep an instance that was already backing up into a Files folder on that
 * folder (#161).
 *
 * Scheduled backups gained a DESTINATION setting - Kanso's app data (the new
 * default, silent and quota-free) or a Nextcloud folder (browsable and
 * off-site-capable). Changing where an existing instance's backups are written
 * as a side effect of an update is not acceptable: the admin would keep an
 * unexplained pile of files in Files while new ones appeared somewhere they
 * cannot see, and their retention window would silently restart.
 *
 * So "existing install" is detected explicitly, and narrowly: no destination has
 * ever been chosen ({@see BackupService::KEY_DESTINATION} absent) AND a backup
 * target path is configured ({@see BackupService::KEY_PATH} non-empty). Only a
 * release older than this setting can leave that combination behind, and on such
 * an instance the backups are in that folder. It is stamped to
 * {@see BackupService::DEST_FILES} here, once.
 *
 * Everything else is left alone and takes the new default:
 *  - a FRESH install has no app values at all (the backup keys are only written
 *    when an admin saves the panel), so nothing matches and the default applies;
 *  - an existing install that never configured a target path was never writing
 *    backups anywhere, so it has nothing to preserve.
 *
 * Schema-less by design, and idempotent: a second run finds the key set and
 * does nothing. {@see BackupService::getDestination()} applies the identical
 * rule at runtime, so an instance is on the right destination even before this
 * step runs.
 */
class Version006300Date20260921000000 extends SimpleMigrationStep {
	public function __construct(
		private IConfig $config,
	) {
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$chosen = trim($this->config->getAppValue(BackupService::APP_ID, BackupService::KEY_DESTINATION, ''));
		if ($chosen !== '') {
			// An administrator already has a destination - never overrule it.
			return;
		}

		$path = trim($this->config->getAppValue(BackupService::APP_ID, BackupService::KEY_PATH, ''));
		if ($path === '') {
			// Fresh install, or backups were never configured: the new default
			// (app data) applies and there is nothing to preserve.
			return;
		}

		$this->config->setAppValue(
			BackupService::APP_ID,
			BackupService::KEY_DESTINATION,
			BackupService::DEST_FILES,
		);
		$output->info('Kanso: keeping scheduled backups in the configured Files folder (' . $path . ')');
	}
}
