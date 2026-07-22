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
		} catch (\OverflowException) {
			// SortKeyService: a derived fractional sort key would exceed the
			// column width — the affected list needs a rebalance before the
			// operation can succeed. Clients should surface a retry.
			return new JSONResponse(['error' => 'rebalance_required'], Http::STATUS_CONFLICT);
		} catch (\InvalidArgumentException) {
			// Defensive: SortKeyService rejects malformed/misordered keys with
			// InvalidArgumentException. That must never surface as a 500 — it
			// means the request was built against stale or inconsistent state.
			return new JSONResponse(['error' => 'Invalid input'], Http::STATUS_BAD_REQUEST);
		}
	}
}
