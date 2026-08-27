// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #9845 — optimistic concurrency on card descriptions.
//
// Before this, two people with the same card open were pure last-writer-wins:
// whoever saved second silently overwrote the other's text, with no warning to
// either side. For a long description that is unrecoverable data loss.
//
// The editor now sends the card version it was seeded from (`baseLastModified`)
// and the server refuses a write based on a stale version with
// 409 {"error": "description_conflict", description, lastModified}. The check is
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

			// `lastModified` has second resolution: make sure the other author's
			// write lands in a strictly later second than the version the peer's
			// editor was seeded from, so the conflict is deterministic.
			await page.waitForTimeout(1200)

			// The other author saves first (plain API call, no base version — the
			// parameter is optional and old clients keep working).
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
})
