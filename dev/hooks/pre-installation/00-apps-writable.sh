#!/bin/sh
# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Fix "Cannot write into 'apps' directory" on first-boot install.
#
# The official Nextcloud image ships apps.config.php with two apps paths:
#   /var/www/html/apps        writable => false   (bundled apps, read-only)
#   /var/www/html/custom_apps writable => true    (the store/custom target)
# `occ maintenance:install` requires the *writable* path (custom_apps) to be
# writable by the runtime user, otherwise it aborts with the error above.
#
# We mount the app at /var/www/html/custom_apps/kanso, so Docker pre-creates the
# custom_apps directory as root:root. The image entrypoint only chowns/populates
# custom_apps when it is EMPTY (it isn't — it holds our bind mount), so on a
# fresh install custom_apps stays root-owned and the installer fails. This is the
# root cause of the NC 30/31 boot failures.
#
# This hook runs as root (via the image's pre-installation hook) immediately
# before maintenance:install. Make the *writable* path (custom_apps) owned by
# and writable to www-data, and also disable the App Store for good measure so
# the installer never needs to write to the read-only bundled apps dir. No effect
# once installed.
set -e

for dir in /var/www/html/custom_apps /var/www/html/apps; do
	if [ -d "$dir" ]; then
		chown -R www-data:www-data "$dir" || true
		chmod -R u+rwX "$dir" || true
	fi
done

# Belt-and-suspenders: disabling the App Store means the installer does not
# require a writable default apps store dir at all. Written as a config partial
# that maintenance:install reads before running its writability checks.
mkdir -p /var/www/html/config
cat > /var/www/html/config/kanso-appstore.config.php <<'PHP'
<?php
$CONFIG = array (
  'appstoreenabled' => false,
);
PHP
chown www-data:www-data /var/www/html/config/kanso-appstore.config.php || true
