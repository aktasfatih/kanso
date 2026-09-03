#!/bin/sh
# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Post-install smoke assertion for the cross-version / cross-DB CI matrix.
# Two things are asserted, in this order:
#
#   SCHEMA  — enabling Kanso on this (NC_VERSION, KANSO_DB) combo actually ran
#             the migrations and CREATED THE TABLES. That is the failure mode we
#             hit once: a default-named PRIMARY KEY blew the 23-char index-name
#             limit and silently created ZERO tables on a fresh install.
#   APP CODE — real Kanso code paths actually EXECUTE against this server's API.
#             Counting tables proves nothing about whether the app runs: it let
#             info.xml claim NC 30 support while opening the app was a fatal
#             PHP Error on a server API that did not exist yet (see step 4).
#
# Run against the stack booted by dev/setup.sh. Exits non-zero on any failure.
set -eu
cd "$(dirname "$0")"

KANSO_DB="${KANSO_DB:-postgres}"
# Overridable so this can be pointed at a throwaway stack (a different container
# name / published port) without disturbing a running dev stack. Defaults are
# exactly what dev/setup.sh + dev/docker-compose.yml boot.
CONTAINER="${KANSO_CONTAINER:-kanso-dev}"
DB_CONTAINER="${KANSO_DB_CONTAINER:-kanso-dev-db}"
BASE_URL="${KANSO_BASE_URL:-http://localhost:8891}"
ADMIN_AUTH="${KANSO_ADMIN_AUTH:-admin:admin}"
OCC="docker exec -u www-data $CONTAINER php occ"

fail() { echo "SMOKE FAIL: $*" >&2; exit 1; }

# 1. App must be enabled (occ app:enable would have failed the boot otherwise,
#    but assert it explicitly so a partially-installed app is caught).
$OCC app:list --enabled 2>/dev/null | grep -q 'kanso' \
	|| $OCC app:list 2>/dev/null | grep -q 'kanso' \
	|| fail "kanso is not in 'occ app:list' (enabled apps)"

# 2. Every migration must be recorded as applied. NC has no per-app
#    'migrations:status' occ command across 32–34, so verify directly: the
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
	docker exec "$DB_CONTAINER" psql -U nextcloud -d nextcloud -tAc \
		"SELECT count(*) FROM oc_migrations WHERE app='kanso';"
}
count_migrations_mysql() {
	docker exec "$DB_CONTAINER" mariadb -unextcloud -pnextcloud -N -B nextcloud -e \
		"SELECT count(*) FROM oc_migrations WHERE app='kanso';"
}
count_migrations_sqlite() {
	docker exec -u www-data "$CONTAINER" php -r '
		$db = new PDO("sqlite:/var/www/html/data/nextcloud.db");
		echo (int)$db->query("SELECT count(*) FROM oc_migrations WHERE app=\"kanso\"")->fetchColumn();
	'
}

count_tables_postgres() {
	docker exec "$DB_CONTAINER" psql -U nextcloud -d nextcloud -tAc \
		"SELECT count(*) FROM information_schema.tables WHERE table_name LIKE 'oc_kanso\_%';"
}
has_table_postgres() {
	docker exec "$DB_CONTAINER" psql -U nextcloud -d nextcloud -tAc \
		"SELECT to_regclass('public.oc_$1') IS NOT NULL;" | grep -q t
}

count_tables_mysql() {
	docker exec "$DB_CONTAINER" mariadb -unextcloud -pnextcloud -N -B nextcloud -e \
		"SELECT count(*) FROM information_schema.tables WHERE table_schema='nextcloud' AND table_name LIKE 'oc_kanso\_%';"
}
has_table_mysql() {
	docker exec "$DB_CONTAINER" mariadb -unextcloud -pnextcloud -N -B nextcloud -e \
		"SELECT count(*) FROM information_schema.tables WHERE table_schema='nextcloud' AND table_name='oc_$1';" | grep -q 1
}

# SQLite lives inside the NC container; query it via PDO. Table name (when
# checking a specific table) is passed as argv[1], never interpolated into SQL.
count_tables_sqlite() {
	docker exec -u www-data "$CONTAINER" php -r '
		$db = new PDO("sqlite:/var/www/html/data/nextcloud.db");
		$q = $db->query("SELECT count(*) FROM sqlite_master WHERE type=\"table\" AND name LIKE \"oc_kanso_%\"");
		echo (int)$q->fetchColumn();
	'
}
has_table_sqlite() {
	docker exec -u www-data "$CONTAINER" php -r '
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

echo "schema OK — now exercising app code"

# 4. EXECUTE APP CODE. Everything above proves the SCHEMA exists; not one line
#    of Kanso PHP runs in it, so a server API the app calls but this Nextcloud
#    does not have is invisible to it. That blind spot is real, not theoretical:
#    info.xml claimed NC 30 support for months while the app fataled on its very
#    first screen, because two IQueryBuilder APIs only exist from NC 32 —
#      * IQueryBuilder::PARAM_DATETIME_MUTABLE, bound by CardMapper's overdue
#        aggregates and reached from BoardService::findAllWithStats(), i.e. the
#        boards-list payload the app loads on open;
#      * IQueryBuilder::forUpdate(), called by CardMapper::findByStackForUpdate()
#        and reached from CardService::rebalanceStack().
#    PHPUnit and psalm cannot see it either: composer.json maps OCP\ to
#    vendor/nextcloud/ocp pinned ^34, so both type-check against the NEWEST
#    server's API and never the oldest supported one. This matrix, on the real
#    server, is the only place that can catch it — so drive both paths here.
#
#    A missing API is a PHP \Error. Neither swallows it: ApiErrorTrait maps only
#    Kanso's own service exceptions (never \Error), so the request is a 500; and
#    Symfony's console returns non-zero when a command throws. Both are asserted
#    below, so this goes RED instead of silently green.
#
#    Auth is admin BasicAuth + the OCS-APIRequest header, which is how Nextcloud
#    lets a non-browser client skip the CSRF token.
API_BODY="$(mktemp)"
SMOKE_BOARD=''
cleanup() {
	# Leave the instance as we found it. Best-effort: a failed run has already
	# reported the real error and must keep its exit status.
	if [ -n "$SMOKE_BOARD" ]; then
		curl -s -o /dev/null -u "$ADMIN_AUTH" -H 'OCS-APIRequest: true' \
			-X DELETE "$BASE_URL/apps/kanso/api/boards/$SMOKE_BOARD" || true
	fi
	rm -f "$API_BODY"
}
trap cleanup EXIT

# api <METHOD> <path> [json body] — body lands in $API_BODY, status in $API_STATUS.
api() {
	if [ $# -ge 3 ]; then
		API_STATUS="$(curl -s -o "$API_BODY" -w '%{http_code}' \
			-u "$ADMIN_AUTH" -H 'OCS-APIRequest: true' -H 'Content-Type: application/json' \
			-X "$1" -d "$3" "$BASE_URL/apps/kanso$2")"
	else
		API_STATUS="$(curl -s -o "$API_BODY" -w '%{http_code}' \
			-u "$ADMIN_AUTH" -H 'OCS-APIRequest: true' \
			-X "$1" "$BASE_URL/apps/kanso$2")"
	fi
}

# Assert 200 and surface the body on failure — a 500 body names the missing
# API/class, which is the whole diagnostic.
expect_200() {
	[ "$API_STATUS" = "200" ] \
		|| fail "$1 → HTTP $API_STATUS. Response: $(head -c 500 "$API_BODY")"
}

# First "id" in a JSON object response is the created entity's own id.
created_id() { grep -o '"id":[0-9]*' "$API_BODY" | head -1 | cut -d: -f2; }

api POST /api/boards '{"title":"smoke probe"}'
expect_200 'POST /api/boards (BoardService::create)'
SMOKE_BOARD="$(created_id)"
[ -n "$SMOKE_BOARD" ] || fail "POST /api/boards returned no board id: $(head -c 500 "$API_BODY")"

api POST /api/stacks "{\"boardId\":${SMOKE_BOARD},\"title\":\"Smoke\"}"
expect_200 'POST /api/stacks (StackService::create)'
SMOKE_STACK="$(created_id)"
[ -n "$SMOKE_STACK" ] || fail "POST /api/stacks returned no stack id: $(head -c 500 "$API_BODY")"

# An overdue due date, so the DATETIME comparison below has a row to match
# rather than only being parsed.
api POST /api/cards "{\"stackId\":${SMOKE_STACK},\"title\":\"smoke card\",\"duedate\":\"2020-01-01T00:00:00+00:00\"}"
expect_200 'POST /api/cards (CardService::create)'

# 4a. The boards list — BoardService::findAllWithStats(), which binds
#     PARAM_DATETIME_MUTABLE in the overdue aggregate. Note the ORDER: that
#     aggregate short-circuits on an empty board-id set, so it is only reached
#     once the board above exists. Running this first would prove nothing.
api GET /api/boards
expect_200 'GET /api/boards (BoardService::findAllWithStats)'
grep -q '"stats"' "$API_BODY" \
	|| fail "GET /api/boards returned no per-board stats block: $(head -c 500 "$API_BODY")"
echo "  ok: boards list with stats (PARAM_DATETIME_MUTABLE)"

# 4b. The rebalance — CardService::rebalanceStack() →
#     CardMapper::findByStackForUpdate(), which calls forUpdate(). occ exits
#     non-zero if it throws, and `set -e` turns that into a failed build.
#
#     NOT on SQLite: `SELECT ... FOR UPDATE` has no SQLite equivalent, so the
#     platform rejects it outright ("Operation 'FOR UPDATE' is not supported by
#     platform") on EVERY Nextcloud version — a database limitation, not a
#     version-support signal, so asserting it here would just paint the whole
#     sqlite matrix red. Postgres runs on every NC version in the matrix, so
#     forUpdate() is still covered on every version we claim to support.
if [ "$KANSO_DB" = "sqlite" ]; then
	echo "  skip: occ kanso:rebalance — SQLite has no SELECT ... FOR UPDATE"
else
	$OCC kanso:rebalance --board "$SMOKE_BOARD" \
		|| fail "occ kanso:rebalance --board $SMOKE_BOARD failed (CardMapper::findByStackForUpdate / forUpdate())"
	echo "  ok: occ kanso:rebalance (forUpdate)"
fi

echo "SMOKE OK: NC=${NC_VERSION:-?} DB=${KANSO_DB} — kanso enabled, migrations applied, schema present, app code runs."
