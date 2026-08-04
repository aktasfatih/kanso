<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

/**
 * Shared hardening for card-attachment bytes, whatever their source (a direct
 * upload, a "Share from Files" copy, or a Deck import copy). Every path that
 * stores an attachment MUST run its client/source-supplied filename and MIME
 * through here and cap its size, so an untrusted `.html`/`.svg` can never become
 * stored XSS and an oversized source can never OOM the import.
 *
 * The logic lived on {@see CardAttachmentService}; it is promoted here so the
 * copy paths reuse the exact same coercion rather than diverging.
 */
final class AttachmentSanitizer {
	/** Hard cap on a single stored attachment. Oversized sources are rejected. */
	public const MAX_SIZE = 100 * 1024 * 1024; // 100 MiB

	/**
	 * MIME types that could be rendered/scripted inline by a browser if the
	 * download header were ever weakened. We never serve these as their own
	 * Content-Type - they are stored (and downloaded) as a generic binary so a
	 * stored `.html`/`.svg` can never become stored XSS. Defence in depth: the
	 * download is ALSO forced Content-Disposition: attachment + nosniff.
	 */
	private const UNSAFE_MIME_PREFIXES = [
		'text/html',
		'application/xhtml',
		'image/svg',
		'application/xml',
		'text/xml',
		'application/javascript',
		'text/javascript',
	];

	/**
	 * Normalizes a source filename into a safe display label: strips any path
	 * component (defence in depth - it is never a path, but keep the label
	 * clean), collapses control chars, caps length, and falls back to a generic
	 * name when empty.
	 */
	public static function filename(string $name): string {
		$name = basename(str_replace('\\', '/', $name));
		$name = preg_replace('/[\x00-\x1f\x7f]/', '', $name) ?? '';
		$name = trim($name);
		if (strlen($name) > 255) {
			$name = substr($name, 0, 255);
		}
		return $name === '' ? 'attachment' : $name;
	}

	/**
	 * Keeps only a plausible `type/subtype` MIME, coercing anything a browser
	 * might render/script inline to a generic binary. The value is
	 * source-supplied and is never trusted for rendering.
	 */
	public static function mime(string $mime): string {
		$mime = strtolower(trim($mime));
		if (preg_match('~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~', $mime) !== 1
			|| strlen($mime) > 255) {
			return 'application/octet-stream';
		}
		foreach (self::UNSAFE_MIME_PREFIXES as $prefix) {
			if (str_starts_with($mime, $prefix)) {
				return 'application/octet-stream';
			}
		}
		return $mime;
	}
}
