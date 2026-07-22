# Kanso

Fast open-source kanban boards for [Nextcloud](https://nextcloud.com). Early development.

Kanso is a from-scratch kanban app built for speed: instant drag & drop with
optimistic updates, payloads sized for large boards, and realtime sync. It is
an independent alternative to Deck and does not depend on it.

## Development

Everything runs against a throwaway local Nextcloud — never against a real
instance. Requires Docker and Node 20+.

```sh
npm install
npm run build          # or: npm run watch
cd dev && ./setup.sh   # boots NC 32 + postgres, enables the app
```

Then open http://localhost:8891 (login `admin` / `admin`, test user
`tester` / `kanso-dev-tester!1`). The checkout is mounted as `custom_apps/kanso`, so a
rebuild (`npm run build`) plus a browser reload picks up frontend changes; PHP
changes apply immediately. `docker compose down` in `dev/` resets the instance.

PHP tooling runs via Docker (no host PHP needed):

```sh
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
  -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app \
  php:8.2-cli-alpine php vendor/bin/php-cs-fixer fix --dry-run
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app \
  php:8.2-cli-alpine php vendor/bin/psalm
```

## License

AGPL-3.0-or-later
