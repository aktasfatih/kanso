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
		# What Nextcloud records in config.php's `dbtype` for this driver — used
		# by the webroot check further down.
		EXPECTED_DBTYPE=pgsql
		cat > .db.env <<-'ENV'
			POSTGRES_HOST=db
			POSTGRES_DB=nextcloud
			POSTGRES_USER=nextcloud
			POSTGRES_PASSWORD=nextcloud
		ENV
		;;
	mysql|mariadb)
		COMPOSE_PROFILE=mysql
		EXPECTED_DBTYPE=mysql
		cat > .db.env <<-'ENV'
			MYSQL_HOST=db
			MYSQL_DATABASE=nextcloud
			MYSQL_USER=nextcloud
			MYSQL_PASSWORD=nextcloud
		ENV
		;;
	sqlite)
		COMPOSE_PROFILE=sqlite
		EXPECTED_DBTYPE=sqlite3
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

# The reset every message below points at. NOT a bare `down -v`: compose only
# removes the volumes of services whose profile is active, so `down -v` without
# a profile deletes the webroot and leaves the (profiled) database container up
# with all its data — the half-wiped state that bricks the next boot. `'*'`
# selects every profile, so it clears whichever driver was last used.
RESET_CMD="docker compose --profile '*' down -v && NC_VERSION=${NC_VERSION} KANSO_DB=${KANSO_DB} ./setup.sh"

echo "Waiting for Nextcloud to finish installing..."
# Generous budget: on a cold, slow CI runner the older NC images pull fresh and
# their first-boot install (Postgres especially) can take well over 7 minutes.
# 180 * 5s = 15 min, comfortably inside the install-matrix job's 30-min cap.
#
# The budget is for a SLOW boot, not a dead one. When the entrypoint's install
# fails it exits the container, and every remaining second of that 15 minutes is
# spent polling a port nothing is listening on — so stop as soon as the
# container is gone and let the diagnosis below print. (That wait is how the
# blank-webroot brick used to present: a quarter of an hour of silence, then a
# generic "did not come up".)
for i in $(seq 1 180); do
	if curl -sf http://localhost:8891/status.php 2>/dev/null | grep -q '"installed":true'; then
		break
	fi
	case "$(docker inspect -f '{{.State.Status}}' kanso-dev 2>/dev/null || echo missing)" in
		running|created|restarting) ;;
		*) echo "The kanso-dev container is no longer running — not waiting out the rest." >&2
		   break ;;
	esac
	sleep 5
done
curl -sf http://localhost:8891/status.php | grep -q '"installed":true' || {
	echo "Nextcloud did not come up; check: docker logs kanso-dev" >&2
	echo >&2
	echo "If the log ends in 'Installing of nextcloud failed!' (typically with" >&2
	echo "'permission denied for table oc_migrations'), the entrypoint found an" >&2
	echo "EMPTY webroot next to a populated database and tried to install over it." >&2
	echo "That means the two were reset separately — usually a bare 'down -v',"  >&2
	echo "which only removes the volumes of the active profiles. Reset both:" >&2
	echo "  ${RESET_CMD}" >&2
	echo "That destroys the dev database too — dev/seed.sh reseeds it." >&2
	exit 1
}

# --- is this the stack that was asked for? -----------------------------------
# The webroot is a NAMED volume (see docker-compose.yml), so it deliberately
# outlives `docker compose down`. That is what makes `down` a restart instead of
# the trap it used to be — but it also means a webroot installed against another
# driver, or another NC major, survives, and Nextcloud will boot it without
# complaint. Everything downstream — smoke.sh, the install matrix, the e2e suite
# — would then pass against a stack nobody asked for. Assert, don't trust.

# 1. The database driver. `KANSO_DB=sqlite ./setup.sh` over a postgres install:
#    the entrypoint sees a config.php, skips the install, keeps the old dbtype,
#    and the stack comes up "healthy" while still talking to postgres.
#    No `|| true` and no swallowed stderr: on an install that answered
#    status.php `"installed":true`, dbtype is always set, so failing to read it
#    means something is wrong with occ — and a guard that quietly stops guarding
#    is worse than no guard.
ACTUAL_DBTYPE="$($OCC config:system:get dbtype | tr -d '\r\n ')"
if [ "$ACTUAL_DBTYPE" != "$EXPECTED_DBTYPE" ]; then
	echo >&2
	echo "This Nextcloud is installed on '${ACTUAL_DBTYPE}', but KANSO_DB=${KANSO_DB}" >&2
	echo "asked for '${EXPECTED_DBTYPE}'. The webroot volume is left over from an" >&2
	echo "earlier boot on the other driver; switching drivers needs a reset, not a" >&2
	echo "restart:" >&2
	echo "  ${RESET_CMD}" >&2
	echo "That destroys the dev database too — dev/seed.sh reseeds it." >&2
	exit 1
fi

# 2. The Nextcloud major. This one is asymmetric, and only one half is loud:
#    a webroot NEWER than the image makes the entrypoint refuse to start (caught
#    by the boot check above), but a webroot one major OLDER is silently rsynced
#    and `occ upgrade`d — so `NC_VERSION=33 ./setup.sh` over an NC 32 webroot
#    yields a healthy, correct-looking NC 33 whose Kanso migrations never ran on
#    NC 33 at all. That is exactly what the install matrix exists to check, so it
#    is exactly the run that must not pass quietly. The entrypoint says so in its
#    log; a same-major patch bump (34.0.3 → 34.0.4) is a legitimate upgrade and
#    deliberately does NOT trip this.
CARRIED_FROM="$(docker logs kanso-dev 2>&1 \
	| sed -n 's/.*Upgrading nextcloud from \([0-9][0-9]*\)\..*/\1/p' | tail -1)"
if [ -n "$CARRIED_FROM" ] && [ "$CARRIED_FROM" != "$NC_VERSION" ]; then
	echo >&2
	echo "This webroot was installed on Nextcloud ${CARRIED_FROM} and the entrypoint just" >&2
	echo "upgraded it in place to ${NC_VERSION}. An upgraded instance is not a fresh" >&2
	echo "install: Kanso's migrations ran on ${CARRIED_FROM}, not on ${NC_VERSION}, so anything" >&2
	echo "this stack proves about NC ${NC_VERSION} is worth nothing. Reset:" >&2
	echo "  ${RESET_CMD}" >&2
	echo "(dev/upgrade-check.sh is the script that tests upgrades on purpose.)" >&2
	exit 1
fi

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
# Browsers reach the push daemon through apache at http://localhost:8891/push,
# and apache additionally listens on 8891 INSIDE the container so the very same
# URL is reachable from occ/php too (notify_push:setup connects to it). Both
# halves are configured by dev/apache/notify_push.conf, which docker-compose.yml
# bind-mounts into /etc/apache2/conf-enabled/ — so it is applied by the `up -d`
# above, on every boot, with no step here to forget.
#
# This used to be a `docker exec` that ran a2enmod and wrote that conf into the
# running container. /etc/apache2 is NOT on a volume, so every recreation of the
# nextcloud service (a compose edit, an NC_VERSION bump, a `docker compose up -d`
# from a different worktree re-pointing KANSO_APP_SRC) silently reverted it while
# the daemon stayed up and Nextcloud kept advertising push — the exact
# "advertised but dead" state the self-test below exists to catch, arriving
# without anyone re-running setup.sh. Do not move it back into an exec.
#
# It does mean the config only lands when the container is (re)created. compose
# does that for us on the `up -d` above whenever this file changes; a stack left
# running from before the mount existed needs one `docker compose --profile
# postgres up -d --force-recreate nextcloud`. The self-test below says so if it
# is still missing.
if ! docker exec kanso-dev test -f /etc/apache2/conf-enabled/notify_push.conf; then
	echo >&2
	echo "WARNING: the /push reverse-proxy config is not mounted in kanso-dev." >&2
	echo "  * dev/apache/notify_push.conf should be bind-mounted to" >&2
	echo "    /etc/apache2/conf-enabled/notify_push.conf (see docker-compose.yml)." >&2
	echo "  * Recreate the container so the mount is applied:" >&2
	echo "      docker compose --profile postgres up -d --force-recreate nextcloud" >&2
	echo >&2
fi

# Best-effort install: it needs egress (github.com for the pinned tarball, or
# apps.nextcloud.com for the fallback), which not every host has. Under `set -eu`
# a failure here would kill the whole boot, so it only downgrades realtime
# instead.
#
# EVERY step below can flip the flag off, not just the install — keep it that
# way. `occ app:list` prints DISABLED apps too, so the app being listed does not
# mean it is usable: skip the install on a mere name match and `notify_push:setup`
# is then an unregistered command whose non-zero exit kills the boot under
# `set -e` — exactly the failure this block exists to remove. So gate on the
# commands actually WORKING, and warn once at the end.
#
# The app version is PINNED, in lockstep with the daemon image tag in
# docker-compose.yml — see the long comment on that service for why both halves
# have to move together (short version: self-test compares the two versions, so
# either side floating turns the boot warning into noise). Bump the version and
# the URL here together with the compose tag, and nothing else.
NOTIFY_PUSH_VERSION=1.4.0
NOTIFY_PUSH_URL="https://github.com/nextcloud-releases/notify_push/releases/download/v${NOTIFY_PUSH_VERSION}/notify_push-v${NOTIFY_PUSH_VERSION}.tar.gz"

notify_push_ready=1
# Match on the PINNED version, not the bare app name: `occ app:list` prints
# disabled apps too, and a long-lived stack still carrying the previous pin has
# to be re-installed or the version-match check below fails by construction.
# Same guard shape as install-optional-apps.sh.
if ! $OCC app:list | grep -q "^  - notify_push: $NOTIFY_PUSH_VERSION"; then
	# Preferred path: side-load the pinned release tarball, the same mechanism
	# install-optional-apps.sh uses for deck/contacts (download on the host,
	# `docker cp` it in). That makes the app version a pin in this file instead
	# of whatever the App Store happens to serve for this Nextcloud major.
	echo "Installing notify_push v${NOTIFY_PUSH_VERSION} (pinned to the daemon image tag)..."
	if ! curl -fsSL "$NOTIFY_PUSH_URL" -o /tmp/notify_push.tar.gz; then
		# Fallback for a host that can't reach github.com. Unpinned by nature —
		# say so, because the self-test may then legitimately report a version
		# skew against the pinned daemon image.
		echo "Could not download the pinned notify_push v${NOTIFY_PUSH_VERSION} tarball; falling back to the App Store." >&2
		echo "  * The store serves whatever version it likes for this Nextcloud" >&2
		echo "    major, so the self-test's version-match check may then fail" >&2
		echo "    against the pinned daemon image. That is a real mismatch, not" >&2
		echo "    a false alarm — pull the tarball, or align the compose tag." >&2
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
		# set, so nothing that runs unattended ever turns the store on.
		#
		# And only when the app is genuinely ABSENT: `occ app:install` returns 1
		# on an app that is already present (core Command/App/Install.php: "already
		# installed"), so running it against a stack that merely carries a
		# different version would flip notify_push_ready off and make the boot
		# claim push is dead on a stack where it works. Keeping the version we
		# already have is the right call here — it may skew against the pinned
		# daemon, which the self-test will then say out loud.
		if $OCC app:list | grep -q "^  - notify_push:"; then
			echo "  * notify_push is already present at another version — keeping it." >&2
		else
			docker exec kanso-dev rm -f /var/www/html/config/kanso-appstore.config.php || notify_push_ready=0
			$OCC config:system:set appstoreenabled --value=true --type=boolean >/dev/null || notify_push_ready=0
			$OCC app:install notify_push || notify_push_ready=0
		fi
	elif ! docker cp /tmp/notify_push.tar.gz kanso-dev:/tmp/notify_push.tar.gz \
		|| ! docker exec kanso-dev bash -ec '
			rm -rf /var/www/html/custom_apps/notify_push
			tar -xzf /tmp/notify_push.tar.gz -C /var/www/html/custom_apps
			chown -R www-data:www-data /var/www/html/custom_apps/notify_push
		'; then
		# `bash -ec`, and the copy chained into the same condition, on purpose.
		# The tree is `rm -rf`'d before the untar (so a version bump can't leave
		# the previous release's orphaned files behind), which means a tar that
		# dies half-way has already destroyed a working install. Without `-e` the
		# exec's status would be `chown`'s — and chown succeeds on a half-extracted
		# directory, so the boot would sail on with a broken app and still report
		# push as healthy. Both halves live in the `if` condition rather than the
		# body because `set -e` does not apply to conditions: a docker failure has
		# to downgrade realtime, never abort the boot.
		echo >&2
		echo "WARNING: unpacking notify_push v${NOTIFY_PUSH_VERSION} into the container failed." >&2
		echo "  * custom_apps/notify_push may be half-extracted — re-run ./setup.sh." >&2
		notify_push_ready=0
	else
		# `app:enable` short-circuits on an app that is already enabled (core
		# Command/App/Enable.php returns before Installer::installApp()), and
		# installApp() is what writes appconfig `installed_version` — the value
		# `occ app:list` prints and the guard above matches on. So on a stack that
		# already had notify_push enabled at the previous pin, a bump would extract
		# the new tree, leave the recorded version at the old one, and re-download
		# on every boot forever while the app's repair steps never re-ran. Disable
		# first so the enable below is a real install. No-op on a fresh stack.
		$OCC app:disable notify_push >/dev/null 2>&1 || true
	fi
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
	echo "  * If the output above ends in a push server / app VERSION MISMATCH," >&2
	echo "    the cause is the two pins having drifted apart, not the network:" >&2
	echo "    set the image tag in docker-compose.yml and NOTIFY_PUSH_VERSION" >&2
	echo "    here to the SAME version, then re-run. (Measured: a skew fails" >&2
	echo "    \`notify_push:setup\` outright, so the boot lands here rather than" >&2
	echo "    in the self-test warning — the mismatch line is the one to read.)" >&2
	echo "  * Otherwise the usual cause is this host reaching neither github.com nor" >&2
	echo "    apps.nextcloud.com, NOT a missing release: the pinned" >&2
	echo "    notify_push v${NOTIFY_PUSH_VERSION} supports Nextcloud 30-35." >&2
	echo "    To side-load it by hand, unpack this into the container's" >&2
	echo "    custom_apps and re-run:" >&2
	echo "    ${NOTIFY_PUSH_URL}" >&2
	echo >&2
fi
fi

echo
echo "Ready: http://localhost:8891  (admin / admin, test user: tester / kanso-dev-tester!1)"
