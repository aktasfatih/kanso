<!--
  - SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Kanso e2e tests

Playwright specs live here, one `*.spec.js` per feature. Run them against the
local dev stack in `dev/` (never against prod):

```sh
npm run test:e2e                 # full suite, serial (workers: 1)
npx playwright test labels       # a single spec
```

## Shared helpers — use these, don't re-roll them

Every spec imports its plumbing from [`helpers.js`](./helpers.js) instead of
copy-pasting `BASE`/`AUTH`/`ncLogin`/`apiGet` blocks:

```js
import { test, expect, api, ncLogin, boardUrl } from './helpers.js'

const board = await api.post('/boards', { title: 'My Test Board' })
await ncLogin(page)
await page.goto(boardUrl(board.id))
```

- `api` — admin-bound client: `.get/.post/.patch/.put/.delete` (throw on non-2xx),
  `.send(method, path, body)`, `.raw(method, path, body)` (Response, no throw —
  for status-code assertions).
- `makeApi(authFor(user, pass))` — a client for another identity.
- `ncLogin(page, { user, pass })`, `gotoBoard`, `boardUrl`, `provisionUser`,
  `deleteUser`, `BASE`, `API`, `OCS`, `adminAuth`, `authFor`.

A spec that logs in as a non-admin must also opt out of the shared admin session
with `test.use({ storageState: { cookies: [], origins: [] } })`.

## Isolation & parallelism

The suite runs **serial by default** (`workers: 1`). Every spec acts as the same
`admin` user against one shared DB, so concurrent specs would corrupt each
other's board lists and per-user aggregate views (My Work / Inbox / Search).

The suite is **parallel-ready**: `helpers.js` exposes a worker-scoped `user`
fixture. With `E2E_ISOLATE=1` each Playwright worker provisions its own
Nextcloud user (`kansoe2e_w<n>`), which namespaces all of that per worker, so
workers can be raised safely:

```sh
npm run test:e2e:parallel        # E2E_ISOLATE=1 E2E_WORKERS=4
# or: E2E_ISOLATE=1 E2E_WORKERS=4 npx playwright test
```

With the flag off, the `user`/`api` fixtures resolve to `admin` and behaviour is
identical to the serial run.
