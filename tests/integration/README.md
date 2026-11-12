# Card move concurrency

Run this harness against an explicitly selected, disposable Nextcloud installation
with Kanso enabled and all checkout migrations applied. It creates users, boards,
cards and attachment bytes in that installation. It does not reset an installation
or connect to any other database.

```sh
docker exec -u www-data \
  -e NEXTCLOUD_ROOT=/var/www/html \
  -e KANSO_INTEGRATION_DISPOSABLE=1 \
  YOUR_DISPOSABLE_NEXTCLOUD_CONTAINER \
  php /var/www/html/custom_apps/kanso/tests/integration/card-move-concurrency.php
```

Add `-e NEXTCLOUD_CONFIG_DIR=/path/to/isolated/config` when the container hosts
several disposable configurations. Run once per supported database driver:
SQLite, PostgreSQL and MySQL/MariaDB. The MySQL/MariaDB test user needs `PROCESS`
on its disposable server to inspect InnoDB lock waits.
MySQL 8 uses `performance_schema.data_lock_waits` and also needs read access
to `performance_schema`.

Each race uses separate PHP processes and real Nextcloud database connections.
Barriers pause actual service mutations before their production guard commits.
PostgreSQL activity and MariaDB InnoDB status prove that the contender reached a blocked
database statement before releasing the holder. SQLite uses a short busy timeout
and checks the actual lock rejection, then retries after the holder commits.
Ordinary writes on unrelated cards must complete concurrently on SQL servers.

The harness covers both write/move orders for comments, resolution, reactions,
Files links, uploads, attachments copied from Files, attachment removal, title,
labels, assignees, checklists, field values, time entries, subscriptions, reminders,
reviews, projects, relations, parent changes and recurrence updates. Additional
checks exercise destination field deletion, both board permission gates, copied
object cleanup, nested attachment rollback, post-commit callback ordering and
import/duplication with board settings and card data.

A run prints and verifies the reflected source path and SHA-256 of the production
guard and every participating service before testing, preventing an installed
App Store copy from silently replacing the checkout under test.
Every worker verifies the same source paths and hashes before its operation starts.
A successful run ends with `OK <driver>: <count> integration checks` and exits zero.
The fixture boards remain in the disposable installation for inspection.

## Card references and allocation

Run this harness against a disposable installation after applying the checkout's
migrations:

```sh
docker exec -u www-data \
  -e NEXTCLOUD_ROOT=/var/www/html \
  -e KANSO_INTEGRATION_DISPOSABLE=1 \
  YOUR_DISPOSABLE_NEXTCLOUD_CONTAINER \
  php /var/www/html/custom_apps/kanso/tests/integration/card-references.php
```

Use the isolated configuration and database-driver permissions described above.
Human aliases retain the prefix used at the move, within the original board's
context, with no expiry. Resolution requires READ on that live source board and
READ plus card visibility at the final destination. Restoring an original cancels
its alias and uses the source board's current prefix. Source-card purge retains
aliases; source-board deletion disables its human-reference context while numeric
chains survive. Destination trash, deletion or purge makes the reference unavailable.
Counters retain committed allocation history through card purge; imports may leave
gaps. Upgrade backfill seeds missing counters above retained card identities,
surviving numbers and aliases. It cannot recover history erased from every source
or unknown older prefixes.

The harness checks monotonic numbering after permanent deletion, import,
duplication and CSV intake, plus allocator initialization through a legacy import
that owns its outer transaction. Separate PHP processes exercise simultaneous card
creation in the same and different stacks, with database lock evidence on the
sequence allocator. SQLite's lock rejection and retry are checked explicitly;
a bounded same-stack sort-key conflict can also be retried after commit.

Move checks cover chains, source-card purge, duplicate board prefixes, prefix
renames, restored originals, destination deletion and source-board purge. The
real services and controller must enforce source-board access, destination READ
and private/internal card visibility. Synthetic legacy collisions check live-card
precedence and hidden-card denial. Migration backfill is rerun after clearing
only owned fixture metadata and counters, checking recovery from surviving
sources under their current prefix, preservation of existing counters and
numeric-only purged-source aliases. Missing historical prefixes cannot be inferred.
Source paths and SHA-256 fingerprints are verified in the parent and worker
processes. A successful run ends with
`OK <driver>: <count> reference integration checks`; owned fixture boards, aliases
and the temporary reader account are removed in `finally`.

## Reference upgrade boundaries

```sh
docker exec -u www-data \
  -e NEXTCLOUD_ROOT=/var/www/html \
  -e KANSO_INTEGRATION_DISPOSABLE=1 \
  YOUR_DISPOSABLE_NEXTCLOUD_CONTAINER \
  php /var/www/html/custom_apps/kanso/tests/integration/card-reference-upgrade.php
```

Run once per disposable SQLite, PostgreSQL and MySQL/MariaDB configuration.
Real card creation, trash and purge paths construct legacy highest-number
deletions, including a board with every card purged. Removing only each owned
fixture's counter simulates the pre-counter cutover. The actual migration must
seed past the retained native identity; surviving `MAX(board_seq)` alone fails
these checks. Existing counters stay unchanged and fresh boards still start at one.

The harness also creates a separate temporary SQLite file with minimal tables,
using the selected configuration's table prefix and Nextcloud's connection and
schema APIs. `PRAGMA database_list` must identify that newly owned file before any
schema change. It runs the unchanged estimate-widening migration against that file.
This reproduces a concrete limit: SQLite can lose a purged identity during a
pre-counter table rebuild, leaving no retained row or alias from which to recover
that old reference. A historical number may then be reissued at cutover. The
harness prints the lost value and the bounded residual
explicitly; it does not claim that the upgrade reconstructs erased history.
After cutover, the durable board counter must survive another actual card-table
widening and purge even when SQLite's card identity falls again.

Source paths and SHA-256 hashes are printed and verified. Only owned boards,
counters and the new SQLite file are changed; fixtures are removed in `finally`.
The shared session card schema, published migrations and global configuration
are never changed. A successful run ends with `OK <driver>: <count> upgrade checks`.

## Webhook availability

Run against a disposable installation with Kanso enabled and all checkout
migrations applied:

```sh
docker exec -u www-data \
  -e NEXTCLOUD_ROOT=/var/www/html \
  -e KANSO_INTEGRATION_DISPOSABLE=1 \
  YOUR_DISPOSABLE_NEXTCLOUD_CONTAINER \
  php /var/www/html/custom_apps/kanso/tests/integration/webhook-availability.php
```

The harness temporarily enables Nextcloud brute-force protection and lowers its
attempt threshold, then restores the original configuration in `finally`. It
uses the installed brute-force middleware and throttler, the checkout's actual
controller and services, and the reserved fixture IP `198.51.100.74`. An attributed
control action must return `429` from a populated bucket, proving that middleware
protection is active. The harness resets only its fixture IP/action attempts;
it refuses to run if that pair already has attempts or is allowlisted.

For both GitHub and Forgejo, it rejects 20 bad signatures without creating cards,
then accepts 100 signed deliveries across two boards with exactly one card and
link per issue. It also checks pre-existing shared lockouts, missing/tampered
signatures, board/provider secret isolation, disabled hooks and anonymous settings
permission denial. Class source paths are checked and SHA-256 fingerprints
printed so an installed App Store copy cannot silently replace the checkout.
Fixture boards remain for inspection. This exercises middleware dispatch
directly; the ordinary webhook e2e specs cover HTTP routing and body capture.
