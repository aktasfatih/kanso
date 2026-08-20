#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Build a runtime-only, App-Store-ready release tarball for Kanso.
#
# Output: build/kanso.tar.gz  — a gzipped tar containing a single top-level
#         kanso/ directory with only the files Nextcloud needs at runtime.
#
# The frontend is built (js/) and, if signing material is available, the app is
# signed (appinfo/signature.json) and a detached store-submission signature is
# written next to the tarball. Signing is skipped cleanly when no key is present,
# so the script always produces an installable (though unsigned) tarball.
#
# Usage:
#   scripts/build-release.sh
#
# Signing (optional, see docs/RELEASING.md). Provide EITHER file paths:
#   APP_PRIVATE_KEY_FILE=/path/kanso.key APP_CERTIFICATE_FILE=/path/kanso.crt \
#     scripts/build-release.sh
# OR the PEM contents directly (used by CI secrets):
#   APP_PRIVATE_KEY="$(cat kanso.key)" APP_CERTIFICATE="$(cat kanso.crt)" \
#     scripts/build-release.sh
#
# Env knobs:
#   OCC        path to a Nextcloud occ (needed to sign; e.g. /var/www/html/occ)
#   SKIP_BUILD=1  reuse the existing js/ instead of running npm run build

set -euo pipefail

APP_ID="kanso"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${ROOT}/build"
STAGE_DIR="${BUILD_DIR}/${APP_ID}"
TARBALL="${BUILD_DIR}/${APP_ID}.tar.gz"

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mwarn:\033[0m %s\n' "$*" >&2; }

cd "$ROOT"

# ---------------------------------------------------------------------------
# 1. Build the frontend (production bundle into js/)
# ---------------------------------------------------------------------------
if [[ "${SKIP_BUILD:-}" == "1" ]]; then
	log "SKIP_BUILD=1 — reusing existing js/"
else
	log "Building frontend (npm ci && npm run build)"
	if [[ -f package-lock.json ]]; then
		npm ci --no-audit --no-fund
	else
		npm install --no-audit --no-fund
	fi
	npm run build
fi

if [[ ! -f "${ROOT}/js/kanso-main.mjs" ]]; then
	echo "ERROR: js/kanso-main.mjs missing after build — aborting." >&2
	exit 1
fi

# ---------------------------------------------------------------------------
# 2. Stage the runtime-only app directory
# ---------------------------------------------------------------------------
log "Staging runtime files into ${STAGE_DIR}"
rm -rf "$BUILD_DIR"
mkdir -p "$STAGE_DIR"

# Runtime files/dirs. Anything not listed here is intentionally excluded:
# node_modules, src, tests, dev, docs, vendor (Kanso autoloads nothing from
# composer at runtime), .git, .github, .claude and all dev/CI/tooling configs.
INCLUDE=(
	appinfo
	lib
	js
	img
	templates
	CHANGELOG.md
	README.md
)
# Optional dirs/files — included only if present.
INCLUDE_OPTIONAL=(
	l10n
	css
	LICENSE
	COPYING
	LICENSES
	REUSE.toml
)

for item in "${INCLUDE[@]}"; do
	if [[ ! -e "$item" ]]; then
		echo "ERROR: required item '$item' missing — aborting." >&2
		exit 1
	fi
	cp -a "$item" "$STAGE_DIR/"
done
for item in "${INCLUDE_OPTIONAL[@]}"; do
	[[ -e "$item" ]] && cp -a "$item" "$STAGE_DIR/"
done

# Belt-and-braces scrub: drop anything that must never ship even if it slipped
# into a copied dir (e.g. source maps, editor/OS cruft, stray dev files).
find "$STAGE_DIR" \
	\( -name '*.map' \
	-o -name '.DS_Store' \
	-o -name '*.cache' \
	-o -name '*.gz' \
	-o -name 'Thumbs.db' \
	-o -name '.env' -o -name '.env.*' \
	\) -type f -delete
# Never ship dev/agent/VCS dirs even if nested.
find "$STAGE_DIR" \
	\( -name '.git' -o -name '.github' -o -name '.claude' \
	-o -name 'node_modules' -o -name 'tests' -o -name '__tests__' \) \
	-type d -prune -exec rm -rf {} +

# ---------------------------------------------------------------------------
# 2b. Rewrite the bundled CHANGELOG.md into the App Store's format (#57)
# ---------------------------------------------------------------------------
# The store reads CHANGELOG.md from the archive and imports the released
# version's notes (nextcloudappstore/api/v1/release/parser.py). It matches
# version headings with `^## (?:\[)?(?:v)?(\d+\.\d+(\.\d+)?)`, keyed on
# appinfo/info.xml's <version>: the heading MUST be level-2 (`## `), but a `[`
# bracket, a `v` prefix and any trailing text (dates, links) are ignored — and
# only the lines BELOW the heading are shown as the notes. semantic-release
# writes minor/major headings as level-1 `# [X.Y.Z](compare-url) (date)`, which
# the `^## ` anchor never matches, so those releases import empty notes. Rewrite
# every version heading in the STAGED copy to `## X.Y.Z - date` (the repo's rich
# human changelog is untouched), BEFORE signing so the rewrite is covered by the
# integrity signature (a later edit would break it).
if [[ -f "$STAGE_DIR/CHANGELOG.md" ]]; then
	log "Normalising bundled CHANGELOG.md to the App Store heading format"
	# `# [X.Y.Z](url) (date)` / `## [X.Y.Z](url)` / `# X.Y.Z (date)` → `## X.Y.Z - date`.
	# Most-specific rule first; once a line is rewritten it no longer matches the
	# looser rules, so no double-substitution.
	sed -i -E \
		-e 's|^#{1,3} \[([0-9]+\.[0-9]+\.[0-9]+)\]\([^)]*\)[[:space:]]*\(([0-9]{4}-[0-9]{2}-[0-9]{2})\).*|## \1 - \2|' \
		-e 's|^#{1,3} \[([0-9]+\.[0-9]+\.[0-9]+)\]\([^)]*\).*|## \1|' \
		-e 's|^#{1,3} ([0-9]+\.[0-9]+\.[0-9]+) \(([0-9]{4}-[0-9]{2}-[0-9]{2})\).*|## \1 - \2|' \
		-e 's|^#{1,3} \[([0-9]+\.[0-9]+\.[0-9]+)\][[:space:]]*-[[:space:]]*([0-9]{4}-[0-9]{2}-[0-9]{2}).*|## \1 - \2|' \
		"$STAGE_DIR/CHANGELOG.md"

	# Sanity-check: the store keys on the info.xml version, so the released
	# version MUST carry a `## X.Y.Z` heading now or the notes come through empty.
	APP_VERSION="$(sed -n 's|.*<version>\([0-9][0-9.]*\)</version>.*|\1|p' "$STAGE_DIR/appinfo/info.xml" | head -1)"
	if [[ -n "$APP_VERSION" ]] && ! grep -qE "^## ${APP_VERSION//./\\.}( |\$)" "$STAGE_DIR/CHANGELOG.md"; then
		warn "CHANGELOG.md has no '## ${APP_VERSION}' heading after the rewrite —"
		warn "the App Store will import empty release notes for v${APP_VERSION}."
	fi
fi

# ---------------------------------------------------------------------------
# 3. Sign the app (optional — needs a private key + certificate + occ)
# ---------------------------------------------------------------------------
KEY_FILE=""
CRT_FILE=""
TMP_KEY=""
TMP_CRT=""
# Must end with status 0: a failing last command in an EXIT trap overrides the
# script's exit code under `set -e` (this broke the unsigned path, where the
# empty-var tests return 1).
cleanup_keys() {
	if [[ -n "$TMP_KEY" ]]; then rm -f "$TMP_KEY"; fi
	if [[ -n "$TMP_CRT" ]]; then rm -f "$TMP_CRT"; fi
}
trap cleanup_keys EXIT

if [[ -n "${APP_PRIVATE_KEY_FILE:-}" && -n "${APP_CERTIFICATE_FILE:-}" ]]; then
	KEY_FILE="$APP_PRIVATE_KEY_FILE"
	CRT_FILE="$APP_CERTIFICATE_FILE"
elif [[ -n "${APP_PRIVATE_KEY:-}" && -n "${APP_CERTIFICATE:-}" ]]; then
	TMP_KEY="$(mktemp)"; TMP_CRT="$(mktemp)"
	chmod 600 "$TMP_KEY" "$TMP_CRT"
	printf '%s' "$APP_PRIVATE_KEY" > "$TMP_KEY"
	printf '%s' "$APP_CERTIFICATE" > "$TMP_CRT"
	KEY_FILE="$TMP_KEY"; CRT_FILE="$TMP_CRT"
fi

# SIGNED is set only once occ integrity:sign-app has actually embedded
# appinfo/signature.json. The detached store signature (step 5) is gated on it,
# so we never emit a .sig.base64 for a tarball that wasn't integrity-signed.
SIGNED=""
if [[ -n "$KEY_FILE" && -n "$CRT_FILE" ]]; then
	if [[ -n "${OCC:-}" ]]; then
		# OCC was set explicitly: an unusable value is an operator error — fail loud.
		if [[ ! -f "$OCC" ]]; then
			echo "ERROR: OCC='$OCC' does not point at an existing occ file." >&2
			exit 1
		fi
		if ! command -v php >/dev/null 2>&1; then
			echo "ERROR: OCC is set but 'php' is not on PATH — cannot sign." >&2
			exit 1
		fi
		log "Signing app with occ integrity:sign-app"
		php "$OCC" integrity:sign-app \
			--path="$STAGE_DIR" \
			--privateKey="$KEY_FILE" \
			--certificate="$CRT_FILE"
		if [[ ! -f "$STAGE_DIR/appinfo/signature.json" ]]; then
			echo "ERROR: signing reported success but appinfo/signature.json is missing." >&2
			exit 1
		fi
		SIGNED=1
	else
		warn "Signing key present but OCC not set — skipping occ sign-app."
		warn "Set OCC=/path/to/occ to embed appinfo/signature.json (and get a store signature)."
	fi
else
	warn "No signing key/cert provided — building an UNSIGNED tarball."
	warn "See docs/RELEASING.md to sign for the App Store."
fi

# ---------------------------------------------------------------------------
# 4. Tar it up (deterministic-ish; top-level kanso/ dir)
# ---------------------------------------------------------------------------
log "Creating ${TARBALL}"
tar -C "$BUILD_DIR" \
	--owner=0 --group=0 --numeric-owner \
	-czf "$TARBALL" "$APP_ID"

# ---------------------------------------------------------------------------
# 5. Detached store-submission signature (openssl) — only for a signed tarball
# ---------------------------------------------------------------------------
# Gated on $SIGNED so we never produce a store signature for a tarball whose
# appinfo/signature.json was never embedded (which the store would reject).
if [[ -n "$SIGNED" ]]; then
	log "Writing detached store signature ${TARBALL}.sig.base64"
	openssl dgst -sha512 -sign "$KEY_FILE" "$TARBALL" \
		| openssl base64 -A > "${TARBALL}.sig.base64"
fi

# ---------------------------------------------------------------------------
# 6. Report
# ---------------------------------------------------------------------------
SIZE="$(du -h "$TARBALL" | cut -f1)"
COUNT="$(tar -tzf "$TARBALL" | grep -vc '/$')"
log "Done: $TARBALL ($SIZE, $COUNT files)"
if [[ -f "${TARBALL}.sig.base64" ]]; then
	log "Store signature: ${TARBALL}.sig.base64"
fi
