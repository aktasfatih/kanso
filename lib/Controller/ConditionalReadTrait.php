<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

/**
 * Shared `If-None-Match` handling for the app's conditional reads (performance
 * bet #4). Both the board payload and the public ICS feed derive their ETag from
 * the board's latest `kanso_changes` id and answer a matching validator with a
 * 304 BEFORE touching the expensive rows, so the comparison lives here once
 * rather than drifting between them.
 */
trait ConditionalReadTrait {
	/**
	 * Compares the normalized If-None-Match request header (surrounding
	 * quotes and weak-validator prefix stripped) against the current ETag.
	 */
	private function matchesIfNoneMatch(string $etag): bool {
		$header = trim($this->request->getHeader('If-None-Match'));
		if ($header === '') {
			return false;
		}
		if (str_starts_with($header, 'W/')) {
			$header = substr($header, 2);
		}
		return trim($header, '"') === $etag;
	}
}
