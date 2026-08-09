<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\SetupCheck;

use OCA\Kanso\AppInfo\Application;
use OCP\IConfig;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Admin-Overview setup check (#3744) for the Guests-app allow-list.
 *
 * External board members may be Guests-app accounts, and the Guests app
 * restricts which apps a guest may use via its app whitelist. When Kanso is
 * missing from that list, a guest clicking an emailed card deep link gets a
 * generic denial - a config problem that looks exactly like a Kanso bug at
 * first client contact. The `viewer` app is checked alongside: guests need it
 * to open card attachments inline.
 *
 * Deliberately silent (a plain success) when the Guests app is not installed
 * or its whitelist is disabled - there is nothing to configure then. Like
 * {@see InstanceConfigCheck}, this reports only and never writes config.
 */
class GuestsWhitelistCheck implements ISetupCheck {
	/** Attachment preview for guests rides the Viewer app. */
	private const VIEWER_APP_ID = 'viewer';

	public function __construct(
		private IConfig $config,
		private IL10N $l10n,
	) {
	}

	#[\Override]
	public function getCategory(): string {
		// Custom categories are merged into "system" in the Admin Overview.
		return 'kanso';
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Kanso & the Guests app');
	}

	#[\Override]
	public function run(): SetupResult {
		// Enabled-state via appconfig (the same channel InstanceConfigCheck
		// uses): 'no'/'' is disabled; 'yes' or a JSON group list is enabled.
		$guestsEnabled = $this->config->getAppValue('guests', 'enabled', 'no');
		if ($guestsEnabled === 'no' || $guestsEnabled === '') {
			return SetupResult::success(
				$this->l10n->t('The Guests app is not installed - nothing to check.')
			);
		}

		// The Guests app only restricts apps while its whitelist is in use.
		// The stored value is a Nextcloud appconfig BOOL: setAppValueBool
		// persists '1'/'0', older writes may hold 'true'/'false' - accept the
		// platform's whole truthy set (a bare `!== 'true'` would read the
		// stored '1' as "disabled" and silently never warn).
		$useWhitelist = in_array(
			strtolower($this->config->getAppValue('guests', 'usewhitelist', '1')),
			['1', 'true', 'yes', 'on'],
			true
		);
		if (!$useWhitelist) {
			return SetupResult::success(
				$this->l10n->t('The Guests app whitelist is disabled - guests can use Kanso.')
			);
		}

		$whitelist = array_filter(array_map(
			'trim',
			explode(',', $this->config->getAppValue('guests', 'whitelist', ''))
		));

		$missing = [];
		if (!in_array(Application::APP_ID, $whitelist, true)) {
			$missing[] = Application::APP_ID;
		}
		if (!in_array(self::VIEWER_APP_ID, $whitelist, true)) {
			$missing[] = self::VIEWER_APP_ID;
		}

		if ($missing !== []) {
			return SetupResult::warning(
				$this->l10n->t(
					'The Guests app is enabled but its app whitelist is missing: %1$s. Guest accounts shared onto a Kanso board cannot open it (card deep links from emails fail with a generic denial). Add the missing app(s) in Administration settings > Guests.',
					[implode(', ', $missing)]
				),
				'https://github.com/nextcloud/guests#whitelist'
			);
		}

		return SetupResult::success(
			$this->l10n->t('The Guests app whitelist includes Kanso and the Viewer app.')
		);
	}
}
