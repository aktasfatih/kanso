<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\BackupService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin-only endpoints backing the Kanso backup settings panel (#3615): read /
 * write the app-wide backup config and trigger a backup on demand. None of the
 * actions carry #[NoAdminRequired], so Nextcloud gates them to admins - the
 * panel is Kanso's first admin surface.
 */
class BackupAdminController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private BackupService $backupService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The persisted backup config plus last-run result.
	 */
	public function index(): JSONResponse {
		return $this->respond(fn (): JSONResponse => new JSONResponse($this->backupService->getConfig()));
	}

	/**
	 * Persists the backup config (enabled flag, target path, retention count,
	 * and the account whose files back the target path).
	 */
	public function update(
		bool $enabled = false,
		string $path = '',
		int $retention = BackupService::DEFAULT_RETENTION,
		string $account = BackupService::DEFAULT_ACCOUNT,
	): JSONResponse {
		return $this->respond(function () use ($enabled, $path, $retention, $account): JSONResponse {
			$this->backupService->saveConfig($enabled, $path, $retention, $account);
			return new JSONResponse($this->backupService->getConfig());
		});
	}

	/**
	 * Runs a backup right now (respecting the enabled flag) and returns the
	 * run summary plus the refreshed config/last-run record.
	 */
	public function run(): JSONResponse {
		return $this->respond(function (): JSONResponse {
			$result = $this->backupService->run();
			return new JSONResponse([
				'result' => $result,
				'config' => $this->backupService->getConfig(),
			]);
		});
	}
}
