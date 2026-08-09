<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Kanso\Notification\Notifier;
use OCA\Kanso\Service\ActivityPublisher;
use OCA\Kanso\SetupCheck\GuestsWhitelistCheck;
use OCA\Kanso\SetupCheck\InstanceConfigCheck;
use OCP\Activity\IManager as IActivityManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\IURLGenerator;
use OCP\Util;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @implements IEventListener<LoadAdditionalScriptsEvent>
 */
class Application extends App implements IBootstrap, IEventListener {
	public const APP_ID = 'kanso';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		$context->registerNotifierService(Notifier::class);

		// Admin-Overview setup check (#3747): warns about instance config that
		// silently breaks shipped features (AJAX cron vs. due reminders, unset
		// overwrite.cli.url vs. email deep links). Report-only, never writes.
		$context->registerSetupCheck(InstanceConfigCheck::class);
		// Guests-app allow-list check (#3744): a guest shared onto a board can
		// only open Kanso when the Guests whitelist includes it. Silent when
		// the Guests app is absent or its whitelist is off.
		$context->registerSetupCheck(GuestsWhitelistCheck::class);

		// Kanso's own app-data folder, so services (card attachments, #3526) can
		// type-hint IAppData directly. Scoped to this app id - the bytes live in
		// Kanso's app-data, never in a user's personal Files.
		$context->registerService(IAppData::class, static function (ContainerInterface $c): IAppData {
			return $c->get(IAppDataFactory::class)->get(self::APP_ID);
		});

		// Activity-stream bridge (#3439): construct the publisher with a nullable
		// Activity IManager - the Activity app is optional, so feature-detect it
		// here and hand the publisher null when it is absent (it then no-ops). The
		// container cannot auto-wire an optional dependency, hence the factory.
		$context->registerService(ActivityPublisher::class, static function (ContainerInterface $c): ActivityPublisher {
			$manager = null;
			try {
				$manager = $c->get(IActivityManager::class);
			} catch (\Throwable) {
				// Activity app not installed - publisher runs as a no-op.
			}
			return new ActivityPublisher(
				$manager,
				$c->get(IURLGenerator::class),
				$c->get(LoggerInterface::class),
			);
		});

		// "Share from Files" (#3645): inject Kanso's Files-integration bundle into
		// the Files app so it can register the "Add to Kanso…" file action. The
		// listener is resolved lazily on dispatch, so this stays a no-op cost on
		// every other page.
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, self::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
	}

	/**
	 * Loads the tiny Files-integration entry (src/files.js) only when the Files
	 * app is rendering its scripts - it registers the "Add to Kanso…" file action.
	 */
	#[\Override]
	public function handle(\OCP\EventDispatcher\Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) {
			return;
		}
		Util::addScript(self::APP_ID, self::APP_ID . '-files');
	}
}
