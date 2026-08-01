<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Pin the process default timezone so recurrence tests that fall back to the
// "server default timezone" are deterministic regardless of the host.
date_default_timezone_set('UTC');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/stubs/doctrine.php';
require_once __DIR__ . '/stubs/notifypush.php';
