// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE, me, provisionUser, deleteUser, makeApi, authFor, exportArchive } from './helpers.js'

test.describe('Single-assignee boards', () => {
	const state = { boards: [], users: [] }
	const password = 'Kanso#SinglePerson2026'
	let board, stack

	test.beforeAll(async ({}, workerInfo) => {
		for (const role of ['reader', 'editor', 'manager']) {
			const uid = `kanso_single_${workerInfo.workerIndex}_${role}`
			await provisionUser(uid, password, { displayName: uid })
			state.users.push(uid)
		}
	})
	test.beforeEach(async () => {
		board = await api.post('/boards', { title: `Single assignee ${Date.now()}` })
		state.boards.push(board.id)
		stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		for (const [i, permission] of [1, 3, 9].entries()) {
			await api.post(`/boards/${board.id}/acl`, { participant: state.users[i], participantType: 'user', permission })
		}
	})
	test.afterAll(async () => {
		for (const id of state.boards) await api.delete(`/boards/${id}`).catch(() => {})
		for (const uid of state.users) await deleteUser(uid).catch(() => {})
	})

	async function card(title = 'Choose a person') {
		return api.post('/cards', { stackId: stack.id, title })
	}
	async function openCard(page, title) {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${board.id}`)
		await page.locator('.card-tile', { hasText: title }).click()
		await expect(page.locator('.card-modal__attrbar')).toBeVisible()
	}
	const personRow = (page, uid) => page.locator('.card-modal__assign-option', { hasText: uid })

	test('settings keep multiple people, require manual cleanup, then replace the sole person', async ({ page }) => {
		expect(board.singleAssignee).toBe(false)
		const task = await card()
		await api.put(`/cards/${task.id}/assignees/${me}`)
		await api.put(`/cards/${task.id}/assignees/${state.users[0]}`)
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${board.id}`)
		await page.getByRole('button', { name: /^More( board actions)?$/ }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: 'General', exact: true }).click()
		const setting = page.getByRole('checkbox', { name: 'One assignee per card' })
		await expect(setting).not.toBeChecked()
		await page.getByText('One assignee per card', { exact: true }).click()
		await expect(setting).toBeChecked()
		expect((await api.get(`/cards/${task.id}`)).assigneeIds).toEqual([me, state.users[0]])
		await page.reload()
		await page.locator('.card-tile', { hasText: task.title }).click()
		await page.locator('button[data-pill="assign"]').click()
		await expect(page.locator('[data-test="single-assignee-note"]')).toContainText('Remove extra assignees')
		await expect(personRow(page, state.users[1])).toHaveAttribute('aria-disabled', 'true')
		await personRow(page, state.users[1]).focus()
		await page.keyboard.press('Enter')
		await expect(page.locator('.card-modal__save-error', { hasText: 'Remove extra assignees' })).toBeVisible()
		await expect(page.locator('.card-modal__assignee-pill')).toHaveCount(2)
		await personRow(page, me).first().click()
		await expect(page.locator('.card-modal__assignee-pill')).toHaveCount(1)
		await expect(personRow(page, state.users[1])).not.toHaveAttribute('aria-disabled', 'true')
		await expect(page.locator('[data-test="single-assignee-note"]')).toContainText('replaces the current assignee')
		await personRow(page, state.users[1]).click()
		await expect(page.locator('.card-modal__assignee-name')).toHaveText([state.users[1]])
		await expect.poll(async () => (await api.get(`/cards/${task.id}`)).assigneeIds).toEqual([state.users[1]])
		await page.reload()
		await expect(page.locator('.card-modal__assignee-name')).toHaveText([state.users[1]])
	})

	test('fast queued single-person choices replace without losing the latest selection', async ({ page }) => {
		const task = await card('Latest person')
		await api.patch(`/boards/${board.id}`, { singleAssignee: true })
		await openCard(page, task.title)
		await page.locator('button[data-pill="assign"]').click()
		let release
		const held = new Promise((resolve) => { release = resolve })
		let entered
		const requestStarted = new Promise((resolve) => { entered = resolve })
		await page.route(`**/cards/${task.id}/assignees/${state.users[0]}`, async (route) => {
			entered()
			await held
			await route.continue()
		})
		await personRow(page, state.users[0]).click()
		await requestStarted
		await personRow(page, state.users[1]).click()
		await personRow(page, state.users[2]).click()
		release()
		await expect.poll(async () => (await api.get(`/cards/${task.id}`)).assigneeIds).toEqual([state.users[2]])
		await expect(page.locator('.card-modal__assignee-name')).toHaveText([state.users[2]])
	})

	test('manager mode changes and editor replacement retain permission and participant checks', async () => {
		const task = await card()
		const reader = makeApi(authFor(state.users[0], password))
		const editor = makeApi(authFor(state.users[1], password))
		const manager = makeApi(authFor(state.users[2], password))
		expect((await editor.raw('PATCH', `/boards/${board.id}`, { singleAssignee: true })).status).toBe(403)
		await manager.patch(`/boards/${board.id}`, { singleAssignee: true })
		await api.put(`/cards/${task.id}/assignees/${me}`)
		expect((await reader.raw('PUT', `/cards/${task.id}/assignees/${state.users[0]}`)).status).toBe(403)
		expect((await editor.raw('PUT', `/cards/${task.id}/assignees/unknown-person`)).status).toBe(400)
		expect((await api.get(`/cards/${task.id}`)).assigneeIds).toEqual([me])
		await editor.put(`/cards/${task.id}/assignees/${state.users[0]}`)
		expect((await api.get(`/cards/${task.id}`)).assigneeIds).toEqual([state.users[0]])
		await manager.patch(`/boards/${board.id}`, { singleAssignee: false })
		await editor.put(`/cards/${task.id}/assignees/${me}`)
		expect((await api.get(`/cards/${task.id}`)).assigneeIds).toEqual([state.users[0], me])
	})

	test('grandfathered assignments survive idempotence, export, restore and both board copies', async () => {
		const task = await card()
		for (const uid of [me, state.users[0]]) await api.put(`/cards/${task.id}/assignees/${uid}`)
		await api.patch(`/boards/${board.id}`, { singleAssignee: true })
		await api.put(`/cards/${task.id}/assignees/${me}`)
		expect((await api.get(`/cards/${task.id}`)).assigneeIds).toEqual([me, state.users[0]])
		expect((await api.raw('PUT', `/cards/${task.id}/assignees/${state.users[1]}`)).status).toBe(400)
		const exported = await exportArchive(board.id)
		expect(exported.doc.board.singleAssignee).toBe(true)
		const restored = await api.post('/boards/import', { document: JSON.stringify(exported.doc) })
		state.boards.push(restored.boardId)
		for (const withCards of [true, false]) {
			const copy = await api.post(`/boards/${board.id}/duplicate`, { withCards })
			state.boards.push(copy.boardId)
			const data = await api.get(`/boards/${copy.boardId}`)
			expect(data.board.singleAssignee).toBe(true)
			expect(data.cards.length).toBe(withCards ? 1 : 0)
			if (withCards) expect(data.cards[0].assigneeIds).toEqual([me, state.users[0]])
		}
		const data = await api.get(`/boards/${restored.boardId}`)
		expect(data.board.singleAssignee).toBe(true)
		expect(data.cards[0].assigneeIds).toEqual([me, state.users[0]])
		const copied = await api.post(`/cards/${task.id}/copy`, { targetStackId: stack.id })
		expect((await api.get(`/cards/${copied.id}`)).assigneeIds).toEqual([])
	})

	test('CSV multi-person rejection rolls back every row and accepts one surviving distinct person', async () => {
		await api.patch(`/boards/${board.id}`, { singleAssignee: true })
		const input = { boardId: board.id, stackId: stack.id, mapping: { title: 0, assignees: 1 }, hasHeader: true }
		const refused = await api.raw('POST', '/csv-import', { ...input, document: `title,people\nFirst,${me}\nSecond,"${me},${state.users[0]}"` })
		expect(refused.status).toBe(400)
		expect((await api.get(`/boards/${board.id}`)).cards).toEqual([])
		await api.post('/csv-import', { ...input, document: `title,people\nOnly one,"${me},${me},unknown-person"` })
		expect((await api.get(`/boards/${board.id}`)).cards[0].assigneeIds).toEqual([me])
	})

	test('graph move refuses an over-limit child without moving anyone and accepts the cleaned graph', async () => {
		const parent = await card('Move parent')
		const child = await card('Move child')
		await api.put(`/cards/${child.id}/parent`, { parentCardId: parent.id })
		for (const uid of [me, state.users[0]]) await api.put(`/cards/${child.id}/assignees/${uid}`)
		const target = await api.post('/boards', { title: 'Single destination' })
		state.boards.push(target.id)
		await api.post(`/boards/${target.id}/acl`, { participant: state.users[0], participantType: 'user', permission: 1 })
		await api.patch(`/boards/${target.id}`, { singleAssignee: true })
		const destination = await api.post('/stacks', { boardId: target.id, title: 'Destination' })
		const refused = await api.raw('POST', `/cards/${parent.id}/move-to-board`, { targetStackId: destination.id })
		expect(refused.status).toBe(400)
		expect((await api.get(`/boards/${target.id}`)).cards).toEqual([])
		expect((await api.get(`/cards/${child.id}`)).assigneeIds).toEqual([me, state.users[0]])
		expect((await api.get(`/boards/${board.id}`)).cards).toHaveLength(2)
		await api.delete(`/cards/${child.id}/assignees/${me}`)
		await api.post(`/cards/${parent.id}/move-to-board`, { targetStackId: destination.id })
		const moved = await api.get(`/boards/${target.id}`)
		expect(moved.cards).toHaveLength(2)
		expect(moved.cards.find((c) => c.title === child.title).assigneeIds).toEqual([state.users[0]])
	})
})
