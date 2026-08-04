// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const AUTH = 'Basic ' + Buffer.from('admin:admin').toString('base64')

// Authenticated API call (as admin, who owns/manages the board).
async function api(method, path, body) {
	const r = await fetch(API + path, {
		method,
		headers: { ...HEADERS, Authorization: AUTH },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	const text = await r.text()
	return { ok: r.ok, status: r.status, body: text ? JSON.parse(text) : null }
}

// UNAUTHENTICATED fetch of the public JSON payload (no session, no OCS header).
async function fetchPublic(token) {
	const r = await fetch(`${API}/public/${encodeURIComponent(token)}`, {
		headers: { 'Content-Type': 'application/json' },
	})
	const text = await r.text()
	return { status: r.status, body: text ? JSON.parse(text) : null }
}

// Public / read-only board share links (#3531). A MANAGE user mints a token; an
// unauthenticated reader gets a STRIPPED read-only board; disabling 404s it.
test.describe('Public read-only board share', () => {
	let boardId = 0
	let todoStackId = 0
	let cardId = 0
	let token = ''

	test.beforeAll(async () => {
		boardId = (await api('POST', '/boards', { title: 'Public Share E2E' })).body.id
		todoStackId = (await api('POST', '/stacks', { boardId, title: 'To do' })).body.id
		cardId = (await api('POST', '/cards', { stackId: todoStackId, title: 'Public visible card' })).body.id
		// Add people/comments that MUST NOT surface on the public view.
		await api('PUT', `/cards/${cardId}/assignees/admin`)
		await api('POST', `/cards/${cardId}/comments`, { message: 'internal comment SHOULD NOT LEAK' })
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`)
	})

	test('off by default; config reports disabled', async () => {
		const cfg = (await api('GET', `/boards/${boardId}/public-share`)).body
		expect(cfg.enabled).toBe(false)
		expect(cfg.url).toBeFalsy()
	})

	test('MANAGE enables a link and gets a public URL + token', async () => {
		const res = await api('POST', `/boards/${boardId}/public-share`)
		expect(res.status).toBe(200)
		expect(res.body.enabled).toBe(true)
		expect(res.body.token).toBeTruthy()
		expect(res.body.token.length).toBeGreaterThanOrEqual(32)
		expect(res.body.url).toContain('/p/')
		token = res.body.token
	})

	test('unauthenticated fetch returns the STRIPPED read-only payload', async () => {
		const res = await fetchPublic(token)
		expect(res.status).toBe(200)
		expect(res.body.board.title).toBe('Public Share E2E')

		// The board object carries no owner / acl / token / webhook.
		expect(Object.keys(res.body.board).sort()).toEqual(['color', 'prefix', 'title'])

		const card = res.body.cards.find((c) => c.title === 'Public visible card')
		expect(card).toBeTruthy()

		// No people, no comments, no internal metadata anywhere in the payload.
		const json = JSON.stringify(res.body)
		expect(json).not.toContain('admin') // no assignee / owner uid
		expect(json).not.toContain('SHOULD NOT LEAK') // no comments
		expect(card.assignees).toBeUndefined()
		expect(card.assigneeIds).toBeUndefined()
		expect(card.comments).toBeUndefined()
		expect(card.commentCount).toBeUndefined()
		expect(card.owner).toBeUndefined()
		expect(card.reviewState).toBeUndefined()
		expect(res.body.acl).toBeUndefined()
		expect(res.body.subscription).toBeUndefined()
	})

	test('the public page renders read-only with no edit affordances or people', async ({ page }) => {
		await page.goto(`${BASE}/index.php/apps/kanso/p/${token}`)
		await expect(page.locator('.public-board__title')).toHaveText('Public Share E2E')
		await expect(page.locator('.public-board__badge')).toContainText('Read-only')
		// The board CSS must actually load (it ships in public.php, since the build
		// merges all entry CSS into the authenticated main bundle the public page
		// never loads). Assert the kanban layout, not a plain text list.
		await expect(page.locator('.public-board__columns')).toHaveCSS('display', 'flex')
		await expect(page.locator('.public-card__title').filter({ hasText: 'Public visible card' })).toBeVisible()
		// No comment box, no assignee avatars, no comment text.
		await expect(page.locator('body')).not.toContainText('SHOULD NOT LEAK')
		await expect(page.locator('.public-card__title')).toHaveCount(1)
	})

	test('no mutation is possible via the public routes', async () => {
		// There is no public write route; a POST to the data route is a 404
		// (route only registered for GET), and the authenticated mutation routes
		// still require a session (401/403 without auth).
		const noSessionPatch = await fetch(`${API}/cards/${cardId}`, {
			method: 'PATCH',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ title: 'hacked' }),
		})
		expect([401, 403, 404]).toContain(noSessionPatch.status)
		// The card title is unchanged.
		const card = (await api('GET', `/cards/${cardId}`)).body
		expect(card.title).toBe('Public visible card')
	})

	test('rotating the link invalidates the previous token', async () => {
		const old = token
		const res = await api('POST', `/boards/${boardId}/public-share`)
		expect(res.body.token).toBeTruthy()
		expect(res.body.token).not.toBe(old)
		token = res.body.token

		// The old token no longer resolves.
		expect((await fetchPublic(old)).status).toBe(404)
		// The new one does.
		expect((await fetchPublic(token)).status).toBe(200)
	})

	test('disabling the link makes it 404', async () => {
		expect((await api('DELETE', `/boards/${boardId}/public-share`)).status).toBe(200)
		expect((await fetchPublic(token)).status).toBe(404)

		const cfg = (await api('GET', `/boards/${boardId}/public-share`)).body
		expect(cfg.enabled).toBe(false)
	})

	test('an invalid / unknown token is a 404', async () => {
		expect((await fetchPublic('totally-made-up-token-that-does-not-exist')).status).toBe(404)
	})
})
