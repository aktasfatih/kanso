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

## Repository secrets

Add these under Settings → Secrets and variables → Actions. The release job
degrades gracefully as each is added, so you can wire them up incrementally:

- `APP_PRIVATE_KEY` — full PEM contents of `kanso.key`. **Signing is gated on
  this.** Absent → the job builds a valid *unsigned* tarball and skips the store
  upload (the store requires a signature).
- `APP_CERTIFICATE` — full PEM contents of `kanso.crt`.
- `NC_APPSTORE_TOKEN` — Nextcloud App Store API token. Absent → the GitHub
  release is still published, but `scripts/publish-appstore.sh` is a clean no-op
  (no store upload).
- `RELEASE_TOKEN` — a PAT (or GitHub App token) allowed to **push to protected
  `main`**. semantic-release commits the version bump + changelog back to `main`;
  the default `GITHUB_TOKEN` push is rejected by branch protection. Create a
  fine-grained PAT with **Contents: read/write** on the repo, and add its
  identity to the branch-protection *bypass* list (Settings → Branches →
  `main` → "Allow specified actors to bypass required pull requests"). Absent →
  everything runs but the final push to `main` fails.

## Cut a release

Releases are automated with **semantic-release** — you do **not** edit the
version or the changelog by hand, and you do **not** create tags. Both are
derived from the Conventional-Commit history since the last release tag:

| Commit type on `main`                | Bump               |
| ------------------------------------ | ------------------ |
| `fix:` …                             | patch (0.9.37→0.9.38) |
| `feat:` …                            | minor (0.9.37→0.10.0) |
| `feat!:` / body `BREAKING CHANGE:`   | major (0.9.37→1.0.0)  |
| `chore:`/`test:`/`refactor:`/`ci:`/`docs:` | no release      |

To cut a release: **Actions → Release → "Run workflow"** on `main`. The
**Release** workflow (`.github/workflows/release.yml`) then:

1. Computes the next version from the commits since the last tag.
2. Updates `CHANGELOG.md` (generated from those commit messages).
3. Stamps the version into `appinfo/info.xml` + `package.json`
   (`scripts/apply-version.sh`), then builds + signs the tarball
   (`scripts/build-release.sh`).
4. Creates the git tag `vX.Y.Z` and the GitHub Release with the tarball +
   detached signature attached.
5. Uploads to the App Store (`scripts/publish-appstore.sh`).
6. Commits `chore(release): X.Y.Z [skip ci]` back to `main` with the bumped
   files. The `[skip ci]` keeps CI from re-running on that commit.

> To make releases fully continuous (fire on every push to `main` instead of the
> manual button), uncomment the `push: branches: [main]` trigger in
> `release.yml`.

Preview what the next release *would* be without publishing anything:

```sh
npm run release:dry
```

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

Automated by `scripts/publish-appstore.sh` in the release job's success step
(after the GitHub Release exists, so the store can fetch the asset). It POSTs the
release-asset download URL + the detached base64 signature to
`https://apps.nextcloud.com/api/v1/apps/releases`; the store re-downloads the
tarball, verifies the signature against your registered certificate, parses
`appinfo/info.xml`, and publishes. It no-ops cleanly when `NC_APPSTORE_TOKEN` is
unset.

To upload a build by hand (e.g. the token isn't configured yet):

```sh
NC_APPSTORE_TOKEN="$YOUR_STORE_TOKEN" GITHUB_REPOSITORY=aktasfatih/kanso \
  bash scripts/publish-appstore.sh 0.9.38
```

## Pre-flight checklist

Version and changelog are automated, so most of the old manual gates are gone.
What still matters before you press *Run workflow*:

- [ ] The releasable commits since the last tag have **correct Conventional
      types** (`fix:`/`feat:` for user-facing; a mistyped `chore:` won't ship).
      `npm run release:dry` shows the computed version + notes.
- [ ] `appinfo/info.xml` `<dependencies>` still `min-version="30" max-version="34"`.
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
