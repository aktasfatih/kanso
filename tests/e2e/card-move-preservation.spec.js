// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, BASE, API, api, currentAuth, me } from './helpers.js'

const PNG = Uint8Array.from(Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGNgAAEAAAUA' +
	'AQ0KLbQAAAAASUVORK5CYII=', 'base64',
))

test.describe('Cross-board move preserves card data', () => {
	const state = { srcBoard: 0, dstBoard: 0, srcStack: 0, dstStack: 0, projects: [] }

	test.beforeAll(async () => {
		state.srcBoard = (await api.post('/boards', { title: 'Preservation source' })).id
		state.dstBoard = (await api.post('/boards', { title: 'Preservation destination' })).id
		state.srcStack = (await api.post('/stacks', { boardId: state.srcBoard, title: 'Source' })).id
		state.dstStack = (await api.post('/stacks', { boardId: state.dstBoard, title: 'Destination' })).id
	})

	test.afterAll(async () => {
		for (const id of state.projects) await api.delete(`/projects/${id}`).catch(() => {})
		if (state.srcBoard) await api.delete(`/boards/${state.srcBoard}`).catch(() => {})
		if (state.dstBoard) await api.delete(`/boards/${state.dstBoard}`).catch(() => {})
	})

	test('preserves field values, review state, project, reminders, and activity after source purge', async () => {
		const field = { name: 'Move priority', type: 'select', options: ['low', 'high'] }
		const sourceField = await api.post('/card-fields', { ...field, boardId: state.srcBoard })
		const targetField = await api.post('/card-fields', { ...field, boardId: state.dstBoard })
		const sourceType = await api.post('/review-types', { boardId: state.srcBoard, title: 'Move QA', color: '31CC7C', stage: 0 })
		const targetType = await api.post('/review-types', { boardId: state.dstBoard, title: 'Move QA', color: '31CC7C', stage: 0 })
		const project = await api.post('/projects', { title: 'Move project' })
		state.projects.push(project.id)
		const card = await api.post('/cards', { stackId: state.srcStack, title: 'Preserve attributes' })
		await api.put(`/cards/${card.id}/fields/${sourceField.id}`, { value: 'high' })
		await api.put(`/projects/${project.id}/cards/${card.id}`)
		await api.put(`/cards/${card.id}/reviews/${me}`, { reviewTypeId: sourceType.id })
		const before = await api.get(`/cards/${card.id}`)
		const review = before.reviews.find((item) => item.reviewTypeId === sourceType.id)
		await api.patch(`/cards/${card.id}/reviews/${review.id}`, { state: 'approved' })
		const thread = await api.post(`/cards/${card.id}/comments`, { body: 'Keep this thread' })
		await api.post(`/cards/${card.id}/comments`, { body: 'Keep this reply', parentCommentId: thread.id })
		const remindAt = Math.floor(Date.now() / 1000) + 86400
		await api.post(`/cards/${card.id}/reminders`, { remindAt, commentId: thread.id })
		await api.put(`/cards/${card.id}/subscription`)
		await api.patch(`/cards/${card.id}`, { priority: 3 })
		const originalActivity = await api.get(`/cards/${card.id}/activity`)
		expect(originalActivity.length).toBeGreaterThan(1)

		const moved = await api.post(`/cards/${card.id}/move-to-board`, { targetStackId: state.dstStack })
		await api.delete(`/cards/${card.id}/purge`)
		const detail = await api.get(`/cards/${moved.id}`)
		expect(detail.fieldValues).toEqual(expect.arrayContaining([
			expect.objectContaining({ fieldId: targetField.id, value: 'high' }),
		]))
		expect(detail.reviews).toEqual(expect.arrayContaining([
			expect.objectContaining({ reviewer: me, reviewTypeId: targetType.id, state: 'approved' }),
		]))
		expect((await api.get(`/projects/${project.id}/cards`)).map((item) => item.id)).toContain(moved.id)
		const movedThread = (await api.get(`/cards/${moved.id}/comments`)).find((item) => item.body === 'Keep this thread')
		expect((await api.get(`/cards/${moved.id}/reminders`))).toEqual(expect.arrayContaining([
			expect.objectContaining({ remindAt, commentId: movedThread.id }),
		]))
		expect((await api.get(`/cards/${moved.id}/subscription`)).subscribed).toBe(true)
		const activity = await api.get(`/cards/${moved.id}/activity`)
		for (const item of originalActivity) {
			expect(activity).toEqual(expect.arrayContaining([
				expect.objectContaining({ actor: item.actor, verb: item.verb, timestamp: item.timestamp, detail: item.detail }),
			]))
		}
	})

	test('refuses a move without a matching custom field without deleting the original', async () => {
		const card = await api.post('/cards', { stackId: state.srcStack, title: 'Unmapped field' })
		const sourceField = await api.post('/card-fields', { boardId: state.srcBoard, name: 'Source only', type: 'text' })
		await api.put(`/cards/${card.id}/fields/${sourceField.id}`, { value: 'must survive' })
		const result = await api.raw('POST', `/cards/${card.id}/move-to-board`, { targetStackId: state.dstStack })
		expect(result.ok).toBe(false)
		expect((await result.text()).toLowerCase()).toContain('custom field')
		const detail = await api.get(`/cards/${card.id}`)
		expect(detail.fieldValues).toEqual(expect.arrayContaining([
			expect.objectContaining({ fieldId: sourceField.id, value: 'must survive' }),
		]))
	})

	test('refuses a move without a matching review type without losing the review', async () => {
		const card = await api.post('/cards', { stackId: state.srcStack, title: 'Unmapped review' })
		const sourceType = await api.post('/review-types', { boardId: state.srcBoard, title: 'Source-only QA', stage: 0 })
		await api.put(`/cards/${card.id}/reviews/${me}`, { reviewTypeId: sourceType.id })
		const result = await api.raw('POST', `/cards/${card.id}/move-to-board`, { targetStackId: state.dstStack })
		expect(result.ok).toBe(false)
		expect((await result.text()).toLowerCase()).toContain('review type')
		expect((await api.get(`/cards/${card.id}`)).reviews).toEqual(expect.arrayContaining([
			expect.objectContaining({ reviewer: me, reviewTypeId: sourceType.id, state: 'pending' }),
		]))
	})

	test('preserves a thread-only subscription across the move', async ({ peer }) => {
		for (const boardId of [state.srcBoard, state.dstBoard]) {
			await api.post(`/boards/${boardId}/acl`, {
				participant: peer.user, participantType: 'user', permission: 3,
			})
		}
		const card = await api.post('/cards', { stackId: state.srcStack, title: 'Thread subscriber' })
		const root = await api.post(`/cards/${card.id}/comments`, { body: 'Thread root' })
		await peer.api.post(`/cards/${card.id}/comments`, { body: 'Following this thread', parentCommentId: root.id })
		await peer.api.delete(`/cards/${card.id}/subscription`)
		expect((await peer.api.get(`/cards/${card.id}/subscription`)).subscribed).toBe(false)
		const moved = await api.post(`/cards/${card.id}/move-to-board`, { targetStackId: state.dstStack })
		await api.delete(`/cards/${card.id}/purge`)
		const movedRoot = (await api.get(`/cards/${moved.id}/comments`)).find((item) => item.body === 'Thread root')
		expect((await peer.api.get(`/cards/${moved.id}/subscription`)).subscribed).toBe(false)
		await api.post(`/cards/${moved.id}/comments`, { body: 'A new reply', parentCommentId: movedRoot.id })
		await expect.poll(async () => {
			const response = await fetch(`${BASE}/ocs/v2.php/apps/notifications/api/v2/notifications`, {
				headers: { Authorization: peer.auth, 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			const notifications = (await response.json())?.ocs?.data ?? []
			return notifications.some((item) => item.app === 'kanso' && Number(item.object_id) === moved.id)
		}).toBe(true)
	})

	test('moves recurring template and latest occurrence together while preserving the rule', async () => {
		const card = await api.post('/cards', { stackId: state.srcStack, title: 'Recurring move' })
		const rule = await api.post(`/boards/${state.srcBoard}/recur-rules`, {
			templateCardId: card.id, targetStackId: state.srcStack, mode: 0,
			rrule: 'FREQ=DAILY;INTERVAL=2', skipWhileOpen: true,
		})
		const spawned = (await api.post(`/recur-rules/${rule.id}/create-now`)).card
		const before = (await api.get(`/boards/${state.srcBoard}/recur-rules`)).find((item) => item.id === rule.id)
		expect(before.lastSpawnedAt).toBe(spawned.id)
		const moved = await api.post(`/cards/${card.id}/move-to-board`, { targetStackId: state.dstStack })
		const after = (await api.get(`/boards/${state.dstBoard}/recur-rules`)).find((item) => item.id === rule.id)
		expect(after).toEqual(expect.objectContaining({
			templateCardId: moved.id,
			targetStackId: state.dstStack,
			rrule: before.rrule,
			occurrencesSpawned: before.occurrencesSpawned,
			nextOccurrenceAt: before.nextOccurrenceAt,
			skipWhileOpen: true,
		}))
		expect(after.lastSpawnedAt).not.toBe(spawned.id)
		expect((await api.get(`/cards/${after.lastSpawnedAt}`)).boardId).toBe(state.dstBoard)
		expect((await api.get(`/boards/${state.srcBoard}/recur-rules`)).find((item) => item.id === rule.id)).toBeUndefined()
		await api.delete(`/cards/${card.id}/purge`)
		await api.delete(`/cards/${spawned.id}/purge`)
		expect((await api.get(`/boards/${state.dstBoard}/recur-rules`)).find((item) => item.id === rule.id)).toEqual(after)
	})

	test('transfers a running timer without duplicating it when the source is restored', async () => {
		const doing = await api.post('/stacks', { boardId: state.srcBoard, title: 'In progress' })
		await api.patch(`/stacks/${doing.id}`, { role: 3 })
		await api.post(`/boards/${state.srcBoard}/automation-rules`, {
			trigger: 'card_entered_role', action: 'start_timer', params: { role: 3 },
		})
		const card = await api.post('/cards', { stackId: state.srcStack, title: 'Timer move' })
		await api.post(`/cards/${card.id}/move`, { targetStackId: doing.id })
		expect((await api.get(`/boards/${state.srcBoard}`)).cards.find((item) => item.id === card.id).timerRunning).toBe(true)
		const moved = await api.post(`/cards/${card.id}/move-to-board`, { targetStackId: state.dstStack })
		await api.post(`/cards/${card.id}/restore`)
		const source = (await api.get(`/boards/${state.srcBoard}`)).cards.find((item) => item.id === card.id)
		const target = (await api.get(`/boards/${state.dstBoard}`)).cards.find((item) => item.id === moved.id)
		expect(source.timerRunning).toBe(false)
		expect(target.timerRunning).toBe(true)
	})

	test('preserves an embedded image that belongs to another moved card', async () => {
		const first = await api.post('/cards', { stackId: state.srcStack, title: 'Image source' })
		const second = await api.post('/cards', { stackId: state.srcStack, title: 'Image reference' })
		await api.post(`/cards/${first.id}/relations`, { otherCardId: second.id, kind: 'relates' })
		const form = new FormData()
		form.append('file', new Blob([PNG], { type: 'image/png' }), 'other-card.png')
		const response = await fetch(`${API}/cards/${first.id}/attachments`, {
			method: 'POST', headers: { Authorization: currentAuth, 'OCS-APIREQUEST': 'true' }, body: form,
		})
		expect(response.ok).toBe(true)
		const image = await response.json()
		const originalUrl = `/apps/kanso/api/cards/${first.id}/attachments/${image.id}/inline`
		await api.patch(`/cards/${second.id}`, { description: `![cross-card](${originalUrl})` })
		await api.post(`/cards/${second.id}/comments`, { body: `![cross-card](${originalUrl})` })
		const movedSecond = await api.post(`/cards/${second.id}/move-to-board`, { targetStackId: state.dstStack })
		const targetBoard = await api.get(`/boards/${state.dstBoard}`)
		const movedFirst = targetBoard.cards.find((item) => item.title === 'Image source')
		const copiedImage = (await api.get(`/cards/${movedFirst.id}/attachments`))[0]
		const movedUrl = `/apps/kanso/api/cards/${movedFirst.id}/attachments/${copiedImage.id}/inline`
		await api.delete(`/cards/${first.id}/purge`)
		await api.delete(`/cards/${second.id}/purge`)
		expect((await api.get(`/cards/${movedSecond.id}`)).description).toBe(`![cross-card](${movedUrl})`)
		expect((await api.get(`/cards/${movedSecond.id}/comments`))[0].body).toBe(`![cross-card](${movedUrl})`)
		const inline = await fetch(`${BASE}${movedUrl}`, { headers: { Authorization: currentAuth, 'OCS-APIREQUEST': 'true' } })
		expect(inline.ok).toBe(true)
		expect(new Uint8Array(await inline.arrayBuffer())).toEqual(PNG)
	})
})
