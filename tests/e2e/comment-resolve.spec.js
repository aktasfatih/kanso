// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Resolving a discussion thread collapses it under the card.
 *
 * The point of the feature is decluttering, so the assertions are about what
 * DISAPPEARS: once a thread is resolved its body, its replies and its reply
 * composer are gone, leaving one summary row. The reload in the middle is the
 * load-bearing step — it proves the state came back from the server rather than
 * living in this tab's memory.
 */
import { test, expect, api, ncLogin, BASE } from './helpers.js'

const GROUP = '.card-modal__comment-group'
const SUMMARY = '.card-modal__thread-summary'
const REPLIES = '.card-modal__replies .card-modal__comment--reply'

test.describe('Comment threads / resolve', () => {
	const state = { boardId: 0, cardId: 0, topId: 0, replyId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		for (const b of await api.get('/boards')) {
			if (b.title === 'Resolve E2E Board') await api.delete(`/boards/${b.id}`)
		}
		const board = await api.post('/boards', { title: 'Resolve E2E Board' })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Card With Resolvable Thread' })
		state.cardId = card.id
		// Seed through the API so the UI test starts from a known thread.
		const top = await api.post(`/cards/${card.id}/comments`, { body: 'Should we ship this?' })
		state.topId = top.id
		const reply = await api.post(`/cards/${card.id}/comments`, {
			body: 'Yes, settled.',
			parentCommentId: top.id,
		})
		state.replyId = reply.id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('resolve collapses the thread, survives a reload, and reopen restores it', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Open to begin with: body + reply visible, no summary row.
		await expect(page.locator(REPLIES)).toHaveCount(1, { timeout: 8000 })
		await expect(page.locator(SUMMARY)).toHaveCount(0)

		// Resolve it from the top-level comment's controls.
		const group = page.locator(GROUP).first()
		await group.locator('.card-modal__comment-resolve-btn').click()

		// Collapsed: one summary row, no reply rendered at all.
		await expect(page.locator(SUMMARY)).toHaveCount(1, { timeout: 6000 })
		await expect(page.locator(SUMMARY)).toContainText('Resolved')
		await expect(page.locator(REPLIES)).toHaveCount(0)
		await expect(page.locator('.card-modal__comment-body')).toHaveCount(0)

		// THE load-bearing assertion: a full reload throws away every scrap of
		// client state, so a thread still collapsed here is collapsed because the
		// SERVER said so (resolved_at), not because this tab remembered.
		await page.reload()
		await page.waitForSelector('.card-modal', { timeout: 10_000 })
		await expect(page.locator(SUMMARY)).toHaveCount(1, { timeout: 8000 })
		await expect(page.locator(REPLIES)).toHaveCount(0)

		// The API agrees — resolvedAt is set on the top-level comment only.
		const afterResolve = await api.get(`/cards/${state.cardId}/comments`)
		const top = afterResolve.find((c) => c.id === state.topId)
		const reply = afterResolve.find((c) => c.id === state.replyId)
		expect(top.resolvedAt).toBeGreaterThan(0)
		expect(reply.resolvedAt).toBe(0)

		// Peek: expanding locally reveals the thread WITHOUT reopening it.
		await page.locator('.card-modal__thread-summary-toggle').click()
		await expect(page.locator(REPLIES)).toHaveCount(1, { timeout: 6000 })
		const stillResolved = await api.get(`/cards/${state.cardId}/comments`)
		expect(stillResolved.find((c) => c.id === state.topId).resolvedAt).toBeGreaterThan(0)

		// Reopen for everyone: the summary row goes away and stays away.
		await page.locator(GROUP).first().locator('.card-modal__comment-resolve-btn').click()
		await expect(page.locator(SUMMARY)).toHaveCount(0, { timeout: 6000 })
		await expect(page.locator(REPLIES)).toHaveCount(1)

		await page.reload()
		await page.waitForSelector('.card-modal', { timeout: 10_000 })
		await expect(page.locator(REPLIES)).toHaveCount(1, { timeout: 8000 })
		await expect(page.locator(SUMMARY)).toHaveCount(0)

		const afterReopen = await api.get(`/cards/${state.cardId}/comments`)
		expect(afterReopen.find((c) => c.id === state.topId).resolvedAt).toBe(0)
	})

	test('a deep link into a resolved thread force-expands it', async ({ page }) => {
		// A mention or reminder notification must never land on a card that
		// renders nothing. Resolve server-side, then arrive via ?comment=<replyId>.
		await api.put(`/comments/${state.topId}/resolve`)

		await ncLogin(page)
		await page.goto(`${state.cardUrl}?comment=${state.replyId}`)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// The linked reply is rendered and reachable, despite the thread being
		// resolved — and the thread is still resolved for everyone else.
		await expect(page.locator(`#comment-${state.replyId}`)).toBeVisible({ timeout: 10_000 })
		await expect(page.locator(SUMMARY)).toHaveCount(0)
		const comments = await api.get(`/cards/${state.cardId}/comments`)
		expect(comments.find((c) => c.id === state.topId).resolvedAt).toBeGreaterThan(0)

		await api.delete(`/comments/${state.topId}/resolve`)
	})

	test('the resolve endpoints are idempotent and refuse a reply', async () => {
		// Idempotent: a repeated PUT/DELETE is a 200 no-op, not an error.
		const first = await api.put(`/comments/${state.topId}/resolve`)
		expect(first.resolvedAt).toBeGreaterThan(0)
		const again = await api.put(`/comments/${state.topId}/resolve`)
		expect(again.resolvedAt).toBe(first.resolvedAt)

		expect((await api.delete(`/comments/${state.topId}/resolve`))).toBeNull()
		const reopened = await api.get(`/cards/${state.cardId}/comments`)
		expect(reopened.find((c) => c.id === state.topId).resolvedAt).toBe(0)
		await api.delete(`/comments/${state.topId}/resolve`)

		// The THREAD is the unit: a reply has no resolved state of its own.
		const r = await api.raw('PUT', `/comments/${state.replyId}/resolve`)
		expect(r.status).toBe(400)
	})
})
