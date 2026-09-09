#!/bin/sh
# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Boot the dev Nextcloud and enable kanso. Idempotent — safe to re-run.
#
# Configurable for the cross-version / cross-DB CI matrix (and local use):
#   NC_VERSION  Nextcloud major version → image nextcloud:<NC_VERSION>-apache
#               (default 34)
#   KANSO_DB    database driver: postgres (default) | mysql | sqlite
#   KANSO_SKIP_OPTIONAL_APPS=1
#               don't side-load the optional apps two e2e specs need (deck,
#               contacts) — see install-optional-apps.sh
#   KANSO_SKIP_BUILD=1
#               don't build the frontend first — js/ and css/ are gitignored
#               build outputs, so without a build the app (and the public share
#               page) serve no JS at all. Set this only when you have already
#               built, as both CI jobs have.
#   KANSO_APP_SRC
#               which app tree to mount as custom_apps/kanso, relative to dev/
#               (default `..`, this checkout). Only upgrade-check.sh sets it —
#               see docker-compose.yml.
#
# Examples:
#   ./setup.sh                                  # NC 34 + postgres (default)
#   NC_VERSION=32 KANSO_DB=sqlite ./setup.sh    # NC 32 + sqlite (no db service)
#   NC_VERSION=33 KANSO_DB=mysql  ./setup.sh    # NC 33 + mariadb
set -eu
cd "$(dirname "$0")"

NC_VERSION="${NC_VERSION:-34}"
KANSO_DB="${KANSO_DB:-postgres}"
export NC_VERSION

OCC="docker exec -u www-data kanso-dev php occ"

# --- database driver selection ----------------------------------------------
# Generate dev/.db.env with the driver-specific env the Nextcloud auto-installer
# reads, and pick the matching compose profile. The nextcloud service always
# reads .db.env (env_file), so it must exist even for sqlite (empty DB host env,
# SQLITE_DATABASE set → the image installs SQLite in-container).
case "$KANSO_DB" in
	postgres)
		COMPOSE_PROFILE=postgres
		cat > .db.env <<-'ENV'
			POSTGRES_HOST=db
			POSTGRES_DB=nextcloud
			POSTGRES_USER=nextcloud
			POSTGRES_PASSWORD=nextcloud
		ENV
		;;
	mysql|mariadb)
		COMPOSE_PROFILE=mysql
		cat > .db.env <<-'ENV'
			MYSQL_HOST=db
			MYSQL_DATABASE=nextcloud
			MYSQL_USER=nextcloud
			MYSQL_PASSWORD=nextcloud
		ENV
		;;
	sqlite)
		COMPOSE_PROFILE=sqlite
		cat > .db.env <<-'ENV'
			SQLITE_DATABASE=nextcloud
		ENV
		;;
	*)
		echo "Unknown KANSO_DB='$KANSO_DB' (want: postgres | mysql | sqlite)" >&2
		exit 2
		;;
esac

# --- frontend build ----------------------------------------------------------
# js/ and css/ are gitignored BUILD OUTPUTS, and the container mounts this
# checkout straight into custom_apps/kanso. Nothing here used to compile them,
# so a fresh clone or a new git worktree served no app JS at all: the board and
# the public share page render blank, and the e2e suite fails on assertions that
# read like product bugs. That is exactly how `tests/e2e/public-share.spec.js`
# came to be "red locally, green in CI" — CI builds in its own workflow step
# (.github/workflows/ci.yml), this script did not. Build before the stack boots.
#
#   KANSO_SKIP_BUILD=1  skip it — for callers that already built (both CI jobs
#                       do) or that mount a prebuilt tree. Also skipped
#                       automatically when KANSO_APP_SRC points somewhere other
#                       than this checkout (upgrade-check.sh mounts an unpacked
#                       release tarball, which ships js/ already built).
if [ "${KANSO_SKIP_BUILD:-0}" = "1" ] || [ "${KANSO_APP_SRC:-..}" != ".." ]; then
	echo "Skipping frontend build (KANSO_SKIP_BUILD=${KANSO_SKIP_BUILD:-0}, app src=${KANSO_APP_SRC:-..})"
else
	echo "Building the frontend (js/, css/)..."
	( cd .. && { [ -d node_modules ] || npm ci --no-audit --no-fund; } && npm run build )
fi

echo "Booting Nextcloud ${NC_VERSION} on ${KANSO_DB}..."
# --profile selects which db service (if any) starts; the sqlite profile has no
# db service so only redis + nextcloud come up.
docker compose --profile "$COMPOSE_PROFILE" up -d

echo "Waiting for Nextcloud to finish installing..."
# Generous budget: on a cold, slow CI runner the older NC images pull fresh and
# their first-boot install (Postgres especially) can take well over 7 minutes.
# 180 * 5s = 15 min, comfortably inside the install-matrix job's 30-min cap.
for i in $(seq 1 180); do
	if curl -sf http://localhost:8891/status.php 2>/dev/null | grep -q '"installed":true'; then
		break
	fi
	sleep 5
done
curl -sf http://localhost:8891/status.php | grep -q '"installed":true' || {
	echo "Nextcloud did not come up; check: docker logs kanso-dev" >&2
	exit 1
}

# Docker pre-creates the mountpoint parent as root; hand it to the web user
docker exec kanso-dev chown www-data:www-data /var/www/html/custom_apps

$OCC app:enable kanso
$OCC background:cron
# Brute-force throttling OFF in the throwaway dev stack. Kanso's public-share
# endpoints are #[BruteForceProtection], and tests/e2e/public-share.spec.js
# deliberately presents four rejected tokens per run (rotated, disabled,
# made-up). The throttle is per source IP and survives the run, so after a few
# consecutive local runs Nextcloud answers 429 + an HTML error page to EVERY
# request from the dev box and the whole spec collapses on `Unexpected token
# '<'` — a failure that reads like a product bug and that CI, booting a fresh
# instance per run, never reproduces. Nothing in the suite asserts throttling.
#
# Order matters: `security:bruteforce:reset` returns early while protection is
# already false (Throttler::resetDelayForIP), so clear any attempts an earlier
# boot recorded BEFORE switching it off, or a stack that is already throttled
# stays throttled forever.
# The host's requests arrive from the compose bridge gateway, i.e. the
# container's default route.
DEV_GATEWAY_IP="$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.Gateway}}{{end}}' kanso-dev 2>/dev/null || true)"
if [ -n "$DEV_GATEWAY_IP" ]; then
	$OCC security:bruteforce:reset "$DEV_GATEWAY_IP" >/dev/null 2>&1 || true
fi
$OCC config:system:set auth.bruteforce.protection.enabled --value=false --type=boolean
# The welcome wizard overlays the page and blocks automated UI tests
$OCC app:disable firstrunwizard

# Plain test user for sharing/permission scenarios (user:add is not idempotent)
if ! $OCC user:list | grep -q '^  - tester:'; then
	docker exec -u www-data -e OC_PASS='kanso-dev-tester!1' kanso-dev php occ user:add --password-from-env tester
fi

# --- optional apps the e2e suite needs ---------------------------------------
# deck (deck-import.spec.js) and contacts (card-contacts.spec.js). Kept in its
# own script so the local stack and the CI e2e job provision from one set of
# pins instead of duplicating the side-load in the workflow file.
./install-optional-apps.sh

# --- notify_push (realtime push) ---------------------------------------------
# Optional: the app falls back to delta-polling without it, and the e2e suite
# doesn't need realtime. CI sets KANSO_SKIP_NOTIFY_PUSH=1 to skip the whole
# block (no appstore reachable there anyway); when it isn't set, a failed
# install only warns — see the guard below. Only wired for postgres (the
# notify_push service only runs under the postgres profile).
#
# Do NOT make skipping the default to line local timings up with CI: this block
# is the only place the push path is exercised anywhere, so a skipping default
# would mean tests/e2e/realtime.spec.js's push-positive test never runs (locally
# it would skip, in CI it already does). Whoever wants the quiet already has
# KANSO_SKIP_NOTIFY_PUSH=1 ./setup.sh.
if [ "${KANSO_SKIP_NOTIFY_PUSH:-0}" = "1" ] || [ "$KANSO_DB" != "postgres" ]; then
	echo "Skipping notify_push setup (KANSO_SKIP_NOTIFY_PUSH=${KANSO_SKIP_NOTIFY_PUSH:-0}, db=${KANSO_DB})"
else
# Browsers reach the push daemon through apache at http://localhost:8891/push.
# Apache additionally listens on 8891 INSIDE the container so the very same
# URL is reachable from occ/php too (notify_push:setup connects to it).
docker exec kanso-dev bash -c '
	a2enmod -q proxy proxy_http proxy_wstunnel >/dev/null
	cat > /etc/apache2/conf-enabled/notify_push.conf <<CONF
ProxyPass /push/ws ws://notify_push:7867/ws
ProxyPass /push/ http://notify_push:7867/
ProxyPassReverse /push/ http://notify_push:7867/
CONF
	grep -q "Listen 8891" /etc/apache2/ports.conf || echo "Listen 8891" >> /etc/apache2/ports.conf
	# `graceful` on purpose; do NOT "fix" this to `apache2ctl -k restart`. Both
	# halves were measured on this image (Apache/2.4.68, Debian): a graceful
	# reload DOES bind a freshly appended `Listen` (append `Listen 8892` here and
	# :8892 answers straight after), and `-k restart` run in this exec breaks the
	# boot — the exec came back 129 (128+SIGHUP) and `set -eu` aborted setup.sh on
	# this very line, with the stderr that would have explained it swallowed by
	# the `2>/dev/null` below. If push is ever unreachable at :8891 on a first
	# boot, the reproducible cause is the app store, just below — not this line.
	apache2ctl graceful
' 2>/dev/null

# Best-effort install: `occ app:install` needs the container to reach
# apps.nextcloud.com, which it can't everywhere (no egress, a stale appstore
# cache) — the same wall install-optional-apps.sh side-steps with tarballs.
# Under `set -eu` a failure here would kill the whole boot, so it only
# downgrades realtime instead.
#
# EVERY step below can flip the flag off, not just the install. `occ app:list`
# prints DISABLED apps too, so matching notify_push there does not mean it is
# usable: an installed-but-disabled app skips the install entirely, and then
# `notify_push:setup` is an unregistered command whose non-zero exit would kill
# the boot under `set -e` — exactly the failure this block exists to remove.
# So gate on the commands actually WORKING, and warn once at the end.
notify_push_ready=1
if ! $OCC app:list | grep -q notify_push; then
	# `app:install` also needs an ENABLED app store, and this stack boots with it
	# off: hooks/pre-installation/00-apps-writable.sh writes
	# config/kanso-appstore.config.php with appstoreenabled => false, which it only
	# needs for the duration of maintenance:install. Nextcloud merges
	# config/*.config.php OVER config.php, so that partial outlives the install,
	# and `config:system:set appstoreenabled --value=true` then reports success
	# while `config:system:get` keeps answering false. The install fails with
	# "Could not download app notify_push, it was not found on the appstore",
	# which reads like a delisted app or a blocked network and is neither — this
	# container reaches the store fine. Drop the partial, then set the real value.
	# Only an actual install pays for this, and only on a postgres stack booted
	# without KANSO_SKIP_NOTIFY_PUSH — which both CI jobs and upgrade-check.sh
	# set, so nothing that has no egress ever turns the store on.
	docker exec kanso-dev rm -f /var/www/html/config/kanso-appstore.config.php || notify_push_ready=0
	$OCC config:system:set appstoreenabled --value=true --type=boolean >/dev/null || notify_push_ready=0
	$OCC app:install notify_push || notify_push_ready=0
fi

# Idempotent, and the step that turns "present" into "usable".
if [ "$notify_push_ready" = "1" ]; then
	$OCC app:enable notify_push || notify_push_ready=0
fi

if [ "$notify_push_ready" = "1" ]; then
	# The daemon talks to Nextcloud as http://nextcloud and sits behind the
	# compose network's apache proxy.
	$OCC config:system:set trusted_proxies 0 --value 172.16.0.0/12
	$OCC config:system:set trusted_domains 1 --value nextcloud
	# The gate that actually matters: whether the command runs at all.
	$OCC notify_push:setup http://localhost:8891/push || notify_push_ready=0
fi

# `notify_push:setup` exiting 0 only proves the command RAN. Once it has
# succeeded once, Nextcloud advertises the notify_push capability from then on,
# so every client takes the push path even if the daemon later goes dark — a
# stack that lies about push is worse than one that has none, because the lie is
# invisible until an e2e assertion with a sub-30s budget goes red. self-test is
# the only check that walks the whole path (redis → daemon → Nextcloud → trusted
# proxy → version match), so run it and print it. It exits non-zero on a real
# break (measured: 1 for an unreachable daemon, 2 for an untrusted proxy) and 0
# on the two http/localhost notes this stack always prints.
if [ "$notify_push_ready" = "1" ]; then
	echo
	echo "Verifying the push path (occ notify_push:self-test)."
	echo "The unencrypted-http and localhost notes are expected in the dev stack:"
	if ! $OCC notify_push:self-test; then
		echo >&2
		echo "WARNING: notify_push is installed and ADVERTISED, but self-test FAILED." >&2
		echo "  * The capability stays advertised, so clients take the push path" >&2
		echo "    and may never receive a frame." >&2
		echo "  * tests/e2e/realtime.spec.js's push test will FAIL (it only skips on" >&2
		echo "    KANSO_SKIP_NOTIFY_PUSH=1), and so may any spec with a short budget." >&2
		echo "  * Check the daemon: docker logs kanso-dev-push" >&2
		echo "  * Check the proxy:  docker exec kanso-dev curl -si http://localhost:8891/push/test/cookie | head -1" >&2
		echo "    A 400 there is healthy (the daemon answers); a connection failure" >&2
		echo "    means apache never bound :8891 inside the container." >&2
		echo >&2
	fi
fi

if [ "$notify_push_ready" != "1" ]; then
	echo >&2
	echo "WARNING: could not set up notify_push — continuing without realtime push." >&2
	echo "  * Kanso works normally; realtime falls back to delta-polling." >&2
	echo "  * tests/e2e/realtime.spec.js's push test will now FAIL rather than skip" >&2
	echo "    (it only skips on the env var), so run the suite with" >&2
	echo "    KANSO_SKIP_NOTIFY_PUSH=1 for a clean local result." >&2
	echo "  * The usual cause is this container not reaching apps.nextcloud.com," >&2
	echo "    NOT a missing release: notify_push v1.4.0 supports Nextcloud 30-35." >&2
	echo "    To side-load it by hand, unpack this into the container's" >&2
	echo "    custom_apps and re-run:" >&2
	echo "    https://github.com/nextcloud-releases/notify_push/releases/download/v1.4.0/notify_push-v1.4.0.tar.gz" >&2
	echo >&2
fi
fi

echo
echo "Ready: http://localhost:8891  (admin / admin, test user: tester / kanso-dev-tester!1)"
