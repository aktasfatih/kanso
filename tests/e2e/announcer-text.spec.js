// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE, OCS, adminAuth, provisionUser, deleteUser } from './helpers.js'

test.use({ storageState: { cookies: [], origins: [] } })

test('assignee and label announcements preserve special characters as text', async ({ page }) => {
	const stamp = `${Date.now()}`
	const user = `announcement_${stamp}`
	const name = `Ann & Bo O'Brien " <img src=x onerror=alert(1)>`
	const label = `Review & <img src=x onerror=alert(2)>`
	let boardId
	try {
		await provisionUser(user, 'Kanso-announcer!1', { displayName: name })
		const info = await fetch(`${OCS}/users/${user}?format=json`, {
			headers: { 'OCS-APIREQUEST': 'true', Authorization: adminAuth },
		}).then((response) => response.json())
		expect(info.ocs.data.displayname).toBe(name)
		const board = await api.post('/boards', { title: `Announcements ${stamp}` })
		boardId = board.id
		await api.post(`/boards/${boardId}/acl`, { participant: user, participantType: 'user', permission: 3 })
		const stack = await api.post('/stacks', { boardId, title: 'To do' })
		await api.post('/cards', { stackId: stack.id, title: 'Announcement card' })
		await api.post('/labels', { boardId, title: label, color: '0082c9' })

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${boardId}`)
		await page.locator('.card-tile').filter({ hasText: 'Announcement card' }).click()
		const attrbar = page.locator('.card-modal__attrbar')
		await attrbar.locator('button[data-pill="assign"]').click()
		const person = attrbar.locator('.card-modal__assign-option').filter({ hasText: name })
		await expect(person).toHaveCount(1)
		const live = page.locator('.board-view__sr-only[aria-live="polite"]')
		await person.click()
		await expect(live).toHaveText(`${name} assigned`)
		await expect(live.locator('*')).toHaveCount(0)
		await person.click()
		await expect(live).toHaveText(`${name} unassigned`)
		await expect(live.locator('*')).toHaveCount(0)

		await page.keyboard.press('Escape')
		await attrbar.locator('button[data-pill="label"]').click()
		const labelOption = attrbar.locator('.card-modal__label-toggle').filter({ hasText: label })
		await expect(labelOption).toHaveCount(1)
		await labelOption.click()
		await expect(live).toHaveText(`Label ${label} added`)
		await expect(live.locator('*')).toHaveCount(0)
	} finally {
		if (boardId) await api.delete(`/boards/${boardId}`).catch(() => {})
		await deleteUser(user)
	}
})

test('card move announces card and column names as text', async ({ page }) => {
	const stamp = `${Date.now()}`
	const title = `Card & <img src=x onerror=alert(1)>`
	const stackTitle = `Work & <b>Now</b>`
	const board = await api.post('/boards', { title: `Move announcements ${stamp}` })
	try {
		const stack = await api.post('/stacks', { boardId: board.id, title: stackTitle })
		await api.post('/cards', { stackId: stack.id, title: 'Another card' })
		const card = await api.post('/cards', { stackId: stack.id, title })
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`)
		await expect(page.locator('.card-modal__actions-menu button').first()).toBeVisible()
		await page.locator('.card-modal__actions-menu button').first().click()
		await page.getByRole('menuitem', { name: 'Move to top' }).click()
		const live = page.locator('.board-view__sr-only[aria-live="polite"]')
		await expect(live).toHaveText(`${title} moved to ${stackTitle}`)
		await expect(live.locator('*')).toHaveCount(0)
	} finally {
		await api.delete(`/boards/${board.id}`).catch(() => {})
	}
})
