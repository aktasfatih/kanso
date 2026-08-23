// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE, me } from './helpers.js'

test.describe('Review-type stage gating', () => {
	const state = {
		boardId: 0,
		stackId: 0,
		cardId: 0,
		codeTypeId: 0,
		qaTypeId: 0,
		cardUrl: '',
	}

	test.beforeAll(async () => {
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === 'Review Gating E2E Board') {
				await api.delete(`/boards/${b.id}`)
			}
		}

		const board = await api.post('/boards', { title: 'Review Gating E2E Board' })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Backlog' })
		state.stackId = stack.id
		const card = await api.post('/cards', { stackId: stack.id, title: 'Gated card' })
		state.cardId = card.id

		// Code = stage 0, QA = stage 1. Lower stage gates higher.
		const code = await api.post('/review-types', { boardId: board.id, title: 'Code', stage: 0 })
		state.codeTypeId = code.id
		const qa = await api.post('/review-types', { boardId: board.id, title: 'QA', stage: 1 })
		state.qaTypeId = qa.id

		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await api.delete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('QA renders gated while Code is pending, then un-gates once Code is approved', async ({ page }) => {
		// Request both reviews from the current user (one per type is allowed on the same card).
		await api.put(`/cards/${state.cardId}/reviews/${me}`, { reviewTypeId: state.codeTypeId })
		await api.put(`/cards/${state.cardId}/reviews/${me}`, { reviewTypeId: state.qaTypeId })

		// The server should mark the QA (stage 1) review gated, blocked by the Code
		// (stage 0) review, and QA's notification should be deferred (notifiedAt null).
		let detail = await api.get(`/cards/${state.cardId}`)
		let qa = detail.reviews.find((r) => r.reviewTypeId === state.qaTypeId)
		let code = detail.reviews.find((r) => r.reviewTypeId === state.codeTypeId)
		expect(qa.gated).toBe(true)
		expect(qa.blockedBy).toContain(code.id)
		expect(qa.notifiedAt).toBeNull()
		// Code (stage 0) is not gated and was notified at request time.
		expect(code.gated).toBe(false)

		// The QA chip should render distinctly gated in the modal.
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 12_000 })
		const gatedChip = page.locator('.card-modal__review-pill--gated')
		await expect(gatedChip).toBeVisible({ timeout: 8_000 })
		await expect(gatedChip.locator('.card-modal__review-type-badge')).toContainText('QA')

		// Approve the Code review → QA un-gates and its deferred notification fires.
		await api.patch(`/cards/${state.cardId}/reviews/${code.id}`, { state: 'approved' })

		detail = await api.get(`/cards/${state.cardId}`)
		qa = detail.reviews.find((r) => r.reviewTypeId === state.qaTypeId)
		expect(qa.gated).toBe(false)
		expect(qa.blockedBy).toEqual([])
		expect(qa.state).toBe('pending')
		// The deferred notification fired: notifiedAt is now stamped.
		expect(qa.notifiedAt).not.toBeNull()

		// The gated chip is gone in the UI after reload.
		await page.reload()
		await page.waitForSelector('.card-modal', { timeout: 12_000 })
		await expect(page.locator('.card-modal__review-pill--gated')).toHaveCount(0, { timeout: 8_000 })
	})
})
