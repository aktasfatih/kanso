// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.use({ viewport: { width: 390, height: 700 }, isMobile: true, hasTouch: true })

async function swipe(page, x, startY, endY) {
	const session = await page.context().newCDPSession(page)
	try {
		await session.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y: startY }] })
		for (let i = 1; i <= 12; i++) {
			await session.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x, y: startY + (endY - startY) * i / 12 }] })
			await new Promise(resolve => setTimeout(resolve, 20))
		}
		await session.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] })
	} finally {
		await session.detach()
	}
}

test.describe('Mobile due date scrolling', () => {
	let boardId
	let cardId
	let lastCommentId

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Mobile due date scrolling' })
		boardId = board.id
		const stack = await api.post('/stacks', { boardId, title: 'To do' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Long card' })
		cardId = card.id
		await api.patch(`/cards/${cardId}`, {
			duedate: '2027-12-10T12:00:00Z',
			description: Array.from({ length: 80 }, (_, i) => `Paragraph ${i + 1}`).join('\n\n'),
		})
		for (let i = 0; i < 12; i++) {
			const comment = await api.post(`/cards/${cardId}/comments`, { body: `Comment ${i + 1}` })
			lastCommentId = comment.id
		}
	})

	test.afterAll(async () => {
		if (boardId) await api.delete(`/boards/${boardId}`).catch(() => {})
	})

	for (const mode of ['modal', 'page']) {
		test(`${mode}: an outside swipe dismisses the date sheet and scrolls the card`, async ({ page }) => {
			await ncLogin(page)
			const path = mode === 'modal' ? `/board/${boardId}/card/${cardId}` : `/card/${cardId}`
			await page.goto(`${BASE}/index.php/apps/kanso#${path}`)
			const due = page.locator('button.card-modal__pill[data-pill="due"]')
			const sheet = page.locator('.card-modal__popover--date')
			const root = mode === 'modal' ? page.locator('.modal-container__content') : page.locator('.card-page')
			await due.click()
			await expect(sheet).toBeVisible()
			const bounds = await sheet.boundingBox()
			expect(bounds.y).toBeGreaterThan(250)
			await swipe(page, 200, bounds.y - 40, bounds.y - 150)
			await expect(sheet).toHaveCount(0)
			await expect.poll(() => root.evaluate(el => el.scrollTop)).toBeGreaterThan(30)
			await swipe(page, 200, 600, 300)
			await expect.poll(() => root.evaluate(el => el.scrollTop)).toBeGreaterThan(200)
			await page.locator('.card-modal__tabbar').getByRole('tab', { name: /Discussion/ }).click()
			await expect(page.locator(`#comment-${lastCommentId}`)).toBeVisible()
		})
	}

	test('the date sheet can be scrolled internally and closed explicitly', async ({ page }) => {
		await page.setViewportSize({ width: 390, height: 500 })
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${boardId}/card/${cardId}`)
		const due = page.locator('button.card-modal__pill[data-pill="due"]')
		const sheet = page.locator('.card-modal__popover--date')
		await due.click()
		await expect(sheet).toBeVisible()
		const bounds = await sheet.boundingBox()
		expect(await sheet.evaluate(el => el.scrollHeight - el.clientHeight)).toBeGreaterThan(0)
		await swipe(page, 200, bounds.y + bounds.height - 40, bounds.y + 60)
		await expect(sheet).toBeVisible()
		await expect.poll(() => sheet.evaluate(el => el.scrollTop)).toBeGreaterThan(0)
		const close = sheet.getByRole('button', { name: 'Close', exact: true })
		const closeBounds = await close.boundingBox()
		const sheetBounds = await sheet.boundingBox()
		expect(closeBounds.y).toBeGreaterThanOrEqual(sheetBounds.y)
		expect(closeBounds.y + closeBounds.height).toBeLessThanOrEqual(sheetBounds.y + sheetBounds.height)
		await close.focus()
		await page.keyboard.press('Enter')
		await expect(sheet).toHaveCount(0)
		await expect(due).toBeFocused()
	})

	test('an edited date saves when dismissed and a rejected save stays visible', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${boardId}/card/${cardId}`)
		const due = page.locator('button.card-modal__pill[data-pill="due"]')
		const sheet = page.locator('.card-modal__popover--date')
		await due.click()
		const date = sheet.locator('[data-date="end"]')
		await date.fill('2027-12-15T12:00')
		await date.focus()
		const bounds = await sheet.boundingBox()
		await swipe(page, 200, bounds.y - 40, bounds.y - 150)
		await expect(sheet).toHaveCount(0)
		await expect.poll(async () => (await api.get(`/cards/${cardId}`)).duedate).toContain('2027-12-15')
		await page.reload()
		await due.click()
		await page.route(`**/api/cards/${cardId}`, async route => {
			if (route.request().method() === 'PATCH') await route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ error: 'Date save refused' }) })
			else await route.continue()
		})
		await sheet.locator('[data-date="end"]').fill('2027-12-16T12:00')
		await sheet.locator('[data-date="end"]').focus()
		await sheet.getByRole('button', { name: 'Close', exact: true }).click()
		await expect(sheet).toHaveCount(0)
		await expect(page.getByText('Date save refused')).toBeVisible()
	})

	test('touching another picker opens it and a backdrop tap dismisses only the date sheet', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${boardId}/card/${cardId}`)
		const due = page.locator('button.card-modal__pill[data-pill="due"]')
		const priority = page.locator('button.card-modal__pill[data-pill="priority"]')
		const sheet = page.locator('.card-modal__popover--date')
		await due.click()
		const priorityBounds = await priority.boundingBox()
		await page.touchscreen.tap(priorityBounds.x + priorityBounds.width / 2, priorityBounds.y + priorityBounds.height / 2)
		await expect(sheet).toHaveCount(0)
		await expect(priority).toHaveAttribute('aria-expanded', 'true')
		await priority.click()
		await due.click()
		await page.touchscreen.tap(2, 250)
		await expect(sheet).toHaveCount(0)
		await expect(page.locator('.card-modal')).toBeVisible()
	})
})
