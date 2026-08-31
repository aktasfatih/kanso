#!/bin/sh
# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Side-load the optional Nextcloud apps two e2e specs need, into the running
# dev stack (container `kanso-dev`). Idempotent — safe to re-run.
#
#   deck     → tests/e2e/deck-import.spec.js  (its beforeAll seeds a source
#              board through the real Deck API, so the whole describe errors
#              out when Deck is missing)
#   contacts → tests/e2e/card-contacts.spec.js (ContactService::isAvailable()
#              is false without the app, so the picker search returns an empty
#              list and the spec's option assertion times out)
#
# Called by dev/setup.sh, so a local stack and the CI e2e job provision
# identically and the version pins live in exactly one place.
#
# Why tarballs and not `occ app:install`: the CI runner's dind network cannot
# reach the Nextcloud appstore from inside the container. The runner host CAN
# reach GitHub, so download here and `docker cp` the archive in. Do not
# "simplify" this to an appstore install — it will fail in CI.
#
# Env:
#   KANSO_SKIP_OPTIONAL_APPS=1  skip entirely (offline boot, or a flaky
#                               github.com — the two specs above will then fail,
#                               but the rest of the stack still comes up). A
#                               failed download does the same thing with a
#                               warning; this just makes it quiet and instant.
#   NC_VERSION                  the pins below target Nextcloud 34; on any other
#                               major the install is skipped rather than risking
#                               an incompatible app (contacts v8.7.5 declares
#                               min-version=33, max-version=34)
set -eu

# Pinned releases. Bump both the version and the URL together.
DECK_VERSION=1.18.3
DECK_URL="https://github.com/nextcloud-releases/deck/releases/download/v1.18.3/deck-v1.18.3.tar.gz"
CONTACTS_VERSION=8.7.5
CONTACTS_URL="https://github.com/nextcloud-releases/contacts/releases/download/v8.7.5/contacts-v8.7.5.tar.gz"

NC_VERSION="${NC_VERSION:-34}"

if [ "${KANSO_SKIP_OPTIONAL_APPS:-0}" = "1" ]; then
	echo "Skipping optional e2e apps (KANSO_SKIP_OPTIONAL_APPS=1)"
	exit 0
fi

if [ "$NC_VERSION" != "34" ]; then
	echo "Skipping optional e2e apps: pins target Nextcloud 34, this stack is ${NC_VERSION}"
	exit 0
fi

# Download → copy into the container → unpack → enable. The download is skipped
# only when the container already reports the *pinned* version (custom_apps
# lives in the container's /var/www/html volume, so an install survives a
# restart) — that keeps a re-run cheap without letting a version bump here
# become a silent no-op on a long-lived stack, and re-downloads over a
# half-extracted tree from an interrupted run. `app:enable` is idempotent and
# stays unconditional so a present-but-disabled app gets re-enabled.
#
# A failed DOWNLOAD warns and returns instead of aborting: these apps are
# optional, the failure mode is known and narrow (exactly one named spec goes
# red), and killing the boot here is worse than booting degraded — this script
# runs BEFORE the notify_push block, so a hard exit meant an offline dev never
# even reached the code that was made forgiving on purpose. CI is unaffected:
# it has github.com egress, so it takes the same path it always did, and a
# genuine download failure there still surfaces as the named spec failing.
install_optional_app() {
	app="$1"
	version="$2"
	url="$3"
	spec="$4"

	if docker exec -u www-data kanso-dev php occ app:list | grep -q "^  - $app: $version"; then
		echo "Optional app '$app' already at the pinned v${version}, skipping download"
	else
		echo "Installing optional app '$app' v${version} (for the e2e specs)..."
		if ! curl -fsSL "$url" -o "/tmp/$app.tar.gz"; then
			echo >&2
			echo "WARNING: could not download $app from $url — continuing without it." >&2
			echo "  * The stack still boots and Kanso itself works normally." >&2
			echo "  * $spec will FAIL until $app is installed." >&2
			echo "  * Set KANSO_SKIP_OPTIONAL_APPS=1 to skip this step quietly." >&2
			echo >&2
			return 0
		fi
		docker cp "/tmp/$app.tar.gz" "kanso-dev:/tmp/$app.tar.gz"
		docker exec kanso-dev bash -c "tar -xzf /tmp/$app.tar.gz -C /var/www/html/custom_apps && chown -R www-data:www-data /var/www/html/custom_apps/$app"
	fi
	docker exec -u www-data kanso-dev php occ app:enable "$app"
}

install_optional_app deck "$DECK_VERSION" "$DECK_URL" tests/e2e/deck-import.spec.js
install_optional_app contacts "$CONTACTS_VERSION" "$CONTACTS_URL" tests/e2e/card-contacts.spec.js
