// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test('the day-before reminder can be saved, reloaded and cleared', async ({ page }) => {
	const board = await api.send('POST', '/boards', { title: 'Due Reminder E2E' })
	try {
		const stack = await api.send('POST', '/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api.send('POST', '/cards', { stackId: stack.id, title: 'Reminder card' })
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`)
		const duePill = page.locator('button.card-modal__pill[data-pill="due"]')
		await duePill.click()
		const reminder = page.getByRole('checkbox', { name: 'Remind assignees and card watchers one day before' })
		await expect(reminder).toHaveCount(0)
		const date = page.locator('.card-modal__popover [data-date="end"]')
		await date.fill('2027-12-10T12:00')
		await date.press('Enter')
		await expect(reminder).toBeVisible()
		await reminder.click()
		await expect(reminder).toBeChecked()
		await expect.poll(async () => (await api.send('GET', `/cards/${card.id}`)).dueReminderDayBefore).toBe(true)
		await page.reload()
		await duePill.click()
		await expect(reminder).toBeChecked()
		await reminder.click()
		await expect(reminder).not.toBeChecked()
		await expect.poll(async () => (await api.send('GET', `/cards/${card.id}`)).dueReminderDayBefore).toBe(false)
		await page.reload()
		await duePill.click()
		await expect(reminder).not.toBeChecked()
	} finally {
		await api.send('DELETE', `/boards/${board.id}`).catch(() => {})
	}
})

test('a rejected reminder update restores the saved checkbox value', async ({ page }) => {
	const board = await api.send('POST', '/boards', { title: 'Due Reminder Failure E2E' })
	try {
		const stack = await api.send('POST', '/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api.send('POST', '/cards', { stackId: stack.id, title: 'Reminder card' })
		await api.send('PATCH', `/cards/${card.id}`, { duedate: '2027-12-10T12:00:00Z' })
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`)
		await page.locator('button.card-modal__pill[data-pill="due"]').click()
		const reminder = page.getByRole('checkbox', { name: 'Remind assignees and card watchers one day before' })
		await page.route(`**/api/cards/${card.id}`, async (route) => {
			if (route.request().method() === 'PATCH') {
				await route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ error: 'Save refused' }) })
			} else {
				await route.continue()
			}
		})
		await reminder.click()
		await expect(page.getByText('Save refused')).toBeVisible()
		await expect(reminder).not.toBeChecked()
		await expect(reminder).toBeEnabled()
		expect((await api.send('GET', `/cards/${card.id}`)).dueReminderDayBefore).toBe(false)
		await api.send('PATCH', `/cards/${card.id}`, { dueReminderDayBefore: true })
		await page.reload()
		await page.locator('button.card-modal__pill[data-pill="due"]').click()
		await expect(reminder).toBeChecked()
		await reminder.click()
		await expect(page.getByText('Save refused')).toBeVisible()
		await expect(reminder).toBeChecked()
		expect((await api.send('GET', `/cards/${card.id}`)).dueReminderDayBefore).toBe(true)
	} finally {
		await api.send('DELETE', `/boards/${board.id}`).catch(() => {})
	}
})

test('a saved reminder stays visible when the follow-up read fails', async ({ page }) => {
	const board = await api.send('POST', '/boards', { title: 'Due Reminder Read Failure E2E' })
	try {
		const stack = await api.send('POST', '/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api.send('POST', '/cards', { stackId: stack.id, title: 'Reminder card' })
		await api.send('PATCH', `/cards/${card.id}`, { duedate: '2027-12-10T12:00:00Z' })
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`)
		await page.locator('button.card-modal__pill[data-pill="due"]').click()
		const reminder = page.getByRole('checkbox', { name: 'Remind assignees and card watchers one day before' })
		await page.route(`**/api/cards/${card.id}`, async (route) => {
			if (route.request().method() === 'GET') {
				await route.fulfill({ status: 503, contentType: 'application/json', body: '{}' })
			} else {
				await route.continue()
			}
		})
		await reminder.click()
		await expect(reminder).toBeChecked()
		expect((await api.send('GET', `/cards/${card.id}`)).dueReminderDayBefore).toBe(true)
	} finally {
		await api.send('DELETE', `/boards/${board.id}`).catch(() => {})
	}
})
