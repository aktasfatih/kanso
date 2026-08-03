<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\AppInfo;

use OCA\Kanso\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use Psr\Container\ContainerInterface;

class Application extends App implements IBootstrap {
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
	}

	#[\Override]
	public function boot(IBootContext $context): void {
	}
}
