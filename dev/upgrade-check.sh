#!/bin/sh
# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# THE UPDATE PATH, end to end: install the last RELEASED Kanso, fill it with
# data, then upgrade it to this branch and prove the data survived.
#
# Why this exists
# ---------------
# Every other CI job installs the branch's own tree onto an empty Nextcloud, so
# every migration meets an EMPTY database. The standard ways a migration breaks
# are all invisible there:
#   * a NOT NULL column added without a default   — fails the moment a table has rows
#   * a backfill that trips over real values      — nulls, encoding, size
#   * a unique index that meets existing duplicates
#   * a migration whose runtime is fine on 0 rows and not on 100k
# Kanso is on the App Store, so the update path is the one every existing user
# takes, and until this job nothing exercised it.
#
# What it does
# ------------
#   1. builds this branch's shipping tree (scripts/build-release.sh)
#   2. downloads the latest release tarball and unpacks it into a staging dir
#   3. boots dev/setup.sh with that staging dir mounted as the app (so the
#      RELEASED code installs and runs the RELEASED migrations)
#   4. seeds real data through the API (dev/seed.sh)
#   5. replaces the staging dir's contents with the branch build, in place
#   6. runs `occ upgrade` — this is the step under test
#   7. asserts the seeded data still reads back (dev/seed.sh verify) and that
#      the schema/app-code smoke still passes (dev/smoke.sh)
#
# Scope: ONE Nextcloud version and ONE database (NC 34 + postgres). This class
# of bug is caught by one leg; multiplying it across the install matrix would
# add nine more for nothing. Widen only if a real defect slips through.
#
# DESTRUCTIVE: it runs `docker compose down -v` first, because the released
# version can only be installed onto a pristine database. Any local dev stack
# and its data are destroyed. Re-run ./setup.sh afterwards to get yours back.
#
# Env:
#   KANSO_RELEASE_URL       tarball to install as "the last release"
#                           (default: the repo's latest GitHub release asset)
#   KANSO_RELEASE_TARBALL   use this local tarball instead of downloading
#   NC_VERSION / KANSO_DB   pinned to 34 / postgres; override only to explore
set -eu
cd "$(dirname "$0")"

START_TS="$(date +%s)"

NC_VERSION="${NC_VERSION:-34}"
KANSO_DB="${KANSO_DB:-postgres}"
RELEASE_URL="${KANSO_RELEASE_URL:-https://github.com/aktasfatih/kanso/releases/latest/download/kanso.tar.gz}"
STAGE_PARENT=".upgrade-app"
STAGE="${STAGE_PARENT}/kanso"
BRANCH_BUILD="../build/kanso"

export NC_VERSION KANSO_DB

fail() { echo "UPGRADE CHECK FAIL: $*" >&2; exit 1; }
step() { echo; echo "=== $* ==="; }

app_version() { sed -n 's|.*<version>\([0-9][0-9.]*\)</version>.*|\1|p' "$1" | head -1; }
# a <= b ?
version_le() { [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -1)" = "$1" ]; }

OCC="docker exec -u www-data kanso-dev php occ"
BASE_URL="${KANSO_BASE_URL:-http://localhost:8891}"
ADMIN_AUTH="${KANSO_ADMIN_AUTH:-admin:admin}"

# `occ app:enable` (and `occ upgrade`) run in their OWN php process. The apache
# workers that have been answering status.php since the container booted keep
# their own local app-config cache (APCu in this image), so for a few seconds
# after a CLI state change the HTTP API can still answer 404 for a route that
# occ already considers enabled. That is a real, reproducible race — it made a
# run of this script fail on `POST /api/boards -> HTTP 404` moments after
# `kanso 0.24.0 enabled` — so gate on the API itself being ready rather than on
# occ having returned. Bounded, and it fails loudly if it never comes up.
wait_for_api() {
	i=0
	while [ "$i" -lt 60 ]; do
		if [ "$(curl -s -o /dev/null -w '%{http_code}' -u "$ADMIN_AUTH" \
			-H 'OCS-APIRequest: true' "$BASE_URL/apps/kanso/api/boards")" = "200" ]; then
			return 0
		fi
		i=$((i + 1))
		sleep 1
	done
	fail "the Kanso HTTP API never became ready ($1)"
}

# ---------------------------------------------------------------------------
step "1. Build this branch's shipping tree"
# ---------------------------------------------------------------------------
# Not a hand-rolled copy of the checkout: build-release.sh produces exactly the
# runtime tree the App Store ships, so the upgrade under test is the real one.
# js/ is gitignored, so it must already be built (npm run build).
[ -f ../js/kanso-main.mjs ] || fail "js/ is not built — run 'npm run build' first"
SKIP_BUILD=1 ../scripts/build-release.sh >/dev/null || fail "scripts/build-release.sh failed"
[ -f "${BRANCH_BUILD}/appinfo/info.xml" ] || fail "build/kanso was not produced"
BRANCH_VERSION="$(app_version "${BRANCH_BUILD}/appinfo/info.xml")"
echo "branch tree: ${BRANCH_BUILD} (info.xml version ${BRANCH_VERSION})"

# ---------------------------------------------------------------------------
step "2. Fetch the last released Kanso"
# ---------------------------------------------------------------------------
rm -rf "$STAGE_PARENT"
mkdir -p "$STAGE_PARENT"
if [ -n "${KANSO_RELEASE_TARBALL:-}" ]; then
	echo "using local tarball ${KANSO_RELEASE_TARBALL}"
	cp "$KANSO_RELEASE_TARBALL" "${STAGE_PARENT}/release.tar.gz"
else
	echo "downloading ${RELEASE_URL}"
	curl -fsSL "$RELEASE_URL" -o "${STAGE_PARENT}/release.tar.gz" \
		|| fail "could not download the release tarball from ${RELEASE_URL}"
fi
tar -xzf "${STAGE_PARENT}/release.tar.gz" -C "$STAGE_PARENT"
[ -f "${STAGE}/appinfo/info.xml" ] || fail "the release tarball did not contain kanso/appinfo/info.xml"
RELEASED_VERSION="$(app_version "${STAGE}/appinfo/info.xml")"
echo "released tree: ${STAGE} (info.xml version ${RELEASED_VERSION})"

# ---------------------------------------------------------------------------
step "3. Install the RELEASED version on a pristine Nextcloud ${NC_VERSION}/${KANSO_DB}"
# ---------------------------------------------------------------------------
# compose refuses to parse the model without .db.env; setup.sh rewrites it.
[ -f .db.env ] || : > .db.env
# Same KANSO_DB → compose-profile mapping setup.sh uses.
COMPOSE_PROFILE="$KANSO_DB"
if [ "$COMPOSE_PROFILE" = "mariadb" ]; then COMPOSE_PROFILE=mysql; fi
docker compose --profile "$COMPOSE_PROFILE" down -v >/dev/null 2>&1 || true
KANSO_APP_SRC="./${STAGE}" \
	KANSO_SKIP_NOTIFY_PUSH=1 KANSO_SKIP_OPTIONAL_APPS=1 \
	./setup.sh || fail "dev/setup.sh could not boot the released version"

INSTALLED="$($OCC config:app:get kanso installed_version | tr -d '\r\n ')"
[ "$INSTALLED" = "$RELEASED_VERSION" ] \
	|| fail "expected the released version ${RELEASED_VERSION} to be installed, got '${INSTALLED}'"
echo "installed_version = ${INSTALLED} (the released tree)"
wait_for_api "after installing the released version"

# ---------------------------------------------------------------------------
step "4. Seed real data through the API"
# ---------------------------------------------------------------------------
./seed.sh seed || fail "seeding the released install failed"

# ---------------------------------------------------------------------------
step "5. Swap the branch tree in, in place"
# ---------------------------------------------------------------------------
# Same host path, so the container sees the new code immediately and nothing has
# to be recreated — the DB, the config and the seeded data all stay exactly as
# the released version left them. This is the moment a user's install becomes
# "new code, old data".
find "$STAGE" -mindepth 1 -delete
cp -a "${BRANCH_BUILD}/." "${STAGE}/"

# Nextcloud only runs an app's migrations when info.xml's version is NEWER than
# the installed one. Versions here are release-only (semantic-release stamps
# them at release time — see CLAUDE.md), so on a feature branch info.xml still
# carries the SAME version as the release we just installed, and `occ upgrade`
# would be a no-op: a green, entirely vacuous job. So stamp the staged copy —
# never the checkout — with the next patch version, which is exactly what the
# release would have done.
NEW_VERSION="$BRANCH_VERSION"
if version_le "$BRANCH_VERSION" "$RELEASED_VERSION"; then
	NEW_VERSION="$(printf '%s' "$RELEASED_VERSION" | awk -F. '{printf "%s.%s.%d", $1, $2, $3 + 1}')"
	echo "branch info.xml is ${BRANCH_VERSION} (not newer than the release);"
	echo "stamping the STAGED copy as ${NEW_VERSION} so the migrator actually runs"
	sed -i "s|<version>${BRANCH_VERSION}</version>|<version>${NEW_VERSION}</version>|" \
		"${STAGE}/appinfo/info.xml"
	[ "$(app_version "${STAGE}/appinfo/info.xml")" = "$NEW_VERSION" ] \
		|| fail "could not stamp the staged info.xml version"
else
	echo "branch info.xml (${BRANCH_VERSION}) is already newer than ${RELEASED_VERSION}"
fi

# ---------------------------------------------------------------------------
step "6. occ upgrade — the step under test"
# ---------------------------------------------------------------------------
# NOT '|| true'. Every other job in this workflow can afford to be lenient here
# because it upgrades an empty install; this one is the assertion.
$OCC upgrade -v || {
	$OCC maintenance:mode --off >/dev/null 2>&1 || true
	fail "occ upgrade failed on an install that had real data"
}
$OCC maintenance:mode --off >/dev/null 2>&1 || true

AFTER="$($OCC config:app:get kanso installed_version | tr -d '\r\n ')"
[ "$AFTER" = "$NEW_VERSION" ] \
	|| fail "installed_version is '${AFTER}', expected '${NEW_VERSION}' — the app was never upgraded, so this run proved nothing"
echo "installed_version = ${AFTER} (upgraded from ${RELEASED_VERSION})"
wait_for_api "after the upgrade"

# ---------------------------------------------------------------------------
step "7. Assert the pre-existing data survived"
# ---------------------------------------------------------------------------
./seed.sh verify || fail "seeded data did not survive the upgrade"

# And the usual post-install assertions, now on a populated database: every
# migration recorded as applied (including the branch's new ones), the schema
# present, and app code actually executing.
./smoke.sh || fail "dev/smoke.sh failed after the upgrade"

ELAPSED=$(( $(date +%s) - START_TS ))
echo
echo "UPGRADE CHECK OK: ${RELEASED_VERSION} → ${NEW_VERSION} on NC ${NC_VERSION}/${KANSO_DB},"
echo "  with pre-existing data, in $((ELAPSED / 60))m $((ELAPSED % 60))s."
