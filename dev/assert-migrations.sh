#!/bin/sh
# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# ONE assertion: every Kanso migration FILE is recorded as applied in
# oc_migrations. Nothing else — no schema counting, no app code, no repair.
#
# It lives in its own script because two callers need exactly it and nothing
# else, and the per-driver dispatch below is the part that rots when it is
# forked:
#   * dev/setup.sh  runs it before it prints "Ready:", so a boot that came up as
#                   new code on the old schema refuses to claim it worked.
#                   Nextcloud runs an app's migrations only when info.xml's
#                   <version> is newer than the installed one, and Kanso stamps
#                   that at release time (never on a feature branch — CLAUDE.md),
#                   so on an already-enabled stack `occ app:enable` is a no-op
#                   and a migration added since the last reset simply never runs.
#   * dev/smoke.sh  runs it as step 2 of the post-install smoke, ahead of the
#                   schema and app-code assertions.
#
# NC has no per-app `migrations:status` occ command across 32-34 (only
# `migrations:preview`), so verify directly against the table: one row per
# migration file proves the migrator ran end to end, which `app:enable`
# returning 0 does not.
#
# DETECT, DO NOT REPAIR. Running the migrator from here would mean a setup
# script silently mutating a shared dev database under whoever is using it; the
# false green is what's being fixed, not the skew itself.
#
# Env (all optional, same names/defaults as dev/smoke.sh so a throwaway stack can
# be pointed at without disturbing a running one):
#   KANSO_DB            postgres (default) | mysql | mariadb | sqlite
#   KANSO_CONTAINER     Nextcloud container   (default kanso-dev)
#   KANSO_DB_CONTAINER  database container    (default kanso-dev-db)
#   KANSO_APP_SRC       app tree mounted as custom_apps/kanso, relative to dev/
#                       (default `..`, this checkout)
set -eu
cd "$(dirname "$0")"

KANSO_DB="${KANSO_DB:-postgres}"
CONTAINER="${KANSO_CONTAINER:-kanso-dev}"
DB_CONTAINER="${KANSO_DB_CONTAINER:-kanso-dev-db}"
# Count the migrations of the tree that is actually MOUNTED, not unconditionally
# this checkout's. dev/upgrade-check.sh boots setup.sh with the last RELEASE
# unpacked (KANSO_APP_SRC=./.upgrade-app/kanso), which has fewer migration files
# than the branch — counting `..` there would fail by construction on a stack
# that is perfectly correct.
APP_SRC="${KANSO_APP_SRC:-..}"

fail() { echo "MIGRATION CHECK FAIL: $*" >&2; exit 1; }

MIGRATION_FILES="$(ls "$APP_SRC"/lib/Migration/Version*.php | wc -l | tr -d ' ')"
# A glob that matches nothing counts 0, and `set -e` cannot see it: `ls` is not
# the last element of the pipeline, so the exit status is `tr`'s. The assertion
# below would then read `[ "$MIGS" -ge 0 ]` — true for every value of MIGS, i.e.
# the whole check passes while asserting nothing. A typo'd, unset or relocated
# KANSO_APP_SRC must go red here, not go quiet.
[ "$MIGRATION_FILES" -gt 0 ] \
	|| fail "no migration files under '$APP_SRC' — KANSO_APP_SRC does not point at a Kanso app tree"

count_migrations_postgres() {
	docker exec "$DB_CONTAINER" psql -U nextcloud -d nextcloud -tAc \
		"SELECT count(*) FROM oc_migrations WHERE app='kanso';"
}
count_migrations_mysql() {
	docker exec "$DB_CONTAINER" mariadb -unextcloud -pnextcloud -N -B nextcloud -e \
		"SELECT count(*) FROM oc_migrations WHERE app='kanso';"
}
# SQLite lives inside the NC container; query it via PDO.
count_migrations_sqlite() {
	docker exec -u www-data "$CONTAINER" php -r '
		$db = new PDO("sqlite:/var/www/html/data/nextcloud.db");
		echo (int)$db->query("SELECT count(*) FROM oc_migrations WHERE app=\"kanso\"")->fetchColumn();
	'
}

case "$KANSO_DB" in
	postgres)      MIGS="$(count_migrations_postgres)" ;;
	mysql|mariadb) MIGS="$(count_migrations_mysql)" ;;
	sqlite)        MIGS="$(count_migrations_sqlite)" ;;
	*) fail "unknown KANSO_DB='$KANSO_DB'" ;;
esac

echo "migration files: $MIGRATION_FILES | migrations recorded applied: ${MIGS:-0}"
[ "${MIGS:-0}" -ge "$MIGRATION_FILES" ] \
	|| fail "only ${MIGS:-0} of $MIGRATION_FILES migrations recorded in oc_migrations — migrator did not run to completion"
