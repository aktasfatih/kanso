<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

/**
 * THE due-date wire format, in one place: strict ISO 8601, normalized to UTC.
 * Shared by the card due date ({@see CardService}) and the checklist-step due
 * date ({@see ChecklistService}, #3745) so the two can never drift apart.
 */
final class DueDateParser {
	/**
	 * Strict ISO 8601 due dates, normalized to UTC. The empty string clears
	 * the due date (returns null). Two accepted shapes: RFC 3339 without
	 * fractional seconds (2026-07-22T12:00:00Z / +02:00) and with milliseconds
	 * (2026-07-22T12:00:00.000Z) - the latter is what JS Date.toISOString()
	 * produces.
	 *
	 * @throws InvalidInputException on any other shape
	 */
	public static function parse(string $duedate): ?\DateTime {
		if ($duedate === '') {
			return null;
		}
		$parsed = \DateTime::createFromFormat(\DateTimeInterface::ATOM, $duedate)
			?: \DateTime::createFromFormat('Y-m-d\TH:i:s.vP', $duedate);
		// createFromFormat rolls over out-of-range components (2026-02-30
		// becomes March 2nd) and only records it in getLastErrors - reject
		// those too, or clients get a silently wrong date back.
		$errors = \DateTime::getLastErrors();
		if ($parsed === false
			|| ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
			throw new InvalidInputException(
				'Due date must be an ISO 8601 datetime like 2026-07-22T12:00:00Z'
			);
		}
		$parsed->setTimezone(new \DateTimeZone('UTC'));
		return $parsed;
	}
}
