<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service\Forge;

use OCA\Kanso\Db\CardLink;

/**
 * A forge delivery (GitHub, Forgejo, ...) normalized into the only shape the
 * shared automation cares about. Everything provider-specific - header names,
 * payload spellings, URL layout - is resolved by the per-provider normalizer
 * BEFORE this object exists, so {@see \OCA\Kanso\Service\AbstractForgeWebhookService}
 * never branches on which forge sent the delivery.
 *
 * `state` is already mapped onto the CardLink enum and `merged` already folds
 * the several spellings a forge may use, so the automation reads them straight.
 *
 * `urlCandidates` is the set of URL spellings under which this event's issue/PR
 * may have been attached as a link - the reverse-lookup key for the issue path.
 * Providers differ here: github.com has four host/slash spellings, while a
 * Forgejo instance additionally needs both `/issues/{n}` and `/pulls/{n}`
 * because the two share one number sequence and redirect to each other.
 */
final class ForgeEvent {
	/** Not a delivery the automation acts on (a ping, a malformed body, ...). */
	public const KIND_NONE = 'none';
	public const KIND_PR = 'pr';
	public const KIND_ISSUE = 'issue';

	/**
	 * @param string[] $labels lowercase-comparable label names carried by the payload
	 * @param string[] $urlCandidates URL spellings this issue/PR may be attached under
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $action = '',
		public readonly string $branch = '',
		public readonly string $htmlUrl = '',
		public readonly ?string $title = null,
		public readonly string $state = CardLink::STATE_UNKNOWN,
		public readonly bool $merged = false,
		public readonly array $labels = [],
		public readonly array $urlCandidates = [],
	) {
	}

	/** A delivery the automation does not act on. */
	public static function none(): self {
		return new self(self::KIND_NONE);
	}

	/** Whether the payload lists a label of this (case-insensitive) name. */
	public function hasLabel(string $name): bool {
		foreach ($this->labels as $label) {
			if (mb_strtolower($label) === mb_strtolower($name)) {
				return true;
			}
		}
		return false;
	}
}
