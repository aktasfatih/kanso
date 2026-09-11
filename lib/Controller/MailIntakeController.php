<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\MailIntakeService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Email-intake config for a board (#117). Every endpoint is MANAGE-gated in the
 * service; there is no public ingest counterpart because intake PULLS from IMAP
 * on cron rather than being pushed to.
 *
 * The mailbox password travels in on {@see save} and never travels back out -
 * {@see \OCA\Kanso\Db\MailIntake::jsonSerialize} reports only whether one is
 * stored.
 */
class MailIntakeController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private MailIntakeService $mailIntakeService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$config = $this->mailIntakeService->getConfig($id, $this->currentUserId());
			// A board with no mailbox is a normal state, not a 404 - the config UI
			// renders an empty form from it.
			return new JSONResponse($config?->jsonSerialize());
		});
	}

	/**
	 * Creates or replaces the config. `$password` null (the field left untouched
	 * in the UI) keeps the stored credential.
	 */
	#[NoAdminRequired]
	public function save(
		int $id,
		int $stackId,
		string $host,
		int $port = 993,
		string $encryption = 'ssl',
		string $username = '',
		?string $password = null,
		string $mailbox = 'INBOX',
		string $senderAllowlist = '',
		bool $enabled = false,
		bool $requireAuth = false,
		int $dailyLimit = 0,
		int $perSenderDailyLimit = 0,
	): JSONResponse {
		return $this->respond(function () use ($id, $stackId, $host, $port, $encryption, $username, $password, $mailbox, $senderAllowlist, $enabled, $requireAuth, $dailyLimit, $perSenderDailyLimit): JSONResponse {
			$config = $this->mailIntakeService->saveConfig(
				$id,
				$stackId,
				$host,
				$port,
				$encryption,
				$username,
				$password,
				$mailbox,
				$senderAllowlist,
				$enabled,
				$this->currentUserId(),
				$requireAuth,
				$dailyLimit,
				$perSenderDailyLimit,
			);
			return new JSONResponse($config->jsonSerialize());
		});
	}

	/**
	 * Connects and authenticates once, right now, so a wrong password is a
	 * message on screen rather than a five-minute wait for cron.
	 */
	#[NoAdminRequired]
	public function test(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse($this->mailIntakeService->testConnection($id, $this->currentUserId()));
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$this->mailIntakeService->deleteConfig($id, $this->currentUserId());
			return new JSONResponse([]);
		});
	}

	/**
	 * @throws NotPermittedException if there is no user session
	 */
	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new NotPermittedException('No authenticated user');
		}
		return $user->getUID();
	}
}
