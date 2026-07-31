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

# --- notify_push (realtime push) ---------------------------------------------
# Optional: the app falls back to delta-polling without it, and the e2e suite
# doesn't need realtime. Skip in CI (KANSO_SKIP_NOTIFY_PUSH=1) so a flaky/absent
# appstore release can't fail the whole boot.
if [ "${KANSO_SKIP_NOTIFY_PUSH:-0}" = "1" ]; then
	echo "Skipping notify_push setup (KANSO_SKIP_NOTIFY_PUSH=1)"
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

if ! $OCC app:list | grep -q notify_push; then
	$OCC app:install notify_push
fi
# The daemon talks to Nextcloud as http://nextcloud and sits behind the
# compose network's apache proxy.
$OCC config:system:set trusted_proxies 0 --value 172.16.0.0/12
$OCC config:system:set trusted_domains 1 --value nextcloud
$OCC notify_push:setup http://localhost:8891/push
fi

echo
echo "Ready: http://localhost:8891  (admin / admin, test user: tester / kanso-dev-tester!1)"
