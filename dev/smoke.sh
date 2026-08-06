#!/bin/sh
# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Post-install smoke assertion for the cross-version / cross-DB CI matrix.
# Verifies that enabling Kanso on this (NC_VERSION, KANSO_DB) combo actually
# ran the migrations and CREATED THE TABLES — the exact failure mode we hit once
# (a default-named PRIMARY KEY blew the 23-char index-name limit and silently
# created ZERO tables on a fresh install).
#
# Run against the stack booted by dev/setup.sh. Exits non-zero on any failure.
set -eu
cd "$(dirname "$0")"

KANSO_DB="${KANSO_DB:-postgres}"
CONTAINER=kanso-dev
OCC="docker exec -u www-data $CONTAINER php occ"

fail() { echo "SMOKE FAIL: $*" >&2; exit 1; }

# 1. App must be enabled (occ app:enable would have failed the boot otherwise,
#    but assert it explicitly so a partially-installed app is caught).
$OCC app:list --enabled 2>/dev/null | grep -q 'kanso' \
	|| $OCC app:list 2>/dev/null | grep -q 'kanso' \
	|| fail "kanso is not in 'occ app:list' (enabled apps)"

# 2. Every migration must be recorded as applied. NC has no per-app
#    'migrations:status' occ command across 30–34, so verify directly: the
#    oc_migrations table must hold one row per migration file. This proves the
#    migrator ran end-to-end (not just that app:enable returned 0).
# cwd is dev/ (the script cd'd here), so migrations are one level up.
MIGRATION_FILES="$(ls ../lib/Migration/Version*.php | wc -l | tr -d ' ')"

# 3. The core tables must physically exist. This is the load-bearing assertion:
#    if migrations silently no-op'd, these are absent. Count kanso_* tables
#    directly in the DB (driver-specific query), and require the core four.
CORE="kanso_boards kanso_stacks kanso_cards kanso_changes"
MIN_TABLES=20   # 28 tables today; a generous floor that still catches "zero".

count_migrations_postgres() {
	docker exec kanso-dev-db psql -U nextcloud -d nextcloud -tAc \
		"SELECT count(*) FROM oc_migrations WHERE app='kanso';"
}
count_migrations_mysql() {
	docker exec kanso-dev-db mariadb -unextcloud -pnextcloud -N -B nextcloud -e \
		"SELECT count(*) FROM oc_migrations WHERE app='kanso';"
}
count_migrations_sqlite() {
	docker exec -u www-data kanso-dev php -r '
		$db = new PDO("sqlite:/var/www/html/data/nextcloud.db");
		echo (int)$db->query("SELECT count(*) FROM oc_migrations WHERE app=\"kanso\"")->fetchColumn();
	'
}

count_tables_postgres() {
	docker exec kanso-dev-db psql -U nextcloud -d nextcloud -tAc \
		"SELECT count(*) FROM information_schema.tables WHERE table_name LIKE 'oc_kanso\_%';"
}
has_table_postgres() {
	docker exec kanso-dev-db psql -U nextcloud -d nextcloud -tAc \
		"SELECT to_regclass('public.oc_$1') IS NOT NULL;" | grep -q t
}

count_tables_mysql() {
	docker exec kanso-dev-db mariadb -unextcloud -pnextcloud -N -B nextcloud -e \
		"SELECT count(*) FROM information_schema.tables WHERE table_schema='nextcloud' AND table_name LIKE 'oc_kanso\_%';"
}
has_table_mysql() {
	docker exec kanso-dev-db mariadb -unextcloud -pnextcloud -N -B nextcloud -e \
		"SELECT count(*) FROM information_schema.tables WHERE table_schema='nextcloud' AND table_name='oc_$1';" | grep -q 1
}

# SQLite lives inside the NC container; query it via PDO. Table name (when
# checking a specific table) is passed as argv[1], never interpolated into SQL.
count_tables_sqlite() {
	docker exec -u www-data kanso-dev php -r '
		$db = new PDO("sqlite:/var/www/html/data/nextcloud.db");
		$q = $db->query("SELECT count(*) FROM sqlite_master WHERE type=\"table\" AND name LIKE \"oc_kanso_%\"");
		echo (int)$q->fetchColumn();
	'
}
has_table_sqlite() {
	docker exec -u www-data kanso-dev php -r '
		$db = new PDO("sqlite:/var/www/html/data/nextcloud.db");
		$s = $db->prepare("SELECT count(*) FROM sqlite_master WHERE type=\"table\" AND name=?");
		$s->execute(["oc_" . $argv[1]]);
		echo (int)$s->fetchColumn();
	' "$1" | grep -q 1
}

case "$KANSO_DB" in
	postgres)      COUNT="$(count_tables_postgres)"; MIGS="$(count_migrations_postgres)"; HAS=has_table_postgres ;;
	mysql|mariadb) COUNT="$(count_tables_mysql)";    MIGS="$(count_migrations_mysql)";    HAS=has_table_mysql ;;
	sqlite)        COUNT="$(count_tables_sqlite)";   MIGS="$(count_migrations_sqlite)";   HAS=has_table_sqlite ;;
	*) fail "unknown KANSO_DB='$KANSO_DB'" ;;
esac

echo "migration files: $MIGRATION_FILES | migrations recorded applied: ${MIGS:-0}"
[ "${MIGS:-0}" -ge "$MIGRATION_FILES" ] \
	|| fail "only ${MIGS:-0} of $MIGRATION_FILES migrations recorded in oc_migrations — migrator did not run to completion"

echo "kanso_* tables present: $COUNT"
[ "${COUNT:-0}" -ge "$MIN_TABLES" ] || fail "only $COUNT kanso_* tables (< $MIN_TABLES) — migrations did not create the schema"

for t in $CORE; do
	if $HAS "$t"; then
		echo "  ok: $t"
	else
		fail "core table oc_$t is MISSING"
	fi
done

echo "SMOKE OK: NC=${NC_VERSION:-?} DB=${KANSO_DB} — kanso enabled, migrations applied, schema present."
