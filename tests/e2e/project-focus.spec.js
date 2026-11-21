// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test('the project editor preserves keyboard focus through save, cancel and reopen', async ({ page }) => {
	const project = await api.post('/projects', { title: `Project editor focus ${Date.now()}` })
	const errors = []
	page.on('pageerror', (error) => errors.push(error.message))
	try {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/projects/${project.id}`)
		const menu = page.locator('.project-view__header-actions .action-item__menutoggle').last()
		const openEditor = async () => {
			await menu.click()
			await page.getByRole('menuitem', { name: 'Edit project', exact: true }).click()
			await expect(page.locator('#edit-project-title')).toBeFocused()
		}
		const markdown = '**Editor focus**\n\n- Preserved description'
		await openEditor()
		await page.locator('#edit-project-desc').fill(markdown)
		await page.locator('.project-view__form .project-view__md-btn[title="Toggle preview"]').click()
		await expect(page.locator('.project-view__form .project-view__desc-preview strong')).toHaveText('Editor focus')
		await page.getByRole('button', { name: 'Save', exact: true }).click()
		await expect(page.locator('#edit-project-title')).toBeHidden()
		await expect(page.locator('.project-view__desc-view strong')).toHaveText('Editor focus')
		await expect(menu).toBeFocused()
		await openEditor()
		await expect(page.locator('#edit-project-desc')).toHaveValue(markdown)
		const dialog = page.getByRole('dialog', { name: 'Edit project', exact: true })
		const lastButton = dialog.getByRole('button', { name: 'Cancel', exact: true })
		await lastButton.focus()
		await page.keyboard.press('Tab')
		await expect.poll(() => dialog.evaluate((element) => element.contains(document.activeElement))).toBe(true)
		await page.keyboard.press('Shift+Tab')
		await expect.poll(() => dialog.evaluate((element) => element.contains(document.activeElement))).toBe(true)
		await page.keyboard.press('Escape')
		await expect(page.locator('#edit-project-title')).toBeHidden()
		await expect(menu).toBeFocused()
		await openEditor()
		await page.locator('#edit-project-title').fill('Unsaved title')
		await page.getByRole('button', { name: 'Cancel', exact: true }).click()
		await expect(page.locator('#edit-project-title')).toBeHidden()
		await expect(menu).toBeFocused()
		await openEditor()
		await expect(page.locator('#edit-project-title')).toHaveValue(project.title)
		await expect(page.locator('#edit-project-desc')).toHaveValue(markdown)
		expect(errors.filter((message) => /fallbackFocus|focus.?trap/i.test(message))).toEqual([])
	} finally {
		await api.delete(`/projects/${project.id}`).catch(() => {})
	}
})
