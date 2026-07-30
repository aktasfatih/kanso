<!--
  - SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Releasing Kanso to the Nextcloud App Store

This is the end-to-end checklist for cutting a Kanso release and publishing it
to [apps.nextcloud.com](https://apps.nextcloud.com). App id: **`kanso`**.

Kanso autoloads **no** Composer packages at runtime (the app framework loads
`lib/` for us, and `composer.json` has no `require` block), so the release
tarball ships **without `vendor/`**. It contains only `appinfo/ lib/ js/ img/
templates/` plus `README.md`, `CHANGELOG.md`, `LICENSE` and the `LICENSES/` +
`REUSE.toml` licensing metadata.

## One-time setup: the app signing certificate

App Store apps must be signed with a certificate issued by Nextcloud. This is a
**human-gated** step — you request it once and reuse it for every release.

1. Generate a private key and a certificate signing request (CSR). The CN
   **must** be the app id, `kanso`:

   ```sh
   openssl req -nodes -newkey rsa:4096 \
     -keyout kanso.key -out kanso.csr \
     -subj "/CN=kanso"
   ```

2. Keep `kanso.key` **secret and offline**. Never commit it. (`*.key` / `*.crt`
   are ignored by `.gitignore`.)

3. Open a pull request adding `kanso.csr` to the
   [nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests)
   repo under `kanso/kanso.csr`, following that repo's README. Once merged,
   download the issued `kanso.crt`.

4. Register the app id on <https://apps.nextcloud.com> (App id `kanso` is
   already confirmed available) and upload/keep the public certificate on your
   account so the store can verify uploads.

## Store the signing material as CI secrets (optional but recommended)

To let the release workflow sign automatically, add two repository secrets
(Settings → Secrets and variables → Actions):

- `APP_PRIVATE_KEY` — the full PEM contents of `kanso.key`
- `APP_CERTIFICATE` — the full PEM contents of `kanso.crt`

The workflow only signs when `APP_PRIVATE_KEY` is present; otherwise it still
builds a valid unsigned tarball.

## Cut a release

1. Update the version in `appinfo/info.xml` (`<version>`) and `package.json`,
   and add a dated section to `CHANGELOG.md`.
2. Commit, then tag and push:

   ```sh
   git tag v0.9.0
   git push origin v0.9.0
   ```

3. The **Release** GitHub Actions workflow (`.github/workflows/release.yml`)
   builds the frontend, runs `scripts/build-release.sh`, signs if secrets are
   set, and attaches `kanso.tar.gz` (+ `kanso.tar.gz.sig.base64`) to a GitHub
   Release.

### Building / signing locally

You can produce the exact same artifact by hand:

```sh
# Unsigned (smoke-testing the package):
bash scripts/build-release.sh

# Signed (needs your key, cert, and a Nextcloud occ):
OCC=/path/to/nextcloud/occ \
  APP_PRIVATE_KEY_FILE=/secure/kanso.key \
  APP_CERTIFICATE_FILE=/secure/kanso.crt \
  bash scripts/build-release.sh
```

Signing does two independent things:

1. **Embeds `appinfo/signature.json`** inside the app *before* tarring, via
   `occ integrity:sign-app --path=<staged-app> --privateKey=kanso.key
   --certificate=kanso.crt`. This is what Nextcloud's integrity checker
   validates after install.
2. **Writes a detached store signature** `build/kanso.tar.gz.sig.base64`:

   ```sh
   openssl dgst -sha512 -sign kanso.key build/kanso.tar.gz | openssl base64
   ```

   The App Store's upload API needs this base64 signature of the tarball.

## Upload to the App Store

Publish the release via the App Store API (or the web upload form), providing
the download URL of `kanso.tar.gz` and the detached base64 signature:

```sh
curl -X POST https://apps.nextcloud.com/api/v1/apps/releases \
  -u "YOUR_STORE_USER:YOUR_STORE_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "download": "https://github.com/aktasfatih/kanso/releases/download/v0.9.0/kanso.tar.gz",
    "signature": "'"$(cat build/kanso.tar.gz.sig.base64)"'"
  }'
```

The store re-downloads the tarball, verifies the signature against your
registered certificate, parses `appinfo/info.xml`, and publishes the release.

## Pre-flight checklist

- [ ] `appinfo/info.xml` `<version>` bumped and valid; `<dependencies>` still
      `min-version="30" max-version="32"`.
- [ ] `CHANGELOG.md` has a dated entry for the new version.
- [ ] `README.md` / `info.xml` copy still matches the shipped feature set.
- [ ] `scripts/build-release.sh` runs clean and the tarball installs on a stock
      Nextcloud (see the smoke test below).
- [ ] The tarball contains **no** `node_modules`, `src`, `tests`, `dev`, `vendor`,
      `.git`, `.github`, `.claude`, or source maps.

### Local install smoke test

```sh
bash scripts/build-release.sh
tar -xzf build/kanso.tar.gz -C /path/to/nextcloud/custom_apps
sudo -u www-data php /path/to/nextcloud/occ app:enable kanso
```
