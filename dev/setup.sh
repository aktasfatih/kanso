#!/bin/sh
# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Boot the dev Nextcloud and enable kanso. Idempotent — safe to re-run.
set -eu
cd "$(dirname "$0")"

OCC="docker exec -u www-data kanso-dev php occ"

docker compose up -d

echo "Waiting for Nextcloud to finish installing..."
for i in $(seq 1 60); do
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

echo
echo "Ready: http://localhost:8891  (admin / admin, test user: tester / kanso-dev-tester!1)"
