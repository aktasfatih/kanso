#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Write a release version into the two files that must carry it:
#   - appinfo/info.xml  <version>…</version>   (what Nextcloud + the App Store read)
#   - package.json      "version": "…"          (kept in sync for tooling)
#
# semantic-release owns the version (it is derived from the commit history since
# the last tag), so this script is called from the release pipeline's prepare
# step with the computed version — it is NOT a hand-run bump. See docs/RELEASING.md.
#
# Usage: scripts/apply-version.sh 0.9.38

set -euo pipefail

VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
	echo "ERROR: usage: $0 <version>  (e.g. 0.9.38)" >&2
	exit 1
fi
# Guard against a malformed version leaking into info.xml/the store.
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-.+][0-9A-Za-z.-]+)?$ ]]; then
	echo "ERROR: '$VERSION' is not a semver version." >&2
	exit 1
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# info.xml: replace the single top-level <version>…</version>.
sed -i -E "s|<version>[^<]*</version>|<version>${VERSION}</version>|" appinfo/info.xml

# package.json: replace the top-level "version": "…" (the only such key at root).
sed -i -E "0,/\"version\": \"[^\"]*\"/s//\"version\": \"${VERSION}\"/" package.json

echo "==> applied version ${VERSION} to appinfo/info.xml and package.json"
