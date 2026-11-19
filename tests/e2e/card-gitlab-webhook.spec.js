// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE, API } from './helpers.js'

const PROJECT = 'https://git.example.org/group/subgroup/project'

async function deliver(boardId, token, payload) {
	const response = await fetch(`${API}/boards/${boardId}/gitlab-webhook`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', 'X-Gitlab-Token': token },
		body: typeof payload === 'string' ? payload : JSON.stringify(payload),
	})
	return { status: response.status, body: await response.json() }
}

function event(kind, action, state, extra = {}) {
	return {
		object_kind: kind,
		object_attributes: {
			action, state, title: 'GitLab event',
			url: `${PROJECT}/-/${kind === 'merge_request' ? 'merge_requests' : 'issues'}/3`,
			...extra,
		},
	}
}

test.describe('GitLab webhook integration', () => {
	let boardId = 0
	let todoId = 0
	let reviewId = 0
	let doneId = 0
	let card
	let secret = ''

	test.beforeEach(async () => {
		const board = await api.post('/boards', { title: `GitLab ${Date.now()}` })
		boardId = board.id
		await api.patch(`/boards/${boardId}`, { prefix: 'GL' })
		todoId = (await api.post('/stacks', { boardId, title: 'To do' })).id
		reviewId = (await api.post('/stacks', { boardId, title: 'Review' })).id
		doneId = (await api.post('/stacks', { boardId, title: 'Done' })).id
		await api.patch(`/stacks/${todoId}`, { role: 2 })
		await api.patch(`/stacks/${reviewId}`, { role: 4 })
		await api.patch(`/stacks/${doneId}`, { role: 5 })
		card = await api.post('/cards', { stackId: todoId, title: 'GitLab linked work' })
		secret = (await api.post(`/boards/${boardId}/gitlab/rotate`)).secret
	})

	test.afterEach(async () => {
		if (boardId) await api.delete(`/boards/${boardId}`).catch(() => {})
		boardId = 0
	})

	test('nested GitLab links are canonical, idempotent and visible on the card', async ({ page }) => {
		const url = `${PROJECT}/-/merge_requests/3`
		const first = await api.post(`/cards/${card.id}/links`, { url: `${url}/?view=inline#note_1` })
		const repeated = await api.post(`/cards/${card.id}/links`, { url })
		expect(repeated.id).toBe(first.id)
		expect(first).toMatchObject({ provider: 'gitlab', kind: 'pr', state: 'unknown', url })
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${boardId}/card/${card.id}`)
		await expect(page.locator('.card-modal__link-row', { has: page.locator(`a[href="${url}"]`) })).toBeVisible()
		expect(await api.get(`/cards/${card.id}/links`)).toHaveLength(1)
	})

	test('issue events reject bad tokens and preserve ordering on redelivery', async () => {
		await api.post(`/cards/${card.id}/links`, { url: `${PROJECT}/-/issues/3` })
		const closed = event('issue', 'close', 'closed')
		const before = await api.get(`/boards/${boardId}`)
		for (const token of ['', 'wrong-token']) {
			expect((await deliver(boardId, token, closed)).status).toBe(401)
		}
		expect((await api.get(`/boards/${boardId}`)).cursor).toBe(before.cursor)
		expect((await api.get(`/cards/${card.id}/links`))[0].state).toBe('unknown')

		const response = await deliver(boardId, secret, closed)
		expect(response.status).toBe(200)
		expect(response.body.moved).toBe(true)
		expect((await api.get(`/cards/${card.id}`)).stackId).toBe(doneId)
		expect((await api.get(`/cards/${card.id}/links`))[0]).toMatchObject({ state: 'closed', title: 'GitLab event' })
		const settled = await api.get(`/boards/${boardId}`)
		expect((await deliver(boardId, secret, closed)).body.moved).toBe(false)
		expect((await api.get(`/boards/${boardId}`)).cursor).toBe(settled.cursor)

		expect((await deliver(boardId, secret, event('issue', 'reopen', 'opened'))).status).toBe(200)
		expect((await api.get(`/cards/${card.id}`)).stackId).toBe(todoId)
		expect((await api.get(`/cards/${card.id}/links`))[0].state).toBe('open')
	})

	test('merge requests match a branch or human reference and do not alias issues', async () => {
		await api.post(`/cards/${card.id}/links`, { url: `${PROJECT}/-/issues/3` })
		const opened = event('merge_request', 'open', 'opened', { source_branch: `kanso-${card.id}-work` })
		expect((await deliver(boardId, secret, opened)).body.moved).toBe(true)
		expect((await api.get(`/cards/${card.id}`)).stackId).toBe(reviewId)
		const merged = event('merge_request', 'merge', 'merged', { source_branch: 'feature/work', title: `Finish GL-${card.boardSeq}` })
		expect((await deliver(boardId, secret, merged)).body.moved).toBe(true)
		const current = await api.get(`/cards/${card.id}`)
		expect(current.stackId).toBe(doneId)
		expect(Number(current.doneAt)).toBeGreaterThan(0)
		const links = await api.get(`/cards/${card.id}/links`)
		expect(links).toHaveLength(2)
		expect(links.find((link) => link.kind === 'issue').state).toBe('unknown')
		expect(links.find((link) => link.kind === 'pr').state).toBe('merged')
		const settled = await api.get(`/boards/${boardId}`)
		expect((await deliver(boardId, secret, merged)).body.moved).toBe(false)
		expect((await api.get(`/boards/${boardId}`)).cursor).toBe(settled.cursor)
	})

	test('configuration is MANAGE-only and tokens are independent and immediately revocable', async ({ peer }) => {
		await api.post(`/boards/${boardId}/acl`, { participant: peer.user, participantType: 'user', permission: 3 })
		for (const [method, path] of [['GET', ''], ['POST', '/rotate'], ['DELETE', '']]) {
			expect((await peer.api.raw(method, `/boards/${boardId}/gitlab${path}`)).status).toBe(403)
		}
		expect((await api.get(`/boards/${boardId}/webhook`)).enabled).toBe(false)
		expect((await api.get(`/boards/${boardId}/forgejo`)).enabled).toBe(false)
		const config = await api.get(`/boards/${boardId}/gitlab`)
		expect(config).not.toHaveProperty('secret')
		expect(JSON.stringify(await api.get(`/boards/${boardId}`))).not.toContain(secret)
		const oldSecret = secret
		secret = (await api.post(`/boards/${boardId}/gitlab/rotate`)).secret
		expect(secret).not.toBe(oldSecret)
		expect((await deliver(boardId, oldSecret, {})).status).toBe(401)
		expect((await deliver(boardId, secret, {})).status).toBe(200)
		await api.delete(`/boards/${boardId}/gitlab`)
		expect((await deliver(boardId, secret, {})).status).toBe(401)
	})

	test('board settings exposes the GitLab setup and disable controls', async ({ page }) => {
		await api.delete(`/boards/${boardId}/gitlab`)
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${boardId}`)
		await page.getByRole('button', { name: 'More', exact: true }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		const settings = page.getByRole('dialog', { name: 'Board settings' })
		await settings.getByRole('tab', { name: 'Automation', exact: true }).click()
		const group = settings.locator('[data-test=gitlab-settings]')
		await group.getByRole('button', { name: 'GitLab', exact: true }).click()
		await group.getByRole('button', { name: 'Enable & generate secret', exact: true }).click()
		await expect(group.locator('#bs-gitlab-secret')).toHaveValue(/.+/)
		await expect(group.locator('#bs-gitlab-url')).toHaveValue(/gitlab-webhook$/)
		expect((await api.get(`/boards/${boardId}/gitlab`)).enabled).toBe(true)
		await group.getByRole('button', { name: 'Disable', exact: true }).click()
		await expect(group.locator('#bs-gitlab-secret')).toHaveCount(0)
		expect((await api.get(`/boards/${boardId}/gitlab`)).enabled).toBe(false)
	})
})
