<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Activity;

use OCP\Activity\ISetting;
use OCP\L10N\IFactory;

/**
 * The single Activity-app setting that lists Kanso in the user's Activity
 * preferences, so each user can opt this app's stream/mail in or out. Registered
 * via the app's info.xml `<activity><settings>` block. Its identifier matches the
 * event type produced by {@see Provider::TYPE_KANSO} so the toggle governs every
 * Kanso event.
 */
class Setting implements ISetting {
	public function __construct(
		private IFactory $l10nFactory,
	) {
	}

	#[\Override]
	public function getIdentifier(): string {
		return Provider::TYPE_KANSO;
	}

	#[\Override]
	public function getName(): string {
		return $this->l10nFactory->get('kanso')->t('A card was created, moved or completed, or a board was shared with you');
	}

	#[\Override]
	public function getPriority(): int {
		return 51;
	}

	#[\Override]
	public function canChangeStream(): bool {
		return true;
	}

	#[\Override]
	public function isDefaultEnabledStream(): bool {
		return true;
	}

	#[\Override]
	public function canChangeMail(): bool {
		return true;
	}

	#[\Override]
	public function isDefaultEnabledMail(): bool {
		return false;
	}
}
