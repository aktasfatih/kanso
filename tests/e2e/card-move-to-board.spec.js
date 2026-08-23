// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, BASE, api, ncLogin } from './helpers.js'

// Live (non-archived) cards in a stack, top-to-bottom, from the board payload.
async function stackTitles(boardId, stackId) {
	const board = await api.get(`/boards/${boardId}`)
	return board.cards
		.filter((c) => c.stackId === stackId && !c.archived)
		.sort((a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0))
		.map((c) => c.title)
}

// #3679 — "Move to board…" from the card ⋯ menu: relocate a single card to
// another board (created on the target, removed from the source).
test.describe('Move card to another board (card ⋯ menu)', () => {
	const state = { srcBoardId: 0, dstBoardId: 0, srcStackId: 0, dstStackId: 0, labelId: 0, srcId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const src = await api.post('/boards', { title: 'Move-Src E2E' })
		state.srcBoardId = src.id
		state.srcStackId = (await api.post('/stacks', { boardId: src.id, title: 'Source Column' })).id

		const dst = await api.post('/boards', { title: 'Move-Dst E2E' })
		state.dstBoardId = dst.id
		state.dstStackId = (await api.post('/stacks', { boardId: dst.id, title: 'Landing Column' })).id
		// A matching label (same name + color) on BOTH boards so the map-over keeps it.
		await api.post('/labels', { boardId: src.id, title: 'Important', color: 'e01e01' })
		const dstLabel = await api.post('/labels', { boardId: dst.id, title: 'Important', color: 'e01e01' })
		state.labelId = dstLabel.id

		const card = await api.post('/cards', { stackId: state.srcStackId, title: 'Relocatable card' })
		state.srcId = card.id
		await api.patch(`/cards/${card.id}`, { description: 'travels with me', priority: 3 })
		const srcLabels = await api.get(`/boards/${src.id}`)
		const srcLabelId = srcLabels.labels.find((l) => l.title === 'Important').id
		await api.put(`/cards/${card.id}/labels/${srcLabelId}`)
		await api.post(`/cards/${card.id}/checklist`, { title: 'carry me too' })

		state.cardUrl = `${BASE}/index.php/apps/kanso/#/board/${src.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.srcBoardId) await api.delete(`/boards/${state.srcBoardId}`).catch(() => {})
		if (state.dstBoardId) await api.delete(`/boards/${state.dstBoardId}`).catch(() => {})
	})

	test('moves the card off the source board and onto the target', async ({ page }) => {
		// Preconditions: source has the card, target is empty.
		expect(await stackTitles(state.srcBoardId, state.srcStackId)).toEqual(['Relocatable card'])
		expect(await stackTitles(state.dstBoardId, state.dstStackId)).toEqual([])

		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Open the ⋯ menu and click "Move to board…".
		await page.locator('.card-modal__actions-menu button').first().click()
		await page.getByRole('menuitem', { name: 'Move to board…' }).click()

		// The move dialog opens (reuses the copy picker); pick the target board+column.
		await page.waitForSelector('.card-modal__copy-dialog', { timeout: 8_000 })
		await page.locator('.card-modal__copy-field select').first()
			.selectOption({ label: 'Move-Dst E2E' })
		await page.locator('.card-modal__copy-field select').nth(1)
			.selectOption({ label: 'Landing Column' })
		await page.getByRole('button', { name: 'Move', exact: true }).click()

		// The card LEFT the source board...
		await expect
			.poll(() => stackTitles(state.srcBoardId, state.srcStackId), { timeout: 8_000 })
			.toEqual([])
		// ...and APPEARS on the target board.
		await expect
			.poll(() => stackTitles(state.dstBoardId, state.dstStackId), { timeout: 8_000 })
			.toEqual(['Relocatable card'])

		// The relocated card is a NEW card (fresh id) carrying the content + mapped label.
		const dstBoard = await api.get(`/boards/${state.dstBoardId}`)
		const moved = dstBoard.cards.find((c) => c.stackId === state.dstStackId && !c.archived)
		expect(moved).toBeTruthy()
		expect(moved.id).not.toBe(state.srcId)

		const detail = await api.get(`/cards/${moved.id}`)
		expect(detail.description).toBe('travels with me')
		expect(detail.priority).toBe(3)
		expect(detail.labelIds).toContain(state.labelId)
		expect(detail.checklistItems.map((i) => i.title)).toEqual(['carry me too'])

		// The original id is gone (soft-deleted on the source): its detail 404s.
		const r = await api.raw('GET', `/cards/${state.srcId}`)
		expect(r.status).toBe(404)
	})
})
