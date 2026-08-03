<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Minimal stand-in for OC\Hooks\Emitter, a private Nextcloud interface that
// OCP\Files\IRootFolder extends but that ships in the server, not in the ocp
// stubs. Tests only ever mock IRootFolder (which pulls this in), so the stub
// just needs to exist with the right shape for PHPUnit's mock generator.

namespace OC\Hooks;

if (!interface_exists(Emitter::class)) {
	interface Emitter {
		public function listen($scope, $method, callable $callback);

		public function removeListener($scope = null, $method = null, ?callable $callback = null);
	}
}
