<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\CalDav;

use Sabre\CalDAV\ICalendarObject;
use Sabre\DAV\Exception\Forbidden;

/**
 * One card, presented as a read-only VTODO calendar object (#3534 / issue #49).
 * A pure value object: the VCALENDAR body, ETag, name and mtime are computed up
 * front by {@see \OCA\Kanso\Service\CardCalendarService}; every write is rejected.
 */
class CardCalendarObject implements ICalendarObject {
	public function __construct(
		private string $name,
		private string $calendarData,
		private string $etag,
		private int $lastModified,
	) {
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function getName() {
		return $this->name;
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function get() {
		return $this->calendarData;
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function getContentType() {
		return 'text/calendar; charset=utf-8';
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function getETag() {
		return $this->etag;
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function getSize() {
		return \strlen($this->calendarData);
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function getLastModified() {
		return $this->lastModified;
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function put($data) {
		throw new Forbidden('This calendar is read-only');
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function setName($name) {
		throw new Forbidden('This calendar is read-only');
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function delete() {
		throw new Forbidden('This calendar is read-only');
	}
}
