<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

/**
 * Marker: a correctly-signed GitHub delivery whose body is not JSON at all -
 * in practice the webhook was created with the `application/x-www-form-urlencoded`
 * content type.
 *
 * It exists so {@see \OCA\Kanso\Controller\WebhookController::github()} can
 * answer with the "set the Content type to application/json" hint for THIS case
 * only. {@see GithubWebhookService::handleWebhook()} also runs card creates and
 * moves, which throw the plain {@see InvalidInputException}; catching that in the
 * controller told a user whose content type was already correct to go and change
 * it - the one line they see in GitHub's delivery log.
 *
 * Extends InvalidInputException so any caller that only knows the general type
 * (and the API error mapping) still treats it as bad input.
 */
class NonJsonWebhookBodyException extends InvalidInputException {
}
