// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')

async function ncLogin(page) {
	// Session is preloaded via storageState (see playwright.config.js) for the
	// default admin context — skip the UI login round-trip entirely. Specs that
	// opt out of storageState start with no cookies and fall through to log in.
	if ((await page.context().cookies()).some((c) => c.name === 'nc_username' || c.name === 'nc_session_id')) return
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	const userInput = page.locator('#user')
	const isLoginPage = await userInput.isVisible({ timeout: 3000 }).catch(() => false)
	if (!isLoginPage) return
	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

async function api(method, path, body) {
	const r = await fetch(API + path, {
		method,
		headers: { ...HEADERS, Authorization: AUTH },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	const text = await r.text()
	return { ok: r.ok, status: r.status, body: text ? JSON.parse(text) : null }
}

// Upload a small in-memory file via multipart.
async function uploadFile(cardId, filename, content, contentType = 'text/plain') {
	const form = new FormData()
	form.append('file', new Blob([content], { type: contentType }), filename)
	const r = await fetch(API + `/cards/${cardId}/attachments`, {
		method: 'POST',
		headers: { 'OCS-APIREQUEST': 'true', Authorization: AUTH },
		body: form,
	})
	const text = await r.text()
	return { ok: r.ok, status: r.status, body: text ? JSON.parse(text) : null }
}

// A minimal valid 1x1 PNG (so upload keeps image/png and the inline endpoint
// serves it) as a Uint8Array.
const PNG_1x1 = new Uint8Array([
	0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0x00, 0x00, 0x00, 0x0d,
	0x49, 0x48, 0x44, 0x52, 0x00, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00, 0x01,
	0x08, 0x06, 0x00, 0x00, 0x00, 0x1f, 0x15, 0xc4, 0x89, 0x00, 0x00, 0x00,
	0x0a, 0x49, 0x44, 0x41, 0x54, 0x78, 0x9c, 0x63, 0x00, 0x01, 0x00, 0x00,
	0x05, 0x00, 0x01, 0x0d, 0x0a, 0x2d, 0xb4, 0x00, 0x00, 0x00, 0x00, 0x49,
	0x45, 0x4e, 0x44, 0xae, 0x42, 0x60, 0x82,
])

async function uploadBytes(cardId, filename, bytes, contentType) {
	const form = new FormData()
	form.append('file', new Blob([bytes], { type: contentType }), filename)
	const r = await fetch(API + `/cards/${cardId}/attachments`, {
		method: 'POST',
		headers: { 'OCS-APIREQUEST': 'true', Authorization: AUTH },
		body: form,
	})
	const text = await r.text()
	return { ok: r.ok, status: r.status, body: text ? JSON.parse(text) : null }
}

// Card file attachments (#3526): app-storage, board-ACL-gated. Upload/list/
// download/delete over the API, plus the security cases (IDOR, oversized,
// path-traversal filename doesn't escape, download is Content-Disposition:
// attachment).
test.describe('Card file attachments', () => {
	let boardId = 0
	let stackId = 0
	let cardId = 0
	let otherCardId = 0

	test.beforeAll(async () => {
		boardId = (await api('POST', '/boards', { title: 'Attachments E2E' })).body.id
		stackId = (await api('POST', '/stacks', { boardId, title: 'Tasks' })).body.id
		cardId = (await api('POST', '/cards', { stackId, title: 'Card with files' })).body.id
		otherCardId = (await api('POST', '/cards', { stackId, title: 'Other card' })).body.id
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`)
	})

	test('upload, list, download and delete via the API', async () => {
		// Starts empty.
		let res = await api('GET', `/cards/${cardId}/attachments`)
		expect(res.ok).toBe(true)
		expect(res.body).toEqual([])

		// Upload.
		const up = await uploadFile(cardId, 'notes.txt', 'hello attachments', 'text/plain')
		expect(up.ok).toBe(true)
		expect(up.body.filename).toBe('notes.txt')
		expect(up.body.mime).toBe('text/plain')
		expect(up.body.size).toBe('hello attachments'.length)
		// The storage key must never be exposed to clients.
		expect(up.body.storageKey).toBeUndefined()
		const attId = up.body.id

		// It shows up in the list.
		res = await api('GET', `/cards/${cardId}/attachments`)
		expect(res.body).toHaveLength(1)
		expect(res.body[0].filename).toBe('notes.txt')

		// Card detail carries an attachment count.
		res = await api('GET', `/cards/${cardId}`)
		expect(res.body.attachmentCount).toBe(1)

		// Download returns the bytes with an attachment disposition.
		const dl = await fetch(API + `/cards/${cardId}/attachments/${attId}`, {
			headers: { 'OCS-APIREQUEST': 'true', Authorization: AUTH },
		})
		expect(dl.ok).toBe(true)
		expect(dl.headers.get('content-disposition') || '').toContain('attachment')
		expect(await dl.text()).toBe('hello attachments')

		// Delete removes it.
		res = await api('DELETE', `/cards/${cardId}/attachments/${attId}`)
		expect(res.ok).toBe(true)
		res = await api('GET', `/cards/${cardId}/attachments`)
		expect(res.body).toEqual([])
	})

	test('a path-traversal filename does not escape and is kept only as a label', async () => {
		const up = await uploadFile(cardId, '../../../../etc/passwd', 'x', 'text/plain')
		expect(up.ok).toBe(true)
		// The stored label is basename-stripped; the bytes live under a
		// server-generated key, so nothing escaped.
		expect(up.body.filename).toBe('passwd')
		await api('DELETE', `/cards/${cardId}/attachments/${up.body.id}`)
	})

	test('IDOR: an attachment of one card cannot be reached via another card', async () => {
		const up = await uploadFile(cardId, 'secret.txt', 'top secret', 'text/plain')
		expect(up.ok).toBe(true)
		const attId = up.body.id

		// Reaching it through the OTHER card's URL must fail (not a leak).
		const dl = await fetch(API + `/cards/${otherCardId}/attachments/${attId}`, {
			headers: { 'OCS-APIREQUEST': 'true', Authorization: AUTH },
		})
		expect(dl.status).toBe(404)

		// Deleting via the wrong card must also fail.
		const del = await api('DELETE', `/cards/${otherCardId}/attachments/${attId}`)
		expect(del.ok).toBe(false)
		expect(del.status).toBe(404)

		await api('DELETE', `/cards/${cardId}/attachments/${attId}`)
	})

	// Inline raster-image serve (#3525): only png/jpeg/gif/webp are served
	// Content-Disposition: inline with the exact type + nosniff; everything else
	// (txt, svg, cross-card IDOR) 404s and stays download-only.
	test('inline endpoint serves a png inline with nosniff; non-images and IDOR 404', async () => {
		const png = await uploadBytes(cardId, 'shot.png', PNG_1x1, 'image/png')
		expect(png.ok).toBe(true)
		expect(png.body.mime).toBe('image/png')
		const pngId = png.body.id

		// The png serves INLINE with the exact allow-listed type + nosniff.
		const inline = await fetch(API + `/cards/${cardId}/attachments/${pngId}/inline`, {
			headers: { 'OCS-APIREQUEST': 'true', Authorization: AUTH },
		})
		expect(inline.ok).toBe(true)
		expect(inline.headers.get('content-type')).toContain('image/png')
		expect((inline.headers.get('content-disposition') || '')).toContain('inline')
		expect(inline.headers.get('x-content-type-options')).toBe('nosniff')
		const buf = new Uint8Array(await inline.arrayBuffer())
		expect(buf.length).toBe(PNG_1x1.length)

		// A .txt attachment is NOT inline-serveable → 404 (download-only).
		const txt = await uploadFile(cardId, 'notes.txt', 'plain', 'text/plain')
		const txtInline = await fetch(API + `/cards/${cardId}/attachments/${txt.body.id}/inline`, {
			headers: { 'OCS-APIREQUEST': 'true', Authorization: AUTH },
		})
		expect(txtInline.status).toBe(404)

		// An SVG (scriptable) uploaded is coerced to octet-stream and is NOT
		// inline-serveable → 404.
		const svg = await uploadFile(cardId, 'x.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'image/svg+xml')
		const svgInline = await fetch(API + `/cards/${cardId}/attachments/${svg.body.id}/inline`, {
			headers: { 'OCS-APIREQUEST': 'true', Authorization: AUTH },
		})
		expect(svgInline.status).toBe(404)

		// IDOR: the png cannot be inlined through the OTHER card's URL.
		const idor = await fetch(API + `/cards/${otherCardId}/attachments/${pngId}/inline`, {
			headers: { 'OCS-APIREQUEST': 'true', Authorization: AUTH },
		})
		expect(idor.status).toBe(404)

		// Cleanup.
		await api('DELETE', `/cards/${cardId}/attachments/${pngId}`)
		await api('DELETE', `/cards/${cardId}/attachments/${txt.body.id}`)
		await api('DELETE', `/cards/${cardId}/attachments/${svg.body.id}`)
	})

	test('an empty upload is rejected', async () => {
		const up = await uploadFile(cardId, 'empty.txt', '', 'text/plain')
		expect(up.ok).toBe(false)
		expect(up.status).toBe(400)
	})

	// Regression for the CSRF rejection (#3784): download + inline are GET
	// endpoints reached by a plain `<a download>` navigation / `<img src>` load
	// — i.e. a browser *session-cookie* request that carries NO requesttoken.
	// Nextcloud enforces CSRF on cookie-authenticated requests, so without
	// `#[NoCSRFRequired]` these 412'd in the real app.
	//
	// IMPORTANT: this must go through the browser session, NOT the Basic-auth
	// `api()`/`fetch()` helpers above — Nextcloud skips the CSRF check for
	// non-cookie auth (Basic / app password), which is exactly why every other
	// download assertion in this file passed even while the app was broken.
	// `page.request` shares the logged-in context's cookies but adds no CSRF
	// token, faithfully reproducing the `<a href>` / `<img>` fetch.
	test('download and inline succeed over a browser session (no CSRF rejection)', async ({ page }) => {
		await ncLogin(page)

		// Seed one downloadable file and one inline-able png (setup via the API
		// helper is fine; the assertion below is what must use the session).
		const txt = await uploadFile(cardId, 'session-dl.txt', 'via session cookie', 'text/plain')
		expect(txt.ok).toBe(true)
		const png = await uploadBytes(cardId, 'session.png', PNG_1x1, 'image/png')
		expect(png.ok).toBe(true)

		// Download over the session cookie (no requesttoken) — the exact path a
		// user's `<a download>` click takes. 412 here == the CSRF regression.
		const dl = await page.request.get(`${API}/cards/${cardId}/attachments/${txt.body.id}`)
		expect(dl.status(), 'attachment download rejected — likely missing NoCSRFRequired').toBe(200)
		expect((dl.headers()['content-disposition'] || '')).toContain('attachment')
		expect(await dl.text()).toBe('via session cookie')

		// Inline serve over the session cookie — the path an `<img src>` takes.
		const inline = await page.request.get(`${API}/cards/${cardId}/attachments/${png.body.id}/inline`)
		expect(inline.status(), 'inline attachment rejected — likely missing NoCSRFRequired').toBe(200)
		expect(inline.headers()['content-type']).toContain('image/png')
		expect((inline.headers()['content-disposition'] || '')).toContain('inline')

		// Cleanup.
		await api('DELETE', `/cards/${cardId}/attachments/${txt.body.id}`)
		await api('DELETE', `/cards/${cardId}/attachments/${png.body.id}`)
	})

	test('upload/list/download/delete through the CardModal UI', async ({ page }) => {
		await ncLogin(page)
		const cardUrl = `${BASE}/index.php/apps/kanso#/board/${boardId}/card/${cardId}`
		await page.goto(cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Upload a small file through the hidden file input.
		await page.setInputFiles('.card-modal__file-input', {
			name: 'ui-upload.txt',
			mimeType: 'text/plain',
			buffer: Buffer.from('uploaded from the UI'),
		})

		// The attachment row appears with the filename.
		const row = page.locator('.card-modal__link-row', { hasText: 'ui-upload.txt' })
		await expect(row).toHaveCount(1, { timeout: 8000 })

		// The download link points at the attachment endpoint.
		const href = await row.locator('a.card-modal__link').getAttribute('href')
		expect(href).toContain('/attachments/')

		// Remove it via the per-row button.
		await row.locator('.card-modal__child-remove').click()
		await expect(page.locator('.card-modal__link-row', { hasText: 'ui-upload.txt' }))
			.toHaveCount(0, { timeout: 8000 })
	})

	// Paste an image into the description textarea (#3525): it uploads via the
	// attachment endpoint and the saved description renders an <img> pointing at
	// the inline endpoint.
	test('paste an image into the description uploads it and renders an inline <img>', async ({ page }) => {
		await ncLogin(page)
		const cardUrl = `${BASE}/index.php/apps/kanso#/board/${boardId}/card/${cardId}`
		await page.goto(cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Enter description edit mode (empty description shows a placeholder button).
		await page.click('.card-modal__desc-placeholder')
		const textarea = page.locator('.card-modal__desc-textarea')
		await expect(textarea).toBeVisible({ timeout: 5000 })
		await textarea.focus()

		// Dispatch a real paste event carrying a PNG File on the textarea. The
		// composable reads clipboardData.items, uploads, then rewrites the markdown.
		const pngArray = Array.from(PNG_1x1)
		await textarea.evaluate((el, bytes) => {
			const file = new File([new Uint8Array(bytes)], 'clip.png', { type: 'image/png' })
			const dt = new DataTransfer()
			dt.items.add(file)
			el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }))
		}, pngArray)

		// The upload completes and the markdown gets the inline-endpoint URL.
		await expect(textarea).toHaveValue(/!\[[^\]]*\]\(.*\/attachments\/\d+\/inline\)/, { timeout: 10_000 })

		// Save, then the rendered description shows the same-origin inline <img>.
		await page.locator('.card-modal__desc-actions button', { hasText: 'Save' }).click()
		const img = page.locator('.card-modal__desc-rendered img')
		await expect(img).toHaveCount(1, { timeout: 8000 })
		const src = await img.first().getAttribute('src')
		expect(src).toContain(`/api/cards/${cardId}/attachments/`)
		expect(src).toContain('/inline')

		// Clean up the pasted attachment so the shared card resets.
		await apiPatch(`/cards/${cardId}`, { description: '' })
	})
})

async function apiPatch(path, body) {
	const r = await fetch(API + path, {
		method: 'PATCH',
		headers: { ...HEADERS, Authorization: AUTH },
		body: JSON.stringify(body),
	})
	return { ok: r.ok, status: r.status }
}
