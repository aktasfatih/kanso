<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

class MutationConflictException extends \RuntimeException {
	public function __construct(\Throwable $previous) {
		parent::__construct('Another change is being saved. Please try again.', 0, $previous);
	}
}
