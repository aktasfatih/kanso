<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

/**
 * The per-board prefix half of a card's human-readable identifier
 * (`PREFIX-<board_seq>`, e.g. "KAN-123"). A board carries a short prefix
 * derived from its title; each card carries a board-scoped incrementing number.
 *
 * The prefix is a DISPLAY/reference attribute only - it never affects ordering
 * (that stays the fractional sort key) and a card's assigned number is immutable
 * once created. This helper is the single source of truth for deriving and
 * normalizing a prefix, shared by board creation, board settings and the
 * backfill migration so all three agree.
 */
final class BoardPrefix {
	/** Fallback when a title yields no usable alpha characters. */
	public const DEFAULT = 'KAN';

	/** Max prefix length - a human id stays short and scannable. */
	public const MAX_LENGTH = 5;

	/**
	 * Derives a stable default prefix from a board title: the first
	 * {@see self::MAX_LENGTH} uppercased ASCII letters of the title (letters
	 * only - digits, spaces and punctuation are skipped), falling back to
	 * {@see self::DEFAULT} when the title carries no letters at all.
	 *
	 * Deterministic, so the migration backfill and a fresh board create derive
	 * the same value for the same title.
	 */
	public static function fromTitle(string $title): string {
		// Keep ASCII letters only, drop everything else, uppercase, then take a
		// short lead. mb_strtoupper first so accented input degrades predictably.
		$letters = preg_replace('/[^A-Za-z]/', '', mb_strtoupper($title, 'UTF-8')) ?? '';
		if ($letters === '') {
			return self::DEFAULT;
		}
		return substr($letters, 0, self::MAX_LENGTH);
	}

	/**
	 * Normalizes a user-supplied prefix to the stored shape: trimmed, uppercased,
	 * letters/digits only, capped at {@see self::MAX_LENGTH}. Returns null when
	 * the input reduces to nothing (the caller then keeps the current prefix or
	 * derives one).
	 */
	public static function normalize(string $prefix): ?string {
		$clean = preg_replace('/[^A-Za-z0-9]/', '', mb_strtoupper($prefix, 'UTF-8')) ?? '';
		if ($clean === '') {
			return null;
		}
		return substr($clean, 0, self::MAX_LENGTH);
	}
}
