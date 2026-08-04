<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Minimal stand-in for the Files app's LoadAdditionalScriptsEvent, which is not
// installable via composer (it lives in the OCA\Files app, not the OCP public
// API package). Application only ever references it by class name to register a
// lazily-resolved event listener + type-narrow in handle(), so this stub exists
// purely so psalm can resolve the symbol. At runtime the real Files-app class is
// autoloaded (the Files app is always enabled).

namespace OCA\Files\Event;

use OCP\EventDispatcher\Event;

if (!class_exists(LoadAdditionalScriptsEvent::class)) {
	class LoadAdditionalScriptsEvent extends Event {
	}
}
