<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Kanso\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Util;
use Psr\Container\ContainerInterface;

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

		// Kanso's own app-data folder, so services (card attachments, #3526) can
		// type-hint IAppData directly. Scoped to this app id - the bytes live in
		// Kanso's app-data, never in a user's personal Files.
		$context->registerService(IAppData::class, static function (ContainerInterface $c): IAppData {
			return $c->get(IAppDataFactory::class)->get(self::APP_ID);
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
