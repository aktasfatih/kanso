<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Shared service-exception → JSON error response mapping for the plain
 * API controllers. Every error body has the shape {"error": "..."}.
 */
trait ApiErrorTrait {
	/**
	 * @param callable(): JSONResponse $callback
	 */
	private function respond(callable $callback): JSONResponse {
		try {
			return $callback();
		} catch (InvalidInputException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (NotPermittedException) {
			return new JSONResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
	}
}
