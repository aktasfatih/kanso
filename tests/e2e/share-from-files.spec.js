// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, currentAuth, me, ncLogin, BASE } from './helpers.js'

const API = BASE + '/index.php/apps/kanso/api'
// DAV path for the CURRENT user's own Files. Built at call time so it reads the
// live `me` binding (a module-level snapshot would capture 'admin' before the
// worker rebind).
const davUrl = () => BASE + '/remote.php/dav/files/' + me
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }

async function api(method, path, body) {
	const r = await fetch(API + path, {
		method,
		headers: { ...HEADERS, Authorization: currentAuth },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	const text = await r.text()
	return { ok: r.ok, status: r.status, body: text ? JSON.parse(text) : null }
}

// Uploads bytes to the user's Files over WebDAV and returns the numeric fileId
// (the OC-FileId header, of the form "<id>oc..." - the leading integer is the id).
async function putFile(name, content) {
	const put = await fetch(`${davUrl()}/${name}`, {
		method: 'PUT',
		headers: { Authorization: currentAuth },
		body: content,
	})
	if (!put.ok && put.status !== 204 && put.status !== 201) {
		throw new Error(`PUT ${name} failed: ${put.status}`)
	}
	// OC-FileId is "<numeric id><instance suffix>", e.g. "00003076oc9x77r6nq0m".
	// The numeric id is only the LEADING run of digits (parseInt stops at the
	// first non-digit) - do NOT strip all non-digits, which would fold the
	// suffix's digits into the id.
	const raw = put.headers.get('oc-fileid') || ''
	const id = parseInt(raw, 10)
	if (!Number.isFinite(id) || id <= 0) throw new Error(`no fileId for ${name}: "${raw}"`)
	return id
}

// "Share from Files" (#3645): copy a file from the actor's own Nextcloud Files
// onto a card. The API path (below) is the security-critical surface and is
// fully covered here end-to-end. NOTE / COVERAGE GAP: driving the Files-app UI
// action ("Add to Kanso…" in a file's action menu → picker dialog → this same
// endpoint) is not automated - it depends on the Files app's own action-menu
// markup, which is brittle to script across NC versions. The action registration
// itself is a thin wrapper over @nextcloud/files' registerFileAction; the copy
// semantics it invokes are what these API + PHPUnit tests lock down.
test.describe('Share from Files', () => {
	let boardId = 0
	let stackId = 0
	let cardId = 0
	let fileId = 0

	test.beforeAll(async () => {
		boardId = (await api('POST', '/boards', { title: 'Share-From-Files E2E' })).body.id
		stackId = (await api('POST', '/stacks', { boardId, title: 'Tasks' })).body.id
		cardId = (await api('POST', '/cards', { stackId, title: 'Ship it' })).body.id
		fileId = await putFile('kanso-share-from-files.txt', 'from the files app')
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`)
		await fetch(`${davUrl()}/kanso-share-from-files.txt`, {
			method: 'DELETE',
			headers: { Authorization: currentAuth },
		}).catch(() => {})
	})

	test('copies a readable file onto the card as an attachment', async () => {
		// Starts with no attachments.
		let res = await api('GET', `/cards/${cardId}/attachments`)
		expect(res.ok).toBe(true)
		expect(res.body).toEqual([])

		// Attach the Files node by id.
		res = await api('POST', `/cards/${cardId}/attachments/from-file`, { fileId })
		expect(res.ok).toBe(true)
		expect(res.body.filename).toBe('kanso-share-from-files.txt')
		expect(res.body.size).toBe('from the files app'.length)
		// The server-generated storage key is an internal detail and never leaks
		// to the client.
		expect(res.body.storageKey).toBeUndefined()

		// It now lists on the card, and its bytes are the copied content.
		res = await api('GET', `/cards/${cardId}/attachments`)
		expect(res.body).toHaveLength(1)
		const attId = res.body[0].id

		const dl = await fetch(`${API}/cards/${cardId}/attachments/${attId}`, {
			headers: { Authorization: currentAuth, 'OCS-APIREQUEST': 'true' },
		})
		expect(dl.ok).toBe(true)
		expect(await dl.text()).toBe('from the files app')
	})

	test('rejects a fileId the actor cannot read (not found)', async () => {
		// A wildly out-of-range id the admin userfolder can never resolve.
		const res = await api('POST', `/cards/${cardId}/attachments/from-file`, { fileId: 2147480000 })
		expect(res.ok).toBe(false)
		expect([400, 404]).toContain(res.status)
	})

	test('the Files-integration script registers the action', async ({ page }) => {
		// Log in as the current user (detects the worker's live session under
		// isolation), open the Files app (which fires loadAdditionalScripts →
		// Kanso's files entry), then assert the action is in the shared registry.
		await ncLogin(page)
		await page.goto(BASE + '/index.php/apps/files')
		await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {})

		// registerFileAction stores actions in the window-scoped @nextcloud/files
		// registry (window._nc_files_scope.v4_0.fileActions, a Map keyed by id);
		// assert our id landed there once the Files page loaded Kanso's script.
		const registered = await page.evaluate(() => {
			const actions = window._nc_files_scope?.v4_0?.fileActions
			return !!(actions && typeof actions.has === 'function' && actions.has('kanso-add-to-card'))
		})
		expect(registered).toBe(true)
	})
})
