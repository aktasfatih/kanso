<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Settings;

use OCA\Kanso\Service\BackupService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/**
 * Kanso admin panel for scheduled S3/external-storage board backups (#3615).
 * Renders the enabled/path/account/retention form plus the last-run status,
 * seeding the template with the persisted config from {@see BackupService}. The
 * panel is admin-gated by Nextcloud (registered as an `admin` setting in
 * info.xml); all writes go through {@see \OCA\Kanso\Controller\BackupAdminController}.
 */
class BackupAdmin implements ISettings {
	public function __construct(
		private BackupService $backupService,
	) {
	}

	#[\Override]
	public function getForm(): TemplateResponse {
		return new TemplateResponse('kanso', 'admin-backup', [
			'config' => $this->backupService->getConfig(),
		], TemplateResponse::RENDER_AS_BLANK);
	}

	#[\Override]
	public function getSection(): string {
		return 'kanso';
	}

	#[\Override]
	public function getPriority(): int {
		return 50;
	}
}
