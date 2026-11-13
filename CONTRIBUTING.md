# Contributing to Kanso

Bug reports and pull requests are welcome. Kanso is developed in the open and
usable day-to-day; see the [issues](https://github.com/aktasfatih/kanso/issues)
for where help is useful.

## Licensing of contributions

Kanso is licensed under the [AGPL-3.0-or-later](LICENSE), and it always will be
for the community. To keep the project sustainable, it is also offered under a
separate commercial license to organizations that cannot use AGPL software.

**By submitting a contribution, you agree to the [Contributor License Agreement
(CLA.md)](CLA.md).** In short: you keep the copyright to your work, and you
grant the maintainer the right to distribute it under the AGPL and, if needed,
under commercial license terms. This is what lets Kanso stay open source while
funding its own development. Please read [CLA.md](CLA.md) before opening a PR.

## Development

See the **Development** section of the [README](README.md#development) for the
local docker dev stack, and the tooling used (PHPUnit, Playwright, psalm,
php-cs-fixer). Before opening a PR:

- Keep psalm and php-cs-fixer clean.
- Add or update tests for behavior changes (happy path + permission denial for
  API/services).
- Follow the existing code style and the performance conventions documented in
  the README and `CLAUDE.md` (fractional sort keys, summary-only board reads,
  the `kanso_changes` delta log, ETags) — don't regress these.

`main` is a protected branch: every change lands through a pull request and can
only be merged once **all** required CI checks pass — `cs-check`, `psalm`,
`unit-php (8.2)`, `unit-php (8.3)`, `build-frontend`, the full `e2e` suite, and
`upgrade (last release → this branch, with data)`. Open your PR against `main`;
CI runs automatically on push.

`unit-mcp` (the `mcp/` Python server's pytest suite) also runs on every PR, but
it is **advisory for now** — it is not yet in the branch-protection required
list, so a red `unit-mcp` will not block a merge. Adding it there is a
maintainer action in the repository settings that has not happened yet. Please
treat it as required anyway: don't merge on a red `unit-mcp`.

The same applies to the `unit-php (8.4)` and `unit-php (8.5)` legs: they run on
every PR but are not in the required list, so a red one will not block a merge.
Treat them as required anyway: don't merge on a red `unit-php (8.4)` or
`unit-php (8.5)`.

## PHP verification

From the root of your checkout or worktree, build the minimum-PHP tooling image
and run the locked dependencies, PHPUnit, Psalm and style checks:

```sh
docker build --tag kanso-php ./dev/php
docker run --rm --user "$(id -u):$(id -g)" \
  -e COMPOSER_HOME=/tmp/composer -e XDG_CACHE_HOME=/tmp/cache \
  -v "$PWD:/app" kanso-php sh -ec '
    test ! -L vendor
    composer install --no-interaction --no-progress --prefer-dist
    composer check-platform-reqs --lock
    composer test:unit
    composer psalm -- --threads=1 --no-cache
    composer cs:check -- --using-cache=no
  '
```

The image uses PHP 8.2 (the locked Psalm requires 64-bit PHP 8.2.27 or newer).
It asserts every extension required by `composer.json` and `composer.lock`, plus
PDO for the mapper code, matching the explicit set in CI. The base supplies the
XML, multibyte and PDO extensions; the image adds zip. GD is not needed. The build
context is only `dev/php`, with an allowlist that excludes app source and local
environment files.
These checks do not need a running Nextcloud or database.

Each worktree needs its own real `vendor/` directory. A symlink to another
checkout can make Composer's autoloader resolve app classes there. For offline
reuse, first compare both `composer.lock` files and require
`test ! -e vendor && test ! -L vendor` to pass. Then copy with
`command cp -Rf /path/to/checkout/vendor .` and require
`diff -qr /path/to/checkout/vendor vendor` to pass. `command cp -f` bypasses
interactive `cp -i` aliases; verify the copy instead of assuming it succeeded.

## Mobile Enter shortcuts

Text inputs that save on `@keydown.enter` or `@keyup.enter` need a static
`enterkeyhint` such as `enter` or `done`. This also applies to `NcTextField` and
`NcInputField`: mobile keyboards can otherwise show Next and move focus without
sending Enter. Run `npm run test:enterkeyhint` and `npm run lint:enterkeyhint`;
the existing frontend CI job runs both.

The guard parses Vue templates, including IME-helper and arrow callbacks. It
accepts HTML hint keywords and bound string literals; unknown dynamic hints or
spreads require an explanation. Unknown dynamic input types remain checked.
For an intentional exception, place `<!-- enterkeyhint-ok: concrete reason -->`
immediately before that input as a sibling comment. It exempts that input only.

## Writing a migration

Every other CI job installs the branch's own tree onto an empty Nextcloud, so a
migration there only ever meets an **empty** database. Kanso is on the App
Store, and the update path — old data, new code — is the one every existing user
takes. A `NOT NULL` column with no default, a backfill that trips over real
values, a unique index that meets existing duplicates: all of those pass a fresh
install and break a real one.

The `upgrade` CI job is what covers that. It installs the last released tarball,
seeds real data through the API, swaps in this branch's build and runs
`occ upgrade`, then asserts the seeded data still reads back. Run it yourself
exactly as CI does — it takes about a minute once the images are cached:

```sh
npm run build          # the job's own first step; js/ is gitignored
./dev/upgrade-check.sh # DESTRUCTIVE: recreates the dev stack from scratch
```

The seeding lives in `dev/seed.sh`, which lists **exactly which tables it
covers** in its header. If your migration touches a table that is not on that
list, the job cannot protect it: extend the seeder and the list together.
Everything is seeded through the HTTP API so it works on any database; the one
value no API can set (a backdated timestamp) goes through a single helper with a
per-driver branch, the pattern `dev/smoke.sh` already uses.

## Translations

Kanso follows each user's Nextcloud language. Adding or improving a translation
needs no code — edit `translationfiles/<lang>/kanso.po` and run
`npm run l10n:compile`. See [docs/TRANSLATING.md](docs/TRANSLATING.md) for the
full workflow. The languages that ship today are listed under *Localization* in
the [README](README.md) — kept in step with `l10n/` by a check in
`npm run test:l10n` — and more are very welcome.

## Staying current with Nextcloud

[Dependabot](.github/dependabot.yml) opens weekly PRs for our composer, npm and
GitHub-Actions dependencies. The one to watch is **`nextcloud/ocp`**: its major
version tracks the Nextcloud major line, so an `ocp` **major** PR (e.g. `^34 →
^35`) means a new Nextcloud is out. That is the cue to run the compatibility
pass — verify the app on the new version, then raise `max-version` in
`appinfo/info.xml` and bump the CI/dev images (Dependabot can't edit those). No
Dependabot PR auto-merges; each one runs the full CI (psalm, PHPUnit, e2e) and
is reviewed before merge.

`min-version` moves for the same reason, in the other direction: drop a major
once Nextcloud stops supporting it, or sooner if the code uses a server API that
major doesn't have. **Nothing but the install matrix can catch that** — the `ocp`
pin means psalm and PHPUnit always type-check against the *newest* server's API,
never the oldest one we claim. So `dev/smoke.sh` executes real app code (the
boards list and `occ kanso:rebalance`) on every `(version, db)` combo; when you
add a call to a newer server API, that is what turns red.

## Commit messages

Conventional-ish messages (`fix:`, `feat:`, `docs:`, `chore:`…), one logical
change per commit.
