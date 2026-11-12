// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, boardUrl, BASE } from './helpers.js'

async function startCandidate(input, value) {
	await input.dispatchEvent('compositionstart')
	const prevented = await input.evaluate((el, candidate) => {
		el.value = candidate
		el.dispatchEvent(new InputEvent('input', {
			bubbles: true,
			isComposing: true,
			inputType: 'insertCompositionText',
			data: candidate,
		}))
		const enter = new KeyboardEvent('keydown', {
			key: 'Enter', code: 'Enter', keyCode: 229,
			isComposing: true, bubbles: true, cancelable: true,
		})
		el.dispatchEvent(enter)
		return enter.defaultPrevented
	}, value)
	expect(prevented).toBe(false)
	await expect(input).toBeVisible()
}

async function finishCandidate(input) {
	await input.dispatchEvent('compositionend')
	await input.dispatchEvent('keyup', { key: 'Enter', code: 'Enter', isComposing: false })
}

function recordWrites(page, path) {
	const writes = []
	page.on('request', (request) => {
		if (request.method() !== 'GET' && path.test(new URL(request.url()).pathname)) writes.push(request)
	})
	return writes
}

test.describe('IME Enter confirmation', () => {
	let board
	let stack
	let card
	let pageErrors

	test.beforeEach(async ({ page }) => {
		pageErrors = []
		page.on('pageerror', (error) => pageErrors.push(error.message))
		board = await api.post('/boards', { title: 'IME confirmation' })
		stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		card = await api.post('/cards', { stackId: stack.id, title: 'IME card' })
		await ncLogin(page)
		await page.goto(boardUrl(board.id))
		await page.locator('.card-tile').filter({ hasText: 'IME card' }).click()
		await expect(page.locator('.card-modal')).toBeVisible()
	})

	test.afterEach(async () => {
		if (board) await api.delete(`/boards/${board.id}`)
		expect(pageErrors).toEqual([])
	})

	test('checklist addition waits for composition and accepts Android Enter', async ({ page }) => {
		const input = page.locator('.card-modal__checklist-add-input')
		await input.fill('Before')
		const writes = recordWrites(page, /\/cards\/\d+\/checklist$/)
		await startCandidate(input, 'Before候補')
		expect(writes).toHaveLength(0)
		expect((await api.get(`/cards/${card.id}`)).checklistItems).toHaveLength(0)
		await finishCandidate(input)
		expect(writes).toHaveLength(0)
		await input.dispatchEvent('keydown', {
			key: 'Enter', code: 'Enter', keyCode: 229, isComposing: false,
		})
		await expect(page.locator('.card-modal__checklist-item-title')).toHaveText('Before候補')
		await expect.poll(async () => (await api.get(`/cards/${card.id}`)).checklistItems.map((item) => item.title))
			.toEqual(['Before候補'])
		expect(writes).toHaveLength(1)
	})

	test('checklist rename keeps the editor open until ordinary Enter', async ({ page }) => {
		const item = await api.post(`/cards/${card.id}/checklist`, { title: 'Original item' })
		await page.reload()
		const row = page.locator(`.card-modal__checklist-item[data-item-id="${item.id}"]`)
		await row.locator('.card-modal__checklist-item-title').click()
		const input = row.locator('.card-modal__checklist-item-input')
		await input.fill('Before')
		const writes = recordWrites(page, /\/checklist\/\d+$/)
		await startCandidate(input, 'Before候補')
		expect(writes).toHaveLength(0)
		await finishCandidate(input)
		expect(writes).toHaveLength(0)
		await input.press('Enter')
		await expect(page.locator('.card-modal__checklist-item-title')).toHaveText('Before候補')
		await expect.poll(async () => (await api.get(`/cards/${card.id}`)).checklistItems[0].title).toBe('Before候補')
		expect(writes).toHaveLength(1)
	})

	test('sub-card addition waits for ordinary Enter', async ({ page }) => {
		const input = page.getByPlaceholder('Add a sub-card…')
		await input.fill('Before')
		const writes = recordWrites(page, /\/api\/cards$/)
		await startCandidate(input, 'Before候補')
		expect(writes).toHaveLength(0)
		expect((await api.get(`/cards/${card.id}`)).children).toHaveLength(0)
		await finishCandidate(input)
		expect(writes).toHaveLength(0)
		await input.press('Enter')
		await expect.poll(async () => (await api.get(`/cards/${card.id}`)).children.map((child) => child.title))
			.toEqual(['Before候補'])
		expect(writes).toHaveLength(1)
	})

	test('card title confirmation keeps the old title until ordinary Enter', async ({ page }) => {
		await page.locator('.card-modal__title').click()
		const input = page.locator('.card-modal__title-input')
		await input.fill('Before')
		const writes = recordWrites(page, new RegExp(`/cards/${card.id}$`))
		await startCandidate(input, 'Before候補')
		expect(writes).toHaveLength(0)
		expect((await api.get(`/cards/${card.id}`)).title).toBe('IME card')
		await finishCandidate(input)
		expect(writes).toHaveLength(0)
		await input.press('Enter')
		await expect(page.locator('.card-modal__title')).toHaveText('Before候補')
		await expect.poll(async () => (await api.get(`/cards/${card.id}`)).title).toBe('Before候補')
		expect(writes.length).toBeGreaterThan(0)
		for (const request of writes) expect(request.postDataJSON().title).toBe('Before候補')
	})

	test('board card composer does not submit its form while composing', async ({ page }) => {
		await page.keyboard.press('Escape')
		const input = page.locator('.card-composer__input')
		await input.fill('Before')
		const writes = recordWrites(page, /\/api\/cards$/)
		await startCandidate(input, 'Before候補')
		expect(writes).toHaveLength(0)
		await finishCandidate(input)
		expect(writes).toHaveLength(0)
		await input.press('Enter')
		await expect(page.locator('.card-tile').filter({ hasText: 'Before候補' })).toBeVisible()
		await expect.poll(async () => (await api.get(`/boards/${board.id}`)).cards.map((item) => item.title).sort())
			.toEqual(['Before候補', 'IME card'])
		expect(writes).toHaveLength(1)
	})

	test('view rename ignores candidate-confirmation keyup after composition ends', async ({ page }) => {
		const created = await api.put('/views', { name: 'IME view', filter: {}, groupBy: 'board', display: 'list' })
		const view = created.views.find((item) => item.name === 'IME view')
		try {
			await page.goto(`${BASE}/index.php/apps/kanso#/views/${view.id}`)
			await page.locator('.view-page__title').click()
			const input = page.locator('.view-page__title-input')
			await input.fill('Before')
			const writes = recordWrites(page, new RegExp(`/views/${view.id}$`))
			await startCandidate(input, 'Before候補')
			expect(writes).toHaveLength(0)
			await finishCandidate(input)
			await expect(input).toBeVisible()
			expect(writes).toHaveLength(0)
			expect((await api.get('/views')).views.find((item) => item.id === view.id).name).toBe('IME view')
			await input.press('Enter')
			await expect(page.locator('.view-page__title')).toHaveText('Before候補')
			await expect.poll(async () => (await api.get('/views')).views.find((item) => item.id === view.id).name)
				.toBe('Before候補')
			expect(writes).toHaveLength(1)
		} finally {
			await api.delete(`/views/${view.id}`)
		}
	})
})
