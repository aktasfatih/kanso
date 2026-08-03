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

	test('an empty upload is rejected', async () => {
		const up = await uploadFile(cardId, 'empty.txt', '', 'text/plain')
		expect(up.ok).toBe(false)
		expect(up.status).toBe(400)
	})

	test('upload/list/download/delete through the CardModal UI', async ({ page }) => {
		await ncLogin(page)
		const cardUrl = `${BASE}/index.php/apps/kanso#/board/${boardId}/card/${cardId}`
		await page.goto(cardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
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
})
