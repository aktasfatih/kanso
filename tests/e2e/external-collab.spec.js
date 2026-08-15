// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const ADMIN = 'Basic ' + Buffer.from('admin:admin').toString('base64')
const TESTER = 'Basic ' + Buffer.from('tester:kanso-dev-tester!1').toString('base64')

async function call(auth, method, path, body) {
	return fetch(API + path, {
		method,
		headers: { ...HEADERS, Authorization: auth },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
}

async function api(auth, method, path, body) {
	const r = await call(auth, method, path, body)
	if (!r.ok) throw new Error(`${method} ${path} → ${r.status}: ${await r.text()}`)
	return method === 'DELETE' ? null : r.json()
}

async function loginAs(page, user, pass) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	if (!(await page.locator('#user').isVisible({ timeout: 3000 }).catch(() => false))) return
	await page.fill('#user', user)
	await page.fill('#password', pass)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

// #3744 — external/guest collaboration + fragment-free deep links.
// tester is shared onto the board as an EXTERNAL member with READ|EDIT:
//   - the /card/{id} SERVER route survives the login round-trip and lands
//     the member on the right card (the old #/… fragment links did not);
//   - an external member can edit/comment their visible cards (happy path);
//   - whole-board egress (export/duplicate) is 403 for externals and its UI
//     entries are hidden; board structure (stacks) is internal-only too;
//   - a deep link to a card hidden from the viewer is an existence-safe 404.
test.describe.serial('External collaboration + deep links (#3744)', () => {
	// Logs in as a non-admin (tester) on the default page and asserts the login
	// screen itself, so it must NOT inherit the shared authenticated storageState
	// — otherwise the page starts as admin, the #user login form never shows, and
	// loginAs no-ops.
	test.use({ storageState: { cookies: [], origins: [] } })

	const token = 'xc' + Math.floor(Date.now() / 1000)
	const state = { boardId: 0, stackId: 0, clientCardId: 0, internalCardId: 0 }

	test.beforeAll(async () => {
		const board = await api(ADMIN, 'POST', '/boards', { title: 'ClientCollab ' + token })
		state.boardId = board.id
		const stack = await api(ADMIN, 'POST', '/stacks', { boardId: board.id, title: 'Lane' })
		state.stackId = stack.id

		await api(ADMIN, 'POST', `/boards/${board.id}/acl`, {
			participant: 'tester',
			participantType: 'user',
			permission: 3, // READ | EDIT
			role: 'external',
		})

		// A public card the external member can see…
		const pub = await api(ADMIN, 'POST', '/cards', { stackId: stack.id, title: 'Client card ' + token })
		state.clientCardId = pub.id
		// …and a provider-internal card hidden from them.
		const internal = await api(ADMIN, 'POST', '/cards', { stackId: stack.id, title: 'Provider secret ' + token })
		await api(ADMIN, 'PATCH', `/cards/${internal.id}`, { visibility: 'internal' })
		state.internalCardId = internal.id
	})

	test.afterAll(async () => {
		if (state.boardId) await api(ADMIN, 'DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('deep link survives the login round-trip and opens the right card', async ({ page }) => {
		// Cold hit as a logged-out user: the server route must bounce through
		// the NC login and come BACK to the card - the exact flow hash links
		// break (their fragment is dropped by the login redirect).
		await page.goto(`${BASE}/index.php/apps/kanso/card/${state.clientCardId}`)
		await page.waitForSelector('#user', { timeout: 15_000 })
		await page.fill('#user', 'tester')
		await page.fill('#password', 'kanso-dev-tester!1')
		await page.click('button[type=submit]')

		// Post-login we land on the server route, and the SPA opens the modal.
		await page.waitForURL((url) => url.pathname.includes(`/apps/kanso/card/${state.clientCardId}`), { timeout: 30_000 })
		await page.waitForSelector('.card-modal', { timeout: 15_000 })
		await expect(page.locator('.card-modal')).toContainText('Client card ' + token, { timeout: 15_000 })
	})

	test('deep link to a hidden card is an existence-safe 404', async ({ page }) => {
		await loginAs(page, 'tester', 'kanso-dev-tester!1')
		const response = await page.goto(`${BASE}/index.php/apps/kanso/card/${state.internalCardId}`)
		expect(response.status()).toBe(404)
		await expect(page.locator('body')).toContainText('Card not found')
	})

	test('external member edits and comments a visible card (happy path)', async () => {
		const updated = await api(TESTER, 'PATCH', `/cards/${state.clientCardId}`, {
			title: 'Client card (edited) ' + token,
		})
		expect(updated.title).toBe('Client card (edited) ' + token)

		await api(TESTER, 'POST', `/cards/${state.clientCardId}/comments`, {
			body: 'Looks good from the client side ' + token,
		})
		const comments = await api(TESTER, 'GET', `/cards/${state.clientCardId}/comments`)
		expect(JSON.stringify(comments)).toContain('client side ' + token)
	})

	test('export and duplicate are 403 for the external member (and 200 for internal)', async () => {
		expect((await call(TESTER, 'GET', `/boards/${state.boardId}/export`)).status).toBe(403)
		expect((await call(TESTER, 'POST', `/boards/${state.boardId}/duplicate`, { withCards: true })).status).toBe(403)

		// The internal side keeps its (viewer-scoped, #3743) export.
		const adminExport = await call(ADMIN, 'GET', `/boards/${state.boardId}/export`)
		expect(adminExport.status).toBe(200)
		const cleanup = await call(ADMIN, 'POST', `/boards/${state.boardId}/duplicate`, { withCards: false })
		expect(cleanup.status).toBe(200)
		const dup = await cleanup.json()
		await api(ADMIN, 'DELETE', `/boards/${dup.boardId}`).catch(() => {})
	})

	test('board structure is internal-only: stack create/move 403 for the external member', async () => {
		expect((await call(TESTER, 'POST', '/stacks', { boardId: state.boardId, title: 'Client lane' })).status).toBe(403)
		expect((await call(TESTER, 'POST', `/stacks/${state.stackId}/move`, { afterStackId: null })).status).toBe(403)
		// MANAGE surfaces reject the external too (ACL edit, board settings).
		expect((await call(TESTER, 'POST', `/boards/${state.boardId}/acl`, {
			participant: 'admin',
			participantType: 'user',
			permission: 3,
		})).status).toBe(403)
		expect((await call(TESTER, 'PATCH', `/boards/${state.boardId}`, { title: 'Renamed by client' })).status).toBe(403)
	})

	test('export/duplicate tile-menu entries are hidden for the external member', async ({ page }) => {
		await loginAs(page, 'tester', 'kanso-dev-tester!1')
		await page.goto(`${BASE}/index.php/apps/kanso`)
		await page.waitForSelector(`[data-test="board-options-menu-${state.boardId}"]`, { timeout: 15_000 })
		await page.locator(`[data-test="board-options-menu-${state.boardId}"] button`).first().click()

		// The menu is open (pin entry present) but the egress entries are not.
		await expect(page.locator(`[data-test="toggle-pin-${state.boardId}"]`)).toBeVisible({ timeout: 8_000 })
		await expect(page.locator(`[data-test="tile-export-${state.boardId}"]`)).toHaveCount(0)
		await expect(page.locator(`[data-test="tile-duplicate-with-cards-${state.boardId}"]`)).toHaveCount(0)
	})
})
