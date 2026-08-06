#!/bin/sh
# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Nextcloud's installer refuses to install if it can't write into the bundled
# "apps" directory ("Cannot write into 'apps' directory"). On older majors
# (seen on NC 30/31) the official image's default ownership of /var/www/html/apps
# occasionally isn't writable by the runtime user at install time, which aborts
# the first-boot install. This pre-installation hook (run as root by the image
# entrypoint, before `occ maintenance:install`) makes the directory writable so
# install proceeds on every supported major. No effect once installed.
set -e
if [ -d /var/www/html/apps ]; then
	chown -R www-data:www-data /var/www/html/apps || true
	chmod -R u+rwX /var/www/html/apps || true
fi
