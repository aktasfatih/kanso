// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, API, currentAuth } from './helpers.js'
import crypto from 'node:crypto'

const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const FORGE = 'https://git.example.org/octo/app'

// Bespoke client: returns { ok, status, body } (does NOT throw) because these
// specs assert on status codes (200/400/401) directly. Mirrors card-webhook.spec.js.
async function api(method, path, body) {
	const r = await fetch(API + path, {
		method,
		headers: { ...HEADERS, Authorization: currentAuth },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	const text = await r.text()
	return { ok: r.ok, status: r.status, body: text ? JSON.parse(text) : null }
}

// Unauthenticated POST to the public Forgejo ingest endpoint. Forgejo signs with
// a RAW lowercase hex digest and no `sha256=` prefix.
async function postWebhook(boardId, rawBody, signature, header = 'X-Forgejo-Signature') {
	const r = await fetch(`${API}/boards/${boardId}/forgejo-webhook`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', [header]: signature },
		body: rawBody,
	})
	const text = await r.text()
	return { status: r.status, body: text ? JSON.parse(text) : null }
}

const sign = (body, secret) => crypto.createHmac('sha256', secret).update(body).digest('hex')

test.describe('Forgejo webhook ingest', () => {
	let boardId = 0
	let todoStackId = 0
	let doneStackId = 0
	let cardId = 0
	let secret = ''

	test.beforeAll(async () => {
		boardId = (await api('POST', '/boards', { title: 'Forgejo E2E' })).body.id
		todoStackId = (await api('POST', '/stacks', { boardId, title: 'To do' })).body.id
		doneStackId = (await api('POST', '/stacks', { boardId, title: 'Done' })).body.id
		await api('PATCH', `/stacks/${doneStackId}`, { role: 5 }) // ROLE_DONE
		await api('PATCH', `/stacks/${todoStackId}`, { role: 2 }) // ROLE_TODO (reopen fallback)
		cardId = (await api('POST', '/cards', { stackId: todoStackId, title: 'Ship it' })).body.id
		secret = (await api('POST', `/boards/${boardId}/forgejo/rotate`)).body.secret
		expect(secret).toBeTruthy()
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`)
	})

	test('the Forgejo secret is separate from the GitHub one', async () => {
		const github = await api('GET', `/boards/${boardId}/webhook`)
		expect(github.body.enabled).toBe(false)
		const forgejo = await api('GET', `/boards/${boardId}/forgejo`)
		expect(forgejo.body.enabled).toBe(true)
		expect(forgejo.body.payloadUrl).toContain('forgejo-webhook')
	})

	test('a raw-hex signed merged-PR delivery moves the card to Done', async () => {
		const raw = JSON.stringify({
			action: 'closed',
			pull_request: {
				head: { ref: `kanso-${cardId}-ship-it` },
				html_url: `${FORGE}/pulls/2`,
				state: 'closed',
				merged: true,
			},
		})
		const res = await postWebhook(boardId, raw, sign(raw, secret))
		expect(res.status).toBe(200)
		expect(res.body.handled).toBe(true)
		expect(res.body.moved).toBe(true)

		const card = (await api('GET', `/cards/${cardId}`)).body
		expect(card.stackId).toBe(doneStackId)
		expect(Number(card.doneAt)).toBeGreaterThan(0)
	})

	// Forgejo emits the GitHub-compatible header alongside its own.
	test('a sha256=-prefixed signature is also accepted', async () => {
		await api('POST', `/cards/${cardId}/move`, { targetStackId: todoStackId, afterCardId: null })
		const raw = JSON.stringify({
			action: 'closed',
			pull_request: {
				head: { ref: `kanso-${cardId}-x` },
				html_url: `${FORGE}/pulls/2`,
				merged: true,
			},
		})
		const res = await postWebhook(boardId, raw, 'sha256=' + sign(raw, secret), 'X-Hub-Signature-256')
		expect(res.status).toBe(200)
		expect(res.body.moved).toBe(true)
	})

	// A Forgejo hook saved WITHOUT a secret still sends the header - empty. That
	// must read as a rejection, not as "no signature offered".
	for (const [name, sig] of [['empty', ''], ['whitespace', '   '], ['wrong', 'a'.repeat(64)]]) {
		test(`a ${name} signature is rejected 401 and does not move`, async () => {
			await api('POST', `/cards/${cardId}/move`, { targetStackId: todoStackId, afterCardId: null })
			const raw = JSON.stringify({
				action: 'closed',
				pull_request: { head: { ref: `kanso-${cardId}-x` }, html_url: `${FORGE}/pulls/9`, merged: true },
			})
			const res = await postWebhook(boardId, raw, sig)
			expect(res.status).toBe(401)

			const card = (await api('GET', `/cards/${cardId}`)).body
			expect(card.stackId).toBe(todoStackId)
		})
	}

	test('a delivery that matches nothing says why', async () => {
		const raw = JSON.stringify({
			action: 'opened',
			pull_request: { head: { ref: 'feature/unrelated' }, html_url: `${FORGE}/pulls/3` },
		})
		const res = await postWebhook(boardId, raw, sign(raw, secret))
		expect(res.status).toBe(200)
		expect(res.body.handled).toBe(false)
		expect(res.body.reason).toBe('no_branch_match')
	})

	test('a signed issue-closed delivery moves the linked card to Done', async () => {
		const issueUrl = `${FORGE}/issues/12345`
		const issueCardId = (await api('POST', '/cards', { stackId: todoStackId, title: 'Fix the bug' })).body.id
		const linkRes = await api('POST', `/cards/${issueCardId}/links`, { url: issueUrl })
		expect(linkRes.ok).toBe(true)
		expect(linkRes.body.provider).toBe('forgejo')

		const raw = JSON.stringify({
			action: 'closed',
			issue: { html_url: issueUrl, state: 'closed', title: 'Fix the bug upstream' },
		})
		const res = await postWebhook(boardId, raw, sign(raw, secret))
		expect(res.status).toBe(200)
		expect(res.body.moved).toBe(true)
		expect(res.body.cardId).toBe(issueCardId)

		const card = (await api('GET', `/cards/${issueCardId}`)).body
		expect(card.stackId).toBe(doneStackId)

		// State came from the delivery, not a poll - the instance is never called.
		const links = (await api('GET', `/cards/${issueCardId}/links`)).body
		expect(links[0].state).toBe('closed')
		expect(links[0].title).toBe('Fix the bug upstream')
	})

	// Forgejo shares one number sequence between issues and PRs and redirects
	// each spelling to the other, so a link pasted as /issues/N must still be
	// matched by a delivery carrying /pulls/N.
	test('a link pasted as /issues/N is matched by a /pulls/N delivery', async () => {
		const pastedUrl = `${FORGE}/issues/777`
		const c = (await api('POST', '/cards', { stackId: todoStackId, title: 'Dual spelling' })).body.id
		expect((await api('POST', `/cards/${c}/links`, { url: pastedUrl })).ok).toBe(true)

		const raw = JSON.stringify({
			action: 'closed',
			issue: { html_url: `${FORGE}/pulls/777`, state: 'closed', title: 'Actually a PR' },
		})
		const res = await postWebhook(boardId, raw, sign(raw, secret))
		expect(res.status).toBe(200)
		expect(res.body.handled).toBe(true)
		expect(res.body.cardId).toBe(c)

		const links = (await api('GET', `/cards/${c}/links`)).body
		expect(links[0].state).toBe('closed')
	})

	test('a foreign-host link is rejected on a board without the Forgejo webhook', async () => {
		const otherBoardId = (await api('POST', '/boards', { title: 'Forgejo E2E no-hook' })).body.id
		const stackId = (await api('POST', '/stacks', { boardId: otherBoardId, title: 'Tasks' })).body.id
		const c = (await api('POST', '/cards', { stackId, title: 'No hook' })).body.id

		const res = await api('POST', `/cards/${c}/links`, { url: `${FORGE}/pulls/1` })
		expect(res.ok).toBe(false)
		expect(res.status).toBe(400)

		await api('DELETE', `/boards/${otherBoardId}`)
	})
})

test.describe('Forgejo webhook issue intake', () => {
	let boardId = 0
	let inboxStackId = 0
	let secret = ''
	let issueSeq = 0

	const openedBody = (n, { title = 'New bug report', labels = [] } = {}) =>
		JSON.stringify({
			action: 'opened',
			issue: { html_url: `${FORGE}/issues/${n}`, state: 'open', title, labels },
		})

	const cardsIn = async (stackId) => {
		const cards = (await api('GET', `/boards/${boardId}`)).body.cards ?? []
		return cards.filter((c) => c.stackId === stackId)
	}

	test.beforeAll(async () => {
		boardId = (await api('POST', '/boards', { title: 'Forgejo Intake E2E' })).body.id
		inboxStackId = (await api('POST', '/stacks', { boardId, title: 'Inbox' })).body.id
		secret = (await api('POST', `/boards/${boardId}/forgejo/rotate`)).body.secret
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`)
	})

	test('opened issue with intake off says intake_off', async () => {
		const raw = openedBody(++issueSeq)
		const res = await postWebhook(boardId, raw, sign(raw, secret))
		expect(res.status).toBe(200)
		expect(res.body.handled).toBe(false)
		expect(res.body.reason).toBe('intake_off')
		expect(await cardsIn(inboxStackId)).toHaveLength(0)
	})

	test('opened issue creates a linked card, redelivery does not duplicate', async () => {
		const cfg = await api('PUT', `/boards/${boardId}/forgejo/intake`, { stackId: inboxStackId, label: '' })
		expect(cfg.ok).toBe(true)
		expect(cfg.body.intakeStackId).toBe(inboxStackId)

		const n = ++issueSeq
		const raw = openedBody(n, { title: 'Crash on load' })
		let res = await postWebhook(boardId, raw, sign(raw, secret))
		expect(res.body.created).toBe(true)

		const cards = await cardsIn(inboxStackId)
		expect(cards).toHaveLength(1)
		expect(cards[0].title).toBe('Crash on load')

		const links = (await api('GET', `/cards/${res.body.cardId}/links`)).body
		expect(links).toHaveLength(1)
		expect(links[0].provider).toBe('forgejo')
		expect(links[0].kind).toBe('issue')
		expect(links[0].state).toBe('open')

		res = await postWebhook(boardId, raw, sign(raw, secret))
		expect(res.body.reason).toBe('intake_duplicate')
		expect(await cardsIn(inboxStackId)).toHaveLength(1)
	})

	test('the label filter takes in matching issues only', async () => {
		const cfg = await api('PUT', `/boards/${boardId}/forgejo/intake`, { stackId: inboxStackId, label: 'bug' })
		expect(cfg.body.intakeLabel).toBe('bug')
		const before = (await cardsIn(inboxStackId)).length

		let raw = openedBody(++issueSeq, { labels: [{ name: 'enhancement' }] })
		let res = await postWebhook(boardId, raw, sign(raw, secret))
		expect(res.body.reason).toBe('intake_filtered')
		expect((await cardsIn(inboxStackId)).length).toBe(before)

		raw = openedBody(++issueSeq, { title: 'Labelled bug', labels: [{ name: 'Bug' }] })
		res = await postWebhook(boardId, raw, sign(raw, secret))
		expect(res.body.created).toBe(true)
		expect((await cardsIn(inboxStackId)).length).toBe(before + 1)
	})

	test('the intake endpoint rejects a stack of another board', async () => {
		const otherBoardId = (await api('POST', '/boards', { title: 'Forgejo Intake other' })).body.id
		const foreignStackId = (await api('POST', '/stacks', { boardId: otherBoardId, title: 'Elsewhere' })).body.id
		const res = await api('PUT', `/boards/${boardId}/forgejo/intake`, { stackId: foreignStackId, label: '' })
		expect(res.status).toBe(400)
		await api('DELETE', `/boards/${otherBoardId}`)
	})
})
