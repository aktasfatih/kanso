// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Shared e2e helpers.
 *
 * Every spec used to copy-paste its own BASE/HEADERS/AUTH constants plus
 * ncLogin() and a little apiGet/apiPost/apiDelete cluster (116× ncLogin,
 * 40× apiPost, …). This module is the single source of truth for all of it.
 *
 * It is also the seam for running the suite in PARALLEL. Today the suite runs
 * `workers: 1` because every spec acts as the same `admin` user against one
 * shared DB — fixed board names, delete-then-recreate, and per-user aggregate
 * views (my-work / inbox / search) all collide if two specs run at once. The
 * `test` exported here is extended with a worker-scoped `user`/`api` fixture:
 * flip `E2E_ISOLATE=1` and each Playwright worker provisions its own Nextcloud
 * user, so fixed board names and aggregate views become naturally namespaced
 * per worker and `workers` can be raised. With the flag off (the default) the
 * fixtures resolve to `admin` and behaviour is byte-for-byte unchanged.
 */
import { test as base, expect } from '@playwright/test'
import { mkdirSync } from 'node:fs'

export { expect }

export const BASE = process.env.E2E_BASE_URL || 'http://localhost:8891'
export const API = BASE + '/index.php/apps/kanso/api'
export const OCS = BASE + '/ocs/v2.php/cloud'

const JSON_HEADERS = {
	'OCS-APIREQUEST': 'true',
	'Content-Type': 'application/json',
}

export function authFor(user, pass) {
	return 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64')
}

export const ADMIN = { user: 'admin', pass: 'admin' }

/** Fixed superuser auth — use ONLY for genuine admin-only operations
 * (OCS user provisioning, instance config). NOT for "act as the current user". */
export const adminAuth = authFor(ADMIN.user, ADMIN.pass)

/** The current acting user's Basic-auth string. `adminAuth` by default; rebound
 * per worker under E2E_ISOLATE. Bespoke per-spec fetch clients that mean "act as
 * me" (not "act as the superuser") should read THIS at call time, not adminAuth,
 * so their requests match the worker's browser session. */
export let currentAuth = adminAuth

/**
 * Build a Kanso API client bound to a Basic-auth string.
 *   - get/post/patch/put return parsed JSON (throw on non-2xx)
 *   - delete resolves to null (throw on non-2xx)
 *   - send(method, path, body) is the generic throwing form
 *   - raw(method, path, body) returns the Response WITHOUT throwing, for
 *     specs that assert on status codes (e.g. 403/404 egress checks)
 */
export function makeApi(auth = adminAuth) {
	async function raw(method, path, body) {
		return fetch(API + path, {
			method,
			headers: { ...JSON_HEADERS, Authorization: auth },
			body: body === undefined ? undefined : JSON.stringify(body),
		})
	}
	async function send(method, path, body) {
		const r = await raw(method, path, body)
		if (!r.ok) throw new Error(`${method} ${path} → ${r.status}: ${await r.text()}`)
		return method === 'DELETE' ? null : r.json()
	}
	return {
		auth,
		raw,
		send,
		get: (path) => send('GET', path),
		post: (path, body) => send('POST', path, body),
		patch: (path, body) => send('PATCH', path, body),
		put: (path, body) => send('PUT', path, body),
		delete: (path) => send('DELETE', path),
	}
}

/** Admin-bound API client — the drop-in for the old apiGet/apiPost/apiDelete.
 * `let` (not `const`) so the worker isolation fixture can rebind this live
 * binding to the worker's own user; every spec's `import { api }` then follows. */
export let api = makeApi(adminAuth)

/** The current acting user's id. `admin` by default; rebound per worker under
 * E2E_ISOLATE. Use this instead of the literal 'admin' wherever a spec means
 * "myself" (assignee/reviewer/self-mention). Read it at call time (a `const x =
 * me` snapshot taken at import would capture 'admin' before the rebind). */
export let me = ADMIN.user

/** Default second identity for multi-user specs (board sharing / ACL / peer
 * login). The dev stack's shared `tester`; rebound to a per-worker peer under
 * isolation. This is a plain object, not a fixture, so it can't rebind itself —
 * multi-user specs consume the worker-scoped `peer` fixture below instead. */
export const TESTER = { user: 'tester', pass: 'kanso-dev-tester!1' }

/**
 * Drive (or detect) the Nextcloud login. If a live session already exists
 * (the shared storageState from global-setup), this returns immediately.
 * Pass explicit creds to log in as a non-admin — such specs must also opt out
 * of the shared admin storageState via `test.use({ storageState: … })`.
 */
export async function ncLogin(page, { user = ADMIN.user, pass = ADMIN.pass } = {}) {
	// Retry the whole flow: under parallel load a single php-fpm/postgres backend
	// can be slow to process the login POST, so one attempt isn't reliable. The
	// dominant CI flake was `page.click` eating its full 30s "waiting for
	// scheduled navigations to finish" on that slow POST and wedging the worker
	// (every test on it then failing at helpers.js login).
	const attempts = 3
	for (let attempt = 1; attempt <= attempts; attempt++) {
		await page.goto(BASE + '/index.php/login').catch(() => {})
		await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})

		const isLoginPage = await page.locator('#user').isVisible({ timeout: 3000 }).catch(() => false)
		if (!isLoginPage) return // already logged in

		await page.fill('#user', user)
		await page.fill('#password', pass)
		// noWaitAfter: submit WITHOUT auto-waiting on the (possibly slow) post-login
		// navigation — the redirect is driven explicitly below, so a slow backend
		// can't burn the click's timeout budget and abort the whole worker.
		await page.click('button[type=submit]', { noWaitAfter: true }).catch(() => {})

		try {
			await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
			await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
			return
		} catch (e) {
			if (attempt === attempts) {
				throw new Error(`ncLogin: still on the login page after ${attempts} attempts (${e.message})`)
			}
			await page.waitForTimeout(1500) // brief backoff, then retry the whole flow
		}
	}
}

/** Navigate to a board via the SPA hash route. */
export async function gotoBoard(page, boardId) {
	await page.goto(`${BASE}/index.php/apps/kanso#/board/${boardId}`)
}

export function boardUrl(boardId) {
	return `${BASE}/index.php/apps/kanso#/board/${boardId}`
}

/**
 * Provision (idempotently) a Nextcloud user via the OCS provisioning API,
 * using admin. Returns { user, pass, auth, api }. Safe to call repeatedly —
 * a 102 "already exists" is treated as success.
 */
export async function provisionUser(user, pass, { displayName } = {}) {
	const body = new URLSearchParams({ userid: user, password: pass })
	if (displayName) body.set('displayName', displayName)
	const r = await fetch(`${OCS}/users`, {
		method: 'POST',
		headers: {
			'OCS-APIREQUEST': 'true',
			Authorization: adminAuth,
			'Content-Type': 'application/x-www-form-urlencoded',
		},
		body,
	})
	// 200 = created; 996/102 with "already exists" is fine for idempotency.
	if (!r.ok) {
		const text = await r.text()
		if (!/already exists/i.test(text)) {
			throw new Error(`provisionUser(${user}) → ${r.status}: ${text}`)
		}
	}
	const auth = authFor(user, pass)
	return { user, pass, auth, api: makeApi(auth) }
}

export async function deleteUser(user) {
	await fetch(`${OCS}/users/${encodeURIComponent(user)}`, {
		method: 'DELETE',
		headers: { 'OCS-APIREQUEST': 'true', Authorization: adminAuth },
	}).catch(() => {})
}

/**
 * Parallel isolation.
 *
 * With E2E_ISOLATE unset (the default) everything below is admin and the suite
 * is byte-for-byte its old serial self. With E2E_ISOLATE=1 each Playwright
 * worker gets a dedicated, provisioned user `kansoe2e_w<index>` and its browser
 * pages start logged in AS that user, so fixed board names and per-user
 * aggregate views (my-work / inbox / search / pinned) are namespaced per worker
 * and `E2E_WORKERS` can be raised safely. Specs need no changes: the exported
 * `api` live binding is rebound to the worker's client, and `storageState` is
 * overridden per worker — `ncLogin(page)` then just detects the live session.
 */
const ISOLATE = process.env.E2E_ISOLATE === '1'
const WORKER_PASS = 'Kanso-e2e-worker!1'
const ADMIN_STATE = 'tests/e2e/.auth/admin.json'

let testImpl = base.extend({
	// Worker identity, provisioned once per worker. `auto` so it runs at worker
	// setup even for specs that don't destructure it — that's what lets it rebind
	// the module-level `api` binding before any beforeAll/test in the worker.
	user: [async ({}, use, workerInfo) => {
		if (!ISOLATE) {
			await use({ ...ADMIN, auth: adminAuth, api: makeApi(adminAuth) })
			return
		}
		const username = `kansoe2e_w${workerInfo.workerIndex}`
		const provisioned = await provisionUser(username, WORKER_PASS, { displayName: username })
		api = provisioned.api // rebind the exported live binding → specs' `import { api }` follow
		me = username // …and the "current user" id for self-referential specs
		currentAuth = provisioned.auth // …and the auth for bespoke per-spec clients
		await use(provisioned)
	}, { scope: 'worker', auto: true }],

	// Second identity for multi-user specs (board sharing, ACL, peer login).
	// Not auto — only the specs that need it destructure `{ peer }`. Off-isolate
	// it's the shared dev `tester`; on-isolate a per-worker peer so two workers
	// never contend over one secondary account.
	peer: [async ({}, use, workerInfo) => {
		if (!ISOLATE) {
			const auth = authFor(TESTER.user, TESTER.pass)
			await use({ ...TESTER, auth, api: makeApi(auth) })
			return
		}
		const username = `kansoe2e_w${workerInfo.workerIndex}_peer`
		await use(await provisionUser(username, WORKER_PASS, { displayName: username }))
	}, { scope: 'worker' }],
})

if (ISOLATE) {
	testImpl = testImpl.extend({
		// One logged-in browser session per worker, captured once. Depends on
		// `user` so the account exists first; uses a throwaway clean context to
		// drive the real login form, then persists that session to a file.
		workerStorageState: [async ({ user, browser }, use, workerInfo) => {
			mkdirSync('tests/e2e/.auth', { recursive: true })
			const file = `tests/e2e/.auth/worker-${workerInfo.workerIndex}.json`
			const ctx = await browser.newContext()
			try {
				const page = await ctx.newPage()
				await ncLogin(page, { user: user.user, pass: user.pass })
				await ctx.storageState({ path: file })
			} finally {
				await ctx.close()
			}
			await use(file)
		}, { scope: 'worker' }],

		// Override the storageState option so every page in this worker starts as
		// the worker user instead of the shared admin session from the config.
		storageState: async ({ workerStorageState }, use) => {
			await use(workerStorageState)
		},
	})
}

export const test = testImpl
