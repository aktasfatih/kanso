// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'
import crypto from 'node:crypto'

const BASE = 'http://localhost:8891'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const AUTH = 'Basic ' + Buffer.from('admin:admin').toString('base64')

async function api(method, path, body) {
	const r = await fetch(API + path, {
		method,
		headers: { ...HEADERS, Authorization: AUTH },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	const text = await r.text()
	return { ok: r.ok, status: r.status, body: text ? JSON.parse(text) : null }
}

// Unauthenticated POST straight to the public webhook endpoint with a raw body
// and an X-Hub-Signature-256 header (no session, no OCS header).
async function postWebhook(boardId, rawBody, signature) {
	const r = await fetch(`${API}/boards/${boardId}/github-webhook`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', 'X-Hub-Signature-256': signature },
		body: rawBody,
	})
	const text = await r.text()
	return { status: r.status, body: text ? JSON.parse(text) : null }
}

const sign = (body, secret) => 'sha256=' + crypto.createHmac('sha256', secret).update(body).digest('hex')

// GitHub webhook ingest (#3466): a signed PR-merged delivery moves the card to
// the Done-role stack; a bad signature is rejected 401.
test.describe('GitHub webhook ingest', () => {
	let boardId = 0
	let todoStackId = 0
	let doneStackId = 0
	let cardId = 0
	let secret = ''

	test.beforeAll(async () => {
		boardId = (await api('POST', '/boards', { title: 'Webhook E2E' })).body.id
		todoStackId = (await api('POST', '/stacks', { boardId, title: 'To do' })).body.id
		doneStackId = (await api('POST', '/stacks', { boardId, title: 'Done' })).body.id
		await api('PATCH', `/stacks/${doneStackId}`, { role: 5 }) // ROLE_DONE
		cardId = (await api('POST', '/cards', { stackId: todoStackId, title: 'Ship it' })).body.id
		secret = (await api('POST', `/boards/${boardId}/webhook/rotate`)).body.secret
		expect(secret).toBeTruthy()
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`)
	})

	test('a signed merged-PR delivery moves the card to Done', async () => {
		const payload = {
			action: 'closed',
			pull_request: {
				head: { ref: `kanso-${cardId}-ship-it` },
				html_url: 'https://github.com/nextcloud/server/pull/2',
				merged: true,
			},
		}
		const raw = JSON.stringify(payload)
		const res = await postWebhook(boardId, raw, sign(raw, secret))
		expect(res.status).toBe(200)
		expect(res.body.handled).toBe(true)
		expect(res.body.moved).toBe(true)

		// The card is now in the Done stack and stamped done.
		const card = (await api('GET', `/cards/${cardId}`)).body
		expect(card.stackId).toBe(doneStackId)
		expect(Number(card.doneAt)).toBeGreaterThan(0)
	})

	// Issues events (#3751): an issue has no branch, so the card is matched via
	// its attached issue link. Closed → Done-role stack; reopened → back to the
	// in-progress-role stack (falling back to To do when absent).
	test('a signed issue-closed delivery moves the linked card to Done, reopened moves it back', async () => {
		const issueUrl = 'https://github.com/nextcloud/server/issues/12345'
		const issueCardId = (await api('POST', '/cards', { stackId: todoStackId, title: 'Fix the bug' })).body.id
		await api('PATCH', `/stacks/${todoStackId}`, { role: 2 }) // ROLE_TODO (reopen fallback target)
		const linkRes = await api('POST', `/cards/${issueCardId}/links`, { url: issueUrl })
		expect(linkRes.ok).toBe(true)

		// closed → Done-role stack, stamped done, cached link state refreshed.
		let raw = JSON.stringify({
			action: 'closed',
			issue: { html_url: issueUrl, state: 'closed', title: 'Fix the bug upstream' },
		})
		let res = await postWebhook(boardId, raw, sign(raw, secret))
		expect(res.status).toBe(200)
		expect(res.body.handled).toBe(true)
		expect(res.body.moved).toBe(true)
		expect(res.body.cardId).toBe(issueCardId)

		let card = (await api('GET', `/cards/${issueCardId}`)).body
		expect(card.stackId).toBe(doneStackId)
		expect(Number(card.doneAt)).toBeGreaterThan(0)

		const links = (await api('GET', `/cards/${issueCardId}/links`)).body
		expect(links[0].state).toBe('closed')
		expect(links[0].title).toBe('Fix the bug upstream')

		// reopened → back out of Done (no in-progress stack here → To do fallback).
		raw = JSON.stringify({
			action: 'reopened',
			issue: { html_url: issueUrl, state: 'open', title: 'Fix the bug upstream' },
		})
		res = await postWebhook(boardId, raw, sign(raw, secret))
		expect(res.status).toBe(200)
		expect(res.body.moved).toBe(true)

		card = (await api('GET', `/cards/${issueCardId}`)).body
		expect(card.stackId).toBe(todoStackId)
	})

	test('an issues delivery for an unlinked issue is an accepted no-op', async () => {
		const raw = JSON.stringify({
			action: 'closed',
			issue: { html_url: 'https://github.com/nextcloud/server/issues/999999', state: 'closed', title: 'x' },
		})
		const res = await postWebhook(boardId, raw, sign(raw, secret))
		expect(res.status).toBe(200)
		expect(res.body.handled).toBe(false)
	})

	test('a delivery with a bad signature is rejected 401 and does not move', async () => {
		// Reset the card into To do first.
		await api('POST', `/cards/${cardId}/move`, { targetStackId: todoStackId, afterCardId: null })

		const raw = JSON.stringify({
			action: 'closed',
			pull_request: { head: { ref: `kanso-${cardId}-x` }, html_url: 'https://github.com/o/r/pull/9', merged: true },
		})
		const res = await postWebhook(boardId, raw, 'sha256=deadbeef')
		expect(res.status).toBe(401)

		const card = (await api('GET', `/cards/${cardId}`)).body
		expect(card.stackId).toBe(todoStackId)
	})
})
