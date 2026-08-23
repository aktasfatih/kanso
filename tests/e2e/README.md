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

- `api` — client for the **current** user: `.get/.post/.patch/.put/.delete`
  (throw on non-2xx), `.send(method, path, body)`, `.raw(method, path, body)`
  (Response, no throw — for status-code assertions).
- `me` — the current user's **id** (use instead of the literal `'admin'` for
  self: `` `/cards/${id}/assignees/${me}` ``). Read it at call time.
- `currentAuth` — the current user's Basic-auth string, for a bespoke per-spec
  `fetch` client that means "act as me". Read at call time.
- `peer` — a **fixture** (destructure `{ peer }`) giving a second identity for
  board-sharing / ACL / peer-login specs: `{ user, pass, auth, api }`.
- `makeApi(auth)`, `authFor(user, pass)`, `ncLogin(page, { user, pass })`,
  `gotoBoard`, `boardUrl`, `provisionUser`, `deleteUser`, `BASE`, `API`, `OCS`.
- `adminAuth` — the real superuser; use ONLY for genuine admin-only ops (OCS
  user provisioning), never as "act as me".

A spec that logs in as a non-admin (its `peer`) must also opt out of the shared
session with `test.use({ storageState: { cookies: [], origins: [] } })`.

## Isolation & parallelism

The suite runs **serial by default** (`workers: 1`). Every spec acts as the same
`admin` user against one shared DB, so concurrent specs would corrupt each
other's board lists and per-user aggregate views (My Work / Inbox / Search).

Set **`E2E_ISOLATE=1`** and the suite becomes genuinely parallel-safe: each
Playwright worker provisions its own Nextcloud user (`kansoe2e_w<n>`), its
browser pages start logged in AS that user (per-worker `storageState`), and the
`api` / `me` / `currentAuth` bindings + the `peer` fixture all resolve to
per-worker identities. Fixed board names and per-user aggregate views are then
namespaced per worker, so `E2E_WORKERS` can be raised:

```sh
npm run test:e2e:parallel        # E2E_ISOLATE=1 E2E_WORKERS=4
# or: E2E_ISOLATE=1 E2E_WORKERS=4 npx playwright test
```

With the flag **off** (the default) every binding resolves to `admin` (and
`peer` to the dev `tester`), so behaviour is byte-for-byte the serial run — the
mechanism is dormant, not a code path specs have to think about.
