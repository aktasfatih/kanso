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

## Optional Nextcloud apps some specs need

A few specs exercise integrations with other Nextcloud apps, and fail with a
confusing "button is missing" / "board not found" error if that app isn't
installed rather than saying so:

| Spec | Needs |
| --- | --- |
| [`card-contacts.spec.js`](./card-contacts.spec.js) | `contacts` — `ContactService::isAvailable()` is false without the app, so the picker's search returns an empty list and the spec times out waiting for its option |
| [`deck-import.spec.js`](./deck-import.spec.js) | `deck` — its `beforeAll` seeds a source board through the real Deck API, so the whole describe errors out |
| [`deck-import-ui.spec.js`](./deck-import-ui.spec.js) | `deck` — same reason: it seeds a source board, then drives the import modal to assert the result summary |

`dev/setup.sh` side-loads both (pinned release tarballs) through
[`dev/install-optional-apps.sh`](../../dev/install-optional-apps.sh), and the CI
`e2e` job gets them from the same script, so a local run and CI provision
identically. If you booted with `KANSO_SKIP_OPTIONAL_APPS=1`, run
`./dev/install-optional-apps.sh` against the running stack to add them
afterwards. The pins support Nextcloud **34** only, so on a stack booted with
`NC_VERSION=30..33` the script skips itself and those two specs stay red —
that's expected, not a regression.

## Realtime push (`notify_push`)

[`realtime.spec.js`](./realtime.spec.js)'s push test needs `notify_push`, which
`dev/setup.sh` side-loads from a pinned release tarball (falling back to the
appstore only when that download fails). The pin has to match the
`icewind1991/notify_push` image tag in `dev/docker-compose.yml` —
`notify_push:self-test` compares the two versions and fails on a skew. The
install is best-effort — if the boot printed a `WARNING: could not set up
notify_push`, push is unavailable and the test **fails rather than skips**. Run
the suite with `KANSO_SKIP_NOTIFY_PUSH=1` to skip it and exercise only the
delta-poll fallback.

**Which CI check covers push.** Not this suite. The required `e2e` job sets
`KANSO_SKIP_NOTIFY_PUSH=1` (a tarball download has no business on the critical
path of a ~1.5h required check), so the push-positive test **skips there**.
A separate, smaller `e2e-push` job boots the same stack *with* push and runs
this one spec; it runs in parallel, so it adds nothing to the `e2e` wall-clock.
Until that job has a few green runs it is **not** a required check — so a red
`e2e-push` on a PR is a real signal to read, not a merge blocker.

**"Advertised but dead" is asserted before any spec runs.** Nextcloud advertises
the notify_push capability from a database row written by `notify_push:setup`
and never retracts it, so a stack whose daemon died — or whose apache `/push`
proxy vanished on a container recreate, which is what happened in #10443 — keeps
telling clients to use push while no frame can arrive. Nothing re-runs
`notify_push:self-test` on a plain `docker compose up -d`, so
[`push-health.js`](./push-health.js) runs in Playwright's global setup instead:
if push is advertised, its advertised websocket endpoint must complete a real
handshake, or the run aborts with one message naming the cause (404 → the proxy
is missing from the container; 5xx → the daemon behind it is down). It is silent
on a stack that simply has no push, which is the supported fallback and what CI's
`e2e` job runs.

```sh
npm run check:push                  # same check, standalone, against a booted stack
KANSO_REQUIRE_NOTIFY_PUSH=1 …       # also fail when push isn't advertised at all
KANSO_SKIP_PUSH_HEALTHCHECK=1 …     # bypass the check
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

## Wait budgets — don't hand-roll a short one

`playwright.config.js` gives every assertion 15s (`expect: { timeout: 15_000 }`)
and every test 240s. Those numbers are sized for a **saturated** self-hosted
runner pool, where a round-trip that costs ~0.3s on a dev box measurably costs
1.8-3x more. A hand-written `{ timeout: 5000 }` on a wait that expects something
to **appear** silently opts back out of that headroom — which is how three runs
of the same commit once tripped seven different specs, none of them twice.

So, for a **positive** wait (`toBeVisible`, `toHaveText`, `toHaveValue`,
`toHaveCount(n>0)`, `waitForSelector`, `waitForResponse`, `waitForFunction`):

```js
await expect(tile).toBeVisible()                      // ✅ inherits the 15s global
await expect(tile).toBeVisible({ timeout: 5000 })     // ❌ guard fails the build
await page.waitForSelector('.card-modal', { timeout: 15_000 })  // ✅ see below
```

`page.waitFor*` is **not** covered by `expect.timeout` — with `actionTimeout`
unset it would fall through to the 240s test cap — so those keep an explicit
`{ timeout: 15_000 }` rather than dropping the option.

`expect.poll(fn, { timeout })` and `toPass({ timeout })` follow the same rule,
and they have **no exception for `.not`**. Unlike a matcher, a poll returns the
instant its assertion passes — `expect.poll(...).not.toBe(x)` returns as soon as
the value stops being `x`, it does not spend the budget proving a negative. So
every poll budget is a positive one. (`playwright.config.js` states
`expect.toPass.timeout` alongside `expect.timeout`, because Playwright otherwise
falls back to 0 for `toPass` — "no budget but the 240s test cap" — rather than to
the 15s global.)

None of this applies to a **negative** wait (`not.toBeVisible`,
`toHaveCount(0)`, `state: 'hidden'`) or to `waitForTimeout`: there a short
budget is load-bearing, because the assertion only passes by spending it.
Lengthening those just makes the suite slower.

### …and don't hand-roll one that outlives what it waits on

The mirror-image mistake, and the one the first sweep of this rule made: some
things on screen **dismiss themselves**, so the 15s global is not a safe default
for a wait on one. It is a wait nothing can satisfy once the thing is gone, and
the failure it prints ("not visible") describes something that really did
appear.

Known lifetimes, all of them from the source rather than from memory:

| Thing | Life | Defined in |
| --- | --- | --- |
| Undo toast (`showUndo`) | **10s** | `TOAST_UNDO_TIMEOUT`, `@nextcloud/dialogs/dist/toast.d.ts` |
| Any other toast | **7s** | `TOAST_DEFAULT_TIMEOUT`, same file |
| "Find on board" ring | **2.4s** | `revealTimer`, `src/views/BoardView.vue` |
| Comment deep-link highlight | **4s** | `highlightTimer`, `src/components/CardDetail.vue` |
| "Created" flash on a recurrence rule | **3s** | `src/components/BoardSettingsModal.vue` |
| "Copied!" on the branch-name button | **1.5s** | `branchCopied`, `src/components/CardDetail.vue` |

So a wait on one of those states a budget **under** its life, and says so:

```js
const undoToast = toast(page, 'Card deleted')
// short-budget-ok: the undo toast is gone at 10s (TOAST_UNDO_TIMEOUT)
await expect(undoToast).toBeVisible({ timeout: 8_000 })
```

Two things follow from the same fact and are easy to miss:

- **Order matters.** Anything perishable is asserted *first*. A 10s wait sitting
  in front of a 2.4s ring can consume the ring's whole life and then look for a
  class that was correctly removed.
- **What the budget really has to cover is the action, not the toast.** If the
  action the toast reports is itself slow (a 101-card bulk write runs to ~30s),
  the wait has to outlive the toast — there is no budget that covers a 30s write
  and stays under a 10s toast. Those are annotated `// long-budget-ok: <reason>`
  and are expected to be rare; three exist today, all over 100-card bulk writes.

`npm run lint:e2e-timeouts` enforces all of it (it runs in CI's `build-frontend`
job, so it fails in minutes rather than costing a ~1.7h e2e cycle). It knows a
toast wait by the suite's own `toast()` helper — including through a variable
bound to one — and requires either a budget under 10s or the long-budget escape.
It does **not** know about the other ephemera in the table above; those are on
you, and `// short-budget-ok: <reason>` is how you record the reason:

```js
// short-budget-ok: the banner auto-dismisses at 3s, so a longer wait can't pass
await expect(banner).toBeVisible({ timeout: 2000 })
```

One gap worth knowing about: the guard only inspects the matchers in
`POSITIVE_WAITS` (`toBeVisible`, `toHaveText`, `toHaveValue`, `toHaveCount`,
`waitForSelector`, `waitForResponse`, `waitForFunction`) plus `expect.poll` /
`toPass`. Around 130 hand-rolled sub-15s budgets still sit on `toHaveClass`,
`toContainText`, `toHaveURL`, `toBeInViewport` and friends. Sweeping those is its
own change; until then, apply the rule by hand when you touch one.

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
