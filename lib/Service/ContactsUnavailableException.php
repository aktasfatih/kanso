<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

/**
 * A contact mutation was attempted while the optional Contacts app is disabled
 * for the user. Extends {@see InvalidInputException} so the API controllers map
 * it to a clean HTTP 400 (never a 500) - the feature simply is not available.
 */
class ContactsUnavailableException extends InvalidInputException {
}
