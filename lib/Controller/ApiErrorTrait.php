<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\DescriptionConflictException;
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
		} catch (DescriptionConflictException $e) {
			// Optimistic-concurrency conflict on a card description: the write was
			// based on a stale version and would have clobbered somebody else's
			// text. Same 409 shape as `rebalance_required`, plus the current
			// server-side text so the client can show both versions instead of
			// throwing either one away.
			return new JSONResponse([
				'error' => 'description_conflict',
				'description' => $e->getCurrentDescription(),
				'lastModified' => $e->getCurrentLastModified(),
			], Http::STATUS_CONFLICT);
		} catch (\OverflowException) {
			// A derived fractional sort key would exceed the column width, or a
			// concurrent move kept colliding after a retry (CardService) - the
			// affected list needs a rebalance / the client should retry. Both
			// are transient conflicts, surfaced as 409.
			return new JSONResponse(['error' => 'rebalance_required'], Http::STATUS_CONFLICT);
		}
	}
}
