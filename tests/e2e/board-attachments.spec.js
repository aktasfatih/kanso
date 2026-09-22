// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, ncLogin, BASE, boardUrl, currentAuth } from './helpers.js'

const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }

// Local client: returns { ok, status, body } and never throws, so a test can
// assert on a 403 as easily as on a payload. `auth` is explicit because the
// denial cases act as a SECOND user.
async function call(auth, method, path, body) {
	const r = await fetch(API + path, {
		method,
		headers: { ...HEADERS, Authorization: auth },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	const text = await r.text()
	return { ok: r.ok, status: r.status, body: text ? JSON.parse(text) : null }
}

const api = (method, path, body) => call(currentAuth, method, path, body)

// Multipart upload of a small in-memory file (the shared api client is JSON only).
async function uploadFile(cardId, filename, content) {
	const form = new FormData()
	form.append('file', new Blob([content], { type: 'text/plain' }), filename)
	const r = await fetch(API + `/cards/${cardId}/attachments`, {
		method: 'POST',
		headers: { 'OCS-APIREQUEST': 'true', Authorization: currentAuth },
		body: form,
	})
	if (!r.ok) throw new Error(`upload ${filename} failed: ${r.status}`)
	return r.json()
}

// Board-wide attachment view (#10670): every file on the board in one modal,
// reachable from the ⋯ More menu, each row naming its owning card and opening it.
test.describe('Board attachments view', () => {
	let boardId = 0
	let stackId = 0
	let firstCardId = 0
	let secondCardId = 0

	test.beforeAll(async () => {
		boardId = (await api('POST', '/boards', { title: 'Board attachments E2E' })).body.id
		stackId = (await api('POST', '/stacks', { boardId, title: 'Tasks' })).body.id
		firstCardId = (await api('POST', '/cards', { stackId, title: 'Spec card' })).body.id
		secondCardId = (await api('POST', '/cards', { stackId, title: 'Design card' })).body.id
		// Two files on two DIFFERENT cards: the point of the view is that one
		// listing spans the board, not a single card.
		await uploadFile(firstCardId, 'board-listing-spec.txt', 'spec bytes')
		await uploadFile(secondCardId, 'board-listing-logo.txt', 'logo bytes')
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`)
	})

	test('lists every board attachment and opens the owning card', async ({ page }) => {
		await ncLogin(page)
		await page.goto(boardUrl(boardId))
		await page.waitForSelector('.board-view__header', { timeout: 10_000 })

		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: 'Board attachments' }).click()

		// Both files are listed, each next to the card it hangs off.
		const specRow = page.locator('.board-attachments__row', { hasText: 'board-listing-spec.txt' })
		const logoRow = page.locator('.board-attachments__row', { hasText: 'board-listing-logo.txt' })
		await expect(specRow).toHaveCount(1, { timeout: 10_000 })
		await expect(logoRow).toHaveCount(1)
		await expect(specRow).toContainText('Spec card')
		await expect(logoRow).toContainText('Design card')

		// Download stays on the existing per-card endpoint, addressed by card +
		// attachment id - no second byte path was introduced for this view.
		await expect(specRow.locator('a.board-attachments__download'))
			.toHaveAttribute('href', new RegExp(`/api/cards/${firstCardId}/attachments/\\d+$`))

		// Clicking a row opens the card the file is attached to.
		await specRow.locator('.board-attachments__open').click()
		await page.waitForSelector('.card-modal', { timeout: 10_000 })
		await expect(page).toHaveURL(new RegExp(`/board/${boardId}/card/${firstCardId}$`))
	})
})

// The denial half, over real HTTP with a real second user: board membership is
// not enough - the listing is scoped to what the CALLER may see, and a board
// they are no member of answers 403.
test.describe.serial('Board attachments - who may see what', () => {
	// Multi-user: BasicAuth per request, do not inherit the shared admin session.
	test.use({ storageState: { cookies: [], origins: [] } })

	const token = 'ba' + Math.floor(Date.now() / 1000)
	const state = { sharedBoardId: 0, unsharedBoardId: 0 }

	test.beforeAll(async ({ peer }) => {
		const board = (await api('POST', '/boards', { title: 'Board attachments shared ' + token })).body
		state.sharedBoardId = board.id
		const stack = (await api('POST', '/stacks', { boardId: board.id, title: 'Lane' })).body

		// peer is an INTERNAL member with READ - they see the board, and every
		// public card on it.
		await api('POST', `/boards/${board.id}/acl`, {
			participant: peer.user, participantType: 'user', permission: 1, role: 'internal',
		})

		// A public card's file, which the member MAY see…
		const pub = (await api('POST', '/cards', { stackId: stack.id, title: 'Shared card ' + token })).body
		await uploadFile(pub.id, `shared-${token}.txt`, 'visible to the member')

		// …and a PRIVATE card of the owner's, whose file they may not - the whole
		// point of this card: the listing must not become the way around it.
		const priv = (await api('POST', '/cards', { stackId: stack.id, title: 'Owner secret ' + token })).body
		await api('PATCH', `/cards/${priv.id}`, { visibility: 'private' })
		await uploadFile(priv.id, `owner-only-${token}.txt`, 'must never be listed')

		// A second board the peer is NO member of at all.
		const other = (await api('POST', '/boards', { title: 'Board attachments unshared ' + token })).body
		state.unsharedBoardId = other.id
		const otherStack = (await api('POST', '/stacks', { boardId: other.id, title: 'Lane' })).body
		const otherCard = (await api('POST', '/cards', { stackId: otherStack.id, title: 'Elsewhere ' + token })).body
		await uploadFile(otherCard.id, `stranger-${token}.txt`, 'another board entirely')
	})

	test.afterAll(async () => {
		for (const id of [state.sharedBoardId, state.unsharedBoardId]) {
			if (id) await api('DELETE', `/boards/${id}`).catch(() => {})
		}
	})

	test('a member never sees the file of a card hidden from them', async ({ peer }) => {
		const res = await call(peer.auth, 'GET', `/boards/${state.sharedBoardId}/attachments`)
		expect(res.status).toBe(200)

		const names = res.body.items.map((i) => i.filename)
		expect(names).toContain(`shared-${token}.txt`)
		expect(names).not.toContain(`owner-only-${token}.txt`)
		// The total is the viewer's total too - a count that saw more would leak
		// the existence of the hidden card's file.
		expect(res.body.total).toBe(1)

		// The owner, by contrast, sees both.
		const asOwner = await api('GET', `/boards/${state.sharedBoardId}/attachments`)
		expect(asOwner.body.items.map((i) => i.filename).sort())
			.toEqual([`owner-only-${token}.txt`, `shared-${token}.txt`])
	})

	test('a non-member gets 403, not a listing', async ({ peer }) => {
		const res = await call(peer.auth, 'GET', `/boards/${state.unsharedBoardId}/attachments`)
		expect(res.status).toBe(403)
	})
})
