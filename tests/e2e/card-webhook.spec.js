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

// Issue intake (#3752): a board can opt in to auto-creating a linked card when
// an issue is opened, into a configured stack, optionally filtered by label.
test.describe('GitHub webhook issue intake', () => {
	let boardId = 0
	let inboxStackId = 0
	let secret = ''
	let issueSeq = 0

	const openedBody = (issueNumber, { title = 'New bug report', labels = [] } = {}) =>
		JSON.stringify({
			action: 'opened',
			issue: {
				html_url: `https://github.com/octo/app/issues/${issueNumber}`,
				state: 'open',
				title,
				labels,
			},
		})

	// Card summaries of one stack from the board payload (includes archived
	// card summaries - they carry `archived: true`).
	const cardsIn = async (stackId) => {
		const cards = (await api('GET', `/boards/${boardId}`)).body.cards ?? []
		return cards.filter((c) => c.stackId === stackId)
	}

	test.beforeAll(async () => {
		boardId = (await api('POST', '/boards', { title: 'Intake E2E' })).body.id
		inboxStackId = (await api('POST', '/stacks', { boardId, title: 'Inbox' })).body.id
		secret = (await api('POST', `/boards/${boardId}/webhook/rotate`)).body.secret
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`)
	})

	test('opened issue with intake off is an accepted no-op', async () => {
		const raw = openedBody(++issueSeq)
		const res = await postWebhook(boardId, raw, sign(raw, secret))
		expect(res.status).toBe(200)
		expect(res.body.handled).toBe(false)
		expect(await cardsIn(inboxStackId)).toHaveLength(0)
	})

	test('opened issue creates a linked card in the configured stack, redelivery does not duplicate', async () => {
		const cfg = await api('PUT', `/boards/${boardId}/webhook/intake`, { stackId: inboxStackId, label: '' })
		expect(cfg.ok).toBe(true)
		expect(cfg.body.intakeStackId).toBe(inboxStackId)

		const issueNumber = ++issueSeq
		const raw = openedBody(issueNumber, { title: 'Crash on load' })
		let res = await postWebhook(boardId, raw, sign(raw, secret))
		expect(res.status).toBe(200)
		expect(res.body.handled).toBe(true)
		expect(res.body.created).toBe(true)

		const cards = await cardsIn(inboxStackId)
		expect(cards).toHaveLength(1)
		expect(cards[0].title).toBe('Crash on load')

		// Link-only card: the issue rides as a KIND_ISSUE link with the payload's
		// state/title cached; the description stays empty (no body copy).
		const links = (await api('GET', `/cards/${res.body.cardId}/links`)).body
		expect(links).toHaveLength(1)
		expect(links[0].url).toBe(`https://github.com/octo/app/issues/${issueNumber}`)
		expect(links[0].kind).toBe('issue')
		expect(links[0].state).toBe('open')
		expect(links[0].title).toBe('Crash on load')

		// Redelivery of the same issue → no duplicate card.
		res = await postWebhook(boardId, raw, sign(raw, secret))
		expect(res.status).toBe(200)
		expect(await cardsIn(inboxStackId)).toHaveLength(1)
	})

	test('an archived intake card still dedupes a redelivered opened event', async () => {
		const issueNumber = ++issueSeq
		const raw = openedBody(issueNumber)
		const res = await postWebhook(boardId, raw, sign(raw, secret))
		expect(res.body.created).toBe(true)
		const countAfterCreate = (await cardsIn(inboxStackId)).length

		await api('PATCH', `/cards/${res.body.cardId}`, { archived: true })
		const again = await postWebhook(boardId, raw, sign(raw, secret))
		expect(again.status).toBe(200)
		expect(again.body.handled).toBe(false)
		// No new card: the archived one still holds the dedup.
		expect((await cardsIn(inboxStackId)).length).toBe(countAfterCreate)
	})

	test('the label filter takes in matching issues only', async () => {
		const cfg = await api('PUT', `/boards/${boardId}/webhook/intake`, { stackId: inboxStackId, label: 'bug' })
		expect(cfg.ok).toBe(true)
		expect(cfg.body.intakeLabel).toBe('bug')

		const before = (await cardsIn(inboxStackId)).length

		let raw = openedBody(++issueSeq, { labels: [{ name: 'enhancement' }] })
		let res = await postWebhook(boardId, raw, sign(raw, secret))
		expect(res.body.handled).toBe(false)
		expect((await cardsIn(inboxStackId)).length).toBe(before)

		raw = openedBody(++issueSeq, { title: 'Labelled bug', labels: [{ name: 'Bug' }] })
		res = await postWebhook(boardId, raw, sign(raw, secret))
		expect(res.body.created).toBe(true)
		expect((await cardsIn(inboxStackId)).length).toBe(before + 1)
	})

	test('the intake endpoint rejects a stack of another board', async () => {
		const otherBoardId = (await api('POST', '/boards', { title: 'Intake E2E other' })).body.id
		const foreignStackId = (await api('POST', '/stacks', { boardId: otherBoardId, title: 'Elsewhere' })).body.id
		const res = await api('PUT', `/boards/${boardId}/webhook/intake`, { stackId: foreignStackId, label: '' })
		expect(res.status).toBe(400)
		await api('DELETE', `/boards/${otherBoardId}`)
	})
})

async function ncLogin(page) {
	// Session is preloaded via storageState (see playwright.config.js) for the
	// default admin context — skip the UI login round-trip entirely. Specs that
	// opt out of storageState start with no cookies and fall through to log in.
	if ((await page.context().cookies()).some((c) => c.name === 'nc_username' || c.name === 'nc_session_id')) return
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	if (!(await page.locator('#user').isVisible({ timeout: 3000 }).catch(() => false))) return
	await page.fill('#user', 'admin')
	await page.fill('#password', 'admin')
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

// Settings UI smoke (#3752): the intake stack picker + label filter live in the
// board settings' GitHub webhook section and persist through the intake endpoint.
test.describe('Issue intake settings UI', () => {
	let boardId = 0
	let inboxStackId = 0

	test.beforeAll(async () => {
		boardId = (await api('POST', '/boards', { title: 'Intake UI E2E' })).body.id
		inboxStackId = (await api('POST', '/stacks', { boardId, title: 'Inbox' })).body.id
		await api('POST', '/stacks', { boardId, title: 'Doing' })
		// The intake block shows once the webhook is enabled.
		await api('POST', `/boards/${boardId}/webhook/rotate`)
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`)
	})

	async function openGithubSettings(page) {
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /automation/i }).click()
		// The GitHub group auto-expands while the webhook is active.
		await expect(page.locator('#bs-webhook-intake-stack')).toBeVisible({ timeout: 8_000 })
	}

	test('picking a stack and a label filter persists', async ({ page }) => {
		await ncLogin(page)
		await openGithubSettings(page)
		const github = page.locator('#bs-automation-github')

		await page.locator('#bs-webhook-intake-stack').selectOption({ label: 'Inbox' })
		await expect
			.poll(async () => (await api('GET', `/boards/${boardId}/webhook`)).body.intakeStackId)
			.toBe(inboxStackId)

		// Filter mode: only issues carrying one GitHub label.
		await github.getByLabel('Which issues to take in').selectOption('label')
		await github.getByPlaceholder('GitHub label name').fill('bug')
		await github.getByRole('button', { name: 'Save' }).click()
		await expect
			.poll(async () => (await api('GET', `/boards/${boardId}/webhook`)).body.intakeLabel)
			.toBe('bug')

		// A fresh load reads it all back.
		await page.reload()
		await openGithubSettings(page)
		await expect(page.locator('#bs-webhook-intake-stack')).toHaveValue(String(inboxStackId))
		await expect(github.getByLabel('Which issues to take in')).toHaveValue('label')
		await expect(github.getByPlaceholder('GitHub label name')).toHaveValue('bug')

		// Back to all issues: the label filter is dropped server-side.
		await github.getByLabel('Which issues to take in').selectOption('all')
		await expect
			.poll(async () => (await api('GET', `/boards/${boardId}/webhook`)).body.intakeLabel)
			.toBe('')
	})
})
