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
export const adminAuth = authFor(ADMIN.user, ADMIN.pass)

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

/** Admin-bound API client — the drop-in for the old apiGet/apiPost/apiDelete. */
export const api = makeApi(adminAuth)

/**
 * Drive (or detect) the Nextcloud login. If a live session already exists
 * (the shared storageState from global-setup), this returns immediately.
 * Pass explicit creds to log in as a non-admin — such specs must also opt out
 * of the shared admin storageState via `test.use({ storageState: … })`.
 */
export async function ncLogin(page, { user = ADMIN.user, pass = ADMIN.pass } = {}) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})

	const isLoginPage = await page.locator('#user').isVisible({ timeout: 3000 }).catch(() => false)
	if (!isLoginPage) return // already logged in

	await page.fill('#user', user)
	await page.fill('#password', pass)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
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
 * Worker-scoped identity. With E2E_ISOLATE unset (default) this is admin and
 * costs nothing. With E2E_ISOLATE=1 each worker gets a dedicated, provisioned
 * user `kansoe2e_w<index>` so parallel workers never share board lists or
 * per-user aggregate views.
 */
const ISOLATE = process.env.E2E_ISOLATE === '1'
const WORKER_PASS = 'Kanso-e2e-worker!1'

export const test = base.extend({
	// eslint-disable-next-line no-empty-pattern
	user: [async ({}, use, workerInfo) => {
		if (!ISOLATE) {
			await use({ ...ADMIN, auth: adminAuth, api })
			return
		}
		const username = `kansoe2e_w${workerInfo.workerIndex}`
		const provisioned = await provisionUser(username, WORKER_PASS, { displayName: username })
		await use(provisioned)
		// Left in place between runs on purpose: re-provision is idempotent and
		// tearing down would race sibling specs still mid-flight in CI retries.
	}, { scope: 'worker' }],

	// Per-test API client bound to the worker identity. Drop-in for module `api`.
	api: async ({ user }, use) => {
		await use(user.api)
	},
})
