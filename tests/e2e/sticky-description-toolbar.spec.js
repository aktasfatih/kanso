// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Description toolbar while scrolling', () => {
	let boardId
	let cardId

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Sticky editor toolbar' })
		boardId = board.id
		const stack = await api.post('/stacks', { boardId, title: 'Tasks' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Long description' })
		cardId = card.id
		await api.patch(`/cards/${cardId}`, {
			description: Array.from({ length: 100 }, (_, i) => `Paragraph ${i + 1} with some content.`).join('\n\n'),
		})
		for (let i = 0; i < 15; i++) {
			await api.post(`/cards/${cardId}/checklist`, { title: `Step ${i + 1}` })
		}
	})

	test.afterAll(async () => {
		if (boardId) await api.delete(`/boards/${boardId}`).catch(() => {})
	})

	for (const variant of ['modal', 'page', 'mobile', 'mobile-page']) {
		test(`${variant}: toolbar follows the editor and formats a selection`, async ({ page }) => {
			if (variant.startsWith('mobile')) await page.setViewportSize({ width: 390, height: 700 })
			await ncLogin(page)
			const route = variant.endsWith('page') ? `/card/${cardId}` : `/board/${boardId}/card/${cardId}`
			await page.goto(`${BASE}/index.php/apps/kanso#${route}`)
			await page.locator('.card-modal__section-head').filter({ hasText: 'Description' }).getByRole('button', { name: 'Edit' }).click()
			const editor = page.locator('.card-modal__section .kanso-md-editor').first()
			const toolbar = editor.locator('.kanso-md-editor__toolbar')
			await expect(toolbar).toBeVisible()
			const prose = editor.locator('.ProseMirror')
			await expect(prose.locator('p')).toHaveCount(100)

			const geometry = await editor.evaluate(el => {
				let scroller = el.parentElement
				while (scroller && (!/(auto|scroll)/.test(getComputedStyle(scroller).overflowY) || scroller.scrollHeight <= scroller.clientHeight)) scroller = scroller.parentElement
				if (!scroller) throw new Error('No editor scroll container')
				const target = el.querySelector('.ProseMirror p:last-child')
				scroller.scrollTop += target.getBoundingClientRect().top - scroller.getBoundingClientRect().top - scroller.clientHeight / 2
				const range = document.createRange()
				range.selectNodeContents(target)
				const selection = window.getSelection()
				selection.removeAllRanges()
				selection.addRange(range)
				const bar = el.querySelector('.kanso-md-editor__toolbar').getBoundingClientRect()
				const box = scroller.getBoundingClientRect()
				scroller.scrollTop -= 50
				const newBarTop = el.querySelector('.kanso-md-editor__toolbar').getBoundingClientRect().top
				return { barTop: bar.top, newBarTop, scrollerTop: box.top, editorTop: el.getBoundingClientRect().top, scrollTop: scroller.scrollTop }
			})
			expect(geometry.scrollTop).toBeGreaterThan(100)
			expect(geometry.editorTop).toBeLessThan(geometry.scrollerTop - 100)
			expect(Math.abs(geometry.barTop - geometry.newBarTop)).toBeLessThan(3)
			const accessible = await toolbar.getByRole('button', { name: 'Bold', exact: true }).evaluate(el => {
				const box = el.getBoundingClientRect()
				const hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2)
				return box.top >= 0 && box.bottom <= innerHeight && !!hit && el.contains(hit)
			})
			expect(accessible).toBe(true)
			await toolbar.getByRole('button', { name: 'Bold', exact: true }).click()
			await expect(prose.locator('p:last-child strong')).toHaveText('Paragraph 100 with some content.')
			const boundary = await editor.evaluate(el => {
				let scroller = el.parentElement
				while (scroller && (!/(auto|scroll)/.test(getComputedStyle(scroller).overflowY) || scroller.scrollHeight <= scroller.clientHeight)) scroller = scroller.parentElement
				const before = scroller.scrollTop
				scroller.scrollTop += el.getBoundingClientRect().bottom - scroller.getBoundingClientRect().top + 100
				const bar = el.querySelector('.kanso-md-editor__toolbar').getBoundingClientRect()
				return { moved: scroller.scrollTop - before, bottom: bar.bottom, editorBottom: el.getBoundingClientRect().bottom, scrollerTop: scroller.getBoundingClientRect().top }
			})
			expect(boundary.moved).toBeGreaterThan(100)
			expect(boundary.editorBottom).toBeLessThan(boundary.scrollerTop)
			expect(boundary.bottom).toBeLessThanOrEqual(boundary.editorBottom + 1)
			await page.locator('.card-modal__desc-actions').getByRole('button', { name: 'Cancel' }).click()
		})
	}
})
