<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Minimal Doctrine DBAL stubs for unit tests.
 *
 * The nextcloud/ocp dev package references Doctrine symbols (e.g. the
 * IQueryBuilder::PARAM_* constants and IDBConnection method defaults) that
 * only exist inside a real Nextcloud server. Reflecting those interfaces -
 * which PHPUnit's mock generator does for IDBConnection - evaluates the
 * constants and would fatal without these definitions. Shapes mirror
 * doctrine/dbal 4 as bundled with Nextcloud 32.
 */

namespace Doctrine\DBAL {
	if (!enum_exists(ParameterType::class)) {
		enum ParameterType {
			case NULL;
			case INTEGER;
			case STRING;
			case LARGE_OBJECT;
			case BOOLEAN;
			case BINARY;
			case ASCII;
		}
	}

	if (!enum_exists(ArrayParameterType::class)) {
		enum ArrayParameterType {
			case INTEGER;
			case STRING;
			case ASCII;
			case BINARY;
		}
	}
}

namespace Doctrine\DBAL\Types {
	if (!class_exists(Types::class)) {
		class Types {
			public const ASCII_STRING = 'ascii_string';
			public const BIGINT = 'bigint';
			public const BINARY = 'binary';
			public const BLOB = 'blob';
			public const BOOLEAN = 'boolean';
			public const DATE_MUTABLE = 'date';
			public const DATE_IMMUTABLE = 'date_immutable';
			public const DATEINTERVAL = 'dateinterval';
			public const DATETIME_MUTABLE = 'datetime';
			public const DATETIME_IMMUTABLE = 'datetime_immutable';
			public const DATETIMETZ_MUTABLE = 'datetimetz';
			public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable';
			public const DECIMAL = 'decimal';
			public const FLOAT = 'float';
			public const GUID = 'guid';
			public const INTEGER = 'integer';
			public const JSON = 'json';
			public const SIMPLE_ARRAY = 'simple_array';
			public const SMALLFLOAT = 'smallfloat';
			public const SMALLINT = 'smallint';
			public const STRING = 'string';
			public const TEXT = 'text';
			public const TIME_MUTABLE = 'time';
			public const TIME_IMMUTABLE = 'time_immutable';
		}
	}
}
