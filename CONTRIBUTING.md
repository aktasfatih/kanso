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
only be merged once **all** CI checks pass — `cs-check`, `psalm`, `unit-php` (on
PHP 8.2 and 8.3), `unit-mcp` (the `mcp/` Python server's pytest suite),
`build-frontend`, and the full `e2e` suite. Open your PR
against `main`; CI runs automatically on push.

## Translations

Kanso follows each user's Nextcloud language. Adding or improving a translation
needs no code — edit `translationfiles/<lang>/kanso.po` and run
`npm run l10n:compile`. See [docs/TRANSLATING.md](docs/TRANSLATING.md) for the
full workflow (German ships today; other languages are very welcome).

## Staying current with Nextcloud

[Dependabot](.github/dependabot.yml) opens weekly PRs for our composer, npm and
GitHub-Actions dependencies. The one to watch is **`nextcloud/ocp`**: its major
version tracks the Nextcloud major line, so an `ocp` **major** PR (e.g. `^34 →
^35`) means a new Nextcloud is out. That is the cue to run the compatibility
pass — verify the app on the new version, then raise `max-version` in
`appinfo/info.xml` and bump the CI/dev images (Dependabot can't edit those). No
Dependabot PR auto-merges; each one runs the full CI (psalm, PHPUnit, e2e) and
is reviewed before merge.

## Commit messages

Conventional-ish messages (`fix:`, `feat:`, `docs:`, `chore:`…), one logical
change per commit.
