// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE, API, OCS, adminAuth } from './helpers.js'

const READ = 1
const EDIT = 2
const SHARE = 4
const MANAGE = 8
const COMMENT = 16

async function openSharing(page, boardId) {
	await page.goto(`${BASE}/index.php/apps/kanso#/board/${boardId}`)
	await expect(page.locator('.board-view__header')).toBeVisible()
	await page.getByRole('button', { name: 'More', exact: true }).click()
	await page.getByRole('menuitem', { name: /board settings/i }).click()
	await page.locator('#bs-rail-tab-sharing').click()
	await expect(page.locator('.sharing__entry')).toBeVisible()
}

async function postInBrowser(page, cardId, body) {
	const response = page.waitForResponse((r) =>
		r.url().endsWith(`/cards/${cardId}/comments`) && r.request().method() === 'POST',
	)
	const composer = page.locator('.card-modal__composer .ProseMirror')
	await composer.fill(body)
	await composer.press('Control+Enter')
	const result = await response
	expect(result.ok()).toBe(true)
	return result.json()
}

async function deleteInBrowser(page, button, commentId) {
	const response = page.waitForResponse((r) =>
		r.url().endsWith(`/comments/${commentId}`) && r.request().method() === 'DELETE',
	)
	await button.click()
	expect((await response).ok()).toBe(true)
}

async function toggleGrant(page, checkbox, checked, boardId, aclId) {
	const response = page.waitForResponse((r) =>
		r.url().endsWith(`/boards/${boardId}/acl/${aclId}`) && r.request().method() === 'PATCH',
	)
	await checkbox.setChecked(checked)
	expect((await response).ok()).toBe(true)
}

test.describe('Member Comment permission', () => {
	test.use({ viewport: { width: 1600, height: 1000 } })

	const state = { boardId: 0, cardId: 0, aclId: 0, ownerCommentId: 0, cardUrl: '' }

	test.beforeEach(async ({ peer }) => {
		const board = await api.post('/boards', { title: `Comment permission ${Date.now()}` })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Review' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Discussion without editing', description: 'Keep this card unchanged.' })
		state.cardId = card.id
		state.ownerCommentId = (await api.post(`/cards/${card.id}/comments`, { body: 'Owner discussion' })).id
		state.aclId = (await api.post(`/boards/${board.id}/acl`, {
			participant: peer.user,
			participantType: 'user',
			permission: READ | COMMENT,
		})).id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterEach(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
		state.boardId = 0
	})

	test('a Comment member posts, replies, edits, deletes and reacts without card editing', async ({ browser, peer }) => {
		const context = await browser.newContext({ viewport: { width: 1600, height: 1000 }, storageState: { cookies: [], origins: [] } })
		try {
			const page = await context.newPage()
			await ncLogin(page, peer)
			await page.goto(state.cardUrl)
			await expect(page.locator('.card-modal__composer .ProseMirror')).toBeVisible()
			await expect(page.locator('.card-modal__comment-resolve-btn')).toHaveCount(0)
			await expect(page.locator('.card-modal__status-chip--btn')).toHaveCount(0)
			await expect(page.locator('.card-modal__file-input')).toHaveCount(0)
			await expect(page.locator('button[data-pill="assign"]')).toHaveCount(0)
			await page.locator('.card-modal__title').click()
			await expect(page.locator('.card-modal__title-input')).toHaveCount(0)

			let uploads = 0
			page.on('request', (request) => {
				if (request.method() === 'POST' && request.url().endsWith(`/cards/${state.cardId}/attachments`)) uploads++
			})
			await page.locator('.card-modal__composer .ProseMirror').evaluate((editor) => {
				const clipboardData = new DataTransfer()
				clipboardData.items.add(new File([new Uint8Array([137, 80, 78, 71])], 'comment.png', { type: 'image/png' }))
				editor.dispatchEvent(new ClipboardEvent('paste', { clipboardData, bubbles: true, cancelable: true }))
			})
			const own = await postInBrowser(page, state.cardId, 'Reviewer feedback')
			const ownRow = page.locator(`#comment-${own.id} > .card-modal__comment`)
			await expect(ownRow.locator('.card-modal__comment-body')).toHaveText('Reviewer feedback')
			expect(uploads).toBe(0)
			expect(await api.get(`/cards/${state.cardId}/attachments`)).toHaveLength(0)

			await ownRow.getByTitle('Edit comment', { exact: true }).click()
			await ownRow.locator('.card-modal__comment-edit-textarea').fill('Edited reviewer feedback')
			await ownRow.locator('.card-modal__comment-edit-textarea').press('Control+Enter')
			await expect(ownRow.locator('.card-modal__comment-body')).toHaveText('Edited reviewer feedback')

			const ownerRow = page.locator(`#comment-${state.ownerCommentId} > .card-modal__comment`)
			await expect(ownerRow.getByTitle('Edit comment', { exact: true })).toHaveCount(0)
			await expect(ownerRow.getByTitle('Delete comment', { exact: true })).toHaveCount(0)
			await ownerRow.getByTitle('Add reaction', { exact: true }).click()
			await ownerRow.locator('.card-modal__reaction-picker').getByTitle('👍', { exact: true }).click()
			await expect(ownerRow.locator('.card-modal__reaction-chip--mine')).toContainText('1')
			await ownerRow.locator('.card-modal__reaction-chip--mine').click()
			await expect(ownerRow.locator('.card-modal__reaction-chip')).toHaveCount(0)

			await ownerRow.getByRole('button', { name: 'Reply', exact: true }).click()
			const replyResult = page.waitForResponse((r) => r.url().endsWith(`/cards/${state.cardId}/comments`) && r.request().method() === 'POST')
			const replyComposer = page.locator('.card-modal__reply-compose .ProseMirror')
			await replyComposer.fill('Reviewer reply')
			await replyComposer.press('Control+Enter')
			const replyResponse = await replyResult
			expect(replyResponse.ok()).toBe(true)
			const reply = await replyResponse.json()
			const replyRow = page.locator(`#comment-${reply.id}`)
			await expect(replyRow.locator('.card-modal__comment-body')).toHaveText('Reviewer reply')
			await deleteInBrowser(page, replyRow.getByTitle('Delete comment', { exact: true }), reply.id)
			await expect(replyRow).toHaveCount(0)

			await deleteInBrowser(page, ownRow.getByTitle('Delete comment', { exact: true }), own.id)
			await expect(ownRow).toHaveCount(0)
			const saved = await peer.api.get(`/cards/${state.cardId}/comments`)
			expect(saved.map((comment) => comment.id)).toEqual([state.ownerCommentId])
			expect((await peer.api.raw('PATCH', `/cards/${state.cardId}`, { title: 'Denied' })).status).toBe(403)
			expect((await peer.api.raw('PUT', `/comments/${state.ownerCommentId}/resolve`)).status).toBe(403)
			expect((await peer.api.raw('PATCH', `/comments/${state.ownerCommentId}`, { body: 'Denied' })).status).toBe(403)
			expect((await peer.api.raw('DELETE', `/comments/${state.ownerCommentId}`)).status).toBe(403)
			expect((await api.get(`/cards/${state.cardId}`)).title).toBe('Discussion without editing')
		} finally {
			await context.close()
		}
	})

	test('an author cannot delete another person’s reply with their own parent', async ({ browser, page, peer }) => {
		const own = await peer.api.post(`/cards/${state.cardId}/comments`, { body: 'Keep the whole discussion' })
		const reply = await api.post(`/cards/${state.cardId}/comments`, { body: 'Someone else replied', parentCommentId: own.id })
		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
		try {
			const peerPage = await context.newPage()
			await ncLogin(peerPage, peer)
			await peerPage.goto(state.cardUrl)
			const protectedDelete = peerPage.locator(`#comment-${own.id} > .card-modal__comment .card-modal__comment-icon-btn--danger`)
			await expect(protectedDelete).toBeDisabled()
			await expect(protectedDelete).toHaveAttribute('title', 'Only a board manager can delete a thread with replies from other people.')
			expect((await peer.api.raw('DELETE', `/comments/${own.id}`)).status).toBe(403)
			expect((await api.get(`/cards/${state.cardId}/comments`)).map((comment) => comment.id)).toEqual([state.ownerCommentId, own.id, reply.id])
		} finally {
			await context.close()
		}

		await ncLogin(page)
		await page.goto(state.cardUrl)
		const managerDelete = page.locator(`#comment-${own.id} > .card-modal__comment`).getByTitle('Delete comment', { exact: true })
		await expect(managerDelete).toBeEnabled()
		await deleteInBrowser(page, managerDelete, own.id)
		await expect(page.locator(`#comment-${own.id}`)).toHaveCount(0)
		expect((await api.get(`/cards/${state.cardId}/comments`)).map((comment) => comment.id)).toEqual([state.ownerCommentId])
	})

	test('read-only and revoked members cannot mutate discussion', async ({ browser, peer }) => {
		const own = await peer.api.post(`/cards/${state.cardId}/comments`, { body: 'Before permission removal' })
		await api.patch(`/boards/${state.boardId}/acl/${state.aclId}`, { permission: READ })
		for (const [method, path, body] of [
			['POST', `/cards/${state.cardId}/comments`, { body: 'Denied' }],
			['PATCH', `/comments/${own.id}`, { body: 'Denied' }],
			['DELETE', `/comments/${own.id}`],
			['PUT', `/comments/${own.id}/reactions/${encodeURIComponent('👍')}`],
			['DELETE', `/comments/${own.id}/reactions/${encodeURIComponent('👍')}`],
		]) expect((await peer.api.raw(method, path, body)).status).toBe(403)

		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
		try {
			const page = await context.newPage()
			await ncLogin(page, peer)
			await page.goto(state.cardUrl)
			await expect(page.locator(`#comment-${own.id}`)).toBeVisible()
			await expect(page.locator('.card-modal__composer')).toHaveCount(0)
			await expect(page.locator('.card-modal__reaction-add')).toHaveCount(0)
			await expect(page.getByTitle('Edit comment', { exact: true })).toHaveCount(0)
			await expect(page.getByRole('button', { name: 'Reply', exact: true })).toHaveCount(0)
		} finally {
			await context.close()
		}
		await api.delete(`/boards/${state.boardId}/acl/${state.aclId}`)
		expect((await peer.api.raw('POST', `/cards/${state.cardId}/comments`, { body: 'Still denied' })).status).toBe(403)
	})

	test('legacy editors retain Comment and limited sharers cannot grant missing capabilities', async ({ peer }) => {
		await api.patch(`/boards/${state.boardId}/acl/${state.aclId}`, { permission: READ | EDIT | SHARE })
		const board = await peer.api.get(`/boards/${state.boardId}`)
		expect(board.permissions & COMMENT).toBe(COMMENT)
		await peer.api.post(`/cards/${state.cardId}/comments`, { body: 'Legacy editor comment' })
		await peer.api.put(`/comments/${state.ownerCommentId}/resolve`)
		await peer.api.delete(`/comments/${state.ownerCommentId}/resolve`)
		await peer.api.patch(`/boards/${state.boardId}/acl/${state.aclId}`, { permission: READ | COMMENT | SHARE })
		expect((await peer.api.raw('PATCH', `/boards/${state.boardId}/acl/${state.aclId}`, { permission: READ | EDIT | COMMENT | SHARE })).status).toBe(403)
		expect((await peer.api.raw('PATCH', `/boards/${state.boardId}/acl/${state.aclId}`, { permission: READ | COMMENT | SHARE | MANAGE })).status).toBe(403)

		await api.patch(`/boards/${state.boardId}/acl/${state.aclId}`, { permission: READ | SHARE | MANAGE })
		expect((await peer.api.raw('PATCH', `/boards/${state.boardId}/acl/${state.aclId}`, { permission: READ | SHARE | MANAGE | COMMENT })).status).toBe(403)
		expect((await peer.api.raw('PATCH', `/boards/${state.boardId}/acl/${state.aclId}`, { permission: READ | SHARE | MANAGE | EDIT })).status).toBe(403)
	})

	test('sharing exposes independent Comment and keeps Edit’s implied Comment checked', async ({ page, peer }) => {
		await api.patch(`/boards/${state.boardId}/acl/${state.aclId}`, { permission: READ })
		await ncLogin(page)
		await openSharing(page, state.boardId)
		const entry = page.locator('.sharing__entry', { hasText: peer.user })
		const comment = entry.getByLabel('Comment', { exact: true })
		const edit = entry.getByLabel('Edit', { exact: true })
		await toggleGrant(page, comment, true, state.boardId, state.aclId)
		await expect(comment).toBeChecked()
		await expect(comment).toBeEnabled()
		await toggleGrant(page, edit, true, state.boardId, state.aclId)
		await expect(comment).toBeChecked()
		await expect(comment).toBeDisabled()
		await expect(entry.getByTitle('Editors can also comment.', { exact: true })).toBeVisible()
		await toggleGrant(page, edit, false, state.boardId, state.aclId)
		await expect(comment).toBeEnabled()
		await expect(comment).toBeChecked()
		await toggleGrant(page, comment, false, state.boardId, state.aclId)
		await expect(comment).not.toBeChecked()
		expect((await api.get(`/boards/${state.boardId}`)).acl.find((acl) => acl.id === state.aclId).permission).toBe(READ)
	})

	test('a mixed-role member cannot activate Manage through an external group grant', async ({ peer }) => {
		const group = `kansoe2e_comment_cap_${Date.now()}`
		async function ocs(method, path, params) {
			const response = await fetch(`${OCS}${path}?format=json`, {
				method,
				headers: { 'OCS-APIREQUEST': 'true', Authorization: adminAuth, 'Content-Type': 'application/x-www-form-urlencoded' },
				body: params ? new URLSearchParams(params) : undefined,
			})
			expect(response.ok).toBe(true)
			const payload = await response.json()
			expect(payload.ocs.meta.status).toBe('ok')
			return payload.ocs.data
		}
		await ocs('POST', '/groups', { groupid: group })
		try {
			await ocs('POST', `/users/${encodeURIComponent(peer.user)}/groups`, { groupid: group })
			expect((await ocs('GET', `/users/${encodeURIComponent(peer.user)}/groups`)).groups).toContain(group)
			await api.patch(`/boards/${state.boardId}/acl/${state.aclId}`, { permission: READ | SHARE })
			const groupAcl = await api.post(`/boards/${state.boardId}/acl`, {
				participant: group,
				participantType: 'group',
				permission: READ,
				role: 'external',
			})
			expect((await peer.api.raw('PATCH', `/boards/${state.boardId}/acl/${groupAcl.id}`, { permission: READ | MANAGE })).status).toBe(403)
			const board = await peer.api.get(`/boards/${state.boardId}`)
			expect(board.permissions).toBe(READ | SHARE)
			expect(board.acl.find((acl) => acl.id === groupAcl.id).permission).toBe(READ)
		} finally {
			await ocs('DELETE', `/groups/${encodeURIComponent(group)}`)
		}
	})

	test('a manager without Comment or Edit cannot enable those sharing toggles', async ({ browser, peer }) => {
		await api.patch(`/boards/${state.boardId}/acl/${state.aclId}`, { permission: READ | SHARE | MANAGE })
		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
		try {
			const page = await context.newPage()
			await ncLogin(page, peer)
			await openSharing(page, state.boardId)
			const entry = page.locator('.sharing__entry', { hasText: peer.user })
			await expect(entry.getByLabel('Comment', { exact: true })).toBeDisabled()
			await expect(entry.getByLabel('Edit', { exact: true })).toBeDisabled()
			await expect(entry.getByLabel('Comment', { exact: true })).not.toBeChecked()
		} finally {
			await context.close()
		}
	})

	test('external Comment members stay visibility-scoped and public links remain read-only', async ({ browser, peer }) => {
		await api.patch(`/boards/${state.boardId}/acl/${state.aclId}`, { permission: READ | COMMENT, role: 'external' })
		await peer.api.post(`/cards/${state.cardId}/comments`, { body: 'Visible external comment' })
		const hidden = await api.post('/cards', { stackId: (await api.get(`/cards/${state.cardId}`)).stackId, title: 'Internal discussion' })
		await api.patch(`/cards/${hidden.id}`, { visibility: 'internal' })
		const hiddenComment = await api.post(`/cards/${hidden.id}/comments`, { body: 'Hidden from external side' })
		expect((await peer.api.raw('POST', `/cards/${hidden.id}/comments`, { body: 'Denied' })).status).toBe(404)
		expect((await peer.api.raw('PUT', `/comments/${hiddenComment.id}/reactions/${encodeURIComponent('👍')}`)).status).toBe(404)

		const share = await api.post(`/boards/${state.boardId}/public-share`)
		await api.put(`/boards/${state.boardId}/public-share/comments`, { enabled: true })
		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
		try {
			const page = await context.newPage()
			await page.goto(`${BASE}/index.php/apps/kanso/p/${share.token}`)
			await expect(page.locator('.public-board__badge')).toContainText('Read-only')
			await page.locator('.public-card', { hasText: 'Discussion without editing' }).click()
			await expect(page.locator('.public-detail__title')).toHaveText('Discussion without editing')
			await expect(page.locator('.public-detail')).toContainText('Visible external comment')
			await expect(page.locator('.public-detail .ProseMirror, .public-detail textarea')).toHaveCount(0)
			for (const [method, path, body] of [
				['POST', `/cards/${state.cardId}/comments`, { body: 'Anonymous denied' }],
				['PUT', `/comments/${state.ownerCommentId}/reactions/${encodeURIComponent('👍')}`],
			]) {
				const result = await context.request.fetch(`${API}${path}`, { method, headers: { 'OCS-APIREQUEST': 'true' }, data: body, maxRedirects: 0 })
				expect([401, 403, 303]).toContain(result.status())
			}
		} finally {
			await context.close()
		}
		expect((await api.get(`/cards/${state.cardId}/comments`)).some((comment) => comment.body === 'Anonymous denied')).toBe(false)
	})
})
