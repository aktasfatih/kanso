// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #9845 / #9848 — optimistic concurrency on card descriptions.
//
// Before this, two people with the same card open were pure last-writer-wins:
// whoever saved second silently overwrote the other's text, with no warning to
// either side. For a long description that is unrecoverable data loss.
//
// The editor now sends the card version it was seeded from
// (`baseDescriptionRevision`, a per-card counter that moves ONLY when the
// description itself changes) and the server refuses a write based on a stale
// version with 409 {"error": "description_conflict", description, lastModified,
// revision}. The guard is a conditional UPDATE inside the write transaction, so
// of two saves fired at the same instant exactly one can land. The check is
// server-side, so it holds whether or not notify_push is available. The client
// keeps BOTH texts: the draft stays in the editor and the version that landed
// while you typed is shown next to it, so nothing is lost without being seen.
//
// This suite drives two identities: `peer` edits in a real browser, `admin`
// plays the second author over the API.

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Description save conflict', () => {
	// Two distinct users, each logged in explicitly — so it must NOT inherit the
	// shared authenticated storageState, or every context would start as admin
	// and ncLogin would no-op.
	test.use({ storageState: { cookies: [], origins: [] } })

	const state = { boardId: 0, cardId: 0, cardUrl: '' }

	test.beforeAll(async ({ peer }) => {
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === 'Description Conflict Board') {
				await api.delete(`/boards/${b.id}`)
			}
		}
		const board = await api.post('/boards', { title: 'Description Conflict Board' })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'S1' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Contended card' })
		state.cardId = card.id
		await api.patch(`/cards/${card.id}`, { description: 'shared baseline' })
		// Share with the peer (READ|EDIT = 3)
		await api.post(`/boards/${board.id}/acl`, {
			participant: peer.user,
			participantType: 'user',
			permission: 3,
		})
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await api.delete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('a second author cannot be silently overwritten, and neither text is lost', async ({ browser, peer }) => {
		const peerCtx = await browser.newContext()
		try {
			const page = await peerCtx.newPage()
			await ncLogin(page, { user: peer.user, pass: peer.pass })

			await page.goto(state.cardUrl)
			await expect(page.locator('.card-modal__desc-rendered')).toContainText('shared baseline', { timeout: 15_000 })

			// The peer starts editing — the editor seeds its draft AND its base
			// version from what is on screen right now.
			await page.locator('.card-modal__desc-view').click()
			const editorSection = page.locator('.card-modal__section .kanso-md-editor')
			await expect(editorSection).toBeVisible({ timeout: 8000 })
			const prose = editorSection.locator('.ProseMirror')
			await expect(prose).toBeVisible({ timeout: 4000 })
			await prose.click()
			await page.keyboard.press('Control+A')
			await page.keyboard.type('the peer rewrote the whole thing')

			// No wait needed: the base is a revision counter, not a timestamp, so a
			// competing write is detected however fast it lands. (Under #9845 this
			// test had to sleep past a second boundary — that window is the race
			// #9848 closed.)

			// The other author saves first (plain API call, no base version — the
			// parameter is optional and old clients keep working). An unguarded
			// write still advances the counter, which is what makes the peer's
			// seeded revision stale.
			await api.patch(`/cards/${state.cardId}`, { description: 'the admin rewrote it first' })

			// The peer saves second. Previously this clobbered the admin's text.
			await page.locator('.card-modal__desc-actions button', { hasText: 'Save' }).click()

			// Now it is refused, and the conflict panel shows what landed while
			// the peer was typing — so the admin's text is visible and recoverable.
			const conflict = page.locator('.card-modal__desc-conflict')
			await expect(conflict).toBeVisible({ timeout: 15_000 })
			await expect(conflict).toContainText('the admin rewrote it first')

			// The peer's own draft is untouched — the editor is still open with it.
			await expect(prose).toContainText('the peer rewrote the whole thing')

			// Nothing was written: the stored description is still the admin's.
			const stillTheirs = await api.get(`/cards/${state.cardId}`)
			expect(stillTheirs.description).toBe('the admin rewrote it first')

			// The peer resolves the conflict deliberately, keeping their version.
			await conflict.locator('button', { hasText: 'Keep my version' }).click()
			await expect(page.locator('.card-modal__desc-rendered'))
				.toContainText('the peer rewrote the whole thing', { timeout: 15_000 })
			await expect(page.locator('.card-modal__desc-conflict')).toHaveCount(0)
		} finally {
			await peerCtx.close()
		}
	})

	test('the ordinary single-editor save path is unaffected', async ({ browser, peer }) => {
		const peerCtx = await browser.newContext()
		try {
			const page = await peerCtx.newPage()
			await ncLogin(page, { user: peer.user, pass: peer.pass })

			await page.goto(state.cardUrl)
			await expect(page.locator('.card-modal__desc-rendered')).toBeVisible({ timeout: 15_000 })

			// Two consecutive edits by the same person, with nobody else involved:
			// no conflict prompt may appear on either of them.
			for (const text of ['solo edit one', 'solo edit two']) {
				await page.locator('.card-modal__desc-view').click()
				const prose = page.locator('.card-modal__section .kanso-md-editor .ProseMirror')
				await expect(prose).toBeVisible({ timeout: 8000 })
				await prose.click()
				await page.keyboard.press('Control+A')
				await page.keyboard.type(text)
				await page.locator('.card-modal__desc-actions button', { hasText: 'Save' }).click()
				await expect(page.locator('.card-modal__desc-rendered')).toContainText(text, { timeout: 15_000 })
				await expect(page.locator('.card-modal__desc-conflict')).toHaveCount(0)
			}
		} finally {
			await peerCtx.close()
		}
	})

	// The property below can ONLY be shown against a real database: the guard is a
	// conditional UPDATE, and the unit suite mocks the card mapper away entirely,
	// so asserting "exactly one wins" there would prove nothing about the SQL.
	test('simultaneous saves on the same base: exactly one lands', async () => {
		const stack = await api.post('/stacks', { boardId: state.boardId, title: 'Race' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Raced card' })
		await api.patch(`/cards/${card.id}`, { description: 'the version both editors saw' })

		const seeded = await api.get(`/cards/${card.id}`)
		const base = seeded.descriptionRevision
		expect(typeof base).toBe('number')

		// SIX writers, all seeded from the SAME base, all fired at once. Two would
		// not be discriminating — sequential execution passes that too. With a fan
		// of six, an implementation that merely re-checks in PHP before writing
		// lets several through, so "exactly one 200" is a real statement about the
		// conditional UPDATE and not about request timing.
		const authors = ['A', 'B', 'C', 'D', 'E', 'F']
		const responses = await Promise.all(authors.map((name) =>
			api.raw('PATCH', `/cards/${card.id}`, {
				description: `author ${name} rewrote it`,
				baseDescriptionRevision: base,
			}),
		))

		const accepted = responses.filter((r) => r.status === 200)
		const refused = responses.filter((r) => r.status === 409)
		expect(accepted).toHaveLength(1)
		expect(refused).toHaveLength(authors.length - 1)

		const winnerBody = await accepted[0].json()

		// Every loser is told what actually landed and which revision to retry
		// from, so nothing it was holding has to be thrown away blindly.
		for (const r of refused) {
			const body = await r.json()
			expect(body.error).toBe('description_conflict')
			expect(body.description).toBe(winnerBody.description)
			expect(body.revision).toBe(base + 1)
		}

		// And the stored card is the winner's text verbatim — never a mix, never a
		// loser's, and the counter moved exactly once for the whole fan.
		const stored = await api.get(`/cards/${card.id}`)
		expect(stored.description).toBe(winnerBody.description)
		expect(stored.descriptionRevision).toBe(base + 1)
	})

	test('simultaneous UNGUARDED saves each move the counter exactly once', async () => {
		// The counter has to climb monotonically even when nobody is claiming it:
		// a bump computed from a value read before the transaction opened would
		// stall (two writes, one increment — a later guarded save would then
		// clobber one of them unseen) or walk backwards.
		const stack = await api.post('/stacks', { boardId: state.boardId, title: 'Bump race' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Bumped card' })
		await api.patch(`/cards/${card.id}`, { description: 'starting point' })

		const seeded = await api.get(`/cards/${card.id}`)
		const base = seeded.descriptionRevision

		const authors = ['A', 'B', 'C', 'D', 'E', 'F']
		const responses = await Promise.all(authors.map((name) =>
			// No base version at all — the last-writer-wins path.
			api.raw('PATCH', `/cards/${card.id}`, { description: `unguarded ${name}` }),
		))
		for (const r of responses) {
			expect(r.status).toBe(200)
		}

		const stored = await api.get(`/cards/${card.id}`)
		expect(stored.descriptionRevision).toBe(base + authors.length)
	})

	test('a client that sends no base version still writes, and still moves the counter', async () => {
		// Back-compat: the MCP server and third-party API clients never send a base
		// version and must keep last-writer-wins. Their write still has to advance
		// the revision, or an editor seeded before it would overwrite it unnoticed.
		const stack = await api.post('/stacks', { boardId: state.boardId, title: 'Legacy' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Unguarded card' })

		await api.patch(`/cards/${card.id}`, { description: 'first' })
		const afterFirst = await api.get(`/cards/${card.id}`)
		await api.patch(`/cards/${card.id}`, { description: 'second' })
		const afterSecond = await api.get(`/cards/${card.id}`)

		expect(afterSecond.description).toBe('second')
		expect(afterSecond.descriptionRevision).toBe(afterFirst.descriptionRevision + 1)

		// A non-description save must NOT move it — that is what makes the token a
		// real description revision rather than another `lastModified`.
		await api.patch(`/cards/${card.id}`, { title: 'Renamed, not rewritten' })
		const afterRename = await api.get(`/cards/${card.id}`)
		expect(afterRename.descriptionRevision).toBe(afterSecond.descriptionRevision)

		// And an editor holding the pre-`second` revision is refused.
		const stale = await api.raw('PATCH', `/cards/${card.id}`, {
			description: 'based on a version two writes ago',
			baseDescriptionRevision: afterFirst.descriptionRevision,
		})
		expect(stale.status).toBe(409)
	})
})
