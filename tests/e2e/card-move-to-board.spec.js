// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, BASE, API, api, currentAuth, me, ncLogin } from './helpers.js'

const PNG = Uint8Array.from(Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGNgAAEAAAUA' +
	'AQ0KLbQAAAAASUVORK5CYII=',
	'base64',
))

// Live (non-archived) cards in a stack, top-to-bottom, from the board payload.
async function stackTitles(boardId, stackId) {
	const board = await api.get(`/boards/${boardId}`)
	return board.cards
		.filter((c) => c.stackId === stackId && !c.archived)
		.sort((a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0))
		.map((c) => c.title)
}

// #3679 — "Move to board…" from the card ⋯ menu: relocate a single card to
// another board (created on the target, removed from the source).
test.describe('Move card to another board (card ⋯ menu)', () => {
	const state = { srcBoardId: 0, dstBoardId: 0, srcStackId: 0, dstStackId: 0, labelId: 0, srcId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const src = await api.post('/boards', { title: 'Move-Src E2E' })
		state.srcBoardId = src.id
		state.srcStackId = (await api.post('/stacks', { boardId: src.id, title: 'Source Column' })).id

		const dst = await api.post('/boards', { title: 'Move-Dst E2E' })
		state.dstBoardId = dst.id
		state.dstStackId = (await api.post('/stacks', { boardId: dst.id, title: 'Landing Column' })).id
		// A matching label (same name + color) on BOTH boards so the map-over keeps it.
		await api.post('/labels', { boardId: src.id, title: 'Important', color: 'e01e01' })
		const dstLabel = await api.post('/labels', { boardId: dst.id, title: 'Important', color: 'e01e01' })
		state.labelId = dstLabel.id

		const card = await api.post('/cards', { stackId: state.srcStackId, title: 'Relocatable card' })
		state.srcId = card.id
		await api.patch(`/cards/${card.id}`, { description: 'travels with me', priority: 3 })
		const srcLabels = await api.get(`/boards/${src.id}`)
		const srcLabelId = srcLabels.labels.find((l) => l.title === 'Important').id
		await api.put(`/cards/${card.id}/labels/${srcLabelId}`)
		await api.post(`/cards/${card.id}/checklist`, { title: 'carry me too' })

		state.cardUrl = `${BASE}/index.php/apps/kanso/#/board/${src.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.srcBoardId) await api.delete(`/boards/${state.srcBoardId}`).catch(() => {})
		if (state.dstBoardId) await api.delete(`/boards/${state.dstBoardId}`).catch(() => {})
	})

	test('moves the card off the source board and onto the target', async ({ page }) => {
		// Preconditions: source has the card, target is empty.
		expect(await stackTitles(state.srcBoardId, state.srcStackId)).toEqual(['Relocatable card'])
		expect(await stackTitles(state.dstBoardId, state.dstStackId)).toEqual([])

		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		// Open the ⋯ menu and click "Move to board…".
		await page.locator('.card-modal__actions-menu button').first().click()
		await page.getByRole('menuitem', { name: 'Move to board…' }).click()

		// The move dialog opens (reuses the copy picker); pick the target board+column.
		await page.waitForSelector('.card-modal__copy-dialog', { timeout: 15_000 })
		await page.locator('.card-modal__copy-field select').first()
			.selectOption({ label: 'Move-Dst E2E' })
		await page.locator('.card-modal__copy-field select').nth(1)
			.selectOption({ label: 'Landing Column' })
		await page.getByRole('button', { name: 'Move', exact: true }).click()

		// The card LEFT the source board...
		await expect
			.poll(() => stackTitles(state.srcBoardId, state.srcStackId))
			.toEqual([])
		// ...and APPEARS on the target board.
		await expect
			.poll(() => stackTitles(state.dstBoardId, state.dstStackId))
			.toEqual(['Relocatable card'])

		// The relocated card is a NEW card (fresh id) carrying the content + mapped label.
		const dstBoard = await api.get(`/boards/${state.dstBoardId}`)
		const moved = dstBoard.cards.find((c) => c.stackId === state.dstStackId && !c.archived)
		expect(moved).toBeTruthy()
		expect(moved.id).not.toBe(state.srcId)

		const detail = await api.get(`/cards/${moved.id}`)
		expect(detail.description).toBe('travels with me')
		expect(detail.priority).toBe(3)
		expect(detail.labelIds).toContain(state.labelId)
		expect(detail.checklistItems.map((i) => i.title)).toEqual(['carry me too'])

		// The original id is gone (soft-deleted on the source): its detail 404s.
		const r = await api.raw('GET', `/cards/${state.srcId}`)
		expect(r.status).toBe(404)
	})

	test('preserves discussion, linked files and attachment contents across a move and source purge', async () => {
		const filename = `kanso-move-linked-${Date.now()}.txt`
		const fileUrl = `${BASE}/remote.php/dav/files/${encodeURIComponent(me)}/${filename}`
		const put = await fetch(fileUrl, {
			method: 'PUT',
			headers: { Authorization: currentAuth },
			body: 'linked file content',
		})
		expect(put.ok).toBe(true)
		const fileId = Number.parseInt(put.headers.get('oc-fileid'), 10)
		expect(fileId).toBeGreaterThan(0)

		try {
			const card = await api.post('/cards', { stackId: state.srcStackId, title: 'Discussion and files' })
			await api.post(`/cards/${card.id}/file-links`, { fileId })
			await api.post(`/cards/${card.id}/attachments/from-file`, { fileId })

			const form = new FormData()
			form.append('file', new Blob([PNG], { type: 'image/png' }), 'move-image.png')
			const uploaded = await fetch(`${API}/cards/${card.id}/attachments`, {
				method: 'POST',
				headers: { Authorization: currentAuth, 'OCS-APIREQUEST': 'true' },
				body: form,
			})
			expect(uploaded.ok).toBe(true)
			const image = await uploaded.json()
			const imageUrl = `/apps/kanso/api/cards/${card.id}/attachments/${image.id}/inline`
			await api.patch(`/cards/${card.id}`, { description: `Image: ![photo](${imageUrl})` })
			const thread = await api.post(`/cards/${card.id}/comments`, { body: `Picture: ![photo](${imageUrl})` })
			await api.post(`/cards/${card.id}/comments`, { body: 'A reply', parentCommentId: thread.id })
			await api.put(`/comments/${thread.id}/reactions/${encodeURIComponent('👍')}`)

			const moved = await api.post(`/cards/${card.id}/move-to-board`, { targetStackId: state.dstStackId })
			const links = await api.get(`/cards/${moved.id}/file-links`)
			expect(links.map((link) => link.fileId)).toContain(fileId)

			const attachments = await api.get(`/cards/${moved.id}/attachments`)
			expect(attachments.map((attachment) => attachment.filename).sort()).toEqual([filename, 'move-image.png'].sort())
			const copiedFile = attachments.find((attachment) => attachment.filename === filename)
			const copiedImage = attachments.find((attachment) => attachment.filename === 'move-image.png')
			const movedImageUrl = `/apps/kanso/api/cards/${moved.id}/attachments/${copiedImage.id}/inline`

			const detail = await api.get(`/cards/${moved.id}`)
			expect(detail.description).toBe(`Image: ![photo](${movedImageUrl})`)
			const comments = await api.get(`/cards/${moved.id}/comments`)
			expect(comments).toHaveLength(2)
			const movedThread = comments.find((comment) => comment.parentCommentId === null)
			const reply = comments.find((comment) => comment.body === 'A reply')
			expect(movedThread.body).toBe(`Picture: ![photo](${movedImageUrl})`)
			expect(movedThread.author).toBe(me)
			expect(reply.parentCommentId).toBe(movedThread.id)
			expect(movedThread.reactions).toEqual(expect.arrayContaining([
				expect.objectContaining({ emoji: '👍', count: 1, mine: true }),
			]))

			await api.delete(`/cards/${card.id}/purge`)
			const download = await fetch(`${API}/cards/${moved.id}/attachments/${copiedFile.id}`, {
				headers: { Authorization: currentAuth, 'OCS-APIREQUEST': 'true' },
			})
			expect(download.ok).toBe(true)
			expect(await download.text()).toBe('linked file content')
			const inline = await fetch(`${BASE}${movedImageUrl}`, {
				headers: { Authorization: currentAuth, 'OCS-APIREQUEST': 'true' },
			})
			expect(inline.ok).toBe(true)
			expect(new Uint8Array(await inline.arrayBuffer())).toEqual(PNG)
			expect((await api.get(`/cards/${moved.id}/file-links`)).map((link) => link.fileId)).toContain(fileId)
			expect(await api.get(`/cards/${moved.id}/comments`)).toHaveLength(2)
		} finally {
			await fetch(fileUrl, { method: 'DELETE', headers: { Authorization: currentAuth } }).catch(() => {})
		}
	})

	test('preserves time entries, forge links and checklist details after source purge', async () => {
		const card = await api.post('/cards', { stackId: state.srcStackId, title: 'Tracked work' })
		await api.patch(`/cards/${card.id}`, {
			duedate: '2035-04-03T10:00:00+00:00',
			dueReminderDayBefore: true,
		})
		const step = await api.post(`/cards/${card.id}/checklist`, { title: 'Assigned step' })
		await api.post(`/checklist/${step.id}/assign`, { participant: me })
		await api.put(`/checklist/${step.id}/due`, { due: '2035-04-02T10:00:00+00:00' })
		await api.patch(`/checklist/${step.id}`, { done: true })
		const originalStep = (await api.get(`/cards/${card.id}/checklist`))[0]
		const originalDetail = await api.get(`/cards/${card.id}`)
		const entry = await api.post(`/cards/${card.id}/time-entries`, { seconds: 5400, note: 'Worked together' })
		const url = 'https://github.com/nextcloud/server/pull/1'
		await api.post(`/cards/${card.id}/links`, { url })

		const moved = await api.post(`/cards/${card.id}/move-to-board`, { targetStackId: state.dstStackId })
		await api.delete(`/cards/${card.id}/purge`)

		const detail = await api.get(`/cards/${moved.id}`)
		expect(detail.createdAt).toBe(originalDetail.createdAt)
		expect(detail.dueReminderDayBefore).toBe(true)
		expect(detail.timeSpent).toBe(5400)
		const movedStep = (await api.get(`/cards/${moved.id}/checklist`))[0]
		expect(movedStep).toEqual(expect.objectContaining({
			title: originalStep.title,
			done: true,
			assignedUser: me,
			assignedRole: originalStep.assignedRole,
			assignedAt: originalStep.assignedAt,
			dueDate: originalStep.dueDate,
			doneAt: originalStep.doneAt,
		}))
		const entries = await api.get(`/cards/${moved.id}/time-entries`)
		expect(entries).toHaveLength(1)
		expect(entries[0]).toEqual(expect.objectContaining({
			seconds: 5400,
			note: 'Worked together',
			createdBy: entry.createdBy,
			createdAt: entry.createdAt,
		}))
		expect((await api.get(`/cards/${moved.id}/links`)).map((link) => link.url)).toContain(url)
	})

	test('preserves a contact link after moving and purging the source card', async () => {
		const uid = `kanso-move-contact-${Date.now()}`
		const name = 'Kanso Move Contact'
		const vcardUrl = `${BASE}/remote.php/dav/addressbooks/users/${encodeURIComponent(me)}/contacts/${uid}.vcf`
		const put = await fetch(vcardUrl, {
			method: 'PUT',
			headers: { Authorization: currentAuth, 'Content-Type': 'text/vcard' },
			body: `BEGIN:VCARD\r\nVERSION:3.0\r\nUID:${uid}\r\nFN:${name}\r\nEND:VCARD\r\n`,
		})
		expect(put.ok).toBe(true)
		try {
			const card = await api.post('/cards', { stackId: state.srcStackId, title: 'Contact work' })
			const link = await api.raw('POST', `/cards/${card.id}/contacts`, { contactUri: uid, displayName: name })
			const linked = await link.json()
			test.skip(link.status === 400 && linked.error === 'Contacts app is not available', 'Optional Contacts app is disabled')
			expect(link.ok, JSON.stringify(linked)).toBe(true)
			const moved = await api.post(`/cards/${card.id}/move-to-board`, { targetStackId: state.dstStackId })
			await api.delete(`/cards/${card.id}/purge`)
			expect((await api.get(`/cards/${moved.id}`)).contacts).toEqual(expect.arrayContaining([
				expect.objectContaining({ contactUri: uid, displayName: name }),
			]))
		} finally {
			await fetch(vcardUrl, { method: 'DELETE', headers: { Authorization: currentAuth } }).catch(() => {})
		}
	})

	test('moves a connected graph and preserves its hierarchy and relations', async () => {
		const makeCard = (title) => api.post('/cards', { stackId: state.srcStackId, title })
		const parent = await makeCard('Graph parent')
		const child = await makeCard('Graph child')
		const peer = await makeCard('Graph peer')
		const downstream = await makeCard('Graph downstream')
		const unrelated = await makeCard('Graph unrelated')
		await api.put(`/cards/${child.id}/parent`, { parentCardId: parent.id })
		await api.post(`/cards/${parent.id}/relations`, { otherCardId: peer.id, kind: 'blocks' })
		await api.post(`/cards/${peer.id}/relations`, { otherCardId: downstream.id, kind: 'relates' })

		const movedChild = await api.post(`/cards/${child.id}/move-to-board`, { targetStackId: state.dstStackId })
		const destination = await api.get(`/boards/${state.dstBoardId}`)
		const byTitle = (title) => destination.cards.find((card) => card.title === title)
		const movedParent = byTitle('Graph parent')
		const movedPeer = byTitle('Graph peer')
		const movedDownstream = byTitle('Graph downstream')
		expect(byTitle('Graph child').id).toBe(movedChild.id)
		expect(movedParent).toBeTruthy()
		expect(movedPeer).toBeTruthy()
		expect(movedDownstream).toBeTruthy()
		expect(byTitle('Graph unrelated')).toBeUndefined()
		expect((await api.get(`/cards/${movedChild.id}`)).parentCardId).toBe(movedParent.id)
		expect((await api.get(`/cards/${movedParent.id}`)).relations.blocks.map((r) => r.cardId)).toContain(movedPeer.id)
		expect((await api.get(`/cards/${movedPeer.id}`)).relations.relates.map((r) => r.cardId)).toContain(movedDownstream.id)
		for (const original of [parent, child, peer, downstream]) {
			expect((await api.raw('GET', `/cards/${original.id}`)).status).toBe(404)
		}
		expect((await api.get(`/cards/${unrelated.id}`)).boardId).toBe(state.srcBoardId)
	})

	test('rejects the entire connected move when a card is hidden from the mover', async ({ peer }) => {
		for (const boardId of [state.srcBoardId, state.dstBoardId]) {
			await api.post(`/boards/${boardId}/acl`, {
				participant: peer.user,
				participantType: 'user',
				permission: 3,
				role: 'external',
			})
		}
		const parent = await api.post('/cards', { stackId: state.srcStackId, title: 'Visible graph root' })
		const secret = await api.post('/cards', { stackId: state.srcStackId, title: 'Hidden graph child' })
		await api.patch(`/cards/${secret.id}`, { visibility: 'private' })
		await api.put(`/cards/${secret.id}/parent`, { parentCardId: parent.id })
		const response = await peer.api.raw('POST', `/cards/${parent.id}/move-to-board`, { targetStackId: state.dstStackId })
		expect(response.ok).toBe(false)
		expect(await response.text()).not.toContain('Hidden graph child')
		expect((await api.get(`/cards/${parent.id}`)).boardId).toBe(state.srcBoardId)
		expect((await api.get(`/cards/${secret.id}`)).parentCardId).toBe(parent.id)
	})
})
