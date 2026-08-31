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
#
# Examples:
#   ./setup.sh                                  # NC 34 + postgres (default)
#   NC_VERSION=30 KANSO_DB=sqlite ./setup.sh    # NC 30 + sqlite (no db service)
#   NC_VERSION=32 KANSO_DB=mysql  ./setup.sh    # NC 32 + mariadb
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
	apache2ctl graceful
' 2>/dev/null

# Best-effort install: `occ app:install` needs the container to reach
# apps.nextcloud.com, which it often can't (no egress, a stale appstore cache,
# or appstoreenabled=false) — the same wall install-optional-apps.sh side-steps
# with tarballs. Under `set -eu` a failure here would kill the whole boot, so
# it only downgrades realtime instead. Everything downstream (including
# notify_push:setup, which would fail against a missing app) stays inside the
# success branch.
notify_push_ready=1
if ! $OCC app:list | grep -q notify_push; then
	$OCC app:install notify_push || notify_push_ready=0
fi

if [ "$notify_push_ready" = "1" ]; then
	# The daemon talks to Nextcloud as http://nextcloud and sits behind the
	# compose network's apache proxy.
	$OCC config:system:set trusted_proxies 0 --value 172.16.0.0/12
	$OCC config:system:set trusted_domains 1 --value nextcloud
	$OCC notify_push:setup http://localhost:8891/push
else
	echo >&2
	echo "WARNING: could not install notify_push — continuing without realtime push." >&2
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
