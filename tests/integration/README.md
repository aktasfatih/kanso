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
