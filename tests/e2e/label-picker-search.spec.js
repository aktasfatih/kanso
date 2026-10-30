// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test('search existing labels, select by keyboard, and create separately', async ({ page }) => {
	const board = await api.post('/boards', { title: 'Search labels' })
	try {
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Todo' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Card' })
		const first = await api.post('/labels', { boardId: board.id, title: 'Alpha bug' })
		const second = await api.post('/labels', { boardId: board.id, title: 'Beta BUG' })
		await api.post('/labels', { boardId: board.id, title: 'Other' })

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`)
		const pill = page.locator('button[data-pill="label"]')
		await pill.click()
		const search = page.getByRole('searchbox', { name: 'Search labels…' })
		const popover = page.locator('.card-modal__attr .card-modal__popover')
		await expect(search).toBeFocused()
		await search.fill('bUg')
		await expect(popover.locator('.card-modal__label-toggle')).toHaveCount(2)
		await expect(popover.getByRole('button', { name: 'Other' })).toHaveCount(0)
		await search.press('Tab')
		const firstButton = popover.getByRole('button', { name: 'Alpha bug' })
		await expect(firstButton).toBeFocused()
		await firstButton.press('Enter')
		await expect(firstButton).toHaveAttribute('aria-pressed', 'true')
		await popover.getByRole('button', { name: 'Beta BUG' }).click()
		await expect.poll(async () => (await api.get(`/cards/${card.id}`)).labelIds.sort()).toEqual([first.id, second.id].sort())

		await search.fill('nothing matches')
		await expect(popover.getByText('No labels match.')).toBeVisible()
		await popover.getByRole('textbox', { name: 'New label name' }).fill('New label')
		await popover.getByRole('button', { name: 'Create', exact: true }).click()
		await expect(page.locator('.card-modal__label-chip', { hasText: 'New label' })).toBeVisible()
		await search.press('Escape')
		await expect(pill).toBeFocused()
		await pill.press('Enter')
		await expect(search).toHaveValue('')
		await expect(popover.getByRole('button', { name: 'New label', exact: true })).toBeVisible()
	} finally {
		await api.delete(`/boards/${board.id}`).catch(() => {})
	}
})

test('editors without manage permission can search and toggle labels', async ({ browser, peer }) => {
	const board = await api.post('/boards', { title: 'Editor labels' })
	try {
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Todo' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Card' })
		const label = await api.post('/labels', { boardId: board.id, title: 'Find this' })
		await api.post(`/boards/${board.id}/acl`, { participant: peer.user, participantType: 'user', permission: 3 })
		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
		try {
			const page = await context.newPage()
			await ncLogin(page, { user: peer.user, pass: peer.pass })
			await page.goto(`${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`)
			await page.locator('button[data-pill="label"]').click()
			await page.getByRole('searchbox', { name: 'Search labels…' }).fill('find')
			await page.getByRole('button', { name: 'Find this' }).click()
			await expect.poll(async () => (await api.get(`/cards/${card.id}`)).labelIds).toContain(label.id)
			await expect(page.getByRole('textbox', { name: 'New label name' })).toHaveCount(0)
		} finally {
			await context.close()
		}
	} finally {
		await api.delete(`/boards/${board.id}`).catch(() => {})
	}
})
