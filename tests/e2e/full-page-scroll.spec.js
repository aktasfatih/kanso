// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Full-page card scrolling', () => {
	let boardId
	let stackId
	let cardId
	let lastCommentId

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Full page scroll test' })
		boardId = board.id
		const stack = await api.post('/stacks', { boardId, title: 'Tasks' })
		stackId = stack.id
		const card = await api.post('/cards', { stackId: stack.id, title: 'Card with long content' })
		cardId = card.id
		await api.patch(`/cards/${cardId}`, {
			description: Array.from({ length: 60 }, (_, i) => `Description paragraph ${i + 1}`).join('\n\n'),
		})
		for (let i = 0; i < 12; i++) {
			const comment = await api.post(`/cards/${cardId}/comments`, { body: `Discussion comment ${i + 1}` })
			lastCommentId = comment.id
		}
	})

	test.afterAll(async () => {
		await api.put('/settings', { cardDiscussionPosition: 'side' }).catch(() => {})
		if (boardId) await api.delete(`/boards/${boardId}`).catch(() => {})
	})

	for (const position of ['side', 'bottom']) {
		test(`${position}: the page scrolls from the card and discussion`, async ({ page }) => {
			await api.put('/settings', { cardDiscussionPosition: position })
			await page.setViewportSize({ width: 1280, height: 800 })
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/card/${cardId}`)
			const root = page.locator('.card-page')
			const content = page.locator('.card-modal__content')
			const discussion = page.locator('.card-modal__discussion')
			await expect(content).toContainText('Description paragraph 60')
			await expect(discussion).toContainText('Discussion comment 12')
			if (position === 'bottom') await expect(page.locator('.card-modal')).toHaveClass(/card-modal--discussion-bottom/)
			await content.hover({ position: { x: 100, y: 60 } })
			await page.mouse.wheel(0, 650)
			await expect.poll(() => root.evaluate(el => el.scrollTop)).toBeGreaterThan(200)
			const header = await page.locator('.card-modal__header').boundingBox()
			const rootBox = await root.boundingBox()
			expect(header.y + header.height).toBeLessThan(rootBox.y)
			const bar = await page.locator('.card-page__bar').boundingBox()
			expect(bar.y + bar.height).toBeLessThan(rootBox.y)
			expect(await content.evaluate(el => el.scrollTop)).toBe(0)
			await page.locator(`#comment-${lastCommentId}`).scrollIntoViewIfNeeded()
			await expect(page.locator(`#comment-${lastCommentId}`)).toBeInViewport()
			await page.locator(`#comment-${lastCommentId}`).hover()
			const before = await root.evaluate(el => el.scrollTop)
			await page.mouse.wheel(0, -300)
			await expect.poll(() => root.evaluate(el => el.scrollTop)).toBeLessThan(before)
			expect(await page.locator('.card-modal__thread-scroll').evaluate(el => el.scrollTop)).toBe(0)
		})
	}

	test('mobile tabs use the page scroller', async ({ page }) => {
		await api.put('/settings', { cardDiscussionPosition: 'side' })
		await page.setViewportSize({ width: 390, height: 700 })
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/card/${cardId}`)
		const root = page.locator('.card-page')
		await expect(page.locator('.card-modal__content')).toContainText('Description paragraph 60')
		await page.locator('.card-modal__content').hover()
		await page.mouse.wheel(0, 700)
		await expect.poll(() => root.evaluate(el => el.scrollTop)).toBeGreaterThan(200)
		await page.locator('.card-modal__tabbar').getByRole('tab', { name: /Discussion/ }).click()
		await expect(page.locator('.card-modal__discussion')).toContainText('Discussion comment 12')
		await page.locator(`#comment-${lastCommentId}`).scrollIntoViewIfNeeded()
		await expect(page.locator(`#comment-${lastCommentId}`)).toBeInViewport()
		expect(await root.evaluate(el => el.scrollTop)).toBeGreaterThan(0)
		await page.locator('.card-modal__tabbar').getByRole('tab', { name: 'Card', exact: true }).click()
		await expect(page.locator('.card-modal__content')).toBeVisible()
	})

	test('short discussion does not stretch to the height of a long description', async ({ page }) => {
		await api.put('/settings', { cardDiscussionPosition: 'side' })
		const card = await api.post('/cards', { stackId, title: 'Short discussion' })
		await api.patch(`/cards/${card.id}`, {
			description: Array.from({ length: 60 }, (_, i) => `Long paragraph ${i}`).join('\n\n'),
		})
		await api.post(`/cards/${card.id}/comments`, { body: 'Only comment' })
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/card/${card.id}`)
		await expect(page.locator('.card-modal__discussion')).toContainText('Only comment')
		const geometry = await page.locator('.card-modal__body').evaluate(el => {
			const content = el.querySelector('.card-modal__content').getBoundingClientRect()
			const discussion = el.querySelector('.card-modal__discussion').getBoundingClientRect()
			return { contentHeight: content.height, discussionHeight: discussion.height }
		})
		expect(geometry.discussionHeight).toBeLessThan(geometry.contentHeight / 2)
	})
})
