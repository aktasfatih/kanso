// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Card #10703 — the card modal's assign and label controls are writes, so they
// belong to editors only.
//
// The attribute bar rendered the "Assign" pill, the per-assignee "×" and the
// "Label" picker for every viewer, including a member shared in with READ only.
// Clicking any of them hit the server, which correctly answered 403, so the
// only thing the UI achieved was inviting a read-only member to fail.
//
// The server-side denial is unchanged and is pinned here first: hiding a
// control is presentation, never the authorization check.

import { test, expect, api, ncLogin, BASE, currentAuth, me } from './helpers.js'

test.describe('Assign and label controls are editors only (#10703)', () => {
	// A second identity logs in explicitly, so this describe must NOT inherit the
	// shared admin storageState (it would silently stay admin and false-pass).
	test.use({ storageState: { cookies: [], origins: [] }, viewport: { width: 1600, height: 900 } })

	const state = { boardId: 0, cardId: 0, labelId: 0, boardUrl: '' }

	test.beforeAll(async ({ peer }) => {
		const board = await api.post('/boards', { title: 'Card attr ACL ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Inbox' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Read-only card' })
		state.cardId = card.id

		// Seed one assignee and one label, so the READ view of both still has
		// something to show once the write affordances are gone.
		await api.put(`/cards/${card.id}/assignees/${me}`)
		const label = await api.post('/labels', { boardId: board.id, title: 'Shipped', color: 'e11d48' })
		state.labelId = label.id
		await api.put(`/cards/${card.id}/labels/${label.id}`)

		// READ only (1) — no EDIT bit, so canEdit is false for the peer.
		await api.post(`/boards/${board.id}/acl`, {
			participant: peer.user,
			participantType: 'user',
			permission: 1,
		})
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('the server refuses an assign and a label write from a viewer', async ({ peer }) => {
		// The UI gate below only hides a dead affordance — this pins that the real
		// gate is server-side, so hiding the pills never becomes the only check.
		const assign = await peer.api.raw('PUT', `/cards/${state.cardId}/assignees/${peer.user}`)
		expect(assign.status).toBe(403)
		const unassign = await peer.api.raw('DELETE', `/cards/${state.cardId}/assignees/${me}`)
		expect(unassign.status).toBe(403)
		const label = await peer.api.raw('DELETE', `/cards/${state.cardId}/labels/${state.labelId}`)
		expect(label.status).toBe(403)
	})

	test('a viewer gets no Assign pill, no assignee "×" and no label picker', async ({ browser, peer }) => {
		const ctx = await browser.newContext({ viewport: { width: 1600, height: 900 } })
		try {
			const page = await ctx.newPage()
			await ncLogin(page, { user: peer.user, pass: peer.pass })
			await page.goto(state.boardUrl)
			await page.waitForSelector('.board-view__header', { timeout: 15_000 })
			await page.locator('.card-tile').filter({ hasText: 'Read-only card' }).click()
			await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

			const attrbar = page.locator('.card-modal__attrbar')

			// The card still READS in full: the assignee and the label are shown…
			await expect(attrbar.locator('.card-modal__assignee-name')).toHaveCount(1)
			await expect(attrbar.locator('.card-modal__label-chip', { hasText: 'Shipped' })).toBeVisible()

			// …but none of the three write affordances is offered.
			await expect(attrbar.locator('button[data-pill="assign"]')).toHaveCount(0)
			await expect(attrbar.locator('.card-modal__assignee-pill .card-modal__pill-x')).toHaveCount(0)
			await expect(attrbar.locator('button[data-pill="label"]')).toHaveCount(0)
		} finally {
			await ctx.close()
		}
	})
})

test.describe('Assign and label controls stay available to editors (#10703)', () => {
	test.use({ viewport: { width: 1600, height: 900 } })

	const state = { boardId: 0, cardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Card attr editor ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Inbox' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Editable card' })
		state.cardId = card.id
		await api.put(`/cards/${card.id}/assignees/${me}`)
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('an editor still gets the Assign pill, the assignee "×" and the label picker', async ({ page }) => {
		await page.goto(state.boardUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await page.locator('.card-tile').filter({ hasText: 'Editable card' }).click()
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		const attrbar = page.locator('.card-modal__attrbar')
		await expect(attrbar.locator('button[data-pill="assign"]')).toBeVisible()
		await expect(attrbar.locator('.card-modal__assignee-pill .card-modal__pill-x')).toHaveCount(1)
		await expect(attrbar.locator('button[data-pill="label"]')).toBeVisible()

		// And they still work — the gate is presentation, not a new restriction.
		await attrbar.locator('.card-modal__assignee-pill .card-modal__pill-x').click()
		await expect(attrbar.locator('.card-modal__assignee-pill')).toHaveCount(0)
		await expect.poll(
			async () => (await api.get(`/cards/${state.cardId}`)).assigneeIds.length,
			{ timeout: 10_000 },
		).toBe(0)
	})
})

// ── Card #10732 ──────────────────────────────────────────────────────────────
// #10703 gated three controls. The rest of the attribute bar — Priority, Type,
// Due date, Estimate, the contact link + unlink, the header "Mark done" and the
// breadcrumb column/status switcher — still rendered for a member shared in with
// READ only.
//
// Two things make this more than a `v-if` sweep:
//
//   * The attribute pills ARE the value display. A read-only member keeps the
//     value (the pill renders as a plain <span>) and loses the editor; a pill
//     with nothing set is pure affordance and disappears entirely.
//   * "Mark done" and the column/status switcher were checked against the role
//     model rather than assumed: the bits are READ/EDIT/SHARE/MANAGE and nothing
//     else, CardService::move() and CardService::update() both assert EDIT, so
//     there is no seat that may move a card without editing it. Same gate.
//   * The Project pill is deliberately NOT gated — ProjectService::addCard()
//     asks only for READ on the card's board, so collecting a readable card into
//     your own project is allowed and the pill is a working control, not a dead
//     one. It is asserted present below so a future sweep cannot quietly take it.
//
// The server-side denials are unchanged and are pinned first, per control.

const CONTACT_UID = 'kanso-e2e-acl-contact'
const CONTACT_FN = 'Robin ACL E2E'

async function putAclVCard() {
	const vcard = [
		'BEGIN:VCARD',
		'VERSION:3.0',
		`UID:${CONTACT_UID}`,
		`FN:${CONTACT_FN}`,
		'EMAIL:robin.acl@example.com',
		'END:VCARD',
	].join('\r\n')
	const r = await fetch(`${BASE}/remote.php/dav/addressbooks/users/${me}/contacts/${CONTACT_UID}.vcf`, {
		method: 'PUT',
		headers: { Authorization: currentAuth, 'Content-Type': 'text/vcard' },
		body: vcard,
	})
	if (!r.ok) throw new Error(`PUT acl vcard → ${r.status}`)
}

async function deleteAclVCard() {
	await fetch(`${BASE}/remote.php/dav/addressbooks/users/${me}/contacts/${CONTACT_UID}.vcf`, {
		method: 'DELETE',
		headers: { Authorization: currentAuth },
	}).catch(() => {})
}

/**
 * Seeds a board whose card carries EVERY attribute this spec cares about, so a
 * missing control can never be confused with a missing value.
 */
async function seedFullCard(title) {
	const board = await api.post('/boards', { title })
	await api.patch(`/boards/${board.id}`, { estimateScale: 'tshirt' })
	const inbox = await api.post('/stacks', { boardId: board.id, title: 'Inbox' })
	const doing = await api.post('/stacks', { boardId: board.id, title: 'Doing' })
	const card = await api.post('/cards', { stackId: inbox.id, title: 'Attribute card' })
	await api.patch(`/cards/${card.id}`, {
		priority: 3,
		type: 'bug',
		duedate: '2030-01-01T10:00:00+00:00',
		estimate: 'M',
	})
	await putAclVCard()
	await api.post(`/cards/${card.id}/contacts`, {
		contactUri: CONTACT_UID,
		displayName: CONTACT_FN,
	})
	return { boardId: board.id, cardId: card.id, doingStackId: doing.id }
}

test.describe('The rest of the attribute bar is editors only (#10732)', () => {
	// Reads only — one peer session, one card modal, shared by every assertion.
	test.describe.configure({ mode: 'serial' })
	test.use({ storageState: { cookies: [], origins: [] }, viewport: { width: 1600, height: 900 } })

	const state = { boardId: 0, cardId: 0, doingStackId: 0, projectId: 0 }
	let ctx = null
	let page = null
	let attrbar = null
	let header = null

	test.beforeAll(async ({ browser, peer }) => {
		Object.assign(state, await seedFullCard('Card attr bar ACL ' + Math.floor(Date.now() / 1000)))
		await api.post(`/boards/${state.boardId}/acl`, {
			participant: peer.user,
			participantType: 'user',
			permission: 1,
		})

		ctx = await browser.newContext({ viewport: { width: 1600, height: 900 } })
		page = await ctx.newPage()
		await ncLogin(page, { user: peer.user, pass: peer.pass })
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await page.locator('.card-tile').filter({ hasText: 'Attribute card' }).click()
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })
		attrbar = page.locator('.card-modal__attrbar')
		header = page.locator('.card-modal__header')
	})

	test.afterAll(async () => {
		if (ctx) await ctx.close()
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
		await deleteAclVCard()
	})

	test('the server refuses every one of these writes from a viewer', async ({ peer }) => {
		// Hiding a control is presentation. THIS is the authorization check, and
		// it must keep failing the viewer whatever the UI does.
		for (const body of [
			{ priority: 1 },
			{ type: 'feature' },
			{ duedate: '2031-02-02T10:00:00+00:00' },
			{ estimate: 'L' },
			{ status: 'done' },
		]) {
			const r = await peer.api.raw('PATCH', `/cards/${state.cardId}`, body)
			expect(r.status, `PATCH ${JSON.stringify(body)}`).toBe(403)
		}
		const move = await peer.api.raw('POST', `/cards/${state.cardId}/move`, { stackId: state.doingStackId })
		expect(move.status).toBe(403)
		const link = await peer.api.raw('POST', `/cards/${state.cardId}/contacts`, {
			contactUri: CONTACT_UID,
			displayName: CONTACT_FN,
		})
		expect(link.status).toBe(403)
		const unlink = await peer.api.raw('DELETE', `/cards/${state.cardId}/contacts`, { contactUri: CONTACT_UID })
		expect(unlink.status).toBe(403)
	})

	test('a viewer reads the priority but gets no priority picker', async () => {
		await expect(attrbar.locator('[data-pill="priority"]')).toHaveText(/High/)
		await expect(attrbar.locator('button[data-pill="priority"]')).toHaveCount(0)
	})

	test('a viewer reads the type but gets no type picker', async () => {
		await expect(attrbar.locator('[data-pill="type"]')).toHaveText(/Bug/)
		await expect(attrbar.locator('button[data-pill="type"]')).toHaveCount(0)
	})

	test('a viewer reads the due date but gets no date editor', async () => {
		await expect(attrbar.locator('[data-pill="due"]')).toHaveText(/2030|Jan/)
		await expect(attrbar.locator('button[data-pill="due"]')).toHaveCount(0)
	})

	test('a viewer reads the estimate but gets no estimate picker', async () => {
		await expect(attrbar.locator('[data-pill="estimate"]')).toHaveText(/Estimate: M/)
		await expect(attrbar.locator('button[data-pill="estimate"]')).toHaveCount(0)
	})

	test('a viewer sees the linked contact but no unlink button', async () => {
		await expect(attrbar.locator('.card-modal__assignee-name', { hasText: CONTACT_FN })).toBeVisible()
		await expect(attrbar.locator('[data-contact-x]')).toHaveCount(0)
	})

	test('a viewer gets no Link contact pill', async () => {
		await expect(attrbar.locator('[data-pill="contact"]')).toHaveCount(0)
	})

	test('a viewer gets no Mark done button', async () => {
		await expect(header.locator('.card-modal__done-btn')).toHaveCount(0)
	})

	test('a viewer reads the status but gets no column/status switcher', async () => {
		await expect(header.locator('.card-modal__status-chip')).toHaveText(/NOT STARTED/i)
		await expect(header.locator('.card-modal__status-chip--btn')).toHaveCount(0)
	})

	test('a viewer still gets the Project pill — collecting a readable card is allowed', async () => {
		// ProjectService::addCard() asks for READ, not EDIT: this pill is a live
		// control for a viewer, so it must survive the sweep.
		await expect(attrbar.locator('button[data-pill="project"]')).toBeVisible()
	})
})

test.describe('The rest of the attribute bar stays available to editors (#10732)', () => {
	test.use({ viewport: { width: 1600, height: 900 } })

	const state = { boardId: 0, cardId: 0, doingStackId: 0 }

	test.beforeAll(async () => {
		Object.assign(state, await seedFullCard('Card attr bar editor ' + Math.floor(Date.now() / 1000)))
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
		await deleteAclVCard()
	})

	test('an editor keeps every control the viewer lost, and they still work', async ({ page }) => {
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await page.locator('.card-tile').filter({ hasText: 'Attribute card' }).click()
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		const attrbar = page.locator('.card-modal__attrbar')
		const header = page.locator('.card-modal__header')

		for (const pill of ['priority', 'type', 'due', 'estimate', 'contact', 'project']) {
			await expect(attrbar.locator(`button[data-pill="${pill}"]`), pill).toBeVisible()
		}
		await expect(attrbar.locator('[data-contact-x]')).toHaveCount(1)
		await expect(header.locator('.card-modal__done-btn')).toBeVisible()
		await expect(header.locator('.card-modal__status-chip--btn')).toBeVisible()

		// The gate is presentation, not a new restriction: an editor's write lands.
		await header.locator('.card-modal__done-btn').click()
		await expect.poll(
			async () => (await api.get(`/cards/${state.cardId}`)).doneAt,
			{ timeout: 10_000 },
		).toBeGreaterThan(0)
	})
})
