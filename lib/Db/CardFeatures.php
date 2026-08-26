<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCA\Kanso\Service\InvalidInputException;

/**
 * The fixed set of BUILT-IN card sections a board manager may switch off
 * (`kanso_boards.disabled_card_features`, #5894). Every feature is ON by
 * default; a board that never links a contact or attaches a file shouldn't have
 * to look at those controls.
 *
 * ## Hiding is not deleting
 *
 * Switching a feature off is a pure PRESENTATION flag: it hides the feature's
 * UI and nothing else. Existing attachments, contact links, GitHub links, time
 * entries and cover colours stay in the database untouched and come back
 * exactly as they were when the feature is re-enabled. There is deliberately no
 * cascade delete anywhere, a running timer is NOT stopped when time tracking is
 * switched off, and historical activity entries ("linked a contact") stay
 * readable.
 *
 * ## Enforcement: CLIENT-SIDE ONLY (deliberate)
 *
 * These flags gate the UI. The API is NOT locked down: uploading an attachment
 * or starting a timer on a board whose corresponding switch is off still
 * succeeds. That is an explicit MVP decision, and it is the honest one given
 * the "hiding is not deleting" guarantee - a board that re-enables a feature
 * must still be able to read and write the data it accumulated, and existing
 * integrations (webhook intake, imports, the MCP server, CalDAV) must not start
 * failing because a manager tidied up a card modal. Do NOT assume the endpoints
 * reject these operations; if server-side rejection is ever wanted it needs its
 * own design pass (what happens to in-flight timers, imports, webhooks).
 *
 * Concretely: with attachments hidden, pasting an image into a description still
 * uploads through the attachment endpoint and creates an attachment row - the
 * description needs its image, and the row simply has no visible list until the
 * section is switched back on.
 *
 * ## Storage
 *
 * Only the DISABLED keys are persisted, as a small JSON array
 * (`["attachments","github"]`). NULL / an empty array = everything enabled, so
 * existing boards need no backfill and a sixth feature costs no migration.
 * Unknown keys read back from storage are ignored, so a downgraded instance
 * still renders - but they are NOT preserved: {@see self::encode()} writes only
 * the keys it knows, so the first flip of any switch on a downgraded instance
 * drops a newer version's disabled key.
 */
final class CardFeatures {
	public const CONTACTS = 'contacts';
	public const ATTACHMENTS = 'attachments';
	public const GITHUB = 'github';
	public const TIME_TRACKING = 'timeTracking';
	public const COVER_COLOR = 'coverColor';

	/**
	 * Every toggleable key, in the order the board settings UI lists them.
	 *
	 * @var string[]
	 */
	public const ALL = [
		self::CONTACTS,
		self::ATTACHMENTS,
		self::GITHUB,
		self::TIME_TRACKING,
		self::COVER_COLOR,
	];

	/**
	 * Decodes the stored column into the NORMALISED map the client consumes:
	 * every known key present, `true` = enabled. NULL, an empty string, invalid
	 * JSON and non-array JSON all mean "nothing disabled" - a corrupt value must
	 * never hide a feature the manager never switched off.
	 *
	 * @return array<string, bool>
	 */
	public static function decode(?string $stored): array {
		$disabled = [];
		if ($stored !== null && $stored !== '') {
			$decoded = json_decode($stored, true);
			if (is_array($decoded)) {
				foreach ($decoded as $key) {
					if (is_string($key) && in_array($key, self::ALL, true)) {
						$disabled[] = $key;
					}
				}
			}
		}

		$map = [];
		foreach (self::ALL as $key) {
			$map[$key] = !in_array($key, $disabled, true);
		}
		return $map;
	}

	/**
	 * Encodes a normalised enabled-map back to the stored column value. Returns
	 * NULL when nothing is disabled, so the all-enabled default stays a NULL row
	 * rather than a redundant `[]`.
	 *
	 * @param array<string, bool> $map
	 */
	public static function encode(array $map): ?string {
		$disabled = [];
		foreach (self::ALL as $key) {
			if (($map[$key] ?? true) === false) {
				$disabled[] = $key;
			}
		}
		if ($disabled === []) {
			return null;
		}
		return json_encode($disabled, JSON_THROW_ON_ERROR);
	}

	/**
	 * Merges a caller-supplied PARTIAL patch onto the board's current state.
	 * Keys the caller omits keep their current value, so the settings UI can
	 * flip one switch without racing the other four.
	 *
	 * @param array<string, bool> $current a normalised enabled-map (from {@see self::decode()})
	 * @param array<array-key, mixed> $patch raw request input
	 * @return array<string, bool> the new normalised enabled-map
	 * @throws InvalidInputException on an unknown key or a non-boolean value
	 */
	public static function applyPatch(array $current, array $patch): array {
		foreach ($patch as $key => $value) {
			if (!is_string($key) || !in_array($key, self::ALL, true)) {
				throw new InvalidInputException('Unknown card feature');
			}
			if (!is_bool($value)) {
				throw new InvalidInputException('Card feature value must be a boolean');
			}
			$current[$key] = $value;
		}
		return $current;
	}
}
