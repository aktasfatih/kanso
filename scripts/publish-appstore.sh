#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Publish a built + signed release to the Nextcloud App Store
# (https://apps.nextcloud.com). Called from semantic-release's success step,
# AFTER the GitHub release exists — the store re-downloads the tarball from the
# GitHub release asset URL and verifies the detached signature against the
# certificate registered on the store account.
#
# Requires (from the CI env):
#   NC_APPSTORE_TOKEN   App Store API token. If empty, this is a clean no-op so
#                       the pipeline still works before the secret is configured.
#   GITHUB_REPOSITORY   owner/repo (set automatically by GitHub Actions).
#
# Usage: scripts/publish-appstore.sh 0.9.38

set -euo pipefail

VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
	echo "ERROR: usage: $0 <version>" >&2
	exit 1
fi

if [[ -z "${NC_APPSTORE_TOKEN:-}" ]]; then
	echo "==> NC_APPSTORE_TOKEN not set — skipping App Store upload (GitHub release still published)."
	exit 0
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SIG_FILE="${ROOT}/build/kanso.tar.gz.sig.base64"
if [[ ! -f "$SIG_FILE" ]]; then
	echo "ERROR: $SIG_FILE missing — the tarball was not signed, cannot publish to the store." >&2
	echo "       (App Store uploads require a signed release; check APP_PRIVATE_KEY/OCC.)" >&2
	exit 1
fi

REPO="${GITHUB_REPOSITORY:-aktasfatih/kanso}"
DOWNLOAD_URL="https://github.com/${REPO}/releases/download/v${VERSION}/kanso.tar.gz"
SIGNATURE="$(cat "$SIG_FILE")"

echo "==> Publishing v${VERSION} to apps.nextcloud.com"
echo "    download: ${DOWNLOAD_URL}"

# --fail-with-body: non-2xx exits non-zero AND prints the store's error body so a
# rejection (bad signature, duplicate version, unknown app) is visible in the log.
curl --fail-with-body -sS -X POST https://apps.nextcloud.com/api/v1/apps/releases \
	-H "Authorization: Token ${NC_APPSTORE_TOKEN}" \
	-H "Content-Type: application/json" \
	-d "{\"download\":\"${DOWNLOAD_URL}\",\"signature\":\"${SIGNATURE}\",\"nightly\":false}"

echo
echo "==> App Store upload accepted for v${VERSION}"
