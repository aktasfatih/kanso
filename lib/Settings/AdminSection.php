<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Kanso's admin settings section (its first admin surface, #3615). Groups the
 * app's admin panels under one entry in the admin settings navigation.
 */
class AdminSection implements IIconSection {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $urlGenerator,
	) {
	}

	#[\Override]
	public function getID(): string {
		return 'kanso';
	}

	#[\Override]
	public function getName(): string {
		return $this->l->t('Kanso');
	}

	#[\Override]
	public function getPriority(): int {
		return 80;
	}

	/**
	 * The settings navigation renders this icon with
	 * `filter: var(--background-invert-if-dark)` — `no` on light themes,
	 * `invert(100%)` on dark ones. So the source asset has to be DARK: black
	 * stays black on light themes and is inverted to white on dark ones.
	 *
	 * `img/app.svg` is the app-menu icon and is deliberately white (issue #162
	 * — it rendered invisible here); every other app ships a separate dark
	 * variant for its section (theming, privacy, serverinfo, … all return
	 * `app-dark.svg`), which is what this returns.
	 */
	#[\Override]
	public function getIcon(): string {
		return $this->urlGenerator->imagePath('kanso', 'app-dark.svg');
	}
}
