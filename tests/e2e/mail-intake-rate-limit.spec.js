// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, BASE, ncLogin, provisionUser, deleteUser, RATE_LIMIT_MESSAGE } from './helpers.js'

test.use({ storageState: { cookies: [], origins: [] } })

test('mailbox tests are limited per user across boards without bypassing permissions', async ({ page }) => {
	const suffix = `${Date.now()}${Math.floor(Math.random() * 100000)}`
	const ownerName = `mailrate_${suffix}`
	const otherName = `mailpeer_${suffix}`
	const password = 'kanso-test-mail-rate!1'
	let owner
	let other
	const boards = []

	try {
		owner = await provisionUser(ownerName, password)
		other = await provisionUser(otherName, password)

		for (const actor of [owner, owner, other]) {
			const board = await actor.api.post('/boards', { title: 'Mail test rate limit' })
			boards.push({ actor, id: board.id })
			const stack = await actor.api.post('/stacks', { boardId: board.id, title: 'Inbox' })
			await actor.api.put(`/boards/${board.id}/mail-intake`, {
				stackId: stack.id,
				host: '127.0.0.1',
				port: 993,
				encryption: 'ssl',
				username: 'mail@example.com',
				password: 'mail-test',
				mailbox: 'INBOX',
				enabled: false,
			})
		}

		const path = (board) => `/boards/${board.id}/mail-intake/test`
		expect((await other.api.raw('POST', path(boards[0]))).status).toBe(403)
		for (let i = 0; i < 10; i++) {
			const response = await owner.api.raw('POST', path(boards[i % 2]))
			expect(response.status).toBe(200)
			expect((await response.json()).ok).toBe(false)
		}
		expect((await owner.api.raw('POST', path(boards[0]))).status).toBe(429)
		expect((await other.api.raw('POST', path(boards[2]))).status).toBe(200)

		await ncLogin(page, { user: owner.user, pass: owner.pass })
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${boards[0].id}`)
		await page.waitForSelector('.board-view__header')
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /automation/i }).click()
		await page.getByRole('button', { name: /Email intake/i }).click()
		const panel = page.locator('#bs-automation-mail-intake')
		await panel.getByRole('button', { name: 'Test connection' }).click()
		await expect(panel.getByText(RATE_LIMIT_MESSAGE)).toBeVisible()
	} finally {
		for (const board of boards) await board.actor.api.delete(`/boards/${board.id}`).catch(() => {})
		await deleteUser(ownerName)
		await deleteUser(otherName)
	}
})
