<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\BackupService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;

/**
 * Admin-only endpoints backing the Kanso backup settings panel (#3615): read /
 * write the app-wide backup config, trigger a backup on demand, and list /
 * download the stored backups. None of the actions carry #[NoAdminRequired] and
 * none is a public page, so Nextcloud gates every one of them to admins - the
 * panel is Kanso's first admin surface.
 *
 * THE DOWNLOAD IS THE SENSITIVE ONE (#161). A backup archive is built at SYSTEM
 * scope: it holds every card on the instance, private ones included, and every
 * attachment. With the app-data destination this endpoint is also the ONLY way
 * to retrieve one, since the files are not in anyone's Files. So it must stay
 * exactly as gated as the settings panel itself - admin session, never a public
 * page, never link-shareable, no token that could be handed to anyone. The
 * filename is the only input and it is allow-listed by
 * {@see BackupService::isBackupName()} before it reaches storage, so it can
 * select nothing outside the backups folder. {@see \OCA\Kanso\Tests\Unit\Controller\BackupAdminControllerTest}
 * pins the gating so a future #[NoAdminRequired] cannot be added quietly.
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
	 * Persists the backup config (enabled flag, destination, target path,
	 * retention count, the account whose files back the target path, and when a
	 * finished run should notify the administrators).
	 */
	public function update(
		bool $enabled = false,
		string $path = '',
		int $retention = BackupService::DEFAULT_RETENTION,
		string $account = BackupService::DEFAULT_ACCOUNT,
		string $notify = BackupService::DEFAULT_NOTIFY,
		string $destination = BackupService::DEFAULT_DESTINATION,
	): JSONResponse {
		return $this->respond(function () use ($enabled, $path, $retention, $account, $notify, $destination): JSONResponse {
			$this->backupService->saveConfig($enabled, $path, $retention, $account, $notify, $destination);
			return new JSONResponse($this->backupService->getConfig());
		});
	}

	/**
	 * The backups currently stored in the configured destination, newest first.
	 * With the app-data destination this listing is the admin's only view of
	 * them - they are not in anyone's Files.
	 */
	public function files(): JSONResponse {
		return $this->respond(fn (): JSONResponse => new JSONResponse([
			'destination' => $this->backupService->getDestination(),
			'files' => $this->backupService->listBackups(),
		]));
	}

	/**
	 * Streams ONE stored backup to the administrator.
	 *
	 * #[NoCSRFRequired] so the panel can link to it directly (same as the card
	 * attachment download). That is not a relaxation of who may read it: the
	 * admin check and the session check both still run, and a cross-site forgery
	 * of a GET download hands the bytes to the victim's own browser, never to the
	 * forging site - the response body stays unreadable to it.
	 *
	 * An invalid name and a missing file are the same 404 on purpose.
	 *
	 * NO ETag is set, deliberately: an in-place same-second overwrite leaves the
	 * app-data ETag unchanged while the bytes change (measured), so revalidating
	 * against it could serve a stale archive. The response is marked no-store
	 * instead - a backup is not something to leave in a shared cache anyway.
	 */
	#[NoCSRFRequired]
	public function download(string $name = ''): Response {
		try {
			$backup = $this->backupService->openBackup($name);
		} catch (\Throwable $e) {
			return new JSONResponse(['message' => 'Backup not found'], Http::STATUS_NOT_FOUND);
		}

		$response = new StreamResponse($backup['stream']);
		// The name matched BackupService::isBackupName(), so it holds nothing that
		// could break out of the header (no quotes, no CR/LF, no separators).
		$response->addHeader(
			'Content-Type',
			str_ends_with($backup['name'], '.json') ? 'application/json' : 'application/zip',
		);
		$response->addHeader('Content-Disposition', 'attachment; filename="' . $backup['name'] . '"');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
		if ($backup['size'] > 0) {
			$response->addHeader('Content-Length', (string)$backup['size']);
		}
		return $response;
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
