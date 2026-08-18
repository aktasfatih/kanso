<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

/**
 * Shared, multibyte-safe title hardening for the import paths. Kanso's
 * `title` columns are STRING length 100, so a source (Deck) title longer than
 * that would overflow the varchar and abort the whole import. Every import
 * that writes a title MUST run it through here so an over-long or empty source
 * title never fails - or silently drops - a card/stack/label.
 *
 * This mirrors the {@see AttachmentSanitizer} static-helper pattern so the
 * length-safety logic lives in one place rather than being re-derived per
 * caller. It intentionally does NOT reject: it coerces, because an import must
 * finish for ALL inputs, not raise on the awkward ones.
 */
final class TitleSanitizer {
	/**
	 * Truncates a title to at most $max characters WITHOUT ever splitting a
	 * multibyte (UTF-8) character, and substitutes $placeholder for an
	 * empty/whitespace-only source (Kanso requires a non-empty title).
	 *
	 * `mb_substr`/`mb_strlen` operate on whole characters, so an emoji or other
	 * multi-byte grapheme is either kept whole or dropped whole - never cut
	 * mid-sequence into mojibake.
	 */
	public static function truncate(string $title, int $max, string $placeholder = 'Untitled'): string {
		$title = trim($title);
		if ($title === '') {
			return $placeholder;
		}
		if (mb_strlen($title) > $max) {
			$title = rtrim(mb_substr($title, 0, $max));
			// rtrim can empty a title that was all-truncatable whitespace; guard.
			if ($title === '') {
				return $placeholder;
			}
		}
		return $title;
	}

	/**
	 * Whether $title exceeds $max characters (multibyte-aware) once trimmed -
	 * i.e. whether {@see truncate} would shorten it. Used to decide when the
	 * full original title is worth preserving into a card description.
	 */
	public static function isOverLength(string $title, int $max): bool {
		return mb_strlen(trim($title)) > $max;
	}
}
