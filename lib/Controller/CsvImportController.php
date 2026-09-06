<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\CsvImportService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * CSV import: append the rows of an uploaded/pasted CSV as cards into an
 * EXISTING board's chosen stack. The caller must have EDIT on that board (the
 * gate lives in {@see CsvImportService}). The raw document text is passed
 * straight through so the size cap + parsing in the service stay meaningful.
 */
class CsvImportController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private CsvImportService $importService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Imports CSV rows as cards into $boardId / $stackId.
	 *
	 * @param string $document raw CSV text (uploaded file or pasted)
	 * @param array<string, mixed> $mapping field name → 0-based source column index
	 * @param bool $hasHeader whether the first row is a header row to skip
	 *
	 * Per-user rate limited, matching the board-import endpoint next door
	 * ({@see \OCA\Kanso\Controller\BoardPortabilityController::import()}): one
	 * request may create up to {@see \OCA\Kanso\Service\CsvImportService::MAX_ROWS}
	 * cards, each costing an INSERT plus a change-log row inside a single
	 * transaction, so the per-request ceiling wants a companion bound over time.
	 * 60/hour sits far above any human import session - nobody re-imports a
	 * spreadsheet a minute apart for an hour - while stopping a scripted loop.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 3600)]
	public function import(
		string $document = '',
		int $boardId = 0,
		int $stackId = 0,
		array $mapping = [],
		bool $hasHeader = true,
	): JSONResponse {
		return $this->respond(function () use ($document, $boardId, $stackId, $mapping, $hasHeader): JSONResponse {
			return new JSONResponse(
				$this->importService->import(
					$document,
					$boardId,
					$stackId,
					$this->normaliseMapping($mapping),
					$hasHeader,
					$this->currentUserId(),
				)
			);
		});
	}

	/**
	 * Coerces the wire mapping into the service's shape: `title` is required and
	 * an integer, every other field is an optional integer column index (a
	 * missing / non-numeric value drops that field).
	 *
	 * @param array<string, mixed> $mapping
	 * @return array{title: int, description?: ?int, duedate?: ?int, labels?: ?int, assignees?: ?int}
	 * @throws InvalidInputException if no usable title column was supplied
	 */
	private function normaliseMapping(array $mapping): array {
		if (!isset($mapping['title']) || !is_numeric($mapping['title'])) {
			throw new InvalidInputException('A title column must be mapped');
		}
		$out = ['title' => (int)$mapping['title']];
		foreach (['description', 'duedate', 'labels', 'assignees'] as $field) {
			if (isset($mapping[$field]) && is_numeric($mapping[$field])) {
				$out[$field] = (int)$mapping[$field];
			}
		}
		return $out;
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
