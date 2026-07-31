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

## Commit messages

Conventional-ish messages (`fix:`, `feat:`, `docs:`, `chore:`…), one logical
change per commit.
