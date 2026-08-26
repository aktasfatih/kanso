// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #3767 — open-card-modal realtime freshness. The board delta only patched the
// board SUMMARY cache, so a card modal open in another tab/browser went stale:
// title, description and comment changes never appeared until close/reopen.
// The fix invalidates the open card's DETAIL queries when a delta change row
// for that card arrives. This suite drives two browser contexts (admin mutates
// via API, tester watches an open modal) and works with notify_push (near-
// instant) or the 5s delta-poll fallback — both under the 15s budgets used
// here, matching realtime.spec.js.
//
// Draft safety is asserted too: the modal's editors copy into local draft refs
// on edit-start, so a remote refresh must never clobber a dirty editor - and
// since #9845 the dirty draft's SAVE no longer clobbers the remote text either;
// it surfaces the conflict and lets the user pick (see description-conflict.spec.js).

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Realtime card modal freshness', () => {
	// Drives two distinct users (admin + tester) and logs each in explicitly — so
	// it must NOT inherit the shared authenticated storageState, or every context
	// would start as admin and ncLogin would no-op.
	test.use({ storageState: { cookies: [], origins: [] } })

	const state = { boardId: 0, cardId: 0, cardUrl: '' }

	test.beforeAll(async ({ peer }) => {
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === 'Realtime Modal Board') {
				await api.delete(`/boards/${b.id}`)
			}
		}
		const board = await api.post('/boards', { title: 'Realtime Modal Board' })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'S1' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Modal card v1' })
		state.cardId = card.id
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

	test('open modal picks up remote title, description and comment without reload', async ({ browser, peer }) => {
		const testerCtx = await browser.newContext()
		try {
			const page = await testerCtx.newPage()
			await ncLogin(page, { user: peer.user, pass: peer.pass })

			// Tester opens the card modal and keeps it open — no further navigation.
			await page.goto(state.cardUrl)
			await expect(page.locator('.card-modal__title')).toHaveText('Modal card v1', { timeout: 15_000 })

			// Admin (other "tab") edits title + description and adds a comment.
			await api.patch(`/cards/${state.cardId}`, { title: 'Modal card v2' })
			await api.patch(`/cards/${state.cardId}`, { description: 'remote description v1' })
			await api.post(`/cards/${state.cardId}/comments`, { body: 'remote comment v1' })

			// All three must land in the OPEN modal: push is near-instant, the
			// delta-poll fallback fires every 5s — 15s is generous for either.
			await expect(page.locator('.card-modal__title')).toHaveText('Modal card v2', { timeout: 15_000 })
			await expect(page.locator('.card-modal__desc-rendered')).toContainText('remote description v1', { timeout: 15_000 })
			await expect(
				page.locator('.card-modal__comment-body').filter({ hasText: 'remote comment v1' }),
			).toBeVisible({ timeout: 15_000 })
		} finally {
			await testerCtx.close()
		}
	})

	test('a remote change never clobbers a dirty description draft', async ({ browser, peer }) => {
		const testerCtx = await browser.newContext()
		try {
			const page = await testerCtx.newPage()
			await ncLogin(page, { user: peer.user, pass: peer.pass })

			await page.goto(state.cardUrl)
			await expect(page.locator('.card-modal__desc-rendered')).toContainText('remote description v1', { timeout: 15_000 })

			// Tester starts editing the description — the editor seeds a LOCAL
			// draft from the current text; from here on it must be untouchable.
			// The description editor is now a Tiptap WYSIWYG editor (MarkdownEditor.vue);
			// clicking the .card-modal__desc-view activates edit mode.
			await page.locator('.card-modal__desc-view').click()

			// Wait for the Tiptap editor to appear inside the description section.
			const editorSection = page.locator('.card-modal__section .kanso-md-editor')
			await expect(editorSection).toBeVisible({ timeout: 8000 })
			const prose = editorSection.locator('.ProseMirror')
			await expect(prose).toBeVisible({ timeout: 4000 })

			// Clear the seeded text and type the local draft.
			await prose.click()
			await page.keyboard.press('Control+A')
			await page.keyboard.type('my precious local draft')

			// Admin edits BOTH the title and the description remotely.
			await api.patch(`/cards/${state.cardId}`, { title: 'Modal card v3' })
			await api.patch(`/cards/${state.cardId}`, { description: 'remote description v2' })

			// The title updating proves the remote change reached the open modal
			// (card detail refetched) while the editor was dirty...
			await expect(page.locator('.card-modal__title')).toHaveText('Modal card v3', { timeout: 15_000 })

			// ...and the dirty draft survived it (the MarkdownEditor only accepts an
			// external v-model update when the markdown differs from what the editor
			// already holds, so a remote patch to draftDescription is ignored while
			// editing is in progress).
			await expect(editorSection).toBeVisible()
			await expect(prose).toContainText('my precious local draft')

			// The tester's save no longer wins silently (#9845): the description was
			// rewritten under them, so the save is refused and BOTH versions are
			// put in front of them - their draft stays in the editor, the text that
			// landed while they typed is shown beside it.
			await page.locator('.card-modal__desc-actions button', { hasText: 'Save' }).click()
			const conflict = page.locator('.card-modal__desc-conflict')
			await expect(conflict).toBeVisible({ timeout: 15_000 })
			await expect(conflict).toContainText('remote description v2')
			await expect(prose).toContainText('my precious local draft')

			// Choosing "Keep my version" is what makes the draft win now - the same
			// end state as before, but deliberately, never by accident.
			await conflict.locator('button', { hasText: 'Keep my version' }).click()
			await expect(page.locator('.card-modal__desc-rendered')).toContainText('my precious local draft', { timeout: 15_000 })
		} finally {
			await testerCtx.close()
		}
	})
})
