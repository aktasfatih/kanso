// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE, me, collectConsoleErrors } from './helpers.js'

async function expectRowFits(row) {
	const geometry = await row.evaluate(el => {
		const bounds = el.getBoundingClientRect()
		const section = el.closest('.card-modal__checklist')
		const modal = el.closest('.card-modal')
		return {
			spill: el.scrollWidth - el.clientWidth,
			sectionSpill: section.scrollWidth - section.clientWidth,
			left: section.getBoundingClientRect().left - modal.getBoundingClientRect().left,
			right: section.getBoundingClientRect().right - modal.getBoundingClientRect().right,
			children: [...el.children].map(child => {
				const rect = child.getBoundingClientRect()
				return { left: rect.left - bounds.left, right: rect.right - bounds.right }
			}),
		}
	})
	expect(geometry.spill).toBeLessThanOrEqual(1)
	expect(geometry.sectionSpill).toBeLessThanOrEqual(1)
	expect(geometry.left).toBeGreaterThanOrEqual(-1)
	expect(geometry.right).toBeLessThanOrEqual(1)
	for (const child of geometry.children) {
		expect(child.left).toBeGreaterThanOrEqual(-1)
		expect(child.right).toBeLessThanOrEqual(1)
	}
}

async function expectReachable(control) {
	await control.scrollIntoViewIfNeeded()
	expect(await control.evaluate(el => {
		const rect = el.getBoundingClientRect()
		return el.contains(document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2))
	})).toBe(true)
}

function dueResponse(page, itemId) {
	return page.waitForResponse(res => res.url().endsWith(`/api/checklist/${itemId}/due`)
		&& res.request().method() === 'PUT' && res.ok())
}

test('long checklist titles keep due controls and the final picker inside narrow cards', async ({ page }) => {
	let boardId, stackId, cardId
	const itemIds = []
	const longTitle = 'Review ' + 'longchecklisttitle'.repeat(12)
	try {
		const board = await api.post('/boards', { title: `Checklist layout ${Date.now()}` })
		boardId = board.id
		const stack = await api.post('/stacks', { boardId, title: 'Checklist' })
		stackId = stack.id
		const card = await api.post('/cards', { stackId, title: 'Checklist layout' })
		cardId = card.id
		for (const title of ['First step', longTitle]) {
			const item = await api.post(`/cards/${cardId}/checklist`, { title })
			itemIds.push(item.id)
		}
		const itemId = itemIds.at(-1)
		await api.put(`/checklist/${itemId}/due`, { due: '2030-12-15T14:30:00.000Z' })
		await ncLogin(page)
		const errors = collectConsoleErrors(page)
		page.on('pageerror', error => errors.push(error.message))

		for (const width of [375, 320, 900, 1280]) {
			await page.setViewportSize({ width, height: 844 })
			await page.goto(`${BASE}/index.php/apps/kanso#/board/${boardId}/card/${cardId}`)
			const row = page.locator(`[data-item-id="${itemId}"]`)
			const chip = row.locator('.card-modal__step-due')
			const title = row.locator('.card-modal__checklist-item-title')
			await expect(chip).toBeVisible()
			await expectRowFits(row)
			await expectReachable(chip)

			await title.click()
			const editor = row.locator('.card-modal__checklist-item-input')
			await expect(editor).toBeFocused()
			await expectRowFits(row)
			expect((await editor.boundingBox()).width).toBeGreaterThanOrEqual(96)
			await expectReachable(chip)
			const renamed = `${longTitle} ${width}`
			const renameResponse = page.waitForResponse(res => res.url().endsWith(`/api/checklist/${itemId}`)
				&& res.request().method() === 'PATCH' && res.ok())
			await editor.fill(renamed)
			await editor.press('Enter')
			await renameResponse
			await expect(title).toHaveText(renamed)

			await chip.click()
			const picker = row.locator('.card-modal__step-popover')
			await expect(picker).toBeVisible()
			await picker.scrollIntoViewIfNeeded()
			await expectRowFits(row)
			expect(await picker.evaluate(el => {
				const rect = el.getBoundingClientRect()
				const section = el.closest('.card-modal__checklist').getBoundingClientRect()
				return rect.left >= section.left - 1 && rect.right <= section.right + 1
					&& rect.top >= section.top - 1 && rect.bottom <= section.bottom + 1
			})).toBe(true)
			const date = picker.locator('.card-modal__date-input')
			await expectReachable(date)
			await expectReachable(picker.locator('.card-modal__field-clear'))
			const update = dueResponse(page, itemId)
			await date.fill('2031-11-20T10:45')
			await update
			await expect(picker).toHaveCount(0)
			expect((await api.get(`/cards/${cardId}/checklist`)).find(item => item.id === itemId).dueDate).toContain('2031-11-20')

			await chip.click()
			const clear = dueResponse(page, itemId)
			await picker.locator('.card-modal__field-clear').click()
			await clear
			await expect(chip).toHaveCount(0)
			expect((await api.get(`/cards/${cardId}/checklist`)).find(item => item.id === itemId).dueDate).toBeNull()
			await row.getByTitle('Set step due date', { exact: true }).click()
			await expectReachable(date)
			const add = dueResponse(page, itemId)
			await date.fill('2032-12-15T09:30')
			await add
			await expect(chip).toBeVisible()
			if (width === 375) {
				await row.getByTitle('Assign step', { exact: true }).click()
				await expectRowFits(row)
				const assign = page.waitForResponse(res => res.url().endsWith(`/api/checklist/${itemId}/assign`)
					&& res.request().method() === 'POST' && res.ok())
				await row.locator('.card-modal__assign-option').filter({ hasText: me }).first().click()
				await assign
				await expect(row.locator('.card-modal__step-assignee')).toBeVisible()
			}
			await page.reload()
			await expect(title).toHaveText(renamed)
			await chip.click()
			await expect(date).toHaveValue('2032-12-15T09:30')
			await expectRowFits(row)
			await expectReachable(date)
			await page.goto('about:blank')
		}
		expect(errors).toEqual([])
	} finally {
		for (const id of itemIds) await api.delete(`/checklist/${id}`)
		if (cardId) await api.delete(`/cards/${cardId}`)
		if (stackId) await api.delete(`/stacks/${stackId}`)
		if (boardId) await api.delete(`/boards/${boardId}`)
	}
})
