// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, gotoBoard } from './helpers.js'

test.describe('Estimate scales', () => {
	const state = { boardId: 0, cardId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: `Scales ${Date.now()}` })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		state.cardId = (await api.post('/cards', { stackId: stack.id, title: 'Scaled card' })).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('preset, number and custom scales validate estimates on the server', async () => {
		const roman = await api.patch(`/boards/${state.boardId}`, { estimateScale: 'roman' })
		expect(roman.estimateTokens.map((t) => t.label)).toContain('VIII')
		expect((await api.patch(`/cards/${state.cardId}`, { estimate: 'IV' })).estimate).toBe('IV')
		expect((await api.raw('PATCH', `/cards/${state.cardId}`, { estimate: '4' })).status).toBe(400)

		await api.patch(`/boards/${state.boardId}`, { estimateScale: 'number' })
		expect((await api.get(`/cards/${state.cardId}`)).estimate).toBeNull()
		expect((await api.patch(`/cards/${state.cardId}`, { estimate: '2.50' })).estimate).toBe('2.5')

		expect((await api.raw('PATCH', `/boards/${state.boardId}`, { estimateScale: 'custom' })).status).toBe(400)
		const custom = await api.patch(`/boards/${state.boardId}`, {
			estimateScale: 'custom',
			estimateCustom: [{ label: 'Pebble', value: 1 }, { label: 'Boulder', value: 13 }],
		})
		expect(custom.estimateTokens).toEqual([{ label: 'Pebble', value: 1 }, { label: 'Boulder', value: 13 }])
	})

	test('a custom scale is edited in board settings and offered on cards', async ({ page }) => {
		await api.patch(`/boards/${state.boardId}`, { estimateScale: 'none' })
		await ncLogin(page)
		await gotoBoard(page, state.boardId)
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.locator('#bs-rail-tab-workflow').click()

		// Switching back to Custom restores the board's saved labels.
		await page.locator('select[id^=estimate-scale]').selectOption('custom')
		const editor = page.locator('[data-test=estimate-custom-editor]')
		await expect(editor).toBeVisible()
		await expect(page.locator('[data-test=estimate-custom-label]')).toHaveCount(2)
		await page.locator('[data-test=estimate-custom-add]').click()
		await page.locator('[data-test=estimate-custom-label]').last().fill('Mountain')
		await page.locator('[data-test=estimate-custom-save]').click()
		await expect.poll(async () => (await api.get(`/boards/${state.boardId}`)).board.estimateTokens.map((t) => t.label))
			.toEqual(['Pebble', 'Boulder', 'Mountain'])

		await page.keyboard.press('Escape')
		await page.locator('.card-tile', { hasText: 'Scaled card' }).click()
		await page.locator('[data-pill=estimate]').click()
		await page.getByRole('button', { name: 'Mountain', exact: true }).click()
		await expect(page.locator('[data-pill=estimate]')).toContainText('Mountain')
		expect((await api.get(`/cards/${state.cardId}`)).estimate).toBe('Mountain')
	})

	test('an any-number board takes a typed estimate', async ({ page }) => {
		await api.patch(`/boards/${state.boardId}`, { estimateScale: 'number' })
		await ncLogin(page)
		await gotoBoard(page, state.boardId)
		await page.locator('.card-tile', { hasText: 'Scaled card' }).click()
		await page.locator('[data-pill=estimate]').click()
		await page.locator('[data-test=estimate-number-input]').fill('7.25')
		await page.keyboard.press('Enter')
		await expect(page.locator('[data-pill=estimate]')).toContainText('7.25')
		expect((await api.get(`/cards/${state.cardId}`)).estimate).toBe('7.25')
	})
})
