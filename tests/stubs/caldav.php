<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Psalm stubs for the CalDAV integration surface Kanso builds on for the
// read-only VTODO calendar (#3534 / issue #49). None of these symbols ship via
// composer: `OCA\DAV\CalDAV\Integration\*` lives in the Nextcloud DAV app and
// `Sabre\DAV\*` / `Sabre\CalDAV\*` / `Sabre\DAVACL\*` live in the server's
// bundled 3rdparty (only `sabre/vobject` is a composer dependency). All are
// autoloaded at runtime - the DAV app is always enabled - so these declarations
// exist purely so psalm can resolve the symbols and type-check the adapters in
// `lib/CalDav`. Signatures mirror the real (largely untyped) Sabre interfaces so
// the adapter implementations stay compatible.

namespace Sabre\DAV {
	interface INode {
		public function delete();
		public function getName();
		public function setName($name);
		public function getLastModified();
	}

	interface ICollection extends INode {
		public function createFile($name, $data = null);
		public function createDirectory($name);
		public function getChild($name);
		public function getChildren();
		public function childExists($name);
	}

	interface IFile extends INode {
		public function put($data);
		public function get();
		public function getContentType();
		public function getETag();
		public function getSize();
	}

	interface IProperties extends INode {
		public function propPatch(PropPatch $propPatch);
		public function getProperties($properties);
	}

	class PropPatch {
	}

	class Exception extends \Exception {
	}
}

namespace Sabre\DAV\Exception {
	class Forbidden extends \Sabre\DAV\Exception {
	}

	class NotFound extends \Sabre\DAV\Exception {
	}

	class MethodNotAllowed extends \Sabre\DAV\Exception {
	}
}

namespace Sabre\DAVACL {
	interface IACL {
		public function getOwner();
		public function getGroup();
		public function getACL();
		public function setACL(array $acl);
		public function getSupportedPrivilegeSet();
	}
}

namespace Sabre\CalDAV {
	interface ICalendarObjectContainer extends \Sabre\DAV\ICollection {
		public function calendarQuery(array $filters);
	}

	interface ICalendar extends ICalendarObjectContainer, \Sabre\DAVACL\IACL {
	}

	interface ICalendarObject extends \Sabre\DAV\IFile {
	}

	class Plugin {
		public const NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';
	}

	class CalendarQueryValidator {
		public function validate(\Sabre\VObject\Component\VCalendar $vObject, array $filters): bool {
			return true;
		}
	}
}

namespace Sabre\CalDAV\Xml\Property {
	class SupportedCalendarComponentSet {
		/** @param string[] $components */
		public function __construct(array $components) {
		}
	}
}

namespace OCA\DAV\CalDAV\Integration {
	interface ICalendarProvider {
		public function getAppId(): string;
		public function fetchAllForCalendarHome(string $principalUri): array;
		public function hasCalendarInCalendarHome(string $principalUri, string $calendarUri): bool;
		public function getCalendarInCalendarHome(string $principalUri, string $calendarUri): ?ExternalCalendar;
	}

	abstract class ExternalCalendar implements \Sabre\CalDAV\ICalendar, \Sabre\DAV\IProperties {
		public function __construct(string $appId, string $calendarUri) {
		}

		public function getName() {
			return '';
		}

		public function setName($name) {
		}

		public function createDirectory($name) {
		}

		public static function isAppGeneratedCalendar(string $calendarUri): bool {
			return false;
		}

		/** @return string[] */
		public static function splitAppGeneratedCalendarUri(string $calendarUri): array {
			return [];
		}

		public static function doesViolateReservedName(string $calendarUri): bool {
			return false;
		}
	}
}
