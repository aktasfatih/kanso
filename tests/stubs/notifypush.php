<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Minimal stand-in for the notify_push app's queue interface, which is not
// installable via composer. ChangeNotifier only ever references it by its
// string class name and duck-types the push() method, so this stub exists
// purely to give tests something concrete to mock.

namespace OCA\NotifyPush\Queue;

if (!interface_exists(IQueue::class)) {
	interface IQueue {
		/**
		 * @param mixed $event
		 */
		public function push(string $channel, $event);
	}
}
