// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, BASE, api, ncLogin } from './helpers.js'

// The raw Response form (does NOT throw) so the reject tests can assert on r.ok.
const rawPost = (path, body) => api.raw('POST', path, body)

const boardCard = (board, id) => board.cards.find((c) => c.id === id)

test.describe('Card relations (#3404)', () => {
	const state = { boardId: 0, stackId: 0, a: 0, b: 0, cTitle: '' }

	test.beforeAll(async () => {
		state.cTitle = 'Rel-B ' + Math.floor(Date.now() / 1000)
		const board = await api.post('/boards', { title: 'Relations ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'To do' })).id
		state.a = (await api.post('/cards', { stackId: state.stackId, title: 'Rel-A ' + Math.floor(Date.now() / 1000) })).id
		state.b = (await api.post('/cards', { stackId: state.stackId, title: state.cTitle })).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('a blocks relation is directional and drives the blocked badge', async () => {
		await api.post(`/cards/${state.a}/relations`, { otherCardId: state.b, kind: 'blocks' })

		// A blocks B; B is blocked by A.
		const a = await api.get(`/cards/${state.a}`)
		const b = await api.get(`/cards/${state.b}`)
		expect(a.relations.blocks.map((r) => r.cardId)).toContain(state.b)
		expect(b.relations.blockedBy.map((r) => r.cardId)).toContain(state.a)

		// The board payload flags B blocked (its blocker A isn't done), A not.
		let board = await api.get(`/boards/${state.boardId}`)
		expect(boardCard(board, state.b).blocked).toBe(true)
		expect(boardCard(board, state.a).blocked).toBe(false)

		// Completing the blocker clears the badge.
		await api.patch(`/cards/${state.a}`, { done: true })
		board = await api.get(`/boards/${state.boardId}`)
		expect(boardCard(board, state.b).blocked).toBe(false)
		await api.patch(`/cards/${state.a}`, { done: false })
	})

	test('a reverse blocks relation that would cycle is rejected', async () => {
		// A already blocks B, so "B blocks A" would close a cycle.
		const r = await rawPost(`/cards/${state.b}/relations`, { otherCardId: state.a, kind: 'blocks' })
		expect(r.ok).toBe(false)
	})

	test('a self relation is rejected', async () => {
		const r = await rawPost(`/cards/${state.a}/relations`, { otherCardId: state.a, kind: 'relates' })
		expect(r.ok).toBe(false)
	})

	test('a symmetric relation shows on both cards and can be removed', async () => {
		const rel = await api.post(`/cards/${state.a}/relations`, { otherCardId: state.b, kind: 'duplicates' })

		expect((await api.get(`/cards/${state.a}`)).relations.duplicates.map((r) => r.cardId)).toContain(state.b)
		expect((await api.get(`/cards/${state.b}`)).relations.duplicates.map((r) => r.cardId)).toContain(state.a)

		await api.delete(`/cards/${state.a}/relations/${rel.id}`)
		expect((await api.get(`/cards/${state.a}`)).relations.duplicates).toHaveLength(0)
	})

	test('add and remove a relation from the card modal', async ({ page }) => {
		// Start clean: drop the blocks relation from the API tests.
		const a = await api.get(`/cards/${state.a}`)
		for (const rel of a.relations.blocks) {
			await api.delete(`/cards/${state.a}/relations/${rel.id}`)
		}

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.a}`)
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 15_000 })

		// The relation editor lives behind the ⋯ (more) menu — open it via the
		// "Add relation" action, which reveals the inline relation form.
		await page.locator('.card-modal__actions-menu button').first().click()
		await page.getByRole('menuitem', { name: /add relation/i }).click()

		// Add "A blocks B" via the revealed relation controls.
		await page.locator('.card-modal__relation-kind').selectOption('blocks')
		await page.locator('.card-modal__relation-target').selectOption(String(state.b))
		await page.locator('.card-modal__relation-add-btn', { hasText: /^Add$/ }).click()

		const row = page.locator('.card-modal__relation-row', { hasText: state.cTitle })
		await expect(row).toBeVisible({ timeout: 8_000 })

		// Remove it again.
		await row.locator('.card-modal__child-remove').click()
		await expect(page.locator('.card-modal__relation-row', { hasText: state.cTitle })).toHaveCount(0, { timeout: 8_000 })
	})

	test('clicking a relation row opens the related card', async ({ page }) => {
		// Ensure A relates to B (symmetric, so it shows on A's modal).
		const a = await api.get(`/cards/${state.a}`)
		if (!a.relations.relates.map((r) => r.cardId).includes(state.b)) {
			await api.post(`/cards/${state.a}/relations`, { otherCardId: state.b, kind: 'relates' })
		}

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.a}`)
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 15_000 })

		// The related row's title is a button — clicking it navigates to that card.
		const row = page.locator('.card-modal__relation-row', { hasText: state.cTitle })
		await expect(row).toBeVisible({ timeout: 8_000 })
		await row.locator('.card-modal__relation-title').click()

		// The route (and the modal title) should now be card B.
		await page.waitForURL(new RegExp(`/card/${state.b}(?!\\d)`), { timeout: 8_000 })
		await expect(page.locator('.card-modal').getByText(state.cTitle).first()).toBeVisible({ timeout: 8_000 })
	})
})
