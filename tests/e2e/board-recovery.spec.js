// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE, API, currentAuth, me, provisionUser, deleteUser, exportArchive } from './helpers.js'

test.use({ storageState: { cookies: [], origins: [] } })

async function openDeleted(page, credentials) {
	await ncLogin(page, credentials)
	await page.goto(`${BASE}/index.php/apps/kanso#/`)
	await page.getByRole('button', { name: 'Deleted boards', exact: true }).click()
	await expect(page.locator('[data-test="deleted-boards"]')).toBeVisible()
}

function stableBoard(payload) {
	const { cursor, etag, ...rest } = payload
	const { lastModified, ...board } = rest.board
	return { ...rest, board }
}

test.describe('Deleted board recovery', () => {
	const owned = []
	test.afterAll(async () => {
		for (const id of owned) await api.raw('DELETE', `/boards/${id}`)
	})

	test('settings deletion explains retention and restores the complete board and attachment', async ({ page, peer, user }) => {
		const board = await api.post('/boards', { title: `Recovery contents ${Date.now()}` })
		owned.push(board.id)
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Keep column' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Restored card' })
		await api.patch(`/cards/${card.id}`, { description: 'Keep the **description**.' })
		const label = await api.post('/labels', { boardId: board.id, title: 'Keep label', color: '0082c9' })
		await api.put(`/cards/${card.id}/labels/${label.id}`)
		await api.put(`/cards/${card.id}/assignees/${me}`)
		await api.post(`/cards/${card.id}/checklist`, { title: 'Keep step' })
		await api.post(`/cards/${card.id}/comments`, { body: 'Keep discussion' })
		await api.post(`/boards/${board.id}/acl`, { participant: peer.user, participantType: 'user', permission: 1 })
		const trashed = await api.post('/cards', { stackId: stack.id, title: 'Keep in card trash' })
		await api.delete(`/cards/${trashed.id}`)
		const form = new FormData()
		form.append('file', new Blob(['Exact attachment bytes\n'], { type: 'text/plain' }), 'keep.txt')
		const uploaded = await fetch(`${API}/cards/${card.id}/attachments`, {
			method: 'POST', headers: { 'OCS-APIREQUEST': 'true', Authorization: currentAuth }, body: form,
		})
		expect(uploaded.ok).toBe(true)
		const before = {
			board: stableBoard(await api.get(`/boards/${board.id}`)),
			card: await api.get(`/cards/${card.id}`),
			trash: await api.get(`/boards/${board.id}/trash`),
			files: Object.entries((await exportArchive(board.id)).entries).filter(([name]) => name !== 'board.json'),
		}
		expect(before.files.length).toBeGreaterThan(0)
		await ncLogin(page, user)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${board.id}`)
		await page.getByRole('button', { name: 'More', exact: true }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: 'General', exact: true }).click()
		await page.locator('.board-actions__danger').getByRole('button', { name: 'Delete', exact: true }).click()
		const confirm = page.locator('.bs-delete-confirm')
		await expect(confirm).toContainText('30 days')
		await expect(confirm).toContainText('Archive instead')
		await expect(confirm).not.toContainText('cannot be undone')
		await confirm.getByRole('button', { name: 'Delete board', exact: true }).click()
		await page.getByRole('button', { name: 'Deleted boards', exact: true }).click()
		const row = page.locator(`[data-test="deleted-board-${board.id}"]`)
		await expect(row).toContainText('30 days left')
		await expect(row).toContainText('Restore before')
		expect((await api.raw('GET', `/cards/${card.id}`)).status).toBe(404)
		expect((await peer.api.raw('POST', `/boards/${board.id}/restore`)).status).toBe(403)
		await row.getByRole('button', { name: 'Restore board', exact: true }).click()
		await expect(row).toHaveCount(0)
		await expect(page.getByRole('status').filter({ hasText: 'Board restored.' })).toBeVisible()
		expect(stableBoard(await api.get(`/boards/${board.id}`))).toEqual(before.board)
		expect(await api.get(`/cards/${card.id}`)).toEqual(before.card)
		expect(await api.get(`/boards/${board.id}/trash`)).toEqual(before.trash)
		expect(Object.entries((await exportArchive(board.id)).entries).filter(([name]) => name !== 'board.json')).toEqual(before.files)
		expect((await peer.api.get(`/boards/${board.id}`)).board.id).toBe(board.id)
		await page.getByRole('button', { name: 'Active', exact: true }).click()
		await page.locator('.board-tile__title', { hasText: board.title }).click()
		await expect(page.locator(`[data-card-id="${card.id}"]`)).toBeVisible()
	})

	test('an owner can recover their last board to Archived', async ({ page }) => {
		const user = await provisionUser(`recovery_${Date.now()}`, 'Recovery-fixture!1')
		try {
			const board = await user.api.post('/boards', { title: 'Last board' })
			await user.api.patch(`/boards/${board.id}`, { archived: true })
			await user.api.delete(`/boards/${board.id}`)
			expect(await user.api.get('/boards')).toEqual([])
			await openDeleted(page, user)
			const row = page.locator(`[data-test="deleted-board-${board.id}"]`)
			await expect(row).toContainText('Restores to Archived.')
			await row.getByRole('button', { name: 'Restore board', exact: true }).click()
			await expect(page.getByRole('status').filter({ hasText: 'Find it under Archived.' })).toBeVisible()
			await page.getByRole('button', { name: /^Archived/ }).click()
			await expect(page.locator('.board-list__archived-row', { hasText: board.title })).toBeVisible()
			await user.api.delete(`/boards/${board.id}`)
		} finally {
			await deleteUser(user.user)
		}
	})

	test('a current board manager can list and restore a deleted shared board', async ({ page, peer }) => {
		const board = await api.post('/boards', { title: `Manager recovery ${Date.now()}` })
		owned.push(board.id)
		await api.post(`/boards/${board.id}/acl`, { participant: peer.user, participantType: 'user', permission: 9 })
		await api.delete(`/boards/${board.id}`)
		await openDeleted(page, peer)
		const row = page.locator(`[data-test="deleted-board-${board.id}"]`)
		await expect(row).toBeVisible()
		await page.getByRole('textbox', { name: 'Search boards', exact: true }).fill('No such recovery title')
		await expect(page.locator('[data-test="deleted-boards"]')).toContainText('No boards match your search.')
		await page.getByRole('textbox', { name: 'Search boards', exact: true }).fill(board.title)
		await row.getByRole('button', { name: 'Restore board', exact: true }).click()
		await expect(row).toHaveCount(0)
		expect((await peer.api.get(`/boards/${board.id}`)).board.id).toBe(board.id)
	})

	test('loading failures can retry and failed restores show the server outcome', async ({ page, user }) => {
		const board = await api.post('/boards', { title: `Recovery unavailable ${Date.now()}` })
		owned.push(board.id)
		await api.delete(`/boards/${board.id}`)
		let unavailable = true
		await page.route('**/api/boards/deleted', async (route) => {
			if (unavailable) await route.fulfill({ status: 503, json: { error: 'Unavailable' } })
			else await route.continue()
		})
		await openDeleted(page, user)
		await expect(page.locator('[data-test="deleted-boards"]')).toContainText('Could not load deleted boards.')
		unavailable = false
		await page.getByRole('button', { name: 'Try again', exact: true }).click()
		const row = page.locator(`[data-test="deleted-board-${board.id}"]`)
		await expect(row).toBeVisible()
		await page.route(`**/api/boards/${board.id}/restore`, (route) => route.fulfill({ status: 404, json: { error: 'Unavailable' } }))
		await row.getByRole('button', { name: 'Restore board', exact: true }).click()
		await expect(page.locator('[data-test="deleted-boards"] [role="alert"]')).toContainText('can no longer be restored')
		expect((await api.raw('GET', `/boards/${board.id}`)).status).toBe(404)
	})

	test('a displayed deadline disables restoration when it expires', async ({ page, user }) => {
		const time = Date.now()
		await page.clock.install({ time })
		await page.route('**/api/boards/deleted', (route) => route.fulfill({ json: [{ id: 999999, title: 'About to expire', restoreUntil: Math.floor(time / 1000) + 2 }] }))
		await openDeleted(page, user)
		const row = page.locator('[data-test="deleted-board-999999"]')
		await expect(row).toBeVisible()
		await page.clock.fastForward(4000)
		await expect(row).toContainText('Recovery window expired.')
		await expect(row.getByRole('button', { name: 'Restore board', exact: true })).toBeDisabled()
	})
})
